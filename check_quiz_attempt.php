<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

foreach (['quiz_attempt', 'question_engine'] as $class) {
    if (!class_exists($class)) {
        if ($class === 'question_engine') {
            require_once($CFG->dirroot . '/question/engine/lib.php');
        }
    }
    if (class_exists($class)) {
        $ref = new ReflectionClass($class);
        echo "<h2>$class Methods:</h2><ul>";
        foreach ($ref->getMethods() as $method) {
            if ($method->isPublic()) {
                echo "<li>" . ($method->isStatic() ? 'static ' : '') . $method->getName() . "</li>";
            }
        }
        echo "</ul>";
    } else {
        echo "<h2>$class NOT FOUND</h2>";
    }
}
