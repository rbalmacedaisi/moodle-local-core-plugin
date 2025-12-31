<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

global $DB;

$username = 'q10@isi.edu.pa'; // Using a known teacher username from memory
$user = $DB->get_record('user', ['username' => $username]);

if (!$user) {
    die("User $username not found\n");
}

echo "Checking user: " . $user->username . " (ID: " . $user->id . ")\n";

$is_teacher = $DB->record_exists('gmk_class', ['instructorid' => $user->id, 'closed' => 0]);

if ($is_teacher) {
    echo "Status: TEACHER\n";
    $classes = $DB->get_records('gmk_class', ['instructorid' => $user->id, 'closed' => 0]);
    foreach ($classes as $class) {
        echo "- Class: " . $class->name . " (ID: " . $class->id . ")\n";
    }
} else {
    echo "Status: NOT A TEACHER in gmk_class table\n";
}
