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
 * Class definition for the local_grupomakro_create_class external function.
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
use DateTime;
use Exception;



defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->dirroot . '/group/externallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

/**
 * External function 'local_grupomakro_create_class' implementation.
 *
 * @package     local_grupomakro_core
 * @category    external
 * @copyright   2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_class extends external_api {

    /**
     * Describes parameters of the {@see self::execute()} method.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'name' => new external_value(PARAM_TEXT, 'Name of the class.'),
                'type' => new external_value(PARAM_INT, 'Type of the class (virtual(1) or inplace(0)).'),
                'instance' => new external_value(PARAM_INT, 'Id of the instance.'),
                'learningPlanId' => new external_value(PARAM_INT, 'Id of the learning plan attached.'),
                'periodId' => new external_value(PARAM_INT, 'Id of the period when the class is going to be dictated defined in the leaerning pland and '),
                'courseId' => new external_value(PARAM_INT, 'Course id for the class'),
                'instructorId' => new external_value(PARAM_INT, 'Id of the class instructor'),
                'initTime' => new external_value(PARAM_TEXT, 'Init hour for the class'),
                'endTime' => new external_value(PARAM_TEXT, 'End hour of the class'),
                'classDays' => new external_value(PARAM_TEXT, 'The days when tha class will be dictated, the format is l/m/m/j/v/s/d and every letter can contain 0 or 1 depending if the day is active')
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
        string $name,
        int $type,
        int $instance,
        int $learningPlanId,
        int $periodId,
        int $courseId,
        int $instructorId,
        string $initTime,
        string $endTime,
        string $classDays
        ) {
        
        // Validate the parameters passed to the function.
        $params = self::validate_parameters(self::execute_parameters(), [
            'name' => $name,
            'type' =>$type,
            'instance'=>$instance,
            'learningPlanId'=>$learningPlanId,
            'periodId' =>$periodId,
            'courseId' =>$courseId,
            'instructorId' =>$instructorId,
            'initTime'=>$initTime,
            'endTime'=>$endTime,
            'classDays'=>$classDays
        ]);
        
        // Global variables.
        global $DB, $USER;
        
        
        try{
            
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
            
            for ($i = 0; $i < 7; $i++) {
                if($incomingClassSchedule[$i]==="1" && !property_exists($availabilityRecords,$weekdays[$i])){
                    $errorString = "El instructor no esta disponible el día ".$weekdays[$i];
                    throw new Exception($errorString);
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
                        throw new Exception($errorString);
                    }
                }
            }
            
            $alreadyAsignedClasses= grupomakro_core_list_classes(['instructorid'=>$instructorId]);

            foreach($alreadyAsignedClasses as $alreadyAsignedClass){
                $alreadyAsignedClassSchedule = explode('/', $alreadyAsignedClass->classdays);
                $classInitTime = $alreadyAsignedClass->inittimeTS;
                $classEndTime = $alreadyAsignedClass->endtimeTS;
                
                for ($i = 0; $i < 7; $i++) {
                    if ($incomingClassSchedule[$i] == $alreadyAsignedClassSchedule[$i] && $incomingClassSchedule[$i] === '1') {
                        if(($incomingInitTimeTS >= $classInitTime && $incomingEndTimeTS<=$classEndTime) || ($incomingInitTimeTS < $classInitTime && $incomingEndTimeTS>$classInitTime) ||($incomingInitTimeTS < $classEndTime && $incomingEndTimeTS>$classEndTime)){
                            $errorString = "La clase ".$alreadyAsignedClass->name.": ".$weekdays[$i]." (".$alreadyAsignedClass->initHourFormatted." - ".$alreadyAsignedClass->endHourFormatted.") se cruza con el horario escogido"  ;
                            throw new Exception($errorString);
                        }
                    }
                }
            }
            
            // --------------------------------------------------------------------
            
            
            //Get the real course Id from the courses table.
            $learningCourse= $DB->get_record('local_learning_courses',['id'=>$courseId]);
            $coreCourseId = $learningCourse->courseid;
            $course= $DB->get_record('course',['id'=>$coreCourseId]);
            
            //----------------------------------------------------Creation of class----------------------------------------------------------
            
            //Create the class object and insert into DB
            $newClass = new stdClass();
            $newClass->name           = $name;
            $newClass->type           = $type;
            $newClass->instance       = $instance;
            $newClass->learningplanid = $learningPlanId;
            $newClass->periodid       = $periodId;
            $newClass->courseid       = $courseId;
            $newClass->instructorid   = $instructorId;
            $newClass->inittime       = $initTime;
            $newClass->endtime        = $endTime;
            $newClass->classdays      = $classDays;
            $newClass->usermodified   = $USER->id;
            $newClass->timecreated    = time();
            $newClass->timemodified   = time();
            
            $newClass->id = $DB->insert_record('gmk_class', $newClass);
            
            //----------------------------------------------------Creation of group----------------------------------------------------------
            
            //Create the group oject and create the group using the webservice.
            $group = [['courseid'=>$coreCourseId,'name'=>$name.'-'.$newClass->id,'idnumber'=>'','description'=>'','descriptionformat'=>'1']];
            $newClass->groupid = \core_group_external::create_groups($group)[0]['id'];
            
            $members = ['members'=>['groupid'=> $newClass->groupid, 'userid'=>$instructorId]];
            $instructorAddedToGroup = \core_group_external::add_group_members($members);
            
            //----------------------------------------------------Creation of course section (topic)-----------------------------------------
            
            $newClass->coursesectionid = grupomakro_core_create_class_section($newClass,$coreCourseId, $newClass->groupid)->id;
            rebuild_course_cache($coreCourseId, true);
    
            //----------------------------------------------------Update class with group and section-------------------------
            
            //Update the class with the new group id and the new section id.
    
            $updatedClass = $DB->update_record('gmk_class', $newClass);
            
            //-----------------------------------------------------Creation of the activities---------------------------------
            
            $newClass->course = $course;
            
            grupomakro_core_create_class_activities($newClass);
            // 
    
            // Return the result.
            return ['status' => $newClass->id, 'message' => 'ok'];
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
                'status' => new external_value(PARAM_INT, 'The ID of the new class or -1 if there was an error.'),
                'message' => new external_value(PARAM_TEXT, 'The error message or Ok.'),
            )
        );
    }
}