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
 * local_grupomakro_msg_get_contacts: who the caller can write to.
 *
 * Student: open classes with their teachers. Teacher: open classes, and the
 * students of one class when classid is given.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\messaging;

use context_system;
use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use local_grupomakro_core\local\messaging\manager;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class get_contacts extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'classid' => new external_value(PARAM_INT, 'Teacher only: include the students of this class', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute($classid = 0): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['classid' => $classid]);
        self::validate_context(context_system::instance());
        return manager::contacts((int)$USER->id, (int)$params['classid']);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'role'    => new external_value(PARAM_ALPHA, 'student | teacher'),
            'classes' => new external_multiple_structure(new external_single_structure([
                'classid'    => new external_value(PARAM_INT, 'gmk_class id'),
                'classname'  => new external_value(PARAM_TEXT, 'Class name'),
                'courseid'   => new external_value(PARAM_INT, 'Core course id'),
                'coursename' => new external_value(PARAM_TEXT, 'Core course name'),
                'threadid'   => new external_value(PARAM_INT, 'Student only: existing thread, 0 if none'),
                'unread'     => new external_value(PARAM_INT, 'Student only: unread in that thread'),
                'teachers'   => new external_multiple_structure(structures::user(), 'Student only: teachers of the class'),
                'students'   => new external_multiple_structure(structures::user(), 'Teacher only: students of the requested class'),
            ])),
        ]);
    }
}
