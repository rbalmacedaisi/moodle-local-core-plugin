<?php
/**
 * Fix Class Course Link
 * Updates the gmk_class table to point to the correct course ID (77)
 */

// Try multiple levels up to find config.php
$config_path = __DIR__ . '/../../../config.php';
if (!file_exists($config_path)) {
    die("Config file not found at " . $config_path);
}
require_once($config_path);

require_login();
require_capability('moodle/site:config', context_system::instance());

echo $OUTPUT->header();
echo "<h1>Fix Class Configuration</h1>";

$current_course_id = 106;
$correct_course_id = 77;
$class_name_pattern = '%INGLÉS II%'; // Filter to be safe

// 1. Find the class with the wrong course ID
echo "<h3>Finding class linked to Course $current_course_id...</h3>";

$class = $DB->get_record_select('gmk_class', "courseid = :cid AND name LIKE :name", 
    ['cid' => $current_course_id, 'name' => $class_name_pattern]);

if (!$class) {
    echo "<p style='color:red'>No class found with Course ID $current_course_id matching name '$class_name_pattern'.</p>";
} else {
    echo "<p>Found Class: <strong>{$class->name}</strong> (ID: {$class->id})</p>";
    echo "<p>Current Course ID: {$class->courseid}</p>";
    
    // 2. Update to correct course ID
    echo "<h3>Updating to Course ID $correct_course_id...</h3>";
    
    $class->courseid = $correct_course_id;
    try {
        $DB->update_record('gmk_class', $class);
        echo "<p style='color:green; font-weight:bold'>SUCCESS: Class updated to link to Course ID $correct_course_id.</p>";
        echo "<p>Please return to the <a href='teacher_dashboard.php'>Teacher Dashboard</a> and verify the data.</p>";
    } catch (Exception $e) {
        echo "<p style='color:red'>ERROR: " . $e->getMessage() . "</p>";
    }
}

echo $OUTPUT->footer();
