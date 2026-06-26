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
 * External: trigger a recompute of the requesting student's absence state
 * across all of their active classes. Useful right after login so the
 * dashboard / overview banners are up to date before the user navigates.
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

class refresh_absence_state extends external_api {

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

        $nowts = time();
        $planfilter = absd_get_alert_plan_filter_sql();
        $classes = $DB->get_records_sql(
            "SELECT gc.id, gc.courseid, gc.corecourseid, gc.groupid, gc.attendancemoduleid, gc.initdate, gc.enddate
               FROM {gmk_course_progre} gcp
               JOIN {gmk_class} gc ON gc.id = gcp.classid
              WHERE gcp.userid = :uid
                AND gcp.status = 2
                AND gc.approved = 1
                AND gc.closed = 0
                AND gc.enddate > :now
                AND {$planfilter['sql']}",
            array_merge(['uid' => (int)$params['userid'], 'now' => $nowts], $planfilter['params'])
        );

        $processed = 0;
        $blocked = 0;
        foreach ($classes as $class) {
            $result = absd_recompute_user_class_state($class, (int)$params['userid']);
            $processed++;
            if (in_array('block', $result['transitions'], true)) {
                if (!absd_is_user_exempt((int)$params['userid'], (int)$class->id)) {
                    absd_apply_class_block((int)$params['userid'], (int)$class->id, 'attendance_threshold_reached');
                    $blocked++;
                }
            }
        }

        return [
            'success'   => true,
            'processed' => $processed,
            'blocked'   => $blocked,
        ];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'success'   => new external_value(PARAM_BOOL, 'True when the recompute ran'),
            'processed' => new external_value(PARAM_INT, 'Number of active classes processed'),
            'blocked'   => new external_value(PARAM_INT, 'Number of classes newly blocked in this call'),
        ]);
    }
}
