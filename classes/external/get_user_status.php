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
 * Class definition for the local_grupomakro_get_user_status function.
 *
 * @package    local_grupomakro_core
 * @copyright  2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external;

use context_system;
use external_api;
use external_description;
use external_function_parameters;
use external_single_structure;
use external_value;

defined('MOODLE_INTERNAL') || die();

require_once $CFG->libdir . '/externallib.php';

/**
 * External function 'local_grupomakroget_user_status' implementation.
 *
 * @package     local_soluttolms_core
 * @category    external
 * @copyright   2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_user_status extends external_api {

    /**
     * Describes parameters of the {@see self::execute()} method.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'userid' => new external_value(PARAM_INT, 'The user id'),
            ]
        );
    }

    /**
     * TODO describe what the function actually does.
     *
     * @param int $userid
     * @return mixed TODO document
     */
    public static function execute(int $userid) {

        // Global variables.
        global $DB;

        // Validate the parameters passed to the function.
        $params = self::validate_parameters(self::execute_parameters(), [
            'userid' => $userid
        ]);

        // Let's see if the user exists.
        $user = $DB->get_record('user', ['id' => $params['userid']]);

        // If the user doesn't exist, return an error.
        if (!$user) {
            return ['status' => -1, 'message' => 'User not found'];
        }

        // Let's get the value of the custom field "needfirsttuition" for the user.
        $field = $DB->get_record('user_info_field', array('shortname' => 'needfirsttuition'));

        // If the field doesn't exist, return an error.
        if (!$field) {
            return ['status' => -1, 'message' => 'Custom field not found'];
        }

        $needfirsttuition = $DB->get_record('user_info_data', ['userid' => $params['userid'], 'fieldid' => $field->id]);

        if ($needfirsttuition->data == 'si') {
            return ['status' => 1, 'message' => 'User has to pay his/her first tuition'];
        } else {
            return ['status' => 2, 'message' => 'User has already paid his/her first tuition'];
        }
    }

    /**
     * Describes the return value of the {@see self::execute()} method.
     *
     * @return external_description
     */
    public static function execute_returns(): external_description {
        return new external_single_structure(
            array(
                'status' => new external_value(PARAM_INT, 'Status code or -1 if there was an error.'),
                'message' => new external_value(PARAM_TEXT, 'The error message or status description.'),
            )
        );
    }
}
