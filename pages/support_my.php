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
 * Mesa de ayuda, fase 2: "Ayuda TI" para docentes dentro de Moodle (§8.4).
 *
 * El docente no usa el portal de estudiantes: aqui abre solicitudes solo en las
 * areas marcadas "disponible para docentes" (Soporte Tecnologico), conversa,
 * confirma la solucion y califica. Monta `support-requester`
 * (js/components/SupportRequester.js) con los mismos WS support_* del portal y
 * audience=teacher. Query: ?ticket=<id> abre esa solicitud; ?calificar=1 abre la
 * calificacion; ?nueva=1 abre el formulario.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

use local_grupomakro_core\local\support\manager;

require_login();
$context = context_system::instance();
$ticketid = optional_param('ticket', 0, PARAM_INT);
$rate = optional_param('calificar', 0, PARAM_BOOL);
$new = optional_param('nueva', 0, PARAM_BOOL);

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/support_my.php'));
$PAGE->set_context($context);
$PAGE->set_title(get_string('tkt_my_title', 'local_grupomakro_core'));
$PAGE->set_heading(get_string('tkt_my_title', 'local_grupomakro_core'));
$PAGE->set_pagelayout('standard');

require_capability('local/grupomakro_core:support_create', $context);
if (!manager::is_teacher((int)$USER->id)) {
    // Students use the portal; staff use the desk.
    $student = \local_grupomakro_core\local\support\config::portal_url('/soporte');
    throw new moodle_exception('tkt_err_noaccess', 'local_grupomakro_core', $student);
}

$assetversion = !empty($CFG->themerev) ? (int)$CFG->themerev : 1;
$config = json_encode(['ticketid' => $ticketid, 'rate' => (bool)$rate, 'new' => (bool)$new, 'userid' => (int)$USER->id],
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

echo $OUTPUT->header();

echo <<<EOT
<link href="https://fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.x/css/materialdesignicons.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.min.css" rel="stylesheet">

<div id="gmk-app">
  <v-app class="transparent">
    <v-main>
      <support-requester :config='$config'></support-requester>
    </v-main>
  </v-app>
</div>

<script src="https://cdn.jsdelivr.net/npm/vue@2.x/dist/vue.js"></script>
<script src="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.js"></script>
<script src="https://unpkg.com/axios/dist/axios.min.js"></script>
<style>
  .theme--light.v-application { background: transparent !important; }
</style>
EOT;

$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/SupportApi.js?v=' . $assetversion));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/SupportRequester.js?v=' . $assetversion));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/app.js?v=' . $assetversion));

echo $OUTPUT->footer();
