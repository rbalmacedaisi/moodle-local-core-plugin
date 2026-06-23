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
 * External: tells the LXP whether the requesting student is allowed to
 * access a given course. When blocked by absence, returns the offending
 * classid so the frontend can show a contextual message.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\student;

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use Exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/pages/absence_helpers.php');

class check_course_access extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'userid'   => new external_value(PARAM_INT, 'User ID', VALUE_REQUIRED),
            'courseid' => new external_value(PARAM_INT, 'Course ID', VALUE_REQUIRED),
        ]);
    }

    public static function execute($userid, $courseid) {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), [
            'userid'   => $userid,
            'courseid' => $courseid,
        ]);

        $context = context_system::instance();
        self::validate_context($context);

        if (!$DB->record_exists('user', ['id' => $params['userid'], 'deleted' => 0])) {
            throw new Exception('user_not_found');
        }

        $payload = absd_get_course_absence_for_user((int)$params['userid'], (int)$params['courseid']);
        if (!$payload) {
            return [
                'allowed'             => true,
                'block_reason'        => '',
                'blocking_classid'    => 0,
                'absence_count'       => 0,
                'alert_level'         => 0,
                'is_blocking_enabled' => absd_is_blocking_enabled(),
            ];
        }

        return [
            'allowed'             => !$payload['blocked'],
            'block_reason'        => $payload['blocked'] ? 'attendance_threshold_reached' : '',
            'blocking_classid'    => $payload['blocked'] ? $payload['classid'] : 0,
            'absence_count'       => $payload['count'],
            'alert_level'         => $payload['level'],
            'is_blocking_enabled' => absd_is_blocking_enabled(),
        ];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'allowed'             => new external_value(PARAM_BOOL, 'True when the student can access the course'),
            'block_reason'        => new external_value(PARAM_TEXT, 'Reason of the block (empty if allowed)'),
            'blocking_classid'    => new external_value(PARAM_INT, 'The class blocking access (0 if allowed)'),
            'absence_count'       => new external_value(PARAM_INT, 'Absence count for the class that maps to the course'),
            'alert_level'         => new external_value(PARAM_INT, 'Alert level (0=none, 1=info, 2=warning, 3=blocked)'),
            'is_blocking_enabled' => new external_value(PARAM_BOOL, 'True when the soft-launch blocking sub-flag is on'),
        ]);
    }
}
