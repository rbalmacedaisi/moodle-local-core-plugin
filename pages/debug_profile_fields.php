<?php
/**
 * Debug page to list custom profile fields and their configurations.
 */
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

// Permissions
require_login();
if (!is_siteadmin()) {
    print_error('onlyadmins', 'error');
}

$PAGE->set_url('/local/grupomakro_core/pages/debug_profile_fields.php');
$PAGE->set_title('Debug: Custom Profile Fields');
$PAGE->set_heading('Custom User Profile Fields Discovery');

echo $OUTPUT->header();

echo $OUTPUT->heading('User Info Fields (Custom Fields)');

global $DB;

$fields = $DB->get_records('user_info_field', [], 'id ASC');

if (!$fields) {
    echo $OUTPUT->notification('No custom profile fields found.', 'info');
} else {
    $table = new html_table();
    $table->head = ['ID', 'Shortname', 'Name', 'DataType', 'Category ID'];
    foreach ($fields as $f) {
        $table->data[] = [
            $f->id,
            '<strong>' . $f->shortname . '</strong>',
            $f->name,
            $f->datatype,
            $f->categoryid
        ];
    }
    echo html_writer::table($table);
}

echo $OUTPUT->heading('Sample Data (First 10 users with custom data)');

$sql = "SELECT d.id, u.username, f.shortname, d.data
        FROM {user_info_data} d
        JOIN {user_info_field} f ON d.fieldid = f.id
        JOIN {user} u ON d.userid = u.id
        ORDER BY u.id ASC, f.id ASC
        LIMIT 50";

$data = $DB->get_records_sql($sql);

if (!$data) {
    echo $OUTPUT->notification('No custom data found in user_info_data.', 'info');
} else {
    $table = new html_table();
    $table->head = ['Username', 'Field Shortname', 'Value'];
    foreach ($data as $d) {
        $table->data[] = [
            $d->username,
            $d->shortname,
            $d->data
        ];
    }
    echo html_writer::table($table);
}

echo $OUTPUT->footer();
