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
$userid = optional_param('userid', 0, PARAM_INT);
$searchuser = optional_param('searchuser', '', PARAM_TEXT);

echo "<div style='background:#f9f9f9; padding:15px; border:1px solid #ddd; border-radius:8px; margin-bottom:20px;'>";
echo "<h3>Simulate Instructor</h3>";
echo "<form method='GET' style='margin-bottom: 10px;'>";
echo "Search Instructor Name/Email: <input type='text' name='searchuser' value='".s($searchuser)."' placeholder='Name...'> ";
echo "<button type='submit'>Search</button>";
echo "</form>";

if ($searchuser) {
    $found_users = $DB->get_records_sql("SELECT id, firstname, lastname, email FROM {user} WHERE (firstname LIKE :s1 OR lastname LIKE :s2 OR email LIKE :s3) AND deleted = 0 LIMIT 10", 
        ['s1' => "%$searchuser%", 's2' => "%$searchuser%", 's3' => "%$searchuser%"]);
    if ($found_users) {
        echo "<ul>";
        foreach ($found_users as $u) {
            echo "<li><a href='?userid={$u->id}'><b>Select:</b> " . fullname($u) . " ({$u->email}) [ID: {$u->id}]</a></li>";
        }
        echo "</ul>";
    } else {
        echo "<p>No users found matching '$searchuser'.</p>";
    }
}

// Automatically pick common instructors if none selected
if (!$userid) {
    $top_instructors = $DB->get_records_sql("SELECT DISTINCT instructorid FROM {gmk_class} LIMIT 5");
    if ($top_instructors) {
        echo "<p>Quick select (Instructors in gmk_class): ";
        foreach ($top_instructors as $ti) {
            $u = $DB->get_record('user', ['id' => $ti->instructorid]);
            if ($u) echo " <a href='?userid={$u->id}'>[" . fullname($u) . "]</a> ";
        }
        echo "</p>";
    }
}
echo "</div>";

if (!$userid) {
    echo "<p style='padding:20px; background:#fff3cd; color:#856404; border:1px solid #ffeeba;'>Please select an instructor above to start the diagnostic.</p>";
} else {
    $target_user = $DB->get_record('user', ['id' => $userid]);
    if (!$target_user) {
        echo "<p style='color:red;'>User $userid not found.</p>";
    } else {
        echo "<h3>Checking for User: " . fullname($target_user) . " (ID: $userid)</h3>";
        $is_target_admin = is_siteadmin($userid);
        echo "<p>Site Admin: " . ($is_target_admin ? '<b style="color:blue;">YES</b> (Will see everything)' : 'NO (Restricted to assigned classes)') . "</p>";
    }
}

// 1. Check Classes for this instructor
$classes = $DB->get_records('gmk_class', ['instructorid' => $userid]);
echo "<h4>Active Classes for this Instructor in {gmk_class}:</h4>";
if (empty($classes)) {
    echo "<p style='color:red;'>No entries found in <b>gmk_class</b> where instructorid = $userid.</p>";
} else {
    echo "<table border='1' cellpadding='5'><tr><th>ID</th><th>Name</th><th>Course ID</th><th>Group ID</th><th>Closed</th><th>End Date</th></tr>";
    foreach ($classes as $class) {
        $past = $class->enddate < time() ? ' (EXPIRED)' : '';
        echo "<tr>";
        echo "<td>{$class->id}</td>";
        echo "<td>" . s($class->name) . "</td>";
        echo "<td>{$class->courseid}</td>";
        echo "<td>{$class->groupid}</td>";
        echo "<td>{$class->closed}</td>";
        echo "<td>" . date('Y-m-d', $class->enddate) . $past . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

// 2. Test the combined helper
echo "<h3>Result of gmk_get_pending_grading_items($userid):</h3>";
$items = gmk_get_pending_grading_items($userid);
if (empty($items)) {
    echo "<p style='color:orange;'>The helper returned 0 items for this user.</p>";
} else {
    echo "<h4>Items Found (" . count($items) . "):</h4>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Type</th><th>Name</th><th>Course ID</th><th>Student</th><th>Submission Time</th></tr>";
    foreach ($items as $item) {
        echo "<tr>";
        echo "<td>{$item->modname}</td>";
        echo "<td>" . s($item->itemname) . "</td>";
        echo "<td>{$item->courseid}</td>";
        echo "<td>" . s($item->firstname) . " " . s($item->lastname) . "</td>";
        echo "<td>" . date('Y-m-d H:i:s', $item->submissiontime) . "</td>";
        echo "</tr>";
    }
    echo "</table>";

    // Simulate the JSON that the external API would return
    echo "<h4>Simulated JSON response for local_grupomakro_get_pending_grading:</h4>";
    require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/teacher/get_pending_grading.php');
    $result = \local_grupomakro_core\external\teacher\get_pending_grading::execute($userid, 0);
    echo "<pre style='background:#eee; padding:10px; max-height:200px; overflow:auto;'>";
    echo json_encode($result, JSON_PRETTY_PRINT);
    echo "</pre>";
}

// 3. Reverse lookup: Who are the instructors for courses with pending quizzes?
echo "<h3>Reverse Lookup: Instructors for Pending Quiz Courses</h3>";
$pending_quiz_courses = $DB->get_records_sql("
    SELECT DISTINCT q.course, c.fullname
    FROM {quiz_attempts} quiza
    JOIN {quiz} q ON q.id = quiza.quiz
    JOIN {course} c ON c.id = q.course
    WHERE quiza.state = 'finished'
      AND EXISTS (
          SELECT 1 FROM {question_attempts} qa 
          JOIN {question_attempt_steps} qas ON qas.questionattemptid = qa.id
          WHERE qa.questionusageid = quiza.uniqueid AND qas.state = 'needsgrading'
      )
");

if (empty($pending_quiz_courses)) {
    echo "<p>No courses found with pending quizzes in the entire system.</p>";
} else {
    foreach ($pending_quiz_courses as $pc) {
        echo "<h4>Course: " . s($pc->fullname) . " (ID: {$pc->course})</h4>";
        
        echo "<p><b>Moodle Roles for this User (Shortnames):</b> ";
        if ($user_roles) {
            foreach ($user_roles as $ur) {
                echo "<span style='padding:2px 5px; background:#e1f5fe; border:1px solid #01579b; border-radius:4px; margin-right:5px; font-family:monospace;'>{$ur->shortname}</span>";
            }
        } else {
            echo "<span style='color:red;'>NONE</span>";
        }
        echo "</p>";

        $instructors = $DB->get_records('gmk_class', ['courseid' => $pc->course]);
        if (empty($instructors)) {
            echo "<p style='color:red;'>No instructors assigned to this course in <b>gmk_class</b> table!</p>";
        } else {
            echo "Instructors in gmk_class for this course:<ul>";
            foreach ($instructors as $inst) {
                $u = $DB->get_record('user', ['id' => $inst->instructorid]);
                echo "<li>" . fullname($u) . " (ID: {$inst->instructorid}) - Class ID: {$inst->id}</li>";
            }
            echo "</ul>";
        }
    }
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
