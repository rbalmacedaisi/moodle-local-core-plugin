<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Module management page — list and manage independent study modules.
 *
 * @package    local_grupomakro_core
 * @copyright  2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

$plugin_name  = 'local_grupomakro_core';
$assetversion = !empty($CFG->themerev) ? (int)$CFG->themerev : 1;

require_login();

$PAGE->set_url($CFG->wwwroot . '/local/grupomakro_core/pages/module_management.php');
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_title('Gestión de Módulos');
$PAGE->set_heading('Gestión de Módulos');
$PAGE->set_pagelayout('admin');

require_capability('moodle/site:config', $context);

$token   = json_encode(get_logged_user_token());
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
      <modulemanagement></modulemanagement>
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
  var userToken = $token;
  var ajaxUrl   = $ajaxUrl;
  var sesskey   = $sesskey;
  var wwwroot   = $wwwroot;
</script>

EOT;

$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/modulemanagement.js?v=' . $assetversion));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/app.js?v=' . $assetversion));

echo $OUTPUT->footer();
