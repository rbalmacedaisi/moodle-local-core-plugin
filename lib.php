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
function grupomakro_core_create_class_activities($classInfo, $course,$type,$section,$groupId,$instructorUserId) {
    global $DB;
    
    $name = $classInfo->name;
    $classId = $classInfo->id;
    $initTime = $classInfo->inittime;
    $endTime = $classInfo->endtime;
    $classDays = $classInfo->classdays;
    
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
    if($type === 1 || $type ===2){ //If the type of activity is equal to 1, create the big blue button activities
        
        $activity = 'bigbluebuttonbn';
        //Start looping from the startDate to the endDate
        while($currentDateTS < $endDateTS){
            $day =  $classDaysList[date('l',$currentDateTS)];
            if($day==='1'){
                $activityEndTS = $currentDateTS+$classDurationInSeconds;
                
                list($module, $context, $cw, $cm, $data) = prepare_new_moduleinfo_data($course, $activity,$section);
                
                $bbbActivityDefinition                                  = new stdClass();
                $bbbActivityDefinition->type                            = "0";
                $bbbActivityDefinition->name                            = $name.'-'.$classId.'-'.$currentDateTS;
                $bbbActivityDefinition->introeditor                     = $data->introeditor;
                $bbbActivityDefinition->showdescription                 = "0";
                $bbbActivityDefinition->welcome                         = "Le damos la bienvenida a la sala de clases online de la clase ".$name ;
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
                $bbbActivityDefinition->openingtime                     = $currentDateTS;
                $bbbActivityDefinition->closingtime                     = $activityEndTS;
                $bbbActivityDefinition->visible                         = 1;
                $bbbActivityDefinition->visibleoncoursepage             = 1;
                $bbbActivityDefinition->cmidnumber                      = "";
                $bbbActivityDefinition->groupmode                       = "1";
                $bbbActivityDefinition->groupingid                      = $groupId;
                // $bbbActivityDefinition->availabilityconditionsjson      = '{"op":"&","c":[{"type":"date","d":">=","t":'.$currentDateTS.'},{"type":"date","d":"<","t":'.$activityEndTS.'}],"showc":[true,true]}';
                $bbbActivityDefinition->availabilityconditionsjson      = '{"op":"&","c":[],"showc":[]}';
                $bbbActivityDefinition->completionunlocked              = 1;
                $bbbActivityDefinition->completion                      = "1";
                $bbbActivityDefinition->completionexpected              = 0;
                $bbbActivityDefinition->tags                            = array();
                $bbbActivityDefinition->course                          = $course->id;
                $bbbActivityDefinition->coursemodule                    = 0;
                $bbbActivityDefinition->section                         = $section;
                $bbbActivityDefinition->module                          = 28;
                $bbbActivityDefinition->modulename                      = $activity;
                $bbbActivityDefinition->instance                        = 0;
                $bbbActivityDefinition->add                             = $activity;
                $bbbActivityDefinition->update                          = 0;
                $bbbActivityDefinition->return                          = 0;
                $bbbActivityDefinition->sr                              = 0;
                $bbbActivityDefinition->competencies                    = array();
                $bbbActivityDefinition->competency_rule                 = "0";
                $bbbActivityDefinition->submitbutton2                   = "Guardar cambios y regresar al curso";
                $bbbActivityDefinition->participants                   = '[{"selectiontype":"all","selectionid":"all","role":"viewer"},{"selectiontype":"user","selectionid":"'.$instructorUserId.'","role":"moderator"}]';
        
                $bbbActivityInfo = add_moduleinfo($bbbActivityDefinition, $course);
            }
            $currentDateTS+=$dayInSeconds;
        }
    }
    $currentDateTS = $startDateTS;
    if ($type === 0 || $type ===2){
        $activity = 'attendance';
        list($module, $context, $cw, $cm, $data) = prepare_new_moduleinfo_data($course, $activity,$section);
        
        $attendanceActivityDefinition                             = new stdClass();
        $attendanceActivityDefinition->name                       = $name.'-'.$classId;
        $attendanceActivityDefinition->introeditor                = $data->introeditor;
        $attendanceActivityDefinition->showdescription            = "0";
        $attendanceActivityDefinition->grade                      = 100;
        $attendanceActivityDefinition->grade_rescalegrades        = NULL;
        $attendanceActivityDefinition->gradecat                   = "38";
        $attendanceActivityDefinition->gradepass                  = NULL;
        $attendanceActivityDefinition->visible                    = 1;
        $attendanceActivityDefinition->visibleoncoursepage        = 1;
        $attendanceActivityDefinition->cmidnumber                 = "";
        $attendanceActivityDefinition->groupmode                  = "1";
        $attendanceActivityDefinition->groupingid                 = $groupId;
        $attendanceActivityDefinition->availabilityconditionsjson = '{"op":"&","c":[],"showc":[]}';
        $attendanceActivityDefinition->completionunlocked         = 1;
        $attendanceActivityDefinition->completion                 = "1";
        $attendanceActivityDefinition->completionexpected         = 0;
        $attendanceActivityDefinition->tags                       = array();
        $attendanceActivityDefinition->course                     = $course->id;
        $attendanceActivityDefinition->coursemodule               = 0;
        $attendanceActivityDefinition->section                    = $section;
        $attendanceActivityDefinition->module                     = 32;
        $attendanceActivityDefinition->modulename                 = $activity;
        $attendanceActivityDefinition->instance                   = 0;
        $attendanceActivityDefinition->add                        = $activity;
        $attendanceActivityDefinition->update                     = 0;
        $attendanceActivityDefinition->return                     = 0;
        $attendanceActivityDefinition->sr                         = 0;
        $attendanceActivityDefinition->competencies               = array();
        $attendanceActivityDefinition->competency_rule            = "0";
        $attendanceActivityDefinition->subnet                     = "";
        $attendanceActivityDefinition->submitbutton2              = "Guardar cambios y regresar al curso";
    
        $attendanceActivityInfo = add_moduleinfo($attendanceActivityDefinition, $course);
        
        $attendanceModuleId = $attendanceActivityInfo->coursemodule;
        $cm             = get_coursemodule_from_id('attendance', $attendanceModuleId, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $pageparams = new stdClass();
        $pageparams->action=1;
        $att= $DB->get_record('attendance', array('id' => $cm->instance), '*', MUST_EXIST);
        $att = new \mod_attendance_structure($att, $cm, $course, $context, $pageparams);
        
        $sessions = [];
        
        while($currentDateTS < $endDateTS){
            $day =  $classDaysList[date('l',$currentDateTS)];
            if($day==='1'){
                $attendanceSessionDefinition = new stdClass();
                $attendanceSessionDefinition->sessdate = $currentDateTS;
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
                
                array_push($sessions,$attendanceSessionDefinition);
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
        $teacherCoreId = $DB->get_record('local_learning_users',['id'=>$teacherId])->userid;
        $userInfo = $DB->get_record('user',['id'=>$teacherCoreId]);
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
        $class->coreCourseName = $DB->get_record('course',['id'=>$class->coreCourseId])->fullname;
        
        $class->startDate = '01/30/2023';
        
        
    }
    return $classes;
}

function grupomakro_core_list_instructors() {
    global $DB;
    $instructors = $DB->get_records('local_learning_users',["userroleid"=>4]);
    foreach($instructors as $instructor){
         $userInfo =$DB->get_record('user',['id'=> $instructor->userid]);
         $instructor->fullname = $userInfo->firstname.' '.$userInfo->lastname;
         $instructor->userid = $userInfo->id;
    }
    return $instructors;
}

function grupomakro_core_list_instructors_without_disponibility(){
    global $DB;
    $filteredInstructors = array();
    $instructors = grupomakro_core_list_instructors();
    foreach($instructors as $instructor){
        if (!$existing_record = $DB->get_record('gmk_teacher_disponibility', array("userid"=>$instructor->userid))) {
            $filteredInstructors[]=$instructor;
        }
    }
    return $filteredInstructors;
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

function getClassEvents(){
    global $DB;
    $fetchedClasses = array();
    $fetchedCourses = array();
    $eventDaysFiltered = [];
    
    $initDate = '2023-04-01';
    $endDate = '2023-05-30';
    
    $events = calendar_get_events(strtotime($initDate),strtotime($endDate),true,true,true,false,false);
    // print_object($events);
    // die;
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
        
        //Set the class information for the event
        $event->instructorName = $gmkClass->instructorName;
        $event->timeRange = $gmkClass->initHourFormatted.' - '. $gmkClass->endHourFormatted;
        $event->classDaysES = $gmkClass->selectedDaysES;
        $event->classDaysEN = $gmkClass->selectedDaysEN;
        $event->typeLabel = $gmkClass->typeLabel;
        $event->className = $gmkClass->name;
        $event->classId = $gmkClass->id;
        $event->instructorId = $gmkClass->instructorid;
        
        
        
        // The big blue button event doesn't come with the timeduration, so we calculate it and added to the event object
        // Asign the event color for both cases
        if($event->modulename === 'bigbluebuttonbn'){
            $event->timeduration = $DB->get_record('bigbluebuttonbn', ['id'=>$event->instance])->closingtime - $event->timestart;
            $event->color = '#2196f3';
            $event->activityUrl = 'https://grupomakro-dev.soluttolabs.com/mod/bigbluebuttonbn/view.php?id='.$moduleInfo->id;
        }else{
            $event->color = '#00bcd4';
            $event->activityUrl = 'https://grupomakro-dev.soluttolabs.com/mod/attendance/view.php?id='.$moduleInfo->id;
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
        array_push($eventDaysFiltered,$event);
    }
    
    return $eventDaysFiltered;
}