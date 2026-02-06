<?php
define('CLI_SCRIPT', false);
require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/locallib.php');

$userid = optional_param('userid', null, PARAM_INT);
$planid = optional_param('planid', null, PARAM_INT);
$search = optional_param('search', '', PARAM_RAW);

echo "<h1>Student Grades Diagnostic</h1>";

if (!$userid && $search) {
    echo "<h2>Searching for student: $search</h2>";
    $users = $DB->get_records_sql("SELECT id, firstname, lastname, email, idnumber FROM {user} WHERE firstname LIKE ? OR lastname LIKE ? OR email LIKE ?", ["%$search%", "%$search%", "%$search%"]);
    echo "<table border='1'><tr><th>ID</th><th>Name</th><th>Email</th><th>ID Number</th></tr>";
    foreach ($users as $u) {
        echo "<tr><td>{$u->id}</td><td>{$u->firstname} {$u->lastname}</td><td>{$u->email}</td><td>{$u->idnumber}</td></tr>";
    }
    echo "</table>";
}

if ($userid) {
    echo "<h2>Data for User ID: $userid</h2>";
    
    // Careers
    $careers = $DB->get_records_sql("
        SELECT lp.id, lp.name as career 
        FROM {local_learning_plans} lp
        JOIN {local_learning_users} lpu ON (lpu.learningplanid = lp.id)
        WHERE lpu.userid = ?", [$userid]);
        
    echo "<h3>Careers:</h3><ul>";
    foreach ($careers as $c) {
        echo "<li>ID: {$c->id} - Name: {$c->career}</li>";
    }
    echo "</ul>";
    
    if ($planid) {
        echo "<h3>Courses for Plan ID: $planid</h3>";
        $courses = $DB->get_records_sql("
            SELECT lpc.*, c.fullname, lp.name as periodname
            FROM {local_learning_courses} lpc
            JOIN {course} c ON (c.id = lpc.courseid)
            LEFT JOIN {local_learning_periods} lp ON (lp.id = lpc.periodid)
            WHERE lpc.learningplanid = ?
            ORDER BY lpc.position ASC", [$planid]);
            
        echo "<table border='1'><tr><th>ID</th><th>Course</th><th>Period</th><th>Position</th><th>Progress</th></tr>";
        foreach ($courses as $co) {
            $progress = $DB->get_record('gmk_course_progre', ['courseid' => $co->courseid, 'userid' => $userid, 'learningplanid' => $planid]);
            $status = $progress ? $progress->status : 'NULL';
            echo "<tr><td>{$co->id}</td><td>{$co->fullname}</td><td>{$co->periodname}</td><td>{$co->position}</td><td>Status: $status</td></tr>";
        }
        echo "</table>";
    }
}
