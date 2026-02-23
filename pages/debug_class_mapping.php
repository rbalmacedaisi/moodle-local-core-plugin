<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_url('/local/grupomakro_core/pages/debug_class_mapping.php');
$PAGE->set_context($context);
$PAGE->set_title('Debug Class Mapping');

echo $OUTPUT->header();

$class_id = optional_param('class_id', 0, PARAM_INT);

if ($class_id) {
    $class = $DB->get_record('gmk_class', ['id' => $class_id]);
    if ($class) {
        echo "<h2>Class Details (DB Raw)</h2>";
        echo "<pre>" . print_r($class, true) . "</pre>";

        echo "<h2>Class Details (via list_classes)</h2>";
        $list = list_classes(['id' => $class_id]);
        echo "<pre>" . print_r($list[$class_id], true) . "</pre>";

        if (!empty($class->courseid)) {
            $subj = $DB->get_record('local_learning_courses', ['id' => $class->courseid]);
            echo "<h2>Learning Course Meta (local_learning_courses)</h2>";
            echo "<pre>" . print_r($subj, true) . "</pre>";
        }
        
        $sessions = $DB->get_records('gmk_class_schedules', ['classid' => $class_id]);
        echo "<h2>Schedules (gmk_class_schedules)</h2>";
        echo "<pre>" . print_r($sessions, true) . "</pre>";
    } else {
        echo "Class not found.";
    }
} else {
    echo "<h2>Recent Classes (Last 20)</h2>";
    $classes = $DB->get_records('gmk_class', [], 'id DESC', 'id, name, courseid, periodid', 0, 20);
    echo "<ul>";
    foreach ($classes as $c) {
        echo "<li><a href='?class_id={$c->id}'>ID: {$c->id} - {$c->name} (Subject ID: {$c->courseid}, Period Link: {$c->periodid})</a></li>";
    }
    echo "</ul>";
}

echo $OUTPUT->footer();
