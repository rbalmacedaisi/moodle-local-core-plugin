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
require_once($CFG->dirroot . '/local/sc_learningplans/external/learning/get_active_learning_plans.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->libdir . '/externallib.php');

$plugin_name = 'local_grupomakro_core';

require_login();

$PAGE->set_url($CFG->wwwroot . '/local/grupomakro_core/pages/createclass.php');

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_title(get_string('create_class', $plugin_name));
$PAGE->set_heading(get_string('create_class', $plugin_name));
$PAGE->set_pagelayout('base');
$PAGE->add_body_class('limitedwidth');

if (is_siteadmin()) {
    $PAGE->navbar->add(get_string('class_management', $plugin_name), new moodle_url('/local/grupomakro_core/pages/classmanagement.php'));
}
$PAGE->navbar->add(
    get_string('create_class', $plugin_name),
    new moodle_url('/local/grupomakro_core/pages/createclass.php')
);

// Get the active learning plans with careers and format the object passed to the mustache
$activeLearningPlans = get_active_learning_plans_external::get_active_learning_plans();
$formattedAvailableCareers = [];
$availableCareers =json_decode($activeLearningPlans['availablecareers']);
foreach($availableCareers as $careerName => $careerInfo){
    array_push($formattedAvailableCareers, ['value'=>$careerInfo->lpid, 'label'=>$careerName]);
}

// 
$classRooms = get_classrooms();

$classTypes = [
    ['value'=>1, 'label'=>'Virtual'],
    ['value'=>0, 'label'=>'Presencial'],
    ['value'=>2, 'label'=>'Mixta'],
    
];

$themeToken = get_theme_token();
$userToken = get_logged_user_token();

$strings = new stdClass();
$strings->class_general_data = get_string('class_general_data', $plugin_name);
$strings->class_name = get_string('class_name', $plugin_name);
$strings->class_type = get_string('class_type', $plugin_name);
$strings->class_room = get_string('class_room', $plugin_name);
$strings->class_learning_plan = get_string('class_learning_plan', $plugin_name);
$strings->class_period = get_string('class_period', $plugin_name);
$strings->class_course = get_string('class_course', $plugin_name);
$strings->class_date_time = get_string('class_date_time', $plugin_name);
$strings->class_start_time = get_string('class_start_time', $plugin_name);
$strings->class_end_time = get_string('class_end_time', $plugin_name);
$strings->class_days = get_string('class_days', $plugin_name);
$strings->class_available_instructors = get_string('class_available_instructors', $plugin_name);
$strings->see_availability = get_string('see_availability', $plugin_name);

$strings->class_name_placeholder = get_string('class_name_placeholder', $plugin_name);
$strings->class_type_placeholder = get_string('class_type_placeholder', $plugin_name);
$strings->class_room_placeholder = get_string('class_room_placeholder', $plugin_name);
$strings->class_learningplan_placeholder = get_string('class_learningplan_placeholder', $plugin_name);
$strings->class_period_placeholder = get_string('class_period_placeholder', $plugin_name);
$strings->class_course_placeholder = get_string('class_course_placeholder', $plugin_name);

$strings->monday = get_string('monday', $plugin_name);
$strings->tuesday = get_string('tuesday', $plugin_name);
$strings->wednesday = get_string('wednesday', $plugin_name);
$strings->thursday = get_string('thursday', $plugin_name);
$strings->friday = get_string('friday', $plugin_name);
$strings->saturday = get_string('saturday', $plugin_name);
$strings->sunday = get_string('sunday', $plugin_name);

$strings->accept = get_string('accept', $plugin_name);
$strings->cancel = get_string('cancel', $plugin_name);
$strings->save = get_string('save', $plugin_name);
$strings->close = get_string('close', $plugin_name);

$strings = json_encode($strings);

$templatedata = json_encode([
    'learningPlans' => $formattedAvailableCareers,
    'classTypes'=>$classTypes,
    'classRooms'=>$classRooms
    ]);

echo $OUTPUT->header();

echo <<<EOT
<link href="https://fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.x/css/materialdesignicons.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.min.css" rel="stylesheet">
  <div id="gmk-app">
    <v-app class="transparent" style="background: transparent;">
      <v-main>
        <div>
            <createclass></createclass>
        </div>
      </v-main>
    </v-app>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/vue@2.x/dist/vue.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.js"></script>
  <script src="https://unpkg.com/axios/dist/axios.min.js"></script>
  <style>
    
   </style>
   
   <script>
        var strings = $strings || {};
        var templatedata = $templatedata || {};
        var userToken = $userToken || null;
        var themeToken = $themeToken || null;
  </script>
  
EOT;

// $PAGE->requires->js_call_amd('local_grupomakro_core/create_class', 'init', ['classrooms'=>$classrooms]);
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/createclass.js'));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/modals/errormodal.js'));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/app.js'));

echo $OUTPUT->footer();