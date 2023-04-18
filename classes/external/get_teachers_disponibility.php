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
 * Class definition for the local_grupomakro_delete_class external function.
 *
 * @package    local_grupomakro_core
 * @copyright  2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external;

use context_system;
use external_api;
use external_description;
use external_function_parameters;
use external_single_structure;
use external_value;
use stdClass;
defined('MOODLE_INTERNAL') || die();

require_once $CFG->libdir . '/externallib.php';
require_once($CFG->libdir . '/filelib.php');

/**
 * External function 'local_grupomakro_delete_class' implementation.
 *
 * @package     local_grupomakro_core
 * @category    external
 * @copyright   2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_teachers_disponibility extends external_api {

    /**
     * Describes parameters of the {@see self::execute()} method.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                // 'id' => new external_value(PARAM_TEXT, 'ID of the class to be delete.')
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
        // string $id
        ) {
        
        // Validate the parameters passed to the function.
        // $params = self::validate_parameters(self::execute_parameters(), [
        //     'id' => $id,
        // ]);
        
        // Global variables.
        global $DB;
        
        $disponibilityRecords = $DB->get_records('gmk_teacher_disponibility');
        
        $weekdays = array(
            'disp_monday',
            'disp_tuesday',
            'disp_wednesday',
            'disp_thursday',
            'disp_friday',
            'disp_saturday',
            'disp_sunday'
        );
        $teachersDisponibility = new stdClass();
        
        foreach($disponibilityRecords as $disponibilityRecord){
            $teacherId = $disponibilityRecord->userid;
            $teachersDisponibility->{$teacherId}= new stdClass();
            
            foreach($weekdays as $day){
                $dayAvailabilities = json_decode($disponibilityRecord->{$day});
                $dayLabel = substr($day, 5);
                $teachersDisponibility->{$teacherId}->{$dayLabel} =self::calculate_disponibility_hours($dayAvailabilities);
            }
        }
        print_object($teachersDisponibility);
        die();

        
        
        // Return the result.
        return ['status' => $deleteClassId, 'message' => 'ok'];
    }
    
    
    public static function calculate_disponibility_hours($dayAvailabilities){
        $result = array();
        foreach($dayAvailabilities as $dayAvailability){
            if(!$dayAvailability){continue;}
        
            $startTime = $dayAvailability->st;
            $endTime = $dayAvailability->et;
            
            $startHour = sprintf('%02d:%02d', floor($startTime/3600), ($startTime/60)%60);
            $endHour = sprintf('%02d:%02d', floor($endTime/3600), ($endTime/60)%60);
            
            // Add initial hour to the result array
            $result[] = $startHour;
            
            $startHour = (int)substr($startHour, 0, 2);
            $endHour = (int)substr($endHour , 0, 2);
            $numHours = $endHour - $startHour;
            
            // Adjust end hour to nearest o'clock hour
            if ((int)substr($end, 3) != 0) {
                $endHour++;
            }
            
            // Add o'clock hours for each hour between start and end
            for ($i = 1; $i < $numHours; $i++) {
                $hour = $startHour + $i;
                $result[] = sprintf('%02d:00', $hour);
            }
            
            // Add final o'clock hour to the result array
            // $result[] = sprintf('%02d:00', $endHour);
        }
        return $result;
    }

    /**
     * Describes the return value of the {@see self::execute()} method.
     *
     * @return external_description
     */
    public static function execute_returns(): external_description {
        return new external_single_structure(
            array(
                'status' => new external_value(PARAM_INT, 'The ID of the delete class or -1 if there was an error.'),
                'message' => new external_value(PARAM_TEXT, 'The error message or Ok.'),
            )
        );
    }
}
