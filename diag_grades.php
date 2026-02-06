<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

// Simple diagnostic for grades issues.
$search = optional_param('search', 'Keilis', PARAM_TEXT);

echo "<h1>Diagnostic: Student Grades</h1>";
echo "<form><input type='text' name='search' value='$search'><input type='submit'></form>";

if (!$search) die();

global $DB;

$users = $DB->get_records_sql("SELECT id, firstname, lastname, email FROM {user} WHERE firstname LIKE ? OR lastname LIKE ? OR email LIKE ?", ["%$search%", "%$search%", "%$search%"]);

if (!$users) {
    echo "No users found for '$search'";
    die();
}

foreach ($users as $user) {
    echo "<h2>User: $user->firstname $user->lastname ($user->email) - ID: $user->id</h2>";
    
    // 1. Careers (Learning Plans)
    $careers = $DB->get_records_sql("
        SELECT lp.id, lp.name, lpu.id as relation_id, lpu.periodid as current_period
        FROM {local_learning_plans} lp
        JOIN {local_learning_users} lpu ON lpu.learningplanid = lp.id
        WHERE lpu.userid = ?
    ", [$user->id]);
    
    if (!$careers) {
        echo "<p style='color:red'>No careers associated in local_learning_users.</p>";
    } else {
        echo "<h3>Careers Associated:</h3><ul>";
        foreach ($careers as $lp) {
            echo "<li><strong>Plan ID $lp->id: $lp->name</strong> (Relation ID: $lp->relation_id, Current Period: $lp->current_period)</li>";
            
            // 2. Courses in the Pensum
            $courses = $DB->get_records('local_learning_courses', ['learningplanid' => $lp->id]);
            echo "<ul>";
            echo "<li>Courses in local_learning_courses for this plan: " . count($courses) . "</li>";
            
            // 3. Progress Records
            $progress = $DB->get_records('gmk_course_progre', ['userid' => $user->id, 'learningplanid' => $lp->id]);
            echo "<li>Progress records in gmk_course_progre: " . count($progress) . "</li>";
            
            // Detail courses
            if ($courses) {
                echo "<li>Details of first 5 courses:<ul>";
                $count = 0;
                foreach ($courses as $c) {
                    if ($count++ > 5) break;
                    $moodle_course = $DB->get_record('course', ['id' => $c->courseid], 'id, shortname, fullname');
                    $pName = $DB->get_record('local_learning_periods', ['id' => $c->periodid], 'id, name');
                    echo "<li>Course ID $c->courseid: " . ($moodle_course ? $moodle_course->fullname : "MISSING IN MOODLE") . " | Period: " . ($pName ? $pName->name : "MISSING PERIOD ID $c->periodid") . "</li>";
                }
                echo "</ul></li>";
            }
            echo "</ul>";
        }
        echo "</ul>";
    }
    echo "<hr>";
}
