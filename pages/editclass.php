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

$plugin_name = 'local_grupomakro_core';

require_login();

$context = context_system::instance();
$PAGE->set_context($context);

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/editclass.php'));
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
$class =  list_classes(['id'=>$id])[$id];

if($reschedulingActivity){
    $activityInfo = getActivityInfo($moduleId,$sessionId);
}
// ---------------------------------------

//Set class types and selected class type for the class
$classTypes = [
    'selected'=>$class->type,
    'options'=>[
        ['value'=>1, 'label'=>'Virtual'],
        ['value'=>0, 'label'=>'Presencial'],
        ['value'=>2, 'label'=>'Mixta']
    ]
];
// ----------------------------------------------------

// Get the active learning plans with careers and format the object passed to the mustache
$activeLearningPlans = json_decode(get_active_learning_plans_external::get_active_learning_plans()['availablecareers']);
$classLearningPlans = ['selected'=>$class->learningplanid];
$classLearningPlans['options'] =[];
foreach($activeLearningPlans as $activeLearningPlanKey=>$activeLearningPlan){
    $classLearningPlans['options'][]=['value'=>$activeLearningPlan->lpid,'label'=>$activeLearningPlanKey];
}
//-----------------------------------------------------------------------------------------

//Get learning plan periods
$learningPlanPeriods = json_decode(get_learning_plan_periods_external::get_learning_plan_periods($class->learningplanid)['periods']);
$classPeriods = ['selected'=>$class->periodid];
$classPeriods['options'] =[];
foreach($learningPlanPeriods as $period){
    $classPeriods['options'][]= ['value'=>$period->id, 'label'=>$period->name];
}
//--------------------------

//Get courses by class learning plan and class period
$learningPlanPeriodCourses = json_decode(get_learning_plan_courses_external::get_learning_plan_courses($class->learningplanid,$class->periodid)['courses']);
$classCourses = ['selected'=>$class->courseid];
$classCourses['options'] = [];
foreach($learningPlanPeriodCourses as $course){
    $classCourses['options'][]= ['value'=>$course->id, 'label'=>$course->name];
}
//---------------------------------------------------

//get teacher courses and other potential teachers for the class
$params = ['courseId'=>$class->courseid,'initTime'=>$class->inittime,'endTime'=>$class->endtime,'classDays'=>$class->classdays,'learningPlanId'=>$class->learningplanid,'classId'=>$id];
$classPotentialTeachers = get_potential_class_teachers($params);
$classPotentialTeachers = array_values(array_map(function ($potentialTeacher) use ($class){
    $teacherData = new stdClass();
    $teacherData->fullname =$potentialTeacher->fullname;
    $teacherData->email =$potentialTeacher->email;
    $teacherData->id =$potentialTeacher->id;
    $teacherData->selected = $potentialTeacher->id ===$class->instructorid;
    
    return $teacherData;
},$classPotentialTeachers));

$classDays = $class->classdays;
$classDays = [
    'monday'=>$classDays[0],
    'tuesday'=>$classDays[2],
    'wednesday'=>$classDays[4],
    'thursday'=>$classDays[6],
    'friday'=>$classDays[8],
    'saturday'=>$classDays[10],
    'sunday'=>$classDays[12]
];
$token = get_logged_user_token();

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
$strings = json_encode($strings);

$templatedata = json_encode([
    'classId'=> $class->id,
    'className'=> $class->name,
    'classTypes' => $classTypes,
    'classLearningPlans' => $classLearningPlans,
    'classPeriods'=>$classPeriods,
    'classCourses'=>$classCourses,
    'classTeachers'=>$classPotentialTeachers,
    'initTime'=> $class->inittime,
    'endTime'=>$class->endtime,
    'classDays'=>$classDays,
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
]);

echo $OUTPUT->header();

echo <<<EOT
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, minimal-ui">
    <link href="https://fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.x/css/materialdesignicons.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.min.css" rel="stylesheet">
    <style>
        /* Add any additional styles here */
    </style>
</head>
<body>

<script>
    var strings = $strings || {};
    var templatedata = $templatedata || {};
    var userToken = $token || null;
</script>

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

<!-- Additional scripts if needed -->

</body>
</html>
EOT;


$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/components/editclass.js'));
$PAGE->requires->js(new moodle_url('/local/grupomakro_core/js/app.js'));

$PAGE->requires->js_call_amd('local_grupomakro_core/edit_class', 'init', []);
echo $OUTPUT->footer();

// echo $OUTPUT->render_from_template('local_grupomakro_core/editclass', $templatedata);