<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Teacher-side attendance marking from the teacher dashboard.
 *
 * Institutional rule: a teacher may mark a student ABSENT (justified or not) or
 * LATE, but may NOT mark them present. Presence is earned through the QR scan or
 * the BigBlueButton presence poll, never granted by hand — otherwise the whole
 * attendance chain becomes unverifiable.
 *
 * To keep that rule from trapping teachers into their own mistakes, every mark
 * records the previous state, and the teacher can undo a mark they made
 * themselves: undo restores exactly what was there before (including "present"
 * or "no record"), which is a restoration, not a new grant.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local;

use stdClass;
use context_module;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->dirroot . '/local/grupomakro_core/locallib.php');
require_once($GLOBALS['CFG']->dirroot . '/local/grupomakro_core/pages/absence_helpers.php');

class teacher_attendance {

    /** @var string History action written when a teacher marks a session. */
    const HISTORY_MARK = 'teacher_mark_attendance';

    /** @var string History action written when a teacher undoes their own mark. */
    const HISTORY_UNDO = 'teacher_undo_attendance';

    /**
     * @var string[] Acronyms a teacher may set, in menu order: late and
     * unjustified absence. Justified absence (FJ) is deliberately excluded —
     * justifying an absence requires supporting documentation and is handled by
     * the academic office through the absence dashboard, not by the teacher.
     */
    const TEACHER_ACRONYMS = ['R', 'FI'];

    /**
     * Resolves and validates the (class, session, student) triple plus the
     * teacher's right to act on it. Throws on any violation.
     *
     * @param int $classid
     * @param int $sessionid
     * @param int $studentid
     * @return array{class:stdClass, session:stdClass, cmid:int, attendanceid:int}
     */
    public static function resolve_context(int $classid, int $sessionid, int $studentid): array {
        global $DB, $USER;

        $class = $DB->get_record('gmk_class', ['id' => $classid], '*', MUST_EXIST);
        $session = $DB->get_record('attendance_sessions', ['id' => $sessionid], '*', MUST_EXIST);

        // The session must belong to THIS class's attendance activity, so a
        // teacher can never reach into another class by passing a foreign id.
        $cmid = (int)$class->attendancemoduleid;
        if ($cmid <= 0) {
            throw new \moodle_exception('La clase no tiene actividad de asistencia configurada.');
        }
        $cm = $DB->get_record('course_modules', ['id' => $cmid], 'id, course, instance', MUST_EXIST);
        if ((int)$session->attendanceid !== (int)$cm->instance) {
            throw new \moodle_exception('La sesión no pertenece a esta clase.');
        }

        // Capability is checked on the attendance activity itself.
        $context = context_module::instance($cmid);
        require_capability('mod/attendance:takeattendances', $context);

        // And the acting user must actually run this class (admins excepted).
        if (!is_siteadmin() && !\gmk_user_is_class_instructor_or_support((int)$USER->id, (int)$class->id)) {
            throw new \moodle_exception('No es docente de esta clase.');
        }

        // The student must be enrolled in the class.
        $enrolled = $DB->record_exists_select('gmk_course_progre',
            'classid = :cid AND userid = :uid AND status = 2',
            ['cid' => (int)$class->id, 'uid' => $studentid]);
        if (!$enrolled) {
            throw new \moodle_exception('El estudiante no está inscrito en esta clase.');
        }

        // Only past sessions can be marked: marking a future session would be
        // recording attendance for a class that hasn't happened.
        if (((int)$session->sessdate) > time()) {
            throw new \moodle_exception('No se puede marcar una sesión que aún no ha ocurrido.');
        }

        return [
            'class' => $class,
            'session' => $session,
            'cmid' => $cmid,
            'attendanceid' => (int)$cm->instance,
        ];
    }

    /**
     * The statuses a teacher is allowed to set: everything except the presence
     * status. "Presence" is the highest-grade status of the set — resolving it
     * by grade rather than by acronym keeps the rule working if the acronyms
     * are renamed.
     *
     * @param int $attendanceid
     * @return array<int, stdClass> keyed by status id
     */
    public static function selectable_statuses(int $attendanceid): array {
        global $DB;

        $all = $DB->get_records_select('attendance_statuses',
            'attendanceid = :aid AND visible = 1 AND deleted = 0',
            ['aid' => $attendanceid], 'grade DESC', 'id, acronym, description, grade, setnumber');
        if (empty($all)) {
            return [];
        }
        $maxgrade = null;
        foreach ($all as $s) {
            $maxgrade = ($maxgrade === null) ? (float)$s->grade : max($maxgrade, (float)$s->grade);
        }
        // Two filters, in this order:
        //  1. Never the presence status (top grade of the set) — presence is
        //     earned by QR or BBB, never granted by hand.
        //  2. Only the acronyms the teacher is meant to use (late / unjustified
        //     absence). If none of them exist in this instance (renamed set),
        //     fall back to "everything below presence" so the feature degrades
        //     into something usable instead of an empty menu.
        $belowpresence = [];
        foreach ($all as $s) {
            if ((float)$s->grade >= (float)$maxgrade) {
                continue;
            }
            $belowpresence[(int)$s->id] = $s;
        }

        $preferred = [];
        foreach (self::TEACHER_ACRONYMS as $acronym) {
            foreach ($belowpresence as $id => $s) {
                if (strcasecmp(trim((string)$s->acronym), $acronym) === 0) {
                    $preferred[$id] = $s;
                }
            }
        }
        return !empty($preferred) ? $preferred : $belowpresence;
    }

    /**
     * Marks a student as absent/late in a session.
     *
     * @param int    $classid
     * @param int    $sessionid
     * @param int    $studentid
     * @param int    $statusid
     * @param string $remarks
     * @return array{ok:bool, logid:int, previous:?array, absences:int}
     */
    public static function mark(int $classid, int $sessionid, int $studentid, int $statusid, string $remarks = ''): array {
        global $DB, $USER;

        $ctx = self::resolve_context($classid, $sessionid, $studentid);
        $allowed = self::selectable_statuses($ctx['attendanceid']);
        if (!isset($allowed[$statusid])) {
            throw new \moodle_exception('Estado no permitido. El docente no puede marcar asistencia presente.');
        }
        $status = $allowed[$statusid];

        $now = time();
        $existing = $DB->get_record('attendance_log', ['sessionid' => $sessionid, 'studentid' => $studentid]);

        // Snapshot of what was there before, so undo can restore it verbatim.
        $previous = $existing ? [
            'statusid'  => (int)$existing->statusid,
            'statusset' => (string)$existing->statusset,
            'remarks'   => (string)$existing->remarks,
            'takenby'   => (int)$existing->takenby,
            'timetaken' => (int)$existing->timetaken,
        ] : null;

        if ($existing) {
            $existing->statusid  = (int)$status->id;
            $existing->statusset = (string)($status->setnumber ?? 0);
            $existing->remarks   = $remarks;
            $existing->timetaken = $now;
            $existing->takenby   = (int)$USER->id;
            $DB->update_record('attendance_log', $existing);
            $logid = (int)$existing->id;
        } else {
            $log = new stdClass();
            $log->sessionid = $sessionid;
            $log->studentid = $studentid;
            $log->statusid  = (int)$status->id;
            $log->statusset = (string)($status->setnumber ?? 0);
            $log->remarks   = $remarks;
            $log->timetaken = $now;
            $log->takenby   = (int)$USER->id;
            $logid = (int)$DB->insert_record('attendance_log', $log);
        }

        // Keep the session flagged as taken, otherwise the plugin's log-based
        // calculation ignores it as a "phantom" session.
        $DB->set_field('attendance_sessions', 'lasttaken', $now, ['id' => $sessionid]);
        $DB->set_field('attendance_sessions', 'lasttakenby', (int)$USER->id, ['id' => $sessionid]);

        self::after_change($ctx['class'], $studentid, $sessionid, $logid, $status, $previous, self::HISTORY_MARK);

        return [
            'ok' => true,
            'logid' => $logid,
            'previous' => $previous,
            'absences' => self::count_absences($ctx['class'], $studentid),
        ];
    }

    /**
     * Undoes a mark made by the current teacher, restoring the previous state.
     *
     * Only the teacher who made the mark can undo it, and only back to what was
     * there before — this is what keeps "no marking present" from turning into
     * a trap when the teacher clicks the wrong row.
     *
     * @param int $classid
     * @param int $sessionid
     * @param int $studentid
     * @return array{ok:bool, restored:string, absences:int}
     */
    public static function undo(int $classid, int $sessionid, int $studentid): array {
        global $DB, $USER;

        $ctx = self::resolve_context($classid, $sessionid, $studentid);
        $log = $DB->get_record('attendance_log', ['sessionid' => $sessionid, 'studentid' => $studentid]);
        if (!$log) {
            throw new \moodle_exception('No hay marca que deshacer en esta sesión.');
        }
        if ((int)$log->takenby !== (int)$USER->id && !is_siteadmin()) {
            throw new \moodle_exception('Solo puede deshacer las marcas que usted mismo registró.');
        }

        // Find the snapshot written when this mark was made.
        $entry = $DB->get_record_sql(
            "SELECT id, details
               FROM {gmk_class_absence_history}
              WHERE userid = :uid AND classid = :cid AND action = :act
                AND " . $DB->sql_like('details', ':needle') . "
           ORDER BY id DESC",
            [
                'uid' => $studentid,
                'cid' => (int)$ctx['class']->id,
                'act' => self::HISTORY_MARK,
                'needle' => '%"sessionid":' . (int)$sessionid . '%',
            ],
            IGNORE_MULTIPLE
        );
        $previous = null;
        if ($entry && !empty($entry->details)) {
            $decoded = json_decode($entry->details, true);
            if (is_array($decoded) && !empty($decoded['previous'])) {
                $previous = $decoded['previous'];
            }
        }

        if ($previous && !empty($previous['statusid'])) {
            // Restore the exact previous row, including who had taken it.
            $log->statusid  = (int)$previous['statusid'];
            $log->statusset = (string)($previous['statusset'] ?? 0);
            $log->remarks   = (string)($previous['remarks'] ?? '');
            $log->takenby   = (int)($previous['takenby'] ?? 0);
            $log->timetaken = (int)($previous['timetaken'] ?? time());
            $DB->update_record('attendance_log', $log);
            $restored = 'previous_status';
        } else {
            // There was no record before the teacher's mark: leave it unrecorded
            // so the automatic sources (QR / BBB) can fill it in again.
            $DB->delete_records('attendance_log', ['id' => (int)$log->id]);
            $restored = 'unrecorded';
        }

        self::after_change($ctx['class'], $studentid, $sessionid, (int)$log->id, null, $previous, self::HISTORY_UNDO);

        return [
            'ok' => true,
            'restored' => $restored,
            'absences' => self::count_absences($ctx['class'], $studentid),
        ];
    }

    /**
     * Shared post-change work: audit entry, absence-state recompute and gradebook
     * invalidation.
     *
     * @param stdClass      $class
     * @param int           $studentid
     * @param int           $sessionid
     * @param int           $logid
     * @param stdClass|null $status
     * @param array|null    $previous
     * @param string        $action
     * @return void
     */
    protected static function after_change(stdClass $class, int $studentid, int $sessionid,
                                           int $logid, ?stdClass $status, ?array $previous, string $action): void {
        global $DB, $USER;

        $absences = self::count_absences($class, $studentid);

        \absd_log_history(
            $studentid,
            (int)$class->id,
            $absences,
            \absd_level_for_count($absences),
            $action,
            json_encode([
                'sessionid' => $sessionid,
                'logid'     => $logid,
                'statusid'  => $status ? (int)$status->id : null,
                'acronym'   => $status ? (string)$status->acronym : null,
                'previous'  => $previous,
                'by'        => (int)$USER->id,
            ], JSON_UNESCAPED_UNICODE)
        );

        // Refresh the alert/blocking state for this student in this class so the
        // absence counters and levels stay in step with what was just recorded.
        try {
            \absd_recompute_user_class_state($class, $studentid);
        } catch (\Throwable $e) {
            \gmk_log('WARN teacher_attendance recompute failed class=' . $class->id
                . ' user=' . $studentid . ': ' . $e->getMessage());
        }

        // The attendance grade item must be recomputed by Moodle.
        if (!empty($class->attendancemoduleid)) {
            $instance = (int)$DB->get_field('course_modules', 'instance', ['id' => (int)$class->attendancemoduleid]);
            if ($instance > 0) {
                $DB->set_field('grade_items', 'needsupdate', 1, [
                    'courseid' => (int)$class->corecourseid,
                    'itemmodule' => 'attendance',
                    'iteminstance' => $instance,
                ]);
            }
        }
    }

    /**
     * Current absence count for the student in the class, using the same
     * definition as the alert system (revalidation sessions excluded).
     *
     * @param stdClass $class
     * @param int $studentid
     * @return int
     */
    public static function count_absences(stdClass $class, int $studentid): int {
        global $DB;

        $pastids = \absd_get_class_past_session_ids($class, time());
        $takenids = \absd_get_taken_session_ids($pastids);
        if (empty($takenids)) {
            return 0;
        }
        $map = \absd_get_student_absences($takenids, [$studentid]);
        return (int)($map[$studentid] ?? 0);
    }
}
