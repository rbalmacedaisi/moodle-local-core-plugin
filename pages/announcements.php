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
 * Admin dashboard for the broadcast-message system (info / warning notices
 * shown to students in the LXP).
 *
 * Mounts a Vue component (`announcements`) that:
 *  - lists every existing message with active/acked counters
 *  - shows a creation form (type / scope / recipients / ack / priority)
 *  - drills into per-career stats and per-recipient detail for each message
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
$context = context_system::instance();
$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/announcements.php'));
$PAGE->set_context($context);
$PAGE->set_title('Mensajes a estudiantes');
$PAGE->set_heading('Mensajes a estudiantes');
$PAGE->set_pagelayout('admin');

require_capability('local/grupomakro_core:manageannouncements', $context);

$plugin_name  = 'local_grupomakro_core';
$assetversion = !empty($CFG->themerev) ? (int)$CFG->themerev : 1;

$ajaxUrl = json_encode($CFG->wwwroot . '/local/grupomakro_core/ajax.php');
$sesskey = json_encode(sesskey());
$wwwroot = json_encode($CFG->wwwroot);

$canmanage = has_capability('local/grupomakro_core:manageannouncements', $context)
    || has_capability('moodle/site:config', $context);

echo $OUTPUT->header();

echo <<<EOT
<link href="https://fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.x/css/materialdesignicons.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.min.css" rel="stylesheet">

<div id="gmk-app">
  <v-app class="transparent">
    <v-main>
      <announcements
        :canmanage="$canmanage"
      ></announcements>
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

$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/announcements.js?v=' . $assetversion));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/app.js?v=' . $assetversion));

echo $OUTPUT->footer();
