<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../config.php');

global $DB;

echo "--- ROLES ---\n";
$roles = $DB->get_records('role', [], '', 'id, shortname');
foreach ($roles as $role) {
    echo "ID: {$role->id}, Shortname: {$role->shortname}\n";
}

echo "\n--- PLANS WITH NO COURSES ---\n";
$plans = $DB->get_records('local_learning_plans', [], '', 'id, name');
foreach ($plans as $plan) {
    $coursecount = $DB->count_records('local_learning_courses', ['learningplanid' => $plan->id]);
    if ($coursecount == 0) {
        $usercount = $DB->count_records('local_learning_users', ['learningplanid' => $plan->id]);
        echo "ID: {$plan->id}, Name: {$plan->name}, Courses: $coursecount, Enrolled Users: $usercount\n";
    }
}

echo "\n--- STUDENTS WITHOUT PROGRESS RECORDS ---\n";
$sql = "SELECT lpu.userid, lpu.learningplanid, u.firstname, u.lastname, lp.name as planname
        FROM {local_learning_users} lpu
        JOIN {user} u ON u.id = lpu.userid
        JOIN {local_learning_plans} lp ON lp.id = lpu.learningplanid
        LEFT JOIN {gmk_course_progre} gcp ON (gcp.userid = lpu.userid AND gcp.learningplanid = lpu.learningplanid)
        WHERE gcp.id IS NULL
        LIMIT 20";
$missing = $DB->get_records_sql($sql);
foreach ($missing as $m) {
    echo "User: {$m->firstname} {$m->lastname} (ID: {$m->userid}), Plan: {$m->planname} (ID: {$m->learningplanid})\n";
}
