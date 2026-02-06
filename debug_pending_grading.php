<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/datalib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

require_login();
$systemcontext = context_system::instance();
require_capability('moodle/site:config', $systemcontext);

$PAGE->set_context($systemcontext);
$PAGE->set_url(new moodle_url('/local/grupomakro_core/debug_pending_grading.php'));

echo $OUTPUT->header();
echo "<h1>Diagnostic: Pending Grading Items</h1>";

$userid = optional_param('userid', $USER->id, PARAM_INT);
echo "<h3>Checking for User ID: $userid</h3>";

// 1. Check Classes for this instructor
$classes = $DB->get_records('gmk_class', ['instructorid' => $userid]);
echo "<h4>Active Classes for this Instructor in {gmk_class}:</h4>";
if (empty($classes)) {
    echo "<p style='color:red;'>No classes found for this instructor in gmk_class table.</p>";
} else {
    echo "<table border='1'><tr><th>ID</th><th>Name</th><th>Course ID</th><th>Group ID</th></tr>";
    foreach ($classes as $class) {
        echo "<tr>";
        echo "<td>{$class->id}</td>";
        echo "<td>" . s($class->name) . "</td>";
        echo "<td>{$class->courseid}</td>";
        echo "<td>{$class->groupid}</td>";
        echo "</tr>";
    }
    echo "</table>";
}

// 2. Test the combined helper
echo "<h3>Result of gmk_get_pending_grading_items($userid):</h3>";
$items = gmk_get_pending_grading_items($userid);
if (empty($items)) {
    echo "<p style='color:orange;'>The helper returned 0 items.</p>";
} else {
    echo "<h4>Items Found:</h4>";
    echo "<table border='1'>";
    echo "<tr><th>Type</th><th>Name</th><th>Student</th><th>Submission Time</th></tr>";
    foreach ($items as $item) {
        echo "<tr>";
        echo "<td>{$item->modname}</td>";
        echo "<td>" . s($item->itemname) . "</td>";
        echo "<td>" . s($item->firstname) . " " . s($item->lastname) . "</td>";
        echo "<td>" . date('Y-m-d H:i:s', $item->submissiontime) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

// 3. Deep Dive into Quiz Logic
echo "<h3>Raw Quiz Query Deep Dive</h3>";
// Get all finished quiz attempts in the system to see if ANY match
$raw_quizzes = $DB->get_records_sql("
    SELECT quiza.id, quiza.userid, quiza.uniqueid, quiza.quiz, quiza.state, q.name as quizname, q.course
    FROM {quiz_attempts} quiza
    JOIN {quiz} q ON q.id = quiza.quiz
    WHERE quiza.state = 'finished'
    ORDER BY quiza.timefinish DESC
    LIMIT 20
");

if (empty($raw_quizzes)) {
    echo "<p>No finished quiz attempts found in the system.</p>";
} else {
    echo "<h4>Recent Finished Quiz Attempts (System-wide):</h4>";
    echo "<table border='1'>";
    echo "<tr><th>ID</th><th>Course ID</th><th>User</th><th>Quiz</th><th>State</th><th>Needs Grading?</th><th>Instructor Match?</th></tr>";
    foreach ($raw_quizzes as $qa) {
        $needs_grading = $DB->record_exists_sql("
            SELECT 1 FROM {question_attempts} qa 
            JOIN {question_attempt_steps} qas ON qas.questionattemptid = qa.id
            WHERE qa.questionusageid = :uniqueid 
              AND qas.state = 'needsgrading'
        ", ['uniqueid' => $qa->uniqueid]);
        
        $instructor_match = $DB->record_exists('gmk_class', ['courseid' => $qa->course, 'instructorid' => $userid]);
        
        echo "<tr>";
        echo "<td>{$qa->id}</td>";
        echo "<td>{$qa->course}</td>";
        echo "<td>{$qa->userid}</td>";
        echo "<td>" . s($qa->quizname) . "</td>";
        echo "<td>{$qa->state}</td>";
        echo "<td>" . ($needs_grading ? '<b style="color:green;">YES</b>' : 'NO') . "</td>";
        echo "<td>" . ($instructor_match ? '<b style="color:green;">YES</b>' : 'NO') . "</td>";
        echo "</tr>";
        
        if ($needs_grading) {
            // Let's show the steps for this needsgrading attempt
            $steps = $DB->get_records_sql("
                SELECT qas.id, qas.state, qas.fraction, qas.sequencenumber
                FROM {question_attempts} qa
                JOIN {question_attempt_steps} qas ON qas.questionattemptid = qa.id
                WHERE qa.questionusageid = :uniqueid
                ORDER BY qa.id, qas.sequencenumber ASC
            ", ['uniqueid' => $qa->uniqueid]);
            // (Optional: filter only needsgrading for brevity if many)
        }
    }
    echo "</table>";
}

echo $OUTPUT->footer();
