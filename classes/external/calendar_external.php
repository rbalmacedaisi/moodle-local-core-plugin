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
 * External calendar API
 *
 * @package    core_calendar
 * @category   external
 * @copyright  2012 Ankit Agarwal
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since Moodle 2.5
 */
 namespace local_grupomakro_core\external;
 
use stdClass;
use external_api;
use external_description;
use external_function_parameters;
use external_single_structure;
use external_value;


defined('MOODLE_INTERNAL') || die;

require_once("$CFG->libdir/externallib.php");
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

class calendar_external extends external_api {
    
    public static function execute_parameters(): external_function_parameters{
         return new external_function_parameters(
            [
                'userId' => new external_value(PARAM_INT, 'Id of the user',  VALUE_DEFAULT, null, NULL_ALLOWED),
            ]
        );
    }
    
    /**
     * Get data for the monthly calendar view.
     *
     * @param int $year The year to be shown
     * @return  array
     */
    public static function execute($userId = null) {
        global $DB, $USER, $PAGE;

        // Parameter validation.
        $params = self::validate_parameters(self::execute_parameters(), [
            'userId' => $userId,
        ]);
        
        $eventDaysFiltered = getClassEvents();
        
        if($userId){
            $learningPlanUserRoles =  $DB->get_records('local_learning_users', ['userid'=>$userId]);
            
            if (!$learningPlanUserRoles){
                return [
                'events' => 'invalidUserId','message'=>'invalidUserId'
                ];
            }
            
            $eventsFiltered = array();
            
            foreach($learningPlanUserRoles as $learningPlanUserRole){
                
                $userLearningPlanRole = $learningPlanUserRole->userroleid;
                $learningPlanUserId = $learningPlanUserRole->id;
                
                
                if ($userLearningPlanRole === '4'){

                    $eventsFilteredByTeacher=array();
                    foreach($eventDaysFiltered as $event){
                        if($event->instructorLPId ===$learningPlanUserId){
                            $event->role = 'teacher';
                            $eventsFilteredByTeacher[]=$event;
                        }
                    }
                    $eventsFiltered = array_merge($eventsFiltered, $eventsFilteredByTeacher);
                }
                elseif($userLearningPlanRole === '5'){
                    $asignedGroups = $DB->get_records('groups_members', array("userid"=>$userId));
                    $asignedClasses = array();
                    foreach($asignedGroups as $asignedGroup){
                        $groupClassId = $DB->get_record('gmk_class', array("groupid"=>$asignedGroup->groupid , "learningplanid"=>$learningPlanUserRole->learningplanid))->id;
                        $groupClassId? $asignedClasses[]=$groupClassId :null;
                    }

                    $eventsFilteredByClass=array();
                    foreach($eventDaysFiltered as $event){
                        if(in_array($event->classId,$asignedClasses)){
                            $event->role = 'student';
                            $eventsFilteredByClass[]=$event;
                        }
                    }
                    $eventsFiltered = array_merge($eventsFiltered, $eventsFilteredByClass);
                }
                
            }

            $eventDaysFiltered =$eventsFiltered;
        }
        
        return [
            'events' => json_encode(array_values($eventDaysFiltered)),'message'=>'ok'
        ];
    }

    /**
     * Describes the return value of the {@see self::execute()} method.
     *
     * @return external_description
     */
    public static function execute_returns(): external_description {
        return new external_single_structure(
            array(
                'events' => new external_value(PARAM_RAW, 'Events for the month'),
                'message' => new external_value(PARAM_TEXT, 'The error message or Ok.'),
            )
        );
    }
}