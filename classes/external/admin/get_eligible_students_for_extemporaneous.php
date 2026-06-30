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
 * Returns the enrolled students of a class with revalidation eligibility info,
 * used by the director's extemporaneous wizard.
 *
 * The list includes:
 *  - students that satisfy eligibility (is_eligible=true)
 *  - students that DON'T (is_eligible=false) so the director can see WHY
 *
 * For each student, returns: id, fullname, idnumber, email, final_grade,
 * practicalhours, is_eligible, existing_revalidation_id (if any).
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Antigravity
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\admin;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/revalida_manager.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;
use context_system;

class get_eligible_students_for_extemporaneous extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'classid' => new external_value(PARAM_INT, 'Class id', VALUE_REQUIRED),
            'search'  => new external_value(PARAM_TEXT, 'Free-text search', VALUE_DEFAULT, ''),
            'only_eligible' => new external_value(PARAM_BOOL, 'If true, only return eligible students', VALUE_DEFAULT, false),
        ]);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'students' => new external_multiple_structure(new external_single_structure([
                'userid'             => new external_value(PARAM_INT, 'User id'),
                'fullname'           => new external_value(PARAM_RAW, 'Fullname'),
                'idnumber'           => new external_value(PARAM_RAW, 'idnumber'),
                'email'              => new external_value(PARAM_RAW, 'Email'),
                'final_grade'        => new external_value(PARAM_FLOAT, 'Computed final grade (nullable)'),
                'practicalhours'     => new external_value(PARAM_INT, 'Practical hours'),
                'teoricalhours'      => new external_value(PARAM_INT, 'Theoretical hours'),
                'progress_status'    => new external_value(PARAM_INT, 'gmk_course_progre.status'),
                'progress_label'     => new external_value(PARAM_RAW, 'Status label'),
                'is_eligible'        => new external_value(PARAM_BOOL, 'Meets revalidation criteria'),
                'ineligibility_reason'=> new external_value(PARAM_RAW, 'Reason if not eligible'),
                'existing_revalidation_id' => new external_value(PARAM_INT, 'Existing gmk_revalidations.id or 0'),
                'existing_revalidation_status' => new external_value(PARAM_RAW, 'Existing status'),
                'existing_revalidation_extemp' => new external_value(PARAM_INT, 'Existing extemporaneous flag'),
            ])),
        ]);
    }

    public static function execute(int $classid, string $search = '', bool $only_eligible = false) {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'classid' => $classid,
            'search' => $search,
            'only_eligible' => $only_eligible,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:create_extemporaneous_revalidations', $context);

        $classid = (int)$params['classid'];
        $search = trim((string)$params['search']);

        $class = $DB->get_record('gmk_class', ['id' => $classid], '*', MUST_EXIST);

        $where = ['gcp.classid = :cid'];
        $sqlparams = ['cid' => $classid];

        if ($search !== '') {
            $like = '%' . $search . '%';
            $where[] = '(' . $DB->sql_like('u.firstname', ':s1', false) . ' OR '
                          . $DB->sql_like('u.lastname', ':s2', false) . ' OR '
                          . $DB->sql_like('u.idnumber', ':s3', false) . ' OR '
                          . $DB->sql_like('u.email', ':s4', false) . ')';
            $sqlparams['s1'] = $like;
            $sqlparams['s2'] = $like;
            $sqlparams['s3'] = $like;
            $sqlparams['s4'] = $like;
        }

        $sql = "SELECT gcp.id AS progreid, gcp.userid, gcp.practicalhours, gcp.teoricalhours,
                       gcp.grade AS storedgrade, gcp.status AS progress_status,
                       u.firstname, u.lastname, u.email, u.idnumber,
                       r.id AS existing_revalidation_id, r.status AS existing_status,
                       r.extemporaneous AS existing_extemp
                  FROM {gmk_course_progre} gcp
                  JOIN {user} u ON u.id = gcp.userid AND u.deleted = 0
                  LEFT JOIN {gmk_revalidations} r ON r.classid = gcp.classid AND r.userid = gcp.userid
                 WHERE " . implode(' AND ', $where) . "
              ORDER BY u.lastname ASC, u.firstname ASC";

        $rows = $DB->get_records_sql($sql, $sqlparams, 0, 200);

        $out = [];
        foreach ($rows as $r) {
            // Use the recomputed final grade (weighted by current gradebook). If
            // unavailable, fall back to the stored gmk_course_progre.grade.
            $finalGrade = \gmk_get_student_class_grade($classid, (int)$r->userid);
            if ($finalGrade === null && isset($r->storedgrade)) {
                $finalGrade = (float)$r->storedgrade;
            }
            $practical = (int)($r->practicalhours ?? 0);
            $isEligible = ($finalGrade !== null)
                && \local_grupomakro_core\local\revalida_manager::is_eligible((float)$finalGrade, $practical);

            if ($only_eligible && !$isEligible) {
                continue;
            }

            $reasons = [];
            if ($finalGrade === null) {
                $reasons[] = 'sin nota final';
            } elseif ((float)$finalGrade > 70.9) {
                $reasons[] = 'nota superior a 70.9';
            } elseif ((float)$finalGrade < 60.0) {
                $reasons[] = 'nota inferior a 60.0';
            }
            if ($practical > 0) {
                $reasons[] = 'tiene horas prácticas';
            }
            $ineligReason = empty($reasons) ? '' : implode('; ', $reasons);

            $statusLabel = self::status_label((int)$r->progress_status);

            $out[] = [
                'userid' => (int)$r->userid,
                'fullname' => trim((string)$r->firstname . ' ' . (string)$r->lastname),
                'idnumber' => (string)($r->idnumber ?? ''),
                'email' => (string)($r->email ?? ''),
                'final_grade' => $finalGrade === null ? null : round((float)$finalGrade, 2),
                'practicalhours' => $practical,
                'teoricalhours' => (int)($r->teoricalhours ?? 0),
                'progress_status' => (int)($r->progress_status ?? 0),
                'progress_label' => $statusLabel,
                'is_eligible' => $isEligible,
                'ineligibility_reason' => $ineligReason,
                'existing_revalidation_id' => (int)($r->existing_revalidation_id ?? 0),
                'existing_revalidation_status' => (string)($r->existing_status ?? ''),
                'existing_revalidation_extemp' => (int)($r->existing_extemp ?? 0),
            ];
        }

        return ['students' => $out];
    }

    private static function status_label(int $s): string {
        $map = [
            1 => 'Disponible',
            2 => 'Cursando',
            3 => 'Completado',
            4 => 'Aprobada',
            5 => 'Reprobada',
            6 => 'Pendiente Reválida',
            7 => 'Revalidando',
            99 => 'Migración pendiente',
        ];
        return $map[$s] ?? 'Desconocido';
    }
}