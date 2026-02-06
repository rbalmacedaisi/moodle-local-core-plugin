<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

require_login();
$attemptid = optional_param('attemptid', 0, PARAM_INT);

echo "<h1>Quiz Grading Diagnostic</h1>";

if (!$attemptid) {
    echo "Please provide an attemptid. Example: ?attemptid=123";
    // List some recent finished attempts
    $recent = $DB->get_records_sql("SELECT qa.id, q.name, u.firstname, u.lastname, qa.state 
                                    FROM {quiz_attempts} qa 
                                    JOIN {quiz} q ON q.id = qa.quiz
                                    JOIN {user} u ON u.id = qa.userid
                                    WHERE qa.state = 'finished' 
                                    ORDER BY qa.timemodified DESC", [], 0, 10);
    echo "<h2>Recent Finished Attempts</h2><ul>";
    foreach ($recent as $r) {
        echo "<li><a href='?attemptid={$r->id}'>Attempt #{$r->id} - {$r->name} ({$r->firstname} {$r->lastname})</a> - Status: {$r->state}</li>";
    }
    echo "</ul>";
    die();
}

$attemptobj = quiz_attempt::create($attemptid);
$attempt = $attemptobj->get_attempt();

echo "<h2>Attempt #$attemptid info</h2>";
echo "<pre>";
print_r([
    'userid' => $attempt->userid,
    'quizid' => $attempt->quiz,
    'uniqueid' => $attempt->uniqueid,
    'state' => $attempt->state,
    'sumgrades' => $attempt->sumgrades
]);
echo "</pre>";

echo "<h2>Questions in Attempt</h2>";
echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
echo "<tr><th>Slot</th><th>Question ID</th><th>Name</th><th>State</th><th>Mark</th><th>Max Mark</th><th>Needs Grading?</th><th>Steps (Last 3)</th></tr>";

foreach ($attemptobj->get_slots() as $slot) {
    $qa = $attemptobj->get_question_attempt($slot);
    $question = $qa->get_question();
    $state = $qa->get_state();
    
    echo "<tr>";
    echo "<td>$slot</td>";
    echo "<td>{$question->id}</td>";
    echo "<td>" . s($question->name) . "</td>";
    echo "<td>$state</td>";
    echo "<td>" . ($qa->get_mark() ?? 'NULL') . "</td>";
    echo "<td>" . $qa->get_max_mark() . "</td>";
    echo "<td>" . ($state->is_finished() && !$state->is_graded() ? 'YES' : 'NO') . "</td>";
    
    // Last 3 steps
    echo "<td><ul style='font-size: 0.8em;'>";
    $steps = $DB->get_records('question_attempt_steps', ['questionattemptid' => $qa->get_database_id()], 'sequencenumber DESC', '*', 0, 3);
    foreach ($steps as $step) {
        echo "<li>Seq: {$step->sequencenumber}, State: {$step->state}, User: {$step->userid}, Time: " . date('Y-m-d H:i:s', $step->timecreated) . "</li>";
    }
    echo "</ul></td>";
    echo "</tr>";
}
echo "</table>";

echo "<h3>Check Pending Grading Query Logic</h3>";
$needs_grading = $DB->record_exists_sql("SELECT 1 FROM {question_attempts} qa 
                                         JOIN {question_attempt_steps} qas ON qas.questionattemptid = qa.id
                                         WHERE qa.questionusageid = ? AND qas.state = 'needsgrading'", [$attempt->uniqueid]);
echo "<p>Does this attempt have any 'needsgrading' steps? " . ($needs_grading ? "<b>YES</b> (System will show as Pending)" : "NO") . "</p>";

echo "<h3>Manual Grading Interface Simulation</h3>";
echo "<p>If you were to grade Slot 13 with 1 point:</p>";
$qa13 = $attemptobj->get_question_attempt(13);
if ($qa13) {
    echo "<pre>Prefix: " . $qa13->get_field_prefix() . "</pre>";
} else {
    echo "<p>Slot 13 not found in this attempt.</p>";
}
