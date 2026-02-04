<?php
require_once(__DIR__ . '/../../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_find_columns.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Find Specific Columns');
echo $OUTPUT->header();

$search_columns = ['choicegroup', 'draggroup', 'infinite', 'choiceno', 'draglabel'];
$db_tables = $DB->get_tables();

echo "<h3>Searching for columns: " . implode(', ', $search_columns) . "</h3>";

foreach ($db_tables as $table) {
    try {
        $columns = $DB->get_columns($table);
        $found = [];
        foreach ($columns as $col) {
            if (in_array(strtolower($col->name), $search_columns)) {
                $found[] = $col->name;
            }
        }
        
        if (!empty($found) || strpos($table, 'ddwtos') !== false || strpos($table, 'gapselect') !== false) {
            echo "<h4>Table: <strong>$table</strong></h4>";
            echo "<ul>";
            foreach ($columns as $col) {
                $style = in_array(strtolower($col->name), $search_columns) ? 'style="color:red; font-weight:bold;"' : '';
                echo "<li $style>{$col->name} ({$col->type})</li>";
            }
            echo "</ul>";
        }
    } catch (Exception $e) {
        // Skip inaccessible tables
    }
}

echo $OUTPUT->footer();
