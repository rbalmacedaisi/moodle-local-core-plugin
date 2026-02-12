<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/clilib.php');

$table = 'local_learning_users';
$columns = $DB->get_columns($table);

echo "Columns for table {$table}:\n";
foreach ($columns as $column) {
    echo "- " . $column->name . " (" . $column->type . ")\n";
}

echo "\nColumns for table local_learning_periods:\n";
$columnsP = $DB->get_columns('local_learning_periods');
foreach ($columnsP as $column) {
    echo "- " . $column->name . "\n";
}

echo "\nColumns for table gmk_academic_periods:\n";
$columnsA = $DB->get_columns('gmk_academic_periods');
foreach ($columnsA as $column) {
    echo "- " . $column->name . "\n";
}
