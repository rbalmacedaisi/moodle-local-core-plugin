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

class change_password extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters(
            array(
                'userid' => new external_value(PARAM_INT, 'The ID of the user', VALUE_REQUIRED),
                'currentpassword' => new external_value(PARAM_RAW, 'The current password', VALUE_REQUIRED),
                'newpassword' => new external_value(PARAM_RAW, 'The new password', VALUE_REQUIRED)
            )
        );
    }

    public static function execute($userid, $currentpassword, $newpassword) {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), array(
            'userid' => $userid,
            'currentpassword' => $currentpassword,
            'newpassword' => $newpassword
        ));

        // Context validation
        $context = context_user::instance($params['userid']);
        self::validate_context($context);

        // Security check: ensure the user is changing their own password
        // Even admins should probably use a reset flow, but ignoring that for this specific teacher use case
        if ($USER->id != $params['userid']) {
             require_capability('moodle/user:update', $context);
        }

        $user = $DB->get_record('user', array('id' => $params['userid']), '*', MUST_EXIST);

        // Check if current password matches
        if (!validate_internal_user_password($user, $params['currentpassword'])) {
             throw new \moodle_exception('invalidcurrentpassword', 'local_grupomakro_core');
        }

        if (update_internal_user_password($user, $params['newpassword'])) {
            return array(
                'status' => true,
                'message' => 'Password changed successfully'
            );
        } else {
             return array(
                'status' => false,
                'message' => 'Could not change password'
            );
        }
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
