<?php
require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

echo "<h1>Namespace mod_quiz Check</h1>";

$classes = get_declared_classes();
$mod_quiz_classes = array_filter($classes, function($c) {
    return strpos($c, 'mod_quiz\\') === 0;
});

echo "<ul>";
foreach ($mod_quiz_classes as $c) {
    echo "<li>$c</li>";
}
echo "</ul>";

if (class_exists('quiz')) {
    echo "<p style='color:green;'>Global class 'quiz' FOUND</p>";
    $methods = get_class_methods('quiz');
    echo "<ul>";
    foreach ($methods as $m) {
        if (strpos($m, 'get_') === 0) echo "<li>$m</li>";
    }
    echo "</ul>";
}
