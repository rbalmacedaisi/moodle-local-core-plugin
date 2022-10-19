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
 * Class definition for the local_grupomakro_create_user external function.
 *
 * @package    local_grupomakro_core
 * @copyright  2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external;

use stdClass;
use external_api;
use external_description;
use external_function_parameters;
use external_single_structure;
use external_value;

defined('MOODLE_INTERNAL') || die();

require_once $CFG->libdir . '/externallib.php';

/**
 * External function 'local_grupomakro_create_user' implementation.
 *
 * @package     local_soluttolms_core
 * @category    external
 * @copyright   2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_user extends external_api {

    /**
     * Describes parameters of the {@see self::execute()} method.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'firstname' => new external_value(PARAM_TEXT, 'First name of the user.'),
                'lastname' => new external_value(PARAM_TEXT, 'Last name of the user.'),
                'email' => new external_value(PARAM_TEXT, 'Email of the user.'),
                'usertype' => new external_value(PARAM_INT, 'The type of user: 1 for student, 2 fore caregiver.', VALUE_DEFAULT, 1),
            ]
        );
    }

    /**
     * TODO describe what the function actually does.
     *
     * @param int $userid
     * @return mixed TODO document
     */
    public static function execute(string $firstname, string $lastname, string $email, int $usertype = 1) {
        global $DB;

        // Validate the parameters passed to the function.
        $params = self::validate_parameters(self::execute_parameters(), [
            'firstname' => $firstname,
            'lastname' => $lastname,
            'email' => $email,
            'usertype' => $usertype,
        ]);

        // TODO implement the function.

        // Return the result.
        return ['result' => 1, 'message' => 'ok'];
    }

    /**
     * Describes the return value of the {@see self::execute()} method.
     *
     * @return external_description
     */
    public static function execute_returns(): external_description {
        return new external_single_structure(
            array(
                'status' => new external_value(PARAM_INT, 'The ID of the new user or -1 if there was an error.'),
                'message' => new external_value(PARAM_TEXT, 'The error message or Ok.'),
            )
        );
    }
}
