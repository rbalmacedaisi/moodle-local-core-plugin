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
 * Class definition for the local_grupomakro_get_teacher_available_days external function.
 *
 * @package    local_grupomakro_core
 * @copyright  2023 Solutto Consulting <devs@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\gmkclass;

use context_system;
use external_api;
use external_description;
use external_function_parameters;
use external_single_structure;
use external_value;
use Exception;
use stdClass;


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
/**
 * External function 'local_grupomakro_get_teacher_available_days implementation.
 *
 * @package     local_grupomakro_core
 * @category    external
 * @copyright   2023 Solutto Consulting <devs@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_teacher_available_days extends external_api {

    /**
     * Describes parameters of the {@see self::execute()} method.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'initTime' => new external_value(PARAM_TEXT, 'Selected init time', VALUE_REQUIRED),
                'endTime' => new external_value(PARAM_TEXT, 'Selected end time', VALUE_REQUIRED),
                'instructorId' => new external_value(PARAM_INT, 'Intructor ID', VALUE_REQUIRED)
            ]
        );
    }
    /**
     * TODO describe what the function actually does.
     *
     * @param int instructorId
     * @return mixed TODO document
     */
    public static function execute(
            $initTime,
            $endTime,
            $instructorId
        ) {
        
        try{
            // Validate the parameters passed to the function.
            $params = self::validate_parameters(self::execute_parameters(), [
                'initTime' => $initTime,
                'endTime' => $endTime,
                'instructorId' => $instructorId
            ]);
            
            global $DB;
            
            $weekdaysHeaders = array(
                'disp_monday' => 'Lunes',
                'disp_tuesday' => 'Martes',
                'disp_wednesday' => 'Miércoles',
                'disp_thursday' => 'Jueves',
                'disp_friday' => 'Viernes',
                'disp_saturday' => 'Sábado',
                'disp_sunday' => 'Domingo'
            );
            
            $weekdays = array(
                0 => 'Lunes',
                1 => 'Martes',
                2 => 'Miércoles',
                3 => 'Jueves',
                4 => 'Viernes',
                5 => 'Sábado',
                6 => 'Domingo'
            );
            $incomingTimestampRange =convert_time_range_to_timestamp_range([$params['initTime'],$params['endTime']]);
            $incomingTimestampRangeObject = new stdClass();
            $incomingTimestampRangeObject->st = $incomingTimestampRange['initTS'];
            $incomingTimestampRangeObject->et = $incomingTimestampRange['endTS'];
            
            $disponibilityRecord = $DB->get_record('gmk_teacher_disponibility', ['userid'=>$params['instructorId']]);
            
            $daysAvailables = array_filter(array_map(function ($day,$dayColumnName) use ($disponibilityRecord,$incomingTimestampRangeObject){
                $dayAvailable = check_if_time_range_is_contained(json_decode($disponibilityRecord->{$dayColumnName}),$incomingTimestampRangeObject);
                if($dayAvailable) return $day;
            },$weekdaysHeaders,array_keys($weekdaysHeaders)));
            
            
            $alreadyAsignedClasses = list_classes(['instructorid'=>strval($params['instructorId'])]);
            
            foreach($alreadyAsignedClasses as $alreadyAsignedClass){
                $alreadyAsignedClassSchedule = explode('/', $alreadyAsignedClass->classdays);
                $classInitTime = $alreadyAsignedClass->inittimets;
                $classEndTime = $alreadyAsignedClass->endtimets;
                
                for ($i = 0; $i < 7; $i++) {
                    if ($alreadyAsignedClassSchedule[$i] === '1') {
                        if(($incomingTimestampRangeObject->st >= $classInitTime && $incomingTimestampRangeObject->et<=$classEndTime) || ($incomingTimestampRangeObject->st< $classInitTime && $incomingTimestampRangeObject->et>$classInitTime) ||($incomingTimestampRangeObject->st< $classEndTime && $incomingTimestampRangeObject->et>$classEndTime)){
                            $key = array_search($weekdays[$i], $daysAvailables);
                            if ($key !== false) {
                                unset($daysAvailables[$key]);
                            }
                        }
                    }
                }
            }
        
            // Return the result.
            return ['days'=>json_encode(array_values($daysAvailables))];
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
                'status' => new external_value(PARAM_INT, '1 if success or -1 if there was an error.',VALUE_DEFAULT,1),
                'days' => new external_value(PARAM_RAW, 'The list of days that contains the selected class time range',VALUE_DEFAULT,null),
                'message' => new external_value(PARAM_TEXT, 'The error message or Ok.',VALUE_DEFAULT,'ok'),
            )
        );
    }
}
