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

$templatevariables = [
    ['token' => 'student.id', 'label' => 'student.id'],
    ['token' => 'student.username', 'label' => 'student.username'],
    ['token' => 'student.firstname', 'label' => 'student.firstname'],
    ['token' => 'student.lastname', 'label' => 'student.lastname'],
    ['token' => 'student.fullname', 'label' => 'student.fullname'],
    ['token' => 'student.email', 'label' => 'student.email'],
    ['token' => 'student.document_number', 'label' => 'student.document_number'],
    ['token' => 'request.id', 'label' => 'request.id'],
    ['token' => 'request.status', 'label' => 'request.status'],
    ['token' => 'request.letter_name', 'label' => 'request.letter_name'],
    ['token' => 'request.letter_code', 'label' => 'request.letter_code'],
    ['token' => 'request.cost', 'label' => 'request.cost'],
    ['token' => 'request.observation', 'label' => 'request.observation'],
    ['token' => 'date.today', 'label' => 'date.today'],
];
$datasetcodes = array_values(array_map(function($dataset) {
    return (string)$dataset['code'];
}, $datasets));
$datasetcodemap = [];
foreach ($datasets as $dataset) {
    $datasetcodemap[(string)$dataset['code']] = (int)$dataset['id'];
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('letters_catalog_title', $pluginname));

echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => new moodle_url('/local/grupomakro_core/pages/lettertypes.php'),
    'id' => 'gmk-lettertype-form',
]);
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
echo html_writer::label(get_string('letters_field_template', $pluginname), 'id_template_canvas');

echo html_writer::start_div('', ['id' => 'gmk-template-builder']);
echo html_writer::start_div('gmk-template-grid');

echo html_writer::start_div('gmk-template-panel gmk-template-panel-left');
echo html_writer::tag('h5', 'Variables escalares');
foreach ($templatevariables as $var) {
    echo html_writer::tag(
        'button',
        s($var['label']),
        [
            'type' => 'button',
            'class' => 'gmk-token-item',
            'draggable' => 'true',
            'data-token' => s($var['token']),
            'data-kind' => 'scalar',
            'title' => '{{' . s($var['token']) . '}}',
        ]
    );
}
echo html_writer::tag('h5', 'Datasets');
foreach ($datasets as $dataset) {
    $token = 'DATASET:' . $dataset['code'];
    echo html_writer::tag(
        'button',
        s($dataset['code']),
        [
            'type' => 'button',
            'class' => 'gmk-token-item gmk-token-dataset',
            'draggable' => 'true',
            'data-token' => s($token),
            'data-kind' => 'dataset',
            'data-datasetid' => (string)$dataset['id'],
            'title' => '{{' . s($token) . '}}',
        ]
    );
}
echo html_writer::end_div();

echo html_writer::start_div('gmk-template-panel gmk-template-panel-center');
echo html_writer::start_div('gmk-template-toolbar');
echo html_writer::tag('button', 'Insertar párrafo', ['type' => 'button', 'id' => 'gmk-add-paragraph', 'class' => 'btn btn-secondary btn-sm']);
echo html_writer::tag('button', 'Aplicar HTML', ['type' => 'button', 'id' => 'gmk-apply-source', 'class' => 'btn btn-secondary btn-sm']);
echo html_writer::tag('button', 'Limpiar', ['type' => 'button', 'id' => 'gmk-clear-canvas', 'class' => 'btn btn-secondary btn-sm']);
echo html_writer::end_div();
echo html_writer::start_div('gmk-template-canvas', [
    'id' => 'id_template_canvas',
    'contenteditable' => 'true',
    'spellcheck' => 'false',
    'data-placeholder' => 'Arrastra variables o datasets aqui para construir la carta.',
]);
echo html_writer::end_div();
echo html_writer::tag('label', 'HTML de plantilla (modo avanzado)', ['for' => 'id_template_source', 'class' => 'mt-2']);
echo html_writer::tag('textarea', $editing ? s($editing->template_html) : '', [
    'id' => 'id_template_source',
    'rows' => 8,
    'class' => 'form-control',
]);
echo html_writer::end_div();

echo html_writer::start_div('gmk-template-panel gmk-template-panel-right');
echo html_writer::tag('h5', 'Vista previa');
echo html_writer::tag('div', '', ['id' => 'gmk-template-preview', 'class' => 'gmk-template-preview']);
echo html_writer::end_div();

echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::tag('textarea', $editing ? s($editing->template_html) : '', [
    'id' => 'id_template_html',
    'name' => 'template_html',
    'rows' => 10,
    'class' => 'form-control',
    'style' => 'display:none;',
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

$buildercss = <<<CSS
#gmk-template-builder { border: 1px solid #d0d7de; border-radius: 6px; background: #f8f9fb; }
.gmk-template-grid { display: grid; grid-template-columns: 240px 1fr 360px; gap: 12px; padding: 12px; }
.gmk-template-panel { background: #fff; border: 1px solid #d0d7de; border-radius: 6px; padding: 10px; min-height: 320px; }
.gmk-template-panel h5 { margin: 0 0 8px 0; font-size: 14px; font-weight: 700; }
.gmk-token-item { display: block; width: 100%; margin-bottom: 6px; text-align: left; border: 1px solid #ced4da; background: #fff; border-radius: 4px; padding: 6px 8px; cursor: grab; font-family: monospace; font-size: 12px; }
.gmk-token-item:hover { background: #eef5ff; border-color: #6ea8fe; }
.gmk-token-dataset { background: #eefaf3; border-color: #8fd3a9; }
.gmk-template-toolbar { display: flex; gap: 8px; margin-bottom: 8px; flex-wrap: wrap; }
.gmk-template-canvas { border: 1px dashed #9aa5b1; border-radius: 6px; min-height: 260px; background: #fff; padding: 10px; overflow: auto; }
.gmk-template-canvas:empty:before { content: attr(data-placeholder); color: #7a8691; font-style: italic; }
.gmk-token-chip { display: inline-block; border: 1px solid #7aa2e3; background: #e8f0ff; color: #0a58ca; border-radius: 12px; padding: 2px 8px; margin: 2px; font-family: monospace; font-size: 12px; white-space: nowrap; }
.gmk-token-chip.gmk-dataset-chip { border-color: #74c69d; background: #e8f8ef; color: #1d6f42; }
.gmk-template-preview { border: 1px solid #d0d7de; border-radius: 6px; background: #fff; min-height: 420px; max-height: 620px; overflow: auto; padding: 12px; }
.gmk-template-preview table { width: 100%; border-collapse: collapse; margin-top: 8px; }
.gmk-template-preview table th, .gmk-template-preview table td { border: 1px solid #dee2e6; padding: 5px 7px; font-size: 12px; }
.gmk-template-preview table th { background: #f1f3f5; }
.gmk-preview-missing { color: #b02a37; font-family: monospace; }
@media (max-width: 1280px) {
  .gmk-template-grid { grid-template-columns: 1fr; }
}
CSS;
echo html_writer::tag('style', $buildercss);

$datasetcodesjson = json_encode($datasetcodes);
$datasetcodemapjson = json_encode($datasetcodemap);
$builderjs = <<<JS
(function() {
  const form = document.getElementById('gmk-lettertype-form');
  const hiddenInput = document.getElementById('id_template_html');
  const sourceInput = document.getElementById('id_template_source');
  const canvas = document.getElementById('id_template_canvas');
  const preview = document.getElementById('gmk-template-preview');
  const applySourceBtn = document.getElementById('gmk-apply-source');
  const addParagraphBtn = document.getElementById('gmk-add-paragraph');
  const clearBtn = document.getElementById('gmk-clear-canvas');
  const tokenButtons = Array.prototype.slice.call(document.querySelectorAll('.gmk-token-item'));
  const datasetCodes = $datasetcodesjson || [];
  const datasetCodeMap = $datasetcodemapjson || {};

  if (!form || !hiddenInput || !sourceInput || !canvas || !preview) {
    return;
  }

  function escapeHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function isDatasetToken(token) {
    return String(token).indexOf('DATASET:') === 0;
  }

  function buildTokenChip(token) {
    const chip = document.createElement('span');
    chip.className = 'gmk-token-chip' + (isDatasetToken(token) ? ' gmk-dataset-chip' : '');
    chip.setAttribute('contenteditable', 'false');
    chip.setAttribute('data-token', token);
    chip.textContent = '{{' + token + '}}';
    return chip;
  }

  function replaceTemplateWithChips(template) {
    let html = String(template || '');
    html = html.replace(/\\{\\{\\s*DATASET:([a-zA-Z0-9_]+)\\s*\\}\\}/g, function(_, code) {
      return '<span class="gmk-token-chip gmk-dataset-chip" contenteditable="false" data-token="DATASET:' + escapeHtml(code) + '">{{DATASET:' + escapeHtml(code) + '}}</span>';
    });
    html = html.replace(/\\{\\{\\s*([a-zA-Z0-9\\._]+)\\s*\\}\\}/g, function(_, token) {
      return '<span class="gmk-token-chip" contenteditable="false" data-token="' + escapeHtml(token) + '">{{' + escapeHtml(token) + '}}</span>';
    });
    return html;
  }

  function setCanvasFromTemplate(template) {
    canvas.innerHTML = replaceTemplateWithChips(template || '');
  }

  function ensureDatasetCheckedById(datasetId) {
    if (!datasetId) {
      return;
    }
    const checkbox = form.querySelector('input[name="datasets[]"][value="' + String(datasetId) + '"]');
    if (checkbox) {
      checkbox.checked = true;
    }
  }

  function ensureDatasetsFromTemplate(template) {
    const seen = {};
    String(template || '').replace(/\\{\\{\\s*DATASET:([a-zA-Z0-9_]+)\\s*\\}\\}/g, function(_, code) {
      if (!seen[code] && Object.prototype.hasOwnProperty.call(datasetCodeMap, code)) {
        seen[code] = true;
        ensureDatasetCheckedById(datasetCodeMap[code]);
      }
      return _;
    });
  }

  function getTemplateFromCanvas() {
    const clone = canvas.cloneNode(true);
    const chips = clone.querySelectorAll('.gmk-token-chip');
    chips.forEach(function(chip) {
      const token = chip.getAttribute('data-token') || chip.textContent.replace(/^\\{\\{|\\}\\}$/g, '');
      chip.replaceWith(document.createTextNode('{{' + token + '}}'));
    });
    return clone.innerHTML.trim();
  }

  function datasetPreviewHtml(code) {
    if (code === 'asignaturas_cursadas') {
      return '' +
        '<table><thead><tr><th>Asignatura</th><th>Periodo</th><th>Nota</th><th>Creditos</th><th>Progreso</th></tr></thead>' +
        '<tbody>' +
        '<tr><td>Matematica I</td><td>2026-1</td><td>92</td><td>4</td><td>100%</td></tr>' +
        '<tr><td>Programacion</td><td>2026-1</td><td>89</td><td>4</td><td>100%</td></tr>' +
        '</tbody></table>';
    }
    if (code === 'resumen_creditos') {
      return '' +
        '<table><thead><tr><th>Metrica</th><th>Valor</th></tr></thead>' +
        '<tbody>' +
        '<tr><td>Total creditos cursados</td><td>84</td></tr>' +
        '<tr><td>Total creditos aprobados</td><td>76</td></tr>' +
        '<tr><td>Total asignaturas</td><td>21</td></tr>' +
        '</tbody></table>';
    }
    if (code === 'periodo_actual') {
      return '' +
        '<table><thead><tr><th>Metrica</th><th>Valor</th></tr></thead>' +
        '<tbody>' +
        '<tr><td>Periodo academico actual</td><td>2026-1</td></tr>' +
        '<tr><td>ID de periodo</td><td>12</td></tr>' +
        '</tbody></table>';
    }
    return '<div class="alert alert-info">Preview dataset: ' + escapeHtml(code) + '</div>';
  }

  function renderPreview(template) {
    const sampleVars = {
      'student.id': '1024',
      'student.username': 'estudiante.demo',
      'student.firstname': 'Ana',
      'student.lastname': 'Lopez',
      'student.fullname': 'Ana Lopez',
      'student.email': 'ana.lopez@example.edu',
      'student.document_number': '8-123-456',
      'request.id': '560',
      'request.status': 'solicitada',
      'request.letter_name': 'Carta demo',
      'request.letter_code': 'carta_demo',
      'request.cost': '10.00',
      'request.observation': 'Observacion de prueba',
      'date.today': (new Date()).toISOString().slice(0, 10)
    };

    let html = String(template || '');
    html = html.replace(/\\{\\{\\s*DATASET:([a-zA-Z0-9_]+)\\s*\\}\\}/g, function(_, code) {
      if (datasetCodes.indexOf(code) === -1) {
        return '<span class="gmk-preview-missing">{{DATASET:' + escapeHtml(code) + '}}</span>';
      }
      return datasetPreviewHtml(code);
    });
    html = html.replace(/\\{\\{\\s*([a-zA-Z0-9\\._]+)\\s*\\}\\}/g, function(_, token) {
      if (Object.prototype.hasOwnProperty.call(sampleVars, token)) {
        return escapeHtml(sampleVars[token]);
      }
      return '<span class="gmk-preview-missing">{{' + escapeHtml(token) + '}}</span>';
    });

    preview.innerHTML = html || '<div class="text-muted">Sin contenido para vista previa.</div>';
  }

  function syncFromCanvas() {
    const tpl = getTemplateFromCanvas();
    sourceInput.value = tpl;
    hiddenInput.value = tpl;
    ensureDatasetsFromTemplate(tpl);
    renderPreview(tpl);
  }

  function syncFromSourceToCanvas() {
    const tpl = String(sourceInput.value || '');
    hiddenInput.value = tpl;
    setCanvasFromTemplate(tpl);
    ensureDatasetsFromTemplate(tpl);
    renderPreview(tpl);
  }

  function placeCaretAtEnd(element) {
    element.focus();
    const range = document.createRange();
    range.selectNodeContents(element);
    range.collapse(false);
    const sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
  }

  function insertToken(token, datasetId) {
    const chip = buildTokenChip(token);
    if (isDatasetToken(token)) {
      ensureDatasetCheckedById(datasetId || datasetCodeMap[String(token).replace(/^DATASET:/, '')]);
    }
    const sel = window.getSelection();
    if (!sel || sel.rangeCount === 0 || !canvas.contains(sel.anchorNode)) {
      placeCaretAtEnd(canvas);
    }
    const range = window.getSelection().getRangeAt(0);
    range.deleteContents();
    range.insertNode(chip);
    range.setStartAfter(chip);
    range.collapse(true);
    sel.removeAllRanges();
    sel.addRange(range);
    syncFromCanvas();
  }

  tokenButtons.forEach(function(btn) {
    btn.addEventListener('click', function() {
      insertToken(btn.getAttribute('data-token'), btn.getAttribute('data-datasetid'));
    });
    btn.addEventListener('dragstart', function(ev) {
      ev.dataTransfer.setData('text/plain', btn.getAttribute('data-token'));
      ev.dataTransfer.setData('text/datasetid', btn.getAttribute('data-datasetid') || '');
      ev.dataTransfer.effectAllowed = 'copy';
    });
  });

  canvas.addEventListener('dragover', function(ev) {
    ev.preventDefault();
    ev.dataTransfer.dropEffect = 'copy';
  });
  canvas.addEventListener('drop', function(ev) {
    ev.preventDefault();
    const token = ev.dataTransfer.getData('text/plain');
    const datasetId = ev.dataTransfer.getData('text/datasetid');
    if (token) {
      insertToken(token, datasetId);
    }
  });
  canvas.addEventListener('input', syncFromCanvas);

  sourceInput.addEventListener('input', function() {
    hiddenInput.value = sourceInput.value || '';
    renderPreview(hiddenInput.value);
  });
  sourceInput.addEventListener('blur', syncFromSourceToCanvas);

  if (applySourceBtn) {
    applySourceBtn.addEventListener('click', function() {
      syncFromSourceToCanvas();
    });
  }
  if (addParagraphBtn) {
    addParagraphBtn.addEventListener('click', function() {
      canvas.focus();
      document.execCommand('insertParagraph', false);
      syncFromCanvas();
    });
  }
  if (clearBtn) {
    clearBtn.addEventListener('click', function() {
      canvas.innerHTML = '';
      syncFromCanvas();
      canvas.focus();
    });
  }

  form.addEventListener('submit', function() {
    hiddenInput.value = sourceInput.value || getTemplateFromCanvas();
  });

  syncFromSourceToCanvas();
})();
JS;
echo html_writer::script($builderjs);

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
