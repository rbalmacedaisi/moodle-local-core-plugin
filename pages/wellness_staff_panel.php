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
 * Admin staff roster panel: editable mapping rolekey -> Moodle user + email
 * override. Powers RF-03 / RF-09.3 notifications.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
$context = context_system::instance();
$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/wellness_staff_panel.php'));
$PAGE->set_context($context);
$PAGE->set_title('Bienestar: Personal');
$PAGE->set_heading('Bienestar â€” Personal asignado');
$PAGE->set_pagelayout('admin');

require_capability('local/grupomakro_core:manage_psychology_appointments', $context);

// Cache-bust by JS file mtime. The mtime changes on every pull, so the
// browser is forced to re-fetch whenever the JS itself is updated.
$jsfile = $CFG->dirroot . '/local/grupomakro_core/js/components/wellnessStaffPanel.js';
$appjsfile = $CFG->dirroot . '/local/grupomakro_core/js/app.js';
$assetversion = file_exists($jsfile) ? (int)filemtime($jsfile) : (int)time();
$assetversion_app = file_exists($appjsfile) ? (int)filemtime($appjsfile) : (int)time();
$ajaxUrl = json_encode($CFG->wwwroot . '/local/grupomakro_core/ajax.php');
$sesskey = json_encode(sesskey());
$wwwroot = json_encode($CFG->wwwroot);

echo $OUTPUT->header();

echo <<<EOT
<link href="https://fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.x/css/materialdesignicons.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.min.css" rel="stylesheet">

<div id="gmk-app">
  <v-app class="transparent">
    <v-main>
      <staff-panel></staff-panel>
    </v-main>
  </v-app>
</div>

<script src="https://cdn.jsdelivr.net/npm/vue@2.x/dist/vue.js"></script>
<script src="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.js"></script>
<script src="https://unpkg.com/axios/dist/axios.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
  .theme--light.v-application { background: transparent !important; }
</style>
<script>
  var ajaxUrl = $ajaxUrl;
  var sesskey = $sesskey;
  var wwwroot = $wwwroot;
</script>
EOT;

$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/wellnessStaffPanel.js?v=' . $assetversion));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/app.js?v=' . $assetversion_app));

echo $OUTPUT->footer();