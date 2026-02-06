<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

echo "<h1>Grading Functions Check</h1>";

$functions = ['quiz_process_grading_input', 'quiz_save_best_grade'];
foreach ($functions as $f) {
    if (function_exists($f)) {
        echo "<p style='color:green;'>Function <b>$f</b> exists.</p>";
        $ref = new ReflectionFunction($f);
        echo "Parameters:<ul>";
        foreach ($ref->getParameters() as $p) {
            echo "<li>{$p->getName()}</li>";
        }
        echo "</ul>";
    } else {
        echo "<p style='color:red;'>Function <b>$f</b> NOT found.</p>";
    }
}
