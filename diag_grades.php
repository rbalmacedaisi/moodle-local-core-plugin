<?php
/**
 * Diagnostic tool for Student Grades / Pensum issues.
 * Place this in /local/grupomakro_core/diag_grades.php
 * Access via: https://isi.q10.com/local/grupomakro_core/diag_grades.php
 */

define('NO_OUTPUT_BUFFERING', true);
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

// Optional: Limit to admins
// require_login();
// $context = context_system::instance();
// require_capability('moodle/site:config', $context);

$search = optional_param('search', '', PARAM_TEXT);
$userid = optional_param('userid', 0, PARAM_INT);

echo "<html><head><title>Diagnostic: Student Grades</title>";
echo "<style>
    body { font-family: sans-serif; margin: 20px; line-height: 1.4; color: #333; }
    h1, h2, h3 { color: #1976D2; }
    .card { border: 1px solid #ddd; padding: 15px; margin-bottom: 20px; border-radius: 8px; background: #f9f9f9; }
    .error { color: #d32f2f; font-weight: bold; }
    .success { color: #388e3c; font-weight: bold; }
    .warning { color: #f57c00; font-weight: bold; }
    pre { background: #eee; padding: 10px; border-radius: 4px; overflow-x: auto; font-size: 12px; }
    table { border-collapse: collapse; width: 100%; margin-top: 10px; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; font-size: 13px; }
    th { background: #e3f2fd; }
    tr:nth-child(even) { background: #fff; }
</style></head><body>";

echo "<h1>Diagnostic Tool: Student Grades & Pensum</h1>";
echo "<div class='card'>";
echo "<form method='GET'>
    Search User (Name/Email): <input type='text' name='search' value='".s($search)."'> 
    OR User ID: <input type='number' name='userid' value='".($userid ?: "")."'>
    <input type='submit' value='Run Diagnostic' style='background: #1976D2; color: white; border: none; padding: 5px 15px; cursor: pointer; border-radius: 4px;'>
</form>";
echo "</div>";

if (!$search && !$userid) {
    echo "<p>Please enter a search term or User ID to begin.</p>";
    echo "</body></html>";
    die();
}

global $DB;

$users = [];
if ($userid) {
    $u = $DB->get_record('user', ['id' => $userid]);
    if ($u) $users[] = $u;
} else {
    $users = $DB->get_records_sql("SELECT id, firstname, lastname, email FROM {user} WHERE (firstname LIKE ? OR lastname LIKE ? OR email LIKE ?) AND deleted = 0", ["%$search%", "%$search%", "%$search%"]);
}

if (empty($users)) {
    echo "<div class='error'>No users found for the given criteria.</div>";
    die();
}

foreach ($users as $user) {
    echo "<div class='card'>";
    echo "<h2>User: $user->firstname $user->lastname (<a href='mailto:$user->email'>$user->email</a>) - ID: $user->id</h2>";
    
    // 1. Careers (Learning Plans)
    echo "<h3>1. Careers (from {local_learning_users})</h3>";
    $careers = $DB->get_records_sql("
        SELECT lp.id as planid, lp.name as planname, lpu.id as relation_id, lpu.currentperiodid as current_period_id
        FROM {local_learning_plans} lp
        JOIN {local_learning_users} lpu ON lpu.learningplanid = lp.id
        WHERE lpu.userid = ?
    ", [$user->id]);
    
    if (!$careers) {
        echo "<p class='error'>FAIL: No careers associated with this student in local_learning_users.</p>";
    } else {
        echo "<table><thead><tr><th>Plan ID</th><th>Career Name</th><th>Relation ID</th><th>Current Period</th></tr></thead><tbody>";
        foreach ($careers as $lp) {
            $pName = $DB->get_record('local_learning_periods', ['id' => $lp->current_period_id], 'name');
            echo "<tr>
                <td>$lp->planid</td>
                <td><strong>$lp->planname</strong></td>
                <td>$lp->relation_id</td>
                <td>" . ($pName ? $pName->name : "<span class='error'>Missing Period ID $lp->current_period_id</span>") . "</td>
            </tr>";
        }
        echo "</tbody></table>";

        // Deep dive into each plan
        foreach ($careers as $lp) {
            echo "<h4>Career Detail: $lp->planname (ID $lp->planid)</h4>";
            
            // 2. Courses in the Pensum
            echo "<p><strong>2. Courses in Pensum ({local_learning_courses})</strong></p>";
            $courses = $DB->get_records('local_learning_courses', ['learningplanid' => $lp->planid], 'position ASC');
            
            if (!$courses) {
                echo "<p class='error'>FAIL: No courses found in local_learning_courses for Plan ID $lp->planid.</p>";
            } else {
                echo "<p class='success'>SUCCESS: " . count($courses) . " courses found.</p>";
                echo "<table><thead><tr><th>Pos</th><th>Course ID</th><th>Course Name</th><th>Period ID</th><th>Period Name</th><th>Credits</th></tr></thead><tbody>";
                $count = 0;
                foreach ($courses as $c) {
                    $moodle_course = $DB->get_record('course', ['id' => $c->courseid], 'fullname');
                    $period = $DB->get_record('local_learning_periods', ['id' => $c->periodid], 'name');
                    echo "<tr>
                        <td>$c->position</td>
                        <td>$c->courseid</td>
                        <td>" . ($moodle_course ? $moodle_course->fullname : "<span class='error'>NOT FOUND IN MOODLE</span>") . "</td>
                        <td>$c->periodid</td>
                        <td>" . ($period ? $period->name : "<span class='error'>MISSING</span>") . "</td>
                        <td>$c->credits</td>
                    </tr>";
                    if (++$count > 20) {
                        echo "<tr><td colspan='6' class='warning'>Showing first 20 courses only...</td></tr>";
                        break;
                    }
                }
                echo "</tbody></table>";
            }

            // 3. Progress Records
            echo "<p><strong>3. Progress Records ({gmk_course_progre})</strong></p>";
            $progress = $DB->get_records('gmk_course_progre', ['userid' => $user->id, 'learningplanid' => $lp->planid]);
            if (!$progress) {
                echo "<p class='warning'>No historical progress records found for this student/plan in gmk_course_progre.</p>";
            } else {
                echo "<p class='success'>" . count($progress) . " progress records found.</p>";
                echo "<table><thead><tr><th>Course ID</th><th>Credits</th><th>Progress</th><th>Status</th></tr></thead><tbody>";
                foreach ($progress as $pg) {
                    echo "<tr>
                        <td>$pg->courseid</td>
                        <td>$pg->credits</td>
                        <td>{$pg->progress}%</td>
                        <td>$pg->status</td>
                    </tr>";
                }
                echo "</tbody></table>";
            }

            // 4. Test Webservice Directly
            echo "<h3>4. Webservice Response Test</h3>";
            try {
                require_once(__DIR__ . '/classes/external/student/get_student_learning_plan_pensum.php');
                $wsResponse = \local_grupomakro_core\external\student\get_student_learning_plan_pensum::execute($user->id, $lp->planid);
                echo "<p>Status: " . ($wsResponse['status'] == 1 ? "<span class='success'>SUCCESS</span>" : "<span class='error'>FAIL ({$wsResponse['message']})</span>") . "</p>";
                if ($wsResponse['status'] == 1) {
                    echo "<p>Pensum JSON length: " . strlen($wsResponse['pensum']) . "</p>";
                    $decodedPensum = json_decode($wsResponse['pensum'], true);
                    echo "<p>Periods in pensum: " . count($decodedPensum) . "</p>";
                    echo "<details><summary>View Raw JSON</summary><pre>" . s($wsResponse['pensum']) . "</pre></details>";
                }
            } catch (Exception $e) {
                echo "<p class='error'>CRASH: " . s($e->getMessage()) . "</p>";
                echo "<pre>" . s($e->getTraceAsString()) . "</pre>";
            }
        }
    }
    echo "</div>";
}

echo "</body></html>";
