<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/question/engine/lib.php');

require_login();

$attemptid = optional_param('attemptid', 0, PARAM_INT);
$slot = optional_param('slot', 0, PARAM_INT);

echo "<h1>Quiz Grading Test Hub</h1>";

if (!$attemptid || !$slot) {
    echo "<p>Please provide <b>attemptid</b> and <b>slot</b> in the URL, or select a recent finished attempt below:</p>";
    $recent = $DB->get_records_sql("SELECT qa.id, q.name, u.firstname, u.lastname, qa.state 
                                    FROM {quiz_attempts} qa 
                                    JOIN {quiz} q ON q.id = qa.quiz
                                    JOIN {user} u ON u.id = qa.userid
                                    WHERE qa.state = 'finished' 
                                    ORDER BY qa.timemodified DESC", [], 0, 10);
    echo "<ul>";
    foreach ($recent as $r) {
        echo "<li>Attempt #{$r->id} - {$r->name} (User: {$r->firstname} {$r->lastname}) ";
        echo "[<a href='diag_quiz_grading.php?attemptid={$r->id}'>Inspect Slots</a>]</li>";
    }
    echo "</ul>";
    echo "<p><i>Note: Once you find the slot ID in the Inspector, come back and use ?attemptid=X&slot=Y</i></p>";
    die();
}

$mark = optional_param('mark', 1.0, PARAM_FLOAT);
$mode = optional_param('mode', 'colon', PARAM_ALPHA); // 'colon', 'hyphen', or 'none'

$attemptobj = quiz_attempt::create($attemptid);
$qa = $attemptobj->get_question_attempt($slot);
$prefix = $qa->get_field_prefix();

echo "<h1>Grading Test (Mode: $mode)</h1>";
echo "Attempt: $attemptid, Slot: $slot, Prefix: $prefix<br>";

if ($mode === 'colon') {
    $separator = ':';
} else if ($mode === 'hyphen') {
    $separator = '-';
} else {
    $separator = '';
}

$fieldname = $prefix . $separator . 'mark';
echo "Target Field Name: <b>$fieldname</b><br>";

$data = array(
    $fieldname => $mark,
    $prefix . $separator . 'comment' => "Test grading with $mode at " . date('Y-m-d H:i:s'),
    $prefix . $separator . 'commentformat' => FORMAT_HTML
);

echo "Data to process:<pre>";
print_r($data);
echo "</pre>";

try {
    $attemptobj->process_submitted_actions(time(), false, $data);
    echo "<p style='color:green;'>Process Success!</p>";
    
    // Check if it saved by reloading
    $attemptobj_reloaded = quiz_attempt::create($attemptid);
    $qa_reloaded = $attemptobj_reloaded->get_question_attempt($slot);
echo "Reloaded Mark: " . $qa_reloaded->get_mark() . "<br>";
    echo "Reloaded State: " . (string)$qa_reloaded->get_state() . "<br>";
    
    // Debug: show the last step details
    $laststep = $qa_reloaded->get_last_step();
    echo "Last Step State: " . $laststep->get_state() . "<br>";
    echo "Last Step Fraction: " . $laststep->get_fraction() . "<br>";
    echo "Last Step Data: <pre>";
    print_r($laststep->get_all_data());
    echo "</pre>";

    if ($qa_reloaded->get_mark() == $mark) {
        echo "<h2 style='color:green;'>VERIFIED: $mode works!</h2>";
    } else {
        echo "<h2 style='color:red;'>FAILED: $mode did NOT save.</h2>";
        echo "<p>Try the other mode using the links below.</p>";
    }
} catch (Exception $e) {
    echo "<p style='color:red;'>Error: " . $e->getMessage() . "</p>";
}

echo "<hr><a href='?attemptid=$attemptid&slot=$slot&mark=$mark&mode=colon'>Try Colon (:)</a> | ";
echo "<a href='?attemptid=$attemptid&slot=$slot&mark=$mark&mode=hyphen'>Try Hyphen (-)</a> | ";
echo "<a href='?attemptid=$attemptid&slot=$slot&mark=$mark&mode=none'>Try None (Direct)</a>";
