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
 * Class definition for the local_grupomakro_check_reschedule_conflicts external function.
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
use Exception;

defined('MOODLE_INTERNAL') || die();

require_once $CFG->libdir . '/externallib.php';
require_once($CFG->libdir . '/filelib.php');
require_once $CFG->dirroot. '/group/externallib.php';
require_once $CFG->dirroot. '/local/grupomakro_core/locallib.php';

/**
 * External function 'local_grupomakro_check_reschedule_conflicts' implementation.
 *
 * @package     local_grupomakro_core
 * @category    external
 * @copyright   2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class check_reschedule_conflicts extends external_api {

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
                'endTime' => new external_value(PARAM_TEXT, 'The end time for the session', VALUE_DEFAULT,null),
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
        string $endTime=null,
        string $sessionId=null
        ) {

        
        // Global variables.
        global $DB;
        
        try{

            $classInfo = list_classes(['id'=>$classId])[$classId];

            //Check the instructor availability
            $instructorUserId = $classInfo->instructorid;

            // Get the day of the week in English from the Unix timestamp
            $incomingWeekDay= date('l', strtotime($date));
            
            $incomingInitHour = intval(substr($initTime,0,2));
            $incomingInitMinutes = substr($initTime,3,2);
            if(!$endTime || $endTime === 'null'){
                $endTime = date("H:i", strtotime($initTime) + $classInfo->classduration);
            }
            $incomingEndHour = intval(substr($endTime,0,2));
            $incomingEndMinutes = substr($endTime,3,2);
            $incomingInitTimeTS=$incomingInitHour * 3600 + $incomingInitMinutes * 60;
            $incomingEndTimeTS=$incomingEndHour * 3600 + $incomingEndMinutes * 60;
            
            $weekdays = array(
              'Monday' => 'Lunes',
              'Tuesday'=> 'Martes',
              'Wednesday' => 'Miércoles',
              'Thursday' => 'Jueves',
              'Friday' => 'Viernes',
              'Saturday' => 'Sábado',
              'Sunday' => 'Domingo'
            );
            
            $incomingWeekDay = $weekdays[$incomingWeekDay];
            $instructorEvents = json_decode(\local_grupomakro_core\external\disponibility\get_teachers_disponibility_calendar::execute($instructorUserId)["disponibility"])[0];
            $incomingDayAvailableTime = $instructorEvents->daysFree->{$date};
            $foundedAvailableRange = false;
            for ($i = 0; $i < count($incomingDayAvailableTime); $i+=2) {
                $rangeInitHour = intval(substr($incomingDayAvailableTime[$i],0,2));
                $rangeInitMinutes = substr($incomingDayAvailableTime[$i],3,2);
                $rangeEndHour = intval(substr($incomingDayAvailableTime[$i+1],0,2));
                $rangeEndMinutes = substr($incomingDayAvailableTime[$i+1],3,2);
                $rangeInitTimeTS=$rangeInitHour * 3600 + $rangeInitMinutes * 60;
                $rangeEndTimeTS=$rangeEndHour * 3600 + $rangeEndMinutes * 60;
                if($incomingInitTimeTS >=$rangeInitTimeTS && $incomingEndTimeTS <=$rangeEndTimeTS){
                    $foundedAvailableRange = true;
                    break;
                }
                
            }
            if(!$foundedAvailableRange){
                $errorString = "El instructor no esta disponible el día ".$incomingWeekDay." en el horário: ".$initTime." - ".$endTime.'. Esta seguro de que quiere continuar?';
                return ['status' => 1, 'message'=>$errorString];
            }
            // --------------------------------------------------------------------
            
            //Check the group members and count how many students are in conflict with the new date and time
            $groupMembersWithConflicts = array();
            
            $groupMembers = $DB->get_records('groups_members',array('groupid'=>$classInfo->groupid));
            foreach ($groupMembers as $key => $groupMember) {
                if ($groupMember->userid == $instructorUserId) {
                    unset($groupMembers[$key]);
                    continue;
                }
                $studentEvents = json_decode(\local_grupomakro_core\external\calendar_external::execute($groupMember->userid)["events"]);
                foreach($studentEvents as $studentEvent){
                    $eventStart = explode(' ',$studentEvent->start);
                    if($eventStart[0] ===$date ){
                        $eventInitHour= intval(substr($eventStart[1],0,2));
                        $eventEndHour = intval(substr($studentEvent->end,11,2));
                        $eventInitMinutes= intval(substr($eventStart[1],3,2));
                        $eventEndMinutes = intval(substr($studentEvent->end,13,2));
                        $eventInitTimeTS=$eventInitHour * 3600 + $eventInitMinutes * 60;
                        $eventEndTimeTS=$eventEndHour * 3600 + $eventEndMinutes * 60;
                        if(($incomingInitTimeTS >= $eventInitTimeTS && $incomingEndTimeTS<=$eventEndTimeTS) || ($incomingInitTimeTS < $eventInitTimeTS && $incomingEndTimeTS>$eventInitTimeTS) ||($incomingInitTimeTS < $eventEndTimeTS && $incomingEndTimeTS>$eventEndTimeTS)){
                            $groupMembersWithConflicts[]=$groupMember;
                            break;
                        }
                    }
                }
            }
            // --------------------------------------------------------------------
            
            // Return the result.
            if(count($groupMembersWithConflicts)!==0){
                $errorString=count($groupMembersWithConflicts).' de los ('.count($groupMembers).') miembros del grupo presentan conflictos con el nuevo horario; no se puede reprogramar.';
                return ['status' => 1, 'message'=>$errorString];
                
            }
            return ['status' => 1, 'message'=>'La reprogramación no presenta ningun conflicto, puedes continuar'];
            
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
