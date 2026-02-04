<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/questionlib.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

echo "<h1>DDWTOS Correct Saving Test</h1>";

function test_save($name, $q_data, $f_data) {
    global $DB, $USER;
    echo "<h2>Testing: $name</h2>";
    
    try {
        $category = $DB->get_record_sql("SELECT id FROM {question_categories} LIMIT 1");
        
        $question = new stdClass();
        $question->category = $category->id;
        $question->name = "Test $name " . time();
        $question->questiontext = "Test text [[1]] [[2]]";
        $question->questiontextformat = FORMAT_HTML;
        $question->generalfeedback = "";
        $question->generalfeedbackformat = FORMAT_HTML;
        $question->defaultmark = 1;
        $question->penalty = 0.33;
        $question->qtype = 'ddwtos';
        
        // Merge in specific test data
        foreach ($q_data as $key => $val) {
            $question->$key = $val;
        }

        $form_data = clone $question;
        foreach ($f_data as $key => $val) {
            $form_data->$key = $val;
        }

        $qtypeobj = question_bank::get_qtype('ddwtos');
        $newq = $qtypeobj->save_question($question, $form_data);
        
        echo "Question saved. ID: {$newq->id}<br>";
        
        $ans_count = $DB->count_records('question_answers', ['question' => $newq->id]);
        echo "Records in question_answers: $ans_count<br>";
        
        if ($ans_count > 0) {
            echo "<span style='color:green;font-weight:bold;'>SUCCESS!</span> format '$name' works.<br>";
            $ans = $DB->get_records('question_answers', ['question' => $newq->id]);
            echo "<pre>" . htmlspecialchars(print_r($ans, true)) . "</pre>";
        } else {
            echo "<span style='color:red;'>FAILED</span> format '$name' did not save answers.<br>";
        }
        
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "<br>";
    }
}

// Variation 1: $question->choices (Array of arrays)
test_save("question_choices_array", [
    'choices' => [
        ['answer' => 'Alpha', 'draggroup' => 1, 'infinite' => 0],
        ['answer' => 'Beta', 'draggroup' => 1, 'infinite' => 0]
    ]
], []);

// Variation 2: $form_data->choices (Matching Moodle Form)
test_save("form_data_choices_nested", [], [
    'choices' => [
        ['answer' => 'Gamma', 'draggroup' => 1],
        ['answer' => 'Delta', 'draggroup' => 1]
    ]
]);

// Variation 3: Flattened (Moodle 2.x/3.x style sometimes)
test_save("flattened_draglabel", [], [
    'draglabel' => ['Epsilon', 'Zeta'],
    'draggroup' => [1, 1],
    'infinite' => [0, 0]
]);

echo "<br><br><a href='?'>Run Again</a>";
