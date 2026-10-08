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
 * Returns the consolidated failed-subjects report for a given academic
 * period. Each row is a (student, course) pair where the student has
 * status 5 (Reprobada) or 7 (Revalidando) in gmk_course_progre, and the
 * course may or may not have a projected gmk_class for that period in
 * the student's jornada.
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Antigravity
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\admin\failed_subjects;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/failed_subjects_manager.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;
use context_system;

class get_report extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'periodid'         => new external_value(PARAM_INT, 'Academic period id', VALUE_DEFAULT, 0),
            'search'           => new external_value(PARAM_TEXT, 'Free-text search', VALUE_DEFAULT, ''),
            'learningplanid'   => new external_value(PARAM_INT, 'Filter by learning plan', VALUE_DEFAULT, 0),
            'jornada'          => new external_value(PARAM_TEXT, 'Filter by jornada (Diurno|Nocturno|Sabatino)', VALUE_DEFAULT, ''),
            'hasclass'         => new external_value(PARAM_ALPHA, 'yes|no|all', VALUE_DEFAULT, 'all'),
            'hasquota'         => new external_value(PARAM_ALPHA, 'yes|no|all', VALUE_DEFAULT, 'all'),
            'financial_status' => new external_value(PARAM_TEXT, 'Filter by financial status', VALUE_DEFAULT, ''),
            'student_status'   => new external_value(PARAM_TEXT, 'Filter by academic status (activo|aplazado|retirado|...)', VALUE_DEFAULT, ''),
            'page'             => new external_value(PARAM_INT, 'Page (0-based)', VALUE_DEFAULT, 0),
            'perpage'          => new external_value(PARAM_INT, 'Items per page', VALUE_DEFAULT, 50),
        ]);
    }

    public static function execute(
        int $periodid = 0,
        string $search = '',
        int $learningplanid = 0,
        string $jornada = '',
        string $hasclass = 'all',
        string $hasquota = 'all',
        string $financial_status = '',
        string $student_status = '',
        int $page = 0,
        int $perpage = 50
    ): array {
        global $DB;
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:view_failed_subjects_report', $context);

        $params = self::validate_parameters(self::execute_parameters(), [
            'periodid'         => $periodid,
            'search'           => $search,
            'learningplanid'   => $learningplanid,
            'jornada'          => $jornada,
            'hasclass'         => $hasclass,
            'hasquota'         => $hasquota,
            'financial_status' => $financial_status,
            'student_status'   => $student_status,
            'page'             => $page,
            'perpage'          => $perpage,
        ]);

        $filters = [
            'search'           => $params['search'],
            'learningplanid'   => (int)$params['learningplanid'],
            'jornada'          => $params['jornada'],
            'hasclass'         => $params['hasclass'] === 'all' ? null : $params['hasclass'],
            'hasquota'         => $params['hasquota'] === 'all' ? null : $params['hasquota'],
            'financial_status' => $params['financial_status'],
            'student_status'   => $params['student_status'],
        ];
        $perpage = max(1, min(200, (int)$params['perpage']));
        $page    = max(0, (int)$params['page']);

        $result = \local_grupomakro_core\local\failed_subjects_manager::build_report(
            (int)$params['periodid'],
            $filters,
            $page,
            $perpage
        );

        // Append the list of learning plans for the filter dropdown.
        $plans = $DB->get_records_menu('local_learning_plans', null, 'name ASC', 'id, name');
        $plansOut = [];
        foreach ($plans as $id => $name) {
            $plansOut[] = ['id' => (int)$id, 'name' => (string)$name];
        }

        return [
            'rows'    => $result['rows'],
            'total'   => (int)$result['total'],
            'summary' => $result['summary'],
            'page'    => (int)$result['page'],
            'perpage' => (int)$result['perpage'],
            'learningplans' => $plansOut,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'rows' => new external_multiple_structure(new external_single_structure([
                'progress_id'        => new external_value(PARAM_INT, 'gmk_course_progre.id'),
                'userid'             => new external_value(PARAM_INT, 'Moodle userid'),
                'student_name'       => new external_value(PARAM_RAW, 'Full name'),
                'student_idnumber'   => new external_value(PARAM_RAW, 'Moodle idnumber'),
                'user_email'         => new external_value(PARAM_RAW, 'Moodle email'),
                'cedula'             => new external_value(PARAM_RAW, 'Cedula from user_info_data'),
                'phone'              => new external_value(PARAM_RAW, 'Phone from Odoo'),
                'mobile'             => new external_value(PARAM_RAW, 'Mobile from Odoo'),
                'contact_email'      => new external_value(PARAM_RAW, 'Email from Odoo (may differ)'),
                'financial_status'   => new external_value(PARAM_RAW, 'Financial status code'),
                'financial_label'    => new external_value(PARAM_RAW, 'Financial status label'),
                'academic_status'    => new external_value(PARAM_RAW, 'Academic status (activo|aplazado|retirado|...)'),
                'jornada_estudiante' => new external_value(PARAM_RAW, 'Diurno|Nocturno|Sabatino'),
                'courseid'           => new external_value(PARAM_INT, 'Course id'),
                'coursename'         => new external_value(PARAM_RAW, 'Course fullname'),
                'last_grade'         => new external_value(PARAM_FLOAT, 'Last grade on record'),
                'computed_grade'     => new external_value(PARAM_FLOAT, 'Recomputed grade via gradebookWeightedTotal (matches grademodal.js)', VALUE_OPTIONAL, null, NULL_ALLOWED),
                'failed_at'          => new external_value(PARAM_INT, 'Last timemodified of progress record'),
                'learningplanid'     => new external_value(PARAM_INT, 'Plan id'),
                'planname'           => new external_value(PARAM_RAW, 'Plan name'),
                'progress_status'    => new external_value(PARAM_INT, '5 or 7'),
                'classid'            => new external_value(PARAM_INT, 'Target gmk_class.id', VALUE_OPTIONAL),
                'classname'          => new external_value(PARAM_RAW, 'Target class name'),
                'corecourseid'       => new external_value(PARAM_INT, 'Target course id'),
                'jornada_grupo'      => new external_value(PARAM_RAW, 'Target class shift'),
                'jornada_match'      => new external_value(PARAM_BOOL, 'Jornada matches'),
                'classroomcapacity'  => new external_value(PARAM_INT, 'Quota'),
                'enrolled_count'     => new external_value(PARAM_INT, 'Current enrolled count'),
                'is_full'            => new external_value(PARAM_BOOL, 'Class is full'),
                'available_classes'  => new external_multiple_structure(new external_single_structure([
                    'classid'           => new external_value(PARAM_INT, 'gmk_class id'),
                    'classname'         => new external_value(PARAM_RAW, 'Class name'),
                    'shift'             => new external_value(PARAM_RAW, 'Diurno|Nocturno|Sabatino'),
                    'jornada_match'     => new external_value(PARAM_BOOL, 'Shift matches student jornada'),
                    'classroomcapacity' => new external_value(PARAM_INT, 'Quota'),
                    'enrolled_count'    => new external_value(PARAM_INT, 'Current enrolled count'),
                    'is_full'           => new external_value(PARAM_BOOL, 'Class is full'),
                ]), 'All classes projected for this course+period'),
            ])),
            'total' => new external_value(PARAM_INT, 'Total rows after filter'),
            'summary' => new external_single_structure([
                'students'     => new external_value(PARAM_INT, 'Distinct students'),
                'failed_total' => new external_value(PARAM_INT, 'Total failed subjects'),
                'with_class'   => new external_value(PARAM_INT, 'Rows with a matching class'),
                'with_quota'   => new external_value(PARAM_INT, 'Rows where class has free quota'),
                'full_classes' => new external_value(PARAM_INT, 'Rows where class is full'),
                'periodid'     => new external_value(PARAM_INT, 'Period id'),
            ]),
            'page'    => new external_value(PARAM_INT, 'Current page'),
            'perpage' => new external_value(PARAM_INT, 'Per page'),
            'learningplans' => new external_multiple_structure(
                new external_single_structure([
                    'id'   => new external_value(PARAM_INT, 'Plan id'),
                    'name' => new external_value(PARAM_RAW, 'Plan name'),
                ])
            ),
        ]);
    }
}
