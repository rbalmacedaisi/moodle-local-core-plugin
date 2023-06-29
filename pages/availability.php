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

$PAGE->set_url($CFG->wwwroot . '/local/grupomakro_core/pages/availability.php');

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_title(get_string('availability', $plugin_name));
$PAGE->set_heading(get_string('availability', $plugin_name));
$PAGE->set_pagelayout('base');

$instructors = grupomakro_core_list_instructors_with_disponibility();
$instructorItems = [];
foreach($instructors as $instructor){
  $instructorItem = new stdClass();
  $instructorItem->id = $instructor->userid;
  $instructorItem->text = $instructor->fullname;
  $instructorItem->value = $instructor->fullname;
  array_push($instructorItems,$instructorItem);
}
$instructorItems = json_encode($instructorItems);

$strings = new stdClass();
$strings->today = get_string('today',$plugin_name);
$strings->add = get_string('add',$plugin_name);
$strings->availability = get_string('availability',$plugin_name);
$strings->day = get_string('day',$plugin_name);
$strings->week = get_string('week',$plugin_name);
$strings->month = get_string('month',$plugin_name);
$strings->instructors = get_string('instructors',$plugin_name);
$strings->scheduledclasses = get_string('scheduledclasses',$plugin_name);
$strings->close = get_string('close',$plugin_name);
$strings->edit = get_string('edit',$plugin_name);
$strings->remove = get_string('remove',$plugin_name);
$strings->reschedule = get_string('reschedule',$plugin_name);
$strings->cancel = get_string('cancel',$plugin_name);
$strings->accept = get_string('accept',$plugin_name);
$strings->available_hours = get_string('available_hours',$plugin_name);
$strings->available = get_string('available',$plugin_name);
$strings->name = get_string('name',$plugin_name);
$strings->select_instance = get_string('select_instance',$plugin_name);
$strings->class_type = get_string('class_type',$plugin_name);
$strings->select_careers = get_string('select_careers',$plugin_name);
$strings->select_period = get_string('select_period',$plugin_name);
$strings->select_courses = get_string('select_courses',$plugin_name);
$strings->classdays = get_string('classdays',$plugin_name);
$strings->create = get_string('create',$plugin_name);
$strings->classrooms = get_string('classroom',$plugin_name);
$strings = json_encode($strings);

$classTypes = [
  ['value'=>1, 'label'=>'Virtual'],
  ['value'=>0, 'label'=>'Presencial'],
  ['value'=>2, 'label'=>'Mixta'],
];

$instances = [
  ['value'=>0, 'label'=>'Isi Panamá'],
  ['value'=>1, 'label'=>'Grupo Makro Colombia'],
  ['value'=>2, 'label'=>'Grupo Makro México']
];

$instances = json_encode($instances);
$classTypes = json_encode($classTypes);
$classrooms = json_encode(get_classrooms());

echo $OUTPUT->header();

echo <<<EOT
<link href="https://fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.x/css/materialdesignicons.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.min.css" rel="stylesheet">
  <div id="app">
    <v-app>
      <v-main>
        <v-container fluid>
        <availabilitycalendar></availabilitycalendar>
        </v-container>
      </v-main>
    </v-app>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/vue@2.x/dist/vue.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.js"></script>
  <script src="https://unpkg.com/axios/dist/axios.min.js"></script>
 
  <style lang="scss">
    .v-current-time {
      height: 2px;
      background-color: #ea4335;
      position: absolute;
      left: -1px;
      right: 0;
      pointer-events: none;
    
      &.first::before {
        content: '';
        position: absolute;
        background-color: #ea4335;
        width: 12px;
        height: 12px;
        border-radius: 50%;
        margin-top: -5px;
        margin-left: -6.5px;
      }
    }
    .instructor-select{
      max-width: 400px;
    }
    .v-label.theme--dark + input[type="text"]{
      background: transparent !important;
    }
    .v-btn--round {
      border-radius: 50% !important;
    }
    .v-select__selections input[type="text"],
    .v-text-field__slot input[type="text"]{
      background: transparent !important;
    }
  </style>
  <script>
    var instructorItems = $instructorItems;
    var strings = $strings;
    var classTypes = $classTypes;
    var instances = $instances;
    var classrooms = $classrooms;
  </script>
EOT;

$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/dialogconfirm.js'));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/availabilitycomponent.js'));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/availabilitymodal.js'));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/app.js'));
echo $OUTPUT->footer();