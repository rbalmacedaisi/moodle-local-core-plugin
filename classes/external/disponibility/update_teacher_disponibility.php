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
 * Class definition for the local_grupomakro_update_teacher_disponibility external function.
 *
 * @package    local_grupomakro_core
 * @copyright  2023 Solutto Consulting <devs@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\disponibility;

use context_system;
use external_api;
use external_description;
use external_function_parameters;
use external_single_structure;
use external_multiple_structure;
use external_value;
use stdClass;
use Exception;
class MyException extends Exception {}

defined('MOODLE_INTERNAL') || die();

require_once $CFG->libdir . '/externallib.php';
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->dirroot.'/lib/moodlelib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->dirroot . '/group/externallib.php');
/**
 * External function 'local_grupomakro_update_teacher_disponibilities' implementation.
 *
 * @package     local_grupomakro_core
 * @category    external
 * @copyright   2023 Solutto Consulting <devs@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_teacher_disponibility extends external_api {

    /**
     * Describes parameters of the {@see self::execute()} method.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'instructorId' => new external_value(PARAM_INT, 'ID of the instructor', VALUE_REQUIRED),
                'newDisponibilityRecords' => new external_multiple_structure(
                    new external_single_structure(
                        [
                            'day' => new external_value(PARAM_TEXT, 'Day of the week', VALUE_REQUIRED),
                            'timeslots' => new external_multiple_structure(
                                new external_value(PARAM_TEXT, 'Available time slot', VALUE_REQUIRED),
                                'Array of available time slots'
                            )
                        ],
                        'Record for a single day of availability'
                    ),
                    'Array of availability records for each day of the week'
                ),
                'newInstructorId' => new external_value(PARAM_INT, 'ID of the new instructor that will take the old instructor disponibility and classes', VALUE_OPTIONAL),
            ],
            'Parameters for setting instructor availability'
        );
    }
    /**
     * Update instructor availability
     *
     * @param int $instructorId ID of the instructor
     * @param array $newDisponibilityRecords Array of availability records for each day of the week
     *
     * @return bool True if availability was set successfully, false otherwise
     *
     * @throws moodle_exception
     *
     * @external
     */
    public static function execute(
        $instructorId,$newDisponibilityRecords,$newInstructorId=null
        ) {
        // Global variables.
        global $DB;
        
        try {
            // Validate the parameters passed to the function.
            // $params = self::validate_parameters(self::execute_parameters(), [
            //     'instructorId' => $instructorId,
            //     'newDisponibilityRecords' => $newDisponibilityRecords
            // ]);
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
            
            $teacherDisponibilityId= $DB->get_record('gmk_teacher_disponibility',['userid'=>$instructorId])->id;
            
            $teacherDisponibility = new stdClass();
            $teacherDisponibility->id = $teacherDisponibilityId;
            $teacherDisponibility->userid = $instructorId;
            
            $disponibilityDays = array();
            foreach($newDisponibilityRecords as $newDisponibilityRecord){
                $day = strtolower(str_replace(['á', 'é', 'í', 'ó', 'ú', 'ñ'], ['a', 'e', 'i', 'o', 'u', 'n'], $newDisponibilityRecord['day']));
                $teacherDisponibility->{$dayENLabels[$day]}=calculate_disponibility_range($newDisponibilityRecord['timeslots']);
                $disponibilityDays[]=explode( '_',$dayENLabels[$day])[1];
            }
            
            $instructorAsignedClasses = grupomakro_core_list_classes(['instructorid'=>$instructorId]);
            
            $classLearningPlans = array();
            
            foreach($instructorAsignedClasses as $instructorAsignedClass){
                
                if(!in_array($instructorAsignedClass->learningplanid, $classLearningPlans)){
                    $classLearningPlans[]=$instructorAsignedClass->learningplanid;
                }

                // Check if a day that is already defined for a class is missing in the new disponibility
                foreach($instructorAsignedClass->selectedDaysEN as $classDay){
                     if(!in_array(strtolower($classDay),$disponibilityDays)){
                        $errorString = "El horario de la clase ".$instructorAsignedClass->coreCourseName." con id=".$instructorAsignedClass->id." (".$weekdays[$classDay]." ".$instructorAsignedClass->initHourFormatted.'-'.$instructorAsignedClass->endHourFormatted. ") ,no esta definido en la nueva disponibilidad; no se puede actualizar.";
                        throw new MyException($errorString);
                    }
                    
                    $foundedRange = false;
                    $dayDisponibilities = $teacherDisponibility->{'disp_'.strtolower($classDay)};
                    foreach($dayDisponibilities as $dayDisponibility){
                        if($instructorAsignedClass->inittimeTS >= $dayDisponibility->st &&  $instructorAsignedClass->endtimeTS  <= $dayDisponibility->et){
                            $foundedRange = true;
                            break;
                        }
                    }
                    if(!$foundedRange){
                        $errorString = "El horario de la clase ".$instructorAsignedClass->coreCourseName." con id=".$instructorAsignedClass->id." (".$weekdays[$classDay]." ".$instructorAsignedClass->initHourFormatted.'-'.$instructorAsignedClass->endHourFormatted. ") ,no esta definido en la nueva disponibilidad; no se puede actualizar.";
                        throw new MyException($errorString);
                    }
                }
                // -----------------------------------------------------------------------------------------
            }
            
            //Check if there is a change in the user availability owner
            if($newInstructorId && $newInstructorId !== $instructorId){
                if($DB->get_record('gmk_teacher_disponibility', array('userid'=>$newInstructorId))){
                    $errorString = 'El nuevo instructor ya tiene una disponibilidad definida.';
                    throw new MyException($errorString);
                }
                
                foreach($classLearningPlans as $classLearningPlan){
                    if(!$DB->get_record('local_learning_users', array('userid'=>$newInstructorId, 'learningplanid'=>$classLearningPlan, 'userrolename'=>'teacher'))){
                        $errorString = 'El nuevo instructor no esta en el plan de aprendizaje '.$DB->get_record('local_learning_plans', array('id'=>$classLearningPlan))->name.' ('.$classLearningPlan.')';
                        throw new MyException($errorString);
                    }
                }
                
                foreach($instructorAsignedClasses as $instructorAsignedClass ){
                    $classRecord = $DB->get_record('gmk_class',array('id'=>$instructorAsignedClass->id));
                    $classRecord->instructorid = $newInstructorId;
                    $updateClassInstructor = $DB->update_record('gmk_class',$classRecord);
                    
                    
                    //Update the group with the new instructor
                    $classGroupId = $instructorAsignedClass->groupid;
                    
                    $toRemoveMembers = ['members'=>['groupid'=> $classGroupId, 'userid'=>$instructorId]];
                    $toAddMembers = ['members'=>['groupid'=> $classGroupId, 'userid'=>$newInstructorId]];
                    
                    $instructorAddedToGroup = \core_group_external::delete_group_members($toRemoveMembers);
                    $instructorAddedToGroup = \core_group_external::add_group_members($toAddMembers);
                    // ---------------------------------------
                    
                    
                }
                $teacherDisponibility->userid=$newInstructorId;
                
            }
            // --------------------------------------------------------
            
            foreach($teacherDisponibility as $columnKey => $columnValue){
                if(strpos($columnKey, 'disp_')!== false){
                    $teacherDisponibility->{$columnKey} = json_encode($columnValue);
                }
            }
            
            foreach($dayENLabels as $dayLabel){
                !property_exists( $teacherDisponibility,$dayLabel)?$teacherDisponibility->{$dayLabel}="[]" :null;
            }

            $disponibilityRecordId = $DB->update_record('gmk_teacher_disponibility',$teacherDisponibility);
            
            // Return the result.
            return ['status' => $disponibilityRecordId, 'message' => 'ok'];
        } catch (MyException $e) {
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
                'status' => new external_value(PARAM_INT, '1 if the record was updated, -1 if there was an error.'),
                'message' => new external_value(PARAM_TEXT, 'The error message or Ok.'),
            )
        );
    }
}
