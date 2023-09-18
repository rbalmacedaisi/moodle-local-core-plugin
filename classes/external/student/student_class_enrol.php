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
 * Class definition for the local_grupomakro_student_class_enrol external function.
 *
 * @package    local_grupomakro_core
 * @copyright  2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\student;

use context_system;
use external_api;
use external_description;
use external_function_parameters;
use external_single_structure;
use external_value;
use stdClass;
use Exception;

defined('MOODLE_INTERNAL') || die();

require_once $CFG->dirroot. '/group/lib.php';
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

/**
 * External function 'local_grupomakro_student_class_enrol ' implementation.
 *
 * @package     local_grupomakro_core
 * @category    external
 * @copyright   2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class student_class_enrol extends external_api {

    /**
     * Describes parameters of the {@see self::execute()} method.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'classId' => new external_value(PARAM_TEXT, 'ID of the class.'),
                'userId' => new external_value(PARAM_TEXT, 'ID of the student'),
                'forceQueue' => new external_value(PARAM_BOOL, 'ID of the student', VALUE_DEFAULT,false)
            ]
        );
    }

    /**
     * TODO describe what the function actually does.
     *
     * @param string id
     * @return mixed TODO document
     */
    public static function execute(
        string $classId,
        string $userId,
        bool $forceQueue
        ) {
        
        global $DB;
        // Validate the parameters passed to the function.
        $params = self::validate_parameters(self::execute_parameters(), [
            'classId' => $classId,
            'userId' => $userId,
            'forceQueue' => $forceQueue
        ]);
        $addedToQueue=false;
        $enrolResult=false;
        $courseAlternativeClasses = false;
        try{
            $selectedClass = list_classes(['id'=>$classId])[$classId];
            $selectedClassFull = $selectedClass->classFull;
            
            $courseAlternativeClasses = check_course_alternative_schedules($selectedClass,$userId);
            if(!$selectedClassFull){
                $enrolResult = groups_add_member($selectedClass->groupid,$userId);
            }
            
            else if($forceQueue || empty($courseAlternativeClasses[$selectedClass->corecourseid]['schedules'])){
                $addedToQueue = add_user_to_class_queue($userId,$selectedClass);
                $courseAlternativeClasses = false;
            }
            
            if(!empty($courseAlternativeClasses[$selectedClass->corecourseid]['schedules'])){
                $courseAlternativeClasses = array_map(function ($course){
                    $course['schedules'] = array_values($course['schedules']);
                    return $course;
                },$courseAlternativeClasses);
            }
            return ['status'=>'1','enrolResult'=>$enrolResult,'addedToQueue'=>$addedToQueue,'classAlternatives'=>json_encode($courseAlternativeClasses),'message'=>'ok'];
        }catch (Exception $e) {
            return ['status' => '-1','enrolResult'=>false,'addedToQueue'=>false,'classAlternatives'=>false, 'message' => $e->getMessage()];
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
                'status' => new external_value(PARAM_TEXT, '1 for success, -1 for failure'),
                'enrolResult' => new external_value(PARAM_BOOL, 'True if the enrolment was successful, false otherwise'),
                'addedToQueue'=> new external_value(PARAM_BOOL, 'True if the user was added to the class queue, false otherwise'),
                'classAlternatives'=> new external_value(PARAM_RAW, 'Json encode class alternatives'),
                'message' => new external_value(PARAM_TEXT, 'The error message or ok.'),
            )
        );
    }
}
