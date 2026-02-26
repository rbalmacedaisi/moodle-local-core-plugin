<?php
namespace local_grupomakro_core\external\odoo;

defined('MOODLE_INTERNAL') || die();

// Requirements moved inside execute() block to prevent fatal 500 crashes during route discovery

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use context_system;
use moodle_exception;
use stdClass;

// For sync_student_progress, moved inside execute() block

class enroll_student extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters(
            array(
                'product_name' => new external_value(PARAM_TEXT, 'The name of the Odoo Product (Moodle Learning Plan Name)', VALUE_REQUIRED),
                'username'     => new external_value(PARAM_RAW, 'The username of the student', VALUE_REQUIRED),
                'role_id'      => new external_value(PARAM_INT, 'The Role ID (default to student)', VALUE_DEFAULT, 5),
            )
        );
    }

    public static function execute($product_name, $username, $role_id = 5) {
        global $DB, $CFG;

        $logfile = $CFG->dirroot . '/local/grupomakro_core/odoo_sync_debug.log';
        $logmsg = date('Y-m-d H:i:s') . " - Enroll request: username=$username, product=$product_name, role=$role_id\n";

        // Safe Includes to prevent fatal 500 during WS discovery
        require_once($CFG->libdir . '/externallib.php');
        
        $add_learning_user_path_1 = $CFG->dirroot . '/local/sc_learningplans/external/user/add_learning_user.php';
        $add_learning_user_path_2 = $CFG->dirroot . '/sc_learningplans/external/user/add_learning_user.php';
        
        if (file_exists($add_learning_user_path_1)) {
            require_once($add_learning_user_path_1);
        } elseif (file_exists($add_learning_user_path_2)) {
            require_once($add_learning_user_path_2);
        } else {
            file_put_contents($logfile, $logmsg . " - ERROR: add_learning_user.php not found in typical plugin paths\n", FILE_APPEND);
            return ['status' => 'error', 'message' => 'Missing dependency: add_learning_user.php', 'learning_user_id' => 0, 'plan_id' => 0];
        }

        $locallib_path_1 = $CFG->dirroot . '/local/grupomakro_core/locallib.php';
        if (file_exists($locallib_path_1)) {
            require_once($locallib_path_1);
        }
        
        // Validation of parameters
        try {
            $params = self::validate_parameters(self::execute_parameters(), array(
                'product_name' => $product_name,
                'username'     => $username,
                'role_id'      => $role_id
            ));
        } catch (\Throwable $e) {
            file_put_contents($logfile, $logmsg . " - VALIDATION ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
            throw $e;
        }

        // 1. Resolve User (Moodle usernames are always lowercase)
        $lookupUsername = \core_text::strtolower($params['username']);
        $user = $DB->get_record('user', ['username' => $lookupUsername, 'deleted' => 0, 'suspended' => 0]);
        if (!$user) {
            file_put_contents($logfile, $logmsg . " - ERROR: User not found ($lookupUsername)\n", FILE_APPEND);
            throw new moodle_exception('invaliduser', 'error', '', $params['username'] . " (mapped to $lookupUsername)");
        }

        // 2. Resolve Learning Plan
        // Using 'gmk_learning_plans' based on analysis, but it's an alias for 'local_learning_plans'
        // We query local_learning_plans directly.
        $plan = $DB->get_record('local_learning_plans', ['name' => $params['product_name']]);
        if (!$plan) {
            file_put_contents($logfile, $logmsg . " - ERROR: Plan not found (" . $params['product_name'] . ")\n", FILE_APPEND);
            // Try partial match or handle "Course Name" vs "Plan Name" mismatch if strictly needed.
            // For now, strict name match is assumed per requirements.
            throw new moodle_exception('invalidlearningplan', 'local_grupomakro_core', '', $params['product_name']);
        }

        // 3. Resolve Period (Default to first period of the plan)
        // We pick the first period associated with this plan.
        // Logic from sc_learningplans structure: periods are linked via local_learning_periods
        $first_period = $DB->get_records('local_learning_periods', ['learningplanid' => $plan->id], 'id ASC', '*', 0, 1);
        if (!$first_period) {
             file_put_contents($logfile, $logmsg . " - ERROR: No periods found for plan (" . $plan->id . ")\n", FILE_APPEND);
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
            
            // 5. Explicitly initialize the progress grid because event-driven sometimes fails or misses periods
            if (function_exists('sync_student_progress')) {
                sync_student_progress($user->id);
            }
            
            file_put_contents($logfile, $logmsg . " - SUCCESS: User enrolled, learning_user_id=" . $result['id'] . "\n", FILE_APPEND);
            
            return [
                'status' => 'success',
                'message' => 'User enrolled successfully',
                'learning_user_id' => $result['id'],
                'plan_id' => $plan->id
            ];

        } catch (moodle_exception $e) {
            if ($e->errorcode == 'learninguserexist') {
                file_put_contents($logfile, $logmsg . " - WARNING: learninguserexist\n", FILE_APPEND);
                return [
                    'status' => 'warning',
                    'message' => 'User already enrolled in this plan',
                    'learning_user_id' => 0,
                    'plan_id' => $plan->id
                ];
            }
            file_put_contents($logfile, $logmsg . " - ERROR: MOODLE EXCEPTION " . $e->getMessage() . "\n", FILE_APPEND);
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'learning_user_id' => 0,
                'plan_id' => 0
            ];
        } catch (\Throwable $e) {
            file_put_contents($logfile, $logmsg . " - ERROR: FATAL THROWABLE " . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() . "\n", FILE_APPEND);
            return [
                'status' => 'error',
                'message' => 'PHP Fatal Error: ' . $e->getMessage(),
                'learning_user_id' => 0,
                'plan_id' => 0
            ];
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
