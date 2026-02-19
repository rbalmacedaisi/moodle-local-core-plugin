<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');

echo "--- CHECKING CUSTOM FIELDS ---\n";
$fields = $DB->get_records('user_info_field', [], '', 'id, shortname, name');
foreach ($fields as $f) {
    echo "ID: $f->id | Shortname: $f->shortname | Name: $f->name\n";
}

echo "\n--- CHECKING ACADEMIC PERIODS ---\n";
try {
    $periods = $DB->get_records('gmk_academic_periods', [], '', 'id, name');
    foreach ($periods as $p) {
        echo "ID: $p->id | Name: $p->name\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n--- SAMPLE STUDENTS (TOP 5) ---\n";
$sql = "SELECT u.id, u.firstname, u.lastname, u.idnumber, llu.academicperiodid
        FROM {user} u
        JOIN {local_learning_users} llu ON llu.userid = u.id AND llu.userrolename = 'student'
        WHERE u.deleted = 0 AND u.suspended = 0
        LIMIT 5";
$students = $DB->get_records_sql($sql);
foreach ($students as $s) {
    echo "User: $s->firstname $s->lastname ($s->idnumber) | AP_ID: $s->academicperiodid\n";
    $data = $DB->get_records_sql("
        SELECT f.shortname, d.data 
        FROM {user_info_data} d 
        JOIN {user_info_field} f ON f.id = d.fieldid 
        WHERE d.userid = ?", [$s->id]);
    foreach ($data as $d) {
        echo "  Field: $d->shortname = $d->data\n";
    }
}

echo "\n--- GAP DETECTION TEST ---\n";
$piField = $DB->get_record('user_info_field', ['shortname' => 'periodo_ingreso']);
$sqlGap = "SELECT COUNT(u.id) as count
           FROM {user} u
           JOIN {local_learning_users} llu ON llu.userid = u.id AND llu.userrolename = 'student'
           LEFT JOIN {user_info_data} uid_pi ON uid_pi.userid = u.id AND uid_pi.fieldid = " . ($piField ? $piField->id : 0) . "
           WHERE u.deleted = 0 AND u.suspended = 0
           AND (uid_pi.data IS NULL OR uid_pi.data = '' OR llu.academicperiodid IS NULL OR llu.academicperiodid = 0)";
$gapCount = $DB->get_field_sql($sqlGap);
echo "Total Gaps found: $gapCount\n";
