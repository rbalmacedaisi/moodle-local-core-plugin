<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Diploma template editor page.
 *
 * @package    local_grupomakro_core
 * @copyright  2024 Solutto Consulting
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

$plugin_name = 'local_grupomakro_core';
$assetfile = __DIR__ . '/../js/components/diplomatemplates.js';
$assetversion = is_readable($assetfile)
    ? (int)filemtime($assetfile)
    : (!empty($CFG->themerev) ? (int)$CFG->themerev : 1);
require_login();

$PAGE->set_url($CFG->wwwroot . '/local/grupomakro_core/pages/diplomatemplates.php');
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_title(get_string('diploma_templates_title', $plugin_name));
$PAGE->set_heading(get_string('diploma_templates_title', $plugin_name));
$PAGE->set_pagelayout('admin');
$PAGE->navbar->add(get_string('admin_category_label', $plugin_name),
    new moodle_url('/local/grupomakro_core/pages/academicpanel.php'));
$PAGE->navbar->add(get_string('diploma_templates', $plugin_name));

require_capability('local/grupomakro_core:managediplomas', $context);

// Prepare strings for JS.
$strings = new stdClass();
$strings->save = get_string('save', $plugin_name);
$strings->cancel = get_string('cancel', $plugin_name);
$strings->back = get_string('back', $plugin_name);
$strings->name = get_string('name', $plugin_name);
$strings->description = get_string('diploma_template_description', $plugin_name);
$strings->status = get_string('diploma_template_status', $plugin_name);
$strings->active = get_string('diploma_template_active', $plugin_name);
$strings->inactive = get_string('diploma_template_inactive', $plugin_name);
$strings->background = get_string('diploma_background', $plugin_name);
$strings->background_help = get_string('diploma_background_help', $plugin_name);
$strings->upload_background = get_string('diploma_upload_background', $plugin_name);
$strings->replace_background = get_string('diploma_replace_background', $plugin_name);
$strings->no_background = get_string('diploma_no_background', $plugin_name);
$strings->canvas = get_string('diploma_canvas', $plugin_name);
$strings->canvas_help = get_string('diploma_canvas_help', $plugin_name);
$strings->fields = get_string('diploma_fields', $plugin_name);
$strings->add_element = get_string('diploma_add_element', $plugin_name);
$strings->element_type = get_string('diploma_element_type', $plugin_name);
$strings->type_variable = get_string('diploma_element_type_variable', $plugin_name);
$strings->type_custom = get_string('diploma_element_type_custom', $plugin_name);
$strings->type_static = get_string('diploma_element_type_static', $plugin_name);
$strings->type_qr = get_string('diploma_element_type_qr', $plugin_name);
$strings->variable = get_string('diploma_variable', $plugin_name);
$strings->custom_text = get_string('diploma_custom_text', $plugin_name);
$strings->static_text = get_string('diploma_static_text', $plugin_name);
$strings->position = get_string('diploma_position', $plugin_name);
$strings->position_x = get_string('diploma_position_x', $plugin_name);
$strings->position_y = get_string('diploma_position_y', $plugin_name);
$strings->size = get_string('diploma_size', $plugin_name);
$strings->size_width = get_string('diploma_size_width', $plugin_name);
$strings->size_height = get_string('diploma_size_height', $plugin_name);
$strings->rotation = get_string('diploma_rotation', $plugin_name);
$strings->rotation_deg = get_string('diploma_rotation_deg', $plugin_name);
$strings->font = get_string('diploma_font', $plugin_name);
$strings->font_size = get_string('diploma_font_size', $plugin_name);
$strings->font_weight = get_string('diploma_font_weight', $plugin_name);
$strings->weight_normal = get_string('diploma_font_weight_normal', $plugin_name);
$strings->weight_bold = get_string('diploma_font_weight_bold', $plugin_name);
$strings->font_color = get_string('diploma_font_color', $plugin_name);
$strings->align = get_string('diploma_text_align', $plugin_name);
$strings->align_left = get_string('diploma_align_left', $plugin_name);
$strings->align_center = get_string('diploma_align_center', $plugin_name);
$strings->align_right = get_string('diploma_align_right', $plugin_name);
$strings->line_height = get_string('diploma_line_height', $plugin_name);
$strings->z_index = get_string('diploma_z_index', $plugin_name);
$strings->save_template = get_string('diploma_save_template', $plugin_name);
$strings->save_ok = get_string('diploma_save_template_success', $plugin_name);
$strings->delete_template = get_string('diploma_delete_template', $plugin_name);
$strings->delete_confirm = get_string('diploma_delete_template_confirm', $plugin_name);
$strings->deleted_ok = get_string('diploma_template_deleted', $plugin_name);
$strings->no_templates = get_string('diploma_no_templates', $plugin_name);
$strings->duplicate = get_string('diploma_duplicate_template', $plugin_name);
$strings->duplicated_ok = get_string('diploma_template_duplicated', $plugin_name);
$strings->loading = get_string('diploma_editor_loading', $plugin_name);
$strings->no_fields = get_string('diploma_no_fields', $plugin_name);
$strings->orientation = get_string('diploma_template_orientation', $plugin_name);
$strings->orient_landscape = get_string('diploma_orientation_landscape', $plugin_name);
$strings->orient_portrait = get_string('diploma_orientation_portrait', $plugin_name);
$strings->paper_a4_h = get_string('diploma_paper_a4_h', $plugin_name);
$strings->paper_a4_v = get_string('diploma_paper_a4_v', $plugin_name);
$strings->paper_letter_h = get_string('diploma_paper_letter_h', $plugin_name);
$strings->paper_letter_v = get_string('diploma_paper_letter_v', $plugin_name);
$strings->template_name = get_string('diploma_template_name', $plugin_name);
$strings->new_template = 'Nueva plantilla';
$strings->bundle_title = get_string('diploma_bundle_title', $plugin_name);
$strings->bundle_help = get_string('diploma_bundle_help', $plugin_name);
$strings->bundle_name = get_string('diploma_bundle_name', $plugin_name);
$strings->bundle_prefix = get_string('diploma_bundle_prefix', $plugin_name);
$strings->bundle_next = get_string('diploma_bundle_next', $plugin_name);
$strings->bundle_active = get_string('diploma_bundle_active', $plugin_name);
$strings->bundle_assign = get_string('diploma_bundle_assign', $plugin_name);
$strings->bundle_none = get_string('diploma_bundle_none', $plugin_name);
$strings->bundle_new = get_string('diploma_bundle_new', $plugin_name);
$strings->bundle_save = get_string('diploma_bundle_save', $plugin_name);
$strings->bundle_delete = get_string('diploma_bundle_delete', $plugin_name);
$strings->bundle_delete_confirm = get_string('diploma_bundle_delete_confirm', $plugin_name, '{name}');
$strings->bundle_name_required = get_string('diploma_bundle_name_required', $plugin_name);
$strings->bundle_reset = get_string('diploma_bundle_reset', $plugin_name);
$strings->bundle_reset_confirm = get_string('diploma_bundle_reset_confirm', $plugin_name, (object)['name' => '{name}', 'next' => '{next}']);
$strings->bundle_reset_help = get_string('diploma_bundle_reset_help', $plugin_name);
$strings->bundle_reset_prompt = get_string('diploma_bundle_reset_prompt', $plugin_name);
$strings->bundle_reset_invalid = get_string('diploma_bundle_reset_invalid', $plugin_name);
$strings->bundle_reset_done = get_string('diploma_bundle_reset_done', $plugin_name, '{next}');
$strings->course_tab = get_string('diploma_course_tab', $plugin_name);
$strings->diploma_course_enable = get_string('diploma_course_enable', $plugin_name);
$strings->diploma_course_disable = get_string('diploma_course_disable', $plugin_name);
// Keep a non-encoded copy of the string object so the bundle
// management section below (rendered server-side, not from JS) can
// still access individual properties after $strings is replaced by
// the JSON-encoded version for JS consumption.
$bundleui = $strings;
$strings = json_encode($strings);

$token = get_logged_user_token();
$themeToken = get_theme_token();

echo $OUTPUT->header();

// Single Google Fonts <link> that covers every family used by the editor.
// Mirrors the FONT_OPTIONS catalog in diplomatemplates.js so every font
// that appears in the dropdown is actually loaded by the browser.
$diplomaFontFamilies = [
    'Roboto:100,300,400,500,700,900',
    'Montserrat:400,500,600,700',
    'Lato:400,700,900',
    'Poppins:400,500,600,700',
    'Open+Sans:400,500,600,700',
    'Raleway:400,500,600,700',
    'Oswald:400,500,700',
    'Noto+Sans:400,500,600,700',
    'Lora:400,500,600,700',
    'Merriweather:400,500,700',
    'PT+Sans:400,700',
    'PT+Serif:400,700',
    'Verdana',
    'Tahoma',
    'Georgia',
    'Playfair+Display:400,500,600,700,900',
    'Cormorant+Garamond:400,500,600,700',
    'EB+Garamond:400,500,600,700',
    'Libre+Baskerville:400,500,600,700',
    'Cinzel:400,500,700,900',
    'Cinzel+Decorative:400,700,900',
    'Marcellus',
    'Italiana',
    'Bodoni+Moda:400,500,600,700',
    'Abril+Fatface',
    'Garamond',
    'Petit+Formal+Script',
    'Dancing+Script:400,500,700',
    'Pacifico',
    'Great+Vibes:400,700',
    'Pinyon+Script',
    'Allura:400,700',
    'Tangerine:400,700',
    'Sacramento',
    'Alex+Brush',
    'Parisienne',
    'Mr+De+Haviland',
    'Italianno',
    'Mrs+Saint+Delafield',
    'Bilbo',
    'Rouge+Script',
    'Allison+Script',
    'La+Belle+Aurore',
    'Halimun'
];
echo '<link href="https://fonts.googleapis.com/css?family=' . implode('|', $diplomaFontFamilies) . '&display=swap" rel="stylesheet">' . "\n";

// Emit a @font-face block for every TTF present in
// local/grupomakro_core/tcpdf_fonts/ so the browser uses the EXACT
// same face the PDF renderer registers with TCPDF. Mirrors the
// logic in renderer::register_custom_font() on the PHP side.
//
// IMPORTANT: the family name in @font-face must match the family
// name used by FONT_OPTIONS in diplomatemplates.js EXACTLY (Google
// Fonts uses spaces in family names: 'Great Vibes', 'Petit Formal
// Script', etc.). Naively stripping the -Regular/-Bold suffix from
// the original filename yields 'GreatVibes' which does NOT match
// 'Great Vibes' in the CSS, so the browser keeps the fallback font.
// Use the explicit map below to map TTF key -> Google Fonts family
// name.
$ttfkeytofamily = [
    'opensans'              => 'Open Sans',
    'roboto'                => 'Roboto',
    'lato'                  => 'Lato',
    'montserrat'            => 'Montserrat',
    'poppins'               => 'Poppins',
    'raleway'               => 'Raleway',
    'oswald'                => 'Oswald',
    'playfairdisplay'       => 'Playfair Display',
    'cormorantgaramond'     => 'Cormorant Garamond',
    'ebgaramond'            => 'EB Garamond',
    'librebaskerville'      => 'Libre Baskerville',
    'lora'                  => 'Lora',
    'merriweather'          => 'Merriweather',
    'cinzel'                => 'Cinzel',
    'cinzeldecorative'      => 'Cinzel Decorative',
    'marcellus'             => 'Marcellus',
    'italiana'              => 'Italiana',
    'bodoni'                => 'Bodoni Moda',
    'abrilfatface'          => 'Abril Fatface',
    'petitformalscript'     => 'Petit Formal Script',
    'greatvibes'            => 'Great Vibes',
    'pinyonscript'          => 'Pinyon Script',
    'allura'                => 'Allura',
    'tangerine'             => 'Tangerine',
    'sacramento'            => 'Sacramento',
    'alexbrush'             => 'Alex Brush',
    'dancingscript'         => 'Dancing Script',
    'pacifico'              => 'Pacifico',
    'parisienne'            => 'Parisienne',
    'mrdehaviland'          => 'Mr De Haviland',
    'italianno'             => 'Italianno',
    'mrssaintdelafield'     => 'Mrs Saint Delafield',
    'bilbo'                 => 'Bilbo',
    'rougescript'           => 'Rouge Script',
    'allisonscript'         => 'Allison Script',
    'labelleaurore'         => 'La Belle Aurore',
    'halimun'               => 'Halimun',
    'notosans'              => 'Noto Sans',
    'notoserif'             => 'Noto Serif',
    'ptsans'                => 'PT Sans',
    'ptserif'               => 'PT Serif',
    'gotica'                => 'OPTIEngraversOldEnglish',
];

$fontdir = $CFG->dirroot . '/local/grupomakro_core/tcpdf_fonts';
if (is_dir($fontdir)) {
    // Just emit one @font-face per known key; the browser only needs
    // to load the file once even though the PDF may register it
    // several times with different weights.
    $emitted = [];
    foreach ($ttfkeytofamily as $key => $family) {
        $candidates = glob($fontdir . '/' . $key . '__*.ttf') ?: [];
        $ttf = null;
        foreach ($candidates as $cand) {
            if (strpos($cand, '[wght]') === false && strpos($cand, '[wdth') === false) {
                $ttf = $cand;
                break;
            }
        }
        if (!$ttf || !is_readable($ttf)) { continue; }
        if (isset($emitted[$family])) { continue; }
        $emitted[$family] = true;
        $url = new moodle_url('/local/grupomakro_core/pages/diploma_font.php', ['key' => $key]);
        echo '<style>@font-face{font-family:"' . addslashes($family) . '";font-style:normal;font-weight:400;src:url("' . $url->out(false) . '") format("truetype")}</style>' . "\n";
    }
}

echo <<<EOT
<link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.x/css/materialdesignicons.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.min.css" rel="stylesheet">
<div id="gmk-app">
    <v-app class="transparent">
        <v-main>
            <div>
                <diplomatemplates></diplomatemplates>
            </div>
        </v-main>
    </v-app>
</div>

<script src="https://cdn.jsdelivr.net/npm/vue@2.x/dist/vue.js"></script>
<script src="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.js"></script>
<script src="https://unpkg.com/axios/dist/axios.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
.theme--light.v-application { background: transparent !important; }
    .dpl-canvas-wrap { position: relative; width: 100%; margin: 0 auto; padding: 16px; background: #f4f4f4; border-radius: 8px; overflow: auto; box-shadow: 0 2px 10px rgba(0,0,0,.08); }
    .dpl-canvas { position: relative; background: #fff; box-shadow: 0 0 0 1px #d8d8d8 inset; transform-origin: 0 0; overflow: hidden; }
    .dpl-canvas-bg { position: absolute; top: 0; left: 0; width: 100%; height: 100%; object-fit: contain; pointer-events: none; user-select: none; z-index: 0; }
    .dpl-field { position: absolute; border: 1px dashed #1976d2; background: rgba(25,118,210,.06); cursor: move; user-select: none; box-sizing: border-box; z-index: 2; }
    .dpl-field.selected { border: 2px solid #ff9800; background: rgba(255,152,0,.1); z-index: 9999; }
    .dpl-field .label { position: absolute; top: -22px; left: -1px; background: #1976d2; color: #fff; padding: 1px 6px; font-size: 11px; border-radius: 3px 3px 0 0; pointer-events: none; }
    .dpl-field.selected .label { background: #ff9800; }
    .dpl-field .label .del { background: #f44336; border-radius: 50%; padding: 0 5px; margin-left: 4px; cursor: pointer; pointer-events: auto; }
    .dpl-handle { position: absolute; width: 10px; height: 10px; background: #fff; border: 2px solid #1976d2; border-radius: 50%; z-index: 10000; }
    .dpl-field.selected .dpl-handle { border-color: #ff9800; }
    .dpl-handle.tl { top: -5px; left: -5px; cursor: nwse-resize; }
    .dpl-handle.tr { top: -5px; right: -5px; cursor: nesw-resize; }
    .dpl-handle.bl { bottom: -5px; left: -5px; cursor: nesw-resize; }
    .dpl-handle.br { bottom: -5px; right: -5px; cursor: nesw-resize; }
    .dpl-handle.rotate { top: -22px; left: 50%; transform: translateX(-50%); cursor: alias; border-radius: 0; width: 14px; height: 14px; background: #ff9800; }
    .dpl-handle.rotate::before { content: "\21BB"; color: #fff; font-size: 12px; line-height: 10px; display: block; text-align: center; }
    .dpl-field-content { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; pointer-events: none; white-space: pre-wrap; word-break: break-word; overflow: hidden; padding: 2px; }
    .dpl-field-qr { display: flex; align-items: center; justify-content: center; color: #1976d2; font-size: 11px; }
    .dpl-empty { padding: 60px 20px; text-align: center; color: #888; }
</style>

<script>
    var strings = $strings;
    var userToken = $token;
    var themeToken = $themeToken || null;
</script>
EOT;

// ----------------------------------------------------------------------
// Section: courses eligible for per-student certificate generation.
// Mirrors the "Solo pendientes" UX of the diplomas-by-plan section:
// every course gets a card with a toggle on/off and the count of
// students that would appear in the new "Certificados por curso"
// tab once enabled.
// ----------------------------------------------------------------------
$eligibleCourses = \local_grupomakro_core\local\diplomas\manager::list_courses_with_eligibility(false);
echo '<template id="dpl-courses-partial">';
echo '<div class="dpl-courses-config" style="max-width:1100px;margin:0;padding:0;">';
echo '  <h2 style="margin:0 0 6px;font-size:22px;font-weight:700;color:#111827;">' .
    get_string('diploma_course_param_title', 'local_grupomakro_core') . '</h2>';
echo '  <p style="margin:0 0 18px;color:#6b7280;font-size:14px;">' .
    get_string('diploma_course_param_help', 'local_grupomakro_core') . '</p>';
echo '  <div id="dpl-courses-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px;">';
foreach ($eligibleCourses as $c) {
    $enabled = !empty($c['enabled']);
    $ecolor = $enabled ? '#16a34a' : '#94a3b8';
    $elabel = $enabled ? get_string('diploma_course_disable', 'local_grupomakro_core')
                       : get_string('diploma_course_enable', 'local_grupomakro_core');
    echo '    <div class="dpl-course-card" data-courseid="' . (int)$c['id'] . '" '
       . 'style="background:#fff;border:1px solid ' . ($enabled ? '#86efac' : '#e5e7eb') . ';'
       . 'border-radius:10px;padding:14px 16px;box-shadow:0 2px 8px rgba(15,23,42,.04);">';
    echo '      <div style="display:flex;align-items:flex-start;gap:10px;">';
    echo '        <div style="flex:1;min-width:0;">';
    echo '          <div style="font-size:14px;font-weight:600;color:#111827;'
       . 'white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" '
       . 'title="' . s($c['fullname']) . '">' . s($c['fullname']) . '</div>';
    echo '          <div style="font-size:11px;color:#6b7280;margin-top:2px;">'
       . s($c['shortname']) . '</div>';
    echo '        </div>';
    echo '        <button type="button" class="dpl-course-toggle" '
       . 'data-courseid="' . (int)$c['id'] . '" '
       . 'data-enabled="' . ($enabled ? '1' : '0') . '" '
       . 'style="background:' . $ecolor . ';color:#fff;border:0;border-radius:6px;'
       . 'padding:6px 12px;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap;">'
       . $elabel . '</button>';
    echo '      </div>';
    echo '      <div style="display:flex;gap:14px;margin-top:12px;font-size:12px;color:#6b7280;">';
    echo '        <span><strong style="color:#047857;">' . (int)$c['eligible_count']
       . '</strong> ' . get_string('diploma_course_ready', 'local_grupomakro_core') . '</span>';
    echo '        <span style="color:#94a3b8;">' . (int)$c['total_count']
       . ' ' . get_string('diploma_course_status', 'local_grupomakro_core') . '</span>';
    echo '      </div>';
    echo '    </div>';
}
echo '  </div>';
echo '</div>';
echo '</template>';

echo <<<'EOS'
<style>
.dpl-course-card { transition: border-color .15s, box-shadow .15s; }
.dpl-course-card:hover { box-shadow: 0 6px 18px rgba(15,23,42,.08); }
.dpl-course-toggle[disabled] { opacity:.6; cursor: not-allowed; }
</style>
<script>
(function () {
    'use strict';
    function postJSON(payload) {
        return fetch(window.location.origin + '/local/grupomakro_core/ajax.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(payload)
        }).then(function (r) { return r.json(); });
    }
    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest('.dpl-course-toggle');
        if (!btn) { return; }
        ev.preventDefault();
        if (btn.disabled) { return; }
        var courseid = parseInt(btn.getAttribute('data-courseid'), 10);
        var enabled = btn.getAttribute('data-enabled') !== '1';
        btn.disabled = true;
        var oldLabel = btn.textContent;
        btn.textContent = '...';
        postJSON({
            action: 'local_grupomakro_diploma_set_course_eligibility',
            courseid: courseid,
            enabled: enabled ? 1 : 0
        }).then(function (res) {
            if (res && res.status === 'success') {
                btn.setAttribute('data-enabled', enabled ? '1' : '0');
                btn.textContent = enabled ? (strings.diploma_course_disable || 'Disable') : (strings.diploma_course_enable || 'Enable');
                btn.style.background = enabled ? '#16a34a' : '#94a3b8';
                var card = btn.closest('.dpl-course-card');
                if (card) { card.style.borderColor = enabled ? '#86efac' : '#e5e7eb'; }
            } else {
                alert((res && res.message) || 'Error');
                btn.textContent = oldLabel;
            }
        }).catch(function (e) {
            alert(e.message || e);
            btn.textContent = oldLabel;
        }).finally(function () {
            btn.disabled = false;
        });
    });
})();
</script>
EOS;

// ----------------------------------------------------------------------
// Bundle management section: list existing bundles + a small "New bundle"
// panel, both wired by a tiny vanilla JS helper that POSTs to the
// dispatcher and refreshes the page on success.
// ----------------------------------------------------------------------
$bundles = \local_grupomakro_core\local\diplomas\manager::list_bundles();
echo '<template id="dpl-bundles-partial">';
echo '<div id="dpl-bundles-body">';
echo '<div class="dpl-bundles-config" style="max-width:1100px;margin:0;padding:0;">';
echo '  <h2 style="margin:0 0 6px;font-size:22px;font-weight:700;color:#111827;">' . $bundleui->bundle_title . '</h2>';
echo '  <p style="margin:0 0 18px;color:#6b7280;font-size:14px;">' . $bundleui->bundle_help . '</p>';
echo '  <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">';
echo '    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px;box-shadow:0 2px 8px rgba(15,23,42,.04);">';
if (empty($bundles)) {
    echo '      <div style="color:#6b7280;font-size:14px;">' . $bundleui->bundle_none . '</div>';
} else {
    echo '      <table style="width:100%;border-collapse:collapse;font-size:13px;">';
    echo '        <thead><tr style="text-align:left;color:#6b7280;font-weight:600;">';
    echo '          <th style="padding:6px;">' . $bundleui->bundle_name . '</th>';
    echo '          <th style="padding:6px;">' . $bundleui->bundle_prefix . '</th>';
    echo '          <th style="padding:6px;">' . $bundleui->bundle_next . '</th>';
    echo '          <th style="padding:6px;">' . $bundleui->bundle_active . '</th>';
    echo '          <th></th>';
    echo '        </tr></thead><tbody>';
    foreach ($bundles as $b) {
        $statusColor = !empty($b['active']) ? '#16a34a' : '#9ca3af';
        $statusLabel = !empty($b['active']) ? 'OK' : '-';
        echo '          <tr style="border-top:1px solid #f1f5f9;" data-bundleid="' . (int)$b['id'] . '">';
        echo '            <td style="padding:8px 6px;font-weight:500;">' . s($b['name']) . '</td>';
        echo '            <td style="padding:8px 6px;color:#6b7280;">' . s($b['prefix'] ?: '-') . '</td>';
        echo '            <td style="padding:8px 6px;font-weight:600;">' . (int)$b['next_number'] . '</td>';
        echo '            <td style="padding:8px 6px;color:' . $statusColor . ';">' . $statusLabel . '</td>';
        echo '            <td style="padding:8px 6px;text-align:right;">';
        echo '              <button type="button" class="dpl-bundle-edit" data-bundle=\'' . s(json_encode($b)) . '\' style="background:#e0e7ff;color:#3730a3;border:0;border-radius:6px;padding:4px 10px;font-size:12px;font-weight:600;cursor:pointer;margin-right:4px;">' . get_string('edit') . '</button>';
        echo '              <button type="button" class="dpl-bundle-reset" data-bundleid="' . (int)$b['id'] . '" data-bundlecurrent="' . (int)$b['next_number'] . '" data-bundlename="' . s($b['name']) . '" title="' . s(get_string('diploma_bundle_reset_help', 'local_grupomakro_core')) . '" style="background:#fef3c7;color:#92400e;border:0;border-radius:6px;padding:4px 10px;font-size:12px;font-weight:600;cursor:pointer;margin-right:4px;">' . s(get_string('diploma_bundle_reset', 'local_grupomakro_core')) . '</button>';
        echo '              <button type="button" class="dpl-bundle-delete" data-bundleid="' . (int)$b['id'] . '" data-bundlename="' . s($b['name']) . '" style="background:#fee2e2;color:#b91c1c;border:0;border-radius:6px;padding:4px 10px;font-size:12px;font-weight:600;cursor:pointer;">' . $bundleui->bundle_delete . '</button>';
        echo '            </td>';
        echo '          </tr>';
    }
    echo '        </tbody></table>';
}
echo '    </div>';
echo '    <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px;box-shadow:0 2px 8px rgba(15,23,42,.04);">';
echo '      <div style="font-size:14px;font-weight:600;margin-bottom:10px;" id="dpl-bundle-form-title">' . $bundleui->bundle_new . '</div>';
echo '      <input type="hidden" id="dpl-bundle-id" value="0">';
echo '      <label style="display:block;font-size:12px;color:#6b7280;margin-top:8px;">' . $bundleui->bundle_name . '</label>';
echo '      <input type="text" id="dpl-bundle-name" style="width:100%;padding:6px 8px;border:1px solid #d1d5db;border-radius:6px;">';
echo '      <label style="display:block;font-size:12px;color:#6b7280;margin-top:8px;">' . $bundleui->bundle_prefix . '</label>';
echo '      <input type="text" id="dpl-bundle-prefix" placeholder="LIC-, 2025/," style="width:100%;padding:6px 8px;border:1px solid #d1d5db;border-radius:6px;font-family:monospace;">';
echo '      <label style="display:block;font-size:12px;color:#6b7280;margin-top:8px;">' . $bundleui->bundle_next . '</label>';
echo '      <input type="number" id="dpl-bundle-next" value="1" min="1" style="width:100%;padding:6px 8px;border:1px solid #d1d5db;border-radius:6px;">';
echo '      <label style="display:flex;align-items:center;gap:6px;font-size:13px;margin-top:8px;">';
echo '        <input type="checkbox" id="dpl-bundle-active" checked>';
echo '        <span>' . $bundleui->bundle_active . '</span>';
echo '      </label>';
echo '      <div style="display:flex;gap:8px;margin-top:14px;">';
echo '        <button type="button" id="dpl-bundle-save" style="background:#2563eb;color:#fff;border:0;border-radius:6px;padding:8px 14px;font-weight:600;cursor:pointer;">' . $bundleui->bundle_save . '</button>';
echo '        <button type="button" id="dpl-bundle-cancel" style="display:none;background:#e5e7eb;color:#374151;border:0;border-radius:6px;padding:8px 14px;font-weight:600;cursor:pointer;">' . get_string('cancel') . '</button>';
echo '      </div>';
echo '    </div>';
echo '  </div>';

echo '  <div style="margin-top:24px;background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px;box-shadow:0 2px 8px rgba(15,23,42,.04);">';
echo '    <h3 style="margin:0 0 10px;font-size:16px;font-weight:600;">Plantillas y bundle asignado</h3>';
echo '    <p style="margin:0 0 12px;color:#6b7280;font-size:13px;">Asigná un bundle a cada plantilla. Las plantillas sin bundle usan el formato por defecto (DP-&lt;idnumber&gt;-&lt;planCode&gt;-&lt;YYYY&gt;-&lt;NNNNNN&gt;).</p>';
echo '    <table style="width:100%;border-collapse:collapse;font-size:13px;">';
echo '      <thead><tr style="text-align:left;color:#6b7280;font-weight:600;border-bottom:1px solid #e5e7eb;">';
echo '        <th style="padding:6px;">Plantilla</th>';
echo '        <th style="padding:6px;">Bundle actual</th>';
echo '        <th style="padding:6px;">Cambiar a</th>';
echo '        <th></th>';
echo '      </tr></thead><tbody>';

$bundleOptions = [['text' => '— sin bundle —', 'value' => 0]];
foreach ($bundles as $b) {
    $bundleOptions[] = ['text' => $b['name'] . ($b['prefix'] ? '  (' . $b['prefix'] . ')' : ''), 'value' => (int)$b['id']];
}
$templates = $DB->get_records_sql(
    "SELECT id, name, bundle_id FROM {gmk_diploma_template} ORDER BY name ASC"
);
foreach ($templates as $tpl) {
    $currentBundle = isset($tpl->bundle_id) ? (int)$tpl->bundle_id : 0;
    $currentLabel = '— sin bundle —';
    foreach ($bundles as $b) {
        if ((int)$b['id'] === $currentBundle) {
            $currentLabel = $b['name'] . ($b['prefix'] ? '  (' . $b['prefix'] . ')' : '');
            break;
        }
    }
    echo '        <tr style="border-bottom:1px solid #f1f5f9;" data-templateid="' . (int)$tpl->id . '" data-current-bundleid="' . $currentBundle . '">';
    echo '          <td style="padding:8px 6px;font-weight:500;">' . s($tpl->name) . '</td>';
    echo '          <td style="padding:8px 6px;color:#374151;">' . s($currentLabel) . '</td>';
    echo '          <td style="padding:8px 6px;">';
    echo '            <select class="dpl-tpl-bundle" data-templateid="' . (int)$tpl->id . '" style="width:100%;padding:6px 8px;border:1px solid #d1d5db;border-radius:6px;">';
    foreach ($bundleOptions as $opt) {
        $sel = ((int)$opt['value'] === $currentBundle) ? ' selected' : '';
        echo '<option value="' . (int)$opt['value'] . '"' . $sel . '>' . s($opt['text']) . '</option>';
    }
    echo '            </select>';
    echo '          </td>';
    echo '          <td style="padding:8px 6px;text-align:right;">';
    echo '            <button type="button" class="dpl-tpl-bundle-save" data-templateid="' . (int)$tpl->id . '" style="background:#10b981;color:#fff;border:0;border-radius:6px;padding:4px 12px;font-size:12px;font-weight:600;cursor:pointer;">Guardar</button>';
    echo '          </td>';
    echo '        </tr>';
}
echo '      </tbody></table>';
echo '  </div>';
echo '</div>';
echo '</div>';
echo '</template>';

echo <<<'EOS'
<style>
.dpl-bundle-edit:hover, .dpl-bundle-delete:hover, .dpl-bundle-reset:hover, #dpl-bundle-save:hover, #dpl-bundle-cancel:hover { filter: brightness(0.95); }
.dpl-bundle-row-active { background:#f0f9ff !important; }
</style>
<script>
(function () {
    'use strict';
    function refreshBundles() {
        return fetch(window.location.origin + '/local/grupomakro_core/ajax.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ action: 'local_grupomakro_diploma_render_bundles_section' })
        }).then(function (r) { return r.json(); }).then(function (res) {
            if (res && res.status === 'success' && res.html) {
                document.dispatchEvent(new CustomEvent('dpl-bundles-refreshed', { detail: res.html }));
                return;
            }
            alert((res && res.message) || 'Error');
        }).catch(function (e) {
            console.warn(e);
            alert(e.message || e);
        });
    }
    function postBundle(payload) {
        return fetch(window.location.origin + '/local/grupomakro_core/ajax.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(payload)
        }).then(function (r) { return r.json(); });
    }
    function fillForm(bundle) {
        document.getElementById('dpl-bundle-id').value = bundle.id || 0;
        document.getElementById('dpl-bundle-name').value = bundle.name || '';
        document.getElementById('dpl-bundle-prefix').value = bundle.prefix || '';
        document.getElementById('dpl-bundle-next').value = bundle.next_number || 1;
        document.getElementById('dpl-bundle-active').checked = !!bundle.active;
        document.getElementById('dpl-bundle-form-title').textContent = bundle.id ? ('Editar bundle #' + bundle.id) : (window.strings && window.strings.bundle_new) || 'Crear bundle';
        document.getElementById('dpl-bundle-cancel').style.display = bundle.id ? '' : 'none';
    }
    document.addEventListener('click', function (ev) {
        var button = ev.target.closest('.dpl-bundle-edit');
        if (button) {
            try {
                fillForm(JSON.parse(button.getAttribute('data-bundle')));
                document.querySelectorAll('tr[data-bundleid]').forEach(function (row) {
                    row.classList.remove('dpl-bundle-row-active');
                });
                button.closest('tr').classList.add('dpl-bundle-row-active');
            } catch (e) {
                console.warn(e);
            }
            return;
        }
        button = ev.target.closest('.dpl-bundle-delete');
        if (button) {
            var id = parseInt(button.getAttribute('data-bundleid'), 10);
            var name = button.getAttribute('data-bundlename');
            var message = ((window.strings && window.strings.bundle_delete_confirm) || 'Eliminar el bundle? Las plantillas asignadas volveran al formato por defecto.').replace('{name}', name);
            if (!confirm(message)) { return; }
            postBundle({ action: 'local_grupomakro_diploma_delete_bundle', id: id }).then(function (res) {
                if (res && res.status === 'success') { return refreshBundles(); }
                alert((res && res.message) || 'Error');
            }).catch(function (e) { alert(e.message || e); });
            return;
        }
        button = ev.target.closest('.dpl-bundle-reset');
        if (button) {
            var id = parseInt(button.getAttribute('data-bundleid'), 10);
            var name = button.getAttribute('data-bundlename');
            var current = parseInt(button.getAttribute('data-bundlecurrent'), 10);
            var promptLabel = (window.strings && window.strings.diploma_bundle_reset_prompt) || 'Nuevo siguiente número';
            var input = window.prompt(promptLabel + ' (' + name + ')', String(current));
            if (input === null) { return; }
            var trimmed = String(input).trim();
            if (!/^\d+$/.test(trimmed)) {
                alert((window.strings && window.strings.diploma_bundle_reset_invalid) || 'Ingresa un entero positivo.');
                return;
            }
            var next = parseInt(trimmed, 10);
            if (!next || next < 1) {
                alert((window.strings && window.strings.diploma_bundle_reset_invalid) || 'Ingresa un entero positivo.');
                return;
            }
            var confirmTemplate = (window.strings && window.strings.diploma_bundle_reset_confirm) || 'Restablecer el consecutivo del bundle a {next}?';
            var confirmMessage = confirmTemplate
                .replace(/\{a\}/g, JSON.stringify(name))
                .replace(/\\?\{a->next\\?\}/g, String(next))
                .replace(/\{next\}/g, String(next));
            if (!confirm(confirmMessage)) { return; }
            postBundle({ action: 'local_grupomakro_diploma_reset_bundle_counter', id: id, next: next }).then(function (res) {
                if (res && res.status === 'success') { return refreshBundles(); }
                alert((res && res.message) || 'Error');
            }).catch(function (e) { alert(e.message || e); });
            return;
        }
        button = ev.target.closest('.dpl-tpl-bundle-save');
        if (button) {
            var row = button.closest('tr');
            var templateid = parseInt(button.getAttribute('data-templateid'), 10);
            var newBundle = parseInt(row.querySelector('.dpl-tpl-bundle').value || 0, 10);
            var current = parseInt(row.getAttribute('data-current-bundleid') || '0', 10);
            if (newBundle === current) { alert('Sin cambios.'); return; }
            button.disabled = true;
            postBundle({ action: 'local_grupomakro_diploma_assign_bundle_to_template', templateid: templateid, bundleid: newBundle }).then(function (res) {
                if (res && res.status === 'success') { return refreshBundles(); }
                alert((res && res.message) || 'Error');
            }).catch(function (e) { alert(e.message || e); }).finally(function () { button.disabled = false; });
            return;
        }
        button = ev.target.closest('#dpl-bundle-save');
        if (button) {
            var bundleName = (document.getElementById('dpl-bundle-name').value || '').trim();
            if (!bundleName) {
                alert((window.strings && window.strings.bundle_name_required) || 'El nombre del bundle es obligatorio.');
                return;
            }
            var payload = {
                action: 'local_grupomakro_diploma_save_bundle',
                payload: JSON.stringify({
                    id: parseInt(document.getElementById('dpl-bundle-id').value || 0, 10),
                    name: bundleName,
                    prefix: document.getElementById('dpl-bundle-prefix').value || '',
                    next_number: parseInt(document.getElementById('dpl-bundle-next').value || 1, 10),
                    active: document.getElementById('dpl-bundle-active').checked ? 1 : 0
                })
            };
            button.disabled = true;
            postBundle(payload).then(function (res) {
                if (res && res.status === 'success') { return refreshBundles(); }
                alert((res && res.message) || 'Error');
            }).catch(function (e) { alert(e.message || e); }).finally(function () { button.disabled = false; });
            return;
        }
        button = ev.target.closest('#dpl-bundle-cancel');
        if (button) {
            fillForm({ id: 0, name: '', prefix: '', next_number: 1, active: 1 });
        }
    });
})();
</script>
EOS;


$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/diplomatemplates.js?v=' . $assetversion));

echo $OUTPUT->footer();
