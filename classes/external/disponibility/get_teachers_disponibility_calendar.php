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
use Exception;

defined('MOODLE_INTERNAL') || die();

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
                'instructorId' => new external_value(PARAM_TEXT, 'ID of the teacher.', VALUE_DEFAULT,null)
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

        $teachersDisponibility = get_teacher_disponibility_calendar($params['instructorId']);
        
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
