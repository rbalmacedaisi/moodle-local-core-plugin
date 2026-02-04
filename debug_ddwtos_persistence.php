<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/question/type/ddwtos/questiontype.php');

global $DB;

$qtype = 'ddwtos';
echo "--- DIAGNOSTIC DDWTOS ---\n";

// 1. Get the last ddwtos question
$last_q = $DB->get_record_sql("SELECT * FROM {question} WHERE qtype = ? ORDER BY id DESC", array($qtype), IGNORE_MULTIPLE);

if (!$last_q) {
    die("No ddwtos questions found.\n");
}

echo "Question ID: {$last_q->id}\n";
echo "Name: {$last_q->name}\n";

// 2. Load the question data properly
$questiondata = question_bank::load_question_data($last_q->id);

echo "\n--- QUESTION DATA (from question_bank) ---\n";
// Filter the data to avoid massive dump
$display_data = new stdClass();
$display_data->id = $questiondata->id;
$display_data->qtype = $questiondata->qtype;
if (isset($questiondata->options)) {
    $display_data->choices = $questiondata->options->choices ?? 'Not set';
}
print_r($display_data);

// 3. Check DB tables directly
echo "\n--- DB: qtype_ddwtos_choices ---\n";
$choices = $DB->get_records('qtype_ddwtos_choices', array('questionid' => $last_q->id));
if ($choices) {
    foreach ($choices as $c) {
        echo "ID: {$c->id}, No: {$c->choiceno}, Group: {$c->choicegroup}, Text: {$c->answer}\n";
    }
} else {
    echo "No records in qtype_ddwtos_choices for this question.\n";
}

// 4. Trace the saving process (Simulation)
echo "\n--- SIMULATING SAVE ---\n";
// We will try to update this question with a new group for the first choice
if ($choices) {
    $first_choice = reset($choices);
    $new_group = ($first_choice->choicegroup % 5) + 1;
    echo "Attempting to change choice #{$first_choice->choiceno} group from {$first_choice->choicegroup} to {$new_group}\n";
    
    // Prepare form data similar to ajax.php
    $form_data = new stdClass();
    $form_data->id = $last_q->id;
    $form_data->qtype = 'ddwtos';
    $form_data->name = $last_q->name . " (Debug)";
    $form_data->questiontext = $last_q->questiontext;
    $form_data->defaultmark = $last_q->defaultmark;
    $form_data->category = $last_q->category;
    $form_data->shuffleanswers = 1;
    
    $i = 1;
    foreach ($choices as $c) {
        $group = ($i == 1) ? $new_group : $c->choicegroup;
        
        $choice_rec = [
            'id' => $c->id,
            'answer' => $c->answer,
            'choicegroup' => $group,
            'draggroup' => $group,
            'infinite' => 0
        ];
        
        $form_data->choices[$i] = (object)$choice_rec;
        $form_data->choice[$i-1] = (object)$choice_rec; // 0-based singular
        
        $form_data->draglabel[$i] = $c->answer;
        $form_data->draggroup[$i] = $group;
        $form_data->choicegroup[$i] = $group;
        $form_data->infinite[$i] = 0;
        
        $i++;
    }

    // Try saving
    try {
        $qtype_obj = question_bank::get_qtype('ddwtos');
        echo "Calling save_question_options...\n";
        
        // save_question_options expects a question object with options in it usually, 
        // but here we follow the pattern in ajax.php (which might be slightly off if it uses save_question)
        
        // Let's see what happens if we use the same logic as ajax.php
        $question = clone $last_q;
        foreach ($form_data as $key => $value) {
            $question->$key = $value;
        }
        
        $qtype_obj->save_question_options($question);
        
        // Verify again
        echo "\n--- VERIFY AFTER SAVE ---\n";
        $choices_after = $DB->get_records('qtype_ddwtos_choices', array('questionid' => $last_q->id));
        foreach ($choices_after as $c) {
            echo "ID: {$c->id}, No: {$c->choiceno}, Group: {$c->choicegroup}, Text: {$c->answer}\n";
            if ($c->id == $first_choice->id && $c->choicegroup == $new_group) {
                echo "SUCCESS: Group updated!\n";
            }
        }
    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
    }
}
