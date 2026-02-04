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
                     echo "<br><strong>DB ItemCount:</strong> {$def->itemcount}"; // Show persisted count
                     
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
        
        // --- REGENERATE ACTION ---
        echo "<hr><h3>Actions</h3>";
        echo "<form method='post' action='debug_calculated_inspect.php?qid=$qid'>";
        echo "<input type='hidden' name='action' value='regenerate'>";
        echo "<button type='submit' class='btn btn-warning'>FIX / REGENERATE DATASETS</button>";
        echo "</form>";

    }
    echo "<hr><a href='debug_calculated_inspect.php' class='btn btn-secondary'>New Search</a>";

} 

// ACTION PROCESSOR
$action = optional_param('action', '', PARAM_ALPHANUMEXT);
if ($action === 'regenerate' && $qid) {
    echo "<h3>Regenerating Datasets for QID: $qid...</h3>";
    $q = question_bank::load_question($qid);
    
    // 1. Detect
    $wildcards = [];
    foreach ($q->answers as $ans) {
        if (preg_match_all('~\{([a-zA-Z0-9_]+)\}~', $ans->answer, $matches)) {
            foreach ($matches[1] as $wc) $wildcards[$wc] = true;
        }
    }
    if (isset($q->questiontext)) {
         if (preg_match_all('~\{([a-zA-Z0-9_]+)\}~', $q->questiontext, $matches)) {
             foreach ($matches[1] as $wc) $wildcards[$wc] = true;
         }
    }
    
    echo "Detected Wildcards: " . implode(', ', array_keys($wildcards)) . "<br>";
    
    foreach (array_keys($wildcards) as $name) {
        // Find or Create Def
        $def = $DB->get_record_sql("
            SELECT * FROM {question_dataset_definitions} 
            WHERE name = ? AND (category = 0 OR category = ?)
            ORDER BY id DESC LIMIT 1
        ", [$name, $q->category]);
        
        if (!$def) {
            $def = new stdClass();
            $def->category = 0; $def->name = $name; $def->type = 1; $def->options = 'uniform'; $def->itemcount = 0; $def->xmlid = '';
            $def->id = $DB->insert_record('question_dataset_definitions', $def);
            echo "<span style='color:green'>Created Def ID: {$def->id} for '$name'</span><br>";
        } else {
            echo "Found Def ID: {$def->id} for '$name'<br>";
        }
        
        // Ensure Link
        if (!$DB->record_exists('question_datasets', ['question' => $qid, 'datasetdefinition' => $def->id])) {
            $link = new stdClass(); $link->question = $qid; $link->datasetdefinition = $def->id;
            $DB->insert_record('question_datasets', $link);
            echo "<span style='color:blue'>Restored Link to Def {$def->id}</span><br>";
        }
        
        // Force Item Generation (Always add up to 10 if missing)
        $current_count = $DB->count_records('question_dataset_items', ['definition' => $def->id]);
        if ($current_count < 10) {
            for ($i = $current_count + 1; $i <= 10; $i++) {
                 $val = 1.0 + (9.0 * (mt_rand() / mt_getrandmax())); // Simple default 1-10
                 $val = round($val, 1);
                 $item = new stdClass(); $item->definition = $def->id; $item->itemnumber = $i; $item->value = $val;
                 $DB->insert_record('question_dataset_items', $item);
            }
            $DB->set_field('question_dataset_definitions', 'itemcount', 10, ['id' => $def->id]);
            echo "<span style='color:purple'>Generated items (Top up to 10) for {$def->name}</span><br>";
        } else {
            echo "Items OK ($current_count)<br>";
            // Ensure db itemcount is synced
            $DB->set_field('question_dataset_definitions', 'itemcount', $current_count, ['id' => $def->id]);
        }
    }
    echo "<div class='alert alert-success'>DONE. Check Inspector again.</div>";
}

if (!$qid) {
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
