<?php
/**
 * diag_grading.php
 * Consolidated diagnostic tool for grading and activity tracking.
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/datalib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

require_login();
$systemcontext = context_system::instance();
require_capability('moodle/site:config', $systemcontext); // Admin only

$classid = optional_param('classid', 0, PARAM_INT);
$status = optional_param('status', 'pending', PARAM_ALPHA);

$PAGE->set_url(new moodle_url('/local/grupomakro_core/diag_grading.php', ['classid' => $classid, 'status' => $status]));
$PAGE->set_context($systemcontext);
$PAGE->set_title("Grading Diagnostic");

echo $OUTPUT->header();

echo "<h2>Grading Interface Diagnostic</h2>";

// 1. User Info
echo "<h3>1. Current User Context</h3>";
echo "<ul>";
echo "<li>Name: " . fullname($USER) . "</li>";
echo "<li>ID: " . $USER->id . "</li>";
echo "<li>Is Admin: " . (is_siteadmin() ? 'YES' : 'NO') . "</li>";
echo "</ul>";

// 2. Class Selection
$classes = $DB->get_records('gmk_class', ['instructorid' => $USER->id]);
if (is_siteadmin()) {
    $classes = $DB->get_records('gmk_class', [], 'id DESC', '*', 0, 20);
}

echo "<h3>2. Select Class to Analyze</h3>";
echo "<form method='GET'>";
echo "<select name='classid'>";
echo "<option value='0'>-- Global (No filter) --</option>";
foreach ($classes as $c) {
    $sel = ($c->id == $classid) ? 'selected' : '';
    echo "<option value='{$c->id}' $sel>{$c->id} - " . s($c->name) . "</option>";
}
echo "</select> ";
echo "Status: <select name='status'>";
echo "<option value='pending' " . ($status == 'pending' ? 'selected' : '') . ">Pending</option>";
echo "<option value='history' " . ($status == 'history' ? 'selected' : '') . ">History</option>";
echo "</select> ";
echo "<button type='submit'>Analyze</button>";
echo "</form>";

if ($classid > 0) {
    $class = $DB->get_record('gmk_class', ['id' => $classid]);
    if ($class) {
        echo "<h4>Analyzing Class: " . s($class->name) . "</h4>";
        echo "<ul><li>Course ID: {$class->courseid}</li><li>Group ID: {$class->groupid}</li></ul>";

        echo "<h3>3. Data Integrity Checks</h3>";
        $course = $DB->get_record('course', ['id' => $class->courseid]);
        echo "Course: " . ($course ? "OK (" . s($course->fullname) . ")" : "MISSING") . "<br>";
        $group = $DB->get_record('groups', ['id' => $class->groupid]);
        echo "Group: " . ($group ? "OK (" . s($group->name) . ")" : "MISSING") . "<br>";
        $member_count = $DB->count_records('groups_members', ['groupid' => $class->groupid]);
        echo "Group Members: $member_count<br>";

        echo "<h3>4. SQL Trace (Pending Grading Items)</h3>";
        $GLOBALS['GMK_DEBUG'] = [];
        $items = gmk_get_pending_grading_items($USER->id, $classid, $status);
        
        echo "<h4>Assignment Query:</h4><pre>" . s($GLOBALS['GMK_DEBUG']['sql_assign'] ?? 'N/A') . "</pre>";
        echo "<h4>Quiz Query:</h4><pre>" . s($GLOBALS['GMK_DEBUG']['sql_quiz'] ?? 'N/A') . "</pre>";

        echo "<h3>5. Final Helper Result</h3>";
        echo "Items returned: " . count($items) . "<br>";
        if (!empty($items)) {
            echo "<table border='1' cellpadding='5'>";
            echo "<tr><th>ID</th><th>Type</th><th>Name</th><th>Student</th><th>Time</th></tr>";
            foreach ($items as $item) {
                echo "<tr><td>{$item->submissionid}</td><td>{$item->modname}</td><td>" . s($item->itemname) . "</td><td>" . s($item->studentname ?? '') . "</td><td>" . date('Y-m-d H:i', $item->submissiontime) . "</td></tr>";
            }
            echo "</table>";
        }
    }
}

// 8. Quiz Attempt Inspector
echo "<hr><h3>8. Specific Quiz Attempt Inspector</h3>";
$attemptid = optional_param('attemptid', 0, PARAM_INT);
echo "<form method='GET'><input type='number' name='attemptid' value='$attemptid'> <button type='submit'>Inspect Attempt</button></form>";

if ($attemptid) {
    try {
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        $attemptobj = quiz_attempt::create($attemptid);
        $attempt = $attemptobj->get_attempt();
        echo "<h4>Analyzing Attempt #$attemptid</h4><pre>";
        print_r(['attemptid' => $attempt->id,'userid' => $attempt->userid,'state' => $attempt->state,'sumgrades' => $attempt->sumgrades]);
        echo "</pre>";
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>Slot</th><th>QID</th><th>Name</th><th>State</th><th>Mark</th><th>Max</th><th>Needs?</th></tr>";
        foreach ($attemptobj->get_slots() as $slot) {
            $qa = $attemptobj->get_question_attempt($slot);
            $q = $qa->get_question();
            $state = $qa->get_state();
            echo "<tr><td>$slot</td><td>{$q->id}</td><td>".s($q->name)."</td><td>$state</td><td>".$qa->get_mark()."</td><td>".$qa->get_max_mark()."</td><td>".($state->is_finished() && !$state->is_graded() ? 'YES' : 'NO')."</td></tr>";
        }
        echo "</table>";
    } catch (Exception $e) { echo "Error: " . $e->getMessage(); }
}

echo $OUTPUT->footer();
