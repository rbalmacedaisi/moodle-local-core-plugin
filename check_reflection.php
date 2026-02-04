<?php
require(__DIR__ . '/../../config.php');

echo "<h1>mod_quiz\structure Reflection</h1>";

if (class_exists('\mod_quiz\structure')) {
    $ref = new ReflectionClass('\mod_quiz\structure');
    $method = $ref->getMethod('create_for_quiz');
    
    echo "<h3>Method: create_for_quiz</h3>";
    echo "Parameters:<br><ul>";
    foreach ($method->getParameters() as $p) {
        $type = $p->hasType() ? $p->getType()->getName() : 'No Type';
        echo "<li>Name: {$p->getName()}, Type: $type</li>";
    }
    echo "</ul>";

    // Also look at populate_structure where the error happened
    if ($ref->hasMethod('populate_structure')) {
        $pop = $ref->getMethod('populate_structure');
        echo "<h3>Method: populate_structure</h3>";
        // This is usually protected, but we can see it in traceback
    }
}
