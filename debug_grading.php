<?php
/**
 * Debug Grading Logic
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

require_login();

// Default to current user or allow override if admin
$userid = optional_param('userid', $USER->id, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);

echo $OUTPUT->header();
echo "<h1>Debug Grading Logic</h1>";
echo "<p>User ID: $userid</p>";

// 1. Get Instructor's Classes
$sql_classes = "SELECT c.id, c.name, c.courseid, c.groupid 
                FROM {gmk_class} c 
                WHERE c.instructorid = :userid AND c.closed = 0";
$classes = $DB->get_records_sql($sql_classes, ['userid' => $userid]);

echo "<h2>Active Classes (" . count($classes) . ")</h2>";

foreach ($classes as $class) {
    if ($courseid && $class->courseid != $courseid) continue;

    echo "<hr>";
    echo "<h3>Class: {$class->name} (Course ID: {$class->courseid}, Group ID: {$class->groupid})</h3>";

    // 2. Get Assignments in Course
    $assigns = $DB->get_records('assign', ['course' => $class->courseid], 'duedate ASC');
    
    if (empty($assigns)) {
        echo "<p style='color:orange'>No assignments found in this course.</p>";
        continue;
    }

    echo "<ul>";
    foreach ($assigns as $assign) {
        $cm = get_coursemodule_from_instance('assign', $assign->id);
        if (!$cm) continue;
        
        $context = context_module::instance($cm->id);
        $assignObj = new assign($context, $cm, null);
        
        echo "<li><strong>Assignment: {$assign->name}</strong> (ID: {$assign->id})<br>";
        
        // 3. Debug Counts using API vs SQL
        
        // A. SQL Attempt (Current Logic)
        // Note: Adding group check if class has group
        $group_sql = "";
        $params = ['assignid' => $assign->id];
        
        if ($class->groupid > 0) {
            $group_sql = " AND EXISTS (
                SELECT 1 FROM {groups_members} gm 
                WHERE gm.userid = s.userid AND gm.groupid = :groupid
            )";
            $params['groupid'] = $class->groupid;
        }

        $sql_pending = "SELECT s.id, s.userid, s.status, s.timemodified
                        FROM {assign_submission} s 
                        LEFT JOIN {assign_grades} g ON g.assignment = s.assignment AND g.userid = s.userid
                        WHERE s.assignment = :assignid 
                          AND s.status = 'submitted' 
                          AND (g.grade IS NULL OR g.grade = -1)
                        $group_sql";
                        
        $submissions = $DB->get_records_sql($sql_pending, $params);
        
        echo "SQL Pending Count: " . count($submissions) . "<br>";
        
        if (count($submissions) > 0) {
            echo "<table><tr><th>User ID</th><th>Status</th><th>Submitted Time</th><th>Has Grade Record?</th></tr>";
            foreach ($submissions as $sub) {
                // Check if grade record exists at all
                $g = $DB->get_record('assign_grades', ['assignment' => $assign->id, 'userid' => $sub->userid]);
                $gradeVal = $g ? $g->grade : 'NULL';
                
                echo "<tr>";
                echo "<td>{$sub->userid}</td>";
                echo "<td>{$sub->status}</td>";
                echo "<td>" . userdate($sub->timemodified) . "</td>";
                echo "<td>" . ($g ? "Yes (Val: $gradeVal)" : "No") . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
        
        // B. Check Core API count for comparison
         if ($assignObj->get_instance()->teamsubmission) {
             echo " (Team submission enabled - might affect logic)";
         }
         
        echo "</li>";
    }
    echo "</ul>";
}

echo $OUTPUT->footer();
