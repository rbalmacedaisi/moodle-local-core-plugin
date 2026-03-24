<?php
// Public verification page for generated letters.

require_once(__DIR__ . '/../../../config.php');

use local_grupomakro_core\local\letters\manager;

$token = trim((string)optional_param('t', '', PARAM_ALPHANUMEXT));
$urlparams = [];
if ($token !== '') {
    $urlparams['t'] = $token;
}

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/letter_verify.php', $urlparams));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('base');
$PAGE->set_title(get_string('letter_verify_page_title', 'local_grupomakro_core'));
$PAGE->set_heading(get_string('letter_verify_page_heading', 'local_grupomakro_core'));

$verification = null;
if ($token !== '') {
    $verification = manager::get_verification_data($token);
}

echo $OUTPUT->header();
echo html_writer::div(get_string('letter_verify_intro', 'local_grupomakro_core'), 'alert alert-info');

if ($token === '') {
    echo $OUTPUT->notification(get_string('letter_verify_missing_token', 'local_grupomakro_core'), 'warning');
    echo $OUTPUT->footer();
    exit;
}

if (!$verification) {
    echo $OUTPUT->notification(get_string('letter_verify_invalid', 'local_grupomakro_core'), 'error');
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->notification(get_string('letter_verify_valid', 'local_grupomakro_core'), 'success');

$rows = [];
$rows[] = new html_table_row([
    get_string('letter_verify_field_requestid', 'local_grupomakro_core'),
    (int)$verification['requestid'],
]);
$rows[] = new html_table_row([
    get_string('letter_verify_field_documentid', 'local_grupomakro_core'),
    (int)$verification['documentid'],
]);
$rows[] = new html_table_row([
    get_string('letter_verify_field_letter', 'local_grupomakro_core'),
    format_string((string)$verification['lettertypename']) . ' (' . s((string)$verification['lettertypecode']) . ')',
]);
$rows[] = new html_table_row([
    get_string('letter_verify_field_student', 'local_grupomakro_core'),
    format_string((string)$verification['studentname']),
]);
$rows[] = new html_table_row([
    get_string('letter_verify_field_status', 'local_grupomakro_core'),
    format_string((string)$verification['statuslabel']),
]);
$rows[] = new html_table_row([
    get_string('letter_verify_field_generated', 'local_grupomakro_core'),
    userdate((int)$verification['generatedat']),
]);
$rows[] = new html_table_row([
    get_string('letter_verify_field_version', 'local_grupomakro_core'),
    (int)$verification['version'],
]);

$table = new html_table();
$table->attributes['class'] = 'generaltable';
$table->data = $rows;
echo html_writer::table($table);

echo $OUTPUT->footer();
