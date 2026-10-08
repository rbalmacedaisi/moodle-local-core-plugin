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
 * Enrol a student into a gmk_class from the failed-subjects report. If
 * the class is full the caller can pass force_over=true to bypass the
 * quota check (the action is logged in gmk_class_absence_history).
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Antigravity
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\admin\failed_subjects;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/failed_subjects_manager.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use context_system;

class enrol_student extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userid'     => new external_value(PARAM_INT, 'Moodle user id'),
            'classid'    => new external_value(PARAM_INT, 'gmk_class id'),
            'force_over' => new external_value(PARAM_BOOL, 'Bypass quota check', VALUE_DEFAULT, false),
        ]);
    }

    public static function execute(int $userid, int $classid, bool $force_over = false): array {
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:enrol_from_failed_subjects_report', $context);

        $result = \local_grupomakro_core\local\failed_subjects_manager::enrol_student_in_class(
            $userid,
            $classid,
            $force_over
        );
        return $result;
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status'            => new external_value(PARAM_RAW, 'ok|quota_exceeded|already_enrolled|error'),
            'message'           => new external_value(PARAM_RAW, 'Human-readable message'),
            'enrolled_count'    => new external_value(PARAM_INT, 'Current enrollment count after operation', VALUE_OPTIONAL),
            'classroomcapacity' => new external_value(PARAM_INT, 'Classroom capacity', VALUE_OPTIONAL),
            'forced'            => new external_value(PARAM_BOOL, 'Whether this was a forced enrolment over quota', VALUE_OPTIONAL),
        ]);
    }
}
