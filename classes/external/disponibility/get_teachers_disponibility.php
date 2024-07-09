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
 * Class definition for the local_grupomakro_get_teachers_disponibility external function.
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
use Exception;

defined('MOODLE_INTERNAL') || die();


require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
/**
 * External function 'local_grupomakro_get_teachers_disponibility' implementation.
 *
 * @package     local_grupomakro_core
 * @category    external
 * @copyright   2023 Solutto Consulting <devs@soluttoconsulting.com>
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
                'instructorId' => new external_value(PARAM_TEXT, 'ID of the teacher.', VALUE_DEFAULT,null),
                'initTime' => new external_value(PARAM_TEXT, 'init time filter', VALUE_DEFAULT,null),
                'endTime' => new external_value(PARAM_TEXT, 'end time filter', VALUE_DEFAULT,null)
            ]
        );
    }

    /**
     * get teacher disponibility
     *
     * @param string|null $instructorId ID of the teacher (optional)
     *
     * @throws moodle_exception
     *
     * @external
     */
    public static function execute(
            $instructorId,
            $initTime,
            $endTime
            
        ) {
        
        // Validate the parameters passed to the function.
        $params = self::validate_parameters(self::execute_parameters(), [
            'instructorId' => $instructorId,
            'initTime' => $initTime,
            'endTime' => $endTime,
        ]);
        
        try {
            $teachersDisponibility = get_teachers_disponibility($params);
            

            // Return the result.
            return ['status'=>1,'teacherAvailabilityRecords' =>json_encode(array_values($teachersDisponibility))];
        } catch (Exception $e) {
            return ['status'=>-1, 'message' => $e->getMessage()];
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
                'status' => new external_value(PARAM_INT, '1 if successs, -1 otherwise'),
                'teacherAvailabilityRecords' => new external_value(PARAM_RAW, 'The availability records of the teachers',VALUE_DEFAULT,null),
                'message' => new external_value(PARAM_TEXT, 'The error message or Ok.',VALUE_DEFAULT,'ok'),
            )
        );
    }
}
