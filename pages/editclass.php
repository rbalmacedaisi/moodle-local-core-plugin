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

//Get the class that is going to be edited
$class = json_decode(\local_grupomakro_core\external\list_classes::execute($id)['classes'])[0];
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
    array_push($formattedAvailableCareers, ['value'=>$careerInfo->lpid, 'label'=>$careerName, 'selected'=>$classLearningPlanId === $careerInfo->lpid?'selected':'']);
}
//------------------------------------------------------------------------------------------


//Get learning plan periods
$classLearningPlanPeriods = json_decode(get_learning_plan_periods_external::get_learning_plan_periods($classLearningPlanId)['periods']);
$classLearningPlanPeriodsFormatted = [];
foreach($classLearningPlanPeriods as $period){
    array_push($classLearningPlanPeriodsFormatted, ['value'=>$period->id, 'label'=>$period->name, 'selected'=>$classPeriodId === $period->id?'selected':'']);
}
//--------------------------

//Get courses by class learning plan and class period
$classLearningPlanCourses = json_decode(get_learning_plan_courses_external::get_learning_plan_courses($classLearningPlanId,$classPeriodId)['courses']);
$classLearningPlanCoursesFormatted = [];
foreach($classLearningPlanCourses as $course){
    array_push($classLearningPlanCoursesFormatted, ['value'=>$course->id, 'label'=>$course->name, 'selected'=>$classCourseId === $course->id?'selected':'']);
}
//---------------------------------------------------

//Get teacher by class learning plan
$classLearningPlanTeachers = json_decode(get_learning_plan_teachers_external::get_learning_plan_teachers($classLearningPlanId)['teachers']);
$classLearningPlanTeachersFormatted = [];
foreach($classLearningPlanTeachers as $teacher){
    array_push($classLearningPlanTeachersFormatted, ['value'=>$teacher->id, 'label'=>$teacher->fullname.' ('.$teacher->email.')', 'selected'=>$classInstructorId === $teacher->id?'selected':'']);
}
// ---------------------------------

// var_dump($classLearningPlanTeachersFormatted);
// die();

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
    'cancelurl'=>$CFG->wwwroot.'/local/grupomakro_core/pages/classmanagement.php'
];

echo $OUTPUT->render_from_template('local_grupomakro_core/editclass', $templatedata);
$PAGE->requires->js_call_amd('local_grupomakro_core/edit_class', 'init', []);
echo $OUTPUT->footer();