<?php
require_once('../../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_db_structure.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Debug DB Structure - Moodle Questions');

echo $OUTPUT->header();

$tables = ['question_ddmarker', 'question_ddimageortext', 'question_ddmarker_drops', 'question_ddimageortext_drops', 'question_ddmarker_drags', 'question_ddimageortext_drags'];

foreach ($tables as $table) {
    echo "<h3>Table: {$table}</h3>";
    if ($DB->get_manager()->table_exists($table)) {
        $columns = $DB->get_columns($table);
        echo "<table border='1' cellpadding='5' style='border-collapse: collapse; min-width: 600px;'>
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
                    <td>{$column->default_value}</td>
                  </tr>";
        }
        echo "</tbody></table>";
    } else {
        echo "<p style='color: red;'>Table does not exist.</p>";
    }
}

echo $OUTPUT->footer();
