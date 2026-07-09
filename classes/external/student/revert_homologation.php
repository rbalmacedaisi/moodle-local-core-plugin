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
 * Class definition for the local_grupomakro_revert_homologation external function.
 *
 * Reverts a previously applied homologation for a (student, course, learning
 * plan) triple. Companion to local_grupomakro_homologate_course_grade.
 *
 * Revert semantics:
 *   - Validates that the row exists and currently carries a homologation type.
 *   - Clears gmk_course_progre.homologation_{type,note,at,by}.
 *   - Resets the progress row to a pre-homologation state: status 1
 *     (Disponible), progress 0, classid 0, groupid 0, grade 0.
 *     The Nota Final Integrada grade_grades row is set to null finalgrade /
 *     rawgrade so the consolidated grade disappears, and the course total
 *     grade_grades is nulled as well.
 *   - The Moodle enrolment that the homologation may have created is left
 *     in place on purpose: a director can withdraw the student explicitly
 *     via the existing Retirar flow.
 *   - An optional reason is stored in gmk_course_progre.homologation_note
 *     with a "Revertido · " prefix so the audit trail keeps the original
 *     observation together with the revert reason.
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Solutto Consulting <dev@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\student;

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
 * External function 'local_grupomakro_revert_homologation' implementation.
 */
class revert_homologation extends external_api
{

    /**
     * Pre-homologation status used to reset the progress row.
     * Matches the COURSE_AVAILABLE constant from progress_manager.
     */
    const RESET_STATUS = 1;

    /**
     * Describes parameters of the {@see self::execute()} method.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters
    {
        return new external_function_parameters([
            'userId'         => new external_value(PARAM_INT,  'Student user id.', VALUE_REQUIRED),
            'learningPlanId' => new external_value(PARAM_INT,  'Learning plan id.', VALUE_REQUIRED),
            'coreCourseId'   => new external_value(PARAM_INT,  'Moodle course id (course.id).', VALUE_REQUIRED),
            'reason'         => new external_value(PARAM_RAW,  'Optional free-text reason for the revert.', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Revert a previously applied homologation.
     *
     * @param int    $userId
     * @param int    $learningPlanId
     * @param int    $coreCourseId
     * @param string $reason
     * @return array
     */
    public static function execute(
        int $userId,
        int $learningPlanId,
        int $coreCourseId,
        string $reason = ''
    ) {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'userId'         => $userId,
            'learningPlanId' => $learningPlanId,
            'coreCourseId'   => $coreCourseId,
            'reason'         => $reason,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        $userId         = (int)$params['userId'];
        $learningPlanId = (int)$params['learningPlanId'];
        $coreCourseId   = (int)$params['coreCourseId'];
        $reason         = trim((string)$params['reason']);

        if ($userId <= 0 || $learningPlanId <= 0 || $coreCourseId <= 0) {
            return self::error('Identificadores inválidos.');
        }

        $row = $DB->get_record('gmk_course_progre', [
            'userid'         => $userId,
            'courseid'       => $coreCourseId,
            'learningplanid' => $learningPlanId,
        ], '*', MUST_EXIST);

        $homoType = trim((string)($row->homologation_type ?? ''));
        if ($homoType === '') {
            return self::error('La asignatura no tiene una homologación activa para revertir.');
        }

        $now = time();
        $auditNote = '';
        if (trim((string)($row->homologation_note ?? '')) !== '') {
            $auditNote = trim((string)$row->homologation_note);
        }
        if ($reason !== '') {
            $auditNote = ($auditNote !== '' ? $auditNote . ' | ' : '') . 'Revertido: ' . $reason;
        }

        $previous = [
            'homologation_type' => $homoType,
            'homologation_note' => (string)($row->homologation_note ?? ''),
            'homologation_at'   => (int)($row->homologation_at ?? 0),
            'homologation_by'   => (int)($row->homologation_by ?? 0),
            'grade'             => (float)($row->grade ?? 0),
            'status'            => (int)($row->status ?? 0),
        ];

        $transaction = $DB->start_delegated_transaction();

        try {
            // 1. Reset gmk_course_progre: clear homologation metadata, drop the
            //    consolidated grade, return the row to a pre-homologation state.
            $row->grade             = 0.0;
            $row->status            = self::RESET_STATUS;
            $row->progress          = 0.0;
            $row->classid           = 0;
            $row->groupid           = 0;
            $row->homologation_type = '';
            $row->homologation_note = $auditNote;
            $row->homologation_at   = $now;
            $row->homologation_by   = (int)$USER->id;
            $row->timemodified      = $now;
            $row->usermodified      = (int)$USER->id;
            $DB->update_record('gmk_course_progre', $row);

            // 2. Null the Nota Final Integrada grade_grades so the panel and
            //    gradebook stop reporting the homologated value.
            $nfiItems = $DB->get_records_sql(
                "SELECT id
                   FROM {grade_items}
                  WHERE courseid = :cid
                    AND itemtype = 'manual'
                    AND (itemname LIKE :nfi1
                         OR itemname LIKE :nfi2
                         OR itemname LIKE :nfi3)",
                [
                    'cid'  => $coreCourseId,
                    'nfi1' => '%Nota Final Integrada%',
                    'nfi2' => '%Final Integrada%',
                    'nfi3' => '%Nota Final%',
                ]
            );

            foreach ($nfiItems as $nfiItem) {
                $gradeObj = grade_item::fetch(['id' => (int)$nfiItem->id]);
                if (!$gradeObj) {
                    continue;
                }
                $gg = grade_grade::fetch([
                    'itemid' => (int)$gradeObj->id,
                    'userid' => $userId,
                ]);
                if (!$gg) {
                    continue;
                }
                $gg->finalgrade = null;
                $gg->rawgrade   = null;
                $gg->update('homologation_revert');
            }

            // 3. Null the course total grade_grades too so the Moodle
            //    course-level total follows the revert.
            $courseTotal = grade_item::fetch([
                'courseid' => $coreCourseId,
                'itemtype' => 'course',
            ]);
            if ($courseTotal) {
                $totalGrade = grade_grade::fetch([
                    'itemid' => (int)$courseTotal->id,
                    'userid' => $userId,
                ]);
                if ($totalGrade) {
                    $totalGrade->finalgrade = null;
                    $totalGrade->rawgrade   = null;
                    $totalGrade->update('homologation_revert');
                }
            }

            $transaction->allow_commit();
        } catch (\Throwable $t) {
            $transaction->rollback($t);
            gmk_log('ERROR revert_homologation: ' . $t->getMessage() . ' @ ' . $t->getFile() . ':' . $t->getLine());
            return self::error('No se pudo revertir la homologación: ' . $t->getMessage());
        }

        gmk_log(sprintf(
            'revert_homologation OK user=%d plan=%d course=%d type=%s by=%d',
            $userId, $learningPlanId, $coreCourseId, $homoType, (int)$USER->id
        ));

        return [
            'status'               => 'ok',
            'message'              => 'Homologación revertida correctamente.',
            'gcp_id'               => (int)$row->id,
            'course_status'        => self::RESET_STATUS,
            'previous_type'        => $previous['homologation_type'],
            'previous_note'        => $previous['homologation_note'],
            'previous_at'          => $previous['homologation_at'],
            'previous_by'          => $previous['homologation_by'],
            'previous_grade'       => $previous['grade'],
            'previous_status'      => $previous['status'],
            'homologation_at'      => $now,
            'homologation_by'      => (int)$USER->id,
        ];
    }

    /**
     * Describes the return value of the {@see self::execute()} method.
     *
     * @return external_description
     */
    public static function execute_returns(): external_description
    {
        return new external_single_structure([
            'status'             => new external_value(PARAM_TEXT, 'ok | error'),
            'message'            => new external_value(PARAM_TEXT, 'Descriptive message'),
            'gcp_id'             => new external_value(PARAM_INT,  'gmk_course_progre.id (0 on error)', VALUE_DEFAULT, 0),
            'course_status'      => new external_value(PARAM_INT,  'New gmk_course_progre.status after revert', VALUE_DEFAULT, 0),
            'previous_type'      => new external_value(PARAM_TEXT, 'Homologation type that was reverted', VALUE_DEFAULT, ''),
            'previous_note'      => new external_value(PARAM_RAW,  'Original observation captured at homologation time', VALUE_DEFAULT, ''),
            'previous_at'        => new external_value(PARAM_INT,  'Unix timestamp of the original homologation', VALUE_DEFAULT, 0),
            'previous_by'        => new external_value(PARAM_INT,  'user.id who applied the original homologation', VALUE_DEFAULT, 0),
            'previous_grade'     => new external_value(PARAM_FLOAT, 'Grade that was set by the homologation', VALUE_DEFAULT, 0),
            'previous_status'    => new external_value(PARAM_INT,  'gmk_course_progre.status before the revert', VALUE_DEFAULT, 0),
            'homologation_at'    => new external_value(PARAM_INT,  'Unix timestamp of the revert', VALUE_DEFAULT, 0),
            'homologation_by'    => new external_value(PARAM_INT,  'user.id who performed the revert', VALUE_DEFAULT, 0),
        ]);
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
            'status'             => 'error',
            'message'            => $message,
            'gcp_id'             => 0,
            'course_status'      => 0,
            'previous_type'      => '',
            'previous_note'      => '',
            'previous_at'        => 0,
            'previous_by'        => 0,
            'previous_grade'     => 0,
            'previous_status'    => 0,
            'homologation_at'    => 0,
            'homologation_by'    => 0,
        ];
    }
}