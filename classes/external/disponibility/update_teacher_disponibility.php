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

use external_api;
use external_description;
use external_function_parameters;
use external_single_structure;
use external_multiple_structure;
use external_value;
use Exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
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
                'newInstructorId' => new external_value(PARAM_INT, 'ID of the new instructor that will take the old instructor disponibility and classes', VALUE_DEFAULT,null),
                'skills'=>
                    new external_multiple_structure(
                        new external_value(PARAM_INT, 'Array of skills IDs', VALUE_DEFAULT, []),
                        'Array of skills IDs'
                    ),
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
        $instructorId,$newDisponibilityRecords,$newInstructorId,$skills
        ) {
        // Validate the parameters passed to the function.
        $params = self::validate_parameters(self::execute_parameters(), [
            'instructorId' => $instructorId,
            'newDisponibilityRecords' => $newDisponibilityRecords,
            'newInstructorId' => $newInstructorId,
            'skills'=>$skills
        ]);
        try {
            
            $disponibilityUpdated = update_teacher_disponibility($params);
            
            // Return the result.
            return ['disponibilityUpdated' => $disponibilityUpdated, 'message' => 'ok'];
        } catch (Exception $e) {
            return ['status' => -1,'disponibilityUpdated'=>-1, 'message' => $e->getMessage()];
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
                'status' => new external_value(PARAM_INT, '1 if success, -1 if there was an error.',VALUE_DEFAULT,1),
                'disponibilityUpdated' => new external_value(PARAM_INT, "1 if the disponibility record was updated, -1 if wasn't"),
                'message' => new external_value(PARAM_TEXT, 'The error message or Ok.',VALUE_DEFAULT,'ok')
            )
        );
    }
}
