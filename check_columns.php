<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');

$table = 'qtype_ddimageortext_drops';
$columns = $DB->get_columns($table);

echo "Columns for $table:\n";
foreach ($columns as $name => $col) {
    echo "- $name (Type: {$col->type}, Not Null: " . ($col->not_null ? 'Yes' : 'No') . ")\n";
}
