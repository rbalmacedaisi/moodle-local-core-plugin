<?php
namespace local_grupomakro_core\external\schedule;

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/progress_manager.php');

/**
 * Withdraw a student from a SINGLE class (module or regular) surgically.
 *
 * A student may be enrolled in both a module class and a regular class of the
 * SAME subject (they share one Moodle course: module enrollment adds the student
 * to the regular class group for section visibility). Withdrawing must therefore
 * be surgical: remove only the passed class, keep the other enrollment and the
 * shared Moodle course access, and only unenrol from the course when the student
 * no longer belongs to ANY class of that course. Progress is set to Cursando when
 * an active class survives, or restored to the original/available status when not.
 */
class withdraw_student extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'classId' => new external_value(PARAM_INT, 'The class ID (gmk_class.id) to withdraw from'),
            'userId' => new external_value(PARAM_INT, 'The student user ID'),
            'learningPlanId' => new external_value(PARAM_INT, 'Preferred learning plan ID', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute($classId, $userId, $learningPlanId = 0) {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'classId' => $classId,
            'userId' => $userId,
            'learningPlanId' => $learningPlanId,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        $classId = (int)$params['classId'];
        $userId = (int)$params['userId'];
        $learningPlanId = (int)$params['learningPlanId'];

        $class = $DB->get_record('gmk_class', ['id' => $classId]);

        // ── Class deleted: reset any progress rows still referencing the class id ──
        if (!$class) {
            return self::reset_orphan_progress($userId, $classId, $learningPlanId);
        }

        $corecourseid  = (int)$class->corecourseid;
        $isModuleClass = !empty($class->is_module);

        // ── 1. Remove membership from THIS class group only ───────────────────────
        if (!empty($class->groupid) && groups_is_member((int)$class->groupid, $userId)) {
            groups_remove_member((int)$class->groupid, $userId);
        }

        // ── 2. Target-specific record cleanup ─────────────────────────────────────
        $moduleOriginalStatus = null;
        if ($isModuleClass) {
            // Drop the module enrollment for this class (do NOT touch the regular class).
            $moduleEnroll = $DB->get_record('gmk_module_enrollment', ['classid' => $classId, 'userid' => $userId]);
            if ($moduleEnroll) {
                $moduleOriginalStatus = is_null($moduleEnroll->original_status)
                    ? null
                    : (int)$moduleEnroll->original_status;
                $DB->delete_records('gmk_module_enrollment', ['id' => (int)$moduleEnroll->id]);
            }
        } else {
            // Regular class: also drop any stale group stored on progre rows for this class.
            $progressRowsForClass = $DB->get_records('gmk_course_progre', ['userid' => $userId, 'classid' => $classId]);
            foreach ($progressRowsForClass as $row) {
                $gid = (int)($row->groupid ?? 0);
                if ($gid > 0 && groups_is_member($gid, $userId)) {
                    groups_remove_member($gid, $userId);
                }
            }
        }

        // ── 3. Pending records for THIS class ─────────────────────────────────────
        $DB->delete_records('gmk_class_pre_registration', ['userid' => $userId, 'classid' => $classId]);
        $DB->delete_records('gmk_class_queue', ['userid' => $userId, 'classid' => $classId]);

        // ── 4. What remains for this course (excluding the class we just left)? ────
        $remaining = self::remaining_classes_for_course($userId, $corecourseid, $classId);
        $stillMemberAny = !empty($remaining); // any class group (any state) -> keep course access
        $activeAny = null;
        $activeRegular = null;
        foreach ($remaining as $rc) {
            if ((int)$rc->approved === 1 && (int)$rc->closed === 0) {
                if ($activeAny === null) {
                    $activeAny = $rc;
                }
                if (empty($rc->is_module) && $activeRegular === null) {
                    $activeRegular = $rc;
                }
            }
        }

        // ── 5. Unenrol from the Moodle course only if nothing of it remains ───────
        if (!$stillMemberAny) {
            $enrolplugin = enrol_get_plugin('manual');
            if ($enrolplugin && $corecourseid > 0) {
                $instance = get_manual_enroll($corecourseid);
                if ($instance) {
                    $enrolplugin->unenrol_user($instance, $userId);
                }
            }
        }

        // ── 6. Update progress rows for (user, corecourse[, plan]) ────────────────
        $progreParams = ['userid' => $userId, 'courseid' => $corecourseid];
        if ($learningPlanId > 0) {
            $progreParams['learningplanid'] = $learningPlanId;
        }
        $progreRows = $DB->get_records('gmk_course_progre', $progreParams);
        if (empty($progreRows) && $learningPlanId > 0) {
            // Fall back to any plan if the preferred one had no row.
            $progreRows = $DB->get_records('gmk_course_progre', ['userid' => $userId, 'courseid' => $corecourseid]);
        }

        foreach ($progreRows as $row) {
            if ($activeAny !== null) {
                // An active class of this course survives -> keep Cursando.
                $row->status = 2; // COURSE_IN_PROGRESS (Cursando)
                if ($activeRegular !== null) {
                    // Point the row at the surviving regular class for clean grade resolution.
                    $row->classid = (int)$activeRegular->id;
                    $row->groupid = (int)$activeRegular->groupid;
                } else {
                    // Only a module survives (module grade is resolved without progre.classid).
                    $row->classid = 0;
                    $row->groupid = 0;
                }
            } else {
                // Nothing active remains -> restore to the pre-module status or Available.
                $restore = (!is_null($moduleOriginalStatus) && $moduleOriginalStatus !== 2)
                    ? $moduleOriginalStatus
                    : COURSE_AVAILABLE;
                $row->status = $restore;
                $row->classid = 0;
                $row->groupid = 0;
                $row->progress = 0;
                $row->grade = 0;
            }
            if ($learningPlanId > 0 && (int)($row->learningplanid ?? 0) <= 0) {
                $row->learningplanid = $learningPlanId;
            }
            $row->timemodified = time();
            $DB->update_record('gmk_course_progre', $row);
        }

        $msg = $isModuleClass
            ? 'Estudiante retirado del módulo correctamente.'
            : 'Estudiante retirado de la clase correctamente.';
        if ($stillMemberAny) {
            $msg .= ' Se conservó su otra inscripción del mismo curso.';
        } else {
            $msg .= ' El estado volvió a Disponible.';
        }

        return ['status' => 'ok', 'message' => $msg];
    }

    /**
     * Classes (other than $excludeClassId) of the given core course where the
     * student is still a group member. Any approval/closed state is returned so
     * the caller can decide course access (any) vs. Cursando status (active only).
     *
     * @return array<int,\stdClass>
     */
    private static function remaining_classes_for_course(int $userId, int $corecourseid, int $excludeClassId): array {
        global $DB;
        if ($corecourseid <= 0) {
            return [];
        }
        return $DB->get_records_sql(
            "SELECT c.id, c.is_module, c.approved, c.closed, c.groupid
               FROM {gmk_class} c
               JOIN {groups_members} gm ON gm.groupid = c.groupid AND gm.userid = :uid
              WHERE c.corecourseid = :cid
                AND c.id <> :exclude
                AND c.groupid > 0",
            ['uid' => $userId, 'cid' => $corecourseid, 'exclude' => $excludeClassId]
        );
    }

    /**
     * The class row no longer exists: reset every progress row still referencing
     * the class id and unenrol from the referenced courses.
     */
    private static function reset_orphan_progress(int $userId, int $classId, int $learningPlanId): array {
        global $DB;

        $progressRows = $DB->get_records('gmk_course_progre', ['userid' => $userId, 'classid' => $classId], 'id ASC');
        if (empty($progressRows)) {
            return ['status' => 'error', 'message' => 'No se encontró el registro del estudiante para esta clase.'];
        }

        foreach ($progressRows as $row) {
            $gid = (int)($row->groupid ?? 0);
            if ($gid > 0 && groups_is_member($gid, $userId)) {
                groups_remove_member($gid, $userId);
            }
        }

        $enrolplugin = enrol_get_plugin('manual');
        if ($enrolplugin) {
            $courseIds = [];
            foreach ($progressRows as $row) {
                $cid = (int)($row->courseid ?? 0);
                if ($cid > 0) {
                    $courseIds[] = $cid;
                }
            }
            foreach (array_values(array_unique($courseIds)) as $cid) {
                $instance = get_manual_enroll($cid);
                if ($instance) {
                    $enrolplugin->unenrol_user($instance, $userId);
                }
            }
        }

        foreach ($progressRows as $row) {
            if ($learningPlanId > 0 && (int)($row->learningplanid ?? 0) <= 0) {
                $row->learningplanid = $learningPlanId;
            }
            $row->classid = 0;
            $row->groupid = 0;
            $row->progress = 0;
            $row->grade = 0;
            $row->status = COURSE_AVAILABLE;
            $row->timemodified = time();
            $DB->update_record('gmk_course_progre', $row);
        }

        $DB->delete_records('gmk_class_pre_registration', ['userid' => $userId, 'classid' => $classId]);
        $DB->delete_records('gmk_class_queue', ['userid' => $userId, 'classid' => $classId]);

        return ['status' => 'ok', 'message' => 'Clase no encontrada (fue eliminada). Registro corregido.'];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_TEXT, 'ok | error'),
            'message' => new external_value(PARAM_TEXT, 'Descriptive message'),
        ]);
    }
}
