<?php
require_once(__DIR__ . '/../../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

global $DB;

echo "<h1>Debug Info</h1>";

// Check User Profile Fields
echo "<h2>User Info Fields</h2>";
$fields = $DB->get_records('user_info_field');
echo "<pre>" . print_r($fields, true) . "</pre>";

// Check Learning Periods
echo "<h2>Learning Periods</h2>";
$periods = $DB->get_records('local_learning_periods');
echo "<pre>" . print_r($periods, true) . "</pre>";

// Check Learning Plans
echo "<h2>Learning Plans</h2>";
$plans = $DB->get_records('local_learning_plans');
echo "<pre>" . print_r($plans, true) . "</pre>";
