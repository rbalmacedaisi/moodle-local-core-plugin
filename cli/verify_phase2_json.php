<?php
/**
 * Mimic exactly what ajax.php returns for local_grupomakro_get_attendance_sessions
 * so we can verify the exact JSON shape consumed by AttendancePanel.js.
 */
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/teacher/attendance_manager.php');

$classid = isset($argv[1]) ? (int)$argv[1] : 9606;
$result = \local_grupomakro_core\external\teacher\attendance_manager::get_sessions($classid);
if (!isset($result['status']) || $result['status'] !== 'success') {
    fwrite(STDERR, "ERROR: " . json_encode($result) . "\n");
    exit(1);
}

// Mimic the JSON shape that the AJAX handler returns.
// (It serializes the stdClass array as a JSON array of objects.)
$json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
echo $json . "\n";