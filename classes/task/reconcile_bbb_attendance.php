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
 * Scheduled task: reconciles BBB presence into attendance once a virtual session ends.
 *
 * For each session whose last presence sample is older than the grace window (meeting
 * ended), computes ratio = present_seconds / effective_meeting_duration and marks each
 * student in tiers (>=70% Present, >=40% Late, else Absent). Respects manual marks and is
 * idempotent (reconciled flag). Students never seen in BBB are left untouched for the teacher.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\task;

use core\task\scheduled_task;

defined('MOODLE_INTERNAL') || die();

class reconcile_bbb_attendance extends scheduled_task {

    /** @var int A meeting is considered ended when no sample arrived for this long. */
    const ENDED_GRACE_SECONDS = 600;

    /** @var int Skip auto-marking when the effective meeting was shorter than this (unreliable ratio). */
    const MIN_MEETING_SECONDS = 600;

    public function get_name() {
        return 'Reconciliar asistencia BBB (70% de permanencia)';
    }

    public function execute() {
        global $DB;

        $now = time();

        $presentratio = (float)get_config('local_grupomakro_core', 'bbb_attendance_present_ratio');
        if ($presentratio <= 0) {
            $presentratio = 0.70;
        }
        $lateratio = (float)get_config('local_grupomakro_core', 'bbb_attendance_late_ratio');
        if ($lateratio <= 0) {
            $lateratio = 0.40;
        }

        // Sessions ready to reconcile: unreconciled rows whose last sample is past the grace window.
        $sessions = $DB->get_records_sql(
            "SELECT attendancesessionid,
                    MIN(first_seen) AS minfirst,
                    MAX(last_seen)  AS maxlast
               FROM {gmk_bbb_presence}
              WHERE reconciled = 0
           GROUP BY attendancesessionid
             HAVING MAX(last_seen) < :cutoff",
            ['cutoff' => $now - self::ENDED_GRACE_SECONDS]
        );
        if (empty($sessions)) {
            return;
        }

        foreach ($sessions as $s) {
            $sessionid = (int)$s->attendancesessionid;
            $duration  = (int)$s->maxlast - (int)$s->minfirst;
            try {
                $this->reconcile_session($sessionid, $duration, $presentratio, $lateratio, $now);
            } catch (\Throwable $e) {
                mtrace("reconcile_bbb_attendance: session $sessionid failed: " . $e->getMessage());
            }
        }
    }

    /**
     * Reconcile a single attendance session from accumulated presence.
     */
    protected function reconcile_session(int $sessionid, int $duration, float $presentratio, float $lateratio, int $now): void {
        global $DB;

        $session = $DB->get_record('attendance_sessions', ['id' => $sessionid], 'id, attendanceid, groupid');
        if (!$session) {
            // Session no longer exists — mark rows reconciled to stop reprocessing.
            $DB->set_field('gmk_bbb_presence', 'reconciled', 1, ['attendancesessionid' => $sessionid]);
            return;
        }
        $attendanceid = (int)$session->attendanceid;

        $statuses = $this->resolve_statuses($attendanceid);
        if (empty($statuses['present'])) {
            mtrace("reconcile_bbb_attendance: no usable 'present' status for attendance $attendanceid (session $sessionid).");
            $DB->set_field('gmk_bbb_presence', 'reconciled', 1, ['attendancesessionid' => $sessionid]);
            return;
        }

        $members = (int)$session->groupid > 0
            ? array_flip(array_keys($DB->get_records('groups_members', ['groupid' => (int)$session->groupid], '', 'userid')))
            : [];

        $rows   = $DB->get_records('gmk_bbb_presence', ['attendancesessionid' => $sessionid]);
        $marked = 0;

        if ($duration >= self::MIN_MEETING_SECONDS) {
            foreach ($rows as $row) {
                if ((int)$row->ismoderator === 1) {
                    continue; // Teachers/moderators bound the window but are not marked.
                }
                $uid = (int)$row->userid;
                if (!empty($members) && !isset($members[$uid])) {
                    continue; // Only students of this class group.
                }
                // Respect any existing mark (manual by teacher or already present).
                if ($DB->record_exists('attendance_log', ['sessionid' => $sessionid, 'studentid' => $uid])) {
                    continue;
                }

                $ratio = $duration > 0 ? min(1.0, (int)$row->present_seconds / $duration) : 0.0;
                if ($ratio >= $presentratio) {
                    $statusid = $statuses['present'];
                } else if ($ratio >= $lateratio) {
                    $statusid = $statuses['late'] ?: $statuses['present'];
                } else {
                    $statusid = $statuses['absent'];
                }
                if (empty($statusid)) {
                    continue; // No suitable status (e.g. absent tier missing) — leave for teacher.
                }

                $log = new \stdClass();
                $log->sessionid = $sessionid;
                $log->studentid = $uid;
                $log->statusid  = $statusid;
                $log->timetaken = $now;
                $log->remarks   = 'auto 70%: ' . round((int)$row->present_seconds / 60) . 'min/'
                    . round($duration / 60) . 'min (' . round($ratio * 100) . '%)';
                $log->statusset = '0';
                $DB->insert_record('attendance_log', $log);
                $marked++;
            }

            if ($marked > 0) {
                $lasttaken = $DB->get_field('attendance_sessions', 'lasttaken', ['id' => $sessionid]);
                if (empty($lasttaken)) {
                    $DB->set_field('attendance_sessions', 'lasttaken', $now, ['id' => $sessionid]);
                    $DB->set_field('attendance_sessions', 'lasttakenby', 0, ['id' => $sessionid]);
                }
            }
        } else {
            mtrace("reconcile_bbb_attendance: session $sessionid effective duration {$duration}s < min; left for teacher.");
        }

        $DB->set_field('gmk_bbb_presence', 'reconciled', 1, ['attendancesessionid' => $sessionid]);
        mtrace("reconcile_bbb_attendance: session=$sessionid duration={$duration}s marked=$marked.");
    }

    /**
     * Resolve present / late / absent status ids for an attendance instance.
     * Prefers acronyms P / R / FI, with grade-based fallbacks. Excludes the "unmarked" status.
     *
     * @param int $attendanceid
     * @return array{present:?int, late:?int, absent:?int}
     */
    protected function resolve_statuses(int $attendanceid): array {
        global $DB;

        $all = $DB->get_records_select('attendance_statuses',
            "attendanceid = :aid AND deleted = 0 AND setnumber = 0
               AND (setunmarked = 0 OR setunmarked IS NULL OR setunmarked = '')",
            ['aid' => $attendanceid], 'grade DESC');

        $present = null;
        $late    = null;
        $absent  = null;

        foreach ($all as $st) {
            $ac = strtoupper(trim((string)$st->acronym));
            if ($present === null && $ac === 'P')  { $present = (int)$st->id; }
            if ($late === null && $ac === 'R')     { $late    = (int)$st->id; }
            if ($absent === null && $ac === 'FI')  { $absent  = (int)$st->id; }
        }

        if (!empty($all)) {
            $allv = array_values($all);
            if ($present === null) {
                foreach ($allv as $st) {
                    if ((float)$st->grade > 0) { $present = (int)$st->id; break; }
                }
                if ($present === null) {
                    $present = (int)$allv[0]->id;
                }
            }
            if ($absent === null) {
                $last = end($allv);
                $absent = (int)$last->id;
            }
            if ($late === null) {
                foreach ($allv as $st) {
                    $g = (float)$st->grade;
                    if ((int)$st->id !== $present && (int)$st->id !== $absent && $g > 0) {
                        $late = (int)$st->id;
                        break;
                    }
                }
            }
        }

        return ['present' => $present, 'late' => $late, 'absent' => $absent];
    }
}
