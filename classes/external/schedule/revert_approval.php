<?php
namespace local_grupomakro_core\external\schedule;

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use context_system;
use moodle_exception;
use local_grupomakro_core\local_grupomakro_progress_manager; // Check namespace usage

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/progress_manager.php');

class revert_approval extends external_api {

    /**
     * Parameters for execute.
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'classId' => new external_value(PARAM_INT, 'The class ID'),
        ]);
    }

    /**
     * Execute the function.
     */
    public static function execute($classId) {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'classId' => $classId
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context); 

        $class = $DB->get_record('gmk_class', ['id' => $params['classId']], '*', MUST_EXIST);

        if (!$class->approved) {
            return ['status' => 'warning', 'message' => 'Class is not approved.'];
        }

        mtrace("Reverting approval for class {$class->id}...");

        // 1. Get students in the group
        if ($class->groupid) {
            $members = groups_get_members($class->groupid);
            
            foreach ($members as $member) {
                // Remove from Moodle Group
                groups_remove_member($class->groupid, $member->id);
                
                // Unlink in Progress Manager
                // Note: progress_manager class name might not be namespaced fully in the file, 
                // it is defined as 'class local_grupomakro_progress_manager' in global namespace or similar?
                // The file has no namespace declaration at the top. So it is in global namespace.
                \local_grupomakro_progress_manager::unassign_class_from_course_progress($member->id, $class);

                // Restore to Pre-registration
                // Check if already exists just in case
                if (!$DB->record_exists('gmk_class_pre_registration', ['userid' => $member->id, 'classid' => $class->id])) {
                    $preReg = new \stdClass();
                    $preReg->userid = $member->id;
                    $preReg->classid = $class->id;
                    $preReg->timecreated = time();
                    $preReg->timemodified = time();
                    $preReg->usermodified = $USER->id;
                    $DB->insert_record('gmk_class_pre_registration', $preReg);
                }
            }
        }

        // 2. Set approved = 0
        $class->approved = 0;
        $DB->update_record('gmk_class', $class);

        // 3. Delete approval message
        $DB->delete_records('gmk_class_approval_message', ['classid' => $class->id]);

        return ['status' => 'ok', 'message' => 'Approval reverted.'];
    }

    /**
     * Return structure.
     */
    public static function execute_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_TEXT, 'Status code'),
            'message' => new external_value(PARAM_TEXT, 'Message')
        ]);
    }
}
