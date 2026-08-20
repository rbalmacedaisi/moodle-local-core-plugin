<?php
namespace local_grupomakro_core\external\schedule;

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->dirroot . '/group/lib.php');
require_once($CFG->dirroot . '/enrol/locallib.php');

/**
 * Fully withdraw a student from a Moodle course.
 *
 * Unlike withdraw_student (which surgically removes a single class membership and
 * tries to preserve the rest of the course), this action removes the student from
 * EVERYTHING: every class group of the course, every module_enrollment, every
 * gmk_course_progre row, the Moodle enrolment and the role assignment. After this
 * call the student is back to the "Disponible" state and can re-enrol from scratch.
 *
 * Designed to be the default action of the "Retirar" button so the user does not
 * have to figure out which class to withdraw from.
 */
class withdraw_from_course extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'userId'         => new external_value(PARAM_INT, 'Student user ID'),
            'coreCourseId'   => new external_value(PARAM_INT, 'Moodle course ID to withdraw from'),
        ]);
    }

    public static function execute($userId, $coreCourseId) {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'userId'       => $userId,
            'coreCourseId' => $coreCourseId,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        $userId       = (int)$params['userId'];
        $coreCourseId = (int)$params['coreCourseId'];

        $user   = $DB->get_record('user', ['id' => $userId, 'deleted' => 0], 'id,firstname,lastname', MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $coreCourseId], 'id,fullname,shortname', MUST_EXIST);

        // ── 1. Remove from EVERY class group of this course ──────────────────────
        $classGroups = $DB->get_records('gmk_class', ['corecourseid' => $coreCourseId], '', 'id,groupid,name');
        foreach ($classGroups as $cl) {
            if (!empty($cl->groupid) && groups_is_member((int)$cl->groupid, $userId)) {
                groups_remove_member((int)$cl->groupid, $userId);
            }
        }

        // ── 2. Delete module_enrollment rows for this course ────────────────────
        $moduleIds = array_keys($classGroups);
        if (!empty($moduleIds)) {
            $DB->delete_records('gmk_module_enrollment',
                ['userid' => $userId, 'classid' => $moduleIds]);
        }

        // ── 3. Clean queue and pre_registration ─────────────────────────────────
        if (!empty($moduleIds)) {
            list($csSql, $csParams) = $DB->get_in_or_equal($moduleIds, SQL_PARAMS_NAMED, 'clsid');
            $DB->delete_records_select('gmk_class_queue',
                "userid = :uid AND classid $csSql",
                ['uid' => $userId] + $csParams);
            $DB->delete_records_select('gmk_class_pre_registration',
                "userid = :uid AND classid $csSql",
                ['uid' => $userId] + $csParams);
        }

        // ── 4. Snapshot progre rows, then reset them all to "Disponible" ───────
        $progreRows = $DB->get_records('gmk_course_progre',
            ['userid' => $userId, 'courseid' => $coreCourseId], '', 'id,learningplanid,classid,groupid,status,progress,grade');

        $now = time();
        $academicMovements = [];
        foreach ($progreRows as $row) {
            $academicMovements[] = (object)[
                'userid'         => $userId,
                'learningplanid' => (int)$row->learningplanid,
                'corecourseid'   => $coreCourseId,
                'classid'        => $row->classid !== null ? (int)$row->classid : null,
                'source'         => 'withdrawal',
                'source_record_id' => (int)$row->id,
                'grade'          => null,
                'course_status'  => 1, // COURSE_AVAILABLE
                'effective_at'   => $now,
                'annulled'       => 0,
                'usermodified'   => (int)$USER->id,
                'timecreated'    => $now,
                'timemodified'   => $now,
            ];
        }

        // Reset every progre row for (user, course) to the clean "Disponible" state.
        $DB->execute(
            "UPDATE {gmk_course_progre}
                SET status = 1, classid = 0, groupid = 0,
                    progress = 0.00, grade = 0.00, timemodified = :t
              WHERE userid = :uid AND courseid = :cid",
            ['t' => $now, 'uid' => $userId, 'cid' => $coreCourseId]
        );

        // ── 5. Record one academic_movement per cleared progre row ──────────────
        foreach ($academicMovements as $mv) {
            $DB->insert_record('gmk_academic_movements', $mv);
        }

        // ── 6. Drop the Moodle enrolment and student role ───────────────────────
        $enrolplugin = enrol_get_plugin('manual');
        if ($enrolplugin && $coreCourseId > 0) {
            $instance = get_manual_enroll($coreCourseId);
            if ($instance) {
                $enrolplugin->unenrol_user($instance, $userId);
            }
        }
        // Drop the student role at course context.
        $courseContext = \context_course::instance($coreCourseId, IGNORE_MISSING);
        if ($courseContext) {
            $studentRoleId = (int)$DB->get_field('role', 'id', ['shortname' => 'student']);
            if ($studentRoleId > 0) {
                role_unassign($studentRoleId, $userId, $courseContext->id);
            }
        }

        return [
            'status'  => 'ok',
            'message' => 'El estudiante fue retirado completamente del curso. Puede reinscribirse cuando lo requiera.',
        ];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'status'  => new external_value(PARAM_TEXT, 'ok | error'),
            'message' => new external_value(PARAM_TEXT, 'Descriptive message'),
        ]);
    }
}