<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Class definition for the local_grupomakro_homologate_course_grade external function.
 *
 * Records a homologated (consolidated) grade for a (student, course, learning plan)
 * triple directly from the academic panel. The grade is stored both in the custom
 * gmk_course_progre row (which feeds the director pensum view) and in the Moodle
 * gradebook, on a manual "Nota Final Integrada" grade item that the panel reads
 * with the highest priority.
 *
 * Behaviour:
 *   - Validates the user, course and learning plan.
 *   - Resolves the student's current cuatrimestre (local_learning_users.currentperiodid)
 *     to stamp gmk_course_progre.periodid.
 *   - Auto-enrols the student in the Moodle course via the manual enrol plugin if
 *     they are not yet enrolled (no group association).
 *   - Creates or updates gmk_course_progre with status 4 (Aprobada) when grade >= 71
 *     or 5 (Reprobada) otherwise; always progress=100, classid=0, groupid=0.
 *   - Creates the "Nota Final Integrada" manual grade item on first homologation
 *     and upserts the matching grade_grades record with the observation as feedback.
 *   - Records the homologation source (suficiencia | migracion | homologacion) and
 *     the free-text reason on gmk_course_progre.homologation_*.
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Solutto Consulting <dev@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\student;

use context_course;
use context_system;
use external_api;
use external_description;
use external_function_parameters;
use external_single_structure;
use external_value;
use grade_grade;
use grade_item;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->libdir  . '/gradelib.php');
require_once($CFG->dirroot . '/grade/lib.php');

/**
 * External function 'local_grupomakro_homologate_course_grade' implementation.
 *
 * @package     local_grupomakro_core
 * @category    external
 * @copyright   2026 Solutto Consulting <dev@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class homologate_course_grade extends external_api
{

    /** Pass threshold (aligned with the revalida flow). */
    const PASS_GRADE = 71.0;

    /** Status code emitted when grade >= PASS_GRADE. */
    const STATUS_APPROVED = 4;

    /** Status code emitted when grade < PASS_GRADE. */
    const STATUS_FAILED = 5;

    /** Allowed homologation sources. */
    const ALLOWED_TYPES = ['suficiencia', 'migracion', 'homologacion', 'practica'];

    /**
     * Describes parameters of the {@see self::execute()} method.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters
    {
        return new external_function_parameters(
            [
                'userId'         => new external_value(PARAM_INT,  'Student user id.', VALUE_REQUIRED),
                'learningPlanId' => new external_value(PARAM_INT,  'Learning plan id.', VALUE_REQUIRED),
                'coreCourseId'   => new external_value(PARAM_INT,  'Moodle course id (course.id).', VALUE_REQUIRED),
                'grade'          => new external_value(PARAM_FLOAT, 'Homologated grade (0-100).', VALUE_REQUIRED),
                'type'           => new external_value(PARAM_TEXT, 'Homologation type: suficiencia | migracion | homologacion.', VALUE_REQUIRED),
                'observation'    => new external_value(PARAM_RAW,  'Free-text reason for the homologation.', VALUE_REQUIRED),
            ]
        );
    }

    /**
     * Apply a homologated grade and persist it across the custom progress table and
     * the Moodle gradebook.
     *
     * @param int    $userId
     * @param int    $learningPlanId
     * @param int    $coreCourseId
     * @param float  $grade
     * @param string $type
     * @param string $observation
     * @return array
     */
    public static function execute(
        int $userId,
        int $learningPlanId,
        int $coreCourseId,
        float $grade,
        string $type,
        string $observation
    ) {
        global $DB, $USER, $CFG;

        $params = self::validate_parameters(self::execute_parameters(), [
            'userId'         => $userId,
            'learningPlanId' => $learningPlanId,
            'coreCourseId'   => $coreCourseId,
            'grade'          => $grade,
            'type'           => $type,
            'observation'    => $observation,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        $userId         = (int)$params['userId'];
        $learningPlanId = (int)$params['learningPlanId'];
        $coreCourseId   = (int)$params['coreCourseId'];
        $grade          = round((float)$params['grade'], 2);
        $type           = trim((string)$params['type']);
        $observation    = trim((string)$params['observation']);

        if ($userId <= 0 || $learningPlanId <= 0 || $coreCourseId <= 0) {
            return self::error('Identificadores inválidos.');
        }
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            return self::error('Tipo de homologación inválido. Valores permitidos: ' . implode(', ', self::ALLOWED_TYPES));
        }
        if ($grade < 0 || $grade > 100) {
            return self::error('La nota debe estar entre 0 y 100.');
        }
        if ($observation === '') {
            return self::error('La observación es obligatoria.');
        }

        // Defense-in-depth: reject if the (user, course, plan) row already
        // holds a passing grade. The UI hides the button in this case, but a
        // direct request could otherwise bypass that guard.
        $existingGrade = $DB->get_record('gmk_course_progre', [
            'userid'         => $userId,
            'courseid'       => $coreCourseId,
            'learningplanid' => $learningPlanId,
        ], 'grade', IGNORE_MISSING);
        if ($existingGrade && $existingGrade->grade !== null && (float)$existingGrade->grade >= self::PASS_GRADE) {
            return self::error('La asignatura ya tiene una nota aprobatoria (' .
                number_format((float)$existingGrade->grade, 2) . '). No se puede homologar nuevamente.');
        }

        // Validate user / course / learning plan.
        $user  = $DB->get_record('user', ['id' => $userId, 'deleted' => 0], 'id,firstname,lastname', MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $coreCourseId], 'id,fullname,shortname', MUST_EXIST);
        $plan   = $DB->get_record('local_learning_plans', ['id' => $learningPlanId], 'id,name', MUST_EXIST);

        $now      = time();
        $status   = ($grade >= self::PASS_GRADE) ? self::STATUS_APPROVED : self::STATUS_FAILED;

        // Resolve the student's current cuatrimestre for this learning plan.
        $currentPeriodId = (int)$DB->get_field_sql(
            "SELECT MAX(lu.currentperiodid)
               FROM {local_learning_users} lu
              WHERE lu.userid = :userid
                AND lu.learningplanid = :lpid
                AND (lu.userroleid = :studentrole OR lu.userrolename = :studentrolename)",
            [
                'userid'          => $userId,
                'lpid'            => $learningPlanId,
                'studentrole'     => 5,
                'studentrolename' => 'student',
            ]
        );

        if ($currentPeriodId <= 0) {
            // Fallback to the first period of the plan so we never store a 0 silently.
            $currentPeriodId = (int)$DB->get_field_sql(
                "SELECT MIN(id) FROM {local_learning_periods} WHERE learningplanid = :lpid",
                ['lpid' => $learningPlanId]
            );
            gmk_log("WARN homologate_course_grade: user={$userId} has no currentperiodid for plan={$learningPlanId}, falling back to first period={$currentPeriodId}");
        }

        $periodName = '';
        if ($currentPeriodId > 0) {
            $periodRow = $DB->get_record('local_learning_periods', ['id' => $currentPeriodId], 'name');
            if ($periodRow) {
                $periodName = mb_substr((string)$periodRow->name, 0, 64, 'UTF-8');
            }
        }

        $courseName = mb_substr((string)$course->fullname, 0, 255, 'UTF-8');

        $enrolledNow = false;

        // Make sure the student is enrolled in the Moodle course (no group).
        $enrolPlugin = enrol_get_plugin('manual');
        $courseInstance = get_manual_enroll($coreCourseId);
        $studentRole = $DB->get_record('role', ['shortname' => 'student'], 'id');

        if ($courseInstance && $enrolPlugin && $studentRole && !is_enrolled(context_course::instance($coreCourseId), $userId, 'student', true)) {
            $enrolPlugin->enrol_user($courseInstance, $userId, (int)$studentRole->id);
            $enrolledNow = true;
            gmk_log("homologate_course_grade: enrolled user={$userId} into course={$coreCourseId} via manual plugin (no group).");
        }

        $transaction = $DB->start_delegated_transaction();

        try {
            // Upsert gmk_course_progre (legacy migration data + new homologated rows share the same table).
            $existing = $DB->get_record('gmk_course_progre', [
                'userid'         => $userId,
                'courseid'       => $coreCourseId,
                'learningplanid' => $learningPlanId,
            ], '*', IGNORE_MISSING);

            if ($existing) {
                $existing->grade               = $grade;
                $existing->status              = $status;
                $existing->progress            = 100.00;
                $existing->classid             = 0;
                $existing->groupid             = 0;
                $existing->periodid            = $currentPeriodId;
                $existing->periodname          = $periodName !== '' ? $periodName : $existing->periodname;
                $existing->coursename          = $courseName;
                $existing->homologation_type   = $type;
                $existing->homologation_note   = $observation;
                $existing->homologation_at     = $now;
                $existing->homologation_by     = (int)$USER->id;
                $existing->timemodified        = $now;
                $existing->usermodified        = (int)$USER->id;
                $DB->update_record('gmk_course_progre', $existing);
                $gcpId = (int)$existing->id;
            } else {
                $newRow = new stdClass();
                $newRow->userid            = $userId;
                $newRow->courseid          = $coreCourseId;
                $newRow->learningplanid    = $learningPlanId;
                $newRow->coursename        = $courseName;
                $newRow->periodid          = $currentPeriodId;
                $newRow->periodname        = $periodName !== '' ? $periodName : 'unnamed';
                $newRow->grade             = $grade;
                $newRow->status            = $status;
                $newRow->progress          = 100.00;
                $newRow->credits           = 0;
                $newRow->prerequisites     = '[]';
                $newRow->tc                = 0;
                $newRow->practicalhours    = 0;
                $newRow->teoricalhours     = 0;
                $newRow->classid           = 0;
                $newRow->groupid           = 0;
                $newRow->blocked_by_absence = 0;
                $newRow->blocked_by_absence_at = 0;
                $newRow->homologation_type = $type;
                $newRow->homologation_note = $observation;
                $newRow->homologation_at   = $now;
                $newRow->homologation_by   = (int)$USER->id;
                $newRow->timecreated       = $now;
                $newRow->timemodified      = $now;
                $newRow->usermodified      = (int)$USER->id;
                $gcpId = (int)$DB->insert_record('gmk_course_progre', $newRow);
            }

            // Find or create the "Nota Final Integrada" manual grade item.
            $gradeItem = grade_item::fetch([
                'courseid' => $coreCourseId,
                'itemtype' => 'manual',
                'itemname' => 'Nota Final Integrada',
            ]);

            if (!$gradeItem) {
                $gradeItem = new grade_item(
                    [
                        'courseid' => $coreCourseId,
                        'itemtype' => 'manual',
                        'itemname' => 'Nota Final Integrada',
                        'grademin' => 0,
                        'grademax' => 100,
                        'gradetype' => GRADE_TYPE_VALUE,
                    ],
                    false
                );
                $gradeItem->insert('homologation');
            }

            // Upsert grade_grades with the homologated value + observation.
            $gradeGrade = grade_grade::fetch([
                'itemid' => (int)$gradeItem->id,
                'userid' => $userId,
            ]);

            if ($gradeGrade) {
                $gradeGrade->finalgrade = $grade;
                $gradeGrade->rawgrade   = $grade;
                $gradeGrade->feedback       = $observation;
                $gradeGrade->feedbackformat = FORMAT_PLAIN;
                $gradeGrade->update('homologation');
            } else {
                $gradeGrade = new grade_grade();
                $gradeGrade->itemid          = (int)$gradeItem->id;
                $gradeGrade->userid          = $userId;
                $gradeGrade->rawgrademax     = (float)$gradeItem->grademax;
                $gradeGrade->rawgrademin     = (float)$gradeItem->grademin;
                $gradeGrade->finalgrade      = $grade;
                $gradeGrade->rawgrade        = $grade;
                $gradeGrade->feedback        = $observation;
                $gradeGrade->feedbackformat  = FORMAT_PLAIN;
                $gradeGrade->insert('homologation');
            }

            // Force the course total grade item to reflect the new value too,
            // so the panel and the regular gradebook stay consistent.
            $courseTotal = grade_item::fetch(['courseid' => $coreCourseId, 'itemtype' => 'course']);
            if ($courseTotal) {
                $totalGrade = grade_grade::fetch(['itemid' => (int)$courseTotal->id, 'userid' => $userId]);
                if ($totalGrade) {
                    $totalGrade->finalgrade = $grade;
                    $totalGrade->rawgrade   = $grade;
                    $totalGrade->update('homologation');
                } else {
                    $totalGrade = new grade_grade();
                    $totalGrade->itemid      = (int)$courseTotal->id;
                    $totalGrade->userid      = $userId;
                    $totalGrade->rawgrademax = (float)$courseTotal->grademax;
                    $totalGrade->rawgrademin = (float)$courseTotal->grademin;
                    $totalGrade->finalgrade  = $grade;
                    $totalGrade->rawgrade    = $grade;
                    $totalGrade->insert('homologation');
                }
            }

            $transaction->allow_commit();
        } catch (\Throwable $t) {
            $transaction->rollback($t);
            gmk_log('ERROR homologate_course_grade: ' . $t->getMessage() . ' @ ' . $t->getFile() . ':' . $t->getLine());
            return self::error('No se pudo registrar la homologación: ' . $t->getMessage());
        }

        gmk_log(sprintf(
            'homologate_course_grade OK user=%d plan=%d course=%d grade=%s type=%s by=%d',
            $userId, $learningPlanId, $coreCourseId, (string)$grade, $type, (int)$USER->id
        ));

        return [
            'status'            => 'ok',
            'message'           => 'Nota homologada correctamente.',
            'gcp_id'            => $gcpId,
            'course_status'     => $status,
            'homologation_type' => $type,
            'homologation_at'   => $now,
            'homologation_by'   => (int)$USER->id,
            'enrolled_now'      => $enrolledNow,
        ];
    }

    /**
     * Describes the return value of the {@see self::execute()} method.
     *
     * @return external_description
     */
    public static function execute_returns(): external_description
    {
        return new external_single_structure(
            [
                'status'            => new external_value(PARAM_TEXT, 'ok | error'),
                'message'           => new external_value(PARAM_TEXT, 'Descriptive message'),
                'gcp_id'            => new external_value(PARAM_INT,  'gmk_course_progre.id (0 on error)', VALUE_DEFAULT, 0),
                'course_status'     => new external_value(PARAM_INT,  'New gmk_course_progre.status (4 approved, 5 failed)', VALUE_DEFAULT, 0),
                'homologation_type' => new external_value(PARAM_TEXT, 'Persisted homologation type', VALUE_DEFAULT, ''),
                'homologation_at'   => new external_value(PARAM_INT,  'Unix timestamp of the homologation', VALUE_DEFAULT, 0),
                'homologation_by'   => new external_value(PARAM_INT,  'user.id who applied it', VALUE_DEFAULT, 0),
                'enrolled_now'      => new external_value(PARAM_BOOL, 'Whether the student was auto-enrolled during this call', VALUE_DEFAULT, false),
            ]
        );
    }

    /**
     * Build a uniform error response matching the contract above.
     *
     * @param string $message
     * @return array
     */
    private static function error(string $message): array
    {
        return [
            'status'            => 'error',
            'message'           => $message,
            'gcp_id'            => 0,
            'course_status'     => 0,
            'homologation_type' => '',
            'homologation_at'   => 0,
            'homologation_by'   => 0,
            'enrolled_now'      => false,
        ];
    }
}