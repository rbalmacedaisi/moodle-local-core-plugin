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
 * External: returns per-class absence state and the global inactivity
 * flag for the requesting student. Powers the LXP absence banner,
 * dashboard indicators and warning popups.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\student;

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_multiple_structure;
use external_value;
use Exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/pages/absence_helpers.php');

class get_absence_summary extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'User ID', VALUE_REQUIRED),
        ]);
    }

    public static function execute($userid) {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), ['userid' => $userid]);

        $context = context_system::instance();
        self::validate_context($context);

        if (!$DB->record_exists('user', ['id' => $params['userid'], 'deleted' => 0])) {
            throw new Exception('user_not_found');
        }

        $summary = absd_build_absence_summary((int)$params['userid']);
        return $summary;
    }

    public static function execute_returns() {
        $classstructure = new external_single_structure([
            'classid'           => new external_value(PARAM_INT, 'Class id (gmk_class.id)'),
            'courseid'          => new external_value(PARAM_INT, 'Course id'),
            'coursename'        => new external_value(PARAM_TEXT, 'Course fullname'),
            'absence_count'     => new external_value(PARAM_INT, 'Number of absences in the class'),
            'alert_level'       => new external_value(PARAM_INT, '0=none, 1=info, 2=warning, 3=blocked'),
            'info_dismissed'    => new external_value(PARAM_BOOL, 'Student dismissed the info alert'),
            'warning_dismissed' => new external_value(PARAM_BOOL, 'Student dismissed the warning alert'),
            'blocked'           => new external_value(PARAM_BOOL, 'Class access is blocked'),
            'block_reason'      => new external_value(PARAM_TEXT, 'Reason of the block'),
            'last_calculated'   => new external_value(PARAM_INT, 'Unix timestamp of the last recompute'),
        ]);

        return new external_single_structure([
            'classes'               => new external_multiple_structure($classstructure, 'Per-class absence states'),
            'is_globally_inactive'  => new external_value(PARAM_BOOL, 'True when the student has no non-blocked cursando class'),
            'has_any_blocked_class' => new external_value(PARAM_BOOL, 'True when at least one class is blocked'),
            'open_class_count'      => new external_value(PARAM_INT, 'Number of non-blocked cursando classes'),
            'blocked_class_count'   => new external_value(PARAM_INT, 'Number of blocked cursando classes'),
            'is_blocking_enabled'   => new external_value(PARAM_BOOL, 'True when the soft-launch blocking sub-flag is on'),
        ]);
    }
}