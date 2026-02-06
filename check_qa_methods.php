<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/question/engine/lib.php');

echo "<h1>question_attempt Reflection</h1>";

foreach (['question_attempt', 'question_engine', 'question_usage_by_activity'] as $classname) {
    echo "<h2>Reflection for $classname</h2>";
    if (class_exists($classname)) {
        $ref = new ReflectionClass($classname);
        echo "<h3>Public Methods:</h3><ul style='columns: 3;'>";
        foreach ($ref->getMethods() as $method) {
            if ($method->isPublic()) {
                echo "<li>" . $method->getName() . "</li>";
            }
        }
        echo "</ul>";
    } else {
        echo "<p>Class $classname not found.</p>";
    }
}
