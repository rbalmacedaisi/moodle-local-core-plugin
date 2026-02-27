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
                'period_name'  => new external_value(PARAM_TEXT, 'The name of the enrollment period (optional)', VALUE_DEFAULT, ''),
            )
        );
    }

    public static function execute($product_name, $username, $role_id = 5, $period_name = '') {
        global $DB, $CFG;

        $logfile = $CFG->dirroot . '/local/grupomakro_core/odoo_sync_debug.log';
        $logmsg = date('Y-m-d H:i:s') . " - Enroll request: username=$username, product=$product_name, role=$role_id, period=$period_name\n";

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

        $progress_manager_path = $CFG->dirroot . '/local/grupomakro_core/classes/local/progress_manager.php';
        if (file_exists($progress_manager_path)) {
            require_once($progress_manager_path);
        }
        
        // Validation of parameters
        try {
            $params = self::validate_parameters(self::execute_parameters(), array(
                'product_name' => $product_name,
                'username'     => $username,
                'role_id'      => $role_id,
                'period_name'  => $period_name
            ));
        } catch (\Throwable $e) {
            file_put_contents($logfile, $logmsg . " - VALIDATION ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
            throw $e;
        }

        $lookupUsername = \core_text::strtolower($params['username']);
        $user = $DB->get_record('user', ['username' => $lookupUsername, 'deleted' => 0, 'suspended' => 0]);
        if (!$user) {
            file_put_contents($logfile, $logmsg . " - ERROR: User not found ($lookupUsername)\n", FILE_APPEND);
            throw new moodle_exception('invaliduser', 'error', '', $params['username'] . " (mapped to $lookupUsername)");
        }

        // 1.1 Save the incoming period as "periodo_ingreso" custom profile field
        if (!empty($params['period_name'])) {
            $fieldid = $DB->get_field('user_info_field', 'id', ['shortname' => 'periodo_ingreso']);
            if ($fieldid) {
                $existingdata = $DB->get_record('user_info_data', ['userid' => $user->id, 'fieldid' => $fieldid]);
                if ($existingdata) {
                    $existingdata->data = trim($params['period_name']);
                    $DB->update_record('user_info_data', $existingdata);
                } else {
                    $newdata = new \stdClass();
                    $newdata->userid = $user->id;
                    $newdata->fieldid = $fieldid;
                    $newdata->data = trim($params['period_name']);
                    $newdata->dataformat = 0; // plain text
                    $DB->insert_record('user_info_data', $newdata);
                }
                file_put_contents($logfile, $logmsg . " - SUCCESS: Updated profile field periodo_ingreso for user $user->id\n", FILE_APPEND);
            }
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

        // 3. Resolve Period (Try to match period_name if provided)
        $current_period_id = 0;
        if (!empty($params['period_name'])) {
            // Check if period name exists (case insensitive)
            $sql = "SELECT id FROM {local_learning_periods} WHERE learningplanid = ? AND LOWER(name) = LOWER(?)";
            $named_period = $DB->get_record_sql($sql, [$plan->id, \core_text::strtolower(trim($params['period_name']))]);
            if ($named_period) {
                $current_period_id = $named_period->id;
                file_put_contents($logfile, $logmsg . " - SUCCESS: Matched requested period (" . $params['period_name'] . ") with ID $current_period_id\n", FILE_APPEND);
            } else {
                file_put_contents($logfile, $logmsg . " - WARNING: Requested period (" . $params['period_name'] . ") not found for plan $plan->id. Falling back to active period.\n", FILE_APPEND);
            }
        }

        // 3.1 Fallback to Active Period in Course (within first 2 months / 60 days)
        if (!$current_period_id) {
            $now = time();
            $twomonths = 60 * 24 * 3600;
            $sql_active = "SELECT name FROM {gmk_academic_periods} WHERE status = 1 AND startdate <= ? AND ? <= (startdate + ?) ORDER BY startdate DESC";
            $active_acad_period = $DB->get_records_sql($sql_active, [$now, $now, $twomonths], 0, 1);
            
            if ($active_acad_period) {
                $active_name = reset($active_acad_period)->name;
                $sql = "SELECT id FROM {local_learning_periods} WHERE learningplanid = ? AND LOWER(name) = LOWER(?)";
                $active_period = $DB->get_record_sql($sql, [$plan->id, \core_text::strtolower(trim($active_name))]);
                
                if ($active_period) {
                    $current_period_id = $active_period->id;
                    file_put_contents($logfile, $logmsg . " - INFO: Handled active fallback period (" . $active_name . ") with ID $current_period_id\n", FILE_APPEND);
                }
            }
        }

        // 3.2 Ultimate Fallback: First period of the plan
        if (!$current_period_id) {
            $first_period = $DB->get_records('local_learning_periods', ['learningplanid' => $plan->id], 'id ASC', '*', 0, 1);
            if (!$first_period) {
                file_put_contents($logfile, $logmsg . " - ERROR: No periods found for plan (" . $plan->id . ")\n", FILE_APPEND);
                throw new moodle_exception('noperiodsfound', 'local_grupomakro_core', '', $params['product_name']);
            }
            $current_period_id = reset($first_period)->id;
            file_put_contents($logfile, $logmsg . " - INFO: Handled default first period with ID $current_period_id\n", FILE_APPEND);
        }

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
            
            // 5. Explicitly initialize the progress grid via the new progress_manager architecture
            if (class_exists('local_grupomakro_progress_manager')) {
                \local_grupomakro_progress_manager::create_learningplan_user_progress($user->id, $plan->id, $params['role_id']);
                file_put_contents($logfile, $logmsg . " - SUCCESS: Progress grid initialized\n", FILE_APPEND);
            } else {
                file_put_contents($logfile, $logmsg . " - WARNING: local_grupomakro_progress_manager not found, grid might be empty\n", FILE_APPEND);
            }

            // 6. Explicitly set currentsubperiodid to the first subperiod (Bimestre 1)
            $first_subperiod = $DB->get_records('local_learning_subperiods', ['learningplanid' => $plan->id], 'position ASC, id ASC', '*', 0, 1);
            if ($first_subperiod) {
                $subperiod = reset($first_subperiod);
                $llu_record = $DB->get_record('local_learning_users', ['id' => $result['id']]);
                if ($llu_record) {
                    $llu_record->currentsubperiodid = $subperiod->id;
                    $DB->update_record('local_learning_users', $llu_record);
                    file_put_contents($logfile, $logmsg . " - SUCCESS: Assigned subperiod " . $subperiod->name . " (ID: $subperiod->id)\n", FILE_APPEND);
                }
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
