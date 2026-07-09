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
 * Class definition for the local_grupomakro_get_homologation_audit external function.
 *
 * Returns the full audit trail of homologate/revert actions for a given
 * (user, course, learning plan) triple, ordered chronologically. Powers the
 * "Ver historial" timeline in the academic panel grades modal.
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
use external_multiple_structure;
use external_single_structure;
use external_value;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

/**
 * External function 'local_grupomakro_get_homologation_audit' implementation.
 */
class get_homologation_audit extends external_api
{

    /**
     * Describes parameters of the {@see self::execute()} method.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters
    {
        return new external_function_parameters([
            'userId'         => new external_value(PARAM_INT, 'Student user id.',  VALUE_REQUIRED),
            'coreCourseId'   => new external_value(PARAM_INT, 'Moodle course id.', VALUE_REQUIRED),
            'learningPlanId' => new external_value(PARAM_INT, 'Learning plan id (0 = all plans).', VALUE_DEFAULT, 0),
            'limit'          => new external_value(PARAM_INT, 'Max rows (default 50).', VALUE_DEFAULT, 50),
        ]);
    }

    /**
     * Fetch the audit log for a (user, course, [plan]) triple.
     *
     * @param int $userId
     * @param int $coreCourseId
     * @param int $learningPlanId  0 = all plans
     * @param int $limit
     * @return array
     */
    public static function execute(
        int $userId,
        int $coreCourseId,
        int $learningPlanId = 0,
        int $limit = 50
    ): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'userId'         => $userId,
            'coreCourseId'   => $coreCourseId,
            'learningPlanId' => $learningPlanId,
            'limit'          => $limit,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        $userId         = (int)$params['userId'];
        $coreCourseId   = (int)$params['coreCourseId'];
        $learningPlanId = (int)$params['learningPlanId'];
        $limit          = max(1, min(200, (int)$params['limit']));

        if ($userId <= 0 || $coreCourseId <= 0) {
            return [
                'user_id'        => $userId,
                'core_course_id' => $coreCourseId,
                'entries'        => [],
            ];
        }

        $where  = 'a.userid = :uid AND a.corecourseid = :cid';
        $qparams = ['uid' => $userId, 'cid' => $coreCourseId];
        if ($learningPlanId > 0) {
            $where .= ' AND a.learningplanid = :lpid';
            $qparams['lpid'] = $learningPlanId;
        }

        $rows = $DB->get_records_sql(
            "SELECT a.id, a.userid, a.corecourseid, a.learningplanid, a.gcp_id,
                    a.action, a.type, a.grade, a.course_status, a.observation,
                    a.previous_observation, a.previous_grade, a.previous_status,
                    a.previous_type, a.applied_by, a.applied_at,
                    u.firstname AS applied_firstname, u.lastname AS applied_lastname
               FROM {gmk_homologation_audit} a
          LEFT JOIN {user} u ON u.id = a.applied_by
              WHERE $where
           ORDER BY a.applied_at DESC, a.id DESC",
            $qparams,
            0,
            $limit
        );

        $entries = [];
        foreach ($rows as $row) {
            $entries[] = [
                'id'                   => (int)$row->id,
                'gcp_id'               => (int)($row->gcp_id ?? 0),
                'action'               => (string)($row->action ?? ''),
                'type'                 => (string)($row->type ?? ''),
                'grade'                => is_null($row->grade) ? null : (float)$row->grade,
                'course_status'        => is_null($row->course_status) ? null : (int)$row->course_status,
                'observation'          => (string)($row->observation ?? ''),
                'previous_observation' => (string)($row->previous_observation ?? ''),
                'previous_grade'       => is_null($row->previous_grade) ? null : (float)$row->previous_grade,
                'previous_status'      => is_null($row->previous_status) ? null : (int)$row->previous_status,
                'previous_type'        => (string)($row->previous_type ?? ''),
                'applied_by'           => (int)($row->applied_by ?? 0),
                'applied_by_name'      => trim(((string)($row->applied_firstname ?? '')) . ' ' . ((string)($row->applied_lastname ?? ''))),
                'applied_at'           => (int)($row->applied_at ?? 0),
            ];
        }

        return [
            'user_id'        => $userId,
            'core_course_id' => $coreCourseId,
            'entries'        => $entries,
        ];
    }

    /**
     * Describes the return value of the {@see self::execute()} method.
     *
     * @return external_description
     */
    public static function execute_returns(): external_description
    {
        $entry = new external_single_structure([
            'id'                   => new external_value(PARAM_INT,  'Audit row id'),
            'gcp_id'               => new external_value(PARAM_INT,  'gmk_course_progre.id at the time of the action'),
            'action'               => new external_value(PARAM_TEXT, 'homologate|revert'),
            'type'                 => new external_value(PARAM_TEXT, 'Homologation type at the time of the action'),
            'grade'                => new external_value(PARAM_FLOAT, 'Grade at the time of the action', VALUE_DEFAULT, null, NULL_ALLOWED),
            'course_status'        => new external_value(PARAM_INT,  'gmk_course_progre.status after the action', VALUE_DEFAULT, null, NULL_ALLOWED),
            'observation'          => new external_value(PARAM_RAW,  'Observation captured at the time of the action'),
            'previous_observation' => new external_value(PARAM_RAW,  'Observation that was cleared (revert only)'),
            'previous_grade'       => new external_value(PARAM_FLOAT, 'Grade that was cleared (revert only)', VALUE_DEFAULT, null, NULL_ALLOWED),
            'previous_status'      => new external_value(PARAM_INT,  'Status that was cleared (revert only)', VALUE_DEFAULT, null, NULL_ALLOWED),
            'previous_type'        => new external_value(PARAM_TEXT, 'Type that was cleared (revert only)'),
            'applied_by'           => new external_value(PARAM_INT,  'user.id who applied the action'),
            'applied_by_name'      => new external_value(PARAM_TEXT, 'Full name of the user who applied the action'),
            'applied_at'           => new external_value(PARAM_INT,  'Unix timestamp of the action'),
        ]);

        return new external_single_structure([
            'user_id'        => new external_value(PARAM_INT, 'Echo of userId'),
            'core_course_id' => new external_value(PARAM_INT, 'Echo of coreCourseId'),
            'entries'        => new external_multiple_structure($entry, 'Chronological list (newest first)'),
        ]);
    }
}