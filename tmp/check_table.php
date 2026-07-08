<?php
define('CLI_SCRIPT', true);
require '/var/www/html/moodle/config.php';
$dbman = $DB->get_manager();
$table = new xmldb_table('gmk_diploma_eligible_course');
echo 'table exists: ' . ($dbman->table_exists($table) ? 'yes' : 'no') . "\n";
$gtable = new xmldb_table('gmk_diploma_generation');
$field = new xmldb_field('courseid');
echo 'courseid column exists: ' . ($dbman->field_exists($gtable, $field) ? 'yes' : 'no') . "\n";