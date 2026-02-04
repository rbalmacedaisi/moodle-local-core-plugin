<?php
require_once('../../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_db_structure.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Debug DB Structure - Moodle Questions');

echo $OUTPUT->header();

$all_tables = $DB->get_manager()->get_install_xml_schema()->getTables();
$search_patterns = ['ddmarker', 'ddimageortext', 'drag', 'drop', 'question', 'ddwtos', 'gapselect'];
$found_tables = [];

// Alternative: list all tables from the database directly
$db_tables = $DB->get_tables();
echo "<h3>All Tables (" . count($db_tables) . ")</h3>";
echo "<ul>";
foreach ($db_tables as $table) {
    if (strpos($table, 'qtype') !== false || strpos($table, 'question') !== false || strpos($table, 'dd') !== false || strpos($table, 'gap') !== false) {
        echo "<li><strong>$table</strong></li>";
    } else {
        echo "<li>$table</li>";
    }
}
echo "</ul>";

$tables = [
    'qtype_ddimageortext_drops',
    'qtype_ddmarker_drops',
    'qtype_ddimageortext_drags',
    'qtype_ddmarker_drags'
];

foreach ($tables as $t) {
    echo "<h3>Table: $t</h3>";
    try {
        $columns = $DB->get_columns($t);
        echo "<table border='1'><tr><th>Column</th><th>Type</th><th>Not Null</th></tr>";
        foreach ($columns as $name => $col) {
            echo "<tr><td>$name</td><td>$col->type</td><td>" . ($col->not_null ? 'Yes' : 'No') . "</td></tr>";
        }
        echo "</table>";
    } catch (Exception $e) {
        echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
    }
}

echo $OUTPUT->footer();
