<?php
/**
 * General Question Debugger
 * Inspects any question ID to determine type and basic integrity.
 */
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/questionlib.php');

global $DB, $CFG, $OUTPUT, $PAGE, $USER;

require_login();
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_question_general.php'));
$PAGE->set_title('GMK Debug: General Question Inspector');
$PAGE->set_heading('GMK Debug: General Question Inspector');

echo $OUTPUT->header();

$qid = optional_param('qid', 0, PARAM_INT);

if ($qid) {
    echo "<h3>Inspecting Question ID: $qid</h3>";
    $question = $DB->get_record('question', ['id' => $qid]);

    if ($question) {
        echo "<p><strong>Type:</strong> <span class='badge badge-info'>{$question->qtype}</span></p>";
        echo "<p><strong>Name:</strong> {$question->name}</p>";
        echo "<pre>" . print_r($question, true) . "</pre>";
        
        if (strpos($question->qtype, 'calculated') !== false) {
             echo "<h4>Calculated Question details:</h4>";
             $datasets = $DB->get_records('question_datasets', ['question' => $qid]);
             echo "<p><strong>Datasets found:</strong> " . count($datasets) . "</p>";
             echo "<pre>" . print_r($datasets, true) . "</pre>";
        }
    } else {
        echo "<div class='alert alert-danger'>Question not found in DB.</div>";
    }
    echo "<hr><a href='debug_question_general.php' class='btn btn-secondary'>New Search</a>";
} else {
    ?>
    <form action="debug_question_general.php" method="get">
        <div class="form-group">
            <label>Question ID:</label>
            <input type="number" name="qid" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-primary">Inspect</button>
    </form>
    <?php
}

echo $OUTPUT->footer();
