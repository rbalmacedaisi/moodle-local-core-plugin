<?php
require_once('../../../config.php');
require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_fields.php'));
$PAGE->set_context($context);
$PAGE->set_title('Debug Custom Fields');
$PAGE->set_heading('Debug Custom Fields');

echo $OUTPUT->header();

echo "<h2>User Info Data Fields</h2>";
$fields = $DB->get_records('user_info_field');

echo "<table class='table table-bordered'>";
echo "<thead><tr><th>ID</th><th>Shortname</th><th>Name</th><th>Datatype</th></tr></thead>";
echo "<tbody>";
foreach ($fields as $f) {
    echo "<tr>";
    echo "<td>" . $f->id . "</td>";
    echo "<td>" . $f->shortname . "</td>";
    echo "<td>" . $f->name . "</td>";
    echo "<td>" . $f->datatype . "</td>";
    echo "</tr>";
}
echo "</tbody></table>";

echo "<h2>Specific Debug for 'gmkjourney' (Field ID: 10)</h2>";

// 1. Check raw data distribution for field 10
$sqlCounts = "SELECT data, count(id) as c FROM {user_info_data} WHERE fieldid = 10 GROUP BY data";
$counts = $DB->get_records_sql($sqlCounts);
echo "<h3>Raw Data Distribution in user_info_data (fieldid=10)</h3>";
echo "<ul>";
foreach ($counts as $c) {
    echo "<li>Value: '" . $c->data . "' - Count: " . $c->c . "</li>";
}
echo "</ul>";

// 3. Deep Dive for User 2227 (Meybis)
echo "<h3>Deep Dive for User 2227 (Meybis)</h3>";
$sqlUser = "SELECT f.id, f.shortname, f.name, uid.data
            FROM {user_info_data} uid
            JOIN {user_info_field} f ON f.id = uid.fieldid
            WHERE uid.userid = 2227";
$userData = $DB->get_records_sql($sqlUser);

echo "<table class='table table-bordered'>";
echo "<thead><tr><th>Field ID</th><th>Shortname</th><th>Name</th><th>Stored Value</th></tr></thead>";
echo "<tbody>";
foreach ($userData as $d) {
    echo "<tr>";
    echo "<td>" . $d->id . "</td>";
    echo "<td>" . $d->shortname . "</td>";
    echo "<td>" . $d->name . "</td>";
    echo "<td>[" . $d->data . "]</td>";
    echo "</tr>";
}
echo "</tbody></table>";



echo $OUTPUT->footer();
