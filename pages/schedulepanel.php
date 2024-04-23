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
 * This page is responsible of managing everything related to the orders.
 *
 * @package    local_grupomakro_core
 * @copyright  2022 Solutto Consulting <dev@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->libdir . '/externallib.php');
$plugin_name = 'local_grupomakro_core';
require_login();

$PAGE->set_url($CFG->wwwroot . '/local/grupomakro_core/pages/schedulepanel.php');

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_title(get_string('schedule_panel', $plugin_name));
$PAGE->set_heading(get_string('schedule_panel', $plugin_name));
$PAGE->set_pagelayout('base');

$strings = new stdClass();
$strings->delete_available = get_string('delete_available',$plugin_name);
$strings->selection_schedules = get_string('selection_schedules',$plugin_name);
$strings->search = get_string('search',$plugin_name);
$strings->schedules = get_string('schedules',$plugin_name);
$strings->nodata = get_string('nodata', $plugin_name);
$strings->course = get_string('course', $plugin_name);
$strings->item_class = get_string('class', $plugin_name);
$strings->users = get_string('users', $plugin_name);
$strings->actions = get_string('actions', $plugin_name);

$strings = json_encode($strings);


$token = get_logged_user_token();
$themeToken = get_theme_token();


echo $OUTPUT->header();

echo <<<EOT
<link href="https://fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.x/css/materialdesignicons.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.min.css" rel="stylesheet">
  <div id="app">
    <v-app class="transparent">
      <v-main>
        <div>
          <scheduletable></scheduletable>
        </div>
      </v-main>
    </v-app>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/vue@2.x/dist/vue.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.js"></script>
  <script src="https://unpkg.com/axios/dist/axios.min.js"></script>
  <style>
    .theme--light.v-application{
      background: transparent !important;
    }
    .paneltable td:nth-child(4n){
      display: none !important;
    }
  </style>
   
  <script>
    var strings = $strings;
    var userToken = $token;
    var themeToken = $themeToken || null;
  </script>
  
EOT;

$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/schedulepanel.js'));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/app.js'));
echo $OUTPUT->footer();
