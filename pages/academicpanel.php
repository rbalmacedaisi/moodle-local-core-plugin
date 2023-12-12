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

$PAGE->set_url($CFG->wwwroot . '/local/grupomakro_core/pages/academicpanel.php');

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_title(get_string('academic_panel', $plugin_name));
$PAGE->set_heading(get_string('academic_panel', $plugin_name));
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
$strings->manage_careers = get_string('manage_careers', $plugin_name);
$strings->quarters = get_string('quarters', $plugin_name);
$strings->courses = get_string('courses', $plugin_name);
$strings->see_curriculum = get_string('see_curriculum', $plugin_name);
$strings->students_per_page = get_string('students_per_page', $plugin_name);
$strings->students_list = get_string('students_list', $plugin_name);
$strings->revalidation = get_string('revalidation', $plugin_name);
$strings->there_no_data = get_string('there_no_data', $plugin_name);
$strings->name = get_string('name', $plugin_name);
$strings->careers = get_string('careers', $plugin_name);
$strings->quarters = get_string('quarters', $plugin_name);
$strings->state = get_string('state', $plugin_name);

$strings = json_encode($strings);

$service = $DB->get_record('external_services', array('shortname' =>'moodle_mobile_app', 'enabled' => 1));
$token = json_encode(external_generate_token_for_current_user($service)->token);
$default_carrer_img = $CFG->wwwroot.'/local/grupomakro_core/pix/img-default.jpg';
$default_carrer_img = json_encode($default_carrer_img);


echo $OUTPUT->header();

echo <<<EOT
<link href="https://fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.x/css/materialdesignicons.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.min.css" rel="stylesheet">
  <div id="app">
    <v-app class="transparent">
      <v-main>
        <div>
          <academicpanel></academicpanel>
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
    .panel-content{
      -webkit-column-gap: 1.25rem;
      -moz-column-gap: 1.25rem;
      column-gap: 1.25rem !important;
      grid-template-columns: repeat(4,1fr);
      display: grid;
      gap: 0.5rem;
    }
    ul.list{
      display: grid;
      grid-template-columns: repeat(1,1fr);
      margin-top: 1rem;
    }
    ul.list li{
      list-style: none;
      display: flex;
      align-items: center;
    }
    ul.list a.learning-link{
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      flex: 1 1;
      text-decoration: none;
    }
  </style>
  
  <script>
    var strings = $strings;
    var userToken = $token;
    var defaultImage = $default_carrer_img;
  </script>
  
EOT;

$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/academicpanel.js'));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/studenttable.js'));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/academicoffer.js'));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/curriculum.js'));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/app.js'));
echo $OUTPUT->footer();
