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

namespace local_grupomakro_core\external\disponibility;

use context_system;
use external_api;
use external_description;
use external_function_parameters;
use external_single_structure;
use external_multiple_structure;
use external_value;
use stdClass;
use DateTime;
defined('MOODLE_INTERNAL') || die();

require_once $CFG->libdir . '/externallib.php';
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->dirroot.'/lib/moodlelib.php');
require_once($CFG->dirroot . '/calendar/lib.php');
/**
 * External function 'local_grupomakro_delete_class' implementation.
 *
 * @package     local_grupomakro_core
 * @category    external
 * @copyright   2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class add_teacher_disponibility extends external_api {

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
                )
            ],
            'Parameters for setting instructor availability'
        );
    }
    /**
     * TODO describe what the function actually does.
     *
     * @param int $userid
     * @return mixed TODO document
     */
    public static function execute(
        $instructorId,$newDisponibilityRecords
        ) {
        
        // Validate the parameters passed to the function.
        $params = self::validate_parameters(self::execute_parameters(), [
            'instructorId' => $instructorId,
            'newDisponibilityRecords' => $newDisponibilityRecords
        ]);
        
        // Global variables.
        global $DB;
        
        $dayENLabels = array(
            'lunes' => 'monday',
            'martes' => 'tuesday',
            'miércoles' => 'wednesday',
            'jueves' => 'thursday',
            'viernes' => 'friday',
            'sábado' => 'saturday',
            'domingo' => 'sunday'
        );

        $teacherDisponibility = new stdClass();
        $teacherDisponibility->userid=$instructorId;
        
        foreach($newDisponibilityRecords as $newDisponibilityRecord){
            $day = $newDisponibilityRecord['day'];
            $teacherDisponibility->{'disp_'.$dayENLabels[$day]}=self::calculate_disponibility_range($newDisponibilityRecord['timeslots']);
        }
        print_object($newDisponibilityRecords);
        die;
        
        
        // Return the result.
        return ['status' => $deleteClassId, 'message' => 'ok'];
    }
    
    
    public static function calculate_disponibility_range($timeRanges){
     
    $ranges = [];
    
    foreach ($timeRanges as $range) {
        $times = explode(',', $range);
        $start = strtotime($times[0]);
        $end = strtotime($times[1]);
        
        $merged = false;
        foreach ($ranges as $key => $existing) {
            if ($start >= $existing->st && $end <= $existing->et) {
                // New range is completely contained in an existing range
                $merged = true;
                break;
            } elseif ($start <= $existing->st && $end >= $existing->et) {
                // New range completely contains an existing range
                $existing->st = $start;
                $existing->et = $end;
                $merged = true;
                break;
            } elseif ($start <= $existing->et && $end >= $existing->et) {
                // New range overlaps the end of an existing range
                $existing->et = $end;
                $merged = true;
                break;
            } elseif ($end >= $existing->st && $start <= $existing->st) {
                // New range overlaps the start of an existing range
                $existing->st = $start;
                $merged = true;
                break;
            }
        }
        
        if (!$merged) {
            $ranges[] = (object)['st' => $start, 'et' => $end];
        }
    }
    
    $result = [];
    foreach ($ranges as $range) {
        $result[] = (object)['st' => $range->st - strtotime('today'), 'et' => $range->et - strtotime('today')];
    }
    print_r($result);
        die;
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
