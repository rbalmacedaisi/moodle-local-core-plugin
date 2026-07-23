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
 * Returns the learning plans and their courses to populate the Homologation
 * Manager dropdowns.
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Solutto Consulting <dev@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\homologation;

use context_system;

defined('MOODLE_INTERNAL') || die();

/**
 * get_form_data external function.
 */
class get_form_data {

    /**
     * @return array {status, plans:[{id,name}], courses:{planid:[{id,name}]}}
     */
    public static function execute(): array {
        global $DB;

        require_capability('moodle/site:config', context_system::instance());

        $plans = [];
        $planrows = $DB->get_records('local_learning_plans', null, 'name ASC', 'id, name');
        foreach ($planrows as $p) {
            $plans[] = ['id' => (int)$p->id, 'name' => (string)$p->name];
        }

        // Courses per plan (pensum), ordered by position.
        $courses = [];
        $rows = $DB->get_records_sql(
            "SELECT lpc.id AS lpcid, lpc.learningplanid, lpc.courseid, lpc.position, c.fullname
               FROM {local_learning_courses} lpc
               JOIN {course} c ON c.id = lpc.courseid
           ORDER BY lpc.learningplanid, lpc.position ASC"
        );
        foreach ($rows as $r) {
            $pid = (int)$r->learningplanid;
            if (!isset($courses[$pid])) {
                $courses[$pid] = [];
            }
            $courses[$pid][] = ['id' => (int)$r->courseid, 'name' => (string)$r->fullname];
        }

        return [
            'status'  => 'ok',
            'plans'   => $plans,
            'courses' => $courses,
        ];
    }
}
