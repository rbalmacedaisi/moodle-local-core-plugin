<?php
/**
 * Debug Grading Logic
 */

// Try multiple levels up to find config.php
$config_path = __DIR__ . '/../../../config.php';
if (!file_exists($config_path)) {
    // Fallback or error
    die("Config file not found at " . $config_path);
}
require_once($config_path);
require_once($CFG->dirroot . '/mod/assign/locallib.php');

require_login();

// Default to current user or allow override if admin
$userid = optional_param('userid', $USER->id, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);

// Fix PAGE setup
$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_grading.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Debug Grading');
$PAGE->set_heading('Debug Grading Logic');

echo $OUTPUT->header();
echo "<h1>Debug Grading Logic</h1>";
echo "<p>User ID: $userid</p>";

// 1. Get Instructor's Classes
$sql_classes = "SELECT c.id, c.name, c.courseid, c.groupid 
                FROM {gmk_class} c 
                WHERE c.instructorid = :userid AND c.closed = 0";
$classes = $DB->get_records_sql($sql_classes, ['userid' => $userid]);

echo "<h2>Active Classes (" . count($classes) . ")</h2>";

foreach ($classes as $class) {
    if ($courseid && $class->courseid != $courseid) continue;

    echo "<hr>";
    echo "<h3>Class: {$class->name} (Course ID: {$class->courseid}, Group ID: {$class->groupid})</h3>";

    // 2. CHECK ALL MODULES IN COURSE
    echo "<h4>All Modules in Course {$class->courseid}:</h4>";
    
    $sql_mods = "SELECT cm.id, cm.instance, m.name as modname, cm.visible
                 FROM {course_modules} cm
                 JOIN {modules} m ON m.id = cm.module
                 WHERE cm.course = :courseid";
                 
    $mods = $DB->get_records_sql($sql_mods, ['courseid' => $class->courseid]);
    
    if (empty($mods)) {
        echo "<p style='color:red'>No modules found in course_modules table!</p>";
    } else {
        $counts = [];
        foreach ($mods as $m) {
            $counts[$m->modname] = ($counts[$m->modname] ?? 0) + 1;
        }
        echo "<ul>";
        foreach ($counts as $name => $count) {
            echo "<li>$name: $count</li>";
        }
        echo "</ul>";
    }

    // 3. Specifically Check Assignments table again
    $assigns = $DB->get_records('assign', ['course' => $class->courseid], 'duedate ASC');
    
    if (empty($assigns)) {
        echo "<p style='color:orange'>No records in 'assign' table for this course.</p>";
    } else {
        echo "<p style='color:green'>Found " . count($assigns) . " records in 'assign'. (Wait, previous run said 0?)</p>";
        
        echo "<ul>";
        foreach ($assigns as $assign) {
            echo "<li><strong>Assignment: {$assign->name}</strong> (ID: {$assign->id})</li>";
            // ... (rest of submission check same as before but simplified)
            
            $context = context_module::instance(get_coursemodule_from_instance('assign', $assign->id)->id);
            
            // Pending Count SQL
            $group_sql = "";
            $params = ['assignid' => $assign->id];
            
            if ($class->groupid > 0) {
                // IMPORTANT: Fixed parameter naming collision in previous version if any
                $group_sql = " AND EXISTS (
                    SELECT 1 FROM {groups_members} gm 
                    WHERE gm.userid = s.userid AND gm.groupid = :groupid
                )";
                $params['groupid'] = $class->groupid;
            }

            $sql_pending = "SELECT COUNT(s.id)
                            FROM {assign_submission} s 
                            LEFT JOIN {assign_grades} g ON g.assignment = s.assignment AND g.userid = s.userid
                            WHERE s.assignment = :assignid 
                              AND s.status = 'submitted' 
                              AND (g.grade IS NULL OR g.grade = -1)
                            $group_sql";
                            
            $count = $DB->count_records_sql($sql_pending, $params);
            echo " - Pending Submissions: $count <br>";
        }
        echo "</ul>";
    }
}

echo "<h2>Global Search Diagnosis</h2>";

// 1. Search for the specific assignment
$search_name = "Tarea de prueba%";
echo "<h3>Searching for assignments matching '$search_name'...</h3>";
$found_assigns = $DB->get_records_select('assign', "name LIKE :name", ['name' => $search_name]);

if ($found_assigns) {
    echo "<ul>";
    foreach ($found_assigns as $a) {
        $c = $DB->get_record('course', ['id' => $a->course]);
        echo "<li>FOUND: '{$a->name}' (ID: {$a->id}) in Course: '{$c->fullname}' (ID: {$c->id})</li>";
    }
    echo "</ul>";
} else {
    echo "<p>No assignments found matching that name.</p>";
}

// 2. Search for the course name
$search_course = "%INGLÉS II%";
echo "<h3>Searching for courses matching '$search_course'...</h3>";
$found_courses = $DB->get_records_select('course', "fullname LIKE :name", ['name' => $search_course]);

if ($found_courses) {
    echo "<ul>";
    foreach ($found_courses as $c) {
        $count = $DB->count_records('assign', ['course' => $c->id]);
        echo "<li>Course: '{$c->fullname}' (ID: {$c->id}) - Contains $count assignments.</li>";
    }
    echo "</ul>";
}

echo "<hr>";
echo $OUTPUT->footer();
