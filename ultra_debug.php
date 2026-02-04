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
    } catch (Throwable $e) {
        echo "<p style='color:red;'>Error exploring: " . $e->getMessage() . "</p>";
    }
}

echo "<h3>2.1 Searching for Cloze (multianswer) Questions</h3>";
try {
    $sql = "SELECT id, name, questiontext FROM {question} WHERE qtype = 'multianswer' ORDER BY id DESC LIMIT 5";
    $clozes = $DB->get_records_sql($sql);
    if ($clozes) {
        echo "<ul>";
        foreach ($clozes as $cl) {
            echo "<li><strong>ID: {$cl->id}</strong> - Name: " . htmlspecialchars($cl->name) . "<br>";
            echo "Text: <pre>" . htmlspecialchars($cl->questiontext) . "</pre>";
            
            // Check if it has child questions
            $children = $DB->get_records('question', ['parent' => $cl->id]);
            echo "Children found: " . count($children) . "<br>";
            foreach($children as $child) {
                 $child_ans = $DB->get_records('question_answers', ['question' => $child->id]);
                 echo "&nbsp;&nbsp; - Child ID: {$child->id} (Type: {$child->qtype}) - Answers: " . count($child_ans) . "<br>";
            }
            echo "</li>";
        }
        echo "</ul>";
    } else {
        echo "<p>No Cloze questions found.</p>";
    }
} catch (Throwable $e) {
    echo "<p style='color:red;'>Error finding clozes: " . $e->getMessage() . "</p>";
}

if ($qid !== 0) {
    try {
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
