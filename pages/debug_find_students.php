<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$classid = 125;
echo "<pre>";
echo "Diagnosing Class ID: $classid\n";

$class = $DB->get_record('gmk_class', ['id' => $classid]);
if (!$class) {
    die("Class not found");
}

print_r($class);

echo "\nChecking gmk_class_queue:\n";
$queue = $DB->get_records('gmk_class_queue', ['classid' => $classid]);
echo "Count: " . count($queue) . "\n";
print_r($queue);

echo "\nChecking Moodle Course Enrolments (if corecourseid exists):\n";
if (!empty($class->corecourseid)) {
    $course = $DB->get_record('course', ['id' => $class->corecourseid]);
    if ($course) {
        $enrolledCount = $DB->count_records_sql("
            SELECT COUNT(DISTINCT ue.userid)
            FROM {user_enrolments} ue
            JOIN {enrol} e ON e.id = ue.enrolid
            WHERE e.courseid = ?", [$course->id]);
        echo "Enrolled in Moodle Course ($course->fullname): $enrolledCount\n";
    } else {
        echo "Moodle Course ID {$class->corecourseid} not found\n";
    }
} else {
    echo "No corecourseid defined\n";
}

echo "\nChecking gmk_course_progre (for matriculated/ongoing status):\n";
// Sometimes classes are linked to subjects, and students have progress in those subjects
// But gmk_class is usually a specific instance.
// Let's try to find if any matriculated users are linked to this class in other tables.

$tables = $DB->get_tables();
foreach ($tables as $table) {
    if (strpos($table, 'gmk_') === 0) {
        $columns = $DB->get_columns($table);
        if (isset($columns['classid'])) {
            $count = $DB->count_records($table, ['classid' => $classid]);
            if ($count > 0) {
                echo "Found $count records in table: $table\n";
            }
        }
    }
}
echo "</pre>";
