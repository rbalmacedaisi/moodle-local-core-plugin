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

$PAGE->set_url($CFG->wwwroot . '/local/grupomakro_core/pages/schedules.php');

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_title(get_string('schedules', $plugin_name));
$PAGE->set_heading(get_string('schedules', $plugin_name));
$PAGE->set_pagelayout('base');

$token = get_logged_user_token();

//Check if the user is an Instructor
$sql = "SELECT DISTINCT r.shortname
              FROM {role_assignments} ra, {role} r
             WHERE ra.userid = ? AND ra.roleid = r.id
                    AND r.shortname IN ('teacher')";

$teacherRoles = $DB->get_records_sql($sql , array($USER->id));

//Check if the user is roled as a teacher
$rolInstructor = !empty($teacherRoles);

//override the teacher role if the user is an administrator
$rolInstructor = $DB->get_record('role_assignments', array('roleid'=>1,'userid'=>$USER->id))?0:1;

//Build a instructor filter if the user is an instructor
$classInstructorFilter = $rolInstructor ===1?['instructorid'=>$USER->id]:[];

// Get the list of created classes
$classes = list_classes($classInstructorFilter);
$classItems = [];
foreach($classes as $class){
  $classItem = new stdClass();
  $classItem->id = $class->corecourseid;
  $classItem->text = $class->coreCourseName;
  $classItem->value = $class->corecourseid;
  array_push($classItems,$classItem);
}
$classItemsUnique = [];
foreach($classItems as $item){
  if(!array_key_exists($item->id,$classItemsUnique)){
    $classItemsUnique[$item->id]=$item;
  }
}
$classItemsUnique = json_encode(array_values($classItemsUnique));

//Get the list of Instructors
$instructors = list_instructors();
$instructorItems = [];
foreach($instructors as $instructor){
  $instructorItem = new stdClass();
  $instructorItem->id = $instructor->id;
  $instructorItem->text = $instructor->fullname;
  $instructorItem->value = $instructor->fullname;
  array_push($instructorItems,$instructorItem);
}
$instructorItems = json_encode($instructorItems);

//Get the reschedule causes
$rescheduleCauses = $DB->get_records('gmk_reschedule_causes');
$rescheduleCauses=json_encode(array_values($rescheduleCauses));

$userid = $USER->id;
$url = new moodle_url('/local/grupomakro_core/pages/classmanagement.php');
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
$strings->desc_rescheduling = get_string('desc_rescheduling',$plugin_name);
$strings->competences = get_string('competences', $plugin_name);
$strings->field_required = get_string('field_required', $plugin_name);
$strings->causes_rescheduling = get_string('causes_rescheduling', $plugin_name);
$strings->select_possible_date = get_string('select_possible_date', $plugin_name);
$strings->new_class_time = get_string('new_class_time', $plugin_name);
$strings->activity = get_string('activity', $plugin_name);
$strings = json_encode($strings);

echo $OUTPUT->header();

echo <<<EOT
<link href="https://fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.x/css/materialdesignicons.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.min.css" rel="stylesheet">
  <div id="app">
    <v-app class="transparent">
      <v-main>
        <v-container>
          <classschedule></classschedule>
        </v-container>
      </v-main>
    </v-app>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/vue@2.x/dist/vue.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.js"></script>
  <script src="https://unpkg.com/axios/dist/axios.min.js"></script>
  
  <script>
    var rolInstructor = $rolInstructor;
    var classItems = $classItemsUnique;
    var instructorItems = $instructorItems;
    var strings = $strings;
    var userid = $userid;
    var rescheduleCauses = $rescheduleCauses;
    var token = $token;
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
    #first .v-toolbar__content{
      padding-left: 0px !important;
    }
    .v-text-field__slot textarea{
      background: transparent !important;
    }
    .theme--dark.v-application {
      background: transparent;
    }
    input[type="time"]::-webkit-calendar-picker-indicator {
      display: none;
    }
    .v-input input:active, .v-input input:focus{
      box-shadow: none !important;
    }
    .v-select__selections input[type="text"],
    .v-text-field__slot input[type="text"]{
      background: transparent !important;
    }
  </style>
EOT;

$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/classschedule.js'));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/dialogconfirm.js'));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/availabilitycomponent.js'));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/app.js'));
echo $OUTPUT->footer();