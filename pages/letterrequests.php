<?php
// This file is part of Moodle - https://moodle.org/

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use context_system;
use local_grupomakro_core\local\letters\manager;

$pluginname = 'local_grupomakro_core';

require_login();
require_capability('local/grupomakro_core:managerequests', context_system::instance());

admin_externalpage_setup('grupomakro_core_letter_requests');

$action = optional_param('action', '', PARAM_ALPHA);
$statusfilter = optional_param('status', '', PARAM_TEXT);
$requestid = optional_param('requestid', 0, PARAM_INT);

if ($action === 'updatestatus' && data_submitted()) {
    require_sesskey();
    $newstatus = required_param('newstatus', PARAM_TEXT);
    $note = optional_param('note', '', PARAM_RAW_TRIMMED);
    manager::set_request_status($requestid, $newstatus, (int)$USER->id, $note);
    redirect(
        new moodle_url('/local/grupomakro_core/pages/letterrequests.php', ['status' => $statusfilter]),
        get_string('letters_status_updated', $pluginname),
        1,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'generatedoc') {
    require_sesskey();
    manager::generate_document_for_request($requestid, (int)$USER->id);
    $request = $DB->get_record('gmk_letter_request', ['id' => $requestid], '*', MUST_EXIST);
    if ($request->deliverymode_snapshot === manager::DELIVERY_DIGITAL) {
        manager::set_request_status($requestid, manager::STATUS_GENERADA_DIGITAL, (int)$USER->id, 'Documento generado manualmente');
    } else if ($request->status === manager::STATUS_PAGADA) {
        manager::set_request_status($requestid, manager::STATUS_PENDIENTE_GESTION, (int)$USER->id, 'Documento generado manualmente');
    }
    redirect(
        new moodle_url('/local/grupomakro_core/pages/letterrequests.php', ['status' => $statusfilter]),
        get_string('letters_doc_generated', $pluginname),
        1,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

$download = optional_param('download', 0, PARAM_BOOL);
if ($download && $requestid > 0) {
    require_sesskey();
    $payload = manager::download_document_payload($requestid, (int)$USER->id, true);
    $binary = base64_decode($payload['contentbase64']);
    $filename = clean_filename((string)$payload['filename']);
    header('Content-Type: ' . (string)$payload['mimetype']);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($binary));
    header('Cache-Control: private, no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    echo $binary;
    exit;
}

$requests = manager::get_requests(0, true, $statusfilter);
$statuslabels = manager::get_status_labels();

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('letters_requests_title', $pluginname));

echo html_writer::start_tag('form', ['method' => 'get', 'action' => new moodle_url('/local/grupomakro_core/pages/letterrequests.php')]);
echo html_writer::start_div('mb-3');
echo html_writer::label(get_string('letters_filter_status', $pluginname), 'id_status');
$statusoptions = ['' => get_string('letters_all', $pluginname)];
foreach ($statuslabels as $statuscode => $statusname) {
    $statusoptions[$statuscode] = $statusname;
}
echo html_writer::select($statusoptions, 'status', $statusfilter, false, ['id' => 'id_status', 'class' => 'custom-select']);
echo ' ';
echo html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-secondary', 'value' => get_string('letters_filter', $pluginname)]);
echo html_writer::end_div();
echo html_writer::end_tag('form');

$table = new html_table();
$table->head = [
    get_string('letters_col_id', $pluginname),
    get_string('user', $pluginname),
    get_string('letters_field_name', $pluginname),
    get_string('letters_field_cost', $pluginname),
    get_string('state', $pluginname),
    get_string('letters_col_created', $pluginname),
    get_string('letters_actions', $pluginname),
];
$table->data = [];

foreach ($requests as $item) {
    $user = $DB->get_record('user', ['id' => $item['userid']], 'id,firstname,lastname,username', IGNORE_MISSING);
    $username = $user ? fullname($user) . ' (' . s($user->username) . ')' : ('ID ' . $item['userid']);
    $actions = [];

    $actions[] = html_writer::link(
        new moodle_url('/local/grupomakro_core/pages/letterrequests.php', [
            'status' => $statusfilter,
            'requestid' => $item['id'],
            'edit' => 1,
            'sesskey' => sesskey(),
        ]),
        get_string('letters_manage', $pluginname)
    );

    if (empty($item['document_available'])) {
        $actions[] = html_writer::link(
            new moodle_url('/local/grupomakro_core/pages/letterrequests.php', [
                'action' => 'generatedoc',
                'requestid' => $item['id'],
                'status' => $statusfilter,
                'sesskey' => sesskey(),
            ]),
            get_string('letters_generate_doc', $pluginname)
        );
    }

    if (!empty($item['document_available'])) {
        $actions[] = html_writer::link(
            new moodle_url('/local/grupomakro_core/pages/letterrequests.php', [
                'download' => 1,
                'requestid' => $item['id'],
                'sesskey' => sesskey(),
            ]),
            get_string('download', $pluginname)
        );
    }

    $table->data[] = [
        $item['id'],
        $username,
        s($item['lettertypename']),
        number_format((float)$item['cost_snapshot'], 2),
        s($item['statuslabel']),
        userdate((int)$item['timecreated']),
        implode(' | ', $actions),
    ];
}

echo html_writer::table($table);

$editmode = optional_param('edit', 0, PARAM_BOOL);
if ($editmode && $requestid > 0) {
    $detail = manager::get_request_detail($requestid, (int)$USER->id, true);
    echo html_writer::tag('h4', get_string('letters_manage_request', $pluginname) . ' #' . $requestid);
    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => new moodle_url('/local/grupomakro_core/pages/letterrequests.php'),
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'updatestatus']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'requestid', 'value' => $requestid]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'status', 'value' => $statusfilter]);

    echo html_writer::start_div('mb-3');
    echo html_writer::label(get_string('letters_new_status', $pluginname), 'id_newstatus');
    echo html_writer::select($statuslabels, 'newstatus', $detail['status'], false, ['id' => 'id_newstatus', 'class' => 'custom-select']);
    echo html_writer::end_div();

    echo html_writer::start_div('mb-3');
    echo html_writer::label(get_string('letters_status_note', $pluginname), 'id_note');
    echo html_writer::tag('textarea', '', [
        'id' => 'id_note',
        'name' => 'note',
        'rows' => 3,
        'class' => 'form-control',
    ]);
    echo html_writer::end_div();

    echo html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'btn btn-primary', 'value' => get_string('letters_update_status', $pluginname)]);
    echo html_writer::end_tag('form');
}

echo $OUTPUT->footer();
