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
foreach ($db_tables as $table) {
    foreach ($search_patterns as $pattern) {
        if (strpos($table, $pattern) !== false) {
            $found_tables[] = $table;
            break;
        }
    }
}

$found_tables = array_unique($found_tables);
sort($found_tables);

echo "<h3>Found Tables matching patterns: " . implode(', ', $search_patterns) . "</h3>";

if (empty($found_tables)) {
    echo "<p>No tables found matching patterns.</p>";
}

foreach ($found_tables as $table) {
    echo "<h4>Table: {$table}</h4>";
    $columns = $DB->get_columns($table);
    echo "<table border='1' cellpadding='5' style='border-collapse: collapse; min-width: 600px; margin-bottom: 20px;'>
            <thead>
                <tr style='background: #f4f4f4;'>
                    <th>Column</th>
                    <th>Type</th>
                    <th>Max Length</th>
                    <th>Not Null</th>
                    <th>Default</th>
                </tr>
            </thead>
            <tbody>";
    foreach ($columns as $column) {
        echo "<tr>
                <td>{$column->name}</td>
                <td>{$column->type}</td>
                <td>{$column->max_length}</td>
                <td>" . ($column->not_null ? 'Yes' : 'No') . "</td>
                <td>" . var_export($column->default_value, true) . "</td>
              </tr>";
    }
    echo "</tbody></table>";
}

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
