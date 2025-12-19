<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

// Set up the page
$PAGE->set_url('/local/grupomakro_core/pages/debug_db.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Debug Database Schema');
$PAGE->set_heading('Database Diagnosis');

echo $OUTPUT->header();

echo "<h2>Checking table: local_learning_users</h2>";

$table = 'local_learning_users';
$exists = $DB->get_manager()->table_exists($table);

if (!$exists) {
    echo $OUTPUT->notification("Table $table DOES NOT EXIST.", 'error');
} else {
    echo $OUTPUT->notification("Table $table exists.", 'success');
    
    echo "<h3>Columns:</h3>";
    $columns = $DB->get_columns($table);
    echo "<ul>";
    $foundSubperiod = false;
    foreach ($columns as $col) {
        $style = ($col->name === 'currentsubperiodid') ? 'color:green; font-weight:bold;' : '';
        echo "<li style='$style'>" . $col->name . " (Type: " . $col->type . ")</li>";
        if ($col->name === 'currentsubperiodid') {
            $foundSubperiod = true;
        }
    }
    echo "</ul>";

    if ($foundSubperiod) {
        echo $OUTPUT->notification("Column 'currentsubperiodid' FOUND.", 'success');
    } else {
        echo $OUTPUT->notification("Column 'currentsubperiodid' NOT FOUND. The SQL query in get_student_info.php will fail.", 'error');
    }

    echo "<h3>First 5 Records:</h3>";
    try {
        $records = $DB->get_records($table, null, '', '*', 0, 5);
        if (empty($records)) {
            echo "<p>No records found.</p>";
        } else {
            echo "<table class='table table-bordered'>";
            echo "<thead><tr>";
            foreach (reset($records) as $key => $val) {
                echo "<th>$key</th>";
            }
            echo "</tr></thead><tbody>";
            foreach ($records as $rec) {
                echo "<tr>";
                foreach ($rec as $val) {
                    echo "<td>" . s($val) . "</td>";
                }
                echo "</tr>";
            }
            echo "</tbody></table>";
        }
    } catch (Exception $e) {
        echo $OUTPUT->notification("Error fetching records: " . $e->getMessage(), 'error');
    }
}

echo $OUTPUT->footer();
