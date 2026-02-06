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
        echo "<h2>$class Methods Details:</h2><ul>";
        foreach ($ref->getMethods() as $method) {
            if ($method->isPublic()) {
                if ($method->getName() === 'save_questions_usage_by_activity' || $method->getName() === 'get_question_usage') {
                    echo "<li><b>" . ($method->isStatic() ? 'static ' : '') . $method->getName() . "</b>(";
                    $params = [];
                    foreach ($method->getParameters() as $p) {
                        $params[] = '$' . $p->getName();
                    }
                    echo implode(', ', $params) . ")</li>";
                }
            }
        }
        echo "</ul>";
    }
}
