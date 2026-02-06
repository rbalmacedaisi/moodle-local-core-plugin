<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

require_login();
$systemcontext = context_system::instance();
require_capability('moodle/site:config', $systemcontext);

$attemptid = optional_param('attemptid', 0, PARAM_INT);

echo $OUTPUT->header();

if (!$attemptid) {
    echo "No attempt ID provided.";
} else {
    try {
        require_once($CFG->dirroot . '/question/engine/lib.php');
        $attemptobj = \quiz_attempt::create($attemptid);
        
        echo "<h3>Class Name:</h3>";
        echo get_class($attemptobj);
        
        echo "<h3>Available Methods:</h3>";
        $methods = get_class_methods($attemptobj);
        sort($methods);
        echo "<ul>";
        foreach ($methods as $method) {
            echo "<li>$method</li>";
        }
        echo "</ul>";
        
        echo "<h3>Attempt Data:</h3>";
        echo "<pre>";
        print_r($attemptobj->get_attempt());
        echo "</pre>";

    } catch (Exception $e) {
        echo "Error: " . $e->getMessage();
    }
}

echo $OUTPUT->footer();
