<?php
require_once(__DIR__ . '/../../config.php');
global $DB;

require_login();
require_capability('moodle/site:config', context_system::instance());

echo "<h2>Fixing Class 125 Mapping</h2>";

$classid = 125;
$correct_courseid = 77;

$class = $DB->get_record('gmk_class', ['id' => $classid]);

if ($class) {
    echo "Current Class Data:<br>";
    echo "Name: {$class->name}<br>";
    echo "Old Course ID: {$class->courseid}<br>";
    echo "Correct Course ID: $correct_courseid (CoreCourseID: {$class->corecourseid})<br>";
    
    if ($class->courseid != $correct_courseid) {
        $DB->set_field('gmk_class', 'courseid', $correct_courseid, ['id' => $classid]);
        echo "<b style='color:green;'>SUCCESS: Course ID updated to $correct_courseid.</b><br>";
    } else {
        echo "<b>Course ID is already correct.</b><br>";
    }
} else {
    echo "<b style='color:red;'>Class $classid not found.</b><br>";
}

echo "<br><a href='diag_grading.php?classid=125'>Return to Diagnostic</a>";
