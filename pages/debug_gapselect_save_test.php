<?php
/**
 * Interactive Debug Page for GapSelect Saving Logic.
 * This page simulates the exact logic from ajax.php but provides granular debugging.
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

global $DB, $CFG, $OUTPUT, $PAGE, $USER;

require_login();
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_gapselect_save_test.php'));
$PAGE->set_title('GMK Debug: GapSelect Save Test');
$PAGE->set_heading('GMK Debug: GapSelect Save Test');

echo $OUTPUT->header();

$action = optional_param('action', '', PARAM_ALPHANUMEXT);

if ($action === 'run_test') {
    $raw_json = required_param('json_data', PARAM_RAW);
    $data = json_decode($raw_json);
    
    echo "<h3>Test Results</h3>";
    
    if (!$data) {
        echo "<div class='alert alert-danger'>Invalid JSON</div>";
    } else {
        try {
            // Simulate the logic in ajax.php
            $cat = question_get_default_category($context->id);
            $question = new stdClass();
            $question->category = $cat->id;
            $question->qtype = 'gapselect';
            $question->name = $data->name . " (TEST " . time() . ")";
            $question->questiontext = [
                'text' => $data->questiontext,
                'format' => FORMAT_HTML
            ];
            $question->defaultmark = 1;
            $question->stamp = make_unique_id_code();
            $question->version = make_unique_id_code();
            $question->timecreated = time();
            $question->timemodified = time();
            $question->contextid = $cat->contextid;
            $question->createdby = $USER->id;
            $question->modifiedby = $USER->id;

            $form_data = clone $question;
            $form_data->choices = [];
            $form_data->selectgroup = [];
            $form_data->shuffleanswers = 1;

            if (isset($data->answers) && is_array($data->answers)) {
                $question->answer = [];
                $question->feedback = [];
                $question->fraction = [];
                $question->selectgroup = [];

                foreach ($data->answers as $idx => $ans) {
                    $no = $idx + 1;
                    $text = $ans->text;
                    $group = $ans->group;

                    $choice_entry = [
                        'answer' => $text,
                        'choicegroup' => $group,
                        'selectgroup' => $group,
                        'choiceno' => $no
                    ];

                    $form_data->choices[$no] = $choice_entry;
                    $form_data->selectgroup[$no] = $group;

                    $question->answer[] = $text;
                    $question->feedback[] = $group;
                    $question->selectgroup[] = $group; 
                    $question->fraction[] = 0.0;
                }
            }

            echo "<h4>1. Data Prepared for Moodle:</h4>";
            echo "<p><strong>Question Text:</strong> " . htmlspecialchars($question->questiontext['text']) . "</p>";
            echo "<pre>Question Object:\n" . print_r($question, true) . "</pre>";
            echo "<pre>Form Data Object:\n" . print_r($form_data, true) . "</pre>";

            // Execute Save
            echo "<h4>2. Executing save_question...</h4>";
            $qtypeobj = question_bank::get_qtype('gapselect');
            $newq = $qtypeobj->save_question($question, $form_data);

            echo "<div class='alert alert-success'>Save called. Resulting ID: " . ($newq ? $newq->id : 'FAILED') . "</div>";

            if ($newq) {
                // Verify DB State
                $db_q = $DB->get_record('question', ['id' => $newq->id]);
                echo "<h4>3. Database Verification (Question Table):</h4>";
                echo "<p><strong>Text stored in DB:</strong> <span class='badge badge-primary'>" . htmlspecialchars($db_q->questiontext) . "</span></p>";
                if ($db_q->questiontext !== $data->questiontext) {
                    echo "<div class='alert alert-warning'><strong>WARNING:</strong> Moodle modified the text! Expected '" . htmlspecialchars($data->questiontext) . "'</div>";
                }

                echo "<h4>4. Choices (question_answers table):</h4>";
                $ans_records = $DB->get_records('question_answers', ['question' => $newq->id]);
                echo "<ul>";
                foreach ($ans_records as $ar) {
                    echo "<li>ID: {$ar->id}, Text: {$ar->answer}, Feedback (Group): {$ar->feedback}</li>";
                }
                echo "</ul>";
            }

        } catch (Exception $e) {
            echo "<div class='alert alert-danger'>Error: " . $e->getMessage() . "</div>";
        }
    }
    echo "<hr><a href='debug_gapselect_save_test.php' class='btn btn-secondary'>New Test</a>";
} else {
    ?>
    <p>This page will run the EXACT save logic from <code>ajax.php</code> using the JSON below.</p>
    <form action="debug_gapselect_save_test.php" method="post">
        <input type="hidden" name="action" value="run_test">
        <div class="form-group">
            <label>JSON Question Data (Simulator):</label>
            <textarea name="json_data" class="form-control" rows="10" style="font-family: monospace;"><?php
                $example = [
                    'name' => 'Save Test',
                    'questiontext' => 'Elige la [[1]] [[2]]',
                    'answers' => [
                        ['text' => 'palabra', 'group' => 1],
                        ['text' => 'perdida', 'group' => 2]
                    ]
                ];
                echo json_encode($example, JSON_PRETTY_PRINT);
            ?></textarea>
        </div>
        <button type="submit" class="btn btn-primary">Run Save Simulation</button>
    </form>
    <?php
}

echo $OUTPUT->footer();
