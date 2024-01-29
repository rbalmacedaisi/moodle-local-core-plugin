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
 * Class definition for the local_grupomakro_get_student_learning_plan_pensum external function.
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
use Exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

/**
 * External function 'local_grupomakro_get_student_learning_plan_pensum' implementation.
 *
 * @package     local_grupomakro_core
 * @category    external
 * @copyright   2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_student_learning_plan_pensum extends external_api {
    
    const STATUS_LABEL = [
        0=>'No disponible', 
        1=>'Disponible',
        2=>'Cursando',
        3=>'Aprobada',
        4=>'Aprobada',
        5=>'Reprobada',
        6=>'Revalida',
        7=>'Reprobado',
    ];
    
    const STATUS_COLOR = [
        0=>'#5e35b1', 
        1=>'#1e88e5',
        2=>'#11d1bf',
        3=>'#0cce7b',
        4=>'#0cce7b',
        5=>'#ec407a',
        6=>'#ec407a',
        7=>'#ec407a',
    ];
    
    /**
     * Describes parameters of the {@see self::execute()} method.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'userId' => new external_value(PARAM_TEXT, 'ID of the student.',VALUE_REQUIRED),
                'learningPlanId' => new external_value(PARAM_TEXT, 'ID of the learningPlan.',VALUE_REQUIRED)
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
        $userId,
        $learningPlanId
        ) {
        
        // Validate the parameters passed to the function.
        $params = self::validate_parameters(self::execute_parameters(), [
            'userId' => $userId,
            'learningPlanId'=>$learningPlanId
        ]);
        
        global $DB;
        try{
            $userPensumCourses = $DB->get_records('gmk_course_progre',['userid'=>$params['userId'],'learningplanid'=>$params['learningPlanId']]);
            $groupedUserPensumCourses = [];
            foreach($userPensumCourses as $userPensumCourse){
                
                $userPensumCourse->statusLabel = self::get_status_label($userPensumCourse->status);
                $userPensumCourse->statusColor = self::get_status_color($userPensumCourse->status);
                $userPensumCourse->prerequisites = json_decode($userPensumCourse->prerequisites);
                foreach($userPensumCourse->prerequisites as $prerequisite){
                    $completion = new \completion_info(get_course($prerequisite->id));
                    $prerequisite->completed = $completion->is_course_complete($params['userId']);
                }
                if(!array_key_exists($userPensumCourse->periodname,$groupedUserPensumCourses)){
                    $groupedUserPensumCourses[$userPensumCourse->periodname]['id']=$userPensumCourse->periodid;
                    $groupedUserPensumCourses[$userPensumCourse->periodname]['periodName']=$userPensumCourse->periodname;
                }
                if($userPensumCourse->tc ==='1'){
                    $commonTrunkCourseRecords = $DB->get_records('gmk_course_progre',['userid'=>$params['userId'],'courseid'=>$userPensumCourse->courseid]);
                    foreach($commonTrunkCourseRecords as $commonTrunkCourseRecord){
                        $userPensumCourse->grade = $userPensumCourse->grade < $commonTrunkCourseRecord->grade?$commonTrunkCourseRecord->grade:$userPensumCourse->grade;
                    }
                }
                $groupedUserPensumCourses[$userPensumCourse->periodname]['courses'][]=$userPensumCourse;
            }
            return ['pensum'=>json_encode($groupedUserPensumCourses)];
        }catch (Exception $e) {
            return ['status' => -1,'message' => $e->getMessage()];
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
                'status' => new external_value(PARAM_INT, '1 for success, -1 for failure',VALUE_DEFAULT,1),
                'pensum' => new external_value(PARAM_RAW, 'json encode object with the pensum info',VALUE_DEFAULT,null),
                'message' => new external_value(PARAM_TEXT, 'The error message or ok.',VALUE_DEFAULT,'ok'),
            )
        );
    }
    
    public function get_status_label($status){
        return self::STATUS_LABEL[$status];
    }
    
    public function get_status_color($status){
        return self::STATUS_COLOR[$status];
    }

}
