<?php
/**
 * Interactive Debug Page for Calculated Question Saving Logic.
 * Simulates saving a Calculated question to verify dataset item generation.
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
$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_calculated_save_test.php'));
$PAGE->set_title('GMK Debug: Calculated Save Test');
$PAGE->set_heading('GMK Debug: Calculated Save Test');

echo $OUTPUT->header();

$action = optional_param('action', '', PARAM_ALPHANUMEXT);

if ($action === 'run_test') {
    $raw_json = required_param('json_data', PARAM_RAW);
    $data = json_decode($raw_json);
    
    echo "<h3>Calculated Test Results</h3>";
    
    if (!$data) {
        echo "<div class='alert alert-danger'>Invalid JSON</div>";
    } else {
        try {
            // Simulate the logic in ajax.php (Calculated Block)
            $cat = question_get_default_category($context->id);
            $question = new stdClass();
            $question->category = $cat->id;
            $question->qtype = 'calculated';
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
            
            // Basic Calculated arrays
            $question->answers = [];
            $question->fraction = [];
            $question->tolerance = [];
            $question->tolerancetype = [];
            $question->correctanswerlength = [];
            $question->correctanswerformat = [];
            $question->feedback = [];
             
             if (isset($data->answers) && is_array($data->answers)) {
                foreach ($data->answers as $ans) {
                    $question->answer[] = $ans->text; // Formula
                    $question->fraction[] = 1.0;
                    $question->tolerance[] = 0.01;
                    $question->tolerancetype[] = 1; // Relative
                    $question->correctanswerlength[] = 2; 
                    $question->correctanswerformat[] = 1; // Decimals
                    $question->feedback[] = ['text' => '', 'format' => FORMAT_HTML];
                }
             }
             
             $question->unit = [''];
             $question->multiplier = [1.0];
             $question->synchronize = 0;
             $question->single = 1; // For calculated simple/multi
             $question->answernumbering = 'abc';
             $question->shuffleanswers = 0;
             $question->correctfeedback = ['text' => '', 'format' => FORMAT_HTML];
             $question->partiallycorrectfeedback = ['text' => '', 'format' => FORMAT_HTML];
             $question->incorrectfeedback = ['text' => '', 'format' => FORMAT_HTML];
             $question->shownumcorrect = 1;

             // Ensure form_data also has them for initial cloning if needed logic relies on it
             $form_data->answernumbering = 'abc';
             $form_data->shuffleanswers = 0;

             // --- LOGIC TO TEST: Regex & Datasets ---
             $form_data->dataset = [];
             $detected_wildcards = [];
             
             if (!isset($data->dataset) || empty($data->dataset)) {
                 foreach ($question->answer as $formula) {
                     if (preg_match_all('~\{([a-zA-Z0-9_]+)\}~', $formula, $matches)) {
                         foreach ($matches[1] as $wildcard) {
                             $detected_wildcards[$wildcard] = true;
                         }
                     }
                 }
                 if (!empty($detected_wildcards)) {
                     $data->dataset = [];
                     foreach (array_keys($detected_wildcards) as $wc) {
                         $ds = new stdClass();
                         $ds->name = $wc;
                         $data->dataset[] = $ds;
                     }
                 }
             }

             if (isset($data->dataset) && is_array($data->dataset)) {
                 foreach ($data->dataset as $ds) {
                      $name = $ds->name; 
                      $form_data->dataset[] = $name;
                      
                      $form_data->{"dataset_$name"} = '0';
                      $form_data->{"number_$name"} = 10; // Request 10 items
                      $form_data->{"options_$name"} = 'uniform'; 
                      $form_data->{"calcmin_$name"} = 1;
                      $form_data->{"calcmax_$name"} = 10;
                      $form_data->{"calclength_$name"} = 1;
                      $form_data->{"calcdistribution_$name"} = 'uniform';
                 }
             }

             // Attach to form_data what question has properties of
             $form_data->answer = $question->answer;
             $form_data->fraction = $question->fraction;
             $form_data->tolerance = $question->tolerance;
             $form_data->tolerancetype = $question->tolerancetype;
             $form_data->correctanswerlength = $question->correctanswerlength;
             $form_data->correctanswerformat = $question->correctanswerformat;
             $form_data->feedback = $question->feedback;
             $form_data->unit = $question->unit;
             $form_data->multiplier = $question->multiplier;
             $form_data->correctfeedback = $question->correctfeedback;
             $form_data->partiallycorrectfeedback = $question->partiallycorrectfeedback;
             $form_data->incorrectfeedback = $question->incorrectfeedback;
             $form_data->shownumcorrect = $question->shownumcorrect;


            echo "<h4>1. Data Prepared for Moodle:</h4>";
            echo "<p><strong>Formula:</strong> " . implode(', ', $question->answer) . "</p>";
            echo "<p><strong>Detected Datasets:</strong> " . implode(', ', $form_data->dataset) . "</p>";
            
            // Execute Save
            echo "<h4>2. Executing save_question...</h4>";
            $qtypeobj = question_bank::get_qtype('calculated');
            $newq = $qtypeobj->save_question($question, $form_data);

            // Post-Save: Generate Items (Match ajax.php logic)
            if ($newq && !empty($form_data->dataset)) {
                $definitions = $DB->get_records_sql("
                    SELECT qdd.* 
                    FROM {question_dataset_definitions} qdd
                    JOIN {question_datasets} qd ON qd.datasetdefinition = qdd.id
                    WHERE qd.question = ?
                ", [$newq->id]);

                foreach ($definitions as $def) {
                    if ($DB->count_records('question_dataset_items', ['definition' => $def->id]) == 0) {
                            $min = isset($form_data->{"calcmin_{$def->name}"}) ? $form_data->{"calcmin_{$def->name}"} : 1.0;
                            $max = isset($form_data->{"calcmax_{$def->name}"}) ? $form_data->{"calcmax_{$def->name}"} : 10.0;
                            $dec = isset($form_data->{"calclength_{$def->name}"}) ? $form_data->{"calclength_{$def->name}"} : 1;
                            
                            for ($i = 1; $i <= 10; $i++) {
                                $val = $min + ($max - $min) * (mt_rand() / mt_getrandmax());
                                $val = round($val, $dec);
                                $item = new stdClass();
                                $item->definition = $def->id;
                                $item->itemnumber = $i;
                                $item->value = $val;
                                $DB->insert_record('question_dataset_items', $item);
                            }
                            // echo "<p>Generated 10 items for '{$def->name}'</p>";
                    }
                    
                    // CRITICAL MATCH with ajax.php: Ensure linkage
                    if (!$DB->record_exists('question_datasets', ['question' => $newq->id, 'datasetdefinition' => $def->id])) {
                        $link = new stdClass();
                        $link->question = $newq->id;
                        $link->datasetdefinition = $def->id;
                        $DB->insert_record('question_datasets', $link);
                    }
                }
            }

            echo "<div class='alert alert-success'>Save called. Resulting ID: " . ($newq ? $newq->id : 'FAILED') . "</div>";

            if ($newq) {
                // Verify DB State
                $db_q = $DB->get_record('question', ['id' => $newq->id]);
                echo "<h4>3. Database Verification (Question Table):</h4>";
                echo "<p>Name: {$db_q->name}</p>";

                echo "<h4>4. Dataset Definitions (question_dataset_definitions):</h4>";
                $defs = $DB->get_records_sql("
                    SELECT qdd.* 
                    FROM {question_dataset_definitions} qdd
                    JOIN {question_datasets} qd ON qd.datasetdefinition = qdd.id
                    WHERE qd.question = ?
                ", [$newq->id]);
                
                echo "<ul>";
                foreach ($defs as $def) {
                    echo "<li>ID: {$def->id}, Name: {$def->name}, Item Count: " . $DB->count_records('question_dataset_items', ['definition' => $def->id]) . "</li>";
                    
                    // Specific check for items
                    $items = $DB->get_records('question_dataset_items', ['definition' => $def->id]);
                    if (empty($items)) {
                        echo "<li style='color:red;'><strong>WARNING: No items generated for {$def->name}! This causes 'cannotgetdsfordependent'.</strong></li>";
                    } else {
                        echo "<ul>";
                        foreach(array_slice($items, 0, 3) as $it) echo "<li>Value: {$it->value}</li>";
                        echo "</ul>";
                    }
                }
                echo "</ul>";
                
                if (empty($defs)) {
                    echo "<div class='alert alert-danger'>NO DATASET DEFINITIONS FOUND!</div>";
                }
            }

        } catch (Exception $e) {
            echo "<div class='alert alert-danger'>Error: " . $e->getMessage() . "</div>";
            echo "<pre>" . $e->getTraceAsString() . "</pre>";
        }
    }
    echo "<hr><a href='debug_calculated_save_test.php' class='btn btn-secondary'>New Test</a>";
} else {
    ?>
    <p>Simulate saving a Calculated question to verify dataset generation.</p>
    <form action="debug_calculated_save_test.php" method="post">
        <input type="hidden" name="action" value="run_test">
        <div class="form-group">
            <label>JSON Question Data:</label>
            <textarea name="json_data" class="form-control" rows="10" style="font-family: monospace;"><?php
                $example = [
                    'name' => 'Calculated Test',
                    'questiontext' => 'Calculate {a} + {b}',
                    'answers' => [
                        ['text' => '{a} + {b}']
                    ]
                ];
                echo json_encode($example, JSON_PRETTY_PRINT);
            ?></textarea>
        </div>
        <button type="submit" class="btn btn-primary">Run Calc Simulation</button>
    </form>
    <?php
}

echo $OUTPUT->footer();
