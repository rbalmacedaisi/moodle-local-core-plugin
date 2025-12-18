<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

// Disable output buffering
if (ob_get_level()) ob_end_clean();

echo "<h1>Grupomakro Debug Tool</h1>";

global $DB;
$dbman = $DB->get_manager();
$table = new xmldb_table('gmk_class');

// Check initdate
$initdate_exists = $dbman->field_exists($table, new xmldb_field('initdate'));
echo "<p>Column <b>initdate</b>: " . ($initdate_exists ? "<span style='color:green'>EXISTS</span>" : "<span style='color:red'>MISSING</span>") . "</p>";

// Check enddate
$enddate_exists = $dbman->field_exists($table, new xmldb_field('enddate'));
echo "<p>Column <b>enddate</b>: " . ($enddate_exists ? "<span style='color:green'>EXISTS</span>" : "<span style='color:red'>MISSING</span>") . "</p>";

// Check Version
$plugin = new stdClass();
require($CFG->dirroot . '/local/grupomakro_core/version.php');
echo "<p>Plugin Version in File: <b>" . $plugin->version . "</b></p>";
$installed_version = get_config('local_grupomakro_core', 'version');
echo "<p>Plugin Version in DB: <b>" . $installed_version . "</b></p>";

if ($plugin->version > $installed_version) {
    echo "<h2 style='color:red'>WARNING: Plugin code is newer (" . $plugin->version . ") than Database (" . $installed_version . "). <br>YOU MUST RUN MOODLE UPGRADE.</h2>";
    echo "<p><a href='" . $CFG->wwwroot . "/admin/index.php' target='_blank' style='font-size:20px; font-weight:bold'>CLICK HERE TO UPGRADE DATABASE</a></p>";
} else {
    echo "<h2 style='color:green'>Version matches.</h2>";
}

// Attempt Query
try {
    echo "<hr><h3>Test Query on gmk_class</h3>";
    $records = $DB->get_records('gmk_class', null, '', 'id, name, inittime, endtime' . ($initdate_exists ? ', initdate' : ''), 0, 1);
    echo "<pre>";
    print_r($records);
    echo "</pre>";
    echo "<p style='color:green'>Query Executed Successfully.</p>";
} catch (Exception $e) {
    echo "<p style='color:red'>Query Failed: " . $e->getMessage() . "</p>";
}
