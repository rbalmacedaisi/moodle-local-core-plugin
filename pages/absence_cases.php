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
 * Seguimiento de inasistencias (20261001131). Casos de estudiantes bloqueados
 * por inasistencia en clases en curso, con contacto a un toque e historial de
 * gestiones de solo agregar; pestana de supervision con indicadores para
 * gerencia y administradores. Monta js/components/AbsenceCases.js.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

use local_grupomakro_core\local\absence\cases;

require_login();
$context = context_system::instance();
if (!cases::can_view((int)$USER->id)) {
    throw new required_capability_exception($context, 'local/grupomakro_core:viewabsencedashboard', 'nopermissions', '');
}

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/absence_cases.php'));
$PAGE->set_context($context);
$PAGE->set_title(get_string('absence_cases_title', 'local_grupomakro_core'));
$PAGE->set_heading(get_string('absence_cases_title', 'local_grupomakro_core'));
$PAGE->set_pagelayout('admin');

$assetversion = !empty($CFG->themerev) ? (int)$CFG->themerev : 1;
$config = json_encode([
    'caseid' => optional_param('case', 0, PARAM_INT),
    'tab' => optional_param('tab', '', PARAM_ALPHA),
    'absenceurl' => (new moodle_url('/local/grupomakro_core/pages/absence_dashboard.php'))->out(false),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

echo $OUTPUT->header();

echo <<<EOT
<link href="https://fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.x/css/materialdesignicons.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.min.css" rel="stylesheet">

<div id="gmk-app">
  <v-app class="transparent">
    <v-main>
      <absence-cases :config='$config'></absence-cases>
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
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/AbsenceCases.js?v=' . $assetversion));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/app.js?v=' . $assetversion));

echo $OUTPUT->footer();
