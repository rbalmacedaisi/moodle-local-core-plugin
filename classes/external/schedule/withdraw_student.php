<?php
namespace local_grupomakro_core\external\schedule;

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use context_system;
use local_grupomakro_core\local_grupomakro_progress_manager;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/progress_manager.php');

/**
 * Withdraws a student from an active class:
 *   1. Removes from Moodle group
 *   2. Unenrols from Moodle course
 *   3. Resets gmk_course_progre to status=COURSE_AVAILABLE (1)
 *   4. Removes any pending pre-registration / queue record
 */
class withdraw_student extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'classId' => new external_value(PARAM_INT, 'The class ID (gmk_class.id)'),
            'userId'  => new external_value(PARAM_INT, 'The student user ID'),
        ]);
    }

    public static function execute($classId, $userId) {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'classId' => $classId,
            'userId'  => $userId,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        $class  = $DB->get_record('gmk_class', ['id' => $params['classId']], '*', MUST_EXIST);
        $userId = (int)$params['userId'];

        // 1. Remove from Moodle group.
        if (!empty($class->groupid) && groups_is_member($class->groupid, $userId)) {
            groups_remove_member($class->groupid, $userId);
        }

        // 2. Unenrol from the Moodle course (manual enrolment plugin).
        $enrolplugin    = enrol_get_plugin('manual');
        $courseInstance = get_manual_enroll($class->corecourseid);
        if ($enrolplugin && $courseInstance) {
            $enrolplugin->unenrol_user($courseInstance, $userId);
        }

        // 3. Reset progress record → status = COURSE_AVAILABLE, grade = 0, classid = 0.
        \local_grupomakro_progress_manager::unassign_class_from_course_progress($userId, $class);

        // 4. Remove any pre-registration or queue entries for this class.
        $DB->delete_records('gmk_class_pre_registration', ['userid' => $userId, 'classid' => $class->id]);
        $DB->delete_records('gmk_class_queue',            ['userid' => $userId, 'classid' => $class->id]);

        return ['status' => 'ok', 'message' => 'Estudiante retirado correctamente de la clase.'];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'status'  => new external_value(PARAM_TEXT, 'ok | error'),
            'message' => new external_value(PARAM_TEXT, 'Descriptive message'),
        ]);
    }
}
