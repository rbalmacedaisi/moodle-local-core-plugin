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
require_login();

require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/externallib.php');

$plugin_name = 'local_grupomakro_core';

$context = context_system::instance();

$PAGE->set_url($CFG->wwwroot . '/local/grupomakro_core/pages/availability.php');
$PAGE->set_context($context);
$PAGE->set_title(get_string('availability_calendar', $plugin_name));
$PAGE->set_heading(get_string('availability_calendar', $plugin_name));
$PAGE->set_pagelayout('base');

//Get tokens
$token = get_logged_user_token();
$themeToken = get_theme_token();

//Get the instructors who have an availability created
$instructors = array_values(array_filter(array_map(function ($instructor) {
  if (!$instructor->hasDisponibility) {
    return null;
  }
  $instructorItem = new stdClass();
  $instructorItem->id = $instructor->id;
  $instructorItem->text = $instructor->fullname;
  $instructorItem->value = $instructor->fullname;
  return $instructorItem;
}, grupomakro_core_list_instructors_with_disponibility_flag())));

//get the class types for class type select
$classTypes = \local_grupomakro_core\local\gmk_class::get_class_type_values();

//Get lang strings
$requiredStringsKeys = [
  'today', 'add', 'availability', 'day', 'week', 'month', 'instructors', 'scheduledclasses', 'close', 'edit', 'remove', 'reschedule', 'cancel',
  'accept', 'available_hours', 'available', 'name', 'class_type', 'class_learningplan_placeholder', 'class_period_placeholder',
  'class_course_placeholder', 'class_days', 'create', 'class_room'
];
$strings = new stdClass();
foreach ($requiredStringsKeys as $stringKey) {
  $strings->{$stringKey} = get_string($stringKey, $plugin_name);
}
//Encode data for Vue
$instructors = json_encode($instructors);
$classTypes = json_encode($classTypes);
$classrooms = json_encode(get_classrooms());
$strings = json_encode($strings);

echo $OUTPUT->header();

echo <<<EOT
<link href="https://fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.x/css/materialdesignicons.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.min.css" rel="stylesheet">
  <div id="app">
    <v-app>
      <v-main>
        <v-container fluid>
        <AvailabilityCalendar/>
        </v-container>
      </v-main>
    </v-app>
  </div>
  
  <script src="https://cdn.jsdelivr.net/npm/vue@2.x/dist/vue.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.js"></script>
  <script src="https://unpkg.com/axios/dist/axios.min.js"></script>
  <script>
    var instructors = $instructors;
    var classTypes = $classTypes;
    var classrooms = $classrooms;
    var strings = $strings;
    var token = $token;
    var themeToken = $themeToken || null;
  </script>
  
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
EOT;

$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/app.js'));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/AvailabilityCalendar.js'));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/modals/CreationClassModal.js'));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/dialogconfirm.js'));
echo $OUTPUT->footer();
