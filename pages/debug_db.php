<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/local/grupomakro_core/pages/debug_db.php');
$PAGE->set_pagelayout('base');

echo $OUTPUT->header();

echo "<h2>Custom Fields Configuration</h2>";
$fields = $DB->get_records('user_info_field');
echo "<table class='table table-bordered'>";
echo "<thead><tr><th>ID</th><th>Shortname</th><th>Name</th><th>Datatype</th></tr></thead>";
foreach ($fields as $f) {
    echo "<tr><td>{$f->id}</td><td>{$f->shortname}</td><td>{$f->name}</td><td>{$f->datatype}</td></tr>";
}
echo "</table>";

$testEmail = 'adrianarguelles913@gmail.com'; // From user screenshot
echo "<h2>Debugging User: $testEmail</h2>";

$user = $DB->get_record('user', ['email' => $testEmail, 'deleted' => 0]);

if (!$user) {
    echo "<p class='alert alert-danger'>User not found!</p>";
} else {
    echo "<p>User ID: {$user->id}</p>";
    echo "<p>Standard ID Number (idnumber): <strong>" . ($user->idnumber ? $user->idnumber : 'EMPTY') . "</strong></p>";
    
    echo "<h3>Custom Field Data</h3>";
    $data = $DB->get_records_sql("
        SELECT d.id, d.fieldid, f.shortname, d.data
        FROM {user_info_data} d
        JOIN {user_info_field} f ON f.id = d.fieldid
        WHERE d.userid = ?
    ", [$user->id]);

    if ($data) {
        echo "<table class='table table-bordered'>";
        echo "<thead><tr><th>Field ID</th><th>Shortname</th><th>Value</th></tr></thead>";
        foreach ($data as $d) {
            echo "<tr><td>{$d->fieldid}</td><td>{$d->shortname}</td><td>{$d->data}</td></tr>";
        }
        echo "</table>";
    } else {
        echo "<p>No custom field data found for this user.</p>";
    }
}

echo $OUTPUT->footer();
