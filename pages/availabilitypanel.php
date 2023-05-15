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
$plugin_name = 'local_grupomakro_core';
require_login();

$PAGE->set_url($CFG->wwwroot . '/local/grupomakro_core/pages/availabilitypanel.php');

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_title(get_string('availability_panel', $plugin_name));
$PAGE->set_heading(get_string('availability_panel', $plugin_name));
$PAGE->set_pagelayout('base');

//Get the list of Instructors

$instructors = grupomakro_core_list_instructors_with_disponibility_flag();
$instructorItems = [];
foreach($instructors as $instructor){
  $instructorItem = new stdClass();
  $instructorItem->id = $instructor->userid;
  $instructorItem->text = $instructor->fullname;
  $instructorItem->value = $instructor->fullname;
  $instructorItem->hasDisponibility = $instructor->hasDisponibility;
  
  $instructorItems[$instructor->userid] = $instructorItem;
}
$instructorItems = json_encode(array_values($instructorItems));


$strings = new stdClass();
$strings->delete_available = get_string('delete_available',$plugin_name);
$strings->add = get_string('add',$plugin_name);
$strings->availability = get_string('availability',$plugin_name);
$strings->instructors = get_string('instructors',$plugin_name);
$strings->scheduledclasses = get_string('scheduledclasses',$plugin_name);
$strings->close = get_string('close',$plugin_name);
$strings->edit = get_string('edit',$plugin_name);
$strings->remove = get_string('remove',$plugin_name);
$strings->cancel = get_string('cancel',$plugin_name);
$strings->accept = get_string('accept',$plugin_name);
$strings->available_hours = get_string('available_hours',$plugin_name);
$strings->available = get_string('available',$plugin_name);
$strings->add_availability = get_string('add_availability', $plugin_name);
$strings->days = get_string('days', $plugin_name);
$strings->field_required = get_string('field_required', $plugin_name);
$strings->start_time = get_string('start_time', $plugin_name);
$strings->end_time = get_string('end_time', $plugin_name);
$strings->add_schedule = get_string('add_schedule', $plugin_name);
$strings->search = get_string('search', $plugin_name);
$strings->save = get_string('save', $plugin_name);
$strings->delete_available_confirm = get_string('delete_available_confirm', $plugin_name);
$strings->unable_complete_action = get_string('unable_complete_action', $plugin_name);
$strings = json_encode($strings);

echo $OUTPUT->header();

echo <<<EOT
<link href="https://fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.x/css/materialdesignicons.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.min.css" rel="stylesheet">
  <div id="app">
    <v-app class="transparent">
      <v-main>
        <div>
            <availabilitytable></availabilitytable>
        </div>
      </v-main>
    </v-app>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/vue@2.x/dist/vue.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.js"></script>
  <script src="https://unpkg.com/axios/dist/axios.min.js"></script>
  <style>
    .tiemfield-from.v-text-field--filled.v-input--dense.v-text-field--single-line>.v-input__control>.v-input__slot{
       background: rgb(84 164 217 / 25%) !important;
    }
    .tiemfield-to.v-text-field--filled.v-input--dense.v-text-field--single-line>.v-input__control>.v-input__slot{
      background: rgb(131 200 117 / 25%) !important;
    }
    .even-item {
      background-color: #71dc7421;
    }
    
    .odd-item {
      background-color: #7199dc21;
    }
    .theme--light.v-application{
      background: transparent !important;
    }
    .startTime .v-input__slot fieldset{
      background-color: #71dc7421;
    }
    .timeEnd .v-input__slot fieldset{
      background-color: #7199dc21;
    }
    .v-select__selections input[type="text"],
    .v-text-field__slot input[type="text"]{
      background: transparent !important;
    }
    .theme--dark.v-application {
      background: transparent;
    }
    
   </style>
   
   <script>
    var instructorItems = $instructorItems;
    var strings = $strings;
  </script>
  
EOT;

$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/availabilitytable.js'));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/instructoravailability.js'));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/instructorcompetencies.js'));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/app.js'));
echo $OUTPUT->footer();
