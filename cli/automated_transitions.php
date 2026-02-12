<?php
define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/progress_manager.php');

// Simple log file
$logDir = $CFG->dataroot . '/local_grupomakro_core/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0777, true);
}
$logFile = $logDir . '/automated_transitions_' . date('Y-m-d') . '.log';

mtrace("Starting automated institutional period transitions...");
file_put_contents($logFile, "--- Run started at " . date('Y-m-d H:i:s') . " ---\n", FILE_APPEND);

try {
    mtrace("Handling institutional period transitions...");
    \local_grupomakro_progress_manager::handle_institutional_period_transition($logFile);
    
    mtrace("Handling sub-period (Bloque) transitions...");
    \local_grupomakro_progress_manager::handle_subperiod_transition($logFile);
    
    mtrace("Done! Check log at: $logFile");
} catch (Exception $e) {
    mtrace("Error: " . $e->getMessage());
    file_put_contents($logFile, "[ERROR] " . $e->getMessage() . "\n", FILE_APPEND);
}

file_put_contents($logFile, "--- Run finished ---\n\n", FILE_APPEND);
