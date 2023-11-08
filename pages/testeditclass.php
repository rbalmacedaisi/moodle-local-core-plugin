<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Plugin Page - Grupo Makro
 *
 * @package     local_grupomakro_core
 * @copyright   2022 Solutto <dev@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/local/sc_learningplans/external/learning/get_active_learning_plans.php');
require_once($CFG->dirroot . '/local/sc_learningplans/external/period/get_learning_plan_periods.php');
require_once($CFG->dirroot . '/local/sc_learningplans/external/course/get_learning_plan_courses.php');
require_once($CFG->dirroot . '/local/sc_learningplans/external/user/get_learning_plan_teachers.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->libdir . '/externallib.php');

$plugin_name = 'local_grupomakro_core';

require_login();

$context = context_system::instance();
$PAGE->set_context($context);

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/testeditclass.php'));
$PAGE->set_title(get_string('edit_class', $plugin_name));
$PAGE->set_heading(get_string('edit_class', $plugin_name));
$PAGE->set_pagelayout('base');
$PAGE->add_body_class('limitedwidth');

$id = required_param('class_id', PARAM_TEXT);
$moduleId = optional_param('moduleId',null, PARAM_TEXT);
$sessionId = optional_param('sessionId',null, PARAM_TEXT);
$proposedDate = optional_param('proposedDate',null, PARAM_TEXT);
$proposedHour = optional_param('proposedHour',null, PARAM_TEXT);
$reschedulingActivity = !!$moduleId;

$activityInitDate = null;
$activityInitTime = null;
$activityEndTime = null;
$activityInfo = null;

//Get the class that is going to be edited
$class =  list_classes(array('id'=>$id))[$id];

if($reschedulingActivity){
    $activityInfo = getActivityInfo($moduleId,$sessionId);
}

$classType = $class->type;
$classLearningPlanId=$class->learningplanid;
$classPeriodId = $class->periodid;
$classCourseId = $class->courseid;
$classInstructorId = $class->instructorid;
$classDays = $class->classdays;

$mondayValue = $classDays[0]==='1'?'checked':null;
$tuesdayValue = $classDays[2]==='1'?'checked':null;
$wednesdayValue = $classDays[4]==='1'?'checked':null;
$thursdayValue = $classDays[6]==='1'?'checked':null;
$fridayValue = $classDays[8]==='1'?'checked':null;
$saturdayValue = $classDays[10]==='1'?'checked':null;
$sundayValue = $classDays[12]==='1'?'checked':null;
// ---------------------------------------


//Set class types and selected class type for the class
$classTypes = [
    ['value'=>1, 'label'=>'Virtual', 'selected'=>$classType === '1'? 'selected':null],
    ['value'=>0, 'label'=>'Presencial', 'selected'=>$classType === '0'? 'selected':null],
    ['value'=>2, 'label'=>'Mixta', 'selected'=>$classType === '2'? 'selected':null]
];
// ----------------------------------------------------

// Get the active learning plans with careers and format the object passed to the mustache
$activeLearningPlans = json_decode(get_active_learning_plans_external::get_active_learning_plans()['availablecareers']);
$formattedAvailableCareers = [];
foreach($activeLearningPlans as $careerName => $careerInfo){
    $formattedAvailableCareers[]= ['value'=>$careerInfo->lpid, 'label'=>$careerName, 'selected'=>$classLearningPlanId === $careerInfo->lpid?'selected':''];
}
//------------------------------------------------------------------------------------------


//Get learning plan periods
$classLearningPlanPeriods = json_decode(get_learning_plan_periods_external::get_learning_plan_periods($classLearningPlanId)['periods']);
$classLearningPlanPeriodsFormatted = [];
foreach($classLearningPlanPeriods as $period){
    $classLearningPlanPeriodsFormatted[]= ['value'=>$period->id, 'label'=>$period->name, 'selected'=>$classPeriodId === $period->id?'selected':''];
}
//--------------------------

//Get courses by class learning plan and class period
$classLearningPlanCourses = json_decode(get_learning_plan_courses_external::get_learning_plan_courses($classLearningPlanId,$classPeriodId)['courses']);
$classLearningPlanCoursesFormatted = [];
foreach($classLearningPlanCourses as $course){
    $classLearningPlanCoursesFormatted[]= ['value'=>$course->id, 'label'=>$course->name, 'selected'=>$classCourseId === $course->id?'selected':''];
}
//---------------------------------------------------

//Get teacher by class learning plan
$classLearningPlanTeachers = json_decode(get_learning_plan_teachers_external::get_learning_plan_teachers($classLearningPlanId)['teachers']);
$classLearningPlanTeachersFormatted = [];
foreach($classLearningPlanTeachers as $teacher){
    $classLearningPlanTeachersFormatted[]= ['value'=>$teacher->userid, 'label'=>$teacher->fullname.' ('.$teacher->email.')', 'selected'=>$classInstructorId === $teacher->userid?'selected':''];
}
// ---------------------------------

$service = $DB->get_record('external_services', array('shortname' =>'moodle_mobile_app', 'enabled' => 1));
$token = json_encode(external_generate_token_for_current_user($service)->token);
echo $OUTPUT->header();



$templatedata = [
    'classTypes' => $classTypes,
    'availableCareers' => $formattedAvailableCareers,
    'periods'=>$classLearningPlanPeriodsFormatted,
    'courses'=>$classLearningPlanCoursesFormatted,
    'teachers'=>$classLearningPlanTeachersFormatted,
    'initTime'=> $class->inittime,
    'endTime'=>$class->endtime,
    'mondayValue' =>$mondayValue, 
    'tuesdayValue' =>$tuesdayValue, 
    'wednesdayValue' =>$wednesdayValue,
    'thursdayValue' =>$thursdayValue,
    'fridayValue' =>$fridayValue,
    'saturdayValue' =>$saturdayValue,
    'sundayValue' =>$sundayValue,
    'className'=> $class->name,
    'reschedulingActivity' => $reschedulingActivity,
    'activityInitDate'=>$activityInfo?$activityInfo ->activityInitDate: null,
    'activityProposedDate'=>$activityInfo? ($proposedDate ? $proposedDate : $activityInfo->activityInitDate): null,
    'activityInitTime'=>$activityInfo?$activityInfo ->activityInitTime: null,
    'activityProposedInitTime'=>$activityInfo? ($proposedHour ? $proposedHour : $activityInfo->activityInitTime): null,
    'activityEndTime'=>$activityInfo?$activityInfo ->activityEndTime: null,
    'activityProposedEndTime'=>$activityInfo? ($proposedHour ? date("H:i", strtotime($proposedHour) + $class->classduration)  : $activityInfo->activityEndTime): null,
    'cancelurl'=>$CFG->wwwroot.'/local/grupomakro_core/pages/classmanagement.php',
    'rescheduleCancelUrl'=> $CFG->wwwroot.'/local/grupomakro_core/pages/schedules.php',
    'availabilityPanelUrl' => $CFG->wwwroot.'/local/grupomakro_core/pages/availabilitypanel.php',
];

$classTypes = json_encode(array_values($classTypes));
$classname = json_encode($class->name);
$activityProposedDate = $activityInfo? ($proposedDate ? $proposedDate : $activityInfo->activityInitDate): null;
$templatedata = json_encode($templatedata);

$strings = new stdClass();
$strings->class_name = get_string('class_name', $plugin_name);
$strings->class_type = get_string('class_type', $plugin_name);
$strings->classroom = get_string('classroom', $plugin_name);
$strings->manage_careers = get_string('manage_careers', $plugin_name);
$strings->period = get_string('period', $plugin_name);
$strings->courses = get_string('courses', $plugin_name);
$strings->start_time = get_string('start_time', $plugin_name);
$strings->end_time = get_string('end_time', $plugin_name);
$strings->classdays = get_string('classdays', $plugin_name);
$strings->monday = get_string('monday', $plugin_name);
$strings->tuesday = get_string('tuesday', $plugin_name);
$strings->wednesday = get_string('wednesday', $plugin_name);
$strings->thursday = get_string('thursday', $plugin_name);
$strings->friday = get_string('friday', $plugin_name);
$strings->saturday = get_string('saturday', $plugin_name);
$strings->sunday = get_string('sunday', $plugin_name);
$strings->instructor = get_string('instructor', $plugin_name);
$strings->check_availability = get_string('check_availability', $plugin_name);
$strings->cancel = get_string('cancel', $plugin_name);
$strings->save = get_string('save', $plugin_name);
$strings->select_type_class = get_string('select_type_class', $plugin_name);
$strings->select_classroom = get_string('select_classroom', $plugin_name);
$strings->select_careers = get_string('select_careers', $plugin_name);
$strings->select_period = get_string('select_period', $plugin_name);
$strings->select_courses = get_string('select_courses', $plugin_name);
$strings->select_instructor = get_string('select_instructor', $plugin_name);
$strings->enter_name = get_string('enter_name', $plugin_name);
$strings->general_data = get_string('general_data', $plugin_name);
$strings->class_schedule_days = get_string('class_schedule_days', $plugin_name);
$strings->list_available_instructors = get_string('list_available_instructors', $plugin_name);
$strings->see_availability = get_string('see_availability', $plugin_name);
$strings->close = get_string('close', $plugin_name);
$strings->new_date = get_string('new_date', $plugin_name);
$strings = json_encode($strings);


echo <<<EOT
<link href="https://fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.x/css/materialdesignicons.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.min.css" rel="stylesheet">
  <div id="app">
    <v-app class="transparent" style="background: transparent;">
      <v-main>
        <div>
            <editclass></editclass>
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
        var strings = $strings;
        var classTypes = $classTypes
        var clasname = $classname
        var templatedata = $templatedata
        var userToken = $token;
  </script>
  
EOT;

//echo $OUTPUT->render_from_template('local_grupomakro_core/editclass', $templatedata);
$PAGE->requires->js_call_amd('local_grupomakro_core/edit_class', 'init', []);
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/editclass.js'));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/app.js'));
echo $OUTPUT->footer();