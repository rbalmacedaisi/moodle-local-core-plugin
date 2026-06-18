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
 * Diploma generation page: eligible students per career + bulk issuance + list of issued diplomas.
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

$PAGE->set_url($CFG->wwwroot . '/local/grupomakro_core/pages/diplomageneration.php');
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_title(get_string('diploma_generation_title', $plugin_name));
$PAGE->set_heading(get_string('diploma_generation_title', $plugin_name));
$PAGE->set_pagelayout('admin');
$PAGE->navbar->add(get_string('admin_category_label', $plugin_name),
    new moodle_url('/local/grupomakro_core/pages/academicpanel.php'));
$PAGE->navbar->add(get_string('diploma_generation', $plugin_name));

require_capability('local/grupomakro_core:viewdiplomas', $context);

$strings = new stdClass();
$strings->filter_career = get_string('diploma_filter_career', $plugin_name);
$strings->all_careers = get_string('diploma_all_careers', $plugin_name);
$strings->search_student = get_string('diploma_search_student', $plugin_name);
$strings->no_graduands = get_string('diploma_no_graduands', $plugin_name);
$strings->generate_selected = get_string('diploma_generate_selected', $plugin_name);
$strings->generation_in_progress = get_string('diploma_generation_in_progress', $plugin_name);
$strings->generation_done = get_string('diploma_generation_done', $plugin_name);
$strings->generation_partial = get_string('diploma_generation_partial', $plugin_name);
$strings->download_pdf = get_string('diploma_download_pdf', $plugin_name);
$strings->no_template_selected = get_string('diploma_no_template_selected', $plugin_name);
$strings->no_students_selected = get_string('diploma_no_students_selected', $plugin_name);
$strings->select_template = get_string('diploma_select_template', $plugin_name);
$strings->generated_records = get_string('diploma_generated_records', $plugin_name);
$strings->generated_at = get_string('diploma_generated_at', $plugin_name);
$strings->generated_for = get_string('diploma_generated_for', $plugin_name);
$strings->template_used = get_string('diploma_template_used', $plugin_name);
$strings->status_generated = get_string('diploma_status_generated', $plugin_name);
$strings->status_revoked = get_string('diploma_status_revoked', $plugin_name);
$strings->view_certificate = get_string('diploma_view_certificate', $plugin_name);
$strings->revoke = get_string('diploma_revoke', $plugin_name);
$strings->revoke_confirm = get_string('diploma_revoke_confirm', $plugin_name);
$strings->revoked_ok = get_string('diploma_revoked_ok', $plugin_name);
$strings->filter_status = get_string('diploma_filter_status', $plugin_name);
$strings->all_status = get_string('diploma_all_status', $plugin_name);
$strings->list_only_pending = get_string('diploma_list_only_pending', $plugin_name);
$strings->list_generated = get_string('diploma_list_generated', $plugin_name);
$strings->name = get_string('name', $plugin_name);
$strings->idnumber = get_string('diploma_var_idnumber', $plugin_name);
$strings->document = get_string('diploma_var_documentnumber', $plugin_name);
$strings->career = get_string('careers', $plugin_name);
$strings->selected_count = get_string('diploma_selected_count', $plugin_name);
$strings->eligible_count = get_string('diploma_eligible_count', $plugin_name);
$strings = json_encode($strings);

$token = get_logged_user_token();
$themeToken = get_theme_token();

echo $OUTPUT->header();

echo <<<EOT
<link href="https://fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.x/css/materialdesignicons.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.min.css" rel="stylesheet">
<div id="gmk-app">
    <v-app class="transparent">
        <v-main>
            <div>
                <diplomageneration></diplomageneration>
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
    .dpl-card-summary { transition: all .2s; }
    .dpl-card-summary:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0,0,0,.1); }
    .dpl-career-card.danger { border-left: 4px solid #f44336; }
    .dpl-career-card.success { border-left: 4px solid #4caf50; }
</style>

<script>
    var strings = $strings;
    var userToken = $token;
    var themeToken = $themeToken || null;
</script>
EOT;

$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/diplomageneration.js?v=' . $assetversion));

echo $OUTPUT->footer();
