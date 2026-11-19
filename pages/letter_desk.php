<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Cartas (20261001130): bandeja del personal, mobile first.
 *
 * Monta el componente Vue `letter-desk` (js/components/LetterDesk.js), que
 * llama a los servicios local_grupomakro_letters_desk_* por
 * /lib/ajax/service.php (SupportApi.js, con sesskey). Reemplaza a
 * pages/letterrequests.php.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/grupomakro_core:managerequests', $context);

$requestid = optional_param('request', 0, PARAM_INT);
$tab = optional_param('tab', '', PARAM_ALPHA);

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/letter_desk.php'));
$PAGE->set_context($context);
$PAGE->set_title(get_string('letters_desk_title', 'local_grupomakro_core'));
$PAGE->set_heading(get_string('letters_desk_title', 'local_grupomakro_core'));
$PAGE->set_pagelayout('admin');

$assetversion = !empty($CFG->themerev) ? (int)$CFG->themerev : 1;
$config = json_encode([
    'requestid' => $requestid,
    'tab' => $tab,
    'userid' => (int)$USER->id,
    'fileurl' => (new moodle_url('/local/grupomakro_core/pages/letter_file.php'))->out(false),
    'sesskey' => sesskey(),
    'catalogurl' => has_capability('local/grupomakro_core:manageletters', $context)
        ? (new moodle_url('/local/grupomakro_core/pages/lettertypes.php'))->out(false) : '',
    'supporturl' => (new moodle_url('/local/grupomakro_core/pages/support_desk.php'))->out(false),
    'maxmb' => (int)(\local_grupomakro_core\local\letters\manager::UPLOAD_MAX_BYTES / 1048576),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

echo $OUTPUT->header();

echo <<<EOT
<link href="https://fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.x/css/materialdesignicons.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.min.css" rel="stylesheet">

<div id="gmk-app">
  <v-app class="transparent">
    <v-main>
      <letter-desk :config='$config'></letter-desk>
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
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/LetterDesk.js?v=' . $assetversion));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/app.js?v=' . $assetversion));

echo $OUTPUT->footer();
