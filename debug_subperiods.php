<?php
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/clilib.php');

global $DB;

echo "--- Debugging Subperiods ---\n";

// Get first 3 plans
$plans = $DB->get_records('local_learning_plans', [], 'id ASC', 'id, name', 0, 3);

if (!$plans) {
    echo "No learning plans found.\n";
    exit;
}

foreach ($plans as $plan) {
    echo "Plan ID: {$plan->id} - {$plan->name}\n";
    
    // Query subperiods
    $sql = "SELECT sp.id, sp.name, sp.periodid, p.name as periodname
            FROM {local_learning_subperiods} sp
            JOIN {local_learning_periods} p ON p.id = sp.periodid
            WHERE p.learningplanid = ?
            ORDER BY p.position ASC, sp.position ASC";
            
    $subperiods = $DB->get_records_sql($sql, [$plan->id]);
    
    if (!$subperiods) {
        echo "  -> No subperiods found.\n";
    } else {
        echo "  -> Found " . count($subperiods) . " subperiods.\n";
        foreach ($subperiods as $sp) {
            echo "     - [{$sp->id}] {$sp->name} (Period: {$sp->periodname})\n";
        }
    }
    echo "\n";
}
