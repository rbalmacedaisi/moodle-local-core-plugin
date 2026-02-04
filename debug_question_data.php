<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/questionlib.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$qid = optional_param('qid', 0, PARAM_INT);

echo "<html><head><title>Debug Question Data</title></head><body>";
echo "<h1>Diagnostic Tool - Question Data</h1>";

if ($qid === 0) {
    echo "<p>Please provide a question ID (?qid=XXX)</p>";
    // Look for recent ddwtos questions
    $recent = $DB->get_records('question', ['qtype' => 'ddwtos'], 'id DESC', 'id, name', 0, 5);
    if ($recent) {
        echo "<h3>Recent DDWTOS Questions:</h3><ul>";
        foreach ($recent as $r) {
            echo "<li>ID: {$r->id} - <a href='?qid={$r->id}'>{$r->name}</a></li>";
        }
        echo "</ul>";
    }
} else {
    try {
        $qdata = question_bank::load_question_data($qid);
        echo "<h2>Data for Question ID: $qid</h2>";
        
        echo "<h3>1. Object Explorer</h3>";
        echo "<pre>";
        echo "QTYPE: " . htmlspecialchars($qdata->qtype) . "\n";
        echo "Top level properties: " . implode(', ', array_keys(get_object_vars($qdata))) . "\n";
        
        if (isset($qdata->options)) {
            echo "Options properties: " . implode(', ', array_keys(get_object_vars($qdata->options))) . "\n";
        }
        echo "</pre>";

        echo "<h3>2. Raw Dump (Full)</h3>";
        echo "<pre>";
        print_r($qdata);
        echo "</pre>";

        echo "<h3>3. Database Search (Exhaustive)</h3>";
        $tables = $DB->get_tables();
        
        // Include core tables that might hold answer data
        $system_tables = ['question_answers', 'question_hints', 'question_multianswer'];
        $relevant_tables = array_filter($tables, function($t) use ($system_tables) {
            return strpos($t, 'qtype_') !== false || in_array($t, $system_tables);
        });

        echo "<ul>";
        foreach ($relevant_tables as $table) {
            $column_info = $DB->get_columns($table);
            $col_name = '';
            if (isset($column_info['questionid'])) $col_name = 'questionid';
            elseif (isset($column_info['question'])) $col_name = 'question';

            if ($col_name) {
                $count = $DB->count_records($table, [$col_name => $qid]);
                if ($count > 0) {
                    echo "<li><strong>$table</strong>: Found $count records. Data:<br>";
                    $records = $DB->get_records($table, [$col_name => $qid]);
                    echo "<pre>" . htmlspecialchars(print_r($records, true)) . "</pre>";
                    echo "</li>";
                }
            }
        }
        echo "</ul>";

        // Specialized check for gapselect/ddwtos related tables
        echo "<h3>4. GapSelect/DDWTOS Specific Check</h3>";
        $gap_tables = ['qtype_gapselect', 'qtype_ddwtos'];
        foreach ($gap_tables as $table) {
            if ($DB->get_manager()->table_exists($table)) {
                $rec = $DB->get_record($table, ['questionid' => $qid]);
                echo "<strong>$table</strong> detail: <pre>" . htmlspecialchars(print_r($rec, true)) . "</pre>";
            }
        }

    } catch (Exception $e) {
        echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
    }
}

echo "</body></html>";
