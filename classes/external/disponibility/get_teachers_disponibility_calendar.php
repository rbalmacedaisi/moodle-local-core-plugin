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
 * Class definition for the local_grupomakro_get_teachers_disponibility_calendar external function.
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
use external_value;
use stdClass;
use DateTime;

defined('MOODLE_INTERNAL') || die();

require_once $CFG->libdir . '/externallib.php';
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->dirroot.'/lib/moodlelib.php');
require_once($CFG->dirroot . '/calendar/lib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
/**
 * External function 'local_grupomakro_get_teachers_disponibility_calendar' implementation.
 *
 * @package     local_grupomakro_core
 * @category    external
 * @copyright   2023 Solutto Consulting <devs@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_teachers_disponibility_calendar extends external_api {

    /**
     * Describes parameters of the {@see self::execute()} method.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'instructorId' => new external_value(PARAM_TEXT, 'ID of the teacher.', VALUE_OPTIONAL)
            ]
        );
    }

    /**
     * get teacher disponibility for the disponibility calendar
     *
     * @param string|null $instructorId ID of the teacher (optional)
     *
     * @throws moodle_exception
     *
     * @external
     */
    public static function execute(
            $instructorId = null
        ) {
        
        // Validate the parameters passed to the function.
        $params = self::validate_parameters(self::execute_parameters(), [
            'instructorId' => $instructorId,
        ]);
        
        // Global variables.
        global $DB;
        
        $initDate = '2023-04-01';
        $endDate = '2023-05-30';
        
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
            $teachersDisponibility[$teacherId]->events = json_decode(\local_grupomakro_core\external\calendar_external::execute($teacherId)['events']);
            
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
                        $result[$day] = checkRangeArray($result[$day], $event);
                    }

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
        // Return the result.
        return ['disponibility' => json_encode(array_values($teachersDisponibility)), 'message' => 'ok'];
    }
    
    /**
     * Describes the return value of the {@see self::execute()} method.
     *
     * @return external_description
     */
    public static function execute_returns(): external_description {
        return new external_single_structure(
            array(
                'disponibility' => new external_value(PARAM_RAW, 'The ID of the delete class or -1 if there was an error.'),
                'message' => new external_value(PARAM_TEXT, 'The error message or Ok.'),
            )
        );
    }
}
