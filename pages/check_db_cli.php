<?php
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');

$tables = ['question_ddmarker', 'question_ddimageortext', 'question_ddmarker_drops', 'question_ddimageortext_drops', 'question_ddmarker_drags', 'question_ddimageortext_drags'];

foreach ($tables as $table) {
    echo "Table: {$table}\n";
    if ($DB->get_manager()->table_exists($table)) {
        $columns = $DB->get_columns($table);
        printf("%-30s | %-15s | %-10s | %-10s | %-10s\n", "Column", "Type", "Length", "Not Null", "Default");
        echo str_repeat("-", 85) . "\n";
        foreach ($columns as $column) {
            printf("%-30s | %-15s | %-10d | %-10s | %-10s\n", 
                $column->name, 
                $column->type, 
                $column->max_length, 
                $column->not_null ? 'Yes' : 'No', 
                $column->default_value !== null ? $column->default_value : 'NULL'
            );
        }
    } else {
        echo "Table does not exist.\n";
    }
    echo "\n";
}
