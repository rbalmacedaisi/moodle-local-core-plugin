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
 * Scheduled task: detect and self-heal BBB<->attendance linkage inconsistencies.
 *
 * Three failure modes this task guards against (all of which surfaced on
 * 2026-09-17 when a docente reported "no hay sesion vinculada"):
 *
 *   1. gmk_bbb_attendance_relation rows whose bbbmoduleid is NULL.
 *      Symptom: docente clicks "iniciar sesion" and gets the placeholder
 *      message because the relation is missing the BBB pointer.
 *      Action: log + email the class teacher. Self-heal is intentionally
 *      NOT attempted here because creating a fresh BBB module mid-term
 *      changes the moderator URLs the students already have; the teacher
 *      needs to be aware.
 *
 *   2. gmk_bbb_attendance_relation rows pointing at a deleted class
 *      (classid not in gmk_class). These come from old migrations where
 *      the deletion flow did not clean up the relation table. They are
 *      pure garbage.
 *      Action: hard-delete.
 *
 *   3. gmk_class.bbbmoduleids out of sync with the actual cmids in the
 *      relations. Happens when relations are inserted directly into the
 *      table without going through create_class_activities.
 *      Action: recompute from relations (cheap sync).
 *
 * The task runs every 6 hours. Daily summary goes to error_log via
 * gmk_log_strong() so CloudWatch / Datadog sees it.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\task;

defined('MOODLE_INTERNAL') || die();

class audit_bbb_linkage extends \core\task\scheduled_task {

    public function get_name() {
        return 'Auditar y reparar vinculo BBB <-> asistencia';
    }

    public function execute() {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

        $stats = [
            'dangling_classid' => 0,
            'bbbmoduleid_null_open' => 0,
            'bbbmoduleid_null_closed' => 0,
            'bbbmoduleids_resynced' => 0,
            'notified_teachers' => 0,
            'notification_errors' => 0,
        ];

        // (1) Purge relations whose classid no longer exists. These are
        //     referential garbage from legacy deletion paths.
        $sql = "DELETE FROM {gmk_bbb_attendance_relation}
                 WHERE classid NOT IN (SELECT id FROM {gmk_class})";
        $stats['dangling_classid'] = (int)$DB->execute($sql);

        // (2) Count open classes with bbbmoduleid NULL. We DO NOT auto-heal
        //     here (a fresh BBB module mid-term changes the URLs the
        //     students already saved). We just notify the teacher.
        $nullRows = $DB->get_records_sql("
            SELECT r.id, r.classid, c.name AS classname, c.instructorid,
                   c.supportinstructorid, c.corecourseid, c.coursesectionid,
                   c.groupid, c.bbbmoduleids
              FROM {gmk_bbb_attendance_relation} r
              JOIN {gmk_class} c ON c.id = r.classid
             WHERE r.bbbmoduleid IS NULL
               AND c.closed = 0
        ");

        $nullByClass = [];
        foreach ($nullRows as $row) {
            $cid = (int)$row->classid;
            if (!isset($nullByClass[$cid])) {
                $nullByClass[$cid] = [
                    'classname' => (string)$row->classname,
                    'instructorid' => (int)$row->instructorid,
                    'supportinstructorid' => (int)$row->supportinstructorid,
                    'count' => 0,
                ];
            }
            $nullByClass[$cid]['count']++;
        }
        $stats['bbbmoduleid_null_open'] = count($nullByClass);

        // Also count the closed-class leftovers (informational only; we do
        // not notify and do not auto-heal because the docente is gone).
        $stats['bbbmoduleid_null_closed'] = (int)$DB->count_records_sql("
            SELECT COUNT(*)
              FROM {gmk_bbb_attendance_relation} r
              JOIN {gmk_class} c ON c.id = r.classid
             WHERE r.bbbmoduleid IS NULL AND c.closed = 1
        ");

        // (3) Resync bbbmoduleids for any class where the field drifts from
        //     what the relations actually contain. Cheap and safe: just
        //     rebuild the comma list from the relations table.
        $candidates = $DB->get_records_sql("
            SELECT c.id, c.bbbmoduleids
              FROM {gmk_class} c
             WHERE c.closed = 0
        ");
        foreach ($candidates as $cand) {
            $cmids = [];
            $rels = $DB->get_records('gmk_bbb_attendance_relation',
                ['classid' => (int)$cand->id], '', 'bbbmoduleid');
            foreach ($rels as $r) {
                if (!empty($r->bbbmoduleid)) {
                    $cmids[(int)$r->bbbmoduleid] = true;
                }
            }
            $computed = $cmids ? implode(',', array_keys($cmids)) : null;
            if ($computed !== (string)$cand->bbbmoduleids) {
                $DB->set_field('gmk_class', 'bbbmoduleids', $computed, ['id' => (int)$cand->id]);
                $stats['bbbmoduleids_resynced']++;
            }
        }

        // Notify each affected teacher once per run (consolidated).
        foreach ($nullByClass as $cid => $info) {
            foreach ([$info['instructorid'], $info['supportinstructorid']] as $teacherid) {
                if ($teacherid <= 0) {
                    continue;
                }
                try {
                    if ($this->notify_teacher((int)$teacherid, (int)$cid, $info['classname'], $info['count'])) {
                        $stats['notified_teachers']++;
                    }
                } catch (\Throwable $e) {
                    $stats['notification_errors']++;
                    mtrace('  ERROR notificando al docente ' . $teacherid . ': ' . $e->getMessage());
                }
            }
        }

        // Always-on log so on-call sees the audit result regardless of
        // GMK_DEBUG_LOG being enabled.
        if (function_exists('gmk_log_strong')) {
            gmk_log_strong('info', 'audit_bbb_linkage: ' . json_encode($stats));
        }
        mtrace('audit_bbb_linkage complete: ' . json_encode($stats));
    }

    /**
     * Sends a one-shot message per teacher listing the broken class and the
     * count of orphan BBB relations it still has.
     *
     * @param int $teacherid
     * @param int $classid
     * @param string $classname
     * @param int $count
     * @return bool
     */
    protected function notify_teacher(int $teacherid, int $classid, string $classname, int $count): bool {
        $user = \core_user::get_user($teacherid);
        if (!$user || $user->deleted || $user->suspended) {
            return false;
        }

        $url = (new \moodle_url('/grade/edit/tree/index.php',
            ['id' => 0]))->out(false); // courseid resolved by teacher in their dashboard
        $subject = "Tu clase {$classname} tiene {$count} sesion(es) de asistencia sin sala BBB vinculada";
        $intro = "La(s) siguiente(s) sesion(es) de asistencia de la clase {$classname} "
               . "no tienen una sala BBB vinculada en Moodle, por lo que el boton "
               . "'Iniciar sesion' mostrara el mensaje de 'no hay sesion vinculada'. "
               . "Esto sucede cuando el plugin no pudo crear la sala al momento de "
               . "armar la clase (ej: calendario del curso bloqueado por edicion "
               . "concurrente). Para corregirlo el administrador del sistema puede "
               . "correr el CLI cli/repair_orphan_bbb_create_relation.php con el ID "
               . "de la clase {$classid} y la opcion --apply.";
        $plain = $intro . "\n\nClase ID: {$classid}\nSesiones afectadas: {$count}";
        $html = '<p>' . s($intro) . '</p><p><strong>Clase:</strong> ' . s($classname)
              . '<br><strong>ID:</strong> ' . (int)$classid
              . '<br><strong>Sesiones sin BBB:</strong> ' . (int)$count . '</p>';

        $message = new \core\message\message();
        $message->component = 'local_grupomakro_core';
        $message->name = 'gradebook_weights_incomplete'; // reuse provider that admins route
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $user;
        $message->subject = $subject;
        $message->fullmessage = $plain;
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = $html;
        $message->smallmessage = $subject;
        $message->notification = 1;

        return (bool)message_send($message);
    }
}
