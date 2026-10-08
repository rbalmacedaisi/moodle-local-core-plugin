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
 * Class definition for the local_grupomakro_approve_course_class_schedules external function.
 *
 * @package    local_grupomakro_core
 * @copyright  2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\schedule;

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

require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

/**
 *
 * @package     local_grupomakro_core
 * @category    external
 * @copyright   2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class approve_course_class_schedules extends external_api {

    /**
     * Describes parameters of the {@see self::execute()} method.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            array(
                'approvingSchedules' => new external_multiple_structure(
                    new external_single_structure(
                        array(
                            'classId' => new external_value(PARAM_INT, 'schedule id (class ID)',VALUE_REQUIRED),
                            'approvalMessage' => new external_value(PARAM_TEXT, 'Approval message', VALUE_DEFAULT, ''),
                        )
                    ),
                ),
            )
        );
    }

    /**
     * TODO describe what the function actually does.
     *
     * @param int $userid
     * @return mixed TODO document
     */
    public static function execute(
        $approvingSchedules
        ) {
        

        // Validate the parameters passed to the function.
        $params = self::validate_parameters(self::execute_parameters(), [
            'approvingSchedules' => $approvingSchedules
        ]);
        
        try{
            
            $approveResults = approve_course_schedules($params["approvingSchedules"]);

            // Return the result.
            return ['status' => 1,'approveResults'=>json_encode($approveResults)];
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
                'status' => new external_value(PARAM_INT, '1 if success, -1 otherwise'),
                'approveResults' => new external_value(PARAM_RAW, 'The result of every user enrolment, a check indicating if the approval message was saved and other check if tha class was flagged as approved', VALUE_DEFAULT,null),
                'message' => new external_value(PARAM_TEXT, 'The error message or Ok.',VALUE_DEFAULT,'ok'),
            )
        );
    }
}