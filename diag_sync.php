<?php
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/progress_manager.php');

global $DB;

echo "--- Diagnostic Period Sync ---\n";

// Get one student to test
$student = $DB->get_record_sql("SELECT userid, learningplanid FROM {local_learning_users} WHERE userroleid = 5 LIMIT 1");

if (!$student) {
    die("No students found in local_learning_users.\n");
}

echo "Testing for User ID: {$student->userid}, Plan ID: {$student->learningplanid}\n";

$logFile = make_temp_directory('grupomakro') . '/diag_sync.log';
@unlink($logFile);

$result = \local_grupomakro_progress_manager::sync_student_period_by_count($student->userid, $student->learningplanid, $logFile);

echo "Result: " . ($result ? "SUCCESS" : "FAILED/NO CHANGE") . "\n";

if (file_exists($logFile)) {
    echo "Log Output:\n";
    echo file_get_contents($logFile);
} else {
    echo "No log was generated.\n";
}
