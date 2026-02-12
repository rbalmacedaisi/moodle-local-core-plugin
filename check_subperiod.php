<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../config.php');

$sql = "SELECT lpu.userid, lpu.currentperiodid, lpu.currentsubperiodid, lpu.academicperiodid 
        FROM {local_learning_users} lpu 
        JOIN {user} u ON u.id = lpu.userid 
        WHERE u.deleted = 0 AND u.suspended = 0 
        LIMIT 5";
$records = $DB->get_records_sql($sql);
print_r($records);
