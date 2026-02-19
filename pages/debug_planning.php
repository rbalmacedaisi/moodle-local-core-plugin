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

// Variables Initialization
$studentId = optional_param('userid', 0, PARAM_INT);
$search = optional_param('search', '', PARAM_TEXT);

// Sample List (if no search and no selection)
if (!$studentId && !$search) {
    echo "<h4>Sample Active Students (Direct Query)</h4>";
    // Direct query using local_learning_users ID as key to avoid duplicates
    $sql = "SELECT llu.id, u.id as userid, u.firstname, u.lastname, lp.name as planname
            FROM {user} u
            JOIN {local_learning_users} llu ON llu.userid = u.id
            JOIN {local_learning_plans} lp ON lp.id = llu.learningplanid
            WHERE u.deleted = 0 AND u.suspended = 0
            LIMIT 15";
    $samples = $DB->get_records_sql($sql);
    
    echo "<ul>";
    foreach ($samples as $s) {
        echo "<li><a href='?userid=$s->userid'>$s->firstname $s->lastname</a> - $s->planname</li>";
    }
    echo "</ul>";
}
    


echo "<hr>";

if ($studentId) {
    $user = $DB->get_record('user', ['id' => $studentId]);
    if (!$user) {
        echo "<p style='color:red'>User $studentId not found.</p>";
    } else {
        echo "<h4>Analyzing: $user->firstname $user->lastname ($user->username)</h4>";

        // 1. Check for Duplicate Subscriptions (The cause of the crash)
        $subs = $DB->get_records('local_learning_users', ['userid' => $studentId]);
        echo "<h5>Subscription Records (local_learning_users)</h5>";
        if (count($subs) > 1) {
            echo "<p style='color:red; font-weight:bold'>WARNING: User has " . count($subs) . " subscription records! This causes the 'Duplicate value' error in the system.</p>";
        }
        echo "<ul>";
        foreach ($subs as $sub) {
            echo "<li>ID: $sub->id | PlanID: $sub->learningplanid | PeriodID: $sub->currentperiodid</li>";
        }
        echo "</ul>";
        
        // Use the first valid plan for analysis
        $mainSub = reset($subs);
        $planId = $mainSub->learningplanid;
        
        // 2. Fetch Plan Courses & Prereqs (Replicating logic to verify)
        echo "<h5>Plan Analysis (Plan ID: $planId)</h5>";
        
        // Get Pre Field ID
        $preFieldId = $DB->get_field('customfield_field', 'id', ['shortname' => 'pre']);
        
        $sqlPlan = "SELECT c.id, c.fullname, c.shortname, cfd.value as prereq_shortnames, 
                           p.name as semester_name, p.id as semester_id
                    FROM {local_learning_courses} lpc
                    JOIN {course} c ON c.id = lpc.courseid
                    JOIN {local_learning_periods} p ON p.id = lpc.periodid
                    LEFT JOIN {customfield_data} cfd ON cfd.instanceid = c.id AND cfd.fieldid = :preid
                    WHERE lpc.learningplanid = :planid
                    ORDER BY p.id, c.fullname";
                    
        $planCourses = $DB->get_records_sql($sqlPlan, ['planid' => $planId, 'preid' => $preFieldId ?: 0]);
        
        // Shortname Map
        $allShorts = $DB->get_records_sql_menu("SELECT shortname, id FROM {course}");
        
        // 3. Fetch Approved
         $approved = [];
         
         // A. Custom Table
         $progRecs = $DB->get_records_sql("SELECT courseid FROM {gmk_course_progre} WHERE userid = ? AND status >= 3", [$studentId]);
         foreach ($progRecs as $r) $approved[] = $r->courseid;
         
         // B. Moodle Completion
         $compRecs = $DB->get_records_sql("SELECT course FROM {course_completions} WHERE userid = ? AND timecompleted > 0", [$studentId]);
         foreach ($compRecs as $r) $approved[] = $r->course;
         
         $approved = array_unique($approved);
         echo "<p>Total Approved Courses found: " . count($approved) . "</p>";

         // 4. Calculate Pending & Prereqs
         echo "<table border='1' cellpadding='5' style='border-collapse:collapse; width:100%'>";
         echo "<tr style='background:#eee'>
                <th>Sem</th>
                <th>Course (ID)</th>
                <th>Shortname</th>
                <th>Status</th>
                <th>Prereqs (Raw -> Resolved)</th>
                <th>Prereq Met?</th>
               </tr>";

         foreach ($planCourses as $c) {
             $isApproved = in_array($c->id, $approved);
             $status = $isApproved ? "<span style='color:green'>Approved</span>" : "<span style='color:orange'>Pending</span>";
             
             $prereqHtml = "None";
             $isPrereqMet = true;
             $metHtml = "-";
             
             if (!$isApproved) {
                 // Check Prereqs
                 if (!empty($c->prereq_shortnames)) {
                     $raws = explode(',', $c->prereq_shortnames);
                     $resolvedList = [];
                     $isPrereqMet = true; 
                     
                     foreach ($raws as $r) {
                         $r = trim($r);
                         $rid = $allShorts[$r] ?? null;
                         
                         if ($rid) {
                             $isMet = in_array($rid, $approved);
                             if (!$isMet) $isPrereqMet = false;
                             $resolvedList[] = "$r ($rid) [" . ($isMet ? "OK" : "MISSING") . "]";
                         } else {
                             // Config Error: Prereq shortname does not exist in Moodle
                             $isPrereqMet = false; // Can't meet a non-existent course
                             $resolvedList[] = "$r (<b style='color:red'>NOT FOUND in Moodle</b>)";
                         }
                     }
                     $prereqHtml = implode("<br>", $resolvedList);
                     $metHtml = $isPrereqMet ? "<b style='color:green'>YES</b>" : "<b style='color:red'>NO</b>";
                 } else {
                     $metHtml = "<b style='color:green'>YES (None)</b>";
                 }
                 
                 echo "<tr>
                        <td>$c->semester_name</td>
                        <td>$c->fullname ($c->id)</td>
                        <td>$c->shortname</td>
                        <td>$status</td>
                        <td>$prereqHtml</td>
                        <td>$metHtml</td>
                       </tr>";
             }
         }
         echo "</table>";
    }
}


echo $OUTPUT->footer();
