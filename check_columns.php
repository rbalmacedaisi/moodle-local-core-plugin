<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$columns = $DB->get_columns('gmk_class');
echo "Columns in gmk_class:\n";
foreach ($columns as $col) {
    if (in_array($col->name, ['initdate', 'enddate'])) {
        echo " - " . $col->name . " (Type: " . $col->type . ")\n";
    }
}

if (!isset($columns['initdate'])) echo "MISSING: initdate\n";
if (!isset($columns['enddate'])) echo "MISSING: enddate\n";
