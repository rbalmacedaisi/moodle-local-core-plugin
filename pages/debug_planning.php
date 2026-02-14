<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/planning_manager.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_url('/local/grupomakro_core/pages/debug_planning.php');
$PAGE->set_context($context);
$PAGE->set_title('Debug Academic Planning');
$PAGE->set_heading('Debug Academic Planning Data');

echo $OUTPUT->header();

use local_grupomakro_core\local\planning_manager;

// 1. Check Course Structure & Prereqs
echo "<h3>1. Curricula Structure & Prerequisites</h3>";
$preField = $DB->get_record('customfield_field', ['shortname' => 'pre']);
if (!$preField) {
    echo "<p style='color:red'>WARNING: Course custom field 'pre' NOT FOUND!</p>";
} else {
    echo "<p>Found course custom field 'pre' (ID: $preField->id)</p>";
}

$sql = "SELECT c.id, c.fullname, c.shortname, c.idnumber, cfd.value as prereq_raw
        FROM {course} c
        LEFT JOIN {customfield_data} cfd ON cfd.instanceid = c.id AND cfd.fieldid = " . ($preField->id ?? 0) . "
        JOIN {local_learning_courses} lpc ON lpc.courseid = c.id
        GROUP BY c.id, c.fullname, c.shortname, c.idnumber, cfd.value";
$courseData = $DB->get_records_sql($sql);

echo "<table border='1' cellpadding='5' style='border-collapse:collapse; width:100%'>";
echo "<tr><th>ID</th><th>Fullname</th><th>Shortname</th><th>ID Number</th><th>Prereq (Raw)</th><th>Resolved IDs</th></tr>";

// Resolved map for display
$allCourses = $DB->get_records('course', [], '', 'shortname, id');
$shortnameToId = [];
foreach ($allCourses as $c) {
    $shortnameToId[trim($c->shortname)] = $c->id;
}

foreach ($courseData as $c) {
    $resolved = [];
    if (!empty($c->prereq_raw)) {
        $shorts = explode(',', $c->prereq_raw);
        foreach ($shorts as $s) {
            $s = trim($s);
            if (isset($shortnameToId[$s])) {
                $resolved[] = "$s (ID: " . $shortnameToId[$s] . ")";
            } else {
                $resolved[] = "<span style='color:red'>$s (NOT FOUND)</span>";
            }
        }
    }
    echo "<tr>
            <td>$c->id</td>
            <td>$c->fullname</td>
            <td>$c->shortname</td>
            <td>$c->idnumber</td>
            <td>" . ($c->prereq_raw ?: '<i>None</i>') . "</td>
            <td>" . (implode(', ', $resolved) ?: '<i>None</i>') . "</td>
          </tr>";
}
echo "</table>";

// 2. Sample Student Analysis
$studentId = optional_param('userid', 0, PARAM_INT);
echo "<h3>2. Student Analysis (Status & Approved)</h3>";
echo "<form method='GET'>User ID: <input type='number' name='userid' value='$studentId'> <input type='submit' value='Analyze'></form>";

if ($studentId) {
    $user = $DB->get_record('user', ['id' => $studentId]);
    if (!$user) {
        echo "<p style='color:red'>User $studentId not found.</p>";
    } else {
        echo "<h4>Analyzing: $user->firstname $user->lastname ($user->username)</h4>";
        
        // Approved courses
        $approved_sql = "SELECT cp.courseid, c.fullname, cp.status, cp.timemodified
                         FROM {gmk_course_progre} cp
                         JOIN {course} c ON c.id = cp.courseid
                         WHERE cp.userid = :uid AND cp.status >= 3";
        $approved = $DB->get_records_sql($approved_sql, ['uid' => $studentId]);
        
        echo "<h5>Approved Courses (gmk_course_progre):</h5>";
        if (empty($approved)) {
            echo "<p>No approved courses found in custom table.</p>";
        } else {
            echo "<ul>";
            foreach ($approved as $a) {
                echo "<li>[ID $a->courseid] $a->fullname (Status: $a->status)</li>";
            }
            echo "</ul>";
        }
        
        // Standard completion
        $comp_sql = "SELECT cc.course, c.fullname, cc.timecompleted
                      FROM {course_completions} cc
                      JOIN {course} c ON c.id = cc.course
                      WHERE cc.userid = :uid AND cc.timecompleted > 0";
        $completions = $DB->get_records_sql($comp_sql, ['uid' => $studentId]);
        echo "<h5>Standard Moodle Completions:</h5>";
        if (empty($completions)) {
            echo "<p>No standard completions found.</p>";
        } else {
            echo "<ul>";
            foreach ($completions as $c) {
                echo "<li>[ID $c->course] $c->fullname (Completed: " . date('Y-m-d', $c->timecompleted) . ")</li>";
            }
            echo "</ul>";
        }
        
        // Planning Demand for this student
        $periodId = $DB->get_field_sql("SELECT MAX(id) FROM {gmk_academic_periods}");
        echo "<h5>Calculated Demand (Period $periodId):</h5>";
        
        $data = planning_manager::get_planning_data($periodId);
        $stuData = null;
        foreach ($data['students'] as $s) {
            if ($s['dbId'] == $studentId) {
                $stuData = $s;
                break;
            }
        }
        
        if (!$stuData) {
            echo "<p style='color:red'>Student not found in active planning data (maybe not active or not in plan).</p>";
        } else {
            echo "<p>Career: " . $stuData['career'] . " | Shift: " . $stuData['shift'] . "</p>";
            echo "<h6>Pending Subjects:</h6>";
            echo "<table border='1' cellpadding='5' style='border-collapse:collapse'>";
            echo "<tr><th>ID</th><th>Name</th><th>Prereq Met?</th><th>Priority?</th></tr>";
            foreach ($stuData['pendingSubjects'] as $subj) {
                echo "<tr>
                        <td>" . $subj['id'] . "</td>
                        <td>" . $subj['name'] . "</td>
                        <td style='background:" . ($subj['isPreRequisiteMet'] ? '#dfd' : '#fdd') . "'>" . ($subj['isPreRequisiteMet'] ? 'YES' : 'NO') . "</td>
                        <td style='background:" . ($subj['isPriority'] ? '#dfd' : '#fdd') . "'>" . ($subj['isPriority'] ? 'YES' : 'NO') . "</td>
                      </tr>";
            }
            echo "</table>";
        }
    }
}

echo $OUTPUT->footer();
