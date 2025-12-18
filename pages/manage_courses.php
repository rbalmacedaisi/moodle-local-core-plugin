<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/course/lib.php');

// Optional Autoload
if (file_exists($CFG->dirroot . '/vendor/autoload.php')) {
    require_once($CFG->dirroot . '/vendor/autoload.php');
}

// Permissions
admin_externalpage_setup('grupomakro_core_manage_courses');

$PAGE->set_url('/local/grupomakro_core/pages/manage_courses.php');
$PAGE->set_title('Gestión de Cursos Moderna');
$PAGE->set_heading('Gestor Avanzado de Cursos');
$PAGE->requires->jquery();

// Params
$filter_search = optional_param('search', '', PARAM_TEXT);
$filter_category = optional_param('category', 0, PARAM_INT);
$filter_visible = optional_param('visible', -1, PARAM_INT); // -1 all, 1 yes, 0 no
$filter_plan = optional_param('plan', 0, PARAM_INT);
$filter_schedule_status = optional_param('schedulestatus', -1, PARAM_INT); // -1 All, 1 Active, 0 Inactive
$action = optional_param('action', '', PARAM_ALPHA);
$page = optional_param('page', 0, PARAM_INT);
$perpage = 20;

// Context for SQL
$context = context_system::instance();

// Base SQL Construction
$where = ["c.id <> 1"]; // Exclude site course
$params = [];

// Explicitly build URL for pagination to ensure persistence
$pagingurl = new moodle_url('/local/grupomakro_core/pages/manage_courses.php');

if (!empty($filter_search)) {
    $where[] = "( " . $DB->sql_like('c.fullname', ':search1', false) . " OR " . $DB->sql_like('c.shortname', ':search2', false) . " OR " . $DB->sql_like('c.idnumber', ':search3', false) . " )";
    $params['search1'] = "%$filter_search%";
    $params['search2'] = "%$filter_search%";
    $params['search3'] = "%$filter_search%";
    $pagingurl->param('search', $filter_search);
}

if ($filter_category > 0) {
    $where[] = "c.category = :cat";
    $params['cat'] = $filter_category;
    $pagingurl->param('category', $filter_category);
}

if ($filter_visible !== -1) {
    $where[] = "c.visible = :vis";
    $params['vis'] = $filter_visible;
    $pagingurl->param('visible', $filter_visible);
}

// Filter by Learning Plan
if ($filter_plan > 0) {
    $where[] = "EXISTS (SELECT 1 FROM {local_learning_courses} llc WHERE llc.courseid = c.id AND llc.learningplanid = :planid)";
    $params['planid'] = $filter_plan;
    $pagingurl->param('plan', $filter_plan);
}

// Filter by Schedule Status
if ($filter_schedule_status !== -1) {
    if ($filter_schedule_status == 1) {
        $where[] = "EXISTS (SELECT 1 FROM {gmk_class} gc WHERE gc.courseid = c.id AND gc.closed = 0)";
    } else {
        $where[] = "NOT EXISTS (SELECT 1 FROM {gmk_class} gc WHERE gc.courseid = c.id AND gc.closed = 0)";
    }
    $pagingurl->param('schedulestatus', $filter_schedule_status);
}

// Set URL to page (good practice)
$PAGE->set_url($pagingurl);

$whereSQL = implode(" AND ", $where);

// Columns for SQL
$sql_cols = "c.id, c.fullname, c.shortname, c.idnumber, c.category, c.visible, c.startdate, c.enddate, 
            (SELECT COUNT(gc.id) FROM {gmk_class} gc WHERE gc.courseid = c.id) as total_schedules_count,
            (SELECT COUNT(gc.id) FROM {gmk_class} gc WHERE gc.courseid = c.id AND gc.closed = 0) as active_schedules_count,
            (SELECT COUNT(DISTINCT ra.userid) FROM {role_assignments} ra 
             JOIN {context} ctx ON ctx.id = ra.contextid 
             WHERE ctx.instanceid = c.id AND ctx.contextlevel = 50 AND ra.roleid = 5) as student_count"; 

echo $OUTPUT->header();

// Results
$total_matching = $DB->count_records_sql("SELECT COUNT(*) FROM {course} c WHERE $whereSQL", $params);
$courses = $DB->get_records_sql("SELECT $sql_cols FROM {course} c WHERE $whereSQL ORDER BY c.fullname ASC", $params, $page * $perpage, $perpage);

// Data for JS
$jsCourseData = [];

// Fetch Extra Data
if ($courses) {
    foreach ($courses as $c) {
        // Fetch Plans
        $c->plans = $DB->get_records_sql("
            SELECT lp.id, lp.name 
            FROM {local_learning_plans} lp
            JOIN {local_learning_courses} llc ON llc.learningplanid = lp.id
            WHERE llc.courseid = ?", [$c->id]);
        
        // Fetch All Schedules (closed included)
        // Added Try-Catch for Robustness against DB Schema Mismatch
        try {
            $c->active_schedules = $DB->get_records_sql("
                SELECT gc.id, gc.name, gc.inithourformatted, gc.endhourformatted, gc.classdays, gc.closed, gc.initdate, gc.enddate, gc.approved
                FROM {gmk_class} gc
                WHERE gc.courseid = ?
                ORDER BY gc.closed ASC, gc.id DESC", [$c->id]);
        } catch (\Exception $e) {
            error_log("GMK_DEBUG: Error fetching schedules for course " . $c->id . ". Likely missing columns. Error: " . $e->getMessage());
            // Fallback Query without dates
            $c->active_schedules = $DB->get_records_sql("
                SELECT gc.id, gc.name, gc.inithourformatted, gc.endhourformatted, gc.classdays, gc.closed, gc.approved
                FROM {gmk_class} gc
                WHERE gc.courseid = ?
                ORDER BY gc.closed ASC, gc.id DESC", [$c->id]);
        }
            
        // Add to JS Data
        $jsCourseData[$c->id] = [
            'plans' => array_values($c->plans),
            'schedules' => array_values($c->active_schedules)
        ];
    }
}

echo "<h1>GMK DEBUG: QUERIES OK</h1>";
// var_dump($jsCourseData); // Optional: Uncomment if needed

echo $OUTPUT->footer();
