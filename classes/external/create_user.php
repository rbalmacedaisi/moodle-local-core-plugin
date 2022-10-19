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

use context_system;
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

        // Let's see if the user already exists.
        $user = $DB->get_record('user', ['email' => $params['email']]);

        if ($user) {
            // The user already exists, let's return it.
            return ['status' => $user->id, 'message' => 'User already exists.'];
        }

        // Let's generate a password for the new user.
        $password = generate_password();

        // Let's create the new user.
        $user = create_user_record($params['email'], $password, 'manual');

        

        // Let's update the user's name.
        $user->firstname = $params['firstname'];
        $user->lastname = $params['lastname'];

        // Let's update the user.
        $DB->update_record('user', $user);

        // Let's see if the "usertype" custom field exists.
        $usertypefield = $DB->get_record('user_info_field', ['shortname' => 'usertype']);

        // If the field exists, then let's update the user's usertype.
        if ($usertypefield) {
            $DB->set_field('user_info_data', 'data', $params['usertype'], ['userid' => $user->id, 'fieldid' => $usertypefield->id]);
        }

        // If the "usertype" is "caregiver", then let's assign the "caregiver" role to the user.
        if ($params['usertype'] == 2) {
            $role = $DB->get_record('role', ['shortname' => 'caregiver']);
            role_assign($role->id, $user->id, context_system::instance());
        }

        // Get the emailtemplates_welcomemessage_student and
        // emailtemplates_welcomemessage_caregiver settings from the
        // local_grupomakro_core plugin.
        $welcomemessagestudent = get_config('local_grupomakro_core', 'emailtemplates_welcomemessage_student');

        $welcomemessagecaregiver = get_config('local_grupomakro_core', 'emailtemplates_welcomemessage_caregiver');

        // Replace the placeholders in both templates:
        // - {firstname} with the user's first name.
        // - {lastname} with the user's last name.
        // - {email} with the user's email.
        // - {password} with the user's password.
        $welcomemessagestudent = str_replace(
            ['{firstname}', '{lastname}', '{email}', '{password}'],
            [$user->firstname, $user->lastname, $user->email, $password],
            $welcomemessagestudent
        );

        $welcomemessagecaregiver = str_replace(
            ['{firstname}', '{lastname}', '{email}', '{password}'],
            [$user->firstname, $user->lastname, $user->email, $password],
            $welcomemessagecaregiver
        );

        // Get the contact email address from the site settings.
        $contactemail = get_config('moodle', 'supportemail');

        // If teh usertype is "student", then let's send the student welcome message.
        if ($params['usertype'] == 1) {
            email_to_user($user, $contactemail, get_string('emailtemplates_welcomemessage_subject', 'local_grupomakro_core'), $welcomemessagestudent);
        } else {
            // If the usertype is "caregiver", then let's send the caregiver welcome message.
            email_to_user($user, $contactemail, get_string('emailtemplates_welcomemessage_subject', 'local_grupomakro_core'), $welcomemessagecaregiver);
        }

        // Return the result.
        return ['status' => $user->id, 'message' => 'ok'];
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
