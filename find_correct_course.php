<?php
require_once(__DIR__ . '/../../config.php');
global $DB;

echo "<h2>Searching for the Correct Course</h2>";

// 1. Search for Quiz from screenshot
echo "<h3>1. Quizzes matching 'Cuestionario de prueba 1'</h3>";
$quizzes = $DB->get_records_sql("
    SELECT q.id, q.name, q.course as courseid, c.fullname as coursename, c.shortname
    FROM {quiz} q
    JOIN {course} c ON c.id = q.course
    WHERE q.name LIKE '%Cuestionario de prueba 1%'
");

if ($quizzes) {
    echo "<ul>";
    foreach ($quizzes as $qz) {
        $subs = $DB->count_records('quiz_attempts', ['quiz' => $qz->id]);
        echo "<li>Quiz ID: {$qz->id} - Name: {$qz->name} - <b>Target Course ID: {$qz->courseid}</b> ({$qz->coursename}) - Attempts: $subs</li>";
    }
    echo "</ul>";
} else {
    echo "<p>No quizzes found with that name.</p>";
}

// 2. Search for Course matching 'INGLÉS II'
echo "<h3>2. Courses matching 'INGLÉS II'</h3>";
$courses = $DB->get_records_sql("SELECT id, fullname, shortname FROM {course} WHERE fullname LIKE '%INGLÉS II%' OR shortname LIKE '%INGLÉS II%'");
if ($courses) {
    echo "<ul>";
    foreach ($courses as $co) {
        $class_count = $DB->count_records('gmk_class', ['courseid' => $co->id]);
        echo "<li>Course ID: {$co->id} - Fullname: {$co->fullname} - Shortname: {$co->shortname} - (Linked Classes: $class_count)</li>";
    }
    echo "</ul>";
} else {
    echo "<p>No courses found matching 'INGLÉS II'.</p>";
}

// 3. Search for Group from diagnostic
echo "<h3>3. Group Analysis</h3>";
$groupid = 317;
$group = $DB->get_record('groups', ['id' => $groupid]);
if ($group) {
    echo "Group 317 exists: Name: {$group->name}, <b>Actual Course ID: {$group->courseid}</b>";
} else {
    echo "Group 317 not found.";
}

// 4. Check Class 125 mapping
echo "<h3>4. Class 125 Raw Data</h3>";
$class = $DB->get_record('gmk_class', ['id' => 125]);
if ($class) {
    echo "<pre>";
    print_r($class);
    echo "</pre>";
} else {
    echo "Class 125 not found.";
}
