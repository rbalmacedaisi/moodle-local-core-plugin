<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/question/engine/lib.php');

require_login();
$attemptid = required_param('attemptid', PARAM_INT);
$slot = required_param('slot', PARAM_INT);
$mark = optional_param('mark', 1.0, PARAM_FLOAT);
$mode = optional_param('mode', 'colon', PARAM_ALPHA); // 'colon' or 'hyphen'

$attemptobj = quiz_attempt::create($attemptid);
$qa = $attemptobj->get_question_attempt($slot);
$prefix = $qa->get_field_prefix();

echo "<h1>Grading Test (Mode: $mode)</h1>";
echo "Attempt: $attemptid, Slot: $slot, Prefix: $prefix<br>";

$separator = ($mode === 'colon') ? ':' : '-';
$data = array(
    $prefix . $separator . 'mark' => $mark,
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
    if ($qa_reloaded->get_mark() == $mark) {
        echo "<h2 style='color:green;'>VERIFIED: $mode works!</h2>";
    } else {
        echo "<h2 style='color:red;'>FAILED: $mode did NOT save.</h2>";
    }
} catch (Exception $e) {
    echo "<p style='color:red;'>Error: " . $e->getMessage() . "</p>";
}

echo "<hr><a href='?attemptid=$attemptid&slot=$slot&mark=$mark&mode=colon'>Try Colon (:)</a> | ";
echo "<a href='?attemptid=$attemptid&slot=$slot&mark=$mark&mode=hyphen'>Try Hyphen (-)</a>";
