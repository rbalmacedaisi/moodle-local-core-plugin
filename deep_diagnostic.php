<?php
/**
 * Diagnostic Tool to inspect Moodle Drag and Drop save logic.
 */

define('AJAX_SCRIPT', true);

// Same path discovery logic as ajax.php
$config_path = __DIR__ . '/../../config.php';
if (!file_exists($config_path)) {
    $config_path = __DIR__ . '/../../../config.php';
}

if (!file_exists($config_path)) {
    die("Error: config.php not found at $config_path. Please move this script to your Moodle module directory.");
}

require_once($config_path);
require_once($CFG->libdir . '/questionlib.php');

header('Content-Type: text/plain; charset=utf-8');

echo "--- MOODLE D&D DIAGNOSTIC ---\n\n";

try {
    $qtypes = ['ddimageortext', 'ddmarker'];

    foreach ($qtypes as $type) {
        echo "=== Question Type: $type ===\n";
        $qtypeobj = question_bank::get_qtype($type);
        $reflection = new ReflectionClass($qtypeobj);
        
        // Find save_question_options
        try {
            $method = $reflection->getMethod('save_question_options');
            echo "Method: save_question_options\n";
            echo "File: " . $method->getFileName() . "\n";
            echo "Lines: " . $method->getStartLine() . " to " . $method->getEndLine() . "\n\n";
            
            $content = file($method->getFileName());
            for ($i = $method->getStartLine() - 1; $i < $method->getEndLine(); $i++) {
                echo ($i + 1) . ": " . $content[$i];
            }
            echo "\n\n";
        } catch (ReflectionException $e) {
            echo "Method save_question_options not found in " . get_class($qtypeobj) . "\n";
            // Check parent class
            $parent = $reflection->getParentClass();
            if ($parent) {
                echo "Checking parent: " . $parent->getName() . "\n";
                $method = $parent->getMethod('save_question_options');
                echo "Method: save_question_options (inherited)\n";
                echo "File: " . $method->getFileName() . "\n";
                echo "Lines: " . $method->getStartLine() . " to " . $method->getEndLine() . "\n\n";
                
                $content = file($method->getFileName());
                for ($i = $method->getStartLine() - 1; $i < $method->getEndLine(); $i++) {
                    echo ($i + 1) . ": " . $content[$i];
                }
                echo "\n\n";
            }
        }
    }

    // Also inspect the table structure via $DB
    echo "=== Database Table Inspection ===\n";
    $tables = ['qtype_ddimageortext_drops', 'qtype_ddmarker_drops'];
    foreach ($tables as $table) {
        echo "\nTable: $table\n";
        $columns = $DB->get_columns($table);
        foreach ($columns as $column) {
            echo sprintf("- %-15s Type: %-15s NotNull: %d Default: %s\n", 
                $column->name, $column->type, $column->not_null, $column->default_value);
        }
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n" . $e->getTraceAsString();
}
