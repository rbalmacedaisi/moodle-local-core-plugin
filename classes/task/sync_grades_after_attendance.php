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
 * Scheduled task: refresh the stored subject grade after attendance changes.
 *
 * The class grade includes an attendance item recomputed from the attendance
 * log, but gmk_course_progre is only re-synced when someone grades. Attendance
 * taken or corrected, and sessions deleted (false sessions, reschedules), change
 * that grade without any grading event, so the stored grade drifts. This task
 * re-syncs every (student, subject) whose class attendance changed since the
 * previous run.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\task;

use local_grupomakro_core\course_grade_resolver;

defined('MOODLE_INTERNAL') || die();

class sync_grades_after_attendance extends \core\task\scheduled_task {

    public function get_name() {
        return 'Actualizar notas tras cambios de asistencia';
    }

    public function execute() {
        global $DB;

        if (!get_config('local_grupomakro_core', 'latest_grade_sync_enabled')) {
            mtrace('latest_grade_sync_enabled = 0: nothing to do.');
            return;
        }

        $now = time();
        // First run: look back one day plus a margin.
        $since = (int)get_config('local_grupomakro_core', 'attendance_grade_sync_lastrun') ?: $now - 26 * HOURSECS;

        // 1. Attendance taken or corrected: the student of each changed log row.
        $pairs = $DB->get_records_sql(
            "SELECT DISTINCT " . $DB->sql_concat('l.studentid', "'-'", 'c.corecourseid') . " AS k,
                    l.studentid AS userid, c.corecourseid AS courseid
               FROM {attendance_log} l
               JOIN {attendance_sessions} s ON s.id = l.sessionid
               JOIN {course_modules} cm ON cm.instance = s.attendanceid
               JOIN {modules} m ON m.id = cm.module AND m.name = 'attendance'
               JOIN {gmk_class} c ON c.attendancemoduleid = cm.id AND c.groupid = s.groupid
              WHERE l.timetaken >= :since AND c.corecourseid > 0",
            ['since' => $since]);

        // 2. Sessions deleted: every student of the class, the session's log is gone.
        $deleted = $DB->get_records_sql(
            "SELECT DISTINCT " . $DB->sql_concat('gm.userid', "'-'", 'c.corecourseid') . " AS k,
                    gm.userid, c.corecourseid AS courseid
               FROM {logstore_standard_log} lg
               JOIN {gmk_class} c ON c.attendancemoduleid = lg.contextinstanceid
               JOIN {groups_members} gm ON gm.groupid = c.groupid
              WHERE lg.component = 'mod_attendance' AND lg.eventname = :ev
                AND lg.timecreated >= :since AND c.corecourseid > 0",
            ['ev' => '\\mod_attendance\\event\\session_deleted', 'since' => $since]);
        $pairs += $deleted;

        $synced = 0;
        $changed = 0;
        foreach ($pairs as $p) {
            if (!$DB->record_exists('gmk_course_progre', ['userid' => $p->userid, 'courseid' => $p->courseid])) {
                continue;
            }
            try {
                $changed += count(course_grade_resolver::sync_progress((int)$p->userid, (int)$p->courseid, true));
                $synced++;
            } catch (\Throwable $e) {
                mtrace("  ERROR user {$p->userid} course {$p->courseid}: " . $e->getMessage());
            }
        }

        set_config('attendance_grade_sync_lastrun', $now, 'local_grupomakro_core');
        mtrace("Attendance-driven grade sync: {$synced} student-subject pairs checked, {$changed} rows updated.");
    }
}
