<?php
require(__DIR__ . '/../../config.php');

echo "<h1>Moodle 4.0 API Check</h1>";

if (class_exists('\mod_quiz\structure')) {
    echo "<p style='color:green;'>Class \mod_quiz\structure FOUND</p>";
    $methods = get_class_methods('\mod_quiz\structure');
    echo "<ul>";
    foreach ($methods as $m) {
        echo "<li>$m</li>";
    }
    echo "</ul>";
} else {
    echo "<p style='color:red;'>Class \mod_quiz\structure NOT FOUND</p>";
}
