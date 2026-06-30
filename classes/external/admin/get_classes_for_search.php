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
 * Lightweight class search for the extemporaneous revalidation wizard.
 *
 * Returns up to N classes matching the query (by class name, course name or
 * instructor name). Includes basic metadata useful for the picker.
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Antigravity
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\admin;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;
use context_system;

class get_classes_for_search extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'query'  => new external_value(PARAM_TEXT, 'Search query', VALUE_DEFAULT, ''),
            'limit'  => new external_value(PARAM_INT, 'Max results (cap 50)', VALUE_DEFAULT, 20),
            'only_with_eligible' => new external_value(PARAM_BOOL, 'Only classes with at least 1 eligible student', VALUE_DEFAULT, false),
        ]);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'classes' => new external_multiple_structure(new external_single_structure([
                'id'             => new external_value(PARAM_INT, 'Class id'),
                'name'           => new external_value(PARAM_RAW, 'Class name'),
                'corecourseid'   => new external_value(PARAM_INT, 'Course id'),
                'coursename'     => new external_value(PARAM_RAW, 'Course fullname'),
                'instructorid'   => new external_value(PARAM_INT, 'Instructor userid'),
                'instructor_name'=> new external_value(PARAM_RAW, 'Instructor name'),
                'periodid'       => new external_value(PARAM_INT, 'Academic period id'),
                'periodname'     => new external_value(PARAM_RAW, 'Period name'),
                'student_count'  => new external_value(PARAM_INT, 'Enrolled student count'),
                'eligible_count' => new external_value(PARAM_INT, 'Eligible-for-revalidation student count'),
            ])),
        ]);
    }

    public static function execute(string $query = '', int $limit = 20, bool $only_with_eligible = false) {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'query' => $query,
            'limit' => $limit,
            'only_with_eligible' => $only_with_eligible,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:create_extemporaneous_revalidations', $context);

        $limit = max(1, min(50, (int)$params['limit']));
        $query = trim((string)$params['query']);

        $where = ['1=1'];
        $sqlparams = [];
        if ($query !== '') {
            $like = '%' . $query . '%';
            $where[] = '(' . $DB->sql_like('gc.name', ':q1', false) . ' OR '
                          . $DB->sql_like('c.fullname', ':q2', false) . ' OR '
                          . $DB->sql_like("CONCAT(i.firstname, ' ', i.lastname)", ':q3', false) . ')';
            $sqlparams['q1'] = $like;
            $sqlparams['q2'] = $like;
            $sqlparams['q3'] = $like;
        }

        $sql = "SELECT gc.id, gc.name, gc.corecourseid, gc.instructorid, gc.periodid,
                       c.fullname AS coursename,
                       i.firstname AS instructor_firstname, i.lastname AS instructor_lastname,
                       ap.name AS periodname,
                       (SELECT COUNT(1) FROM {gmk_course_progre} gcp
                          WHERE gcp.classid = gc.id) AS student_count
                  FROM {gmk_class} gc
                  JOIN {course} c ON c.id = gc.corecourseid
                  JOIN {user} i   ON i.id = gc.instructorid
                  LEFT JOIN {gmk_academic_periods} ap ON ap.id = gc.periodid
                 WHERE " . implode(' AND ', $where) . "
              ORDER BY gc.timecreated DESC, gc.id DESC";

        $rows = $DB->get_records_sql($sql, $sqlparams, 0, $limit);

        $out = [];
        foreach ($rows as $r) {
            // Count eligible students per class (best-effort, uses grade + practicalhours).
            $eligible = 0;
            if (!empty($r->corecourseid)) {
                $prows = $DB->get_records('gmk_course_progre',
                    ['classid' => (int)$r->id], '', 'id,userid,practicalhours,grade,classid');
                foreach ($prows as $p) {
                    $practical = (int)($p->practicalhours ?? 0);
                    $grade = $p->grade === null ? null : (float)$p->grade;
                    // Approximate eligibility from the stored grade (revalidated grade if present).
                    // Exact eligibility requires gmk_get_student_class_grade (weight recompute) which
                    // is too expensive for this picker — fallback to the stored grade is good enough
                    // for a list view; the wizard revalidates via the create WS.
                    if ($practical === 0 && $grade !== null && $grade >= 60.0 && $grade <= 70.9) {
                        $eligible++;
                    }
                }
            }
            if ($only_with_eligible && $eligible === 0) {
                continue;
            }
            $out[] = [
                'id'             => (int)$r->id,
                'name'           => (string)$r->name,
                'corecourseid'   => (int)$r->corecourseid,
                'coursename'     => (string)($r->coursename ?? ''),
                'instructorid'   => (int)$r->instructorid,
                'instructor_name'=> trim((string)($r->instructor_firstname ?? '') . ' ' . (string)($r->instructor_lastname ?? '')),
                'periodid'       => (int)($r->periodid ?? 0),
                'periodname'     => (string)($r->periodname ?? ''),
                'student_count'  => (int)($r->student_count ?? 0),
                'eligible_count' => $eligible,
            ];
        }

        return ['classes' => $out];
    }
}