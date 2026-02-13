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

require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
$plugin_name = 'local_grupomakro_core';

$PAGE->set_url($CFG->wwwroot . '/local/grupomakro_core/pages/schedules.php');

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_title(get_string('schedules', $plugin_name));
$PAGE->set_heading(get_string('schedules', $plugin_name));
$PAGE->set_pagelayout('base');

//Get tokens
$token = get_logged_user_token();
$themeToken = get_theme_token();

$userRole = is_siteadmin() ? 'admin' : false;

if (!$userRole) {
  //Check if the user is an Instructor
  $teacherRolesSQL = "SELECT DISTINCT r.shortname
    FROM {role_assignments} ra, {role} r
    WHERE ra.userid = ? AND ra.roleid = r.id
    AND r.shortname LIKE '%teacher%'";
  $userTeacherRoles = $DB->get_records_sql($teacherRolesSQL, array($USER->id));
  $userRole = !empty($userTeacherRoles) ? 'teacher' : false;
}

if (!$userRole) {
  throw new moodle_exception('nopermissiontoseeschedules', $plugin_name);
}
//Build a instructor filter if the user is an instructor
$classInstructorFilter = $userRole === 'teacher' ? ['instructorid' => $USER->id] : [];

$coursesWithCreatedClasses = [];
foreach (list_classes($classInstructorFilter) as $class) {
  if (array_key_exists($class->corecourseid, $coursesWithCreatedClasses)) {
    continue;
  }
  $course = new stdClass();
  $course->id = $class->corecourseid;
  $course->text = $class->coreCourseName;
  $course->value = $class->corecourseid;
  $coursesWithCreatedClasses[$course->id] = $course;
}

//Get the list of Instructors if the user is an admin
$instructors = null;
if (is_siteadmin()) {
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
}

//Get the reschedule causes
$rescheduleCauses = $DB->get_records('gmk_reschedule_causes');
// $userid = $USER->id;

$requiredStringsKeys = [
  'today', 'add', 'availability', 'day', 'week', 'month', 'instructors', 'scheduledclasses',
  'close', 'edit', 'remove', 'reschedule', 'cancel', 'accept', 'desc_rescheduling', 'competences', 'field_required',
  'causes_rescheduling', 'select_possible_date', 'new_class_time', 'activity'
];
$strings = new stdClass();
foreach ($requiredStringsKeys as $stringKey) {
  $strings->{$stringKey} = get_string($stringKey, $plugin_name);
}

$userRole = json_encode($userRole);
$coursesWithCreatedClasses = json_encode(array_values($coursesWithCreatedClasses));
$instructors = json_encode($instructors);
$rescheduleCauses = json_encode(array_values($rescheduleCauses));
$strings = json_encode($strings);

echo $OUTPUT->header();

echo <<<EOT
<link href="https://fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.x/css/materialdesignicons.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.min.css" rel="stylesheet">
  <div id="gmk-app">
    <v-app class="transparent">
      <v-main>
        <v-container>
          <ClassSchedule/>
        </v-container>
      </v-main>
    </v-app>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/vue@2.x/dist/vue.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.js"></script>
  <script src="https://unpkg.com/axios/dist/axios.min.js"></script>
  
  <script>
    var userRole = $userRole;
    var coursesWithCreatedClasses = $coursesWithCreatedClasses;
    var instructors = $instructors;
    var rescheduleCauses = $rescheduleCauses;
    var userId = $USER->id;
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

$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/app.js'));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/ClassSchedule.js'));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/dialogconfirm.js'));
echo $OUTPUT->footer();
