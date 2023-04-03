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
 * Local Lib - Common function for users
 *
 * @package     local_sc_learningplans
 * @copyright   2022 Solutto < G>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once($CFG->dirroot . '/course/modlib.php');

defined('MOODLE_INTERNAL') || die();

/**
 * Get the active learning plans for the class creation
 *
 * @return array
 */
function grupomakro_core_create_class_activities($classInfo, $course,$activity,$section) {
    global $DB;
    
    $name = $classInfo->name;
    $classId = $classInfo->id;
    $initTime = $classInfo->inittime;
    $endTime = $classInfo->endtime;
    $classDays = $classInfo->classdays;
        
    //Calculate the class session duration in seconds
    $initDateTime = DateTime::createFromFormat('H:i', $initTime);
    $endDateTime = DateTime::createFromFormat('H:i', $endTime);
    $classDurationInSeconds = strtotime($endDateTime->format('Y-m-d H:i:s'))-strtotime($initDateTime->format('Y-m-d H:i:s'));
    //
    
    //Get the period start date in seconds and the day name
    $startDate = DateTime::createFromFormat('Y-m-d H:i:s', '2023-04-01 '.$initTime.':00'); // January 1st of this year
    $startDateTS = strtotime($startDate->format('Y-m-d H:i:s'));
    
    //
    
    //Get the period end date timestamp(seconds)
    $endDate = DateTime::createFromFormat('Y-m-d H:i:s', '2023-04-14 '.$endTime.':00'); // April 30th of this year
    $endDateTS = strtotime($endDate->format('Y-m-d H:i:s'));
    //
    
    //Format the class days
    $classDaysNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    $classDaysList = array_combine($classDaysNames,explode('/', $classDays));
    
    //Define some needed constants
    $currentDateTS = $startDateTS;
    $dayInSeconds = 86400;
        
    if($activity === 'bigbluebuttonbn'){ //If the type of activity is equal to 1, create the big blue button activities
        
        
        //Start looping from the startDate to the endDate
        while($currentDateTS < $endDateTS){
            $day =  $classDaysList[date('l',$currentDateTS)];
            if($day==='1'){
                $activityEndTS = $currentDateTS+$classDurationInSeconds;
                
                list($module, $context, $cw, $cm, $data) = prepare_new_moduleinfo_data($course, $activity,$section);
                
                $bbbActivityDefinition = new stdClass();
                $bbbActivityDefinition->type = "0";
                $bbbActivityDefinition->name = $name.'-'.$classId.'-'.$currentDateTS;
                $bbbActivityDefinition->introeditor = $data->introeditor;
                $bbbActivityDefinition->showdescription = "0";
                $bbbActivityDefinition->welcome = "Le damos la bienvenida a la sala de clases online de la clase ".$name ;
                $bbbActivityDefinition->voicebridge = 0;
                $bbbActivityDefinition->userlimit = 0;
                $bbbActivityDefinition->record = 1;
                $bbbActivityDefinition->recordallfromstart = 0;
                $bbbActivityDefinition->recordhidebutton = 0;
                $bbbActivityDefinition->muteonstart = 0;
                $bbbActivityDefinition->recordings_deleted = 1;
                $bbbActivityDefinition->recordings_imported = 0;
                $bbbActivityDefinition->recordings_preview = 1;
                $bbbActivityDefinition->lockonjoin = 1;
                $bbbActivityDefinition->mform_isexpanded_id_permissions = 1;
                $bbbActivityDefinition->participants = '[{"selectiontype":"all","selectionid":"all","role":"viewer"}]';
                $bbbActivityDefinition->openingtime = $currentDateTS;
                $bbbActivityDefinition->closingtime = $activityEndTS;
                $bbbActivityDefinition->visible = 1;
                $bbbActivityDefinition->visibleoncoursepage = 1;
                $bbbActivityDefinition->cmidnumber = "";
                $bbbActivityDefinition->groupmode = "1";
                $bbbActivityDefinition->groupingid = "0";
                $bbbActivityDefinition->availabilityconditionsjson = '{"op":"&","c":[{"type":"date","d":">=","t":'.$currentDateTS.'},{"type":"date","d":"<","t":'.$activityEndTS.'}],"showc":[true,true]}';
                $bbbActivityDefinition->completionunlocked = 1;
                $bbbActivityDefinition->completion = "1";
                $bbbActivityDefinition->completionexpected = 0;
                $bbbActivityDefinition->tags = array();
                $bbbActivityDefinition->course = $course->id;
                $bbbActivityDefinition->coursemodule = 0;
                $bbbActivityDefinition->section = $section;
                $bbbActivityDefinition->module = 28;
                $bbbActivityDefinition->modulename = $activity;
                $bbbActivityDefinition->instance = 0;
                $bbbActivityDefinition->add = $activity;
                $bbbActivityDefinition->update = 0;
                $bbbActivityDefinition->return = 0;
                $bbbActivityDefinition->sr = 0;
                $bbbActivityDefinition->competencies = array();
                $bbbActivityDefinition->competency_rule = "0";
                $bbbActivityDefinition->submitbutton2 = "Guardar cambios y regresar al curso";
        
                $bbbActivityInfo = add_moduleinfo($bbbActivityDefinition, $course);
            }
            $currentDateTS+=$dayInSeconds;
        }
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


