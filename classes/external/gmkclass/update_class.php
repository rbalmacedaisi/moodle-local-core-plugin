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
 * Class definition for the local_grupomakro_update_class external function.
 *
 * @package    local_grupomakro_core
 * @copyright  2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\gmkclass;

use context_system;
use external_api;
use external_description;
use external_function_parameters;
use external_single_structure;
use external_value;
use stdClass;
use Exception;



defined('MOODLE_INTERNAL') || die();

require_once $CFG->libdir . '/externallib.php';
require_once($CFG->libdir . '/filelib.php');
require_once $CFG->dirroot. '/group/lib.php';
require_once $CFG->dirroot. '/local/grupomakro_core/locallib.php';

/**
 * External function 'local_grupomakro_update_class' implementation.
 *
 * @package     local_grupomakro_core
 * @category    external
 * @copyright   2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_class extends external_api {

    /**
     * Describes parameters of the {@see self::execute()} method.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [   
                'classId'=> new external_value(PARAM_TEXT, 'Id of the class.'),
                'name' => new external_value(PARAM_TEXT, 'Name of the class.'),
                'type' => new external_value(PARAM_INT, 'Type of the class (virtual(1) or inplace(0)).'),
                'learningPlanId' => new external_value(PARAM_INT, 'Id of the learning plan attached.'),
                'periodId' => new external_value(PARAM_INT, 'Id of the period when the class is going to be dictated defined in the leaerning pland and '),
                'courseId' => new external_value(PARAM_INT, 'Course id for the class'),
                'instructorId' => new external_value(PARAM_INT, 'Id of the class instructor'),
                'initTime' => new external_value(PARAM_TEXT, 'Init hour for the class'),
                'endTime' => new external_value(PARAM_TEXT, 'End hour of the class'),
                'classDays' => new external_value(PARAM_TEXT, 'The days when the class will have sessions, the format is l/m/m/j/v/s/d and every letter can contain 0 or 1 depending if the day is active')
            ]
        );
    }

    /**
     * TODO describe what the function actually does.
     *
     * @param int $userid
     * @return mixed TODO document
     */
    public static function execute(
        string $classId,
        string $name,
        int $type,
        int $learningPlanId,
        int $periodId,
        int $courseId,
        int $instructorId,
        string $initTime,
        string $endTime,
        string $classDays
        ) {

        try{
            // Global variables.
            global $DB,$USER;

            //Check the instructor availability
            $incomingClassSchedule = explode('/', $classDays);
            $incomingInitHour = intval(substr($initTime,0,2));
            $incomingInitMinutes = substr($initTime,3,2);
            $incomingEndHour = intval(substr($endTime,0,2));
            $incomingEndMinutes = substr($endTime,3,2);
            $incomingInitTimeTS=$incomingInitHour * 3600 + $incomingInitMinutes * 60;
            $incomingEndTimeTS=$incomingEndHour * 3600 + $incomingEndMinutes * 60;
            
            $availabilityRecords = json_decode(\local_grupomakro_core\external\disponibility\get_teachers_disponibility::execute($instructorId)['teacherAvailabilityRecords'])[0]->disponibilityRecords;
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
            
            
            for ($i = 0; $i < 7; $i++) {
                if($incomingClassSchedule[$i]==="1" && !property_exists($availabilityRecords,$weekdays[$i])){
                    $errorString = "El instructor no esta disponible el día ".$weekdays[$i];
                    $errors[]=$errorString;
                }
                else if ($incomingClassSchedule[$i]==="1" && property_exists($availabilityRecords,$weekdays[$i])){
                    $foundedAvailableRange = false;
                    foreach($availabilityRecords->{$weekdays[$i]} as $timeRange){
                        $splittedTimeRange = explode(', ',$timeRange);
                        $rangeInitHour = intval(substr($splittedTimeRange[0],0,2));
                        $rangeInitMinutes = substr($splittedTimeRange[0],3,2);
                        $rangeEndHour = intval(substr($splittedTimeRange[1],0,2));
                        $rangeEndMinutes = substr($splittedTimeRange[1],3,2);
                        $rangeInitTimeTS=$rangeInitHour * 3600 + $rangeInitMinutes * 60;
                        $rangeEndTimeTS=$rangeEndHour * 3600 + $rangeEndMinutes * 60;
                        
                        if($incomingInitTimeTS >=$rangeInitTimeTS && $incomingEndTimeTS <=$rangeEndTimeTS){
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
            
            $alreadyAsignedClasses= grupomakro_core_list_classes(['instructorid'=>$instructorId]);
            unset($alreadyAsignedClasses[$classId]);
            
            foreach($alreadyAsignedClasses as $alreadyAsignedClass){
                $alreadyAsignedClassSchedule = explode('/', $alreadyAsignedClass->classdays);
                $classInitTime = $alreadyAsignedClass->inittimeTS;
                $classEndTime = $alreadyAsignedClass->endtimeTS;
                
                for ($i = 0; $i < 7; $i++) {
                    if ($incomingClassSchedule[$i] == $alreadyAsignedClassSchedule[$i] && $incomingClassSchedule[$i] === '1') {
                        if(($incomingInitTimeTS >= $classInitTime && $incomingEndTimeTS<=$classEndTime) || ($incomingInitTimeTS < $classInitTime && $incomingEndTimeTS>$classInitTime) ||($incomingInitTimeTS < $classEndTime && $incomingEndTimeTS>$classEndTime)){
                            $errorString = "La clase ".$alreadyAsignedClass->name.": ".$weekdays[$i]." (".$alreadyAsignedClass->initHourFormatted." - ".$alreadyAsignedClass->endHourFormatted.") se cruza con el horario escogido"  ;
                             $errors[]=$errorString;
                        }
                    }
                }
            }
            
            if(count($errors)>0){
                throw new Exception(json_encode($errors));
            }
            
            
            // --------------------------------------------------------------------
        
            $classInfo = $DB->get_record('gmk_class', ['id'=>$classId]);
            
            //Get the section
            $section = $DB->get_record('course_sections', ['id' => $classInfo->coursesectionid]);
            
            //Get the current course
            $learningCourse= $DB->get_record('local_learning_courses',['id'=>$classInfo->courseid]);
            $coreCourseId = $learningCourse->courseid;
            $course = $DB->get_record('course', ['id' => $coreCourseId]);
            
            //Delete created resources before the update
            $sectionnum = $section->section;
            $sectioninfo = get_fast_modinfo($course)->get_section_info($sectionnum);
            course_delete_section($course, $sectioninfo, true, true);
            rebuild_course_cache($coreCourseId, true);
    
            //Get the real course Id from the courses table for the new course.
            $learningCourse= $DB->get_record('local_learning_courses',['id'=>$courseId]);
            $coreCourseId = $learningCourse->courseid;
            $course = $DB->get_record('course', ['id' => $coreCourseId]);
            
            //----------------------------------------Update Group-----------------------------------------
            
            //update the group with the class update info
            $groupInfo = new stdClass();
            $groupInfo->idnumber = $name.'-'.$classId;
            $groupInfo->id = $classInfo->groupid;
            $groupInfo->name = $name.'-'.$classId;
            $groupInfo->courseid = $coreCourseId;
            $groupInfo->description = 'Group for the '.$groupInfo->name.' class';
            
            $updateGroup =groups_update_group($groupInfo);
            
            //Remove the previous instructor and add the new one to the group
            $instructorAddedToGroup = groups_remove_member($classInfo->groupid,$classInfo->instructorid);
            $instructorAddedToGroup = groups_add_member($classInfo->groupid,$instructorId);
            
            //---------------------------------------Update Class------------------------------------------
            
            $classInfo->name           = $name;
            $classInfo->type           = $type;
            $classInfo->learningplanid = $learningPlanId;
            $classInfo->periodid       = $periodId;
            $classInfo->courseid       = $courseId;
            $classInfo->instructorid   = $instructorId;
            $classInfo->inittime       = $initTime;
            $classInfo->endtime        = $endTime;
            $classInfo->classdays      = $classDays;
            $classInfo->usermodified   = $USER->id;
            $classInfo->timemodified   = time();
            
            $classUpdated = $DB->update_record('gmk_class', $classInfo);
            
            //-------------------------------Delete section and recreate it-------------------------------
            
                    
            $classSection = grupomakro_core_create_class_section($classInfo,$coreCourseId,$classInfo->groupid );
            rebuild_course_cache($coreCourseId, true);
            
            $classInfo->coursesectionid      = $classSection->id;
            $classUpdated = $DB->update_record('gmk_class', $classInfo);
            
            $classInfo->course = $course;
            
            grupomakro_core_create_class_activities($classInfo);
        
            // Return the result.
            return ['status' => $classInfo->id, 'message' => 'ok'];
        }
        catch (Exception $e) {
            return ['status' => -1, 'message' => $e->getMessage()];
        }
    }


    /**
     * Describes the return value of the {@see self::execute()} method.
     *
     * @return external_description
     */
    public static function execute_returns(): external_description {
        return new external_single_structure(
            array(
                'status' => new external_value(PARAM_INT, 'True if the class is updated, -1 otherwise.'),
                'message' => new external_value(PARAM_TEXT, 'The error message or Ok.'),
            )
        );
    }
}