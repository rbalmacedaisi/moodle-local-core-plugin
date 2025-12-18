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
 * Class definition for the local_grupomakro_update_class external function.
 *
 * @package    local_grupomakro_core
 * @copyright  2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\gmkclass;

use context_system;
use external_api;
use external_description;
use external_function_parameters;
use external_single_structure;
use external_value;
use stdClass;
use Exception;

defined('MOODLE_INTERNAL') || die();

require_once $CFG->dirroot. '/local/grupomakro_core/locallib.php';

/**
 * External function 'local_grupomakro_update_class' implementation.
 *
 * @package     local_grupomakro_core
 * @category    external
 * @copyright   2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_class extends external_api {

    /**
     * Describes parameters of the {@see self::execute()} method.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [   
                'classId'=> new external_value(PARAM_TEXT, 'Id of the class.'),
                'name' => new external_value(PARAM_TEXT, 'Name of the class.'),
                'type' => new external_value(PARAM_INT, 'Type of the class (virtual(1) or inplace(0)).'),
                'learningPlanId' => new external_value(PARAM_INT, 'Id of the learning plan attached.'),
                'periodId' => new external_value(PARAM_INT, 'Id of the period when the class is going to be dictated defined in the leaerning pland and '),
                'courseId' => new external_value(PARAM_INT, 'Course id for the class'),
                'instructorId' => new external_value(PARAM_INT, 'Id of the class instructor'),
                'initTime' => new external_value(PARAM_TEXT, 'Init hour for the class'),
                'endTime' => new external_value(PARAM_TEXT, 'End hour of the class'),
                'initDate' => new external_value(PARAM_TEXT, 'The start date of the class (YYYY-MM-DD)', VALUE_DEFAULT, ''),
                'endDate' => new external_value(PARAM_TEXT, 'The end date of the class (YYYY-MM-DD)', VALUE_DEFAULT, ''),
                'classDays' => new external_value(PARAM_TEXT, 'The days when the class will have sessions, the format is l/m/m/j/v/s/d and every letter can contain 0 or 1 depending if the day is active'),
                // 'classroomId' => new external_value(PARAM_TEXT, 'Classroom id',VALUE_DEFAULT,null,NULL_ALLOWED),
                // 'classroomCapacity' => new external_value(PARAM_INT, 'Classroom capacity',VALUE_DEFAULT,40),
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
        string $name,
        int $type,
        int $learningPlanId,
        int $periodId,
        int $courseId,
        int $instructorId,
        string $initTime,
        string $endTime,
        string $initDate = '',
        string $endDate = '',
        string $classDays
        // string $classroomId,
        // int $classroomCapacity
        ) {


        // Validate the parameters passed to the function.
        $params = self::validate_parameters(self::execute_parameters(), [
            'classId'=>$classId,
            'name' => $name,
            'type' =>$type,
            'learningPlanId'=>$learningPlanId,
            'periodId' =>$periodId,
            'courseId' =>$courseId,
            'instructorId' =>$instructorId,
            'initTime'=>$initTime,
            'endTime'=>$endTime,
            'initDate'=>$initDate,
            'endDate'=>$endDate,
            'classDays'=>$classDays
            // 'classroomId'=>$classroomId,
            // 'classroomCapacity'=>$classroomCapacity
        ]);
        
        try{
            check_class_schedule_availability($instructorId,$classDays, $initTime ,$endTime,'', $classId, $initDate, $endDate);
            
            update_class($params);
            
            // Return the result.
            return [];
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
                'status' => new external_value(PARAM_INT, '1 if class is updated, -1 otherwise.',VALUE_DEFAULT,1),
                'message' => new external_value(PARAM_TEXT, 'The error message or Ok.',VALUE_DEFAULT,'ok'),
            )
        );
    }
}