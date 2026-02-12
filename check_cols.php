<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../config.php');

$record = $DB->get_record_sql('SELECT * FROM {local_learning_users} LIMIT 1');
print_r($record);
