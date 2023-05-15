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

require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot. '/mod/attendance/locallib.php');
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot.'/calendar/lib.php');


defined('MOODLE_INTERNAL') || die();

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

/**
 * Create or updated (delete and recreate) the activities for the given class
 *
 * @return array
 */
function grupomakro_core_create_class_activities($class) {
    global $DB;
    
    $classType =  $class->type;
    $initTime = $class->inittime;
    $endTime = $class->endtime;
    $classDays = $class->classdays;
    
    $initDate = '2023-04-01';
    $endDate = '2023-05-30';
        
    //Calculate the class session duration in seconds
    $initDateTime = DateTime::createFromFormat('H:i', $initTime);
    $endDateTime = DateTime::createFromFormat('H:i', $endTime);
    $classDurationInSeconds = strtotime($endDateTime->format('Y-m-d H:i:s'))-strtotime($initDateTime->format('Y-m-d H:i:s'));
    //
    
    //Get the period start date in seconds and the day name
    $startDate = DateTime::createFromFormat('Y-m-d H:i:s', $initDate.' '.$initTime.':00'); // January 1st of this year
    $startDateTS = strtotime($startDate->format('Y-m-d H:i:s'));
    
    //
    
    //Get the period end date timestamp(seconds)
    $endDate = DateTime::createFromFormat('Y-m-d H:i:s', $endDate.' '.$endTime.':00'); // April 30th of this year
    $endDateTS = strtotime($endDate->format('Y-m-d H:i:s'));
    //
    
    //Format the class days
    $classDaysNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    $classDaysList = array_combine($classDaysNames,explode('/', $classDays));
    
    //Define some needed constants
    $currentDateTS = $startDateTS;
    $dayInSeconds = 86400;
    
    //If the type of activity is virtual or mixta, create the big blue button activities
    if($classType === 1 || $classType ===2){
    
        //Start looping from the startDate to the endDate
        while($currentDateTS < $endDateTS){
            $day =  $classDaysList[date('l',$currentDateTS)];
            if($day==='1'){
                $activityEndTS = $currentDateTS+$classDurationInSeconds;
                createBigBlueButtonActivity($class,$currentDateTS,$activityEndTS);
            }
            $currentDateTS+=$dayInSeconds;
        }
    }
    
    
    $currentDateTS = $startDateTS;
    
    //If the type of activity is presencial or mixta, create the attendance activity and sessions
    if ($classType === 0 || $classType ===2){
        
        $attendanceActivityInfo = createAttendanceActivity($class);
        
        $attendanceModuleId = $attendanceActivityInfo->coursemodule;
        $cm  = get_coursemodule_from_id('attendance', $attendanceModuleId, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $att= $DB->get_record('attendance', array('id' => $cm->instance), '*', MUST_EXIST);
        $att = new \mod_attendance_structure($att, $cm, $class->course, $context);
        
        $sessions = [];
        
        while($currentDateTS < $endDateTS){
            $day =  $classDaysList[date('l',$currentDateTS)];
            if($day==='1'){
                
                $attendanceSession = createAttendanceSessionObject($currentDateTS,$classDurationInSeconds,$class->groupid);
                array_push($sessions,$attendanceSession);
            }
            $currentDateTS+=$dayInSeconds;
            
        }
        $att->add_sessions($sessions);
    }
    
    return ['status'=>'created'];
}

function grupomakro_core_create_class_section($classInfo, $courseId, $groupId) {
    global $DB;
    
    //Calculate the next section value from the already defined sections in the course
    $courseSections = $DB->get_records('course_sections',['course'=>$courseId]);
    $sections= [];
    foreach ($courseSections as $section){
        array_push($sections,$section->section);
    }
    $maxSection= max($sections);
    
    //Create the course section object for the class.
    $classSection= new stdClass();
    $classSection->course           = $courseId;
    $classSection->section          = intval($maxSection) + 1;
    $classSection->name             = $classInfo->name.'-'.$classInfo->id;
    $classSection->visible          = 1;
    $classSection->timemodified     = time();
    $classSection->timecreated     = time();
    $classSection->summary          = '';
    $classSection->summaryformat    = 1;
    $classSection->sequence         = '';
    $classSection->availability     = '{"op":"&","c":[{"type":"group","id":'.$groupId.'}],"showc":[true]}';
    
    $createdSectionId = $DB->insert_record('course_sections', $classSection);
    $classSection->id = $createdSectionId;
    
    return $classSection;
}

function grupomakro_core_list_classes($filters) {
    global $DB;
    
    $classes = $DB->get_records('gmk_class',$filters);
    foreach($classes as $class){
            
        //get the class instructor name
        $teacherId = $class->instructorid;
        $class->instructorLPId = $DB->get_record('local_learning_users',['userid'=>$teacherId, 'learningplanid'=>$class->learningplanid])->id;
        $userInfo = $DB->get_record('user',['id'=>$teacherId]);
        $class->instructorName = $userInfo->firstname.' '. $userInfo->lastname;
        //
        
        //set the type Label
        
        $classLabels = ['1'=>'Virtual', '0'=>'Presencial', '2'=>'Mixta'];
        $class->typeLabel = $classLabels[$class->type];
        //
        
        //set the formatted hour in the format am/pm
        $initHour = intval(substr($class->inittime,0,2));
        $initMinutes = substr($class->inittime,3,2);
        $endHour = intval(substr($class->endtime,0,2));
        $endMinutes = substr($class->endtime,3,2);
        $class->initHourFormatted = $initHour>12? strval($initHour-12).':'.$initMinutes.' pm': ($initHour===12? $initHour.':'.$initMinutes.' pm' : $initHour.':'.$initMinutes.' am');
        $class->endHourFormatted = $endHour>12? strval($endHour-12).':'.$endMinutes.' pm': ($endHour===12? $endHour.':'.$endMinutes.' pm' : $endHour.':'.$endMinutes.' am');
        //
        
        //set the hour in seconds
        $class->inittimeTS=$initHour * 3600 + $initMinutes * 60;
        $class->endtimeTS=$endHour * 3600 + $endMinutes * 60;
        $class->classDuration = $class->endtimeTS - $class->inittimeTS;
        // 
        
        //set the list of choosen days
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
        $class->selectedDaysES =$selectedDaysES;
        $class->selectedDaysEN =$selectedDaysEN;
        //
        
        //set the company label and code
        $companies = ['Isi Panamá','Grupo Makro Colombia','Grupo Makro México'];
        $companyCodes = ['isi-pa','gk-col','gk-mex'];
        $class->companyName =$companies[$class->instance];
        $class->companyCode =$companyCodes[$class->instance];
        //
        
        //Set the real course id
        $class->coreCourseId = $DB->get_record('local_learning_courses',['id'=>$class->courseid])->courseid;
        $class->course = $DB->get_record('course',['id'=>$class->coreCourseId]);
        $class->coreCourseName = $class->course->fullname;
        $class->coursesectionid = $class->coursesectionid;
        
        $class->startDate = '01/30/2023';
        
        
    }
    return $classes;
}

function grupomakro_core_list_instructors() {
    global $DB;
    $instructors = $DB->get_records('local_learning_users',["userroleid"=>4]);
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
    $instructors = grupomakro_core_list_instructors();
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
    $instructors = grupomakro_core_list_instructors();
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

function checkRangeArray($rangeArray, $inputRange) {
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

function getClassEvents(){
    global $DB;
    $fetchedClasses = array();
    $fetchedCourses = array();
    $eventDaysFiltered = [];
    
    $initDate = '2023-04-01';
    $endDate = '2023-05-30';
    
    $events = calendar_get_events(strtotime($initDate),strtotime($endDate),true,true,true,false,false);
    
    $moduleIds = ["bigbluebuttonbn"=>$DB->get_record('modules',['name'=>'bigbluebuttonbn'])->id,"attendance"=>$DB->get_record('modules',['name'=>'attendance'])->id];
    foreach($events as $event){
        
        if(!array_key_exists($event->modulename, $moduleIds) || !$event->instance){
            continue;
        }
        
        $moduleInfo = $DB->get_record('course_modules', ['instance'=>$event->instance, 'module'=>$moduleIds[$event->modulename]]);
        
        $moduleSectionId = $moduleInfo->section;
        
        //Save the fetched classes to minimize db queries
        if(array_key_exists($moduleSectionId,$fetchedClasses)){
            $gmkClass = $fetchedClasses[$moduleSectionId];
        }else {
            $class = $DB->get_record('gmk_class', ['coursesectionid'=>$moduleSectionId]);
            if(!$class){continue;}
            $gmkClass = json_decode(\local_grupomakro_core\external\gmkclass\list_classes::execute($class->id)['classes'])[0];
            $fetchedClasses[$moduleSectionId] = $gmkClass;

        }
        // var_dump($event);
        // die;
        //Set the class information for the event
        $event->moduleId = $moduleInfo->id;
        $event->instructorName = $gmkClass->instructorName;
        $event->timeRange = $gmkClass->initHourFormatted.' - '. $gmkClass->endHourFormatted;
        $event->classDaysES = $gmkClass->selectedDaysES;
        $event->classDaysEN = $gmkClass->selectedDaysEN;
        $event->typeLabel = $gmkClass->typeLabel;
        $event->className = $gmkClass->name;
        $event->classId = $gmkClass->id;
        $event->instructorLPId = $DB->get_record('local_learning_users',['userid'=>$gmkClass->instructorid,'learningplanid'=>$gmkClass->learningplanid])->id;
        
        
        
        // The big blue button event doesn't come with the timeduration, so we calculate it and added to the event object
        // Asign the event color for both cases
        if($event->modulename === 'bigbluebuttonbn'){
            $event->timeduration = $DB->get_record('bigbluebuttonbn', ['id'=>$event->instance])->closingtime - $event->timestart;
            $event->color = '#2196f3';
            $event->activityUrl = 'https://grupomakro-dev.soluttolabs.com/mod/bigbluebuttonbn/view.php?id='.$moduleInfo->id;
        }else{
            $event->color = '#00bcd4';
            $event->activityUrl = 'https://grupomakro-dev.soluttolabs.com/mod/attendance/view.php?id='.$moduleInfo->id;
            $sessionId = $DB->get_record('attendance_sessions',array('attendanceid'=>$event->instance, 'caleventid'=>$event->id))->id;
            $event->sessionId = $sessionId;
        }
        //Set the initial date and the end date of the event
        $event->start = date('Y-m-d H:i:s',$event->timestart);
        $event->end = date('Y-m-d H:i:s',$event->timestart + $event->timeduration);
        
        //Get the coursename, save the fetched coursenames for minimize db queries
        if(array_key_exists($event->courseid,$fetchedCourses)){
            $event->coursename = $fetchedCourses[$event->courseid];
        }else {
            $event->coursename = $DB->get_record('course', ['id'=>$event->courseid])->fullname;
            $fetchedCourses[$event->courseid] = $event->coursename;
        }

        //push the filtered event to the arrays of events
        $eventDaysFiltered[]=$event;
    }
    
    return $eventDaysFiltered;
}

function createBigBlueButtonActivity($class,$initDateTS,$endDateTS){
    
    global $DB;
    $sectionNumber = $DB->get_record('course_sections',['id'=>$class->coursesectionid])->section;
    
    list($module, $context, $cw, $cm, $data) = prepare_new_moduleinfo_data($class->course,'bigbluebuttonbn',$sectionNumber);
                
    $bbbActivityDefinition                                  = new stdClass();
    $bbbActivityDefinition->type                            = "0";
    $bbbActivityDefinition->name                            = $class->name.'-'.$class->id.'-'.$initDateTS;
    $bbbActivityDefinition->introeditor                     = $data->introeditor;
    $bbbActivityDefinition->showdescription                 = "0";
    $bbbActivityDefinition->welcome                         = "Le damos la bienvenida a la sala de clases online de la clase ".$class->name ;
    $bbbActivityDefinition->voicebridge                     = 0;
    $bbbActivityDefinition->userlimit                       = 0;
    $bbbActivityDefinition->record                          = 1;
    $bbbActivityDefinition->recordallfromstart              = 0;
    $bbbActivityDefinition->recordhidebutton                = 0;
    $bbbActivityDefinition->muteonstart                     = 0;
    $bbbActivityDefinition->recordings_deleted              = 1;
    $bbbActivityDefinition->recordings_imported             = 0;
    $bbbActivityDefinition->recordings_preview              = 1;
    $bbbActivityDefinition->lockonjoin                      = 1;
    $bbbActivityDefinition->mform_isexpanded_id_permissions = 1;
    $bbbActivityDefinition->participants                    = '[{"selectiontype":"all","selectionid":"all","role":"viewer"}]';
    $bbbActivityDefinition->openingtime                     = $initDateTS;
    $bbbActivityDefinition->closingtime                     = $endDateTS;
    $bbbActivityDefinition->visible                         = 1;
    $bbbActivityDefinition->visibleoncoursepage             = 1;
    $bbbActivityDefinition->cmidnumber                      = "";
    $bbbActivityDefinition->groupmode                       = "0";
    $bbbActivityDefinition->groupingid                      = NULL;
    // $bbbActivityDefinition->availabilityconditionsjson      = '{"op":"&","c":[{"type":"date","d":">=","t":'.$currentDateTS.'},{"type":"date","d":"<","t":'.$activityEndTS.'}],"showc":[true,true]}';
    $bbbActivityDefinition->availabilityconditionsjson      = '{"op":"&","c":[],"showc":[]}';
    $bbbActivityDefinition->completionunlocked              = 1;
    $bbbActivityDefinition->completion                      = "1";
    $bbbActivityDefinition->completionexpected              = 0;
    $bbbActivityDefinition->tags                            = array();
    $bbbActivityDefinition->course                          = $class->course->id;
    $bbbActivityDefinition->coursemodule                    = 0;
    $bbbActivityDefinition->section                         = $sectionNumber;
    $bbbActivityDefinition->module                          = 28;
    $bbbActivityDefinition->modulename                      = 'bigbluebuttonbn';
    $bbbActivityDefinition->instance                        = 0;
    $bbbActivityDefinition->add                             = 'bigbluebuttonbn';
    $bbbActivityDefinition->update                          = 0;
    $bbbActivityDefinition->return                          = 0;
    $bbbActivityDefinition->sr                              = 0;
    $bbbActivityDefinition->competencies                    = array();
    $bbbActivityDefinition->competency_rule                 = "0";
    $bbbActivityDefinition->submitbutton2                   = "Guardar cambios y regresar al curso";
    $bbbActivityDefinition->participants                   = '[{"selectiontype":"all","selectionid":"all","role":"viewer"},{"selectiontype":"user","selectionid":"'.$class->instructorId.'","role":"moderator"}]';

    $bbbActivityInfo = add_moduleinfo($bbbActivityDefinition, $class->course);
    
    return $bbbActivityInfo;
}

function createAttendanceActivity($class){
    
    global $DB;
    $sectionNumber = $DB->get_record('course_sections',['id'=>$class->coursesectionid])->section;
    
    list($module, $context, $cw, $cm, $data) = prepare_new_moduleinfo_data($class->course, 'attendance',$sectionNumber);
        
    $attendanceActivityDefinition                             = new stdClass();
    $attendanceActivityDefinition->name                       = $class->name.'-'.$class->id;
    $attendanceActivityDefinition->introeditor                = $data->introeditor;
    $attendanceActivityDefinition->showdescription            = "0";
    $attendanceActivityDefinition->grade                      = 100;
    $attendanceActivityDefinition->grade_rescalegrades        = NULL;
    $attendanceActivityDefinition->gradecat                   = "38";
    $attendanceActivityDefinition->gradepass                  = NULL;
    $attendanceActivityDefinition->visible                    = 1;
    $attendanceActivityDefinition->visibleoncoursepage        = 1;
    $attendanceActivityDefinition->cmidnumber                 = "";
    $attendanceActivityDefinition->groupmode                  = "0";
    $attendanceActivityDefinition->groupingid                 = NULL;
    $attendanceActivityDefinition->availabilityconditionsjson = '{"op":"&","c":[],"showc":[]}';
    $attendanceActivityDefinition->completionunlocked         = 1;
    $attendanceActivityDefinition->completion                 = "1";
    $attendanceActivityDefinition->completionexpected         = 0;
    $attendanceActivityDefinition->tags                       = array();
    $attendanceActivityDefinition->course                     = $class->course->id;
    $attendanceActivityDefinition->coursemodule               = 0;
    $attendanceActivityDefinition->section                    = $sectionNumber;
    $attendanceActivityDefinition->module                     = 32;
    $attendanceActivityDefinition->modulename                 = 'attendance';
    $attendanceActivityDefinition->instance                   = 0;
    $attendanceActivityDefinition->add                        = 'attendance';
    $attendanceActivityDefinition->update                     = 0;
    $attendanceActivityDefinition->return                     = 0;
    $attendanceActivityDefinition->sr                         = 0;
    $attendanceActivityDefinition->competencies               = array();
    $attendanceActivityDefinition->competency_rule            = "0";
    $attendanceActivityDefinition->subnet                     = "";
    $attendanceActivityDefinition->submitbutton2              = "Guardar cambios y regresar al curso";

    $attendanceActivityInfo = add_moduleinfo($attendanceActivityDefinition, $class->course);
    return $attendanceActivityInfo;
        
}

function replaceAttendanceSession($moduleId,$sessionIdToBeRemoved,$sessionDate,$classDurationInSeconds,$groupId){
    
    global $DB;
    
    $cm = get_coursemodule_from_id('attendance', $moduleId, 0, false, MUST_EXIST);
    $course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
    $att = $DB->get_record('attendance', array('id' => $cm->instance), '*', MUST_EXIST);
    $context = \context_module::instance($cm->id);
    $att = new \mod_attendance_structure($att, $cm, $course, $context, $pageparams);
    
    //Remove the attendance session that will be reschedule
    
    $att->delete_sessions(array($sessionIdToBeRemoved));
    
    //Create the new attendance session with the new values

    $attendanceSession = createAttendanceSessionObject($sessionDate,$classDurationInSeconds,$groupId);
    
    $att->add_sessions(array($attendanceSession)); 
    
    return true;
}

function createAttendanceSessionObject($sessionDate,$classDurationInSeconds,$groupId){
    
    $attendanceSessionDefinition = new stdClass();
    $attendanceSessionDefinition->sessdate = $sessionDate;
    $attendanceSessionDefinition->duration = $classDurationInSeconds;
    $attendanceSessionDefinition->descriptionitemid = 0;
    $attendanceSessionDefinition->description = "";
    $attendanceSessionDefinition->descriptionformat = "1";
    $attendanceSessionDefinition->calendarevent = 1;
    $attendanceSessionDefinition->timemodified = time();
    $attendanceSessionDefinition->absenteereport = "1";
    $attendanceSessionDefinition->studentpassword = "";
    $attendanceSessionDefinition->includeqrcode = 0;
    $attendanceSessionDefinition->rotateqrcode = 0;
    $attendanceSessionDefinition->rotateqrcodesecret = "";
    $attendanceSessionDefinition->automark = 0;
    $attendanceSessionDefinition->automarkcmid = 0;
    $attendanceSessionDefinition->automarkcompleted = 0;
    $attendanceSessionDefinition->subnet = "";
    $attendanceSessionDefinition->preventsharedip = 0;
    $attendanceSessionDefinition->preventsharediptime = "";
    $attendanceSessionDefinition->statusset = 0;
    $attendanceSessionDefinition->groupid = $groupId;
    
    return $attendanceSessionDefinition;
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

/**
 * Convert time ranges from input format to formatted time ranges.
 *
 * @param string $ranges_json The time ranges in JSON format.
 * @return array The time ranges as an array of formatted time ranges.
 */
function convert_time_ranges($rangesJson) {
    // Parse the input as a JSON array
    $data = json_decode($rangesJson, true);

    $formattedRanges = array();
    foreach ($data as $range) {
        // Convert start and end times to DateTime objects
        $start = new DateTime('midnight');
        $start->add(new DateInterval('PT' . $range['st'] . 'S'));

        $end = new DateTime('midnight');
        $end->add(new DateInterval('PT' . $range['et'] . 'S'));

        // Format the start and end times as strings
        $startStr = $start->format('H:i');
        $endStr = $end->format('H:i');

        // Add the formatted time range to the result array
        $formattedRanges[] = "$startStr, $endStr";
    }

    // Return the result array
    return $formattedRanges;
}

/**
 * Get the URL for the user picture.
 *
 * @param int $userid The ID of the user.
 * @param int $size The size of the picture (in pixels).
 * @return string The URL of the user picture.
 */
function my_get_user_picture_url($userid, $size = 100) {
    global $DB;
    $user = $DB->get_record('user', array('id' => $userid));
    if (!$user) {
        return '';
    }
    $context = \context_user::instance($user->id);
    $url = \moodle_url::make_pluginfile_url(
        $context->id, 'user', 'icon', null, null, null, $size
    );
    return $url->out();
}