<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$table = 'local_learning_users';
$columns = $DB->get_columns($table);
echo "Columns in $table:\n";
foreach ($columns as $col) {
    echo " - " . $col->name . " (Type: " . $col->type . ")\n";
}

if (!isset($columns['currentsubperiodid'])) {
    echo "MISSING: currentsubperiodid\n";
} else {
    echo "FOUND: currentsubperiodid\n";
}
