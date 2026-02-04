<?php
require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

$functions = get_defined_functions();
$quiz_functions = array_filter($functions['user'], function($f) {
    return strpos($f, 'quiz_') === 0;
});

echo "<h1>Quiz Functions Found:</h1>";
echo "<ul>";
foreach ($quiz_functions as $f) {
    echo "<li>$f</li>";
}
echo "</ul>";

if (function_exists('quiz_remove_slot')) {
    echo "<p style='color:green;'>quiz_remove_slot EXISTS</p>";
} else {
    echo "<p style='color:red;'>quiz_remove_slot DOES NOT EXIST</p>";
}
