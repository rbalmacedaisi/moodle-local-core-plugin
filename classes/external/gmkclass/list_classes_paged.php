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
 * Server-side paginated listing of classes for the classmanagement page.
 *
 * Reuses the bulk-prefetch enrichment from list_classes() (locallib.php)
 * so the response carries the exact same fields the existing template
 * expects (instructorName, periodName, learningPlanName, coreCourseName,
 * classroomName, etc.) — only the count + page are now bounded by the
 * SQL filters.
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Antigravity
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\gmkclass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/class_query_manager.php');

use context_system;
use external_api;
use external_description;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use local_grupomakro_core\local\class_query_manager;
use stdClass;

/**
 * External function 'local_grupomakro_list_classes_paged' implementation.
 */
class list_classes_paged extends external_api {

    /**
     * Describes parameters of execute().
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'search'         => new external_value(PARAM_TEXT, 'Free-text search on name, shift, instructor, learning plan, course.', VALUE_DEFAULT, ''),
            'periodid'       => new external_value(PARAM_INT,  'Academic period id (gmk_academic_periods.id).', VALUE_DEFAULT, 0),
            'learningplanid' => new external_value(PARAM_INT,  'Learning plan id.', VALUE_DEFAULT, 0),
            'corecourseid'   => new external_value(PARAM_INT,  'Core course id (the "asignatura" filter).', VALUE_DEFAULT, 0),
            'status'         => new external_value(PARAM_ALPHA, 'active|closed|all.', VALUE_DEFAULT, 'active'),
            'sort'           => new external_value(PARAM_ALPHA, 'name|timecreated|timemodified|startdate|enddate|periodid|instructor.', VALUE_DEFAULT, 'timecreated'),
            'dir'            => new external_value(PARAM_ALPHA, 'ASC|DESC.', VALUE_DEFAULT, 'DESC'),
            'page'           => new external_value(PARAM_INT,  'Page (0-based).', VALUE_DEFAULT, 0),
            'perpage'        => new external_value(PARAM_INT,  'Items per page (1..200).', VALUE_DEFAULT, 25),
        ]);
    }

    /**
     * Describes the return value of execute().
     */
    public static function execute_returns(): external_description {
        return new external_single_structure([
            'items'      => new external_multiple_structure(
                new external_single_structure([
                    'id'                   => new external_value(PARAM_INT, 'Class id'),
                    'name'                 => new external_value(PARAM_RAW, 'Class name'),
                    'type'                 => new external_value(PARAM_INT, 'Class type'),
                    'typelabel'            => new external_value(PARAM_RAW, 'Class type label'),
                    'icon'                 => new external_value(PARAM_RAW, 'Class type icon'),
                    'closed'               => new external_value(PARAM_INT, 'Closed flag (1=closed)'),
                    'instructorid'         => new external_value(PARAM_INT, 'Instructor userid'),
                    'instructorName'       => new external_value(PARAM_RAW, 'Instructor fullname'),
                    'instructorProfileImage' => new external_value(PARAM_RAW, 'Instructor profile image URL'),
                    'periodid'             => new external_value(PARAM_INT, 'Period id (academic)'),
                    'periodName'           => new external_value(PARAM_RAW, 'Period name'),
                    'learningplanid'       => new external_value(PARAM_INT, 'Learning plan id'),
                    'learningPlanName'     => new external_value(PARAM_RAW, 'Learning plan name'),
                    'corecourseid'         => new external_value(PARAM_INT, 'Core course id'),
                    'coreCourseName'       => new external_value(PARAM_RAW, 'Core course name'),
                    'classroomid'          => new external_value(PARAM_INT, 'Classroom id'),
                    'classroomName'        => new external_value(PARAM_RAW, 'Classroom name'),
                    'classdays'            => new external_value(PARAM_RAW, 'Classdays binary string'),
                    'classDaysString'      => new external_value(PARAM_RAW, 'Classdays rendered'),
                    'inithourformatted'    => new external_value(PARAM_RAW, 'Init hour formatted'),
                    'endhourformatted'     => new external_value(PARAM_RAW, 'End hour formatted'),
                    'inittimets'           => new external_value(PARAM_INT, 'Init hour in seconds'),
                    'endtimets'            => new external_value(PARAM_INT, 'End hour in seconds'),
                    'initdate'             => new external_value(PARAM_INT, 'Start date ts'),
                    'enddate'              => new external_value(PARAM_INT, 'End date ts'),
                    'startDate'            => new external_value(PARAM_RAW, 'Display start date'),
                    'timecreated'          => new external_value(PARAM_INT, 'Created ts'),
                    'timemodified'         => new external_value(PARAM_INT, 'Modified ts'),
                    'enroledStudents'      => new external_value(PARAM_INT, 'Enrolled student count'),
                    'preRegisteredStudents'=> new external_value(PARAM_INT, 'Pre-registered count'),
                    'queuedStudents'       => new external_value(PARAM_INT, 'Queue count'),
                    'shift'                => new external_value(PARAM_RAW, 'Shift raw value'),
                    'shiftvalue'           => new external_value(PARAM_RAW, 'Shift trimmed value'),
                    'shiftdisplay'         => new external_value(PARAM_RAW, 'Shift display value'),
                    'groupurl'             => new external_value(PARAM_RAW, 'Direct course-section URL'),
                    'coursesectionid'      => new external_value(PARAM_INT, 'Course section id'),
                ])
            ),
            'total'      => new external_value(PARAM_INT, 'Total classes matching the current filter'),
            'page'       => new external_value(PARAM_INT, 'Current page (0-based)'),
            'perpage'    => new external_value(PARAM_INT, 'Items per page applied'),
            'totalpages' => new external_value(PARAM_INT, 'Total pages'),
            'facets'     => new external_single_structure([
                'periods' => new external_multiple_structure(
                    new external_single_structure([
                        'id'    => new external_value(PARAM_INT, 'Period id'),
                        'name'  => new external_value(PARAM_RAW, 'Period name'),
                        'count' => new external_value(PARAM_INT, 'Class count in that period'),
                    ])
                ),
                'courses' => new external_multiple_structure(
                    new external_single_structure([
                        'id'    => new external_value(PARAM_INT, 'Course id'),
                        'name'  => new external_value(PARAM_RAW, 'Course fullname'),
                        'count' => new external_value(PARAM_INT, 'Class count in that course'),
                    ])
                ),
            ]),
        ]);
    }

    /**
     * Web service entry point.
     */
    public static function execute(
        string $search = '',
        int $periodid = 0,
        int $learningplanid = 0,
        int $corecourseid = 0,
        string $status = 'active',
        string $sort = 'timecreated',
        string $dir = 'DESC',
        int $page = 0,
        int $perpage = 25
    ) {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'search'         => $search,
            'periodid'       => $periodid,
            'learningplanid' => $learningplanid,
            'corecourseid'   => $corecourseid,
            'status'         => $status,
            'sort'           => $sort,
            'dir'            => $dir,
            'page'           => $page,
            'perpage'        => $perpage,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:manage_classes', $context);

        // Clamp page / perpage (matches the rest of the plugin's WS).
        $perpage = max(1, min(200, (int)$params['perpage']));
        $page    = max(0, (int)$params['page']);

        // Whitelist status to avoid surprises.
        $status = in_array($params['status'], ['active', 'closed', 'all'], true)
            ? $params['status']
            : 'active';

        // Whitelist sort/dir.
        $sort = array_key_exists($params['sort'], class_query_manager::SORT_COLUMNS)
            ? $params['sort']
            : 'timecreated';
        $dir  = (strtoupper($params['dir']) === 'ASC') ? 'ASC' : 'DESC';

        $filters = [
            'search'         => $params['search'],
            'periodid'       => (int)$params['periodid'],
            'learningplanid' => (int)$params['learningplanid'],
            'corecourseid'   => (int)$params['corecourseid'],
            'status'         => $status,
        ];

        $total = class_query_manager::count_filtered($filters);

        $ids = [];
        if ($total > 0) {
            $ids = class_query_manager::fetch_page($filters, $page, $perpage, $sort, $dir);
        }

        // Reuse the existing list_classes() bulk-prefetch so the response
        // carries all the enriched fields the template already expects.
        // list_classes() calls $DB->get_records('gmk_class', $filters) and
        // Moodle's get_records() does NOT accept an array as a filter
        // value (it tries to real_escape_string() it), so we cannot pass
        // ['id' => $ids] directly. Instead we pull the class rows via a
        // proper IN-clause and pass each id individually to list_classes()
        // — list_classes() short-circuits for an id-keyed filter anyway.
        $items = [];
        if (!empty($ids)) {
            $byId = [];
            foreach ($ids as $cid) {
                $one = list_classes(['id' => (int)$cid]);
                if (!empty($one)) {
                    $row = reset($one);
                    $byId[(int)$row->id] = $row;
                }
            }
            foreach ($ids as $cid) {
                if (isset($byId[$cid])) {
                    $items[] = self::format_row($byId[$cid]);
                }
            }
        }

        // Facets for the filter bar (independent of the current filter
        // for status/period/course — they always reflect the universe).
        $facets = class_query_manager::list_class_facets();

        $totalpages = ($perpage > 0 && $total > 0) ? (int)ceil($total / $perpage) : 0;

        return [
            'items'      => $items,
            'total'      => $total,
            'page'       => $page,
            'perpage'    => $perpage,
            'totalpages' => $totalpages,
            'facets'     => $facets,
        ];
    }

    /**
     * Convert the enriched gmk_class object from list_classes() into the
     * flat array we expose through the WS. We keep this list aligned with
     * the keys used by the template (templates/class_management.mustache).
     */
    private static function format_row(stdClass $c): array {
        return [
            'id'                    => (int)$c->id,
            'name'                  => (string)($c->name ?? ''),
            'type'                  => isset($c->type) ? (int)$c->type : 0,
            'typelabel'             => (string)($c->typelabel ?? ''),
            'icon'                  => (string)($c->icon ?? ''),
            'closed'                => isset($c->closed) ? (int)$c->closed : 0,
            'instructorid'          => (int)($c->instructorid ?? 0),
            'instructorName'        => (string)($c->instructorName ?? ''),
            'instructorProfileImage'=> (string)($c->instructorProfileImage ?? ''),
            'periodid'              => (int)($c->periodid ?? 0),
            'periodName'            => (string)($c->periodName ?? ''),
            'learningplanid'        => (int)($c->learningplanid ?? 0),
            'learningPlanName'      => (string)($c->learningPlanName ?? ''),
            'corecourseid'          => (int)($c->corecourseid ?? 0),
            'coreCourseName'        => (string)($c->coreCourseName ?? ''),
            'classroomid'           => (int)($c->classroomid ?? 0),
            'classroomName'         => (string)($c->classroomName ?? ''),
            'classdays'             => (string)($c->classdays ?? ''),
            'classDaysString'       => (string)($c->classDaysString ?? ''),
            'inithourformatted'     => (string)($c->inithourformatted ?? ''),
            'endhourformatted'      => (string)($c->endhourformatted ?? ''),
            'inittimets'            => (int)($c->inittimets ?? 0),
            'endtimets'             => (int)($c->endtimets ?? 0),
            'initdate'              => (int)($c->initdate ?? 0),
            'enddate'               => (int)($c->enddate ?? 0),
            'startDate'             => (string)($c->startDate ?? ''),
            'timecreated'           => (int)($c->timecreated ?? 0),
            'timemodified'          => (int)($c->timemodified ?? 0),
            'enroledStudents'       => (int)($c->enroledStudents ?? 0),
            'preRegisteredStudents' => (int)($c->preRegisteredStudents ?? 0),
            'queuedStudents'        => (int)($c->queuedStudents ?? 0),
            'shift'                 => (string)($c->shift ?? ''),
            'shiftvalue'            => (string)($c->shiftvalue ?? ''),
            'shiftdisplay'          => (string)($c->shiftdisplay ?? ''),
            'groupurl'              => (string)($c->groupurl ?? ''),
            'coursesectionid'       => (int)($c->coursesectionid ?? 0),
        ];
    }
}
