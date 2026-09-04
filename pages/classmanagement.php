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
 * Server-side paginated class management page.
 *
 * Renders the shell + the FIRST page of classes so the first paint has
 * content without waiting for the JS module to fetch. Subsequent pages,
 * search, filters and sort changes are handled by the AMD module
 * local_grupomakro_core/class_management_filters via the WS
 * local_grupomakro_list_classes_paged.
 *
 * The original update_shift and update_class_academic_period POST actions
 * are preserved unchanged.
 *
 * @package    local_grupomakro_core
 * @copyright  2022 Solutto Consulting <dev@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once $CFG->dirroot. '/local/grupomakro_core/locallib.php';
require_once $CFG->dirroot. '/local/grupomakro_core/classes/local/class_query_manager.php';
$plugin_name = 'local_grupomakro_core';

require_login();
require_capability('local/grupomakro_core:view_classmanagement', context_system::instance());

$PAGE->set_url($CFG->wwwroot . '/local/grupomakro_core/pages/classmanagement.php');

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_title(get_string('class_management', $plugin_name));
$PAGE->set_heading(get_string('class_management', $plugin_name));
$PAGE->set_pagelayout('base');

$shiftfeedback = '';
$shifterror = '';
$periodfeedback = '';
$periormerror = '';

// ---- Original POST actions (preserved) -----------------------------------
$action = optional_param('action', '', PARAM_ALPHANUMEXT);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update_shift') {
    require_sesskey();

    $classid = required_param('classid', PARAM_INT);
    $rawshift = optional_param('shift', '', PARAM_RAW_TRIMMED);
    $newshift = trim((string)$rawshift);
    if ($newshift === '' || core_text::strtolower($newshift) === 'null') {
        $newshift = null;
    } else {
        $newshift = core_text::substr($newshift, 0, 50);
    }

    $class = $DB->get_record('gmk_class', ['id' => $classid], 'id, name', IGNORE_MISSING);
    if (!$class) {
        $shifterror = 'Clase no encontrada.';
    } else {
        $record = new stdClass();
        $record->id = (int)$classid;
        $record->shift = $newshift;
        $record->timemodified = time();
        $record->usermodified = (int)$USER->id;
        $DB->update_record('gmk_class', $record);
        $shiftfeedback = 'Jornada actualizada.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update_class_academic_period') {
    require_sesskey();

    $classid = required_param('classid', PARAM_INT);
    $newapid = required_param('academicperiodid', PARAM_INT);

    $class = $DB->get_record('gmk_class', ['id' => $classid], '*', IGNORE_MISSING);
    $newap = $DB->get_record('gmk_academic_periods', ['id' => $newapid], '*', IGNORE_MISSING);
    if (!$class) {
        $periormerror = 'Clase no encontrada.';
    } else if (!$newap) {
        $periormerror = 'Periodo lectivo no válido.';
    } else {
        $participants = get_class_participants($class);
        $userids = array_values(array_unique(array_filter(array_map(
            fn($s) => (int)($s->userid ?? 0),
            (array)$participants->enroledStudents
        ))));
        if (empty($userids)) {
            $periormerror = 'La clase no tiene estudiantes matriculados.';
        } else {
            list($insql, $inparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
            $DB->execute(
                "UPDATE {local_learning_users}
                    SET academicperiodid = :apid,
                        timemodified = :now,
                        usermodified = :userid
                  WHERE userid $insql",
                array_merge($inparams, [
                    'apid'  => (int)$newapid,
                    'now'   => time(),
                    'userid' => (int)$USER->id,
                ])
            );
            $periodfeedback = 'Periodo lectivo actualizado para ' . count($userids) . ' estudiante(s).';
        }
    }
}

// ---- Filter parsing (URL query string) ----------------------------------
$search         = trim((string)optional_param('search', '', PARAM_TEXT));
$periodid       = max(0, (int)optional_param('periodid', 0, PARAM_INT));
$learningplanid = max(0, (int)optional_param('learningplanid', 0, PARAM_INT));
$corecourseid   = max(0, (int)optional_param('corecourseid', 0, PARAM_INT));
$statusParam    = (string)optional_param('status', 'active', PARAM_ALPHA);
$status         = in_array($statusParam, ['active', 'closed', 'all'], true) ? $statusParam : 'active';
$sortParam      = (string)optional_param('sort', 'timecreated', PARAM_ALPHA);
$sortKeys       = array_keys(\local_grupomakro_core\local\class_query_manager::SORT_COLUMNS);
$sort           = in_array($sortParam, $sortKeys, true) ? $sortParam : 'timecreated';
$dirParam       = (string)optional_param('dir', 'DESC', PARAM_ALPHA);
$dir            = (strtoupper($dirParam) === 'ASC') ? 'ASC' : 'DESC';
$page           = max(0, (int)optional_param('page', 0, PARAM_INT));
$perpageParam   = (int)optional_param('perpage', 25, PARAM_INT);
$perpage        = max(1, min(200, $perpageParam));

$filters = [
    'search'         => $search,
    'periodid'       => $periodid,
    'learningplanid' => $learningplanid,
    'corecourseid'   => $corecourseid,
    'status'         => $status,
];

// ---- Facets (dropdowns only show options with at least one class) --------
$facets = \local_grupomakro_core\local\class_query_manager::list_class_facets();

// ---- Server-side render of the first page -------------------------------
// Keeps the first paint with content (no flash of empty state) and also
// means that a direct link to ?page=3&perpage=50&status=closed works
// without JS — the page is fully usable even with JS disabled.
$total      = \local_grupomakro_core\local\class_query_manager::count_filtered($filters);
$totalpages = ($perpage > 0 && $total > 0) ? (int)ceil($total / $perpage) : 0;
if ($page >= $totalpages && $totalpages > 0) {
    // Clamp so the URL never lands on a non-existent page.
    $page = $totalpages - 1;
}

$classIds = [];
if ($total > 0) {
    $classIds = \local_grupomakro_core\local\class_query_manager::fetch_page(
        $filters, $page, $perpage, $sort, $dir
    );
}

// Reuse the existing bulk-prefetch enrichment (see version 20260807000).
// list_classes() calls $DB->get_records('gmk_class', $filters) and that
// helper does NOT accept an array as a filter value (it tries to
// real_escape_string the array, which throws a warning and yields
// nothing). We must call list_classes() once per id, or run a single
// direct query here. Per-id is fine for the 25-row pages we render.
$classes = [];
if (!empty($classIds)) {
    foreach ($classIds as $cid) {
        $one = list_classes(['id' => (int)$cid]);
        if (!empty($one)) {
            $classes[] = reset($one);
        }
    }
}

// Section lookup (preserves the original groupurl behaviour).
$sectionNumberById = [];
$sectionIds = array_values(array_unique(array_filter(array_map(
    fn($c) => (int)($c->coursesectionid ?? 0),
    $classes
))));
if (!empty($sectionIds)) {
    list($insql, $inparams) = $DB->get_in_or_equal($sectionIds, SQL_PARAMS_NAMED, 'sid');
    $sectionNumberById = $DB->get_records_sql_menu(
        "SELECT id, section FROM {course_sections} WHERE id $insql",
        $inparams
    );
}

$courseCache = [];
$closedBadgeText = get_string('classmgmt:closed_badge', $plugin_name);
foreach ($classes as &$class) {
    $shiftvalue = isset($class->shift) ? trim((string)$class->shift) : '';
    $class->shiftvalue = $shiftvalue;
    $class->shiftdisplay = ($shiftvalue !== '') ? $shiftvalue : 'Sin jornada';
    $cid = isset($class->corecourseid) ? (int)$class->corecourseid : 0;
    $sid = isset($class->coursesectionid) ? (int)$class->coursesectionid : 0;
    if ($cid > 0 && $sid > 0 && isset($sectionNumberById[$sid])) {
        if (!isset($courseCache[$cid])) {
            $courseCache[$cid] = $DB->get_record('course', ['id' => $cid], '*', IGNORE_MISSING);
        }
        $course = $courseCache[$cid];
        $class->groupurl = $course
            ? course_get_url($course, $sectionNumberById[$sid])->out()
            : '';
    } else {
        $class->groupurl = '';
    }
    $class->closedbadge = !empty($class->closed) ? $closedBadgeText : '';
}
unset($class);

echo $OUTPUT->header();

// Academic periods available to assign to the class's students
// (for the "Update academic period" modal).
$academicPeriods = array_values(array_map(
    fn($p) => ['id' => (int)$p->id, 'name' => $p->name],
    $DB->get_records('gmk_academic_periods', null, 'name DESC', 'id, name')
));

$fromItem = ($total === 0) ? 0 : ($page * $perpage) + 1;
$toItem   = ($total === 0) ? 0 : min($total, $page * $perpage + count($classes));

// Pre-render the results count label so the mustache template can show it
// without needing object interpolation (which Moodle's mustache does not
// support out of the box).
$resultsCountLabel = '';
if ($total > 0) {
    $resultsCountLabel = get_string('classmgmt:results_count', $plugin_name, (object)[
        'from'  => $fromItem,
        'to'    => $toItem,
        'total' => $total,
    ]);
} else {
    $resultsCountLabel = get_string('classmgmt:no_results_for_filters', $plugin_name);
}

// Pre-render the paginator so the next/prev/first/last buttons are
// visible on the first paint (before any JS interaction).
$paginatorHtml = \local_grupomakro_core\local\class_query_manager::build_paginator_html($total, $totalpages, $page);

$templatedata = [
    'createurl' => $CFG->wwwroot.'/local/grupomakro_core/pages/createcontract.php',
    'url' => $CFG->wwwroot.'/local/grupomakro_core/pages/contractmanagement.php',
    'createclass_url' => $CFG->wwwroot . '/local/grupomakro_core/pages/createclass.php',
    'sesskey' => sesskey(),
    'shiftfeedback' => $shiftfeedback,
    'shifterror' => $shifterror,
    'periodfeedback' => $periodfeedback,
    'periormerror' => $periormerror,
    'academicPeriods' => $academicPeriods,

    // Paginated view state.
    'search'         => $search,
    'periodid'       => $periodid,
    'learningplanid' => $learningplanid,
    'corecourseid'   => $corecourseid,
    'status'         => $status,
    'sort'           => $sort,
    'dir'            => $dir,
    'page'           => $page,
    'perpage'        => $perpage,
    'total'          => $total,
    'totalpages'     => $totalpages,
    'hasResults'     => $total > 0,
    'fromItem'       => $fromItem,
    'toItem'         => $toItem,
    'resultsCountLabel' => $resultsCountLabel,
    'paginatorHtml'     => $paginatorHtml,
    'facetPeriods'   => $facets['periods'],
    'facetCourses'   => $facets['courses'],

    // Status radios pre-selection.
    'statusactivechecked' => ($status === 'active') ? 'checked' : '',
    'statusclosedchecked' => ($status === 'closed') ? 'checked' : '',
    'statusallchecked'    => ($status === 'all')    ? 'checked' : '',

    // First-page rows (server-side render).
    'allClasses' => $classes,
];

echo $OUTPUT->render_from_template('local_grupomakro_core/class_management', $templatedata);

// view toggle + bulk delete (unchanged).
$PAGE->requires->js_call_amd('local_grupomakro_core/delete_class', 'init', []);

// Server-side paginated filters + search + pagination.
$PAGE->requires->js_call_amd('local_grupomakro_core/class_management_filters', 'init', [[
    'sesskey'         => sesskey(),
    'search'          => $search,
    'periodid'        => $periodid,
    'learningplanid'  => $learningplanid,
    'corecourseid'    => $corecourseid,
    'status'          => $status,
    'sort'            => $sort,
    'dir'             => $dir,
    'page'            => $page,
    'perpage'         => $perpage,
]]);

echo $OUTPUT->footer();
