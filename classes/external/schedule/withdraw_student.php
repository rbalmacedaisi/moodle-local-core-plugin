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
 * Withdraw a student from a class and reset their progress to available.
 */
class withdraw_student extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'classId' => new external_value(PARAM_INT, 'The class ID (gmk_class.id)'),
            'userId' => new external_value(PARAM_INT, 'The student user ID'),
            'learningPlanId' => new external_value(PARAM_INT, 'Preferred learning plan ID', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute($classId, $userId, $learningPlanId = 0) {
        global $DB, $USER;

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
        $modifier = isset($USER->id) ? (int)$USER->id : 0;

        if ($class) {
            $progressRows = $DB->get_records('gmk_course_progre', ['userid' => $userId, 'classid' => $classId], 'id ASC');

            // 1. Remove from class group.
            if (!empty($class->groupid) && groups_is_member((int)$class->groupid, $userId)) {
                groups_remove_member((int)$class->groupid, $userId);
            }

            // Defensive: remove from any stale groups linked by progress rows for this class.
            foreach ($progressRows as $row) {
                $gid = (int)($row->groupid ?? 0);
                if ($gid > 0 && groups_is_member($gid, $userId)) {
                    groups_remove_member($gid, $userId);
                }
            }

            // 2. Unenrol from manual enrol instances.
            $enrolplugin = enrol_get_plugin('manual');
            $courseIds = [(int)$class->corecourseid];
            foreach ($progressRows as $row) {
                $cid = (int)($row->courseid ?? 0);
                if ($cid > 0) {
                    $courseIds[] = $cid;
                }
            }
            $courseIds = array_values(array_unique(array_filter($courseIds)));

            if ($enrolplugin) {
                foreach ($courseIds as $cid) {
                    $instance = get_manual_enroll($cid);
                    if ($instance) {
                        $enrolplugin->unenrol_user($instance, $userId);
                    }
                }
            }

            // 3. Reset progress rows.
            $updated = \local_grupomakro_progress_manager::unassign_class_from_course_progress(
                $userId,
                $class,
                $learningPlanId
            );

            // Last resort: force reset rows that still point to this class id.
            if (!$updated && !empty($progressRows)) {
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
            }

            // 4. Remove pending records.
            $DB->delete_records('gmk_class_pre_registration', ['userid' => $userId, 'classid' => $classId]);
            $DB->delete_records('gmk_class_queue', ['userid' => $userId, 'classid' => $classId]);

            $forcedcleanuprows = 0;
            $remaininggroupmember = (!empty($class->groupid) && groups_is_member((int)$class->groupid, $userId));
            $remainingclassrows = $DB->get_records('gmk_course_progre', ['userid' => $userId, 'classid' => $classId], 'id ASC');

            // Defensive post-cleanup for inconsistent rows: force reset by classid/groupid.
            if ($remaininggroupmember || !empty($remainingclassrows)) {
                $forcecandidates = [];
                foreach ($remainingclassrows as $row) {
                    $forcecandidates[(int)$row->id] = $row;
                }
                if (!empty($class->groupid)) {
                    $rowsbygroup = $DB->get_records('gmk_course_progre', ['userid' => $userId, 'groupid' => (int)$class->groupid], 'id ASC');
                    foreach ($rowsbygroup as $row) {
                        $forcecandidates[(int)$row->id] = $row;
                    }
                }

                $groupids = [];
                if (!empty($class->groupid)) {
                    $groupids[(int)$class->groupid] = (int)$class->groupid;
                }

                foreach ($forcecandidates as $row) {
                    $gid = (int)($row->groupid ?? 0);
                    if ($gid > 0) {
                        $groupids[$gid] = $gid;
                    }
                    $changed = false;
                    if ((int)$row->classid !== 0) {
                        $row->classid = 0;
                        $changed = true;
                    }
                    if ((int)$row->groupid !== 0) {
                        $row->groupid = 0;
                        $changed = true;
                    }
                    if ((float)$row->progress !== 0.0) {
                        $row->progress = 0;
                        $changed = true;
                    }
                    if ((float)$row->grade !== 0.0) {
                        $row->grade = 0;
                        $changed = true;
                    }
                    if ((int)$row->status !== COURSE_AVAILABLE) {
                        $row->status = COURSE_AVAILABLE;
                        $changed = true;
                    }
                    if ($learningPlanId > 0 && (int)($row->learningplanid ?? 0) <= 0) {
                        $row->learningplanid = $learningPlanId;
                        $changed = true;
                    }
                    if ($changed) {
                        $row->timemodified = time();
                        $row->usermodified = $modifier;
                        $DB->update_record('gmk_course_progre', $row);
                        $forcedcleanuprows++;
                    }
                }

                foreach ($groupids as $gid) {
                    if ($gid > 0 && groups_is_member($gid, $userId)) {
                        groups_remove_member($gid, $userId);
                    }
                }

                $DB->delete_records('gmk_class_pre_registration', ['userid' => $userId, 'classid' => $classId]);
                $DB->delete_records('gmk_class_queue', ['userid' => $userId, 'classid' => $classId]);
            }

            $stillingroup = (!empty($class->groupid) && groups_is_member((int)$class->groupid, $userId));
            $stillclassrows = (int)$DB->count_records('gmk_course_progre', ['userid' => $userId, 'classid' => $classId]);
            $stillprereg = (int)$DB->count_records('gmk_class_pre_registration', ['userid' => $userId, 'classid' => $classId]);
            $stillqueue = (int)$DB->count_records('gmk_class_queue', ['userid' => $userId, 'classid' => $classId]);

            if ($stillingroup || $stillclassrows > 0 || $stillprereg > 0 || $stillqueue > 0) {
                return [
                    'status' => 'error',
                    'message' => 'Retiro incompleto. Residuals -> group=' . ($stillingroup ? '1' : '0') .
                        ' classrows=' . $stillclassrows . ' prereg=' . $stillprereg . ' queue=' . $stillqueue
                ];
            }

            // Extra cleanup for stale floating "cursando" rows that are not linked to any class/group.
            if ($learningPlanId > 0 && !empty($class->corecourseid)) {
                $floatingrows = $DB->get_records_select(
                    'gmk_course_progre',
                    'userid = :uid AND learningplanid = :lpid AND courseid = :cid AND status = :st AND classid = 0 AND groupid = 0',
                    [
                        'uid' => $userId,
                        'lpid' => $learningPlanId,
                        'cid' => (int)$class->corecourseid,
                        'st' => COURSE_IN_PROGRESS
                    ],
                    'id ASC'
                );
                foreach ($floatingrows as $row) {
                    $row->status = COURSE_AVAILABLE;
                    $row->progress = 0;
                    $row->grade = 0;
                    $row->timemodified = time();
                    $row->usermodified = $modifier;
                    $DB->update_record('gmk_course_progre', $row);
                    $forcedcleanuprows++;
                }
            }

            $message = 'Estudiante retirado correctamente de la clase.';
            if ($forcedcleanuprows > 0) {
                $message .= ' Limpieza forzada aplicada a ' . $forcedcleanuprows . ' registro(s).';
            }
            return ['status' => 'ok', 'message' => $message];
        }

        // Class deleted: reset all progress rows that still reference the class id.
        $progressRows = $DB->get_records('gmk_course_progre', ['userid' => $userId, 'classid' => $classId], 'id ASC');
        if (empty($progressRows)) {
            return ['status' => 'error', 'message' => 'No se encontro el registro del estudiante para esta clase.'];
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
            $courseIds = array_values(array_unique($courseIds));
            foreach ($courseIds as $cid) {
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
