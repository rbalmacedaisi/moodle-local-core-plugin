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
$assetversion = !empty($CFG->themerev) ? (int)$CFG->themerev : 1;
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
$strings->template_name = get_string('diploma_template_name', $plugin_name);
$strings->new_template = 'Nueva plantilla';
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

$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/diplomatemplates.js?v=' . $assetversion));

echo $OUTPUT->footer();
