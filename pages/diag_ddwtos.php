<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/question/type/ddwtos/questiontype.php');

// Simple authentication (Teacher or admin)
require_login();
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/diag_ddwtos.php'));
$PAGE->set_title('Debug DDWTOS Saving');
$PAGE->set_heading('Debug DDWTOS Saving');

$action = optional_param('action', '', PARAM_ALPHA);
$qid = optional_param('qid', 0, PARAM_INT);

function get_choices_table() {
    global $DB;
    if ($DB->get_manager()->table_exists('question_ddwtos_choices')) {
        return 'question_ddwtos_choices';
    }
    if ($DB->get_manager()->table_exists('qtype_ddwtos_choices')) {
        return 'qtype_ddwtos_choices';
    }
    return ''; // Return empty if not found, don't guess question_answers yet
}

$choices_table = get_choices_table();
echo "<h4>Detected choices table: <strong>" . ($choices_table ? $choices_table : 'NONE FOUND') . "</strong></h4>";

echo $OUTPUT->header();

if ($action === 'deepsearch' && $qid) {
    echo "<h3>Deep Searching for Question ID: $qid</h3>";
    $q = $DB->get_record('question', array('id' => $qid));
    // Try to find a distractor/choice text to search for
    $search_term = '';
    
    // Check question text for [[1]] markers to infer there should be valid options
    if (preg_match('/\[\[(\d+)\]\]/', $q->questiontext)) {
        echo "<p>Found gaps in question text.</p>";
    }
    
    // Blind search in question_answers
    $answers = $DB->get_records('question_answers', array('question' => $qid));
    if ($answers) {
        echo "<h4>Found records in 'question_answers':</h4>";
        echo "<ul>";
        foreach ($answers as $a) {
            echo "<li>ID: {$a->id}, Text: {$a->answer}, Fraction: {$a->fraction}</li>";
            if (!$search_term) $search_term = $a->answer; // Pick first one to search
        }
        echo "</ul>";
        echo "<div class='alert alert-warning'>This suggests Moodle might be using `question_answers` for ddwtos like in legacy versions (or Gapfill question type).</div>";
    }

    if (!$search_term) {
        echo "<p>Could not auto-determine a search term. Please edit the URL and add &term=YOUR_SEARCH_TERM</p>";
    } else {
        echo "<h4>Searching database for value: '<strong>$search_term</strong>'</h4>";
        $tables = $DB->get_tables();
        foreach ($tables as $table) {
            // Optimization: skip logs and caches
            if (strpos($table, 'log') !== false || strpos($table, 'cache') !== false || strpos($table, 'stat') !== false) continue;
            
            try {
                $columns = $DB->get_columns($table);
                foreach ($columns as $col) {
                    if ($col->meta_type == 'C' || $col->meta_type == 'X') { // Char or Text
                        $sql = "SELECT id FROM {{$table}} WHERE " . $DB->sql_like($col->name, ':param', false, false);
                        if ($DB->record_exists_sql($sql, ['param' => $search_term])) {
                            echo "<div class='alert alert-success'>FOUND MATCH in table: <strong>$table</strong>, column: <strong>{$col->name}</strong></div>";
                            
                            // Show the row
                            $rec = $DB->get_record_sql("SELECT * FROM {{$table}} WHERE " . $DB->sql_like($col->name, ':param', false, false), ['param' => $search_term]);
                            echo "<pre>" . print_r($rec, true) . "</pre>";
                        }
                    }
                }
            } catch (Exception $e) { continue; }
        }
    }
    echo '<a href="diag_ddwtos.php" class="btn btn-secondary">Back</a>';
}
elseif ($action === 'inspect' && $qid) {
    echo "<h3>Inspecting Question ID: $qid</h3>";
    // $qdata = question_bank::load_question_data($qid); // Avoid calling this if tables are missing to prevent crash
    echo "<h4>Basic Question Data:</h4>";
    $q = $DB->get_record('question', array('id' => $qid));
    echo "<pre>" . print_r($q, true) . "</pre>";

    if ($choices_table) {
        echo "<h4>Choices (from $choices_table):</h4>";
        $lookup_col = ($choices_table === 'question_answers') ? 'question' : 'questionid';
        $choices = $DB->get_records($choices_table, array($lookup_col => $qid));
        echo "<pre>" . print_r($choices, true) . "</pre>";
    } else {
        echo "<div class='alert alert-danger'>No standard choices table found. Check 'question_answers' below:</div>";
        $answers = $DB->get_records('question_answers', array('question' => $qid));
        echo "<pre>" . print_r($answers, true) . "</pre>";
    }

    echo '<a href="diag_ddwtos.php" class="btn btn-secondary">Back</a>';
}
elseif ($action === 'testsave' && $qid) {
    echo "<h3>Testing Save for Question ID: $qid</h3>";
    $last_q = $DB->get_record('question', array('id' => $qid));
    
    // Define logic based on what we found (fallback to answers if no specific table)
    $use_answers_table = empty($choices_table);
    $active_table = $use_answers_table ? 'question_answers' : $choices_table;
    $lookup_col = $use_answers_table ? 'question' : 'questionid';

    $choices = $DB->get_records($active_table, array($lookup_col => $qid));
    
    if ($choices) {
        $first_choice = reset($choices);
        $old_group = $use_answers_table ? 'N/A' : $first_choice->choicegroup;
        echo "<p>Found " . count($choices) . " choices.</p>";
        
        // ... (Persistence test logic would need to be adapted if we are indeed using question_answers)
        echo "<div class='alert alert-warning'>Save test disabled until storage location is confirmed. Please run Deep Search.</div>";
    }
    echo '<a href="diag_ddwtos.php" class="btn btn-secondary">Back</a>';
} else {
    // List questions
    echo "<h3>Recent DDWTOS Questions</h3>";
    $questions = $DB->get_records('question', array('qtype' => 'ddwtos'), 'id DESC', '*', 0, 10);
    
    if ($questions) {
        echo "<table class='table'>";
        echo "<tr><th>ID</th><th>Name</th><th>Rows in question_answers</th><th>Actions</th></tr>";
        foreach ($questions as $q) {
            $ans_count = $DB->count_records('question_answers', ['question' => $q->id]);
            echo "<tr>";
            echo "<td>{$q->id}</td>";
            echo "<td>{$q->name}</td>";
            echo "<td>{$ans_count}</td>";
            echo "<td>
                <a href='diag_ddwtos.php?action=inspect&qid={$q->id}' class='btn btn-info btn-sm'>Inspect</a>
                <a href='diag_ddwtos.php?action=deepsearch&qid={$q->id}' class='btn btn-danger btn-sm'>DEEP SEARCH</a>
            </td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>No questions found.</p>";
    }
}

echo $OUTPUT->footer();
