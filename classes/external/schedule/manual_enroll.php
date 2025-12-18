<?php
namespace local_grupomakro_core\external\schedule;

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use context_system;
use moodle_exception;
use local_grupomakro_core\local_grupomakro_progress_manager;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/progress_manager.php');

class manual_enroll extends external_api {

    /**
     * Parameters.
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'classId' => new external_value(PARAM_INT, 'The class ID'),
            'userId' => new external_value(PARAM_INT, 'The user ID to enroll'),
        ]);
    }

    /**
     * Execute.
     */
    public static function execute($classId, $userId) {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'classId' => $classId,
            'userId' => $userId
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context); 

        $class = $DB->get_record('gmk_class', ['id' => $params['classId']], '*', MUST_EXIST);
        
        if (!$class->approved) {
             return ['status' => 'error', 'message' => 'Class is not approved yet. Cannot enroll students.'];
        }
        
        if ($class->closed) {
             return ['status' => 'error', 'message' => 'Class is closed. Cannot enroll students.'];
        }

        // Logic from enrolApprovedScheduleStudents
        // 1. Add to group
        // check if already member
        if (groups_is_member($class->groupid, $params['userId'])) {
             return ['status' => 'warning', 'message' => 'User is already in this class group.'];
        }

        $added = groups_add_member($class->groupid, $params['userId']);
        
        if ($added) {
            // 2. Assign to course progress
            // Note: assign_class_to_course_progress typically expects a record in gmk_course_progre to exist.
            // If the user hasn't started the course/plan, this record might be missing.
            // Let's check if we need to create it.
            
            $progress = $DB->get_record('gmk_course_progre', ['userid' => $params['userId'], 'courseid' => $class->corecourseid]);
            if (!$progress) {
                 // Create base progress record if missing?
                 // Usually created when assigned a plan?
                 // For now, let's try to update, if it fails, maybe we need to create it.
                 // But assign_class_to_course_progress does get_record first.
                 
                 // If no progress record, we probably should create one or fail?
                 // Let's assume manual enrollment implies they should have one.
                 // Let's create a default one if missing.
                 $newProgress = new \stdClass();
                 $newProgress->userid = $params['userId'];
                 $newProgress->courseid = $class->corecourseid;
                 $newProgress->classid = $class->id;
                 $newProgress->groupid = $class->groupid;
                 $newProgress->progress = 0;
                 $newProgress->grade = 0;
                 $newProgress->status = 1; // COURSE_IN_PROGRESS (Assuming 1)
                 $newProgress->timecreated = time();
                 $newProgress->timemodified = time();
                 $DB->insert_record('gmk_course_progre', $newProgress);
            } else {
                 \local_grupomakro_progress_manager::assign_class_to_course_progress($params['userId'], $class);
            }
            
            return ['status' => 'ok', 'message' => 'User enrolled successfully.'];
        } else {
            return ['status' => 'error', 'message' => 'Failed to add user to group.'];
        }
    }

    /**
     * Returns.
     */
    public static function execute_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_TEXT, 'Status code'),
            'message' => new external_value(PARAM_TEXT, 'Message')
        ]);
    }
}
