<?php
require_once(__DIR__ . '/../../config.php');
global $DB;

echo "<h2>Local Learning Courses Analysis</h2>";

$ids = [77, 106];
list($insql, $inparams) = $DB->get_in_or_equal($ids);

$records = $DB->get_records_sql("SELECT id, courseid FROM {local_learning_courses} WHERE id $insql", $inparams);

echo "<h3>Records in local_learning_courses:</h3>";
echo "<ul>";
foreach ($records as $r) {
    $course = $DB->get_record('course', ['id' => $r->courseid], 'id,fullname');
    echo "<li>ID: {$r->id} -> Moodle Course ID: {$r->courseid} (" . ($course ? $course->fullname : 'NOT FOUND') . ")</li>";
}
echo "</ul>";

echo "<h3>Classes using these IDs:</h3>";
foreach ($ids as $id) {
    $count = $DB->count_records('gmk_class', ['courseid' => $id]);
    echo "CourseID $id is used in $count classes.<br>";
}
