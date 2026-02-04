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
        echo "<h2>System Investigation</h2>";
        echo "Moodle Version: " . get_config('core', 'version') . "<br>";

        echo "<h3>1. Table Schemas</h3>";
        $schemas = ['question_answers', 'question_ddwtos', 'question_gapselect'];
        foreach ($schemas as $s) {
            if ($DB->get_manager()->table_exists($s)) {
                $cols = array_keys($DB->get_columns($s));
                echo "<strong>$s</strong>: " . implode(', ', $cols) . "<br>";
            }
        }

        echo "<h3>2. Searching for SUCCESSFUL DDWTOS Questions</h3>";
        $sql = "SELECT q.id, q.name FROM {question} q 
                JOIN {question_answers} qa ON q.id = qa.question
                WHERE q.qtype = 'ddwtos' LIMIT 3";
        $examples = $DB->get_records_sql($sql);
        if ($examples) {
            foreach ($examples as $ex) {
                echo "<h4>Example SUCCESSFUL Question: {$ex->name} (ID: {$ex->id})</h4>";
                $ex_data = question_bank::load_question_data($ex->id);
                echo "<pre>" . htmlspecialchars(print_r($ex_data, true)) . "</pre>";
            }
        } else {
            echo "<p>No existing ddwtos questions found with answers in question_answers.</p>";
        }

        echo "<h3>3. Search for references to ID $qid</h3>";
        $tables = $DB->get_tables();
        echo "<ul>";
        foreach ($tables as $table) {
            $columns = $DB->get_columns($table);
            foreach ($columns as $colname => $colinfo) {
                if ($colname == 'questionid' || $colname == 'question' || $colname == 'parent') {
                    $count = $DB->count_records($table, [$colname => $qid]);
                    if ($count > 0) {
                        echo "<li><strong>$table</strong> ($colname): Found $count records.</li>";
                        $recs = $DB->get_records($table, [$colname => $qid]);
                        echo "<pre>" . htmlspecialchars(print_r($recs, true)) . "</pre>";
                    }
                }
            }
        }
        echo "</ul>";

    } catch (Exception $e) {
        echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
    }
}

echo "</body></html>";
