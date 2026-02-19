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

// --- AJAX Handler ---
$ajaxAction = optional_param('ajax_action', '', PARAM_TEXT);
if ($ajaxAction === 'massive_step') {
    require_sesskey();
    header('Content-Type: application/json');
    $subid = required_param('subid', PARAM_INT); // Subscription ID
    $sub = $DB->get_record('local_learning_users', ['id' => $subid]);
    
    $res = ['status' => 'ok', 'added' => 0, 'fixed' => 0, 'details' => ''];
    if ($sub && $sub->userrolename === 'student') {
        $sid = $sub->userid;
        $pid = $sub->learningplanid;
        
        // A. Sync Missing
        $planCourses = $DB->get_records('local_learning_courses', ['learningplanid' => $pid]);
        $progRaw = $DB->get_records('gmk_course_progre', ['userid' => $sid]);
        $existing = [];
        foreach ($progRaw as $pr) {
            $existing[$pr->courseid] = $pr->id;
        }
        
        foreach ($planCourses as $pc) {
            if (!isset($existing[$pc->courseid])) {
                $new = new stdClass();
                $new->userid = $sid; $new->learningplanid = $pid; $new->periodid = $pc->periodid;
                $new->courseid = $pc->courseid; $new->status = 0; $new->grade = 0; $new->progress = 0;
                $new->timecreated = time(); $new->timemodified = time();
                $DB->insert_record('gmk_course_progre', $new);
                $res['added']++;
            }
        }

        // B. Fix Status/Grades
        $progRecs = $DB->get_records('gmk_course_progre', ['userid' => $sid]);
        foreach ($progRecs as $rp) {
            $dbGrade = $rp->grade !== null ? (float)$rp->grade : 0;
            $moodleGradeObj = grade_get_course_grade($sid, $rp->courseid);
            $moodleGrade = ($moodleGradeObj && isset($moodleGradeObj->grade)) ? (float)$moodleGradeObj->grade : null;
            
            $effectiveGrade = ($moodleGrade !== null) ? $moodleGrade : (($dbGrade > 0) ? $dbGrade : null);
            $changed = false;

            if ($dbGrade == 0 && $moodleGrade !== null) {
                $rp->grade = $moodleGrade; $changed = true;
            }

            if ($effectiveGrade !== null) {
                if ($effectiveGrade >= 71 && $rp->status < 3) {
                    $rp->status = 4; $changed = true;
                } elseif ($effectiveGrade < 71 && $rp->status != 5 && $rp->status != 3 && $rp->status != 4) {
                    $rp->status = 5; $changed = true;
                }
            }

            if ($changed) {
                $rp->timemodified = time();
                $DB->update_record('gmk_course_progre', $rp);
                $res['fixed']++;
            }
        }
        $userObj = $DB->get_record('user', ['id' => $sid], 'firstname, lastname');
        $res['details'] = "{$userObj->firstname} {$userObj->lastname}";
    }
    echo json_encode($res);
    die();
}

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
    <a href='#' id='nav-sec-massive' onclick='showSection(\"sec-massive\"); return false;'>5. Massive Sync & Fix</a>
</div>";

// --- SECTION 1: Curricula ---
echo "<div id='sec-all' class='debug-section'>";
echo "<h3>1. Curricula Structure & Prerequisites</h3>";
$preFieldId = $DB->get_field('customfield_field', 'id', ['shortname' => 'pre']);
$preField = (object)['id' => $preFieldId]; // For legacy compatibility if any
if (!$preFieldId) {
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
$studentId = optional_param('student_search', 0, PARAM_INT);
$studentSearchTerm = optional_param('search', '', PARAM_TEXT);
$view = optional_param('view', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_TEXT);

echo "<h3>2. Student Analysis (Status & Approved)</h3>";

// Search Form
echo "<form method='GET' style='margin-bottom: 20px; background: #f5f5f5; padding: 15px; border-radius: 5px;'>";
echo "  <input type='hidden' name='mode' value='student'>";
echo "  <strong>Search Student:</strong> ";
echo "  <input type='text' name='search' value='" . s($studentSearchTerm) . "' placeholder='Name or Username...'> ";
echo "  <input type='submit' value='Search'>";
echo "  <a href='?mode=student' style='margin-left:10px'>Clear</a>";
echo "</form>";

// Search Results
if ($studentSearchTerm) {
    $foundUsers = $DB->get_records_sql(
        "SELECT id, firstname, lastname, username, email FROM {user} 
         WHERE deleted = 0 AND (firstname LIKE :q1 OR lastname LIKE :q2 OR username LIKE :q3)
         LIMIT 20",
        ['q1' => "%$studentSearchTerm%", 'q2' => "%$studentSearchTerm%", 'q3' => "%$studentSearchTerm%"]
    );
    
    if ($foundUsers) {
        echo "<ul>";
        foreach ($foundUsers as $u) {
            echo "<li><a href='?mode=student&student_search=$u->id&search=".urlencode($studentSearchTerm)."'>$u->firstname $u->lastname ($u->username)</a></li>"; // Changed userid to student_search
        }
        echo "</ul>";
    } else {
        echo "<p>No users found matching '$studentSearchTerm'.</p>";
    }
}

// Sample List (if no search and no selection)
if (!$studentId && !$studentSearchTerm) {
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
        echo "<li><a href='?mode=student&student_search=$s->userid'>$s->firstname $s->lastname</a> - $s->planname</li>"; // Changed userid to student_search
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
    // Get Study Plan ID
    $planId = $DB->get_field('local_learning_users', 'learningplanid', ['userid' => $studentId]);
    
    if ($planId) {  
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

        // --- NEW: Raw Progress Analysis Logic ---
        echo "<h5>Raw Progress Records (gmk_course_progre) - Status Analysis</h5>";
        $rawProgress = $DB->get_records('gmk_course_progre', ['userid' => $studentId]);
        
        if ($rawProgress) {
            echo "<table border='1' cellpadding='5' style='border-collapse:collapse; width:100%; margin-bottom:15px'>";
            echo "<tr style='background:#f0f0f0'><th>ID</th><th>Course ID</th><th>Course Name</th><th>Status (Raw)</th><th>Grade</th><th>Interpretation</th></tr>";
            foreach ($rawProgress as $rp) {
                $cName = $DB->get_field('course', 'fullname', ['id' => $rp->courseid]);
                $interp = "Unknown";
                if ($rp->status >= 3) $interp = "<span style='color:green'>Approved/Completed</span>";
                elseif ($rp->status == 1) $interp = "<span style='color:orange'>In Progress? (1)</span>";
                elseif ($rp->status == 2) $interp = "<span style='color:red'>Failed/Reprobada? (2)</span>";
                else $interp = "<span style='color:gray'>Other ($rp->status)</span>";
                
                echo "<tr>
                        <td>$rp->id</td>
                        <td>$rp->courseid</td>
                        <td>$cName</td>
                        <td><strong>$rp->status</strong></td>
                        <td>" . ($rp->grade ?? '-') . "</td>
                        <td>$interp</td>
                      </tr>";
            }
            echo "</table>";
        } else {
            echo "<p>No records found in gmk_course_progre.</p>";
        }
        // --- END NEW ---
        
        // --- END NEW ---
        
        // --- NEW: Sync Missing Records Handler ---
        if ($action === 'sync_missing' && $planId) {
             echo "<div style='background:#f0fbff; padding:10px; border:1px solid #bae7ff; margin-bottom:15px'><strong>Syncing Missing Records...</strong><br><ul>";
             $planCourses = $DB->get_records('local_learning_courses', ['learningplanid' => $planId]);
             $existing = $DB->get_records_menu('gmk_course_progre', ['userid' => $studentId], '', 'courseid, id');
             $syncedCount = 0;
             foreach ($planCourses as $pc) {
                 if (!isset($existing[$pc->courseid])) {
                     $new = new stdClass();
                     $new->userid = $studentId;
                     $new->learningplanid = $planId;
                     $new->periodid = $pc->periodid;
                     $new->courseid = $pc->courseid;
                     $new->status = 0; // No disponible default
                     $new->grade = null;
                     $new->progress = 0;
                     $new->timecreated = time();
                     $new->timemodified = time();
                     $DB->insert_record('gmk_course_progre', $new);
                     echo "<li>Missing Course {$pc->courseid} added to gmk_course_progre.</li>";
                     $syncedCount++;
                 }
             }
             echo "</ul><strong>Total Synced: $syncedCount</strong><br><a href='debug_planning.php?student_search=" . urlencode($studentId) . "&view=1' class='btn btn-primary'>Refresh Page</a></div>";
             $rawProgress = $DB->get_records('gmk_course_progre', ['userid' => $studentId]); 
        }

        // --- NEW: Correction Execution Handler ---
        if ($action === 'correct_grades' && $rawProgress) {
            $countFixed = 0;
            echo "<div style='background:#e6fffa; padding:10px; border:1px solid #4fd1c5; margin-bottom:15px'><strong>Executing Grade & Status Corrections...</strong><br><ul>";
            
            foreach ($rawProgress as $rp) {
                 $currentStatus = $rp->status;
                 $dbGrade = $rp->grade !== null ? (float)$rp->grade : null;
                 
                 $moodleGradeObj = grade_get_course_grade($studentId, $rp->courseid);
                 $moodleGrade = ($moodleGradeObj && isset($moodleGradeObj->grade)) ? (float)$moodleGradeObj->grade : null;
                 
                 // Effective Grade Logic:
                 // 1. If Moodle has a grade, trust it (including 0.0).
                 // 2. If Moodle has NO grade (-), but DB has a grade > 0, trust DB.
                 // 3. If DB has 0 but Moodle is null, it's just 'Assigned/Default' -> Ignore.
                 
                 $effectiveGrade = null;
                 if ($moodleGrade !== null) {
                     $effectiveGrade = $moodleGrade;
                 } elseif ($dbGrade !== null && $dbGrade > 0) {
                     $effectiveGrade = $dbGrade;
                 }
                 
                 $newStatus = $currentStatus;
                 $changed = false;

                 // Logic 1: Sync Grade from Moodle if missing in DB
                 if ($dbGrade === null && $moodleGrade !== null) {
                     $rp->grade = $moodleGrade;
                     $changed = true;
                     echo "<li>Course {$rp->courseid}: Imported Grade $moodleGrade from Moodle.</li>";
                 }

                 // Logic 2: Fix Status based on effective grade
                 if ($effectiveGrade !== null) {
                      if ($effectiveGrade >= 71 && $currentStatus < 3) {
                          $newStatus = 4;
                      } elseif ($effectiveGrade < 71 && $currentStatus != 5 && $currentStatus != 3 && $currentStatus != 4) {
                          $newStatus = 5;
                      }
                 }
                 
                 if ($newStatus != $currentStatus) {
                     $rp->status = $newStatus;
                     $changed = true;
                     echo "<li>Course {$rp->courseid}: Status $currentStatus -> $newStatus (Effective Grade: $effectiveGrade) [STATUS FIXED]</li>";
                 }

                 if ($changed) {
                     $rp->timemodified = time();
                     $DB->update_record('gmk_course_progre', $rp);
                     $countFixed++;
                 }
            }
            echo "</ul><strong>Total Records Updated/Synced: $countFixed</strong><br><a href='debug_planning.php?mode=student&student_search=" . urlencode($studentId) . "&search=" . urlencode($studentSearchTerm) . "&view=1' class='btn btn-primary'>Refresh Page</a></div>";
            // Refresh raw data
            $rawProgress = $DB->get_records('gmk_course_progre', ['userid' => $studentId]); 
        }

        // --- Simulator Upgrade ---
        echo "<h5>State Correction Simulator (Detection: Grade < 71 -> Failed/Reprobada)</h5>";
        if ($rawProgress) {
             echo "<table border='1' cellpadding='5' style='border-collapse:collapse; width:100%; margin-bottom:15px'>";
             echo "<tr style='background:#fff0e0'><th>Course</th><th>Current Status</th><th>Grade (DB)</th><th>Moodle Grade</th><th>Proposed Correction</th><th>Reason</th></tr>";
             
             foreach ($rawProgress as $rp) {
                 $cName = $DB->get_field('course', 'fullname', ['id' => $rp->courseid]);
                 $moodleGradeObj = grade_get_course_grade($studentId, $rp->courseid);
                 $moodleGrade = ($moodleGradeObj && isset($moodleGradeObj->grade)) ? (float)$moodleGradeObj->grade : null;
                 
                 $currentStatus = $rp->status;
                 $dbGrade = $rp->grade !== null ? (float)$rp->grade : null;
                 
                  // Effective Grade Logic:
                  // 1. If Moodle has a grade, trust it (including 0.0).
                  // 2. If Moodle has NO grade (-), but DB has a grade > 0, trust DB.
                  // 3. If DB has 0 but Moodle is null, it's just 'Assigned/Default' -> Ignore.
                  
                  $effectiveGrade = null;
                  if ($moodleGrade !== null) {
                      $effectiveGrade = $moodleGrade;
                  } elseif ($dbGrade !== null && $dbGrade > 0) {
                      $effectiveGrade = $dbGrade;
                  }
                  
                  $newStatus = $currentStatus;
                  $action = "OK";
                  $color = "black";

                  // Rule 1: Approval
                  if ($effectiveGrade !== null && $effectiveGrade >= 71) {
                      if ($currentStatus < 3) {
                          $newStatus = 4;
                          $action = "Change to 4 (Approved)";
                          $color = "green";
                      }
                  }
                  // Rule 2: Failure (Detect Real 0 or low grade)
                  elseif ($effectiveGrade !== null && $effectiveGrade < 71) {
                      if ($currentStatus != 5) {
                          $newStatus = 5;
                          $action = "Change to 5 (Failed)";
                          $color = "red";
                      }
                  }
                 
                 $statusLabel = "($currentStatus)";
                 if ($currentStatus == 0) $statusLabel .= " No Disp";
                 if ($currentStatus == 1) $statusLabel .= " Disp";
                 if ($currentStatus == 5) $statusLabel .= " Reprobada";
                 
                 echo "<tr>
                        <td>$cName</td>
                        <td>$statusLabel</td>
                        <td>" . ($dbGrade === null ? '-' : $dbGrade) . "</td>
                        <td>" . ($moodleGrade === null ? '-' : $moodleGrade) . "</td>
                        <td style='color:$color; font-weight:bold'>$action</td>
                        <td>" . ($effectiveGrade === null ? "Missing Grade" : "Effective: $effectiveGrade") . "</td>
                      </tr>";
             }
             echo "</table>";
        }
        
        // --- Buttons ---
        echo "<div class='btn-group'>";
        if ($rawProgress) {
             echo "<form method='post' action='debug_planning.php' style='display:inline'>
                    <input type='hidden' name='mode' value='student'>
                    <input type='hidden' name='student_search' value='" . s($studentId) . "'>
                    <input type='hidden' name='search' value='" . s($studentSearchTerm) . "'>
                    <input type='hidden' name='view' value='1'>
                    <input type='hidden' name='action' value='correct_grades'>
                    <button type='submit' class='btn btn-danger'>🔴 Fix Inconsistent Grades (Set Status 5/4)</button>
                   </form> ";
        }
        echo "<form method='post' action='debug_planning.php' style='display:inline'>
                <input type='hidden' name='mode' value='student'>
                <input type='hidden' name='student_search' value='" . s($studentId) . "'>
                <input type='hidden' name='search' value='" . s($studentSearchTerm) . "'>
                <input type='hidden' name='view' value='1'>
                <input type='hidden' name='action' value='sync_missing'>
                <button type='submit' class='btn btn-warning'>🟡 Create Missing Progress Records</button>
               </form>";
        echo "</div><br><br>";

        // Fetch Plan Courses
        if ($planId) {
            $sqlPlan = "SELECT c.id, c.fullname, c.shortname, cfd.value as prereq_shortnames, 
                               p.name as semester_name, p.id as semester_id
                         FROM {local_learning_courses} lpc
                         JOIN {course} c ON c.id = lpc.courseid
                         JOIN {local_learning_periods} p ON p.id = lpc.periodid
                         LEFT JOIN {customfield_data} cfd ON cfd.instanceid = c.id AND cfd.fieldid = :preid
                         WHERE lpc.learningplanid = :planid
                         ORDER BY p.id, c.fullname";
                         
            $planCourses = $DB->get_records_sql($sqlPlan, ['planid' => $planId, 'preid' => $preFieldId ?: 0]);
        } else {
            $planCourses = [];
        }
        
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
         
         // 5. Real Demand Tree Verification
         echo "<h5>Real Demand Tree Verification (Backend Output)</h5>";
         
         $periodId = $DB->get_field_sql("SELECT MAX(id) FROM {gmk_academic_periods}");
         $realData = planning_manager::get_demand_data($periodId);
         $tree = $realData['demand_tree'];
         $foundInTree = [];
         
         // Better approach: Find the student object in student_list first to know the ID used
         $targetRef = null;
         foreach ($realData['student_list'] as $sl) {
             if ($sl['dbId'] == $studentId) {
                 $targetRef = $sl;
                 break;
             }
         }
         
         if (!$targetRef) {
             echo "<p style='color:red'><strong>CRITICAL:</strong> Student NOT FOUND in 'student_list' returned by planning_manager! They are being filtered out early.</p>";
         } else {
             $targetId = $targetRef['id']; // The ID used in the tree
             echo "<p>Student ID used in Tree: <strong>$targetId</strong></p>";
             
             foreach ($tree as $cName => $shifts) {
                 foreach ($shifts as $sName => $levels) {
                     foreach ($levels as $lName => $lData) {
                         $coursesWithStudent = [];
                         foreach ($lData['course_counts'] as $cId => $cData) {
                             if (isset($cData['students']) && in_array($targetId, $cData['students'])) {
                                 $coursesWithStudent[] = $cData['students']; // Just existence
                                 // Get Subject Name
                                 $subjName = $DB->get_field('course', 'fullname', ['id' => $cId]);
                                 $coursesWithStudent[] = $subjName;
                             }
                         }
                         
                         if (!empty($coursesWithStudent)) {
                             echo "<div style='background:#f8f9fa; padding:10px; margin-bottom:5px; border-left:4px solid green;'>";
                             echo "<strong>FOUND IN:</strong><br>";
                             echo "Career: $cName<br>";
                             echo "Shift: $sName<br>";
                             echo "Level/Column: <strong>$lName</strong><br>";
                             echo "Included in Subjects: " . implode(', ', array_filter($coursesWithStudent, 'is_string'));
                             echo "</div>";
                             $foundInTree = true;
                         }
                     }
                 }
             }
             
             if (!$foundInTree) {
                 echo "<div style='background:#fff3cd; padding:10px; border-left:4px solid orange;'>";
                 echo "<strong>Student is in 'student_list' but NOT found in any Demand Tree Node.</strong><br>";
                 echo "This implies they have pending subjects, but they were all filtered out (e.g. Prereqs not met).";
                 echo "</div>";
             }
         }
    }
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
        $preVal = $DB->get_field('customfield_data', 'value', ['instanceid' => $subjId, 'fieldid' => $preFieldId ?: 0]);
        
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
                <td><a href='?mode=student&student_search=$u->id'>$u->firstname $u->lastname ($u->username)</a></td>
                <td>$u->planname</td>
                <td>$u->subid</td>
                <td><a href='?mode=student&student_search=$u->id'>Analyze</a></td>
              </tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color:green'>No students found with missing period configuration.</p>";
}

echo "</div>"; // End Section 4

// --- SECTION 5: Massive Student Sync & Fix ---
echo "<div id='sec-massive' class='debug-section'>";
echo "<h3>5. Massive Student Sync & Fix (Batch Process)</h3>";
echo "<p>This tool will iterate through students one by one using AJAX to avoid timeouts.</p>";

$allStudents = $DB->get_records('local_learning_users', ['userrolename' => 'student'], 'id', 'id, userid');
$jsonSubs = json_encode(array_values(array_map(function($s){ return $s->id; }, $allStudents)));

echo "
<div id='massive-controls' style='background:#f5f5f5; padding:20px; border-radius:10px; border:1px solid #ddd'>
    <button id='btn-start-massive' class='btn btn-primary' style='font-size:1.2em'>Start Batch Process (" . count($allStudents) . " students)</button>
    <button id='btn-stop-massive' class='btn btn-danger' style='display:none'>Stop Process</button>
    
    <div id='massive-progress-container' style='margin-top:20px; display:none'>
        <div class='progress' style='height:30px; margin-bottom:10px'>
            <div id='massive-bar' class='progress-bar progress-bar-striped progress-bar-animated' role='progressbar' style='width: 0%'>0%</div>
        </div>
        <p id='massive-status'>Waiting to start...</p>
        <div id='massive-log' style='max-height:200px; overflow-y:auto; background:white; border:1px solid #ccc; padding:10px; font-family:monospace; font-size:0.9em'></div>
    </div>
</div>

<script>
(function() {
    const subs = $jsonSubs;
    let index = 0;
    let running = false;
    let totalAdded = 0;
    let totalFixed = 0;

    const btnStart = document.getElementById('btn-start-massive');
    const btnStop = document.getElementById('btn-stop-massive');
    const bar = document.getElementById('massive-bar');
    const status = document.getElementById('massive-status');
    const log = document.getElementById('massive-log');
    const container = document.getElementById('massive-progress-container');

    btnStart.onclick = () => {
        if(!confirm('This will process ' + subs.length + ' students. Continue?')) return;
        running = true;
        btnStart.style.display = 'none';
        btnStop.style.display = 'inline-block';
        container.style.display = 'block';
        processNext();
    };

    btnStop.onclick = () => {
        running = false;
        status.innerText = 'Stopping... (Will stop after current student)';
        btnStop.disabled = true;
    };

    async function processNext() {
        if(!running || index >= subs.length) {
            finish();
            return;
        }

        const subid = subs[index];
        status.innerText = 'Processing student ' + (index + 1) + ' of ' + subs.length + '...';
        
        try {
            const resp = await fetch('debug_planning.php?ajax_action=massive_step&subid=' + subid + '&sesskey=' + M.cfg.sesskey);
            const data = await resp.json();
            
            totalAdded += data.added;
            totalFixed += data.fixed;
            
            const p = Math.round(((index + 1) / subs.length) * 100);
            bar.style.width = p + '%';
            bar.innerText = p + '%';
            
            const line = document.createElement('div');
            line.innerText = '[' + (index + 1) + '] ' + data.details + ': +' + data.added + ' added, ' + data.fixed + ' fixed';
            log.prepend(line);
            
            index++;
            processNext();
        } catch(e) {
            const line = document.createElement('div');
            line.style.color = 'red';
            line.innerText = 'ERROR at index ' + index + ': ' + e.message;
            log.prepend(line);
            index++;
            processNext();
        }
    }

    function finish() {
        running = false;
        btnStop.style.display = 'none';
        btnStart.style.display = 'inline-block';
        btnStart.innerText = 'Restart Process';
        status.innerHTML = '<strong style=\"color:green\">FINISHED!</strong> Total: ' + totalAdded + ' added, ' + totalFixed + ' fixed.';
        alert('Massive process complete!');
    }
})();
</script>
";

echo "</div>"; // End Section 5

echo $OUTPUT->footer();
