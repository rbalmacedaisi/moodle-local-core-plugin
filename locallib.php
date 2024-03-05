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
 * This is the main lib file for the plugin.
 *
 * @package    local_grupomakro_core
 * @copyright  2022 Solutto Consulting <dev@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/group/lib.php');
require_once($CFG->dirroot.'/course/modlib.php');
require_once($CFG->dirroot.'/mod/attendance/locallib.php');
require_once($CFG->dirroot.'/calendar/lib.php');
require_once($CFG->dirroot.'/user/lib.php');
require_once($CFG->dirroot.'/vendor/autoload.php');
require_once($CFG->libdir .'/externallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/progress_manager.php');

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/**
 * This function is the extend_navigation function for the plugin.
 * 
 * We will add a new item to the main navigation menu.
 * 
 * @param global_navigation $navigation
 */
function local_grupomakro_core_extend_navigation(global_navigation $navigation) {
    
    global $CFG, $PAGE;

    // If the current user is a site admin, we will add a new item to the main navigation menu.
    if (is_siteadmin()) {
        $CFG->custommenuitems = get_string('pluginname', 'local_grupomakro_core');
        
        // If the current user has grupomakro_core:seeallorders capability, we will add a new item to the main navigation menu.
        if (has_capability('local/grupomakro_core:seeallorders', $PAGE->context)) {
            $CFG->custommenuitems .= PHP_EOL . '-' . get_string('orders', 'local_grupomakro_core') . 
            '|/local/grupomakro_core/pages/orders.php';
        }
    }
}

function get_teachers_disponibility($params) {
    $timePattern = "/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/";
    
    $incomingTimestampRange=null;
    if(array_key_exists('initTime', $params) && array_key_exists('endTime', $params)){
        $params['initTime'] = preg_match($timePattern, $params['initTime'])?$params['initTime']: null;
        $params['endTime'] = preg_match($timePattern, $params['endTime'])?$params['endTime']: null;
        $incomingTimestampRange = $params['initTime'] && $params['endTime'] ? convert_time_range_to_timestamp_range([$params['initTime'],$params['endTime']]) : null;
        $incomingTimestampRangeObject = new stdClass();
        if($incomingTimestampRange){
            $incomingTimestampRangeObject->st = $incomingTimestampRange['initTS'];
            $incomingTimestampRangeObject->et = $incomingTimestampRange['endTS'];
        }
    }
    
    global $DB;
    $teacherSkills = $DB->get_records('gmk_teacher_skill');
    $disponibilityRecords = $DB->get_records('gmk_teacher_disponibility', $params['instructorId']? ['userid'=>$params['instructorId']]:[]);
    $weekdays = array(
        'disp_monday' => 'Lunes',
        'disp_tuesday' => 'Martes',
        'disp_wednesday' => 'Miércoles',
        'disp_thursday' => 'Jueves',
        'disp_friday' => 'Viernes',
        'disp_saturday' => 'Sábado',
        'disp_sunday' => 'Domingo'
    );
    $teachersDisponibility = array();
    foreach($disponibilityRecords as $disponibilityRecord){
        $teacherId = $disponibilityRecord->userid;
        $teachersDisponibility[$teacherId]= new stdClass();
        $teachersDisponibility[$teacherId]->instructorId = $teacherId;
        
        $teacherInfo = $DB->get_record('user',['id'=>$teacherId]);
        $teachersDisponibility[$teacherId]->instructorName = $teacherInfo->firstname.' '.$teacherInfo->lastname;
        $teachersDisponibility[$teacherId]->instructorPicture =get_user_picture_url($teacherId);
        
        
        $teacherSkillsRelations = $DB->get_records('gmk_teacher_skill_relation',['userid'=>$teacherId]);
        $teachersDisponibility[$teacherId]->instructorSkills = [];
        foreach($teacherSkillsRelations as $teacherSkillsRelation){
            $teachersDisponibility[$teacherId]->instructorSkills[]=['name'=>$teacherSkills[$teacherSkillsRelation->skillid]->name,'id'=>$teacherSkillsRelation->skillid];    
        }
        
        $teachersDisponibility[$teacherId]->disponibilityRecords = array();
        
        $teachersDisponibility[$teacherId]->rangeFilterFounded=false;
        foreach($weekdays as $dayColumnName => $day){
            if($incomingTimestampRange && !$teachersDisponibility[$teacherId]->rangeFilterFounded){
                $teachersDisponibility[$teacherId]->rangeFilterFounded = check_if_time_range_is_contained(json_decode($disponibilityRecord->{$dayColumnName}),$incomingTimestampRangeObject);
            }
            $timeSlots = convert_timestamp_ranges_to_time_ranges($disponibilityRecord->{$dayColumnName});
            if(empty($timeSlots)){
                continue;
            };
            $teachersDisponibility[$teacherId]->disponibilityRecords[$day] = $timeSlots;
            $teachersDisponibility[$teacherId]->days[]=$day;
        }
    }
    if($incomingTimestampRange){
        $teachersDisponibility = array_filter($teachersDisponibility,function($teacherDisponibilityRecord){
            return $teacherDisponibilityRecord->rangeFilterFounded;
        });
    }

    return $teachersDisponibility;
}

function check_class_schedule_availability($instructorId,$classDays, $initTime ,$endTime,$classroomId='', $classId = null){
    //Check the instructor availability
    global $DB;
    $weekdays = array(
        0 => 'Lunes',
        1 => 'Martes',
        2 => 'Miércoles',
        3 => 'Jueves',
        4 => 'Viernes',
        5 => 'Sábado',
        6 => 'Domingo'
    );
    $errors = array();
    
    $incomingClassSchedule = explode('/', $classDays);
    $incomingTimestampRange = convert_time_range_to_timestamp_range([$initTime,$endTime]);

    $availabilityRecords = get_teachers_disponibility(['instructorId'=>$instructorId])[$instructorId]->disponibilityRecords;

    for ($i = 0; $i < 7; $i++) {

        if($incomingClassSchedule[$i]==="1" && !array_key_exists($weekdays[$i],$availabilityRecords)){
            $errorString = "El instructor no esta disponible el día ".$weekdays[$i];
            $errors[]=$errorString;
        }
        else if ($incomingClassSchedule[$i]==="1" && array_key_exists($weekdays[$i],$availabilityRecords)){;
            $foundedAvailableRange = false;
            foreach($availabilityRecords[$weekdays[$i]] as $timeRange){
                $availabilityTimestampRange = convert_time_range_to_timestamp_range(explode(', ',$timeRange));
                if($incomingTimestampRange["initTS"] >=$availabilityTimestampRange["initTS"] && $incomingTimestampRange["endTS"] <=$availabilityTimestampRange["endTS"]){
                    $foundedAvailableRange = true;
                    break;
                }
            }
            if(!$foundedAvailableRange){
                $errorString = "El instructor no esta disponible el día ".$weekdays[$i]." en el horário: ".$initTime." - ".$endTime ;
                $errors[]=$errorString;
            }
        }
    }
    $alreadyAsignedClasses = list_classes(['instructorid'=>strval($instructorId)]);
    
    if($classId){
        unset($alreadyAsignedClasses[$classId]);
    }
    
    foreach($alreadyAsignedClasses as $alreadyAsignedClass){
        $alreadyAsignedClassSchedule = explode('/', $alreadyAsignedClass->classdays);
        $classInitTime = $alreadyAsignedClass->inittimets;
        $classEndTime = $alreadyAsignedClass->endtimets;
        
        for ($i = 0; $i < 7; $i++) {
            if ($incomingClassSchedule[$i] == $alreadyAsignedClassSchedule[$i] && $incomingClassSchedule[$i] === '1') {
                if(($incomingTimestampRange["initTS"] >= $classInitTime && $incomingTimestampRange["endTS"]<=$classEndTime) || ($incomingTimestampRange["initTS"]< $classInitTime && $incomingTimestampRange["endTS"]>$classInitTime) ||($incomingTimestampRange["initTS"]< $classEndTime && $incomingTimestampRange["endTS"]>$classEndTime)){
                    $errorString = "La clase ".$alreadyAsignedClass->name.": ".$weekdays[$i]." (".$alreadyAsignedClass->inithourformatted." - ".$alreadyAsignedClass->endhourformatted.") se cruza con el horario escogido"  ;
                    $errors[]=$errorString;
                }
            }
        }
    }
    
    // if($classroomId!==''){
    //     $classesWithSameClassroom= array_filter($classes, function($class) use ($classroomId) {
    //         return $class->classroomid === strval($classroomId);
    //     });
    //     $newClassDaysArray = array_map('intval', explode('/', $classDays));
    //     foreach($classesWithSameClassroom as $class){
    //         $existingClassDaysArray = array_map('intval', explode('/', $class->classdays));
    //         $length = count($newClassDaysArray);

    //         for ($i = 0; $i < $length; $i++) {
    //             if ($newClassDaysArray[$i] === 1 && $existingClassDaysArray[$i] === 1) {
    //                 if (
    //                     ($initTime >= $class->inittime && $initTime <= $class->endtime) ||
    //                     ($endTime >= $class->inittime && $endTime <= $class->endtime) ||
    //                     ($class->inittime >= $initTime && $class->inittime <= $endTime) ||
    //                     ($class->endtime >= $initTime && $class->endtime <= $endTime)
    //                 ) {
    //                     $errorString =  "El salon de clase no esta disponible el día ".$weekdays[$i]." en el horário: ".$initTime." - ".$endTime ;
    //                     $errors[]=$errorString;
    //                 }
    //             }
    //         }
    //     }
    // }
    if(!empty($errors)){
        throw new Exception(json_encode($errors));
    }
    return true;
}

function get_potential_class_teachers($params){

    global $USER, $DB;
    $timePattern = "/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/";
    $weekdays = array(
        0 => 'Lunes',
        1 => 'Martes',
        2 => 'Miércoles',
        3 => 'Jueves',
        4 => 'Viernes',
        5 => 'Sábado',
        6 => 'Domingo'
    );
    
    $params['classDays'] = $params['classDays'] !== '0/0/0/0/0/0/0'?$params['classDays']: null;
    
    
    $params['initTime'] = preg_match($timePattern, $params['initTime'])?$params['initTime']: null;
    $params['endTime'] = preg_match($timePattern, $params['endTime'])?$params['endTime']: null;
    
    
    $teacherSkills = $DB->get_records('gmk_teacher_skill');
    
    //Get the learning plan teachers and complete fullname, email and skills attributes
    $learningPlanTeachers = $DB->get_records("local_learning_users", ['learningplanid' => $params['learningPlanId'], 'userroleid' => 4]);
    
    $learningPlanTeachers = array_map(function ($teacher) use ($DB,$teacherSkills){
        $coreUser = $DB->get_record('user',['id'=>$teacher->userid]);
        $teacher->id = $coreUser->id;
        $teacher->fullname = $coreUser->firstname.' '.$coreUser->lastname;
        $teacher->email = $coreUser->email;
        $teacherSkillsRelations = $DB->get_records('gmk_teacher_skill_relation',['userid'=>$teacher->userid]);
        $teacher->instructorSkills = [];
        foreach($teacherSkillsRelations as $teacherSkillsRelation){
            $teacher->instructorSkills[]=$teacherSkills[$teacherSkillsRelation->skillid]->name;    
        }
        return $teacher;
    },$learningPlanTeachers);
    
    
    //Get the learning plan course for the course id given
    
    if($params['courseId']){
        $learningPlanCourse =  $DB->get_record("local_learning_courses", ['id' => $params['courseId']]);
        $learningPlanCourse->fullname = $DB->get_record("course", ['id' => $learningPlanCourse->courseid])->fullname;
        
        $learningPlanTeachers = array_filter(array_map(function ($teacher) use ($DB,$params,$learningPlanCourse){
            $teacherHasSkill = false;
            foreach($teacher->instructorSkills as $teacherSkill){
                if(containsSubstringIgnoringCaseAndTildes($teacherSkill,$learningPlanCourse->fullname)){
                    return $teacher;
                }
            }
            return null;
        },$learningPlanTeachers));
    }
    
    
    
    if($params['classDays'] && !$params['initTime'] && !$params['endTime'] ){
        $incomingClassSchedule = explode('/', $params['classDays']);
        $learningPlanTeachers = array_filter(array_map(function ($teacher) use ($incomingClassSchedule,$weekdays){
            $availabilityRecords = get_teachers_disponibility(['instructorId'=>$teacher->userid])[$teacher->userid]->disponibilityRecords;
            for ($i = 0; $i < 7; $i++) {
                if($incomingClassSchedule[$i]==="1" && !array_key_exists($weekdays[$i],$availabilityRecords)){
                    return null;
                }
            }
            return $teacher;
        },$learningPlanTeachers));
    }
    if($params['initTime'] || $params['endTime']){

        $initTime = $params['initTime'] ? $params['initTime'] : updateTimeByMinutes($params['endTime'],-1) ;
        $endTime = $params['endTime'] ? $params['endTime'] : updateTimeByMinutes($params['initTime'],1);
        $classDays =$params['classDays'] ?$params['classDays']:'1/1/1/1/1/1/1';
        
        $incomingClassSchedule = explode('/', $classDays);
        $incomingTimestampRange = convert_time_range_to_timestamp_range([$initTime,$endTime]);

        $learningPlanTeachers = array_filter(array_map(function ($teacher) use ($incomingClassSchedule,$incomingTimestampRange,$weekdays,$classDays,$params){
            $availableDays=[];
            $availabilityRecords = get_teachers_disponibility(['instructorId'=>$teacher->userid])[$teacher->userid]->disponibilityRecords;
            for ($dayIndex = 0; $dayIndex < 7; $dayIndex++) {
                if($classDays !== '1/1/1/1/1/1/1' && $incomingClassSchedule[$dayIndex]==="1" && !array_key_exists($weekdays[$dayIndex],$availabilityRecords)){
                    return null;
                }
                if ($incomingClassSchedule[$dayIndex]==="1" && array_key_exists($weekdays[$dayIndex],$availabilityRecords)){;
                    $foundedAvailableRange = false;
                    foreach($availabilityRecords[$weekdays[$dayIndex]] as $timeRange){
                        $availabilityTimestampRange = convert_time_range_to_timestamp_range(explode(', ',$timeRange));
                        if($incomingTimestampRange["initTS"] >=$availabilityTimestampRange["initTS"] && $incomingTimestampRange["endTS"] <=$availabilityTimestampRange["endTS"]){
                            $foundedAvailableRange = true;
                            break;
                        }
                    }
                    if($classDays !== '1/1/1/1/1/1/1' && !$foundedAvailableRange){
                        return null;
                    }
                    if($foundedAvailableRange){
                        $availableDays[]=$weekdays[$dayIndex];
                    }
                }
            }
            $availableDays = array_filter($availableDays);
            if(!$availableDays){
                return null;
            }
            
            $alreadyAsignedClasses = list_classes(['instructorid'=>$teacher->userid]);
            if($params['classId']){
                // print_object($alreadyAsignedClasses);
                unset($alreadyAsignedClasses[$params['classId']]);
            }
            foreach($alreadyAsignedClasses as $alreadyAsignedClass){
                $alreadyAsignedClassSchedule = explode('/', $alreadyAsignedClass->classdays);
                $classInitTime = $alreadyAsignedClass->inittimets;
                $classEndTime = $alreadyAsignedClass->endtimets;
                
                for ($dayIndex = 0; $dayIndex < 7; $dayIndex++) {
                    if ($incomingClassSchedule[$dayIndex] == $alreadyAsignedClassSchedule[$dayIndex] && $incomingClassSchedule[$dayIndex] === '1') {
                        if(($incomingTimestampRange["initTS"] >= $classInitTime && $incomingTimestampRange["endTS"]<=$classEndTime) || ($incomingTimestampRange["initTS"]< $classInitTime && $incomingTimestampRange["endTS"]>$classInitTime) ||($incomingTimestampRange["initTS"]< $classEndTime && $incomingTimestampRange["endTS"]>$classEndTime)){
                            $index = array_search($weekdays[$dayIndex], $availableDays);
                            if($classDays !== '1/1/1/1/1/1/1' && $index !== false){
                                return null;
                            }
                            if ($index !== false) {
                                unset($availableDays[$index]);
                            }
                        }
                    }
                }
            }
            $availableDays = array_filter($availableDays);
            if(!$availableDays){
                return null;
            }
            return $teacher;
        },$learningPlanTeachers));
    }
    return $learningPlanTeachers;
}

function create_class($classParams){
    global $DB, $USER;

    try{
        $newClass = new stdClass();
        $newClass->name           = $classParams["name"];
        $newClass->type           = $classParams["type"];
        $newClass->learningplanid = $classParams["learningPlanId"];
        $newClass->periodid       = $classParams["periodId"];
        $newClass->courseid       = $classParams["courseId"];
        $newClass->instructorid   = $classParams["instructorId"];
        $newClass->inittime       = $classParams["initTime"];
        $newClass->endtime        = $classParams["endTime"];
        $newClass->classdays      = $classParams["classDays"];
        $newClass->classroomid    = $classParams["classroomId"];
        $newClass->classroomcapacity= $classParams["classroomCapacity"];
        $newClass->usermodified   = $USER->id;
        $newClass->timecreated    = time();
        $newClass->timemodified   = time();
        
        $newClass = fill_compute_class_values($newClass,$classParams);
        
        //Save the class with the current data and get its ID
        $newClass->id = $DB->insert_record('gmk_class', $newClass);
    }
    catch (Exception $e) {
        throw $e;
    }
    
    try {
        //Create the class group and enrol the instructor in it.
        $newClass->groupid =create_class_group($newClass);
        
        //Create the class course section.
        $newClass->coursesectionid = create_class_section($newClass);
        
        $updatedClass = $DB->update_record('gmk_class', $newClass);
        
        create_class_activities($newClass);

    }catch (Exception $e){
        delete_class($newClass);
        throw $e;
    }
    
    return $newClass->id;
    
}

function create_class_group($class){
    $newClassGroup = new stdClass();
    $newClassGroup->idnumber = $class->name.'-'.$class->id;
    $newClassGroup->name = $class->name.'-'.$class->id;
    $newClassGroup->courseid = $class->corecourseid;
    $newClassGroup->description = 'Group for the '.$newClassGroup->name.' class';
    $newClassGroup->descriptionformat = 1;
    $newClassGroup->id =groups_create_group($newClassGroup);
    
    if(!$newClassGroup->id){
        throw new Exception('Error creating class group');
    }

    if(!groups_add_member($newClassGroup->id,$class->instructorid)){
        throw new Exception('Error adding teacher to class group');
    }
    return $newClassGroup->id;
}

function create_class_section($class) {
    
    $section = course_create_section($class->corecourseid);
    course_update_section($class->corecourseid,$section,[
        'name'=>$class->name.'-'.$class->id,
        'availability'=>'{"op":"&","c":[{"type":"group","id":'.$class->groupid.'}],"showc":[true]}'
    ]);
    return $section->id;
}

function delete_class($class, $reason =  null){
    global $DB,$USER;
    
    //Delete section if it's already created and all the activities in it.
    if ($class->coursesectionid){
        $section = $DB->get_field('course_sections','section', ['id' => $class->coursesectionid]);
        course_delete_section( $class->corecourseid, $section, true, true);
        $DB->delete_records('gmk_bbb_attendance_relation',['classid'=>$class->id]);
    }
    
    //Delete class group if it's already created
    if ($class->groupid){
        groups_delete_group($class->groupid);
    }

    //Delete registry and queue record related to the class
    $DB->delete_records('gmk_class_pre_registration',['classid'=>$class->id]);
    $DB->delete_records('gmk_class_queue',['classid'=>$class->id]);
    
    //Add the deletion message to the table
    $classDeletionMessage = new stdClass();
    $classDeletionMessage->classid = $class->id;
    $classDeletionMessage->deletionmessage = $reason;
    $classDeletionMessage->usermodified = $USER->id;
    $classDeletionMessage->timecreated = time();
    $classDeletionMessage->timemodified = time();
    $DB->insert_record('gmk_class_deletion_message',$classDeletionMessage);
    
    //Delete the class
    return $DB->delete_records('gmk_class',['id'=>$class->id]);
}
/**
 * Create or updated (delete and recreate) the activities for the given class
 *
 * @return array
 */
function create_class_activities($class) {
    global $DB,$USER;
    // if($classParams["classroomId"]!== ''){
    //         $classroomsReservations = createClassroomReservations($newClass);
    //     }
    $initDate =  date('Y-m-d');
    $endDate = date('Y-m-d', strtotime('+2 months'));
    
    $class->course = get_course($class->corecourseid);
    
    //Get the period start date in seconds and the day name
    $startDate = DateTime::createFromFormat('Y-m-d H:i:s', $initDate.' '.$class->inittime.':00');
    $startDateTS = strtotime($startDate->format('Y-m-d H:i:s'));
    
    //Get the period end date timestamp(seconds)
    $endDate = DateTime::createFromFormat('Y-m-d H:i:s', $endDate.' '.$class->endtime.':00');
    $endDateTS = strtotime($endDate->format('Y-m-d H:i:s'));
    
    //Format the class days
    $classDaysNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    $classDaysList = array_combine($classDaysNames,explode('/', $class->classdays));

    //Define some needed constants
    $currentDateTS = $startDateTS;
    $dayInSeconds = 86400;
    
    $BBBmoduleId = $DB->get_field('modules','id',['name'=>'bigbluebuttonbn']);
    $classSectionNumber = $DB->get_field('course_sections','section',['id'=>$class->coursesectionid]);
    
    $BBBModuleIds = [];
    $attendanceModuleId = null;
    
    // Start looping from the startDate to the endDate
    while ($currentDateTS < $endDateTS) {
        $day =  $classDaysList[date('l', $currentDateTS)];
        
        if($day === '1'){
            
            $BBBModuleId = null;
            
            if($class->type !== 0){
                // Create Big Blue Button activity
                $activityEndTS = $currentDateTS + (int)$class->classduration;
                $BBBModuleId = create_big_blue_button_activity($class, $currentDateTS, $activityEndTS,$BBBmoduleId,$classSectionNumber);
                $BBBModuleIds[]= $BBBModuleId;
            }
            // Create attendance session
            $attendanceSession = create_attendance_session_object($class,$currentDateTS,(int)$class->classduration,$BBBModuleId);
            $attendanceSessions[] = $attendanceSession;
        }
        $dateTime = new DateTime('@' . $currentDateTS);
        $dateTime->modify('+1 day');
        $currentDateTS = $dateTime->getTimestamp();
        // Move to the next day
        // $currentDateTS += $dayInSeconds;
    }
    
    // Create the attendance module for the class.
    
    $attendanceActivityInfo = create_attendance_activity($class,$classSectionNumber);
    $attendanceCourseModule  = get_coursemodule_from_id('attendance', $attendanceActivityInfo->coursemodule, 0, false, MUST_EXIST);
    $attendanceRecord = $DB->get_record('attendance', array('id' => $attendanceCourseModule->instance), '*', MUST_EXIST);
    $attendanceStructure = new \mod_attendance_structure($attendanceRecord, $attendanceCourseModule, $class->course);
    foreach($attendanceSessions as $session){
        $attendanceSessionId = $attendanceStructure->add_session($session);   
        $classAttendanceBBBRelation = new stdClass();
        $classAttendanceBBBRelation->attendancesessionid=$attendanceSessionId;
        $classAttendanceBBBRelation->bbbmoduleid=$session->bbbModuleId;
        $classAttendanceBBBRelation->classid=$class->id;
        $classAttendanceBBBRelation->attendancemoduleid=$attendanceActivityInfo->coursemodule;
        $classAttendanceBBBRelation->attendanceid=$attendanceCourseModule->instance;
        $classAttendanceBBBRelation->sectionid=$class->coursesectionid;
        $DB->insert_record('gmk_bbb_attendance_relation',$classAttendanceBBBRelation);
    }
    $class->attendancemoduleid = $attendanceActivityInfo->coursemodule;
    $DB->update_record('gmk_class',$class);
    
    return ['status'=>'created'];
}

function create_big_blue_button_activity($class,$initDateTS,$endDateTS,$BBBmoduleId,$classSectionId){
    
    $bbbActivityDefinition                                  = new stdClass();
    
    $bbbActivityDefinition->type                            = '0';
    $bbbActivityDefinition->name                            = $class->name.'-'.$class->id.'-'.$initDateTS;
    $bbbActivityDefinition->welcome                         = "Le damos la bienvenida a la sala de clases online de la clase ".$class->name ;
    $bbbActivityDefinition->wait                            = 1;
    $bbbActivityDefinition->record                          = 1;
    // $bbbActivityDefinition->participants                    = '[{"selectiontype":"user","selectionid":'.$class->instructorid.',"role":"moderator"},{"selectiontype":"all","selectionid":"all","role":"viewer"}]';
    $bbbActivityDefinition->participants                    = '[{"selectiontype":"user","selectionid":'.$class->instructorid.',"role":"moderator"},{"selectiontype":"all","selectionid":"all","role":"viewer"}]';
    // $bbbActivityDefinition->openingtime                     = $initDateTS;
    // $bbbActivityDefinition->closingtime                     = $endDateTS;
    $bbbActivityDefinition->visible                         = 1;
    $bbbActivityDefinition->cmidnumber                      = $class->name.'-'.$class->id.'-'.$initDateTS;
    $bbbActivityDefinition->groupmode                       = '0';
    $bbbActivityDefinition->completionunlocked              = 1;
    $bbbActivityDefinition->modulename                      = 'bigbluebuttonbn';
    $bbbActivityDefinition->intro                           = "Sala de clases online de la clase ".$class->name;
    $bbbActivityDefinition->section                         = $classSectionId;
    $bbbActivityDefinition->module                          = $BBBmoduleId;

    $bbbActivityDefinition->completion                      = 2;
    $bbbActivityDefinition->completionattendanceenabled     = 1;
    $bbbActivityDefinition->completionattendance            = 1;
    // $bbbActivityDefinition->completion = 1;
    // $bbbActivityDefinition->completionview = 1;
    // $bbbActivityDefinition->completionexpected =0;
    
    $bbbActivityInfo = add_moduleinfo($bbbActivityDefinition, $class->course);
    return $bbbActivityInfo->coursemodule;
}

function create_attendance_activity($class,$classSectionNumber){
    
    global $DB;
    
    $attendanceActivityDefinition                             = new stdClass();
    $attendanceActivityDefinition->modulename                 = 'attendance';
    $attendanceActivityDefinition->name                       = $class->name.'-'.$class->id;
    $attendanceActivityDefinition->visible                    = 1;
    $attendanceActivityDefinition->intro                      = "Registro de asistencia para la clase ".$class->name;
    $attendanceActivityDefinition->section                    = $classSectionNumber;
    $attendanceActivityDefinition->module                     = $DB->get_record('modules',['name'=>$attendanceActivityDefinition->modulename])->id;
    $attendanceActivityDefinition->subnet                     = '';
    $attendanceActivityDefinition->groupmode                  = 1;
    
    $attendanceActivityInfo = add_moduleinfo($attendanceActivityDefinition, $class->course);
    return $attendanceActivityInfo;
        
}

function create_attendance_session_object($class,$initDateTS,$classDurationInSeconds,$BBBModuleId=null){
    
    $attendanceSessionDefinition = new stdClass();
    $attendanceSessionDefinition->sessdate = $initDateTS;
    $attendanceSessionDefinition->duration = $classDurationInSeconds;
    $attendanceSessionDefinition->groupid = $class->groupid;
    $attendanceSessionDefinition->timemodified = time();
    $attendanceSessionDefinition->calendarevent = 1;
    $attendanceSessionDefinition->description ="Sesión de asistencia - bbbModule:".$BBBModuleId;
    $attendanceSessionDefinition->includeqrcode = 1;
    $attendanceSessionDefinition->rotateqrcode = 1;
    $attendanceSessionDefinition->studentscanmark = 1;
    $attendanceSessionDefinition->autoassignstatus = 1;
    $attendanceSessionDefinition->studentsearlyopentime = 3600;
    $attendanceSessionDefinition->bbbModuleId= $BBBModuleId;

    return $attendanceSessionDefinition;
}

function replace_attendance_session($moduleId,$sessionIdToBeRemoved,$sessionDate,$classDurationInSeconds,$class){
    
    global $DB;
    
    $attendanceCourseModule = get_coursemodule_from_id('attendance', $moduleId, 0, false, MUST_EXIST);
    $attendanceRecord = $DB->get_record('attendance',['id' => $attendanceCourseModule->instance], '*', MUST_EXIST);
    $context = \context_module::instance($attendanceCourseModule->id);
    $attendance = new \mod_attendance_structure($attendanceRecord, $attendanceCourseModule, $class->course, $context);
    
    //Remove the attendance session that will be reschedule
    
    $attendance->delete_sessions([$sessionIdToBeRemoved]);
    
    //Create the new attendance session with the new values

    $attendanceSession = create_attendance_session_object($class,$sessionDate,$classDurationInSeconds);
    
    return $attendance->add_sessions([$attendanceSession]);
}

function list_classes($filters) {
    global $DB;
    
    $fetchedLearningPlans = [];
    $fetchedLearningPlanPeriods = [];
    $fetchedCourses = [];
    $fetchedInstructors = [];
    
    $classes = $DB->get_records('gmk_class',$filters);
    foreach($classes as $class){
            
        //Set the type class icon
        $class->icon = $class->type ==='0' ? 'fa fa-group':'fa fa-desktop';
            
        //Set the list of choosen days
        $daysES = ['Lunes','Martes','Miércoles','Jueves','Viernes','Sábado','Domingo'];
        $daysEN = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];
        $daysString = $class->classdays;
        $selectedDaysES = [];
        $selectedDaysEN = [];
        foreach($daysES as $index=>$day){
            $includedDay= intval(substr($daysString,0,1))===1;
            $includedDay ? array_push($selectedDaysES,$day) :null;
            $includedDay ? array_push($selectedDaysEN,$daysEN[$index]) :null;
            $daysString = substr($daysString,2);
        }
        $class->selectedDaysES = $selectedDaysES;
        $class->selectedDaysEN = $selectedDaysEN;
        $class->classDaysString = implode(' - ',$class->selectedDaysES);
        
        //set the hour range string
        $class->hourRangeString = $class->inithourformatted.' - '.$class->endhourformatted;
        
        //Set class instructor Info
        if(!array_key_exists( $class->instructorid,$fetchedInstructors)){
            $classInstructor = user_get_users_by_id([$class->instructorid])[$class->instructorid];
            $fetchedInstructors[$class->instructorid] =$classInstructor;
        }else{
            $classInstructor = $fetchedInstructors[$class->instructorid];
        }
        $class->instructorName = $classInstructor->firstname.' '. $classInstructor->lastname;
        
        //Set Learning plan Info
        if(!array_key_exists( $class->learningplanid,$fetchedLearningPlans)){
            $classLearningPlan = $DB->get_record('local_learning_plans',['id'=>$class->learningplanid]);
            $fetchedLearningPlans[$class->learningplanid] = $classLearningPlan;
        }else{
            $classLearningPlan = $fetchedLearningPlans[$class->learningplanid];
        }
          
        $class->learningPlanName = $classLearningPlan->name;
        $class->learningPlanShortname = $classLearningPlan->shortname;
        
        //Set period Info
        if(!array_key_exists($class->periodid,$fetchedLearningPlanPeriods)){
            $classLearningPlanPeriod = $DB->get_record('local_learning_periods',['id'=>$class->periodid]);
            $fetchedLearningPlanPeriods[$class->periodid] = $classLearningPlanPeriod;
        }else{
            $classLearningPlanPeriod = $fetchedLearningPlanPeriods[$class->periodid];
        }
        $class->periodName = $classLearningPlanPeriod->name;
        
        //Set the course Info
        if(!array_key_exists($class->corecourseid,$fetchedCourses)){
            $classCourse = get_course($class->corecourseid);
            $courseCustomFields = \core_course\customfield\course_handler::create()->get_instance_data($class->corecourseid);
            foreach($courseCustomFields as $courseCustomField){
                $classCourse->{$courseCustomField->get_field()->get('shortname')} = $courseCustomField->get_value();
            }
            $fetchedCourses[$class->corecourseid] = $classCourse;
        }else{
            $classCourse = $fetchedCourses[$class->corecourseid];
        }
        $class->course =$classCourse;
        $class->coreCourseName = $class->course->fullname;
        $class->coursesectionid = $class->coursesectionid;
        
        //Set the number of students registered for the class
        $classParticipants = get_class_participants($class); 
        $class->enroledStudents = count($classParticipants->enroledStudents)-1;
        $class->preRegisteredStudents = count($classParticipants->preRegisteredStudents);
        $class->queuedStudents = count($classParticipants->queuedStudents);
        $class->classFull = $class->preRegisteredStudents >= $class->classroomcapacity;
        
        //Instructor profile image
        $class->instructorProfileImage = get_user_picture_url($class->instructorid);
        
        //Setting other variables
        $class->startDate =  date('Y-m-d');
        $class->available = !$class->approved;
    }
    return $classes;
}

function get_class_course_info($coreCourseId){
    $course = get_course($coreCourseId);
    $courseCustomFields = \core_course\customfield\course_handler::create()->get_instance_data($coreCourseId);
    foreach($courseCustomFields as $courseCustomField){
        $course->{$courseCustomField->get_field()->get('shortname')} = $courseCustomField->get_value();
    }
    return $course;
}

function update_class($classParams){
    global $DB, $USER;
    $class = $DB->get_record('gmk_class', ['id'=>$classParams['classId']]);
    $classOldInstructorId = $class->instructorid;

    $class->name           = $classParams["name"];
    $class->type           = $classParams["type"];
    $class->learningplanid = $classParams["learningPlanId"];
    $class->periodid       = $classParams["periodId"];
    $class->courseid       = $classParams["courseId"];
    $class->instructorid   = $classParams["instructorId"];
    $class->inittime       = $classParams["initTime"];
    $class->endtime        = $classParams["endTime"];
    $class->classdays      = $classParams["classDays"];
    $class->usermodified   = $USER->id;
    $class->timemodified   = time();
    
    $class = fill_compute_class_values($class,$classParams);
    
    $classUpdated = $DB->update_record('gmk_class', $class);
    
    $section = $DB->get_field('course_sections','section', ['id' => $class->coursesectionid]);
    course_delete_section($class->corecourseid, $section,true,true);
    $DB->delete_records('gmk_bbb_attendance_relation',['classid'=>$class->id]);
    $class->coursesectionid = create_class_section($class);
    
    $classUpdated = $DB->update_record('gmk_class', $class);
    
    update_class_group($class, $classOldInstructorId);
    create_class_activities($class);
}

function update_class_group($class,$oldInstructorId){

    $updatedClassGroup = new stdClass();
    $updatedClassGroup->id = $class->groupid;
    $updatedClassGroup->name = $class->name.'-'.$class->id;
    $updatedClassGroup->courseid = $class->corecourseid;
    $updatedClassGroup->description = 'Group for the '.$updatedClassGroup->name.' class';
    $updatedClassGroup->descriptionformat = 1;
    $updatedClassGroup->updatedGroup =groups_update_group($updatedClassGroup);
    
    if(!$updatedClassGroup->updatedGroup){
        throw new Exception('Error updating class group');
    }

    //Remove the previous instructor and add the new one to the group
    $groupInstructorRemoved = groups_remove_member($class->groupid,$oldInstructorId);
    $groupInstructorAdded = groups_add_member($class->groupid,$class->instructorid);

    return $updatedClassGroup->updatedGroup;
}

function fill_compute_class_values($class,$classParams){
    global $DB;
    //Let's fill the computed fields ----------------------------------------------------------------------------------------------------------------------
    
    //Type label
    $classLabels = ['1'=>'Virtual', '0'=>'Presencial', '2'=>'Mixta'];
    $class->typelabel = $classLabels[$classParams["type"]];
    
    //Core course ID
    $learningCourseId= $DB->get_field('local_learning_courses','courseid',['id'=>$classParams["courseId"]]);
    $course=get_course($learningCourseId);
    $class->corecourseid = $course->id;
    
    //Instructor learning plan ID
    $class->instructorlpid= $DB->get_field('local_learning_users','id',['userid'=>$classParams["instructorId"], 'learningplanid'=>$classParams["learningPlanId"]]);
    
    //Hours formatted, hours timestamps (seconds after midnight) and classduration (seconds)
    $class->inithourformatted = date('h:i A', strtotime($classParams["initTime"]));
    $class->endhourformatted = date('h:i A', strtotime($classParams["endTime"]));
    $classTimestamps = convert_time_range_to_timestamp_range([$classParams["initTime"],$classParams["endTime"]]);
    $class->inittimets = $classTimestamps["initTS"];
    $class->endtimets = $classTimestamps["endTS"];
    $class->classduration = $classTimestamps["endTS"]-$classTimestamps["initTS"];
    
    return $class;
}

function get_class_participants($class){
    global $DB;
    
    $classParticipants = new stdClass();
    $classParticipants->enroledStudents =  $DB->get_records('groups_members',['groupid'=>$class->groupid]);
    $classParticipants->preRegisteredStudents = $DB->get_records('gmk_class_pre_registration',['classid'=>$class->id]);
    $classParticipants->queuedStudents = $DB->get_records('gmk_class_queue',['classid'=>$class->id]);
    return $classParticipants;
}

function check_course_alternative_schedules($selectedClass, $userId){
    global $DB;
    
    $alternatives = student_get_active_classes($userId,$selectedClass->corecourseid);
    
    unset($alternatives[$selectedClass->corecourseid]['schedules'][$selectedClass->id]);
    
    $alternatives[$selectedClass->corecourseid]['schedules'] = array_filter($alternatives[$selectedClass->corecourseid]['schedules'], function($courseClass) {
        return !$courseClass->classFull;
    });
    return $alternatives;
}

function add_user_to_class_queue($userId,$class){
    global $DB,$USER;

    $classQueueRecord = new stdClass();
    $classQueueRecord->timecreated = time();
    $classQueueRecord->timemodified= time();
    $classQueueRecord->usermodified = $USER->id;
    $classQueueRecord->userid = $userId;
    $classQueueRecord->classid = $class->id;
    $classQueueRecord->courseid = $class->corecourseid;
    
    return !!$DB->insert_record('gmk_class_queue',$classQueueRecord);
}

function add_user_to_class_pre_registry($userId,$class){
    global $DB,$USER;

    $classPreRegistryRecord = new stdClass();
    $classPreRegistryRecord->timecreated = time();
    $classPreRegistryRecord->timemodified = time();
    $classPreRegistryRecord->usermodified = $USER->id;
    $classPreRegistryRecord->userid = $userId;
    $classPreRegistryRecord->classid = $class->id;
    $classPreRegistryRecord->courseid = $class->corecourseid;
    
    return !!$DB->insert_record('gmk_class_pre_registration',$classPreRegistryRecord);
}

function get_class_schedules_overview($params){
    global $DB;
    
    $learningPlansCoursesSchedules=get_learning_plan_course_schedules($params);
    
    $learningPlansCoursesSchedules = array_map(function($course){
        $course->numberOfClasses = count($course->schedules);
        $course->totalParticipants = 0;
        $course->totalCapacity = 0;
        foreach($course->schedules as $schedule){
            $course->totalCapacity += $schedule->classroomcapacity;
            $course->totalParticipants += $schedule->preRegisteredStudents + $schedule->queuedStudents;
        }
        $course->remainingCapacity =$course->totalCapacity- $course->totalParticipants;
        $course->capacityPercent = $course->remainingCapacity / $course->totalCapacity;
        
        $course->capacityColor = '#FFECB3';
        $course->capacityPercent === 1 ? $course->capacityColor = '#00E676' : ($course->capacityPercent < 0.20? '#FF5252' : null);
        return $course;
    },$learningPlansCoursesSchedules);
    return $learningPlansCoursesSchedules;
}

function get_learning_plan_course_schedules($params){
    global $DB;

    //Set the filters if provided
    $filters = construct_course_schedules_filter($params);
    $openClasses = [];
    foreach($filters as $filter){
        $openClasses = array_merge($openClasses,list_classes($filter));
    }

    $classesByCoursePeriodAndLearningPlan = [];
    
    foreach($openClasses as $class){
        
        $containerKey = $class->coreCourseName.'-'.$class->periodName.'-'.($class->course->tc?'tc':$class->learningplanid);

        if(!array_key_exists($containerKey,$classesByCoursePeriodAndLearningPlan)){
            $course = new stdClass();
            $course->courseId = $class->corecourseid;
            $course->courseName = $class->coreCourseName;
            $course->periodNames = [$class->periodName];
            $course->periodIds= [$class->periodid];
            $course->learningPlanIds = [$class->learningplanid];
            $course->learningPlanNames = [$class->learningPlanName];
            $course->tc =  $class->course->tc;
            $course->schedules = [$class];
            
            $classesByCoursePeriodAndLearningPlan[$containerKey] = $course;
            continue;
        }
        if($class->course->tc && !in_array($class->learningPlanName, $classesByCoursePeriodAndLearningPlan[$containerKey]->learningPlanNames)){
            $classesByCoursePeriodAndLearningPlan[$containerKey]->learningPlanNames[] = $class->learningPlanName;
            $classesByCoursePeriodAndLearningPlan[$containerKey]->learningPlanIds[] = $class->learningplanid;
        }
        if($class->course->tc && !in_array($class->periodid, $classesByCoursePeriodAndLearningPlan[$containerKey]->periodIds)){
            $classesByCoursePeriodAndLearningPlan[$containerKey]->periodIds[] = $class->periodid;
            !in_array( $class->periodName, $classesByCoursePeriodAndLearningPlan[$containerKey]->periodNames) ? $classesByCoursePeriodAndLearningPlan[$containerKey]->periodNames[] = $class->periodName:null;
        }
        $classesByCoursePeriodAndLearningPlan[$containerKey]->schedules[]=$class;
    }
    
    $classesByCoursePeriodAndLearningPlan = array_map(function ($course){
        $course->learningPlanNames = implode($course->learningPlanNames,',');
        $course->periodIds = implode($course->periodIds,',');
        $course->periodNames = implode($course->periodNames,',');
        $course->learningPlanIds = implode($course->learningPlanIds,',');
        return $course;
    },$classesByCoursePeriodAndLearningPlan);
    
    return $classesByCoursePeriodAndLearningPlan;
}

function construct_course_schedules_filter($params){
    $filtersArray = [];
    $filters = ['closed'=>0];
    
    $params['skipApproved']? $filters['approved'] = '0':null;
    $params['courseId']? $filters['corecourseid'] = $params['courseId']:null;
    
    if($params['periodIds']){
        $periods = explode(",",$params['periodIds']);
        foreach($periods as $period){
            $filtersCopy = $filters;
            $filtersCopy['periodid']= $period;
            $filtersArray[] = $filtersCopy;
        }
    }else{
        $filtersArray[]=$filters;
    }
    return $filtersArray;
}

function approve_course_schedules($approvingSchedules){
    global $DB,$USER;
    
    $approveResults = [];
    foreach($approvingSchedules as $schedule){
        $class = $DB->get_record('gmk_class',['id'=>$schedule['classId']]);
        if($class->approved){
            throw new Exception('Class already approved');
        }
        $schedulePreRegisteredStudents = $DB->get_records('gmk_class_pre_registration',['classid'=>$schedule['classId']]);
        $scheduleQueuedStudents = $DB->get_records('gmk_class_queue',['classid'=>$schedule['classId']]);

        $enrolmentResults = enrolApprovedScheduleStudents(array_merge($schedulePreRegisteredStudents,$scheduleQueuedStudents),$class);
        
        $class->approved = 1;
        $classApproved = $DB->update_record('gmk_class',$class);
        
        $classApprovedMessage = new stdClass();
        $classApprovedMessage->classid = $schedule['classId'];
        $classApprovedMessage->approvalmessage = $schedule['approvalMessage'];
        $classApprovedMessage->usermodified = $USER->id;
        $classApprovedMessage->timecreated = time();
        $classApprovedMessage->timemodified = time();
        
        $classApprovedMessage->id = $DB->insert_record('gmk_class_approval_message',$classApprovedMessage);
        
        $approveResults[$schedule['classId']] = ["enrolmentResults"=> $enrolmentResults, 'classApproved' => $classApproved, 'approvalMessageSaved'=>!!$classApprovedMessage->id];
    }
    return $approveResults;
}

function enrolApprovedScheduleStudents ($students,$class){
    $enrolmentResults = [];
    foreach($students as $student){
        $enrolmentResults[$student->userid] = groups_add_member($class->groupid, $student->userid);
        if($enrolmentResults[$student->userid]){
            local_grupomakro_progress_manager::assign_class_to_course_progress($student->userid, $class);
        }
        
    }
    return $enrolmentResults;
}

function change_students_schedules($movingStudents){
    global $DB,$USER;
    $changeResults = [];
    $fetchedClasses = [];
    
    foreach($movingStudents as $changeInfo){
        $isPreregisteredStudent = $DB->get_record('gmk_class_pre_registration',['classid'=>$changeInfo["currentClassId"],'userid'=>$changeInfo["studentId"]]);

        $newClass = list_classes(['id'=> $changeInfo["newClassId"]])[$changeInfo["newClassId"]];
        if($isPreregisteredStudent){
            if($newClass->classFull){
                $newClassQueueStudent = createSchedulePreregistryOrQueueObject($changeInfo["studentId"],$newClass->id, $newClass->corecourseid);
                $newClassQueueStudent->id = $DB->insert_record('gmk_class_queue',$newClassQueueStudent);
                $DB->delete_records('gmk_class_pre_registration',['id'=>$isPreregisteredStudent->id]);
                
                $changeResults[$changeInfo["studentId"]] = ['changedSchedule' => !!$newClassQueueStudent->id , 'sendedTo'=> 'Queue'];
                continue;
            }
            
            $isPreregisteredStudent->classid = $changeInfo["newClassId"];
            $isPreregisteredStudent->timemodified = time();
            $isPreregisteredStudent->usermodified = $USER->id;
            $changeResults[$changeInfo["studentId"]] = ['changedSchedule' =>$DB->update_record('gmk_class_pre_registration',$isPreregisteredStudent),'sendedTo'=> 'Preregistry'];
        }
        
        $isQueuedStudent = $DB->get_record('gmk_class_queue',['classid'=>$changeInfo["currentClassId"],'userid'=>$changeInfo["studentId"]]);
        if($isQueuedStudent){
             if(!$newClass->classFull){
                $newClassPreregistryStudent = createSchedulePreregistryOrQueueObject($changeInfo["studentId"],$newClass->id, $newClass->corecourseid);
                $newClassPreregistryStudent->id = $DB->insert_record('gmk_class_pre_registration',$newClassPreregistryStudent);
                $DB->delete_records('gmk_class_queue',['id'=>$isQueuedStudent->id]);
                
                $changeResults[$changeInfo["studentId"]] = ['changedSchedule' => !!$newClassPreregistryStudent->id , 'sendedTo'=> 'Preregistry'];
                continue;
            }
            
            $isQueuedStudent->classid = $changeInfo["newClassId"];
            $isQueuedStudent->timemodified = time();
            $isQueuedStudent->usermodified = $USER->id;
            $changeResults[$changeInfo["studentId"]] = ['changedSchedule' =>$DB->update_record('gmk_class_queue',$isQueuedStudent),'sendedTo'=> 'Queue'];
        
        }
    }
    return $changeResults;
}

function createSchedulePreregistryOrQueueObject($userId,$classId,$courseId){
    global $USER;
    $preregistryOrQueueObject = new stdClass();
    $preregistryOrQueueObject->userid =$userId;
    $preregistryOrQueueObject->classid = $classId;
    $preregistryOrQueueObject->courseid = $courseId;
    $preregistryOrQueueObject->timecreated = time();
    $preregistryOrQueueObject->timemodified = time();
    $preregistryOrQueueObject->usermodified = $USER->id;
    
    return $preregistryOrQueueObject;
}

function deleteStudentFromClassSchedule($deletedStudents){
    global $DB;
    
    foreach($deletedStudents as $student){
        $deletedFromPreregistry = $DB->delete_records('gmk_class_pre_registration',['classid'=>$student['classId'],'userid'=>$student['studentId']]);
        $deletedFromQueue = $DB->delete_records('gmk_class_queue',['classid'=>$student['classId'],'userid'=>$student['studentId']]);
    }

    return;
}

function get_course_students_by_class_schedule($classId){
    global $DB;
    $classStudents = get_class_participants($DB->get_record('gmk_class',['id'=>$classId]));
    
    
    $classStudents->enroledStudents = array_map(function ($student){
        $studentInfo = user_get_users_by_id([$student->userid])[$student->userid];
        $student->email = $studentInfo->email;
        $student->firstname = $studentInfo->firstname;
        $student->lastname = $studentInfo->lastname;
        $student->profilePicture = get_user_picture_url($student->userid);
        return $student;
    },$classStudents->enroledStudents);
    
    $classStudents->preRegisteredStudents = array_map(function ($student){
        $studentInfo = user_get_users_by_id([$student->userid])[$student->userid];
        $student->email = $studentInfo->email;
        $student->firstname = $studentInfo->firstname;
        $student->lastname = $studentInfo->lastname;
        $student->profilePicture = get_user_picture_url($student->userid);
        return $student;
    },$classStudents->preRegisteredStudents);
    
    $classStudents->queuedStudents = array_map(function ($student){
        $studentInfo = user_get_users_by_id([$student->userid])[$student->userid];
        $student->email = $studentInfo->email;
        $student->firstname = $studentInfo->firstname;
        $student->lastname = $studentInfo->lastname;
        $student->profilePicture = get_user_picture_url($student->userid);
        return $student;
    },$classStudents->queuedStudents);
    
    return $classStudents;
}

function get_scheduleless_students($params){
    global $DB;
    $periods = explode(",",$params['periodIds']);
    $usersInPeriods = [];
    
    $usersInPeriods = array_merge(...array_map(function ($period) use ($DB){
        return $DB->get_records("local_learning_users",['currentperiodid'=>$period]);
    },$periods));
    
    $schedulelessUsers = array_filter($usersInPeriods,function ($user) use ($DB,$params){
        if(!!$DB->get_record('gmk_class_pre_registration',['userid'=>$user->userid,'courseid'=>$params['courseId']]) || !!$DB->get_record('gmk_class_queue',['userid'=>$user->userid,'courseid'=>$params['courseId']])){
            return;
        }
        return $user;
    });
    $schedulelessUsers = array_map(function ($user){
        $studentInfo = user_get_users_by_id([$user->userid])[$user->userid];
        $student = new stdClass();
        $student->id = $user->userid;
        $student->email = $studentInfo->email;
        $student->firstname = $studentInfo->firstname;
        $student->lastname = $studentInfo->lastname;
        $student->profilePicture = get_user_picture_url($student->id);
        return $student;
    },$schedulelessUsers);
   
    return $schedulelessUsers;
}

function add_teacher_disponibility($params){
    global $DB, $USER;
    $errors = [];
    if($DB->get_record('gmk_teacher_disponibility',['userid'=>$params['instructorId']])){
        $errors[]='Disponibility already defined for the user with id '.$params['instructorId'].'.';
    }
    $dayENLabels = array(
        'lunes' => 'disp_monday',
        'martes' => 'disp_tuesday',
        'miercoles' => 'disp_wednesday',
        'jueves' => 'disp_thursday',
        'viernes' => 'disp_friday',
        'sabado' => 'disp_saturday',
        'domingo' => 'disp_sunday'
    );
    
    $fetchedSkills = [];
    foreach($params['skills'] as $skillId){
        if(!$skill = $DB->get_record('gmk_teacher_skill',['id'=>$skillId])){
            $errors[]='Invalid skill id: '.$skillId.'.';
        }
        $fectchedSkills[$skillId]=$skill;
    }

    $teacherDisponibility = new stdClass();
    $teacherDisponibility->userid =$params['instructorId'];
    
    foreach($params['newDisponibilityRecords'] as $newDisponibilityRecord){
        $day = strtolower(str_replace(['á', 'é', 'í', 'ó', 'ú', 'ñ'], ['a', 'e', 'i', 'o', 'u', 'n'], $newDisponibilityRecord['day']));
        $teacherDisponibility->{$dayENLabels[$day]}=json_encode(calculate_disponibility_range($newDisponibilityRecord['timeslots']));
    }
    foreach($dayENLabels as $dayLabel){
        !property_exists( $teacherDisponibility,$dayLabel)?$teacherDisponibility->{$dayLabel}="[]" :null;
    }
    if(!empty($errors)){
        throw new Exception(json_encode($errors));
    }
    $disponibilityRecordId = $DB->insert_record('gmk_teacher_disponibility',$teacherDisponibility);
    foreach($params['skills'] as $skillId){
        $teacherSkillRelation = new stdClass();
        $teacherSkillRelation->skillid = $skillId;
        $teacherSkillRelation->userid = $params['instructorId'];
        $teacherSkillRelation->usermodified = $USER->id;
        $teacherSkillRelation->timecreated =time();
        $teacherSkillRelation->timemodified = time();
        
        $DB->insert_record('gmk_teacher_skill_relation',$teacherSkillRelation);
        
    }
    
    return $disponibilityRecordId;
}

function update_teacher_disponibility($params){
    global $DB,$USER ;
    $errors = [];
    $dayENLabels = array(
        'lunes' => 'disp_monday',
        'martes' => 'disp_tuesday',
        'miercoles' => 'disp_wednesday',
        'jueves' => 'disp_thursday',
        'viernes' => 'disp_friday',
        'sabado' => 'disp_saturday',
        'domingo' => 'disp_sunday'
    );
    $weekdays = [
        "Monday" => "Lunes",
        "Tuesday" => "Martes",
        "Wednesday" => "Miércoles",
        "Thursday" => "Jueves",
        "Friday" => "Viernes",
        "Saturday" => "Sábado",
        "Sunday" => "Domingo"
    ];
    foreach($params['skills'] as $skillId){
        if(!$DB->get_record('gmk_teacher_skill',['id'=>$skillId])){
            $errors[]='Invalid skill id: '.$skillId.'.';
        }
    }
    $teacherDisponibilityId= $DB->get_record('gmk_teacher_disponibility',['userid'=>$params['instructorId']])->id;
    $teacherDisponibility = new stdClass();
    $teacherDisponibility->id = $teacherDisponibilityId;
    $teacherDisponibility->userid = $params['instructorId'];
    
    if($params['newDisponibilityRecords']){
        
        $disponibilityDays = array();
        foreach($params['newDisponibilityRecords'] as $newDisponibilityRecord){
            $day = strtolower(str_replace(['á', 'é', 'í', 'ó', 'ú', 'ñ'], ['a', 'e', 'i', 'o', 'u', 'n'], $newDisponibilityRecord['day']));
            $teacherDisponibility->{$dayENLabels[$day]}=calculate_disponibility_range($newDisponibilityRecord['timeslots']);
            $disponibilityDays[]=explode( '_',$dayENLabels[$day])[1];
        }
        
        $instructorAsignedClasses = list_classes(['instructorid'=>$params['instructorId']]);
        $classLearningPlans = array();
                
        foreach($instructorAsignedClasses as $instructorAsignedClass){
            
            if(!in_array($instructorAsignedClass->learningplanid, $classLearningPlans)){
                $classLearningPlans[]=$instructorAsignedClass->learningplanid;
            }
    
            // Check if a day that is already defined for a class is missing in the new disponibility
            foreach($instructorAsignedClass->selectedDaysEN as $classDay){
                 if(!in_array(strtolower($classDay),$disponibilityDays)){
                    $errorString = "El horario de la clase ".$instructorAsignedClass->coreCourseName." (".$weekdays[$classDay]." ".$instructorAsignedClass->inithourformatted.'-'.$instructorAsignedClass->endhourformatted. "), no esta definido en la nueva disponibilidad; no se puede actualizar.";
                    $errors[]=$errorString;
                }
                
                $foundedRange = false;
                $dayDisponibilities = $teacherDisponibility->{'disp_'.strtolower($classDay)};
                foreach($dayDisponibilities as $dayDisponibility){
                    if($instructorAsignedClass->inittimets >= $dayDisponibility->st &&  $instructorAsignedClass->endtimets  <= $dayDisponibility->et){
                        $foundedRange = true;
                        break;
                    }
                }
                if(!$foundedRange){
                    $errorString = "El horario de la clase ".$instructorAsignedClass->coreCourseName." (".$weekdays[$classDay]." ".$instructorAsignedClass->inithourformatted.'-'.$instructorAsignedClass->endhourformatted. "), no esta definido en la nueva disponibilidad; no se puede actualizar.";
                    $errors[]=$errorString;
                }
            }
        }
        
        
        //Check if there is a change in the user availability owner
        if($params['newInstructorId'] && $params['newInstructorId'] !== $params['instructorId'] && empty($errors)){
            $validNewInstructor = true;
            if($DB->get_record('gmk_teacher_disponibility', array('userid'=>$params['newInstructorId']))){
                $errorString = 'El nuevo instructor ya tiene una disponibilidad definida.';
                $errors[]=$errorString;
                $validNewInstructor = false;
            }
            
            foreach($classLearningPlans as $classLearningPlan){
                if(!$DB->get_record('local_learning_users', array('userid'=>$params['newInstructorId'], 'learningplanid'=>$classLearningPlan, 'userrolename'=>'teacher'))){
                    $errorString = 'El nuevo instructor no esta en el plan de aprendizaje '.$DB->get_record('local_learning_plans', array('id'=>$classLearningPlan))->name.'.';
                    $errors[]=$errorString;
                    $validNewInstructor = false;
                }
            }
            
            if($validNewInstructor){
                foreach($instructorAsignedClasses as $instructorAsignedClass ){
                    $classRecord = $DB->get_record('gmk_class',array('id'=>$instructorAsignedClass->id));
                    $classRecord->instructorid = $params['newInstructorId'];
                    $updateClassInstructor = $DB->update_record('gmk_class',$classRecord);
                    
                    //Update the group with the new instructor
                    $classGroupId = $instructorAsignedClass->groupid;
                    
                    $groupInstructorRemoved = groups_remove_member($classGroupId,$params['instructorId']);
                    $groupInstructorAdded = groups_add_member($classGroupId,$params['newInstructorId']);
                }
                $teacherDisponibility->userid=$params['newInstructorId'];
            }
        }
        foreach($teacherDisponibility as $columnKey => $columnValue){
            if(strpos($columnKey, 'disp_')!== false){
                $teacherDisponibility->{$columnKey} = json_encode($columnValue);
            }
        }
        foreach($dayENLabels as $dayLabel){
            !property_exists( $teacherDisponibility,$dayLabel)?$teacherDisponibility->{$dayLabel}="[]" :null;
        }
    }
    
    if(!empty($errors)){
        throw new Exception(json_encode($errors));
    }
    if($params['newDisponibilityRecords']){
        $disponibilityRecordUpdated = $DB->update_record('gmk_teacher_disponibility',$teacherDisponibility);
    }
    
    $DB->delete_records('gmk_teacher_skill_relation',['userid'=>$params['instructorId']]);
    foreach($params['skills'] as $skillId){
        
        // if($DB->get_record('gmk_teacher_skill_relation',['userid'=>$teacherDisponibility->userid,'skillid'=>$skillId])){
        //     continue;
        // }
        $teacherSkillRelation = new stdClass();
        $teacherSkillRelation->skillid = $skillId;
        $teacherSkillRelation->userid = $teacherDisponibility->userid;
        $teacherSkillRelation->usermodified = $USER->id;
        $teacherSkillRelation->timecreated =time();
        $teacherSkillRelation->timemodified = time();
        $DB->insert_record('gmk_teacher_skill_relation',$teacherSkillRelation);
    }
    
    return true;
}

function bulk_update_teachers_disponibilities($disponibilityRecords){
    $results = [];

    global $DB;
    $userDocumentCustomFieldId = $DB->get_record('user_info_field',['shortname'=>'documentnumber'])->id;
    foreach($disponibilityRecords as $disponibilityRecord){
        $instructorDocument = $disponibilityRecord['instructorId'];
        
        $results[$instructorDocument]=[];
        $results[$instructorDocument]['instructorId']=$instructorDocument;
        
        $disponibilityRecord['instructorId'] = $DB->get_record_sql(
            "SELECT userid FROM {user_info_data} WHERE fieldid = ? AND " . $DB->sql_compare_text('data') . " = ?",
            [$userDocumentCustomFieldId, $disponibilityRecord['instructorId']]
        )->userid;
        try{
            if(!$disponibilityRecord['instructorId']){
                throw new Exception(json_encode(['No hay usuario con el número de documento '.$instructorDocument]));
            }
            if(!$DB->get_record('gmk_teacher_disponibility',['userid'=>$disponibilityRecord['instructorId']])){
                $newDisponibilityId = add_teacher_disponibility($disponibilityRecord);
                $results[$instructorDocument]['status']=1;
                $results[$instructorDocument]['message']='Disponibility created with id '.$newDisponibilityId;
                continue;
            }
            $disponibilityUpdated = update_teacher_disponibility($disponibilityRecord);
            $results[$instructorDocument]['status']=1;
            $results[$instructorDocument]['message']='Disponibility updated';
        }catch (Exception $e){
            $results[$instructorDocument]['status']=-1;
            $results[$instructorDocument]['message']=$e->getMessage();
        }
    }
    return $results;
}

function get_academic_calendar_period($filters){
    global $DB;
    
    $calendarRecords = $DB->get_records('gmk_academic_calendar',$filters);
    // print_object($calendarRecords);
    $calendarRecordsFormatted = [];
    foreach($calendarRecords as $calendarRecord){
        if(!array_key_exists($calendarRecord->period,$calendarRecordsFormatted)){
            $periodRecordFormatted = new stdClass();
            $calendarRecordsFormatted[$calendarRecord->period] = $periodRecordFormatted;
        }
        else {
            $periodRecordFormatted = $calendarRecordsFormatted[$calendarRecord->period];
        }
        $bimesterNumber = $calendarRecord->bimesternumber;
        
        $periodRecordFormatted->period = $calendarRecord->period;
        $periodRecordFormatted->bimesters[$bimesterNumber] = $calendarRecord->bimester;
        
        
        $periodRecordFormatted->start[$bimesterNumber] = date('d-m-Y',$calendarRecord->periodstart);
        $periodRecordFormatted->end[$bimesterNumber] = date('d-m-Y',$calendarRecord->periodend);
        $periodRecordFormatted->induction = date('d-m-Y', $calendarRecord->induction);
        
        $finalExamRangeStrData = new stdClass();
        $finalExamRangeStrData->examFrom=date('d-m-y', $calendarRecord->finalexamfrom);
        $finalExamRangeStrData->examUntil=date('d-m-y', $calendarRecord->finalexamuntil);
        
        $periodRecordFormatted->finalExamRange[$bimesterNumber] = get_string('academiccalendar:academic_calendar_table:final_exam_cell','local_grupomakro_core', $finalExamRangeStrData);
        
        $periodRecordFormatted->loadnotesandclosesubjects[$bimesterNumber] = date('d-m-Y',$calendarRecord->loadnotesandclosesubjects);
        $periodRecordFormatted->delivoflistforrevalbyteach[$bimesterNumber] = date('d-m-Y',$calendarRecord->delivoflistforrevalbyteach);
        $periodRecordFormatted->notiftostudforrevalidations[$bimesterNumber] = date('d-m-Y',$calendarRecord->notiftostudforrevalidations);
        $periodRecordFormatted->deadlforpayofrevalidations[$bimesterNumber] = date('d-m-Y',$calendarRecord->deadlforpayofrevalidations);
        $periodRecordFormatted->revalidationprocess[$bimesterNumber] =$calendarRecord->revalidationprocess === "0" ? '':date('d-m-Y',$calendarRecord->revalidationprocess);
        
        if($calendarRecord->registrationsfrom === '0' || $calendarRecord->registrationsuntil === '0'){
            $periodRecordFormatted->registrationRange[$bimesterNumber] = '';
        }
        else{
            $registrationRangeStrData = new stdClass();
            $registrationRangeStrData->registrationFrom=date('d-m-y', $calendarRecord->registrationsfrom);
            $registrationRangeStrData->registrationUntil=date('d-m-y', $calendarRecord->registrationsuntil);
            $periodRecordFormatted->registrationRange[$bimesterNumber] = get_string('academiccalendar:academic_calendar_table:registration_cell','local_grupomakro_core', $registrationRangeStrData);
        }
        $periodRecordFormatted->graduationdate[$bimesterNumber] =$calendarRecord->graduationdate === "0" ? '':date('d-m-Y',$calendarRecord->graduationdate);
    }
    return $calendarRecordsFormatted;
}

function parse_academic_calendar_period_excel($academicCalendarPeriod){
    
    define('COLUMN_PERIOD', 'A');
    define('COLUMN_BIMESTER', 'B');
    define('COLUMN_PERIOD_START', 'C');
    define('COLUMN_PERIOD_END', 'D');
    define('COLUMN_INDUCTION', 'E');
    define('COLUMN_FINAL_EXAM_FROM', 'F');
    define('COLUMN_FINAL_EXAM_UNTIL', 'G');
    define('COLUMN_LOAD_NOTES', 'H');
    define('COLUMN_DELIVERY_LIST', 'I');
    define('COLUMN_NOTIFICATION', 'J');
    define('COLUMN_DEADLINES', 'K');
    define('COLUMN_REVALIDATION', 'L');
    define('COLUMN_REGISTRATIONS_FROM', 'M');
    define('COLUMN_REGISTRATIONS_UNTIL', 'N');
    define('COLUMN_GRADUATION_DATE', 'O');
    
    $romanNumerals = [
        'I' => 1,
        'II' => 2,
        'III' => 3,
        'IV' => 4,
        'V' => 5,
        'VI' => 6,
    ];
    
    
    $content = $academicCalendarPeriod->get_content();
    // Create a temporary file to load the content
    $tempFilePath = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($tempFilePath, $content);

    // Load the XLSX file using PhpSpreadsheet
    $spreadsheet = IOFactory::load($tempFilePath);

    // Process the sheet with ranges
    $rangeSheet = $spreadsheet->getSheet(0);
    
    $bimesterDatesRecords = [];
    $createdBimesterDatesRecordsIds = [];
    $updatedBimesterDatesRecordsIds = [];
    
    global $DB,$USER;
    
    try{
        foreach ($rangeSheet->getRowIterator(2) as $row) {
            
            // Create an instance of stdClass
            $bimesterDates = new stdClass();
            $bimesterDates->period = $rangeSheet->getCell(COLUMN_PERIOD . $row->getRowIndex())->getValue();
            
            if(!$bimesterDates->period){
                continue;
            }
            
            $periodExploded = explode('-', $bimesterDates->period);
            $bimesterDates->year = $periodExploded[0];
            $bimesterDates->yearquarter = $romanNumerals[$periodExploded[1]];
            $bimesterDates->bimester = $rangeSheet->getCell(COLUMN_BIMESTER . $row->getRowIndex())->getValue();
            $bimesterDates->bimesternumber = $romanNumerals[explode(' ', $bimesterDates->bimester)[0]];
            $bimesterDates->periodstart = Date::excelToDateTimeObject($rangeSheet->getCell(COLUMN_PERIOD_START . $row->getRowIndex())->getValue())->getTimestamp();
            $bimesterDates->periodend = Date::excelToDateTimeObject($rangeSheet->getCell(COLUMN_PERIOD_END . $row->getRowIndex())->getValue())->getTimestamp();
            $bimesterDates->induction = Date::excelToDateTimeObject($rangeSheet->getCell(COLUMN_INDUCTION . $row->getRowIndex())->getValue())->getTimestamp();
            $bimesterDates->finalexamfrom = Date::excelToDateTimeObject($rangeSheet->getCell(COLUMN_FINAL_EXAM_FROM . $row->getRowIndex())->getValue())->getTimestamp();
            $bimesterDates->finalexamuntil = Date::excelToDateTimeObject($rangeSheet->getCell(COLUMN_FINAL_EXAM_UNTIL . $row->getRowIndex())->getValue())->getTimestamp();
            $bimesterDates->loadnotesandclosesubjects = Date::excelToDateTimeObject($rangeSheet->getCell(COLUMN_LOAD_NOTES . $row->getRowIndex())->getValue())->getTimestamp();
            $bimesterDates->delivoflistforrevalbyteach = Date::excelToDateTimeObject($rangeSheet->getCell(COLUMN_DELIVERY_LIST . $row->getRowIndex())->getValue())->getTimestamp();
            $bimesterDates->notiftostudforrevalidations = Date::excelToDateTimeObject($rangeSheet->getCell(COLUMN_NOTIFICATION . $row->getRowIndex())->getValue())->getTimestamp();
            $bimesterDates->deadlforpayofrevalidations = Date::excelToDateTimeObject($rangeSheet->getCell(COLUMN_DEADLINES . $row->getRowIndex())->getValue())->getTimestamp();
            $bimesterDates->revalidationprocess = Date::excelToDateTimeObject($rangeSheet->getCell(COLUMN_REVALIDATION . $row->getRowIndex())->getValue())->getTimestamp();
            $bimesterDates->registrationsfrom = Date::excelToDateTimeObject($rangeSheet->getCell(COLUMN_REGISTRATIONS_FROM . $row->getRowIndex())->getValue())->getTimestamp();
            $bimesterDates->registrationsuntil = Date::excelToDateTimeObject($rangeSheet->getCell(COLUMN_REGISTRATIONS_UNTIL . $row->getRowIndex())->getValue())->getTimestamp();
            $bimesterDates->graduationdate = Date::excelToDateTimeObject($rangeSheet->getCell(COLUMN_GRADUATION_DATE . $row->getRowIndex())->getValue())->getTimestamp();
            $bimesterDates->academicperiodid = $bimesterDates->year.$bimesterDates->yearquarter.$bimesterDates->bimesternumber;
    
            $bimesterDatesRecords[] = $bimesterDates;
        }
        
        foreach($bimesterDatesRecords as $bimesterDates){
            $bimesterDates->timemodified = time();
            $bimesterDates->usermodified = $USER->id;
            if($bimerterDateId = $DB->get_field('gmk_academic_calendar','id',['academicperiodid'=>$bimesterDates->academicperiodid])){
                $bimesterDates->id = $bimerterDateId;
                $DB->update_record('gmk_academic_calendar',$bimesterDates);
                $updatedBimesterDatesRecordsIds[]=$bimesterDates->id;
                continue;
            }
            $bimesterDates->timecreated = time();
            $createdBimesterDatesRecordsIds[] = $DB->insert_record('gmk_academic_calendar',$bimesterDates);
        }
        
        $academicCalendarPeriod->delete();
        return ['created'=>$createdBimesterDatesRecordsIds,'updatded'=>$updatedBimesterDatesRecordsIds];
    }catch (Exception $e){
        $academicCalendarPeriod->delete();
        $DB->delete_records_list('gmk_academic_calendar','id',$createdBimesterDatesRecordsIds);
        throw $e;
    }
}

function parse_bulk_disponibilities_CSV($bulkDisponibilitiesFile){
    global $DB;
    
    $teacherSkills = $DB->get_records('gmk_teacher_skill');
    $teacherSkillsMinimized = [];
    foreach($teacherSkills as $teacherSkill){
        $teacherSkillsMinimized[cleanString($teacherSkill->name)]=$teacherSkill->id;
    }
    $disponibilityRecords=[];
    
    $days = [
        'lunes',
        'martes',
        'miercoles',
        'jueves',
        'viernes',
        'sabado',
        'domingo',
    ];
    
    $errors=[];
    // Get the file content
    $content = $bulkDisponibilitiesFile->get_content();

    // Create a temporary file to load the content
    $tempFilePath = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($tempFilePath, $content);

    // Load the XLSX file using PhpSpreadsheet
    $spreadsheet = IOFactory::load($tempFilePath);

    // Process the sheet with ranges
    $rangeSheet = $spreadsheet->getSheet(0);
    foreach ($rangeSheet->getRowIterator(2) as $row) {
        $instructorId = $rangeSheet->getCell('A' . $row->getRowIndex())->getValue();
        if (!$instructorId){
            // $errors[]='Error en hoja horario: columna A, fila '.$row->getRowIndex().'. El número de documento es requerido.';
            continue;
        }
        
        $day = cleanString($rangeSheet->getCell('B' . $row->getRowIndex())->getValue());
        if(!in_array($day,$days)){
            $errors[]='Error en hoja horario: columna B, fila '.$row->getRowIndex().'. Día '.$day.' no definido.';
            continue;
        }
        
        $schedule = $rangeSheet->getCell('C' . $row->getRowIndex())->getValue();
        if(!isValidTimeRange($schedule)){
            $errors[]='Error en hoja horario: columna C, fila '.$row->getRowIndex().'. El rango '.$schedule.' tiene mal formato.';
            continue;
        }
        $timeRange = '[' . $schedule . ']';
        
        if(!isset($disponibilityRecords[$instructorId])){
            $disponibilityRecords[$instructorId]['instructorId']=$instructorId;
            $disponibilityRecords[$instructorId]['newDisponibilityRecords'][$day]['day']=$day;
            $disponibilityRecords[$instructorId]['newDisponibilityRecords'][$day]['timeslots']=$timeRange;
            $disponibilityRecords[$instructorId]['skills']=[];
            continue;

        }
        if(!isset($disponibilityRecords[$instructorId]['newDisponibilityRecords'][$day])){
            $disponibilityRecords[$instructorId]['newDisponibilityRecords'][$day]['day']=$day;
            $disponibilityRecords[$instructorId]['newDisponibilityRecords'][$day]['timeslots']=$timeRange;
            continue;
        }
        $disponibilityRecords[$instructorId]['newDisponibilityRecords'][$day]['timeslots'].=$timeRange;
    }

    // Process the sheet with skills
    $skillsSheet = $spreadsheet->getSheet(1);
    
    foreach ($skillsSheet->getRowIterator(2) as $row) {
        $instructorId = $skillsSheet->getCell('A' . $row->getRowIndex())->getValue();
        if (!$instructorId){
            // $errors[]='Error en hoja habilidades: columna A, fila '.$row->getRowIndex().'. El número de documento es requerido.';
            continue;
        }
        
        $skill = $skillsSheet->getCell('B' . $row->getRowIndex())->getValue();
        // $teacherSkills
        if (!$skill){
            // $errors[]='Error en hoja habilidades: columna B, fila '.$row->getRowIndex().'. El ID debe ser númerico.';
            continue;
        }
        $skill = cleanString($skill);
        if(!array_key_exists($skill,$teacherSkillsMinimized)){
            $errors[]='Error en hoja habilidades: columna B, fila '.$row->getRowIndex().'. La competencia '.$skill.' no es valida.';
            continue;
        }
        
        if(!isset($disponibilityRecords[$instructorId])){
            $disponibilityRecords[$instructorId]['instructorId']=$instructorId;
            $disponibilityRecords[$instructorId]['newDisponibilityRecords']=[];
            $disponibilityRecords[$instructorId]['skills']=[$teacherSkillsMinimized[$skill]];
            continue;
        }
        $disponibilityRecords[$instructorId]['skills'][]=$teacherSkillsMinimized[$skill];
    }
    
    unlink($tempFilePath);
    
    $bulkDisponibilitiesFile->delete();
    
    if(!empty($errors)){
        
        throw new Exception(json_encode($errors));
    }
    
    $disponibilityRecords = array_map(function ($disponibilityRecord){
        $disponibilityRecord['newDisponibilityRecords'] = array_map(function($dayRange){
            $dayRange['timeslots']=parse_bulk_time_ranges($dayRange['timeslots']);
            return $dayRange;
        },$disponibilityRecord['newDisponibilityRecords']);
        return $disponibilityRecord;
    },$disponibilityRecords);
    return $disponibilityRecords;
}

function isValidTimeRange($timeString) {
    // Regular expression to match 'HH:MM' format
    $pattern = '/^(?:2[0-3]|[01][0-9]):[0-5][0-9]-(?:2[0-3]|[01][0-9]):[0-5][0-9]$/';

    // Check if the string matches the pattern
    return preg_match($pattern, $timeString) === 1;
}

function parse_bulk_time_ranges($timeRanges){  
    $pattern = '/\[(\d{2}:\d{2}-\d{2}:\d{2})\]/';
    preg_match_all($pattern, $timeRanges, $matches);
    $timeslots = [];
    foreach ($matches[1] as $match) {
        $range = str_replace('-', ', ', $match);
        $timeslots[] = $range;
    }
    return $timeslots;
}

function close_current_period(){
    global $DB,$USER;
    
    $prerequisiteCustomFieldId = $DB->get_record('customfield_field',['shortname'=>'pre'])->id;
    $studentRoleId = $DB->get_record('role',['shortname'=>'student'])->id;
    $enrolplugin = enrol_get_plugin('manual');
    $learningPlans = $DB->get_records('local_learning_plans');
    
    $learningPlans = ['35'=>$learningPlans['35']];
    
    foreach($learningPlans as $learningPlan){
        
        $learningPlanPeriods = $DB->get_records('local_learning_periods',['learningplanid'=>$learningPlan->id]);
        $learningPlanCourses = $DB->get_records('local_learning_courses',['learningplanid'=>$learningPlan->id]);
        
        foreach($learningPlanPeriods as $learningPlanPeriod){
            
            $LPPeriodStudents = $DB->get_records('local_learning_users',['learningplanid'=>$learningPlan->id,'currentperiodid'=>$learningPlanPeriod->id,'userroleid'=>5]);
            if(!$LPPeriodStudents){
                continue;
            }
            $nextPeriod = $learningPlanPeriods[$learningPlanPeriod->id+1];
            if(!$nextPeriod){
                continue;
            }
            $nextPeriodCourses = array_filter($learningPlanCourses,function ($course) use ($learningPlanPeriod){
                return $course->periodid == $learningPlanPeriod->id+1;
            });
            
            $DB->get_records('local_learning_courses',['learningplanid'=>$learningPlan->id,'periodid'=>$learningPlanPeriod->id]);
            $nextPeriodCourses =  $DB->get_records('local_learning_courses',['learningplanid'=>$learningPlan->id,'periodid'=>$nextPeriod->id]);
            
            foreach($nextPeriodCourses as $nextPeriodCourse){
                $course = get_course($nextPeriodCourse->courseid);
                $coursePreRequisites = explode(',',$DB->get_record('customfield_data',['fieldid'=>$prerequisiteCustomFieldId,'instanceid'=>$nextPeriodCourse->courseid])->value);
                
                foreach($LPPeriodStudents as $LPPeriodStudent){
                    $LPPeriodStudent->currentperiodid = $nextPeriod->id;
                    $DB->update_record('local_learning_users',$LPPeriodStudent);
                    
                    if($coursePreRequisites){
                        $preRequisitesComplete = true;
                        
                        foreach($coursePreRequisites as $coursePreRequisite){
                            $preRequisiteCourse = $DB->get_record('course',['shortname'=>$coursePreRequisite]);
                            $preRequisiteCourseCompletion = new completion_info($preRequisiteCourse);
                            $preRequisiteCourseComplete = $preRequisiteCourseCompletion->is_course_complete($LPPeriodStudent->userid);
                            
                            if(!$preRequisiteCourseComplete){
                                $preRequisitesComplete = false;
                                break;
                            }
                        }
                        if(!$preRequisitesComplete){
                            continue;
                        }
                    }
                    $courseInstance = get_manual_enroll($course->id);
                    $enrolled = $enrolplugin->enrol_user($courseInstance, $LPPeriodStudent->userid, $studentRoleId);
                }
            }
        }
    }

    die;
}

function get_teacher_available_courses($params){
    global $DB;
    
    $teacherSkills = $DB->get_records('gmk_teacher_skill_relation',['userid'=>$params['instructorId']]);
    
    $learningPlanPeriodCourses = $DB->get_records('local_learning_courses',['learningplanid'=>$params['learningPlanId'],'periodid'=>$params['periodId']]);
    $learningPlanPeriodCourses = array_filter(array_map(function ($course) use ($DB,$teacherSkills){
        $course->name = $DB->get_record('course',['id'=>$course->courseid])->fullname;
        $foundedRequiredSkill = false;
        foreach($teacherSkills as $teacherSkill){
            $teacherSkillName = $DB->get_record('gmk_teacher_skill',['id'=>$teacherSkill->skillid])->name;
            $foundedRequiredSkill = containsSubstringIgnoringCaseAndTildes($teacherSkillName,$course->name);
            if($foundedRequiredSkill){
                return $course;
            }
        }
        return null;
    },$learningPlanPeriodCourses));
    return $learningPlanPeriodCourses;
}

function check_reschedule_conflicts($params){
    
    global $DB;
    
    $errors = [];
    $weekdays = array(
      'Monday' => 'Lunes',
      'Tuesday'=> 'Martes',
      'Wednesday' => 'Miércoles',
      'Thursday' => 'Jueves',
      'Friday' => 'Viernes',
      'Saturday' => 'Sábado',
      'Sunday' => 'Domingo'
    );
    
    $classInfo = list_classes(['id'=>$params['classId']])[$params['classId']];
    
    //Check the instructor availability
    $instructorUserId = $classInfo->instructorid;

    //Get the day of the week in English from the Unix timestamp
    $incomingWeekDay= $weekdays[date('l', strtotime($params['date']))];
    $incomingTimeRangeTS = convert_time_range_to_timestamp_range([$params['initTime'],$params['endTime']]);
    
    $instructorEvents = get_teacher_disponibility_calendar($instructorUserId)[$instructorUserId];
    $incomingDayAvailableTime = $instructorEvents->daysFree[$params['date']];
    
    $foundedAvailableRange = false;
    for ($i = 0; $i < count($incomingDayAvailableTime); $i+=2) {
        $freeTimeRangeTS = convert_time_range_to_timestamp_range([$incomingDayAvailableTime[$i],$incomingDayAvailableTime[$i+1]]);
        if($incomingTimeRangeTS['initTS'] >=$freeTimeRangeTS['initTS'] && $incomingTimeRangeTS['endTS'] <=$freeTimeRangeTS['endTS']){
            $foundedAvailableRange = true;
            break;
        }
    }
    if(!$foundedAvailableRange){
        $errors[] = "El instructor no esta disponible el día ".$incomingWeekDay." en el horario ".$params['initTime']." - ".$params['endTime'].'.';
    }
    
    //Check the group members and count how many students are in conflict with the new date and time

    $groupMembers = $DB->get_records('groups_members',array('groupid'=>$classInfo->groupid));

    foreach ($groupMembers as $key => $groupMember) {
        if ($groupMember->userid == $instructorUserId) {
            unset($groupMembers[$key]);
            continue;
        }
        $studentEvents = get_class_events($groupMember->userid);
        foreach($studentEvents as $studentEvent){
            $eventStart = explode(' ',$studentEvent->start);
            $eventEnd = explode(' ',$studentEvent->end);
            if($eventStart[0] === $date){
                continue;
            }
            $eventTimeRangeTS = convert_time_range_to_timestamp_range([$eventStart[1],$eventEnd[1]]);
            
            if(($incomingTimeRangeTS['initTS'] >= $eventTimeRangeTS['initTS'] && $incomingTimeRangeTS['endTS']<=$eventTimeRangeTS['endTS']) 
                ||($incomingTimeRangeTS['initTS'] < $eventTimeRangeTS['initTS'] && $incomingTimeRangeTS['endTS']>$eventTimeRangeTS['initTS']) 
                ||($incomingTimeRangeTS['initTS'] < $eventTimeRangeTS['endTS'] && $incomingTimeRangeTS['endTS']>$eventTimeRangeTS['endTS'])){
                $userInfo = $DB->get_record('user',['id'=>$groupMember->userid]);
                $errors[]='El estudiante '.$userInfo->firstname.' '.$userInfo->lastname.' presenta conflictos con el horario de la clase '.$studentEvent->className;
                break;
            }
            
        }
    }
    // --------------------------------------------------------------------
    $rescheduleConflicts = !empty($errors);
    
    return ['hasConflicts'=>$rescheduleConflicts, 'conflicts'=>$errors];
}

function reschedule_class_activity($params){
    global $DB;
    
    //First we get the modules id defined in the modules table, this can vary between moodle installations, so we make sure we hace the correct ids
    $attendanceModuleId = $DB->get_record('modules', array('name' =>'attendance'), '*', MUST_EXIST)->id;
    $bigBlueButtonModuleId = $DB->get_record('modules', array('name' =>'bigbluebuttonbn'), '*', MUST_EXIST)->id;
    
    //Get the module activity and the class type
    $moduleInfo =  $DB->get_record('course_modules', array('id' =>$params['moduleId']), '*', MUST_EXIST);
    $courseModule = $DB->get_record('modules', array('id' =>$moduleInfo->module), '*', MUST_EXIST);
    $moduleActivity =  $DB->get_record('modules', array('id' =>$moduleInfo->module), '*', MUST_EXIST)->name;
    $classInfo = list_classes(['id' =>$params['classId']])[$params['classId']];
    $classSectionNumber = $DB->get_record('course_sections',['id'=>$classInfo->coursesectionid])->section;
    
    $initDateTime = $params['date'].' '.$params['initTime'];
    $endDateTime = $params['date'].' '.$params['endTime'];
    $initTimestamp = strtotime($initDateTime);
    $endTimestamp = strtotime($endDateTime);
    $classDurationInSeconds = $endTimestamp-$initTimestamp;
    
    $BBBmoduleId = $DB->get_record('modules',['name'=>'bigbluebuttonbn'])->id;
    
    // If the class type is 0 (presencial), just replace the session on the attendance module
    if($classInfo->type === '0'){
        $attendanceSessionRescheduled = replace_attendance_session($params['moduleId'],$params['sessionId'],$initTimestamp,$classDurationInSeconds,$classInfo);
    }
    
    // If the class type is 1 (virtual), we need to replace the big blue button module
    else if($classInfo->type === '1'){
        course_delete_module($params['moduleId']);
        $bigBluebuttonActivityRescheduled = create_big_blue_button_activity($classInfo,$initTimestamp,$endTimestamp,$BBBmoduleId,$classSectionNumber);
    }
     // If the class type is 2 (mixta), we need to reschedule both big blue button activity and attendance session
    else if($classInfo->type === '2'){
        
        // print_object($moduleInfo->id);
        // die;
        if ($moduleActivity === 'bigbluebuttonbn'){
            $bigBlueButtonInstanceModuleId = $moduleInfo->id;
            $bigBlueButtonActivityInfo =  $DB->get_record('bigbluebuttonbn',['id'=>$moduleInfo->instance]);
            $bigBlueButtonActivityInitTS = $bigBlueButtonActivityInfo->openingtime;
            
            // If the reschedule was triggered from the big blue button activity, we must search the attendance session that begins with the same timestamp 
            $classAttendanceModule = $DB->get_record('course_modules',['section'=>$classInfo->coursesectionid , 'module'=>$attendanceModuleId]);
            $classAttendanceModuleId = $classAttendanceModule->id;
            $classAttendanceSessionId = $DB->get_record('attendance_sessions',['attendanceid'=>$classAttendanceModule->instance, 'sessdate'=>$bigBlueButtonActivityInitTS])->id;
        }
        else if ($moduleActivity === 'attendance'){
            $classAttendanceModuleId = $moduleInfo->id;
            $classAttendanceSessionId = $params['sessionId'];
            
            // If the reschedule was triggered from the attendance session, we must search the big bluebutton activity that begins with the same timestamp 
            $classAttendanceSessionInitTS = $DB->get_record('attendance_sessions',[ 'id'=>$classAttendanceSessionId])->sessdate;
            $bigBlueButtonActivityInfo = null;
            $bigBlueButtonActivityItems= $DB->get_records('bigbluebuttonbn',['openingtime'=>$classAttendanceSessionInitTS, 'name'=>$classInfo->name.'-'.$classInfo->id.'-'.$classAttendanceSessionInitTS]);
            foreach($bigBlueButtonActivityItems as $bigBlueButtonActivityItem){
                if (!$bigBlueButtonActivityInfo){
                    $bigBlueButtonActivityInfo = $bigBlueButtonActivityItem;
                    continue;
                }
                $bigBlueButtonActivityInfo = $bigBlueButtonActivityItem->timecreated >$bigBlueButtonActivityInfo->timecreated ?$bigBlueButtonActivityItem :$bigBlueButtonActivityInfo;
            }
            $bigBlueButtonInstanceModuleId = $DB->get_record('course_modules',['instance'=>$bigBlueButtonActivityInfo->id , 'module'=>$bigBlueButtonModuleId])->id;
        }
        
        //With the ids required to do the reschedule setted, lets use the methods to reschedute them
        
        //For attendance
        $attendanceSessionRescheduled = replace_attendance_session($classAttendanceModuleId,$classAttendanceSessionId,$initTimestamp,$classDurationInSeconds,$classInfo);
        
        //For BBB
        course_delete_module($bigBlueButtonInstanceModuleId);
        $bigBluebuttonActivityRescheduled = create_big_blue_button_activity($classInfo,$initTimestamp,$endTimestamp,$BBBmoduleId,$classSectionNumber);

    }
    return true;
}

//Por revisar

function get_class_events($userId) {
    global $DB;
    $initDate = '2022-01-01';
    $endDate = '2024-12-30';
    $moduleIds = ["bigbluebuttonbn"=>$DB->get_field('modules','id',['name'=>'bigbluebuttonbn']),"attendance"=>$DB->get_field('modules','id',['name'=>'attendance'])];
    
    $fetchedClasses=[];
    $fetchedCourses = [];
    $eventDaysFiltered = [];
    
    $events = calendar_get_events(strtotime($initDate),strtotime($endDate),$userId,true,true,false,false,false);
    $eventsWithoutUnsetedEvents = $events;
    
    foreach($events as $eventKey => $event){
        if(!array_key_exists($eventKey,$eventsWithoutUnsetedEvents)){
            continue;
        }
        unset($eventsWithoutUnsetedEvents[$eventKey]);
        
        if(!array_key_exists($event->modulename, $moduleIds) || !$event->instance){
            continue;
        }
        
        list($event,$fetchedClasses,$fetchedCourses) = complete_class_event_information($event,$fetchedClasses,$fetchedCourses,$moduleIds);
        
        if(!$event){
            continue;
        }

        if($event->classType !== '0'){
            foreach ($eventsWithoutUnsetedEvents as $pairEventKey => $pairEvent) {

                list($pairEvent,$fetchedClasses,$fetchedCourses) = complete_class_event_information($pairEvent,$fetchedClasses,$fetchedCourses,$moduleIds);
                
                if ($pairEvent->classId === $event->classId && $pairEvent->timestart === $event->timestart) {
                    if($event->modulename ==='bigbluebuttonbn'){
                        $event->attendanceActivityUrl = $pairEvent->attendanceActivityUrl;
                        $event->sessionId = $pairEvent->sessionId;
                    }else if($event->modulename ==='attendance'){
                        $event->bigBlueButtonActivityUrl = $pairEvent->bigBlueButtonActivityUrl;
                    } 
                    unset($eventsWithoutUnsetedEvents[$pairEventKey]);
                    break;
                }
            }
        }
        $eventDaysFiltered[]=$event;
    }
    
    if($userId){
        $learningPlanUserRoles = $DB->get_records('local_learning_users', ['userid'=>$userId],'','id,userroleid,learningplanid');
        
        if (!$learningPlanUserRoles){
            throw new Exception('The user has no learning plans.');
        }
        
        $eventsFiltered = [];
        
        foreach($learningPlanUserRoles as $learningPlanUserRole){
            
            $userLearningPlanRole = $learningPlanUserRole->userroleid;
            $learningPlanUserId = $learningPlanUserRole->id;
            
            if ($userLearningPlanRole === '4'){

                $eventsFilteredByTeacher=[];
                foreach($eventDaysFiltered as $event){
                    if($event->instructorlpid ===$learningPlanUserId){
                        $event->role = 'teacher';
                        $eventsFilteredByTeacher[]=$event;
                    }
                }
                $eventsFiltered = array_merge($eventsFiltered, $eventsFilteredByTeacher);
            }
            else if($userLearningPlanRole === '5'){
                $asignedGroups = $DB->get_records('groups_members', ["userid"=>$userId],'','groupid');
            
                $asignedClasses = [];
                
                foreach($asignedGroups as $asignedGroup){
                    
                    $groupClassId = $DB->get_field('gmk_class','id', ["groupid"=>$asignedGroup->groupid]);
                    // $groupClassId = $DB->get_field('gmk_class','id', ["groupid"=>$asignedGroup->groupid , "learningplanid"=>$learningPlanUserRole->learningplanid]);
                    $groupClassId? $asignedClasses[]=$groupClassId :null;
                }
                $eventsFilteredByClass=array();
                foreach($eventDaysFiltered as $event){
                    if(in_array($event->classId,$asignedClasses)){
                        $event->role = 'student';
                        unset($event->attendanceActivityUrl);
                        $eventsFilteredByClass[]=$event;
                    }
                }
                $eventsFiltered = array_merge($eventsFiltered, $eventsFilteredByClass);
            }
            
        }

        $eventDaysFiltered =$eventsFiltered;
    }
    return $eventDaysFiltered;
}

function complete_class_event_information($event,$fetchedClasses,$fetchedCourses,$moduleIds){
    global $DB,$CFG;
    
    define('PRESENCIAL_CLASS_TYPE_INDEX','0');
    define('VIRTUAL_CLASS_TYPE_INDEX','1');
    define('MIXTA_CLASS_TYPE_INDEX','2');
    define('PRESENCIAL_CLASS_COLOR','#00bcd4');
    define('VIRTUAL_CLASS_COLOR','#2196f3');
    define('MIXTA_CLASS_COLOR','#673ab7');
    
    $eventColors = [
        PRESENCIAL_CLASS_TYPE_INDEX=>PRESENCIAL_CLASS_COLOR,
        VIRTUAL_CLASS_TYPE_INDEX=>VIRTUAL_CLASS_COLOR,
        MIXTA_CLASS_TYPE_INDEX=>MIXTA_CLASS_COLOR];

    $moduleInfo = $DB->get_record('course_modules', ['instance'=>$event->instance, 'module'=>$moduleIds[$event->modulename]],'id,section');
    $moduleSectionId = $moduleInfo->section;
    
    //Save the fetched classes to minimize db queries
    if(array_key_exists($moduleSectionId,$fetchedClasses)){
        $gmkClass = $fetchedClasses[$moduleSectionId];
    }else {
        
        if(!$classId = $DB->get_field('gmk_class', 'id',['coursesectionid'=>$moduleSectionId])){
            return [false,$fetchedClasses,$fetchedCourses];
        }
        
        $gmkClass =list_classes(["id"=>$classId])[$classId];
        $fetchedClasses[$moduleSectionId] = $gmkClass;
    }
    //Set the class information for the event
    $event->moduleId = $moduleInfo->id;
    $event->instructorName = $gmkClass->instructorName;
    $event->timeRange = $gmkClass->inithourformatted.' - '. $gmkClass->endhourformatted;
    $event->classDaysES = $gmkClass->selectedDaysES;
    $event->classDaysEN = $gmkClass->selectedDaysEN;
    $event->typelabel = $gmkClass->typelabel;
    $event->classType = $gmkClass->type;
    $event->className = $gmkClass->name;
    $event->classId = $gmkClass->id;
    $event->instructorlpid = $gmkClass->instructorlpid;
    $event->groupid = $gmkClass->groupid;
    $event->timeduration = $gmkClass->classduration;
    $event->color = $eventColors[ $gmkClass->type];
    
    //Set the module url

    if($event->modulename === 'bigbluebuttonbn'){
        $event->bigBlueButtonActivityUrl = $CFG->wwwroot.'/mod/bigbluebuttonbn/view.php?id='.$moduleInfo->id;
    }else if ($event->modulename === 'attendance'){
        $event->attendanceActivityUrl = $CFG->wwwroot.'/mod/attendance/view.php?id='.$moduleInfo->id;
        $event->sessionId = $DB->get_field('attendance_sessions','id',['attendanceid'=>$event->instance, 'caleventid'=>$event->id]);
    }
    
    //Set the initial date and the end date of the event
    $event->start = date('Y-m-d H:i:s',$event->timestart);
    $event->end = date('Y-m-d H:i:s',$event->timestart + $event->timeduration);
    
    //Get the coursename, save the fetched coursenames for minimize db queries
    if(array_key_exists($event->courseid,$fetchedCourses)){
        $event->coursename = $fetchedCourses[$event->courseid];
    }else {
        $event->coursename = $DB->get_field('course','fullname', ['id'=>$event->courseid])->fullname;
        $fetchedCourses[$event->courseid] = $event->coursename;
    }
    
    return [$event,$fetchedClasses,$fetchedCourses];
}

function createClassroomReservations ($classInfo){
    
    $initDate = '2023-08-01';
    $endDate = '2023-08-08';
    
    
    //Calculate the class session duration in seconds
    $initDateTime = DateTime::createFromFormat('H:i', $classInfo->inittime);
    $endDateTime = DateTime::createFromFormat('H:i', $classInfo->endtime);
    $classDurationInSeconds = strtotime($endDateTime->format('Y-m-d H:i:s'))-strtotime($initDateTime->format('Y-m-d H:i:s'));
    //
    
    //Get the period start date in seconds and the day name
    $startDate = DateTime::createFromFormat('Y-m-d H:i:s', $initDate.' '.$classInfo->inittime.':00');
    $startDateTS = strtotime($startDate->format('Y-m-d H:i:s'));
    //
    
    //Get the period end date timestamp(seconds)
    $endDate = DateTime::createFromFormat('Y-m-d H:i:s', $endDate.' '.$classInfo->endtime.':00');
    $endDateTS = strtotime($endDate->format('Y-m-d H:i:s'));
    //
    
    //Format the class days
    $classDaysNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    $classDaysList = array_combine($classDaysNames,explode('/', $classInfo->classdays));
    
    //Define some needed constants
    $currentDateTS = $startDateTS;
    $dayInSeconds = 86400;
    
    
    // Create a new cURL resource
    $curl = curl_init();
    
    // Set the request URL
    $url = 'https://isi-panama-staging-8577170.dev.odoo.com/api/classrooms/'.$classInfo->classroomid.'/reservations';
    
    // Set the options for the cURL request
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); // Return the response as a string instead of outputting it
    // You can set additional options such as headers, request type, data, etc. if needed
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Authorization: tokendepruebas123'
    ));
    
    
    $results = array('success'=>[],'failure'=>[]);
    
    while($currentDateTS < $endDateTS){
        $day =  $classDaysList[date('l',$currentDateTS)];
        if($day==='1'){
            $data = array(
                'name' => $classInfo->name.'-'.$classInfo->id.'-'.$currentDateTS,
                'start_date' => $currentDateTS+3600,
                'end_date' => $currentDateTS + $classDurationInSeconds+3600,
                'classroom_id' => $classInfo->classroomid
            );
            $data_json = json_encode($data);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data_json);
            
            // Execute the cURL request and get the response
            $response = curl_exec($curl);
            // Check if an error occurred
            if (curl_errno($curl)) {
                $error = curl_error($curl);
                // Handle the error appropriately
                // For example, you can log the error or display a custom error message
                echo "cURL Error: " . $error;
            }
            
            // Process the response
            if ($response) {
                // var_dump($response);
            }
        }
        $currentDateTS+=$dayInSeconds;
    }
    // Close the cURL resource
    curl_close($curl);
    
    return $url;
    
}

function get_teacher_disponibility_calendar($instructorId){
    
    global $DB;
    
    $initDate = '2023-06-01';
    $endDate = '2023-12-31';
    
    $filters = array();
    if($instructorId){
        $filters['userid']=$instructorId;
    }
    $disponibilityRecords = $DB->get_records('gmk_teacher_disponibility',$filters);
    
    $weekdays = array(
        'disp_monday',
        'disp_tuesday',
        'disp_wednesday',
        'disp_thursday',
        'disp_friday',
        'disp_saturday',
        'disp_sunday'
    );
    
    $teachersDisponibility = array();
    
    foreach($disponibilityRecords as $disponibilityRecord){
        $teacherId = $disponibilityRecord->userid;
        $teachersDisponibility[$teacherId]= new stdClass();
        $teacherInfo = $DB->get_record('user',['id'=>$teacherId]);
        $teachersDisponibility[$teacherId]->name = $teacherInfo->firstname.' '.$teacherInfo->lastname;
        $teachersDisponibility[$teacherId]->id = $teacherId;
        $teachersDisponibility[$teacherId]->events = get_class_events($teacherId);
        $eventsTimesToSubstract = array();
        
        foreach($teachersDisponibility[$teacherId]->events as $event){
            $eventInitDateAndTime = explode(' ',$event->start);
            $eventDate = $eventInitDateAndTime[0];
            $eventInitTime = substr($eventInitDateAndTime[1],0,5);
            $eventEndTime = substr($event->end,11,5);
            
            $eventInitTime = strtotime($eventInitTime) - strtotime('today');
            $eventEndTime = strtotime($eventEndTime) - strtotime('today');
            
            $newRange1 = new stdClass();
            $newRange1->st = $eventInitTime;
            $newRange1->et = $eventEndTime;
            
            if(array_key_exists($eventInitDateAndTime[0], $eventsTimesToSubstract)){
                $eventsTimesToSubstract[$eventInitDateAndTime[0]][]=$newRange1;
                continue;
            }
            $eventsTimesToSubstract[$eventInitDateAndTime[0]]=array($newRange1);
            
        }
        
        $dayDisponibility = array();

        foreach($weekdays as $day){
            $dayAvailabilities = json_decode($disponibilityRecord->{$day});
            $dayLabel = substr($day, 5);
            $dayDisponibilityHours =$dayAvailabilities; 
            if(empty($dayDisponibilityHours)){
                continue;
            };
            $dayDisponibility[$dayLabel] = $dayDisponibilityHours;
        }

        $date = new DateTime($initDate);
        $lastDate = new DateTime($endDate);
        $result = array();
        while ($date <= $lastDate) {
            $day = $date->format('Y-m-d');
            $date->modify('+1 day');
            $dayLabel = strtolower(date('l', strtotime($day)));
            if(!array_key_exists($dayLabel, $dayDisponibility)){
                continue;
            }
            $result[$day] = $dayDisponibility[$dayLabel];
            
            if(array_key_exists($day,$eventsTimesToSubstract)){
                foreach($eventsTimesToSubstract[$day] as $event){
                    $result[$day] = substract_timerange_from_teacher_disponibility($result[$day], $event);
                }

            }
            if(empty($result[$day])){
                unset($result[$day]);
                continue;
            }

            $rangeHolder = array();
            foreach($result[$day] as $dayRange){
                $rangeHolder[]= sprintf('%02d:%02d', floor($dayRange->st / 3600), floor(($dayRange->st % 3600) / 60));
                $rangeHolder[]= sprintf('%02d:%02d', floor($dayRange->et / 3600), floor(($dayRange->et % 3600) / 60));
            }
            $result[$day] = $rangeHolder;
        }
    $teachersDisponibility[$teacherId]->daysFree = $result;
    }

    return $teachersDisponibility;
}

function list_instructors() {
    
    global $DB;
    $editingTeacherRoleId = $DB->get_record('role',["shortname"=>'editingteacher'])->id;
    $teacherRoleId = $DB->get_record('role',["shortname"=>'teacher'])->id;
    $scTeacherRoleId = $DB->get_record('role',["shortname"=>'scteachrole'])->id;
    $editingTeacherUsers = $DB->get_records('role_assignments',["roleid"=>$editingTeacherRoleId]);
    $teacherUsers = $DB->get_records('role_assignments',["roleid"=>$teacherRoleId]);
    $scTeacherUsers = $DB->get_records('role_assignments',["roleid"=>$scTeacherRoleId]);
    $instructors = array_merge($editingTeacherUsers,$teacherUsers);
    $instructors = array_merge($instructors,$scTeacherUsers);
    $uniqueInstructors= array();
    foreach($instructors as $instructor){
         if(!array_key_exists($instructor->userid, $uniqueInstructors)){
            $userInfo =$DB->get_record('user',['id'=> $instructor->userid]);
            $instructor->fullname = $userInfo->firstname.' '.$userInfo->lastname;
            $instructor->userid = $userInfo->id;
            $uniqueInstructors[$instructor->userid] = $instructor;
         }
    }

    return $uniqueInstructors;
}

function grupomakro_core_list_instructors_with_disponibility_flag(){
    global $DB;
    $instructors = list_instructors();
    foreach($instructors as $instructor){
        if (!$existing_record = $DB->get_record('gmk_teacher_disponibility', array("userid"=>$instructor->userid))) {
            $instructor->hasDisponibility = 0;
            continue;
        }
        $instructor->hasDisponibility = 1;
    }
    return $instructors;
}

function grupomakro_core_list_instructors_with_disponibility(){
    global $DB;
    $filteredInstructors = array();
    $instructors = list_instructors();
    foreach($instructors as $instructor){
        if ($existing_record = $DB->get_record('gmk_teacher_disponibility', array("userid"=>$instructor->userid))) {
            $filteredInstructors[]=$instructor;
        }
    }
    return $filteredInstructors;
}

function calculate_disponibility_range($timeRanges){
     
    $ranges = [];
    
    foreach ($timeRanges as $range) {
        $times = explode(',', $range);
        $start = strtotime($times[0]);
        $end = strtotime($times[1]);
        
        $merged = false;
        foreach ($ranges as $key => $existing) {
            if ($start >= $existing->st && $end <= $existing->et) {
                // New range is completely contained in an existing range
                $merged = true;
                break;
            } elseif ($start <= $existing->st && $end >= $existing->et) {
                // New range completely contains an existing range
                $existing->st = $start;
                $existing->et = $end;
                $merged = true;
                break;
            } elseif ($start <= $existing->et && $end >= $existing->et) {
                // New range overlaps the end of an existing range
                $existing->et = $end;
                $merged = true;
                break;
            } elseif ($end >= $existing->st && $start <= $existing->st) {
                // New range overlaps the start of an existing range
                $existing->st = $start;
                $merged = true;
                break;
            }
        }
        
        if (!$merged) {
            $ranges[] = (object)['st' => $start, 'et' => $end];
        }
    }
    
    $result = [];
    foreach ($ranges as $range) {
        $result[] = (object)['st' => $range->st - strtotime('today'), 'et' => $range->et - strtotime('today')];
    }
    return($result);
}

function getActivityInfo($moduleId,$sessionId=null){
    
    global $DB;
    
    //First we get the modules id defined in the modules table, this can vary between moodle installations, so we make sure we hace the correct ids
    $attendanceModuleId = $DB->get_record('modules', array('name' =>'attendance'), '*', MUST_EXIST)->id;
    $bigBlueButtonModuleId = $DB->get_record('modules', array('name' =>'bigbluebuttonbn'), '*', MUST_EXIST)->id;
    
    //Get the module info
    $moduleInfo = $DB->get_record('course_modules', array('id' => $moduleId), '*', MUST_EXIST);
    
    if ($moduleInfo->module === $bigBlueButtonModuleId){
        
        $activityInfo = $DB->get_record('bigbluebuttonbn', array('id' => $moduleInfo->instance), '*', MUST_EXIST);
        $activityInitTS = $activityInfo->openingtime;
        $activityEndTS = $activityInfo->closingtime;
    }
    else if($moduleInfo->module === $attendanceModuleId){
        $sessionInfo = $DB->get_record('attendance_sessions', array('id' => $sessionId), '*', MUST_EXIST);
        $activityInitTS = $sessionInfo->sessdate;
        $activityEndTS = $sessionInfo->sessdate +  $sessionInfo->duration;
    }

    $activityInitDate = date('Y-m-d', $activityInitTS);
    $activityInitTime = date('H:i', $activityInitTS);
    $activityEndTime = date('H:i', $activityEndTS);
    
    $activityInfo= new stdClass();
    $activityInfo->activityInitDate = $activityInitDate;
    $activityInfo->activityInitTime = $activityInitTime;
    $activityInfo->activityEndTime = $activityEndTime;
    
    return $activityInfo;
}

function get_institutions($filters=null){
    global $DB; // Assuming $DB is a globally accessible database object
    
    // Retrieve records from the 'gmk_institution' table
    $institutions = $DB->get_records('gmk_institution',$filters);
    
    // Iterate through each institution
    foreach($institutions as $institution){
        // Count the number of contracts associated with the institution
        
        $institution->contracts = get_institution_contracts(['institutionid' => $institution->id]);
        $institution->numberOfContracts = count($institution->contracts);
        
        $institution->contractNames = [];
        foreach($institution->contracts as $contract){
            $institution->contractNames[] = ['id'=>$contract->id,'contractId'=>$contract->contractid];
        }
    }
    
    // Return the updated array of institution objects
    return array_values($institutions);
}

function get_institution_contracts($filters = null){
    global $DB; // Assuming $DB is a globally accessible database object
    
    // Retrieve records from the 'gmk_institution' table
    $institutionContracts = $DB->get_records('gmk_institution_contract',$filters);

    
    foreach($institutionContracts as $institutionContract){
         $institutionContract->formattedInitDate = date('Y-m-d',$institutionContract->initdate);
         $institutionContract->formattedExpectedEndDate = date('Y-m-d',$institutionContract->expectedenddate);
         $institutionContract->formattedBudget =number_format($institutionContract->budget, 0, '.', '.');
         $institutionContract->formattedBillingCondition =$institutionContract->billingcondition . '%';
         
         $institutionContract->users =get_contract_users($institutionContract->contractid,['contractid'=>$institutionContract->id]);
         $institutionContract->usersCount = 0;
         foreach($institutionContract->users as $institutionContractUser){
             $institutionContract->usersCount+=count($institutionContractUser->courses);
         }
    }
    // Return the updated array of institution objects
    return array_values($institutionContracts);
}

function get_contract_users($contractName,$filters=null){
    global $DB,$CFG; // Assuming $DB is a globally accessible database object
    $contractUserRecords = $DB->get_records('gmk_contract_user',$filters);
    $contractUsers = [];
    
    
    
    foreach($contractUserRecords as $contractUserRecord){
        $contractCourse = $DB->get_record('course',['id'=>$contractUserRecord->courseid]);
        $contractUserRecordInstance = clone $contractUserRecord;
        $contractUserRecordInstance->courseName = $contractCourse->fullname;
        $contractUserRecordInstance->contractName = $contractName;
        
        if(array_key_exists($contractUserRecord->userid,$contractUsers)){
            $contractUsers[$contractUserRecord->userid]->contractInstances[]= $contractUserRecordInstance;
            $contractUsers[$contractUserRecord->userid]->courses[]=$contractCourse->fullname;
            continue;
        }
        $contractUserRecord->contractInstances=[$contractUserRecordInstance];
        $userInfo = $DB->get_record('user',['id'=>$contractUserRecord->userid]);
        $contractUserRecord->phone = $userInfo->phone1?$userInfo->phone1:'Sin definir';
        $contractUserRecord->email = $userInfo->email;
        $contractUserRecord->fullname = $userInfo->firstname.' '.$userInfo->lastname;
        $contractUserRecord->avatar = get_user_picture_url($userInfo->id);
        $contractUserRecord->profileUrl = $CFG->wwwroot.'/user/profile.php?id='.$userInfo->id;
        $contractUserRecord->courses=[$contractCourse->fullname];
        
        $contractUsers[$contractUserRecord->userid]= $contractUserRecord;
    }
    return array_values($contractUsers);    
}

function get_contract_users_by_institution($institutionContracts){
    
    $contractUsers = [];
    foreach($institutionContracts as $institutionContract){
        foreach($institutionContract->users as $institutionContractUser){
            if(!array_key_exists($institutionContractUser->userid,$contractUsers)){
                $institutionContractUserInstance = new stdClass();
                $institutionContractUserInstance->userid = $institutionContractUser->userid;
                $institutionContractUserInstance->phone = $institutionContractUser->phone;
                $institutionContractUserInstance->email = $institutionContractUser->email;
                $institutionContractUserInstance->fullname = $institutionContractUser->fullname;
                $institutionContractUserInstance->avatar = $institutionContractUser->avatar;
                $institutionContractUserInstance->profileUrl = $institutionContractUser->profileUrl;
                $institutionContractUserInstance->courses = $institutionContractUser->courses;
                $institutionContractUserInstance->acquiredContracts = 1;
                $institutionContractUserInstance->contracts = [];
                foreach($institutionContractUser->contractInstances as $contractInstance){
                    $institutionContractUserInstance->contracts[]=['id'=>$contractInstance->id,'contractId'=>$contractInstance->contractName,'courseName'=>$contractInstance->courseName];
                }
                $contractUsers[$institutionContractUser->userid] = $institutionContractUserInstance;
                continue;
            }
            $contractUsers[$institutionContractUser->userid]->acquiredContracts += 1;
            foreach($institutionContractUser->contractInstances as $contractInstance){
                    $contractUsers[$institutionContractUser->userid]->contracts[]=['id'=>$contractInstance->id,'contractId'=>$contractInstance->contractName,'courseName'=>$contractInstance->courseName];
            }
            foreach($institutionContractUser->courses as $institutionContractUserCourse){
                !in_array($institutionContractUserCourse, $contractUsers[$institutionContractUser->userid]->courses)?
                    $contractUsers[$institutionContractUser->userid]->courses[]=$institutionContractUserCourse:
                    null;
            }
            
        }
    }
    
    foreach($contractUsers as $contractUser){
        $contractUser->coursesString = implode(', ',$contractUser->courses);
    }
    
    return $contractUsers;
}

function get_institution_contract_panel_info($institutionId, $institutionContractFilter = null, $institutionContractUserFilter = null){
    $institutionDetailedInfo = new stdClass();
    $institutionDetailedInfo->institutionInfo = get_institutions(['id'=>$institutionId])[0];
    $institutionDetailedInfo->contractUsers = get_contract_users_by_institution($institutionDetailedInfo->institutionInfo->contracts);
    $institutionDetailedInfo->institutionInfo->numberOfUsers = count($institutionDetailedInfo->contractUsers);
    
    if($institutionContractFilter){
        $filteredContracts = [];
        foreach($institutionDetailedInfo->institutionInfo->contracts as $institutionContract){
            if(stripos($institutionContract->contractid, $institutionContractFilter) !== false){
                $filteredContracts[]=$institutionContract;
            }
        }
        $institutionDetailedInfo->institutionInfo->contracts = $filteredContracts;
    }
    
    if($institutionContractUserFilter){
        $filteredContractUsers = [];
        foreach($institutionDetailedInfo->contractUsers as $institutionContractUser){
            if(stripos($institutionContractUser->fullname, $institutionContractUserFilter) !== false || stripos($institutionContractUser->email, $institutionContractUserFilter) !== false ){
                $filteredContractUsers[]=$institutionContractUser;
            }
        }
        $institutionDetailedInfo->contractUsers = $filteredContractUsers;
    }
    
    return $institutionDetailedInfo;
}

function check_enrol_link_validity($token){
    global $DB;
    
    $enrolLinkRecord = $DB->get_record('gmk_contract_enrol_link',['token'=>$token]);
    if(!$enrolLinkRecord){
        throw new Exception(get_string('invalidtoken', $plugin_name));
    }
    else if(time()>$enrolLinkRecord->expirationdate){
        
        throw new Exception(get_string('contractenrollinkexpirated', $plugin_name));
    }
    
    $enrolLinkRecord->courseName = $DB->get_record('course',['id'=>$enrolLinkRecord->courseid])->fullname;
    $enrolLinkRecord->contractId = $DB->get_record('gmk_institution_contract',['id'=>$enrolLinkRecord->contractid])->contractid;
    
    return $enrolLinkRecord;
}

function create_contract_user($user){
    global $DB,$USER;
    $enrolplugin = enrol_get_plugin('manual');
    $userContractRecordsResult = array();
    
    $courseIds = explode(',',$user['courseIds']);
    $contractUserRecords = new stdClass();
    $contractUserRecords->failure = array();
    $contractUserRecords->success = array();
    //loop for each course id and try to enrol the user; if so, add the record to the user contract table
    foreach($courseIds as $courseId){
        if(!$DB->get_record('course', ['id'=>$courseId])){
            $contractUserRecords->failure[]=['courseId'=>$courseId, 'message'=>'El curso con el id '.$courseId.' no existe'];
        }
        
        $instance = get_manual_enroll($courseId);
        if($DB->get_record('gmk_contract_user',['userid'=>$user['userId'], 'contractid'=>$user['contractId'], 'courseid'=>$courseId]) || !$instance){
            $contractUserRecords->failure[]=['courseId'=>$courseId, 'message'=>'El curso '.$DB->get_record('course',['id'=>$courseId])->fullname.' con id '.$courseId.' ya esta matriculado para este contrato y este usuario'];
            continue;
        }
        $enrolled = $enrolplugin->enrol_user($instance, $user['userId'], 5);
        
        $newContractUserRecord = new stdClass();
        $newContractUserRecord->userid = $user['userId'];
        $newContractUserRecord->contractid = $user['contractId'];
        $newContractUserRecord->courseid = $courseId;
        $newContractUserRecord->timecreated = time();
        $newContractUserRecord->timemodified = time();
        $newContractUserRecord->usermodified = $USER->id;
        
        $newContractUserRecord->id = $DB->insert_record('gmk_contract_user',$newContractUserRecord);
        $contractUserRecords->success[]=['courseId'=>$courseId, 'message'=>'ok'];
    }
    $userContractRecordsResult[$user['userId']]=['success'=>$contractUserRecords->success, 'failure'=>$contractUserRecords->failure];
    return $userContractRecordsResult;
}

function create_student_user($user){
    
    $user->mnethostid = 1;
    try{
        $newUserId = user_create_user($user);
        return $newUserId;
    } catch (Exception $e){
        return $e;
    }
}

function get_classrooms(){
    // return [['label'=>'classroom test, Cap: 40', 'value'=>5,'capacity'=>40]];
    // Set the request URL
    $url = 'https://isi-panama.odoo.com//api/classrooms';
    $curl = curl_init($url);
    // Set the options for the cURL request
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); 
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Authorization: Solutto123*'
    ));
    try{
        $response = curl_exec($curl);
    
        if (curl_errno($curl)) {
            throw new Exception(curl_error($curl)); 
        }
        
        // Close the cURL resource
        curl_close($curl);
        
        // Process the response
        if (!$response = json_decode($response)) {
            throw new Exception('Error al obtener lo salones de clases');
            
        }
        return array_map(function($classroom){
            return array(
                'label'=>$classroom->name.', Cap: '.$classroom->capacity,
                'value'=>$classroom->id,
                'capacity'=>$classroom->capacity
            );
        },$response->classrooms);
    }catch (Exception $e){
        return [];
    }
    // Execute the cURL request and get the response
    
}

function student_get_active_classes($userId,$courseId = null){
    global $DB;
    
    $userLearningPlans = $DB->get_records('local_learning_users', array('userid'=>$userId));
    $activeClasses = array();
    
    foreach($userLearningPlans as $userLearningPlan){
        $classFilter = ['learningplanid'=>$userLearningPlan->learningplanid];
        $courseId ? $classFilter['corecourseid']=$courseId :null; 
        $learningPlanActiveClasses = list_classes($classFilter);
        foreach($learningPlanActiveClasses as $learningPlanActiveClass){
            $activeSchedule = construct_active_schedule_object($learningPlanActiveClass,$userId);
            $activeSchedule->learningPlanId = $userLearningPlan->learningplanid;
            if(!array_key_exists($learningPlanActiveClass->course->id,$activeClasses)){
                $activeClasses[$learningPlanActiveClass->course->id]["id"] = $learningPlanActiveClass->course->id;
                $activeClasses[$learningPlanActiveClass->course->id]["name"] = $learningPlanActiveClass->course->fullname;
                $activeClasses[$learningPlanActiveClass->course->id]["schedules"] = [$activeSchedule->classId =>$activeSchedule ];
                $activeClasses[$learningPlanActiveClass->course->id]["selected"]?  null :$activeClasses[$learningPlanActiveClass->course->id]["selected"]=$activeSchedule->selected;
                continue;
            }
            $activeClasses[$learningPlanActiveClass->course->id]["schedules"][$activeSchedule->classId]= $activeSchedule;
            $activeClasses[$learningPlanActiveClass->course->id]["selected"]?  null :$activeClasses[$learningPlanActiveClass->course->id]["selected"]=$activeSchedule->selected;
        }
    }
    return $activeClasses;
}

function construct_active_schedule_object($class,$userId){
    
    global $DB;
    
    $learningPlanActiveSchedule = new stdClass();
    $learningPlanActiveSchedule->days = "";
    foreach($class->selectedDaysES as $index => $classDay){
        $learningPlanActiveSchedule->days .=  $classDay. ($index === count($class->selectedDaysES)-1? "":" - ") ;
    }
    $learningPlanActiveSchedule->start = $class->inithourformatted;
    $learningPlanActiveSchedule->end = $class->endhourformatted;
    $learningPlanActiveSchedule->instructor = $class->instructorName;
    $learningPlanActiveSchedule->type = $class->typelabel;
    $learningPlanActiveSchedule->groupId = $class->groupid;
    $learningPlanActiveSchedule->classId = $class->id;
    $learningPlanActiveSchedule->selected = !!$DB->get_record('gmk_class_pre_registration', ['classid'=>$class->id, 'userid'=>$userId]);;
    $learningPlanActiveSchedule->available = $class->available;
    $learningPlanActiveSchedule->preRegisteredStudents = $class->preRegisteredStudents;
    $learningPlanActiveSchedule->queuedStudents = $class->queuedStudents;
    $learningPlanActiveSchedule->classFull = $class->classFull;
    return $learningPlanActiveSchedule;
}

//Util functions------------------------------------------------------------------------------------------------------------------------------

function convert_time_range_to_timestamp_range($timeRange){
    $rangeInitHour = intval(substr($timeRange[0],0,2));
    $rangeInitMinutes = substr($timeRange[0],3,2);
    $rangeEndHour = intval(substr($timeRange[1],0,2));
    $rangeEndMinutes = substr($timeRange[1],3,2);
    $rangeInitTimeTS=$rangeInitHour * 3600 + $rangeInitMinutes * 60;
    $rangeEndTimeTS=$rangeEndHour * 3600 + $rangeEndMinutes * 60;

    return array("initTS"=>$rangeInitTimeTS, "endTS"=>$rangeEndTimeTS);
}

/**
 * Convert time ranges from input format to formatted time ranges.
 *
 * @param string $ranges_json The time ranges in JSON format.
 * @return array The time ranges as an array of formatted time ranges.
 */
function convert_timestamp_ranges_to_time_ranges($timestampRanges) {
    // Parse the input as a JSON array
    $timestampRanges = json_decode($timestampRanges, true);

    $timeRanges = array();
    foreach ($timestampRanges as $range) {
        // Convert start and end times to DateTime objects
        $start = new DateTime('midnight');
        $start->add(new DateInterval('PT' . $range['st'] . 'S'));

        $end = new DateTime('midnight');
        $end->add(new DateInterval('PT' . $range['et'] . 'S'));

        // Format the start and end times as strings
        $startStr = $start->format('H:i');
        $endStr = $end->format('H:i');

        // Add the formatted time range to the result array
        $timeRanges[] = "$startStr, $endStr";
    }
    // Return the result array
    return $timeRanges;
}

/**
 * Get the URL for the user picture.
 *
 * @param int $userid The ID of the user.
 * @param int $size The size of the picture (in pixels).
 * @return string The URL of the user picture.
 */
function get_user_picture_url($userid, $size = 100) {
    global $DB;
    try{
         $user = $DB->get_record('user', array('id' => $userid));
        if (!$user) {
            return '';
        }
        $context = \context_user::instance($user->id);
        $url = \moodle_url::make_pluginfile_url(
            $context->id, 'user', 'icon', null, null, null, $size
        );
        return $url->out();
    }catch (Exception $error){
        return null;
    }
   
}

function get_learning_plan_image($learningPlanId){
        
    $fs = get_file_storage();
    $context = context_system::instance();
    $files = $fs->get_area_files($context->id, 'local_sc_learningplans', 'learningplan_image', $learningPlanId);
    $urlimg = '';
    foreach ($files as $file) {
        $imageurl = \moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            $file->get_component(),
            $file->get_filearea(),
            $file->get_itemid(),
            $file->get_filepath(),
            $file->get_filename(),
            false
        );
        $urlimg = $imageurl->out(false);
    }
    return $urlimg;
}

function get_logged_user_token(){
    global $DB;
    $service = $DB->get_record('external_services', array('shortname' =>'moodle_mobile_app', 'enabled' => 1));
    return json_encode(external_generate_token_for_current_user($service)->token);
}

function containsSubstringIgnoringCaseAndTildes($needle, $haystack) {
    // Convert both strings to lowercase
    $needle = mb_strtolower($needle, 'UTF-8');
    $haystack = mb_strtolower($haystack, 'UTF-8');
    
    $transliterator = Transliterator::create('NFD;[:Nonspacing Mark:] Remove;NFC');

    // Remove diacritic marks (tildes) using iconv
    $needle = $transliterator->transliterate($needle);
    $haystack = $transliterator->transliterate($haystack);

    // Use strpos to check if $needle is in $haystack
    return strpos($haystack, $needle) !== false;
}

function cleanString($string) {
    $string = mb_strtolower($string, 'UTF-8');
    
    $transliterator = Transliterator::create('NFD;[:Nonspacing Mark:] Remove;NFC');

    $string = $transliterator->transliterate($string);

    return $string;
}

function updateTimeByMinutes($timeString, $minutesToAdd = 1) {
    list($hour, $minute) = explode(":", $timeString);

    // Convert hour and minute to integers
    $hour = intval($hour);
    $minute = intval($minute);

    // Check if we should add or subtract minutes
    if ($minutesToAdd >= 0) {
        $minute += $minutesToAdd;
    } else {
        $minute -= abs($minutesToAdd);
    }

    // Handle overflow and underflow
    while ($minute < 0) {
        $hour -= 1;
        $minute += 60;
    }

    while ($minute > 59) {
        $hour += 1;
        $minute -= 60;
    }

    // Format the updated time back into "HH:MM"
    $updatedTime = sprintf("%02d:%02d", $hour, $minute);

    return $updatedTime;
}

function check_if_time_range_is_contained($rangeArray,$inputRange){
    $rangeContained = false;
    foreach ($rangeArray as $key => $range) {
        if ($range->st <= $inputRange->st && $inputRange->et <= $range->et) {
            // input range is fully contained within the current range
            $rangeContained = true;
            break;
        } 
    }
    return $rangeContained;
}

function substract_timerange_from_teacher_disponibility($rangeArray, $inputRange) {
    foreach ($rangeArray as $key => $range) {
        if ($range->st <= $inputRange->st && $inputRange->et <= $range->et) {
            // input range is fully contained within the current range
            if ($range->st == $inputRange->st && $range->et == $inputRange->et) {
                // input range is identical to current range, so remove it completely
                unset($rangeArray[$key]);
            } else {
                // input range is within current range, so split it
                $newRange1 = new stdClass();
                $newRange1->st = $range->st;
                $newRange1->et = $inputRange->st;// - 1;

                $newRange2 = new stdClass();
                $newRange2->st = $inputRange->et;// + 1;
                $newRange2->et = $range->et;

                // remove the current range from the range array and add the two new ranges
                unset($rangeArray[$key]);

                // if the input range is not completely contained in the beginning of the current range
                if ($newRange1->et > $newRange1->st) {
                    $rangeArray[] = $newRange1;
                }

                // if the input range is not completely contained in the end of the current range
                if ($newRange2->et > $newRange2->st) {
                    $rangeArray[] = $newRange2;
                }
            }
            return $rangeArray;
        } 
    }
    return $rangeArray;
}

/**
 * Get instance of manual enrol
 *
 * @param int $courseid
 * @return stdClass instance
 */
function get_manual_enroll($courseid) {
    $instances = enrol_get_instances($courseid, true);
    foreach ($instances as $instance) {
        if ($instance->enrol = 'manual') {
            return $instance;
        }
    }
    return false;
}
