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
 *
 * Handles the case where gmk_class was deleted (orphaned classid) by falling
 * back to the data stored in gmk_course_progre itself.
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

        $classId = (int)$params['classId'];
        $userId  = (int)$params['userId'];

        // Try to load the class record. If it was deleted, fall back to progre data.
        $class = $DB->get_record('gmk_class', ['id' => $classId]);

        if ($class) {
            // ── Normal path: class exists ──────────────────────────────────
            // 1. Remove from Moodle group.
            if (!empty($class->groupid) && groups_is_member($class->groupid, $userId)) {
                groups_remove_member($class->groupid, $userId);
            }

            // 2. Unenrol from the Moodle course.
            $enrolplugin    = enrol_get_plugin('manual');
            $courseInstance = get_manual_enroll($class->corecourseid);
            if ($enrolplugin && $courseInstance) {
                $enrolplugin->unenrol_user($courseInstance, $userId);
            }

            // 3. Reset progress record.
            \local_grupomakro_progress_manager::unassign_class_from_course_progress($userId, $class);

            // 4. Remove pre-registration / queue entries.
            $DB->delete_records('gmk_class_pre_registration', ['userid' => $userId, 'classid' => $classId]);
            $DB->delete_records('gmk_class_queue',            ['userid' => $userId, 'classid' => $classId]);

            return ['status' => 'ok', 'message' => 'Estudiante retirado correctamente de la clase.'];
        }

        // ── Fallback path: class was deleted, use data from progre record ──
        $progre = $DB->get_record('gmk_course_progre', ['userid' => $userId, 'classid' => $classId]);
        if (!$progre) {
            return ['status' => 'error', 'message' => 'No se encontró el registro del estudiante para esta clase.'];
        }

        // 1. Remove from group if still set.
        if (!empty($progre->groupid) && groups_is_member($progre->groupid, $userId)) {
            groups_remove_member($progre->groupid, $userId);
        }

        // 2. Unenrol from the Moodle course.
        if (!empty($progre->courseid)) {
            $enrolplugin    = enrol_get_plugin('manual');
            $courseInstance = get_manual_enroll($progre->courseid);
            if ($enrolplugin && $courseInstance) {
                $enrolplugin->unenrol_user($courseInstance, $userId);
            }
        }

        // 3. Reset progress record directly (class object not available).
        $progre->classid       = 0;
        $progre->groupid       = 0;
        $progre->progress      = 0;
        $progre->grade         = 0;
        $progre->status        = COURSE_AVAILABLE; // 1
        $progre->timemodified  = time();
        $DB->update_record('gmk_course_progre', $progre);

        // 4. Remove pre-registration / queue entries.
        $DB->delete_records('gmk_class_pre_registration', ['userid' => $userId, 'classid' => $classId]);
        $DB->delete_records('gmk_class_queue',            ['userid' => $userId, 'classid' => $classId]);

        return ['status' => 'ok', 'message' => 'Clase no encontrada (fue eliminada). Registro del estudiante corregido correctamente.'];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'status'  => new external_value(PARAM_TEXT, 'ok | error'),
            'message' => new external_value(PARAM_TEXT, 'Descriptive message'),
        ]);
    }
}
