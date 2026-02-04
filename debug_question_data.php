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
        $prefix = $DB->get_prefix();
        
        $tables_to_check = [
            'question_answers', 
            'question_hints', 
            'qtype_ddwtos', 
            'qtype_gapselect'
        ];

        echo "<ul>";
        foreach ($tables_to_check as $short_table) {
            if ($DB->get_manager()->table_exists($short_table)) {
                $column_info = $DB->get_columns($short_table);
                $col_name = isset($column_info['questionid']) ? 'questionid' : (isset($column_info['question']) ? 'question' : '');

                if ($col_name) {
                    $records = $DB->get_records($short_table, [$col_name => $qid]);
                    echo "<li><strong>$short_table</strong>: Found " . count($records) . " records.<br>";
                    if (!empty($records)) {
                        echo "<pre>" . htmlspecialchars(print_r($records, true)) . "</pre>";
                    }
                    echo "</li>";
                } else {
                    echo "<li><strong>$short_table</strong>: Table exists but no questionid/question column found.</li>";
                }
            } else {
                echo "<li><strong>$short_table</strong>: Table does not exist (checked with prefix '$prefix').</li>";
            }
        }
        echo "</ul>";

        echo "<h3>4. Answer Mapping Test</h3>";
        $raw_answers = $DB->get_records('question_answers', ['question' => $qid]);
        echo "Found in question_answers: " . count($raw_answers) . "<br>";
        foreach($raw_answers as $ra) {
            echo " - ID: {$ra->id}, Answer: " . htmlspecialchars($ra->answer) . "<br>";
        }

    } catch (Exception $e) {
        echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
    }
}

echo "</body></html>";
