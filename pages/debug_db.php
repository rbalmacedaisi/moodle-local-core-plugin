<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/local/grupomakro_core/pages/debug_db.php');
$PAGE->set_pagelayout('base');

echo $OUTPUT->header();

// 1. Find a valid student and plan to test
$student = $DB->get_record_sql("
    SELECT lpu.userid, lpu.learningplanid 
    FROM {local_learning_users} lpu 
    JOIN {user} u ON u.id = lpu.userid 
    WHERE lpu.userrolename = 'student' AND u.deleted = 0 
    LIMIT 1
");

if (!$student) {
    echo "No student found to test.";
    echo $OUTPUT->footer();
    die();
}

$userid = $student->userid;
$lpid = $student->learningplanid;

echo "<h2>Debugging Pensum for User $userid (Plan $lpid)</h2>";

// 2. Run the Query
$sql = "
    SELECT lpc.id, lpc.courseid, lpc.periodid, lpc.position
    FROM {local_learning_courses} lpc
    WHERE lpc.learningplanid = :lpid
    ORDER BY lpc.position ASC
";

echo "<h3>Query 1: Validating Master Plan Courses (local_learning_courses)</h3>";
echo "<pre>$sql</pre>";

$courses = $DB->get_records_sql($sql, ['lpid' => $lpid]);
echo "<p>Found " . count($courses) . " courses in the plan definition.</p>";

if (count($courses) > 0) {
    echo "<table class='table table-bordered'>";
    echo "<thead><tr><th>ID</th><th>Course ID</th><th>Period ID</th><th>Position</th></tr></thead>";
    foreach ($courses as $c) {
        echo "<tr>";
        echo "<td>{$c->id}</td>";
        echo "<td>{$c->courseid}</td>";
        echo "<td>{$c->periodid}</td>";
        echo "<td>{$c->position}</td>";
        echo "</tr>";
    }
    echo "</table>";
}

// 3. Run the Full Query with Left Join
echo "<h3>Query 2: Full Query (Left Join with Progress)</h3>";
$sql2 = "
    SELECT lpc.id, lpc.courseid, gcp.status, gcp.id as progressid
    FROM {local_learning_courses} lpc
    LEFT JOIN {gmk_course_progre} gcp ON (gcp.courseid = lpc.courseid AND gcp.userid = :userid AND gcp.learningplanid = :learningplanid)
    WHERE lpc.learningplanid = :lpid
    ORDER BY lpc.position ASC
";

echo "<pre>$sql2</pre>";
$results = $DB->get_records_sql($sql2, ['userid' => $userid, 'learningplanid' => $lpid, 'lpid' => $lpid]);
echo "<p>Found " . count($results) . " rows.</p>";

if (count($results) > 0) {
    echo "<table class='table table-bordered'>";
    echo "<thead><tr><th>LPC ID</th><th>Course ID</th><th>Status (Progress)</th><th>Progress ID</th></tr></thead>";
    foreach ($results as $r) {
        echo "<tr>";
        echo "<td>{$r->id}</td>";
        echo "<td>{$r->courseid}</td>";
        echo "<td>" . ($r->status ?? 'NULL') . "</td>";
        echo "<td>" . ($r->progressid ?? 'NULL') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

echo $OUTPUT->footer();
