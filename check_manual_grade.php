<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/question/engine/lib.php');

$ref = new ReflectionMethod('question_attempt', 'manual_grade');
echo "manual_grade Parameters:<ul>";
foreach ($ref->getParameters() as $p) {
    echo "<li>{$p->getName()}</li>";
}
echo "</ul>";
