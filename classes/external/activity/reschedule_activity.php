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
 * Class definition for the local_grupomakro_reschedule_activity external function.
 *
 * @package    local_grupomakro_core
 * @copyright  2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\activity;

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

// require_once $CFG->libdir . '/externallib.php';
require_once($CFG->libdir . '/filelib.php');
// require_once $CFG->dirroot. '/group/externallib.php';
require_once $CFG->dirroot. '/local/grupomakro_core/locallib.php';

/**
 * External function 'local_grupomakro_reschedule_activity' implementation.
 *
 * @package     local_grupomakro_core
 * @category    external
 * @copyright   2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reschedule_activity extends external_api {

    /**
     * Describes parameters of the {@see self::execute()} method.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [   
                'classId'=> new external_value(PARAM_TEXT, 'Id of the class.'),
                'moduleId'=> new external_value(PARAM_TEXT, 'Id of the course module.'),
                'date' => new external_value(PARAM_TEXT, 'The date that will be assigned to the activity'),
                'initTime' => new external_value(PARAM_TEXT, 'The init time for the session'),
                'endTime' => new external_value(PARAM_TEXT, 'The end time for the session'),
                'sessionId'=> new external_value(PARAM_TEXT, 'Id of the attendance session.', VALUE_DEFAULT,null),
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
            string $moduleId,
            string $date,
            string $initTime,
            string $endTime,
            string $sessionId=null
        ) {
        // Global variables.
        global $DB;
        
        try{
            //First we get the modules id defined in the modules table, this can vary between moodle installations, so we make sure we hace the correct ids
            $attendanceModuleId = $DB->get_record('modules', array('name' =>'attendance'), '*', MUST_EXIST)->id;
            $bigBlueButtonModuleId = $DB->get_record('modules', array('name' =>'bigbluebuttonbn'), '*', MUST_EXIST)->id;
            
            
            //
            $moduleInfo =  $DB->get_record('course_modules', array('id' =>$moduleId), '*', MUST_EXIST);
            $moduleActivity =  $DB->get_record('modules', array('id' =>$moduleInfo->module), '*', MUST_EXIST)->name;
            $classInfo = grupomakro_core_list_classes(['id' =>$classId])[$classId];
            $classType = $classInfo->type;
            

            // Calculate the class session duration in seconds
            
            $initDateTime = DateTime::createFromFormat('H:i', $initTime);
            $endDateTime = DateTime::createFromFormat('H:i',$endTime);
            $classDurationInSeconds = strtotime($endDateTime->format('Y-m-d H:i:s'))-strtotime($initDateTime->format('Y-m-d H:i:s'));
            
            // Calculate the start time and the end time timestamps
            
            $initDateTime = $date . ' ' . $initTime;
            $endDateTime = $date . ' ' . $endTime;
            $initTimestamp = strtotime($initDateTime);
            $endTimestamp = strtotime($endDateTime);
    
            // If the class type is 0 (presencial), just replace the session on the attendance module
            if($classType === '0'){
                $attendanceSessionRescheduled = replaceAttendanceSession($moduleId,$sessionId,$initTimestamp,$classDurationInSeconds,$classInfo->groupid);
            }
            
            // If the class type is 1 (virtual), we need to replace the big blue button module
            else if($classType === '1'){
                course_delete_module($moduleId);
                $bigBluebuttonActivityRescheduled = createBigBlueButtonActivity($classInfo,$initTimestamp,$endTimestamp);
                
            }
            
            // If the class type is 2 (mixta), we need to reschedule both big blue button activity and attendance session
            else if($classType === '2'){
                if ($moduleActivity === 'bigbluebuttonbn'){
                    
                    $bigBlueButtonModuleId =$moduleInfo->id;
                    $bigBlueButtonActivityInfo =  $DB->get_record('bigbluebuttonbn',['id'=>$moduleInfo->instance]);
                    $bigBlueButtonActivityInitTS = $bigBlueButtonActivityInfo->openingtime;
                    
                    // If the reschedule was triggered from the big blue button activity, we must search the attendance session that begins with the same timestamp 
                    $classAttendanceModule = $DB->get_record('course_modules',['section'=>$classInfo->coursesectionid , 'module'=>$attendanceModuleId]);
                    $classAttendanceModuleId = $classAttendanceModule->id;
                    $classAttendanceSessionId = $DB->get_record('attendance_sessions',['attendanceid'=>$classAttendanceModule->instance, 'sessdate'=>$bigBlueButtonActivityInitTS])->id;
                    
                }
                else if ($moduleActivity === 'attendance'){
                    $classAttendanceModuleId = $moduleInfo->id;
                    $classAttendanceSessionId= $sessionId;
                    
                    // If the reschedule was triggered from the attendance session, we must search the big bluebutton activity that begins with the same timestamp 
                    $classAttendanceSessionInitTS = $DB->get_record('attendance_sessions',[ 'id'=>$classAttendanceSessionId])->sessdate;
                    $bigBlueButtonActivityInfo = null;
                    $bigBlueButtonActivityItems= $DB->get_records('bigbluebuttonbn',['openingtime'=>$classAttendanceSessionInitTS, 'name'=>$classInfo->name.'-'.$classInfo->id.'-'.$classAttendanceSessionInitTS]);
                    foreach($bigBlueButtonActivityItems as $bigBlueButtonActivityItem){
                        if (!$bigBlueButtonActivityInfo){
                            $bigBlueButtonActivityInfo = $bigBlueButtonActivityItem;
                        }
                        else if($bigBlueButtonActivityItem->timecreated >$bigBlueButtonActivityInfo->timecreated ){
                            $bigBlueButtonActivityInfo=$bigBlueButtonActivityItem;
                        }
                    }
                    $bigBlueButtonModuleId = $DB->get_record('course_modules',['instance'=>$bigBlueButtonActivityInfo->id , 'module'=>$bigBlueButtonModuleId])->id;
                }
                
                //With the ids required to do the reschedule setted, lets use the methods to reschedute them
                
                //For attendance
                $attendanceSessionRescheduled = replaceAttendanceSession($classAttendanceModuleId,$classAttendanceSessionId,$initTimestamp,$classDurationInSeconds,$classInfo->groupid);
                
                //For BBB
                course_delete_module($bigBlueButtonModuleId);
                $bigBluebuttonActivityRescheduled = createBigBlueButtonActivity($classInfo,$initTimestamp,$endTimestamp);
                
            }
            
            // Return the result.
            return ['status' => 1, 'message'=>'ok'];
            
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
                'status' => new external_value(PARAM_INT, 'The ID of the disponibility record or -1 if there was an error.'),
                'message' => new external_value(PARAM_TEXT, 'The error message or Ok.'),
            )
        );
    }
}
