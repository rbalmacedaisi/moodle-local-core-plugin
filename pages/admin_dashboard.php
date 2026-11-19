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
 * Dashboard administrativo "Mis pendientes" (20261001129,
 * PLAN_DASHBOARD_ADMINISTRATIVO.md §2.4). Inicio de los administrativos cuando
 * dash_home_enabled = 1 (lib.php, local_grupomakro_core_get_staff_landing_url).
 *
 * Monta el componente Vue `admin-dashboard` (js/components/AdminDashboard.js),
 * que pide los datos al WS local_grupomakro_dashboard_get por
 * /lib/ajax/service.php con la sesskey (SupportApi.js).
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/grupomakro_core:view_admin_dashboard', $context);

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/admin_dashboard.php'));
$PAGE->set_context($context);
$PAGE->set_title(get_string('admindash_title', 'local_grupomakro_core'));
$PAGE->set_heading(get_string('admindash_title', 'local_grupomakro_core'));
$PAGE->set_pagelayout('admin');

$assetversion = !empty($CFG->themerev) ? (int)$CFG->themerev : 1;
$config = json_encode(['scope' => optional_param('scope', 'mine', PARAM_ALPHA)],
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

echo $OUTPUT->header();

echo <<<EOT
<link href="https://fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.x/css/materialdesignicons.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.min.css" rel="stylesheet">

<div id="gmk-app">
  <v-app class="transparent">
    <v-main>
      <admin-dashboard :config='$config'></admin-dashboard>
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
EOT;

$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/SupportApi.js?v=' . $assetversion));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/AdminDashboard.js?v=' . $assetversion));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/app.js?v=' . $assetversion));

echo $OUTPUT->footer();
