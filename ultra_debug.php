<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/questionlib.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$qid = optional_param('qid', 0, PARAM_INT);

echo "<html><head><title>ULTRA Debug Question Data</title></head><body>";
echo "<h1>ULTRA Diagnostic Tool - Scanning ALL Tables</h1>";

if ($qid === 0) {
    echo "<p>Please provide a question ID (?qid=XXX)</p>";
} else {
    try {
        echo "<h2>Analyzing Question ID: $qid</h2>";
        
        $tables = $DB->get_tables();
        echo "<p>Checking " . count($tables) . " tables for references to ID $qid...</p>";
        
        echo "<ul>";
        foreach ($tables as $table) {
            $columns = $DB->get_columns($table);
            $qid_cols = [];
            foreach ($columns as $colname => $colinfo) {
                // Focus on potential foreign keys
                if ($colname == 'questionid' || $colname == 'question' || $colname == 'parent' || $colname == 'itemid') {
                    $qid_cols[] = $colname;
                }
            }
            
            if (!empty($qid_cols)) {
                foreach ($qid_cols as $col) {
                    $count = $DB->count_records($table, [$col => $qid]);
                    if ($count > 0) {
                        echo "<li><strong>$table</strong> (Column: $col): Found $count records.<br>";
                        $records = $DB->get_records($table, [$col => $qid]);
                        echo "<pre>" . htmlspecialchars(print_r($records, true)) . "</pre>";
                        echo "</li>";
                    }
                }
            }
        }
        echo "</ul>";

        // Also check if any answer references it in question_answers
        // But wait, question_answers has its own ID. 
        // Let's check for any record that contains '[[1]]' or similar in question_answers? 
        // No, let's search for DDWTOS records.
        
        echo "<h3>Searching for ANY record in question_answers related to DDWTOS choices</h3>";
        $ans = $DB->get_records('question_answers', ['question' => $qid]);
        echo "Direct get_records in question_answers: " . count($ans) . " results.<br>";
        if($ans) {
             echo "<pre>" . htmlspecialchars(print_r($ans, true)) . "</pre>";
        }

        echo "<h3>Listing all tables containing 'dd' or 'gap' or 'text'</h3>";
        $all_tables = $DB->get_tables();
        $matching = array_filter($all_tables, function($t) {
            return strpos($t, 'dd') !== false || strpos($t, 'gap') !== false || strpos($t, 'text') !== false;
        });
        echo "<ul>";
        foreach ($matching as $t) {
            echo "<li>$t</li>";
        }
        echo "</ul>";

    } catch (Exception $e) {
        echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
    }
}

echo "</body></html>";
