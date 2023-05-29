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
 * Class definition for the local_grupomakro_add_teacher_disponibility external function.
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

defined('MOODLE_INTERNAL') || die();

require_once $CFG->libdir . '/externallib.php';
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->dirroot.'/lib/moodlelib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
/**
 * External function 'local_grupomakro_add_teacher_disponibility' implementation.
 *
 * @package     local_grupomakro_core
 * @category    external
 * @copyright   2023 Solutto Consulting <devs@soluttoconsulting.com>
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
     * Set instructor availability
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
        $instructorId,$newDisponibilityRecords
        ) {
        
        try {
            // Validate the parameters passed to the function.
            $params = self::validate_parameters(self::execute_parameters(), [
                'instructorId' => $instructorId,
                'newDisponibilityRecords' => $newDisponibilityRecords
            ]);
            
            // Global variables.
            global $DB;
            
            $dayENLabels = array(
                'lunes' => 'disp_monday',
                'martes' => 'disp_tuesday',
                'miercoles' => 'disp_wednesday',
                'jueves' => 'disp_thursday',
                'viernes' => 'disp_friday',
                'sabado' => 'disp_saturday',
                'domingo' => 'disp_sunday'
            );
    
            $teacherDisponibility = new stdClass();
            $teacherDisponibility->userid =$instructorId;
            
            foreach($newDisponibilityRecords as $newDisponibilityRecord){
                $day = strtolower(str_replace(['á', 'é', 'í', 'ó', 'ú', 'ñ'], ['a', 'e', 'i', 'o', 'u', 'n'], $newDisponibilityRecord['day']));
                $teacherDisponibility->{$dayENLabels[$day]}=json_encode(calculate_disponibility_range($newDisponibilityRecord['timeslots']));
            }
            
            foreach($dayENLabels as $dayLabel){
                !property_exists( $teacherDisponibility,$dayLabel)?$teacherDisponibility->{$dayLabel}="[]" :null;
            }
            
            try {
                $disponibilityRecordId = $DB->insert_record('gmk_teacher_disponibility',$teacherDisponibility);
            } catch (Exception $e) {
                $disponibilityRecordId = -1;
            }
            
            // Return the result.
            return ['status' => $disponibilityRecordId, 'message' => 'ok'];
        } catch (Exception $e) {
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
