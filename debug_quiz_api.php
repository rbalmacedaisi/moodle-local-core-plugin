<?php
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/teacher/get_quiz_attempt_data.php');

$attemptid = $argv[1] ?? 0;

if (!$attemptid) {
    die("Usage: php debug_quiz_api.php <attemptid>\n");
}

try {
    echo "Testing get_quiz_attempt_data for attempt $attemptid...\n";
    
    // We need a user context for execute
    $user = $DB->get_record('user', ['username' => 'admin']); // or any teacher
    if (!$user) {
        $user = $DB->get_record('user', ['id' => 2]);
    }
    \core\session\manager::set_user($user);

    $result = \local_grupomakro_core\external\teacher\get_quiz_attempt_data::execute($attemptid);
    
    echo "Success!\n";
    echo "Quiz: " . $result->quizname . "\n";
    echo "Student: " . $result->username . "\n";
    echo "Questions: " . count($result->questions) . "\n";
    
    foreach ($result->questions as $q) {
        echo "- Slot {$q->slot}: {$q->name} [Needs Grading: " . ($q->needsgrading ? 'YES' : 'NO') . "]\n";
        // echo "  HTML Length: " . strlen($q->html) . "\n";
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
