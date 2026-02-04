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
        
        // Mandatory fields to avoid SQL "cannot be null" errors
        $question->correctfeedback = "";
        $question->correctfeedbackformat = FORMAT_HTML;
        $question->partiallycorrectfeedback = "";
        $question->partiallycorrectfeedbackformat = FORMAT_HTML;
        $question->incorrectfeedback = "";
        $question->incorrectfeedbackformat = FORMAT_HTML;
        $question->shownumcorrect = 1;

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
            foreach($ans as $a) {
                echo " - Answer: {$a->answer}, Feedback: " . htmlspecialchars($a->feedback) . "<br>";
            }
        } else {
            echo "<span style='color:red;'>FAILED</span> format '$name' did not save answers.<br>";
        }
        
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "<br>";
    }
}

// Variation 1: $form_data->choices with 'choicegroup' (Notice suggested this)
test_save("form_data_choices_choicegroup", [], [
    'choices' => [
        ['answer' => 'Alpha', 'choicegroup' => 1, 'infinite' => 0],
        ['answer' => 'Beta', 'choicegroup' => 1, 'infinite' => 0]
    ]
]);

// Variation 2: $form_data->choices with 'draggroup' (Standard for some versions)
test_save("form_data_choices_draggroup", [], [
    'choices' => [
        ['answer' => 'Gamma', 'draggroup' => 1, 'infinite' => 0],
        ['answer' => 'Delta', 'draggroup' => 1, 'infinite' => 0]
    ]
]);

// Variation 4: Native Form Format (choice[0][answer], choice[0][choicegroup])
$native_form = [
    'choice' => [
        0 => ['answer' => 'Epsilon', 'choicegroup' => 1],
        1 => ['answer' => 'Zeta', 'choicegroup' => 1]
    ]
];
test_save("form_data_choice_array", [], $native_form);

echo "<br><br><a href='?'>Run Again</a>";
