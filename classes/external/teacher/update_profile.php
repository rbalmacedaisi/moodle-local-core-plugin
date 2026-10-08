<?php
namespace local_grupomakro_core\external\teacher;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/user/lib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use stdClass;
use context_user;

class update_profile extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters(
            array(
                'userid' => new external_value(PARAM_INT, 'The ID of the user', VALUE_REQUIRED),
                'firstname' => new external_value(PARAM_TEXT, 'The first name of the user', VALUE_OPTIONAL),
                'lastname' => new external_value(PARAM_TEXT, 'The last name of the user', VALUE_OPTIONAL),
                'email' => new external_value(PARAM_EMAIL, 'The email of the user', VALUE_OPTIONAL),
                'phone1' => new external_value(PARAM_TEXT, 'The phone number of the user', VALUE_OPTIONAL),
                'description' => new external_value(PARAM_RAW, 'The description of the user', VALUE_OPTIONAL)
            )
        );
    }

    public static function execute($userid, $firstname = '', $lastname = '', $email = '', $phone1 = '', $description = '') {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), array(
            'userid' => $userid,
            'firstname' => $firstname,
            'lastname' => $lastname,
            'email' => $email,
            'phone1' => $phone1,
            'description' => $description
        ));

        // Context validation
        $context = context_user::instance($params['userid']);
        self::validate_context($context);

        // Security check: ensure the user is updating their own profile or has permission
        if ($USER->id != $params['userid']) {
             require_capability('moodle/user:update', $context);
        }

        $user = $DB->get_record('user', array('id' => $params['userid']), '*', MUST_EXIST);

        $user->firstname = !empty($params['firstname']) ? $params['firstname'] : $user->firstname;
        $user->lastname = !empty($params['lastname']) ? $params['lastname'] : $user->lastname;
        $user->email = !empty($params['email']) ? $params['email'] : $user->email;
        $user->phone1 = isset($params['phone1']) ? $params['phone1'] : $user->phone1;
        $user->description = isset($params['description']) ? $params['description'] : $user->description;

        // Use Moodle's user_update_user to handle triggers and other internal logic
        user_update_user($user, true, false);

        return array(
            'status' => true,
            'message' => 'Profile updated successfully'
        );
    }

    public static function execute_returns() {
        return new external_single_structure(
            array(
                'status' => new external_value(PARAM_BOOL, 'Status of the operation'),
                'message' => new external_value(PARAM_TEXT, 'Message regarding the operation')
            )
        );
    }
}
