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

echo "<h2>Sample User Data (Limit 10)</h2>";
$sql = "SELECT u.id, u.firstname, u.lastname, uid.fieldid, f.shortname, uid.data
        FROM {user} u
        JOIN {user_info_data} uid ON uid.userid = u.id
        JOIN {user_info_field} f ON f.id = uid.fieldid
        LIMIT 20";
$data = $DB->get_records_sql($sql);

echo "<table class='table table-bordered'>";
echo "<thead><tr><th>User</th><th>Field (Shortname)</th><th>Value</th></tr></thead>";
echo "<tbody>";
foreach ($data as $d) {
    echo "<tr>";
    echo "<td>" . $d->firstname . " " . $d->lastname . "</td>";
    echo "<td>" . $d->shortname . "</td>";
    echo "<td>" . $d->data . "</td>";
    echo "</tr>";
}
echo "</tbody></table>";

echo $OUTPUT->footer();
