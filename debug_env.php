<?php
define('CLI_SCRIPT', false);
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

// Simple debug script to check environment
header('Content-Type: text/plain');

echo "GMK DEBUG ENVIRONMENT\n";
echo "====================\n\n";

global $DB;

// 1. Check Custom Fields
echo "CUSTOM FIELDS (user_info_field):\n";
$fields = $DB->get_records('user_info_field', [], '', 'id, shortname, name, datatype');
foreach ($fields as $f) {
    echo "- [{$f->id}] {$f->shortname}: {$f->name} ({$f->datatype})\n";
}
echo "\n";

// 2. Check Tables Existence
$tables_to_check = [
    'local_learning_users',
    'local_learning_plans',
    'local_learning_periods',
    'local_learning_subperiods',
    'gmk_academic_periods',
    'gmk_course_progre',
    'user_info_data'
];

echo "TABLE CHECKS:\n";
foreach ($tables_to_check as $table) {
    $exists = $DB->get_manager()->table_exists($table);
    echo "- Table '{$table}': " . ($exists ? "EXISTS" : "MISSING") . "\n";
    if ($exists) {
        $columns = $DB->get_columns($table);
        $col_names = array_keys($columns);
        echo "  Columns: " . implode(', ', $col_names) . "\n";
    }
}
echo "\n";

// 3. Sample Data
echo "SAMPLE ACADEMIC PERIODS:\n";
if ($DB->get_manager()->table_exists('gmk_academic_periods')) {
    $periods = $DB->get_records('gmk_academic_periods', [], 'id DESC', '*', 0, 5);
    foreach ($periods as $p) {
        echo "- [{$p->id}] {$p->name} (Status: {$p->status})\n";
    }
} else {
    echo "gmk_academic_periods table not found.\n";
}

echo "\nGMK DEBUG END";
