<?php

require_once(__DIR__ . '/../../../config.php');

require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
$courseId = 53;
$studentId = 3;
$attendanceModuleId = 3192;
$attendanceId = 110;
$completionState = 1;
$attendanceSessionId=1151;

// date_default_timezone_set('America/Bogota');
// print_object(date_default_timezone_get());
// foreach(array_keys(groups_get_members(216)) as $groupMemberId){
//     if($DB->get_record('gmk_course_progre',['userid'=>$groupMemberId,'courseid'=>$courseId])){
//         local_grupomakro_progress_manager::calculate_learning_plan_user_course_progress($courseId,116,$attendanceModuleId);
//     }
//     // local_grupomakro_progress_manager::calculate_learning_plan_user_course_progress($courseId,$studentId,$attendanceModuleId);
// }

// $timezone = new DateTimeZone('America/Bogota');
print_object(date('Y-m-d H:i:s',1710363600));
// die;

    
// $cm = get_coursemodule_from_instance('attendance', 109, 0, false, MUST_EXIST);

// print_object($cm);

// global $DB, $CFG;
// require_once($CFG->dirroot.'/mod/attendance/classes/structure.php');

// $coursemod = get_fast_modinfo($courseId);
// print_object($coursemod->instances['attendance'][109]->get_course_module_record());

// $attendance = $DB->get_record('attendance', array('id' => 109), '*', MUST_EXIST);
// print_object($attendance);

// $cm = get_coursemodule_from_instance('attendance', 109, 0, false, MUST_EXIST);
// print_object($cm);

// $course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);

// // Get attendance.
// $attendance_structure = new mod_attendance_structure($attendance, $cm, $course);
// print_object($attendance_structure);

// local_grupomakro_progress_manager::handle_qr_marked_attendance($courseId,$studentId,$attendanceModuleId,$attendanceId,$attendanceSessionId);

// $cm = get_fast_modinfo(53);
// print_object($cm->instances['attendance'][109]->get_course_module_record());
// local_grupomakro_progress_manager::calculate_learning_plan_user_course_progress($courseId,$userId,$moduleId,$completionState);
die;
// // print_object($courses);

// foreach($courses as $course){
//     try{
//         $newClassGroup = new stdClass();
//         $newClassGroup->idnumber ='rev-'.$course->shortname;
//         $newClassGroup->name = 'Revalida';
//         $newClassGroup->courseid = $course->id;
//         $newClassGroup->description = 'Group for revalidating '.$course->shortname.' course';
//         $newClassGroup->descriptionformat = 1;
//         $newClassGroup->id =groups_create_group($newClassGroup);
        
//         print_object($newClassGroup);
//         $section = course_create_section($course->id);
//         course_update_section($course->id,$section,[
//             'name'=>'Reválida',
//             'availability'=> '{"op":"&","c":[{"type":"group","id":'.$newClassGroup->id.'}],"showc":[true]}'
//         ]);
        
//     }
//     catch(Exception $e){
//         echo $e->getMessage();
//     }
// }


// course_create_section(78);
// course_update_section(78);

// local_grupomakro_period_manager::close_class_grades_and_open_revalids();


// $gmkPeriodManager->close_class_grades_and_open_revalids();

// $courseId = 53;
// $userId = 116;
// $coursemod = get_fast_modinfo($courseId,$userId); //
// $course = $coursemod->get_course();
// $moduleId = 2724;
// $module= $coursemod->get_cm($moduleId);

// $completion = new completion_info($course);
// $completion->delete_all_completion_data();
// print_object($completion->is_course_complete($userId));
// print_object($completion->get_progress_all('',array(),166));
// $newCourseProgressPorcentaje = core_completion\progress::get_course_progress_percentage(get_course($courseId),$userId);
// print_object($newCourseProgressPorcentaje);

// print_object(grade_get_course_grades($courseId,  $userId));
// print_object(grade_get_course_grade($userId,$courseId));
// print_object(grade_get_grade_items_for_activity($module));
// print_object(grade_is_user_graded_in_activity($module,$userId));