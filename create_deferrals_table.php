<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../config.php');

global $DB;
$dbman = $DB->get_manager();

$table = new xmldb_table('gmk_academic_deferrals');
$table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
$table->add_field('academicperiodid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
$table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
$table->add_field('career', XMLDB_TYPE_CHAR, '100', null, null, null, null);
$table->add_field('shift', XMLDB_TYPE_CHAR, '50', null, null, null, null);
$table->add_field('current_level', XMLDB_TYPE_CHAR, '50', null, null, null, null);
$table->add_field('target_period_index', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
$table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
$table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
$table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

$table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));

if (!$dbman->table_exists($table)) {
    $dbman->create_table($table);
    echo "Table gmk_academic_deferrals created.\n";
} else {
    echo "Table gmk_academic_deferrals already exists.\n";
}
