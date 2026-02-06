<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/question/engine/lib.php');

echo "<h1>question_attempt Reflection</h1>";

if (class_exists('question_attempt')) {
    $ref = new ReflectionClass('question_attempt');
    
    echo "<h3>Constants:</h3><pre>";
    print_r($ref->getConstants());
    echo "</pre>";

    echo "<h3>Static Properties:</h3><pre>";
    print_r($ref->getStaticProperties());
    echo "</pre>";

    echo "<h3>Methods:</h3><ul>";
    foreach ($ref->getMethods() as $method) {
        if ($method->isPublic()) {
            echo "<li>" . $method->getName();
            if ($method->getName() === 'process_submitted_actions') {
                echo " (";
                foreach ($method->getParameters() as $p) {
                    echo " $" . $p->getName() . ",";
                }
                echo ")";
            }
            echo "</li>";
        }
    }
    echo "</ul>";
}
