<?php
require_once(__DIR__ . '/../../../config.php');
global $DB;

$studentRoleId = $DB->get_field('role', 'id', ['shortname' => 'student']);

$sql = "SELECT lpu.userid, lpu.learningplanid
        FROM {local_learning_users} lpu
        LEFT JOIN {gmk_course_progre} gcp ON (gcp.userid = lpu.userid AND gcp.learningplanid = lpu.learningplanid)
        WHERE lpu.userroleid = :studentroleid AND gcp.id IS NULL";

$missing = $DB->get_records_sql($sql, ['studentroleid' => $studentRoleId]);

echo "Total students in local_learning_users: " . $DB->count_records('local_learning_users', ['userroleid' => $studentRoleId]) . "\n";
echo "Students missing gmk_course_progre records: " . count($missing) . "\n";

if (count($missing) > 0) {
    echo "\nExample missing users:\n";
    $i = 0;
    foreach ($missing as $m) {
        $user = $DB->get_record('user', ['id' => $m->userid], 'firstname, lastname, idnumber');
        echo "- {$user->firstname} {$user->lastname} (ID: {$m->userid}, Doc: {$user->idnumber}) Plans: {$m->learningplanid}\n";
        if (++$i >= 5) break;
    }
}
