<?php
// This file is part of Moodle - https://moodle.org/

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use context_system;
use local_grupomakro_core\local\letters\manager;

$pluginname = 'local_grupomakro_core';

require_login();
require_capability('local/grupomakro_core:manageletters', context_system::instance());

admin_externalpage_setup('grupomakro_core_letter_types');

$action = optional_param('action', '', PARAM_ALPHA);
$editid = optional_param('editid', 0, PARAM_INT);

if ($action === 'save' && data_submitted()) {
    require_sesskey();
    $record = new stdClass();
    $record->id = optional_param('id', 0, PARAM_INT);
    $record->code = trim(required_param('code', PARAM_ALPHANUMEXT));
    $record->name = trim(required_param('name', PARAM_TEXT));
    $record->warningtext = optional_param('warningtext', '', PARAM_RAW);
    $record->cost = (float)optional_param('cost', 0, PARAM_FLOAT);
    $record->active = optional_param('active', 0, PARAM_BOOL) ? 1 : 0;
    $record->deliverymode = optional_param('deliverymode', manager::DELIVERY_DIGITAL, PARAM_ALPHA);
    $record->generationmode = optional_param('generationmode', manager::GENERATION_AUTO, PARAM_ALPHA);
    $record->autostamp = optional_param('autostamp', 0, PARAM_BOOL) ? 1 : 0;
    $record->autosignature = optional_param('autosignature', 0, PARAM_BOOL) ? 1 : 0;
    $record->stampimageurl = optional_param('stampimageurl', '', PARAM_RAW_TRIMMED);
    $record->signatureimageurl = optional_param('signatureimageurl', '', PARAM_RAW_TRIMMED);
    $record->odoo_product_id = optional_param('odoo_product_id', 0, PARAM_INT);
    $record->template_html = optional_param('template_html', '', PARAM_RAW);
    $record->usermodified = $USER->id;
    $record->timemodified = time();

    if ($record->id > 0) {
        $DB->update_record('gmk_letter_type', $record);
        $lettertypeid = $record->id;
    } else {
        $record->timecreated = time();
        $lettertypeid = $DB->insert_record('gmk_letter_type', $record);
    }

    $datasetids = optional_param_array('datasets', [], PARAM_INT);
    $DB->delete_records('gmk_letter_type_dataset', ['lettertypeid' => $lettertypeid]);
    $sort = 1;
    foreach ($datasetids as $datasetid) {
        $map = (object)[
            'lettertypeid' => $lettertypeid,
            'datasetdefid' => (int)$datasetid,
            'sortorder' => $sort++,
            'usermodified' => $USER->id,
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $DB->insert_record('gmk_letter_type_dataset', $map);
    }
    redirect(new moodle_url('/local/grupomakro_core/pages/lettertypes.php'), get_string('letters_saved', $pluginname), 1, \core\output\notification::NOTIFY_SUCCESS);
}

if ($action === 'delete') {
    require_sesskey();
    $id = required_param('id', PARAM_INT);
    $DB->delete_records('gmk_letter_type_dataset', ['lettertypeid' => $id]);
    $DB->delete_records('gmk_letter_type', ['id' => $id]);
    redirect(new moodle_url('/local/grupomakro_core/pages/lettertypes.php'), get_string('letters_deleted', $pluginname), 1, \core\output\notification::NOTIFY_SUCCESS);
}

$editing = null;
if ($editid > 0) {
    $editing = $DB->get_record('gmk_letter_type', ['id' => $editid], '*', IGNORE_MISSING);
}
$selecteddatasets = [];
if ($editing) {
    $selecteddatasets = array_map(function($rec) {
        return (int)$rec->datasetdefid;
    }, $DB->get_records('gmk_letter_type_dataset', ['lettertypeid' => $editing->id], 'sortorder ASC', 'datasetdefid'));
}
$datasets = manager::get_all_dataset_definitions();
$lettertypes = $DB->get_records('gmk_letter_type', [], 'name ASC');
$labels = manager::get_status_labels();

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('letters_catalog_title', $pluginname));

echo html_writer::start_tag('form', ['method' => 'post', 'action' => new moodle_url('/local/grupomakro_core/pages/lettertypes.php')]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'save']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $editing ? $editing->id : 0]);

echo html_writer::start_div('mb-3');
echo html_writer::label(get_string('letters_field_code', $pluginname), 'id_code');
echo html_writer::empty_tag('input', [
    'id' => 'id_code',
    'type' => 'text',
    'name' => 'code',
    'value' => $editing ? s($editing->code) : '',
    'required' => 'required',
    'class' => 'form-control',
]);
echo html_writer::end_div();

echo html_writer::start_div('mb-3');
echo html_writer::label(get_string('letters_field_name', $pluginname), 'id_name');
echo html_writer::empty_tag('input', [
    'id' => 'id_name',
    'type' => 'text',
    'name' => 'name',
    'value' => $editing ? s($editing->name) : '',
    'required' => 'required',
    'class' => 'form-control',
]);
echo html_writer::end_div();

echo html_writer::start_div('mb-3');
echo html_writer::label(get_string('letters_field_warning', $pluginname), 'id_warningtext');
echo html_writer::tag('textarea', $editing ? s($editing->warningtext) : '', [
    'id' => 'id_warningtext',
    'name' => 'warningtext',
    'rows' => 3,
    'class' => 'form-control',
]);
echo html_writer::end_div();

echo html_writer::start_div('row');
echo html_writer::start_div('col-md-3 mb-3');
echo html_writer::label(get_string('letters_field_cost', $pluginname), 'id_cost');
echo html_writer::empty_tag('input', [
    'id' => 'id_cost',
    'type' => 'number',
    'step' => '0.01',
    'min' => '0',
    'name' => 'cost',
    'value' => $editing ? s((string)$editing->cost) : '0.00',
    'class' => 'form-control',
]);
echo html_writer::end_div();
echo html_writer::start_div('col-md-3 mb-3');
echo html_writer::label(get_string('letters_field_odoo_product', $pluginname), 'id_odoo_product_id');
echo html_writer::empty_tag('input', [
    'id' => 'id_odoo_product_id',
    'type' => 'number',
    'name' => 'odoo_product_id',
    'value' => $editing ? s((string)$editing->odoo_product_id) : '0',
    'class' => 'form-control',
]);
echo html_writer::end_div();
echo html_writer::start_div('col-md-3 mb-3');
echo html_writer::label(get_string('letters_field_deliverymode', $pluginname), 'id_deliverymode');
echo html_writer::select(
    [manager::DELIVERY_DIGITAL => get_string('letters_delivery_digital', $pluginname), manager::DELIVERY_FISICA => get_string('letters_delivery_fisica', $pluginname)],
    'deliverymode',
    $editing ? $editing->deliverymode : manager::DELIVERY_DIGITAL,
    false,
    ['id' => 'id_deliverymode', 'class' => 'custom-select']
);
echo html_writer::end_div();
echo html_writer::start_div('col-md-3 mb-3');
echo html_writer::label(get_string('letters_field_generationmode', $pluginname), 'id_generationmode');
echo html_writer::select(
    [manager::GENERATION_AUTO => get_string('letters_generation_auto', $pluginname), manager::GENERATION_MANUAL => get_string('letters_generation_manual', $pluginname)],
    'generationmode',
    $editing ? $editing->generationmode : manager::GENERATION_AUTO,
    false,
    ['id' => 'id_generationmode', 'class' => 'custom-select']
);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('row');
echo html_writer::start_div('col-md-4 mb-3');
echo html_writer::tag('label', html_writer::empty_tag('input', [
    'type' => 'checkbox',
    'name' => 'active',
    'value' => '1',
    'checked' => ($editing ? (int)$editing->active : 1) ? 'checked' : null,
]) . ' ' . get_string('letters_field_active', $pluginname));
echo html_writer::end_div();
echo html_writer::start_div('col-md-4 mb-3');
echo html_writer::tag('label', html_writer::empty_tag('input', [
    'type' => 'checkbox',
    'name' => 'autostamp',
    'value' => '1',
    'checked' => ($editing && (int)$editing->autostamp) ? 'checked' : null,
]) . ' ' . get_string('letters_field_autostamp', $pluginname));
echo html_writer::end_div();
echo html_writer::start_div('col-md-4 mb-3');
echo html_writer::tag('label', html_writer::empty_tag('input', [
    'type' => 'checkbox',
    'name' => 'autosignature',
    'value' => '1',
    'checked' => ($editing && (int)$editing->autosignature) ? 'checked' : null,
]) . ' ' . get_string('letters_field_autosignature', $pluginname));
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('row');
echo html_writer::start_div('col-md-6 mb-3');
echo html_writer::label(get_string('letters_field_stampimageurl', $pluginname), 'id_stampimageurl');
echo html_writer::empty_tag('input', [
    'id' => 'id_stampimageurl',
    'type' => 'text',
    'name' => 'stampimageurl',
    'value' => $editing ? s((string)$editing->stampimageurl) : '',
    'class' => 'form-control',
]);
echo html_writer::end_div();
echo html_writer::start_div('col-md-6 mb-3');
echo html_writer::label(get_string('letters_field_signatureimageurl', $pluginname), 'id_signatureimageurl');
echo html_writer::empty_tag('input', [
    'id' => 'id_signatureimageurl',
    'type' => 'text',
    'name' => 'signatureimageurl',
    'value' => $editing ? s((string)$editing->signatureimageurl) : '',
    'class' => 'form-control',
]);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('mb-3');
echo html_writer::label(get_string('letters_field_datasets', $pluginname), 'id_datasets');
foreach ($datasets as $dataset) {
    $ischecked = in_array((int)$dataset['id'], $selecteddatasets, true) ? 'checked' : null;
    echo html_writer::tag(
        'label',
        html_writer::empty_tag('input', [
            'type' => 'checkbox',
            'name' => 'datasets[]',
            'value' => $dataset['id'],
            'checked' => $ischecked,
            'style' => 'margin-right:6px;',
        ]) . s($dataset['name']) . ' (' . s($dataset['code']) . ')',
        ['style' => 'display:block;']
    );
}
echo html_writer::end_div();

echo html_writer::start_div('mb-3');
echo html_writer::label(get_string('letters_field_template', $pluginname), 'id_template_html');
echo html_writer::tag('textarea', $editing ? s($editing->template_html) : '', [
    'id' => 'id_template_html',
    'name' => 'template_html',
    'rows' => 10,
    'class' => 'form-control',
]);
echo html_writer::end_div();

echo html_writer::start_div('mb-4');
echo html_writer::empty_tag('input', [
    'type' => 'submit',
    'class' => 'btn btn-primary',
    'value' => get_string('letters_save', $pluginname),
]);
if ($editing) {
    echo ' ' . html_writer::link(new moodle_url('/local/grupomakro_core/pages/lettertypes.php'), get_string('letters_cancel_edit', $pluginname), ['class' => 'btn btn-secondary']);
}
echo html_writer::end_div();
echo html_writer::end_tag('form');

$table = new html_table();
$table->head = [
    get_string('letters_field_code', $pluginname),
    get_string('letters_field_name', $pluginname),
    get_string('letters_field_cost', $pluginname),
    get_string('letters_field_deliverymode', $pluginname),
    get_string('letters_field_generationmode', $pluginname),
    get_string('letters_field_active', $pluginname),
    get_string('actions', $pluginname),
];
$table->data = [];
foreach ($lettertypes as $lt) {
    $editurl = new moodle_url('/local/grupomakro_core/pages/lettertypes.php', ['editid' => $lt->id, 'sesskey' => sesskey()]);
    $deleteurl = new moodle_url('/local/grupomakro_core/pages/lettertypes.php', ['action' => 'delete', 'id' => $lt->id, 'sesskey' => sesskey()]);
    $table->data[] = [
        s($lt->code),
        s($lt->name),
        number_format((float)$lt->cost, 2),
        s($lt->deliverymode),
        s($lt->generationmode),
        ((int)$lt->active ? get_string('yes') : get_string('no')),
        html_writer::link($editurl, get_string('edit')) . ' | ' . html_writer::link($deleteurl, get_string('delete')),
    ];
}

echo html_writer::tag('h4', get_string('letters_catalog_list', $pluginname));
echo html_writer::table($table);
echo $OUTPUT->footer();
