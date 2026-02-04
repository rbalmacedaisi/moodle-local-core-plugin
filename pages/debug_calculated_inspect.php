<?php
/**
 * Deep Inspector for Calculated Questions.
 * Diagnoses 'cannotgetdsfordependent' errors by checking all DB relationships.
 */
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/questionlib.php');

global $DB, $CFG, $OUTPUT, $PAGE, $USER;

require_login();
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_calculated_inspect.php'));
$PAGE->set_title('GMK Debug: Calculated Inspector');
$PAGE->set_heading('GMK Debug: Calculated Inspector');

echo $OUTPUT->header();

$qid = optional_param('qid', 0, PARAM_INT);

if ($qid) {
    echo "<h3>Inspecting Question ID: $qid</h3>";
    
    // 1. Basic Question Data
    $question = $DB->get_record('question', ['id' => $qid]);
    if (!$question) {
        echo "<div class='alert alert-danger'>Question NOT FOUND.</div>";
    } else {
        echo "<p><strong>Name:</strong> {$question->name} | <strong>Type:</strong> {$question->qtype}</p>";
        
        // 2. Calculated Options
        $options_tables = ['question_calculated', 'question_calculated_options'];
        foreach ($options_tables as $table) {
            $opt = $DB->get_record($table, ['question' => $qid]);
            echo "<h4>Table: {{$table}}</h4>";
            if ($opt) {
                echo "<pre>" . print_r($opt, true) . "</pre>";
            } else {
                 echo "<div class='alert alert-warning'>No record in {{$table}} (Normal if not extended type)</div>";
            }
        }
        
        // 3. Question Datasets Maps
        echo "<h4>Question -> Dataset Links (question_datasets)</h4>";
        $links = $DB->get_records('question_datasets', ['question' => $qid]);
        if (empty($links)) {
             echo "<div class='alert alert-danger'><strong>CRITICAL:</strong> No links in question_datasets! The question doesn't know it has datasets.</div>";
        } else {
             foreach ($links as $link) {
                 echo "<div class='card mb-2'><div class='card-body'>";
                 echo "<strong>Link ID:</strong> {$link->id} -> <strong>Definition ID:</strong> {$link->datasetdefinition}";
                 
                 // 4. Definition
                 $def = $DB->get_record('question_dataset_definitions', ['id' => $link->datasetdefinition]);
                 if ($def) {
                     echo "<br><strong>Variable:</strong> {$def->name} (Type: {$def->type}, Category: {$def->category})";
                     
                     // 5. Items
                     $items = $DB->get_records('question_dataset_items', ['definition' => $def->id], 'itemnumber ASC');
                     echo "<br><strong>Items Found:</strong> " . count($items);
                     
                     if (empty($items)) {
                         echo "<div class='alert alert-danger mt-1'>NO ITEMS (Values) GENERATED for this definition!</div>";
                     } else {
                         echo "<ul class='mb-0'>";
                         $count = 0;
                         foreach ($items as $item) {
                             echo "<li>Item #{$item->itemnumber}: <code>{$item->value}</code></li>";
                             if (++$count >= 5) { echo "<li>... (more) ...</li>"; break; }
                         }
                         echo "</ul>";
                     }
                 } else {
                     echo "<div class='alert alert-danger'>Definition ID {$link->datasetdefinition} NOT FOUND in DB!</div>";
                 }
                 echo "</div></div>";
             }
        }
        
        // 6. Check Answers (Formulas)
        echo "<h4>Answers (Formulas)</h4>";
        $answers = $DB->get_records('question_answers', ['question' => $qid]);
        foreach ($answers as $ans) {
            echo "<div>ID: {$ans->id} | Answer: <code>{$ans->answer}</code> | Fraction: {$ans->fraction}</div>";
        }
    }
    echo "<hr><a href='debug_calculated_inspect.php' class='btn btn-secondary'>New Search</a>";
} else {
    ?>
    <form action="debug_calculated_inspect.php" method="get">
        <div class="form-group">
            <label>Question ID (e.g., 188):</label>
            <input type="number" name="qid" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-primary">Deep Inspect</button>
    </form>
    <?php
}

echo $OUTPUT->footer();
