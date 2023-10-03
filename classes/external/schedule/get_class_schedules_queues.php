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
 * Class definition for the local_grupomakro_get_class_schedules_queues external function.
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
use external_value;
use stdClass;
use Exception;


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

/**
 * External function 'local_grupomakro_get_class_schedules_queues' implementation.
 *
 * @package     local_grupomakro_core
 * @category    external
 * @copyright   2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_class_schedules_queues extends external_api {

    /**
     * Describes parameters of the {@see self::execute()} method.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'courseId' => new external_value(PARAM_TEXT, 'Course ID',VALUE_REQUIRED),
                'periodId' => new external_value(PARAM_TEXT, 'Course ID',VALUE_DEFAULT,null),
                'learningPlanId' => new external_value(PARAM_TEXT, 'Course ID',VALUE_DEFAULT,null)
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
        $courseId,
        $periodId,
        $learningPlanId
        ) {

        // Validate the parameters passed to the function.
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseId' => $courseId,
            'periodId' =>$periodId,
            'learningPlanId'=>$learningPlanId
        ]);

        try{
            $schedules =  get_learning_plan_course_schedules($params);
            $schedules = array_values($schedules)[0]->schedules;
            
            $schedules = array_map(function ($schedule){
                $scheduleQueue = new stdClass();
                $scheduleQueue->className = $schedule->name;
                $scheduleQueue->classDays = $schedule->classDaysString;
                $scheduleQueue->initHour = $schedule->inithourformatted;
                $scheduleQueue->endHour = $schedule->endhourformatted;
                $scheduleQueue->classId = $schedule->id;
                
                $scheduleQueue->queue = get_course_students_by_class_schedule($schedule->id);
                return $scheduleQueue;
                
            },$schedules);
            
            // Return the result.
            return ['status' => 1, 'courseSchedulesQueues'=>json_encode($schedules)];
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
                'courseSchedulesQueues' => new external_value(PARAM_RAW, 'The queues of all schedules from a course',VALUE_DEFAULT,null),
                'message' => new external_value(PARAM_TEXT, 'The error message or Ok.',VALUE_DEFAULT,'ok'),
            )
        );
    }
}