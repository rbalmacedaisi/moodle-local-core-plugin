<?php
namespace local_grupomakro_core\external\odoo;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/local/sc_learningplans/external/user/add_learning_user.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use context_system;
use moodle_exception;
use stdClass;

class enroll_student extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters(
            array(
                'product_name' => new external_value(PARAM_TEXT, 'The name of the Odoo Product (Moodle Learning Plan Name)', VALUE_REQUIRED),
                'username'     => new external_value(PARAM_USERNAME, 'The username of the student', VALUE_REQUIRED),
                'role_id'      => new external_value(PARAM_INT, 'The Role ID (default to student)', VALUE_DEFAULT, 5),
            )
        );
    }

    public static function execute($product_name, $username, $role_id = 5) {
        global $DB;

        // Validation of parameters
        $params = self::validate_parameters(self::execute_parameters(), array(
            'product_name' => $product_name,
            'username'     => $username,
            'role_id'      => $role_id
        ));

        // 1. Resolve User
        $user = $DB->get_record('user', ['username' => $params['username'], 'deleted' => 0, 'suspended' => 0]);
        if (!$user) {
            throw new moodle_exception('invaliduser', 'error', '', $params['username']);
        }

        // 2. Resolve Learning Plan
        // Using 'gmk_learning_plans' based on analysis, but it's an alias for 'local_learning_plans'
        // We query local_learning_plans directly.
        $plan = $DB->get_record('local_learning_plans', ['name' => $params['product_name']]);
        if (!$plan) {
            // Try partial match or handle "Course Name" vs "Plan Name" mismatch if strictly needed.
            // For now, strict name match is assumed per requirements.
            throw new moodle_exception('invalidlearningplan', 'local_grupomakro_core', '', $params['product_name']);
        }

        // 3. Resolve Period (Default to first period of the plan)
        // We pick the first period associated with this plan.
        // Logic from sc_learningplans structure: periods are linked via local_learning_periods
        $first_period = $DB->get_records('local_learning_periods', ['learningplanid' => $plan->id], 'id ASC', '*', 0, 1);
        if (!$first_period) {
             throw new moodle_exception('noperiodsfound', 'local_grupomakro_core', '', $params['product_name']);
        }
        $current_period_id = reset($first_period)->id;

        // 4. Enroll User using sc_learningplans logic
        // This ensures all event triggers (progress creation, etc.) happen correctly.
        try {
            // We use the external class from sc_learningplans directly
            // \add_learning_user_external is defined in the included file.
            $result = \add_learning_user_external::add_learning_user(
                $plan->id,
                $user->id,
                $params['role_id'],
                $current_period_id,
                '' // Group name (optional)
            );
            
            return [
                'status' => 'success',
                'message' => 'User enrolled successfully',
                'learning_user_id' => $result['id'],
                'plan_id' => $plan->id
            ];

        } catch (moodle_exception $e) {
            if ($e->errorcode == 'learninguserexist') {
                return [
                    'status' => 'warning',
                    'message' => 'User already enrolled in this plan',
                    'learning_user_id' => 0,
                    'plan_id' => $plan->id
                ];
            }
            throw $e;
        }
    }

    public static function execute_returns() {
        return new external_single_structure(
            array(
                'status' => new external_value(PARAM_TEXT, 'Status of the operation (success, warning, error)'),
                'message' => new external_value(PARAM_TEXT, 'Result message'),
                'learning_user_id' => new external_value(PARAM_INT, 'ID of the learning user record created'),
                'plan_id' => new external_value(PARAM_INT, 'ID of the learning plan')
            )
        );
    }
}
