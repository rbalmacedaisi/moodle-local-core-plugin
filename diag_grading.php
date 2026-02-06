<?php
/**
 * diag_grading.php
 * Advanced diagnostic tool to troubleshoot the grading interface.
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/datalib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

require_login();
$systemcontext = context_system::instance();
require_capability('moodle/site:config', $systemcontext); // Admin only for safety

$classid = optional_param('classid', 0, PARAM_INT);
$status = optional_param('status', 'pending', PARAM_ALPHA);

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
    if (!$class) {
        echo "<p style='color:red;'>Class $classid not found.</p>";
    } else {
        echo "<h4>Analyzing Class: " . s($class->name) . "</h4>";
        echo "<ul>";
        echo "<li>Course ID: {$class->courseid}</li>";
        echo "<li>Group ID: {$class->groupid}</li>";
        echo "</ul>";

        // 3. Database Integrity Checks
        echo "<h3>3. Data Integrity Checks</h3>";
        
        // Course check
        $course = $DB->get_record('course', ['id' => $class->courseid]);
        echo "Course: " . ($course ? "<span style='color:green;'>OK (" . s($course->fullname) . ")</span>" : "<span style='color:red;'>MISSING</span>") . "<br>";

        // Group check
        $group = $DB->get_record('groups', ['id' => $class->groupid]);
        echo "Group: " . ($group ? "<span style='color:green;'>OK (" . s($group->name) . ")</span>" : "<span style='color:red;'>MISSING</span>") . "<br>";

        // Group members
        $member_count = $DB->count_records('groups_members', ['groupid' => $class->groupid]);
        echo "Group Members: <span style='color:".($member_count > 0 ? 'green' : 'orange')."'>$member_count</span><br>";

        // Submissions in course
        $sub_count = $DB->count_records_sql("SELECT COUNT(*) FROM {assign_submission} s JOIN {assign} a ON a.id = s.assignment WHERE a.course = ?", [$class->courseid]);
        echo "Total Assignment Submissions in Course: $sub_count<br>";

        // Quiz attempts in course
        $quiz_count = $DB->count_records_sql("SELECT COUNT(*) FROM {quiz_attempts} quiza JOIN {quiz} q ON q.id = quiza.quiz WHERE q.course = ?", [$class->courseid]);
        echo "Total Quiz Attempts in Course: $quiz_count<br>";

        // 4. Trace the SQL Execution
        echo "<h3>4. SQL Trace (Pending Grading Items)</h3>";
        
        // Reset debug global
        $GLOBALS['GMK_DEBUG'] = [];
        $items = gmk_get_pending_grading_items($USER->id, $classid, $status);
        
        echo "<h4>Assignment Query:</h4>";
        echo "<pre style='background:#f4f4f4; padding:10px; border:1px solid #ccc; white-space: pre-wrap;'>" . s($GLOBALS['GMK_DEBUG']['sql_assign']) . "</pre>";
        echo "Params: <pre>" . json_encode($GLOBALS['GMK_DEBUG']['params_assign']) . "</pre>";
        
        $assign_raw = $DB->get_records_sql($GLOBALS['GMK_DEBUG']['sql_assign'], $GLOBALS['GMK_DEBUG']['params_assign']);
        echo "Raw results found: " . count($assign_raw) . "<br>";

        echo "<h4>Quiz Query:</h4>";
        echo "<pre style='background:#f4f4f4; padding:10px; border:1px solid #ccc; white-space: pre-wrap;'>" . s($GLOBALS['GMK_DEBUG']['sql_quiz']) . "</pre>";
        echo "Params: <pre>" . json_encode($GLOBALS['GMK_DEBUG']['params_quiz']) . "</pre>";
        
        $quiz_raw = $DB->get_records_sql($GLOBALS['GMK_DEBUG']['sql_quiz'], $GLOBALS['GMK_DEBUG']['params_quiz']);
        echo "Raw results found: " . count($quiz_raw) . "<br>";

        echo "<h3>5. Final Helper Result</h3>";
        echo "Items returned by gmk_get_pending_grading_items: " . count($items) . "<br>";
        if (!empty($items)) {
            echo "<table border='1' cellpadding='5'>";
            echo "<tr><th>Type</th><th>Name</th><th>Student</th><th>Time</th></tr>";
            foreach ($items as $item) {
                echo "<tr>";
                echo "<td>{$item->modname}</td>";
                echo "<td>" . s($item->itemname) . "</td>";
                echo "<td>" . s($item->firstname) . " " . s($item->lastname) . "</td>";
                echo "<td>" . date('Y-m-d H:i', $item->submissiontime) . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        }

        // 6. Global Search for English II
        echo "<h3>6. Global Search for 'INGLÉS II'</h3>";
        $matching_courses = $DB->get_records_sql("SELECT id, fullname, shortname FROM {course} WHERE fullname LIKE '%INGLÉS II%' OR shortname LIKE '%INGLÉS II%'");
        echo "<h4>Courses matching 'INGLÉS II':</h4>";
        if ($matching_courses) {
            echo "<ul>";
            foreach ($matching_courses as $mc) {
                $count_sub = $DB->count_records_sql("SELECT COUNT(*) FROM {assign_submission} s JOIN {assign} a ON a.id = s.assignment WHERE a.course = ?", [$mc->id]);
                $count_quiz = $DB->count_records_sql("SELECT COUNT(*) FROM {quiz_attempts} quiza JOIN {quiz} q ON q.id = quiza.quiz WHERE q.course = ?", [$mc->id]);
                echo "<li>ID: {$mc->id} - " . s($mc->fullname) . " (Submissions: $count_sub, Quizzes: $count_quiz)</li>";
            }
            echo "</ul>";
        } else {
            echo "<p>No courses found with that name.</p>";
        }

        $matching_classes = $DB->get_records_sql("SELECT id, name, courseid, groupid FROM {gmk_class} WHERE name LIKE '%INGLÉS II%'");
        echo "<h4>Classes matching 'INGLÉS II' in {gmk_class}:</h4>";
        if ($matching_classes) {
            echo "<ul>";
            foreach ($matching_classes as $mcl) {
                echo "<li>ID: {$mcl->id} - " . s($mcl->name) . " (Mapped to Course: {$mcl->courseid}, Group: {$mcl->groupid})</li>";
            }
            echo "</ul>";
        } else {
            echo "<p>No classes found in {gmk_class} with that name.</p>";
        }

        // 7. Search for specific Quiz from screenshot
        echo "<h3>7. Search for 'Cuestionario de prueba 1'</h3>";
        $quizzes = $DB->get_records_sql("SELECT q.*, c.fullname as coursename FROM {quiz} q JOIN {course} c ON c.id = q.course WHERE q.name LIKE '%Cuestionario de prueba 1%'");
        if ($quizzes) {
            echo "<ul>";
            foreach ($quizzes as $qz) {
                echo "<li>Quiz ID: {$qz->id} - Name: " . s($qz->name) . " - <b>Actual Course ID: {$qz->course}</b> (" . s($qz->coursename) . ")</li>";
            }
            echo "</ul>";
        } else {
            echo "<p>Quiz not found.</p>";
        }
    }
} else {
    echo "<p>Please select a class from the list above or enter a Quiz Attempt ID below:</p>";
}

// 8. Quiz Attempt Inspector (Consolidated from diag_quiz_grading)
echo "<hr><h3>8. Specific Quiz Attempt Inspector</h3>";
$attemptid = optional_param('attemptid', 0, PARAM_INT);
echo "<form method='GET'>";
echo "Attempt ID: <input type='number' name='attemptid' value='$attemptid'> ";
echo "<button type='submit'>Inspect Attempt</button>";
echo "</form>";

if ($attemptid) {
    echo "<h4>Analyzing Attempt #$attemptid</h4>";
    try {
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        $attemptobj = quiz_attempt::create($attemptid);
        $attempt = $attemptobj->get_attempt();
        
        echo "<pre>";
        print_r([
            'userid' => $attempt->userid,
            'quizid' => $attempt->quiz,
            'uniqueid' => $attempt->uniqueid,
            'state' => $attempt->state,
            'sumgrades' => $attempt->sumgrades
        ]);
        echo "</pre>";

        echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
        echo "<tr><th>Slot</th><th>Question ID</th><th>Name</th><th>State</th><th>Mark</th><th>Max Mark</th><th>Needs Grading?</th><th>Steps (Last 3)</th></tr>";

        foreach ($attemptobj->get_slots() as $slot) {
            $qa = $attemptobj->get_question_attempt($slot);
            $question = $qa->get_question();
            $state = $qa->get_state();
            
            echo "<tr>";
            echo "<td>$slot</td><td>{$question->id}</td><td>" . s($question->name) . "</td>";
            echo "<td>$state</td><td>" . ($qa->get_mark() ?? 'NULL') . "</td><td>" . $qa->get_max_mark() . "</td>";
            echo "<td>" . ($state->is_finished() && !$state->is_graded() ? 'YES' : 'NO') . "</td>";
            
            echo "<td><ul style='font-size: 0.8em;'>";
            $steps = $DB->get_records('question_attempt_steps', ['questionattemptid' => $qa->get_database_id()], 'sequencenumber DESC', '*', 0, 3);
            foreach ($steps as $step) {
                echo "<li>Seq: {$step->sequencenumber}, State: {$step->state}, User: {$step->userid}, Time: " . date('Y-m-d H:i:s', $step->timecreated) . "</li>";
            }
            echo "</ul></td></tr>";
        }
        echo "</table>";
    } catch (Exception $e) {
        echo "<p style='color:red;'>Error: " . $e->getMessage() . "</p>";
    }
}

echo $OUTPUT->footer();
