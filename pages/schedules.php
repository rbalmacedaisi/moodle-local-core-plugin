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
require_once($CFG->dirroot . '/local/grupomakro_core/lib.php');
$plugin_name = 'local_grupomakro_core';
require_login();

global $DB,$USER;

$PAGE->set_url($CFG->wwwroot . '/local/grupomakro_core/pages/schedules.php');

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_title(get_string('schedules', $plugin_name));
$PAGE->set_heading(get_string('schedules', $plugin_name));
$PAGE->set_pagelayout('base');

//Check if the user is an Instructor
$sql = "SELECT DISTINCT r.shortname
              FROM {role_assignments} ra, {role} r
             WHERE ra.userid = ? AND ra.roleid = r.id
                    AND r.shortname IN ('teacher')";

$teacherRoles = $DB->get_records_sql($sql , array($USER->id));
$rolInstructor = !empty($teacherRoles);
// 

// Get the list of created classes
$classes = grupomakro_core_list_classes([]);
$classItems = [];
foreach($classes as $class){
  $classItem = new stdClass();
  $classItem->id = $class->coreCourseId;
  $classItem->text = $class->coreCourseName;
  $classItem->value = $class->coreCourseId;
  array_push($classItems,$classItem);
}
$classItemsUnique = [];
foreach($classItems as $item){
  if(!array_key_exists($item->id,$classItemsUnique)){
    $classItemsUnique[$item->id]=$item;
  }
}
$classItemsUnique = json_encode(array_values($classItemsUnique));
// 

//Get the list of Instructors
$instructors = grupomakro_core_list_instructors();
$instructorItems = [];
foreach($instructors as $instructor){
  $instructorItem = new stdClass();
  $instructorItem->id = $instructor->id;
  $instructorItem->text = $instructor->fullname;
  $instructorItem->value = $instructor->fullname;
  array_push($instructorItems,$instructorItem);
}
$instructorItems = json_encode($instructorItems);
// 

echo $OUTPUT->header();

$url = new moodle_url('/local/grupomakro_core/pages/classmanagement.php');


echo <<<EOT
<link href="https://fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.x/css/materialdesignicons.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.min.css" rel="stylesheet">
  <div id="app">
    <v-app>
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
  </script>
  
  <style>
    #first .v-toolbar__content{
      padding-left: 0px !important;
    }
</style>
EOT;

$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/classschedule.js'));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/dialogconfirm.js'));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/availabilitycomponent.js'));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/app.js'));
echo $OUTPUT->footer();
