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
 * Administrative inbox of RET-01 withdrawal requests (20261001080).
 *
 * Mounts the Vue component `withdrawalRequestsPanel` that:
 *   - lists every request with status/date/search filters
 *   - exposes per-row actions: record DA receipt, record Admin receipt,
 *     upload scanned signed PDF, reject, mark as processed
 *   - links to the generated RET-01 PDF for download
 *
 * Holders of local/grupomakro_core:view_wdr_requests (Director Academico,
 * Secretaria Academica, Director General del ISI) reach this page.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
$context = context_system::instance();
$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/withdrawal_requests.php'));
$PAGE->set_context($context);
$PAGE->set_title(get_string('wdr_bandeja_title', 'local_grupomakro_core'));
$PAGE->set_heading(get_string('wdr_bandeja_title', 'local_grupomakro_core'));
$PAGE->set_pagelayout('admin');

require_capability('local/grupomakro_core:view_wdr_requests', $context);

$assetversion = !empty($CFG->themerev) ? (int)$CFG->themerev : 1;

$ajaxUrl = json_encode($CFG->wwwroot . '/local/grupomakro_core/ajax.php');
$sesskey = json_encode(sesskey());
$wwwroot = json_encode($CFG->wwwroot);

$canmanage = has_capability('local/grupomakro_core:manage_wdr_requests', $context);

// Forzar no-cache a nivel de HTTP para evitar que un proxy/CDN/browser
// sirva una version vieja donde el heredoc no interpolaba las variables.
// Moodle ya pone Cache-Control en el 303 de login, pero la respuesta
// 200 final puede cachearse aguas abajo.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

echo $OUTPUT->header();

// Estructura 100% PHP echo (NO heredoc) para que el browser reciba
// las variables SIEMPRE interpoladas, sin riesgo de que un cache o un
// parser raro deje el codigo PHP literal en el HTML.
echo '<link href="https://fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900" rel="stylesheet">';
echo '<link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.x/css/materialdesignicons.min.css" rel="stylesheet">';
echo '<link href="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.min.css">';
echo '<meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate">';
echo '<meta http-equiv="Pragma" content="no-cache">';
echo '<meta http-equiv="Expires" content="0">';
echo '<div id="gmk-app">';
echo '  <v-app class="transparent">';
echo '    <v-main>';
echo '      <withdrawal-requests-panel :canmanage="' . ($canmanage ? 'true' : 'false') . '"></withdrawal-requests-panel>';
echo '    </v-main>';
echo '  </v-app>';
echo '</div>';
echo '<script src="https://cdn.jsdelivr.net/npm/vue@2.x/dist/vue.js"></script>';
echo '<script src="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.js"></script>';
echo '<script src="https://unpkg.com/axios/dist/axios.min.js"></script>';
echo '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>';
echo '<style>.theme--light.v-application { background: transparent !important; }</style>';
echo '<script>';
echo '  var ajaxUrl = ' . $ajaxUrl . ';';
echo '  var sesskey = ' . $sesskey . ';';
echo '  var wwwroot = ' . $wwwroot . ';';
echo '  var GMK_IS_SITEADMIN = ' . ($canmanage ? 'true' : 'false') . ';';
echo '</script>';

$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/withdrawalRequestsPanel.js?v=' . $assetversion));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/app.js?v=' . $assetversion));

echo $OUTPUT->footer();
