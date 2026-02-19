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

echo "
<style>
    .debug-nav { margin-bottom: 20px; border-bottom: 1px solid #ddd; padding-bottom: 10px; }
    .debug-nav a { margin-right: 15px; text-decoration: none; padding: 5px 10px; background: #f8f9fa; border: 1px solid #ddd; border-radius: 4px; color: #333; }
    .debug-nav a.active { background: #007bff; color: white; border-color: #007bff; }
    .debug-section { display: none; }
    .debug-section.active { display: block; }
    .status-badge { padding: 3px 8px; border-radius: 10px; font-size: 0.85em; font-weight: bold; }
    .status-yes { background: #d4edda; color: #155724; }
    .status-no { background: #f8d7da; color: #721c24; }
    .status-pending { background: #fff3cd; color: #856404; }
    .status-approved { background: #cce5ff; color: #004085; }
</style>
<script>
    function showSection(id) {
        document.querySelectorAll('.debug-section').forEach(el => el.classList.remove('active'));
        document.getElementById(id).classList.add('active');
        document.querySelectorAll('.debug-nav a').forEach(el => el.classList.remove('active'));
        document.getElementById('nav-' + id).classList.add('active');
        // Store preference
        localStorage.setItem('debug_last_tab', id);
    }
    document.addEventListener('DOMContentLoaded', () => {
        const last = localStorage.getItem('debug_last_tab') || 'sec-student';
        if(document.getElementById(last)) showSection(last);
    });
</script>
";

$mode = optional_param('mode', '', PARAM_ALPHA);
$defaultTab = 'sec-all'; // Default
if ($mode === 'student') $defaultTab = 'sec-student';
if ($mode === 'subject') $defaultTab = 'sec-subject';

use local_grupomakro_core\local\planning_manager;

// Navigation
echo "<div class='debug-nav'>
    <a href='#' id='nav-sec-all' onclick='showSection(\"sec-all\"); return false;'>1. Curricula & Prereqs</a>
    <a href='#' id='nav-sec-student' onclick='showSection(\"sec-student\"); return false;'>2. Student Analysis</a>
    <a href='#' id='nav-sec-subject' onclick='showSection(\"sec-subject\"); return false;'>3. Subject Analysis</a>
    <a href='#' id='nav-sec-missing' onclick='showSection(\"sec-missing\"); return false;'>4. Missing Period Report</a>
</div>";

// --- SECTION 1: Curricula ---
echo "<div id='sec-all' class='debug-section'>";
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
echo "</div>"; // End Section 1

// --- SECTION 2: Student Analysis ---
echo "<div id='sec-student' class='debug-section'>";
// Variables Initialization
$studentId = optional_param('userid', 0, PARAM_INT);
$search = optional_param('search', '', PARAM_TEXT);

echo "<h3>2. Student Analysis (Status & Approved)</h3>";

// Search Form
echo "<form method='GET' style='margin-bottom: 20px; background: #f5f5f5; padding: 15px; border-radius: 5px;'>";
echo "  <input type='hidden' name='mode' value='student'>";
echo "  <strong>Search Student:</strong> ";
echo "  <input type='text' name='search' value='" . s($search) . "' placeholder='Name or Username...'> ";
echo "  <input type='submit' value='Search'>";
echo "  <a href='?mode=student' style='margin-left:10px'>Clear</a>";
echo "</form>";

// Search Results
if ($search) {
    $foundUsers = $DB->get_records_sql(
        "SELECT id, firstname, lastname, username, email FROM {user} 
         WHERE deleted = 0 AND (firstname LIKE :q1 OR lastname LIKE :q2 OR username LIKE :q3)
         LIMIT 20",
        ['q1' => "%$search%", 'q2' => "%$search%", 'q3' => "%$search%"]
    );
    
    if ($foundUsers) {
        echo "<ul>";
        foreach ($foundUsers as $u) {
            echo "<li><a href='?mode=student&userid=$u->id&search=".urlencode($search)."'>$u->firstname $u->lastname ($u->username)</a></li>";
        }
        echo "</ul>";
    } else {
        echo "<p>No users found matching '$search'.</p>";
    }
}

// Sample List (if no search and no selection)
if (!$studentId && !$search) {
    echo "<h4>Sample Active Students (Direct Query)</h4>";
    // Direct query using local_learning_users ID as key to avoid duplicates
    $sql = "SELECT llu.id, u.id as userid, u.firstname, u.lastname, lp.name as planname
            FROM {user} u
            JOIN {local_learning_users} llu ON llu.userid = u.id
            JOIN {local_learning_plans} lp ON lp.id = llu.learningplanid
            WHERE u.deleted = 0 AND u.suspended = 0 AND llu.userrolename = 'student'
            LIMIT 15";
    $samples = $DB->get_records_sql($sql);
    
    echo "<ul>";
    foreach ($samples as $s) {
        echo "<li><a href='?mode=student&userid=$s->userid'>$s->firstname $s->lastname</a> - $s->planname</li>";
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

        // 1. Check for Duplicate Subscriptions
        $subs = $DB->get_records('local_learning_users', ['userid' => $studentId]);
        echo "<h5>Subscription Records (local_learning_users)</h5>";
        if (count($subs) > 1) {
            echo "<p style='color:red; font-weight:bold'>WARNING: User has " . count($subs) . " subscription records! This caused previous crashes.</p>";
        }
        echo "<ul>";
        foreach ($subs as $sub) {
            $pName = $DB->get_field('local_learning_periods', 'name', ['id' => $sub->currentperiodid]) ?: 'Unknown/Null';
            echo "<li>ID: $sub->id | PlanID: $sub->learningplanid | PeriodID: $sub->currentperiodid (Name: <strong>$pName</strong>) | Role: <strong>$sub->userrolename</strong></li>";
        }
        echo "</ul>";
        
        // Use the LAST valid plan (highest ID) as per planning_manager fix
        $mainSub = end($subs); 
        $currentPeriodName = $DB->get_field('local_learning_periods', 'name', ['id' => $mainSub->currentperiodid]);
        $currentSubPeriodName = $mainSub->currentsubperiodid ? $DB->get_field('local_learning_subperiods', 'name', ['id' => $mainSub->currentsubperiodid]) : '';

        $planId = $mainSub->learningplanid;
        
        // 2. Fetch Plan Courses & Prereqs
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
             $status = $isApproved ? "<span class='status-badge status-approved'>Approved</span>" : "<span class='status-badge status-pending'>Pending</span>";
             
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
                     $metHtml = $isPrereqMet ? "<span class='status-badge status-yes'>YES</span>" : "<span class='status-badge status-no'>NO</span>";
                 } else {
                     $metHtml = "<span class='status-badge status-yes'>YES (None)</span>";
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
         
         // 5. Demand Logic Simulation
         echo "<h5>Demand Logic Simulation</h5>";
         echo "<p>This shows exactly how this student is categorized in the Demand Tree (Backend Logic).</p>";
         
         // Replicate Logic from planning_manager::get_demand_data
         // Level Key Logic
         $levelLabel = $currentPeriodName ?: 'Sin Nivel';
         $subLabel = $currentSubPeriodName ?: '';
         $levelKey = $subLabel ? "$levelLabel - $subLabel" : $levelLabel;
         
         // Fallback Check
         $usedFallback = false;
         if (empty($currentPeriodName) && !empty($planCourses)) {
              // Find first pending subject
              foreach ($planCourses as $c) {
                  $isApproved = in_array($c->id, $approved);
                  if (!$isApproved) {
                      $levelLabel = $c->semester_name; // Fallback
                      $levelKey = $levelLabel; 
                      $usedFallback = true;
                      break;
                  }
              }
         }
         
         // Fetch Shift (Jornada)
         $startJ = microtime(true);
         // We need to fetch the shift from user_info_data
         $jornadaFieldId = $DB->get_field('user_info_field', 'id', ['shortname' => 'gmkjourney']);
         $shiftVal = $DB->get_field('user_info_data', 'data', ['userid' => $studentId, 'fieldid' => $jornadaFieldId ?: 0]);
         $shiftDisplay = $shiftVal ?: 'Sin Jornada';
         // Career
         $careerDisplay = $mainSub->learningplanid ? $DB->get_field('local_learning_plans', 'name', ['id' => $mainSub->learningplanid]) : 'General';
         
         echo "<ul>";
         echo "<li><strong>Career Group:</strong> $careerDisplay</li>";
         echo "<li><strong>Shift Group (Jornada):</strong> $shiftDisplay</li>";
         echo "<li><strong>Raw Period Name (DB):</strong> " . ($currentPeriodName ?: 'NULL') . "</li>";
         echo "<li><strong>Raw Subperiod Name (DB):</strong> " . ($currentSubPeriodName ?: 'NULL') . "</li>";
         echo "<li><strong>Calculated Level Key (Column):</strong> <span style='background:#e2e3e5; padding:2px 5px; border-radius:3px;'>$levelKey</span> " . ($usedFallback ? "(Derived via Fallback)" : "") . "</li>";
         echo "</ul>";
         
         echo "<p><strong>Contributing Demand To:</strong></p>";
         echo "<ul>";
         $contributed = 0;
         foreach ($planCourses as $c) {
             $isApproved = in_array($c->id, $approved);
             if (!$isApproved) {
                 // Check Prereqs again (simplified for display) (Actually we calculated it above in loop)
                 // But wait, the loop above printed rows. We need to re-evaluate IS MET logic.
                 $isPrereqMet = true;
                 if (!empty($c->prereq_shortnames)) {
                     $raws = explode(',', $c->prereq_shortnames);
                     foreach ($raws as $r) {
                         $r = trim($r);
                         $rid = $allShorts[$r] ?? null;
                         if ($rid) {
                             if (!in_array($rid, $approved)) { $isPrereqMet = false; break; }
                         } else {
                             $isPrereqMet = false; 
                         }
                     }
                 }
                 
                 if ($isPrereqMet) {
                     echo "<li>{$c->fullname} ({$c->shortname}) -> <strong>Counted</strong></li>";
                     $contributed++;
                 } else {
                     echo "<li style='color:#999'>{$c->fullname} -> Skipped (Prereq not met)</li>";
                 }
             }
         }
         if ($contributed == 0) echo "<li>None</li>";
         echo "</ul>";

    }
}
echo "</div>"; // End Section 2

// --- SECTION 3: Subject Analysis ---
echo "<div id='sec-subject' class='debug-section'>";
echo "<h3>3. Subject Analysis (Who is pending?)</h3>";

$subjId = optional_param('subjid', 0, PARAM_INT);
$subjSearch = optional_param('subjsearch', '', PARAM_TEXT);

// Subject Search Form
echo "<form method='GET' style='margin-bottom: 20px; background: #e9ecef; padding: 15px; border-radius: 5px;'>";
echo "  <input type='hidden' name='mode' value='subject'>";
echo "  <strong>Select Subject:</strong> ";
// Simple Dropdown of all courses
$allCoursesList = $DB->get_records_menu('course', [], 'fullname', 'id, fullname');
echo html_writer::select($allCoursesList, 'subjid', $subjId, 'Choose...');
echo "  <input type='submit' value='Analyze'>";
echo "</form>";

if ($subjId) {
    if (!isset($allCoursesList[$subjId])) {
         echo "<p style='color:red'>Subject not found.</p>";
    } else {
        echo "<h4>Analyzing Demand for: " . $allCoursesList[$subjId] . " (ID: $subjId)</h4>";
        
        // 1. Get Shortname & Prereqs
        $courseObj = $DB->get_record('course', ['id' => $subjId]);
        $preVal = $DB->get_field('customfield_data', 'value', ['instanceid' => $subjId, 'fieldid' => $preField->id ?? 0]);
        
        echo "<p><strong>Shortname:</strong> $courseObj->shortname</p>";
        echo "<p><strong>Prereqs (Raw):</strong> " . ($preVal ?: 'None') . "</p>";

        // 2. We need to check ALL active students using the Core Logic (planning_manager) 
        // because that's what drives the board. We want to see if planning_manager thinks they are pending.
        $periodId = $DB->get_field_sql("SELECT MAX(id) FROM {gmk_academic_periods}");
        
        $start = microtime(true);
        // Note: This calls the FIXED planning_manager
        $data = planning_manager::get_planning_data($periodId); 
        $end = microtime(true);
        echo "<p><small>Data fetch took " . round($end - $start, 3) . "s</small></p>";

        $foundCount = 0;
        $prereqMetCount = 0;
        
        echo "<table border='1' cellpadding='5' style='border-collapse:collapse; width:100%'>";
        echo "<thead><tr style='background:#eee'>
                <th>Student</th>
                <th>Career</th>
                <th>Status in Plan</th>
                <th>Prereq Met?</th>
              </tr></thead><tbody>";

        if (!empty($data['students'])) {
            foreach ($data['students'] as $stu) {
                $isPending = false;
                $pSubject = null;
                
                if (isset($stu['pendingSubjects']) && is_array($stu['pendingSubjects'])) {
                    foreach ($stu['pendingSubjects'] as $ps) {
                        if ($ps['id'] == $subjId) {
                            $isPending = true;
                            $pSubject = $ps;
                            break;
                        }
                    }
                }
                
                if ($isPending) {
                    $foundCount++;
                    $isMet = $pSubject['isPreRequisiteMet'];
                    if ($isMet) $prereqMetCount++;
                    
                    $rowStyle = $isMet ? "" : "background:#fff5f5; color:#999";
                    $metHtml = $isMet ? "<span class='status-badge status-yes'>YES</span>" : "<span class='status-badge status-no'>NO</span>";
                    
                    echo "<tr style='$rowStyle'>
                            <td><a href='?mode=student&userid={$stu['dbId']}'>{$stu['name']}</a></td>
                            <td>{$stu['career']}</td>
                            <td>Pending</td>
                            <td>$metHtml</td>
                          </tr>";
                }
            }
        }
        echo "</tbody></table>";
        
        echo "<p><strong>Summary:</strong> Found $foundCount students with this subject Pending. Of those, $prereqMetCount serve as valid demand (Prereq Met).</p>";
    }
}
echo "</div>"; // End Section 3

// --- SECTION 4: Missing Period Report ---
echo "<div id='sec-missing' class='debug-section'>";
echo "<h3>4. Students with Missing Period (Fallback Active)</h3>";
echo "<p>The following students have <strong>no assigned period</strong> in their subscription. The system is now auto-calculating their level based on their first pending subject.</p>";

$sqlMissing = "SELECT u.id, u.firstname, u.lastname, u.username, lp.name as planname, llu.id as subid
               FROM {local_learning_users} llu
               JOIN {user} u ON u.id = llu.userid
               JOIN {local_learning_plans} lp ON lp.id = llu.learningplanid
               WHERE (llu.currentperiodid IS NULL OR llu.currentperiodid = 0)
               AND llu.userrolename = 'student'
               AND u.deleted = 0 AND u.suspended = 0
               ORDER BY u.lastname, u.firstname";
$missingUsers = $DB->get_records_sql($sqlMissing);

echo "<p><strong>Total students found:</strong> " . count($missingUsers) . "</p>";

if ($missingUsers) {
    echo "<table border='1' cellpadding='5' style='border-collapse:collapse; width:100%'>";
    echo "<tr style='background:#eee'>
            <th>Student</th>
            <th>Plan</th>
            <th>Subscription ID</th>
            <th>Action</th>
          </tr>";
    foreach ($missingUsers as $u) {
        echo "<tr>
                <td><a href='?mode=student&userid=$u->id'>$u->firstname $u->lastname ($u->username)</a></td>
                <td>$u->planname</td>
                <td>$u->subid</td>
                <td><a href='?mode=student&userid=$u->id'>Analyze</a></td>
              </tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color:green'>No students found with missing period configuration.</p>";
}

echo "</div>"; // End Section 4

echo $OUTPUT->footer();
