<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/question/type/ddwtos/questiontype.php');

// Simple authentication (Teacher or admin)
require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context); // Admin only for safety, or allow teachers

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
    return 'question_answers'; // Fallback to check if they are there
}

$choices_table = get_choices_table();
echo "<h4>Using table: <strong>$choices_table</strong></h4>";

echo $OUTPUT->header();

if ($action === 'inspect' && $qid) {
    echo "<h3>Inspecting Question ID: $qid</h3>";
    $qdata = question_bank::load_question_data($qid);
    echo "<h4>Raw Data (question_bank::load_question_data):</h4>";
    echo "<pre>" . print_r($qdata, true) . "</pre>";
    
    echo "<h4>Options Table:</h4>";
    $option_table = $DB->get_manager()->table_exists('question_ddwtos') ? 'question_ddwtos' : 'qtype_ddwtos_options';
    $options = $DB->get_record($option_table, array('questionid' => $qid));
    echo "<pre>" . print_r($options, true) . "</pre>";

    echo "<h4>Choices (from $choices_table):</h4>";
    $lookup_col = ($choices_table === 'question_answers') ? 'question' : 'questionid';
    $choices = $DB->get_records($choices_table, array($lookup_col => $qid));
    echo "<pre>" . print_r($choices, true) . "</pre>";

    echo '<a href="diag_ddwtos.php" class="btn btn-secondary">Back</a>';
}
elseif ($action === 'testsave' && $qid) {
    echo "<h3>Testing Save for Question ID: $qid</h3>";
    
    $last_q = $DB->get_record('question', array('id' => $qid));
    $lookup_col = ($choices_table === 'question_answers') ? 'question' : 'questionid';
    $choices = $DB->get_records($choices_table, array($lookup_col => $qid));
    
    if ($choices) {
        // ... (remaining test logic using $choices_table and $choices)
        $first_choice = reset($choices);
        $new_group = ($first_choice->choicegroup % 5) + 1;
        echo "<p>Attempting to change choice #{$first_choice->choiceno} group from {$first_choice->choicegroup} to <b>$new_group</b></p>";
        
        $form_data = new stdClass();
        $form_data->id = $qid;
        $form_data->qtype = 'ddwtos';
        $form_data->name = $last_q->name . " (Test Save)";
        $form_data->questiontext = $last_q->questiontext;
        $form_data->defaultmark = $last_q->defaultmark;
        $form_data->category = $last_q->category;
        $form_data->shuffleanswers = 1;
        
        // Let's try different mapping styles together
        $i = 1;
        foreach ($choices as $c) {
            $group = ($c->id == $first_choice->id) ? $new_group : $c->choicegroup;
            
            // Moodle 4.x choice object format
            $choice_obj = new stdClass();
            $choice_obj->id = $c->id;
            $choice_obj->answer = $c->answer;
            $choice_obj->choicegroup = $group;
            $choice_obj->infinite = 0;

            $form_data->choices[$i] = $choice_obj;
            // Also top level arrays which some qtypes use
            $form_data->draglabel[$i] = $c->answer;
            $form_data->draggroup[$i] = $group;
            $form_data->choicegroup[$i] = $group;
            $form_data->infinite[$i] = 0;
            
            $i++;
        }

        try {
            $qtype_obj = question_bank::get_qtype('ddwtos');
            echo "<pre>Calling save_question_options with variety of formats...</pre>";
            
            // Re-clone and merge
            $question = clone $last_q;
            foreach ($form_data as $key => $value) {
                $question->$key = $value;
            }
            
            // We use the same method as ajax.php
            $qtype_obj->save_question_options($question);
            echo "<div class='alert alert-success'>save_question_options executed.</div>";
            
            // Verify
            $choices_after = $DB->get_records($choices_table, array($lookup_col => $qid));
            echo "<h4>Current Database State:</h4>";
            echo "<table class='table'>";
            echo "<tr><th>ID</th><th>No</th><th>Text</th><th>Group</th><th>Status</th></tr>";
            foreach ($choices_after as $c) {
                // Determine group property name (choicegroup or draggroup)
                $g = $c->choicegroup ?? ($c->draggroup ?? '?');
                $no = $c->choiceno ?? ($c->no ?? '?');
                
                $status = ($c->id == $first_choice->id) ? 
                          ($g == $new_group ? '<span class="badge badge-success">UPDATED!</span>' : '<span class="badge badge-danger">FAILED</span>') 
                          : '';
                echo "<tr><td>{$c->id}</td><td>{$no}</td><td>{$c->answer}</td><td>{$g}</td><td>$status</td></tr>";
            }
            echo "</table>";
            
        } catch (Exception $e) {
            echo "<div class='alert alert-danger'>ERROR: " . $e->getMessage() . "</div>";
        }
    }
    echo '<a href="diag_ddwtos.php" class="btn btn-secondary">Back</a>';
} else {
    // List questions
    echo "<h3>Recent DDWTOS Questions</h3>";
    $questions = $DB->get_records('question', array('qtype' => 'ddwtos'), 'id DESC', '*', 0, 10);
    
    if ($questions) {
        echo "<table class='table'>";
        echo "<tr><th>ID</th><th>Name</th><th>Choices & Groups</th><th>Actions</th></tr>";
        foreach ($questions as $q) {
            echo "<tr>";
            echo "<td>{$q->id}</td>";
            echo "<td>{$q->name}</td>";
            echo "<td>";
            $choices = $DB->get_records($choices_table, array($lookup_col => $q->id));
            if ($choices) {
                foreach ($choices as $c) {
                    $g = $c->choicegroup ?? ($c->draggroup ?? '?');
                    $no = $c->choiceno ?? ($c->no ?? '?');
                    echo "<div>#{$no}: <b>{$g}</b> - {$c->answer}</div>";
                }
            }
            echo "</td>";
            echo "<td>
                <a href='diag_ddwtos.php?action=inspect&qid={$q->id}' class='btn btn-info btn-sm'>Inspect Data</a>
                <a href='diag_ddwtos.php?action=testsave&qid={$q->id}' class='btn btn-primary btn-sm'>Test Toggle Group</a>
            </td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>No questions found.</p>";
    }
}

echo $OUTPUT->footer();
