<?php
require(__DIR__ . '/../../config.php');
require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

echo "<pre>";
echo "Checking subperiod data for a few students:\n";

$sql = "SELECT lpu.userid, u.firstname, u.lastname, lpu.currentperiodid, lpu.currentsubperiodid, lpu.academicperiodid 
        FROM {local_learning_users} lpu 
        JOIN {user} u ON u.id = lpu.userid 
        WHERE u.deleted = 0 AND u.suspended = 0 
        LIMIT 10";
$records = $DB->get_records_sql($sql);
print_r($records);

echo "\nChecking subperiods definition:\n";
$subperiods = $DB->get_records('local_learning_subperiods');
print_r($subperiods);

echo "</pre>";
