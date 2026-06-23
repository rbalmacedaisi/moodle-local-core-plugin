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
 * External: persist dismissal of an absence alert (info=1 or warning=2)
 * for a specific (user, class) pair.
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

class dismiss_absence_alert extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'userid'  => new external_value(PARAM_INT, 'User ID', VALUE_REQUIRED),
            'classid' => new external_value(PARAM_INT, 'Class ID (gmk_class.id)', VALUE_REQUIRED),
            'level'   => new external_value(PARAM_INT, '1=info, 2=warning', VALUE_REQUIRED),
        ]);
    }

    public static function execute($userid, $classid, $level) {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), [
            'userid'  => $userid,
            'classid' => $classid,
            'level'   => $level,
        ]);

        $context = context_system::instance();
        self::validate_context($context);

        if (!in_array((int)$params['level'], [1, 2], true)) {
            throw new Exception('invalid_level');
        }

        if (!$DB->record_exists('user', ['id' => $params['userid'], 'deleted' => 0])) {
            throw new Exception('user_not_found');
        }
        if (!$DB->record_exists('gmk_class', ['id' => $params['classid']])) {
            throw new Exception('class_not_found');
        }

        absd_dismiss_user_alert((int)$params['userid'], (int)$params['classid'], (int)$params['level']);

        return [
            'success' => true,
            'userid'  => (int)$params['userid'],
            'classid' => (int)$params['classid'],
            'level'   => (int)$params['level'],
        ];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'True when the dismissal was recorded'),
            'userid'  => new external_value(PARAM_INT, 'User id'),
            'classid' => new external_value(PARAM_INT, 'Class id'),
            'level'   => new external_value(PARAM_INT, 'Alert level dismissed'),
        ]);
    }
}
