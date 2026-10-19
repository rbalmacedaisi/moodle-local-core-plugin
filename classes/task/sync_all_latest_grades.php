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
 * Scheduled task: weekly full reconciliation of the stored grades.
 *
 * Safety net behind the event-driven and incremental syncs: anything that
 * changes a grade without leaving a trace those can see (a script writing the
 * database directly, an undone mark restoring an old timestamp) is corrected
 * here. Same work as cli/sync_latest_grades.php --apply.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\task;

use local_grupomakro_core\course_grade_resolver;

defined('MOODLE_INTERNAL') || die();

class sync_all_latest_grades extends \core\task\scheduled_task {

    public function get_name() {
        return 'Conciliación semanal de notas (última nota)';
    }

    public function execute() {
        global $DB;

        if (!get_config('local_grupomakro_core', 'latest_grade_sync_enabled')) {
            mtrace('latest_grade_sync_enabled = 0: nothing to do.');
            return;
        }
        $pairs = $DB->get_recordset_sql(
            "SELECT DISTINCT p.userid, p.courseid
               FROM {gmk_course_progre} p
               JOIN {user} u ON u.id = p.userid
              WHERE p.courseid > 1 AND u.deleted = 0");
        $n = 0;
        $changed = 0;
        foreach ($pairs as $p) {
            try {
                $changed += count(course_grade_resolver::sync_progress((int)$p->userid, (int)$p->courseid, true));
            } catch (\Throwable $e) {
                mtrace("  ERROR user {$p->userid} course {$p->courseid}: " . $e->getMessage());
            }
            $n++;
        }
        $pairs->close();
        mtrace("Weekly grade reconciliation: {$n} student-subject pairs, {$changed} rows updated.");
    }
}
