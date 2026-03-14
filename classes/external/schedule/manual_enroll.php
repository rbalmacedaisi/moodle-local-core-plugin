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

        $alreadyInGroup = (!empty($class->groupid) && groups_is_member((int)$class->groupid, (int)$params['userId']));

        // Before adding to group, ensure user is enrolled in the core course
        $enrolplugin = enrol_get_plugin('manual');
        $courseInstance = get_manual_enroll($class->corecourseid);
        $studentRoleId = $DB->get_record('role', ['shortname' => 'student'])->id;
        
        if ($courseInstance && $enrolplugin && $studentRoleId) {
            $enrolplugin->enrol_user($courseInstance, $params['userId'], $studentRoleId);
        }

        $added = true;
        if (!empty($class->groupid) && !$alreadyInGroup) {
            $added = groups_add_member((int)$class->groupid, (int)$params['userId']);
        }

        if (!$added) {
            return ['status' => 'error', 'message' => 'Failed to add user to group.'];
        }

        try {
            \local_grupomakro_progress_manager::assign_class_to_course_progress((int)$params['userId'], $class, true);
        } catch (\Throwable $t) {
            return ['status' => 'error', 'message' => 'Failed to sync course progress: ' . $t->getMessage()];
        }

        if ($alreadyInGroup) {
            return ['status' => 'ok', 'message' => 'User was already in group. Progress synced.'];
        }
        return ['status' => 'ok', 'message' => 'User enrolled successfully.'];
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
