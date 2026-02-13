<?php
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/planning_manager.php');

use local_grupomakro_core\local\planning_manager;

// Set a period ID that exists (User mentioned period 1 in logs)
$periodId = 1;

echo "Fetching demand data for period $periodId...\n";

try {
    $data = planning_manager::get_demand_data($periodId);
    
    $students = $data['student_list'];
    echo "Total Students: " . count($students) . "\n";
    
    if (count($students) > 0) {
        echo "Sample Student (First 3):\n";
        print_r(array_slice($students, 0, 3));
        
        // specific check for one of the IDs in the log
        $targetId = '2025-II-00000023';
        echo "\nSearching for ID: $targetId\n";
        $found = false;
        foreach ($students as $s) {
            if ($s['id'] == $targetId) {
                echo "Found! Dump:\n";
                var_dump($s);
                $found = true;
                break;
            }
        }
        if (!$found) echo "Not found in student_list!\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
