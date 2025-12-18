<?php
namespace local_grupomakro_core\external\odoo;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
// Assuming delete_learning_user.php exists based on file listing
require_once($CFG->dirroot . '/local/sc_learningplans/external/user/delete_learning_user.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use moodle_exception;

class unenroll_student extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters(
            array(
                'product_name' => new external_value(PARAM_TEXT, 'The name of the Odoo Product (Moodle Learning Plan Name)', VALUE_REQUIRED),
                'username'     => new external_value(PARAM_USERNAME, 'The username of the student', VALUE_REQUIRED)
            )
        );
    }

    public static function execute($product_name, $username) {
        global $DB;

        // Validation
        $params = self::validate_parameters(self::execute_parameters(), array(
            'product_name' => $product_name,
            'username'     => $username
        ));

        // 1. Resolve User
        $user = $DB->get_record('user', ['username' => $params['username'], 'deleted' => 0]);
        if (!$user) {
            throw new moodle_exception('invaliduser', 'error', '', $params['username']);
        }

        // 2. Resolve Learning Plan
        $plan = $DB->get_record('local_learning_plans', ['name' => $params['product_name']]);
        if (!$plan) {
            throw new moodle_exception('invalidlearningplan', 'local_grupomakro_core', '', $params['product_name']);
        }

        // 3. Resolve Learning User Record (needed for delete function)
        $learning_user = $DB->get_record('local_learning_users', ['learningplanid' => $plan->id, 'userid' => $user->id]);
        
        if (!$learning_user) {
             return [
                'status' => 'warning',
                'message' => 'User was not enrolled in this plan',
            ];
        }

        // 4. Executre Unenrollment
        try {
            // Reusing existing logic
             \delete_learning_user_external::delete_learning_user($learning_user->id);
             
             return [
                'status' => 'success',
                'message' => 'User unenrolled successfully'
            ];
        } catch (moodle_exception $e) {
            throw $e;
        }
    }

    public static function execute_returns() {
        return new external_single_structure(
            array(
                'status' => new external_value(PARAM_TEXT, 'Status of the operation'),
                'message' => new external_value(PARAM_TEXT, 'Result message')
            )
        );
    }
}
