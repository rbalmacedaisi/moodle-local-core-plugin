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
 * Ad hoc task: re-sync the stored grade of every student of a class.
 *
 * Queued by gmk_queue_class_grade_sync() when attendance sessions of the class
 * are deleted: their logs disappear without leaving a changed row behind, so the
 * incremental sync_grades_after_attendance task cannot see them.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\task;

use local_grupomakro_core\course_grade_resolver;

defined('MOODLE_INTERNAL') || die();

class sync_grades_adhoc extends \core\task\adhoc_task {

    public function execute() {
        global $DB;

        if (!get_config('local_grupomakro_core', 'latest_grade_sync_enabled')) {
            return;
        }
        $data = $this->get_custom_data();
        $class = $DB->get_record('gmk_class', ['id' => (int)($data->classid ?? 0)], 'id, groupid, corecourseid');
        if (!$class || (int)$class->corecourseid <= 0 || (int)$class->groupid <= 0) {
            return;
        }
        $userids = $DB->get_fieldset_sql(
            "SELECT DISTINCT gm.userid
               FROM {groups_members} gm
               JOIN {gmk_course_progre} p ON p.userid = gm.userid AND p.courseid = :c
              WHERE gm.groupid = :g",
            ['g' => (int)$class->groupid, 'c' => (int)$class->corecourseid]);
        $changed = 0;
        foreach ($userids as $uid) {
            try {
                $changed += count(course_grade_resolver::sync_progress((int)$uid, (int)$class->corecourseid, true));
            } catch (\Throwable $e) {
                mtrace("  ERROR user {$uid} class {$class->id}: " . $e->getMessage());
            }
        }
        mtrace("Grade re-sync for class {$class->id}: " . count($userids) . " students, {$changed} rows updated.");
    }
}
