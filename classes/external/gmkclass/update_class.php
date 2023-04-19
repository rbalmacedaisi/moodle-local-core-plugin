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

defined('MOODLE_INTERNAL') || die();

require_once $CFG->libdir . '/externallib.php';
require_once($CFG->libdir . '/filelib.php');
require_once $CFG->dirroot. '/group/externallib.php';
require_once $CFG->dirroot. '/local/grupomakro_core/lib.php';

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
                'classDays' => new external_value(PARAM_TEXT, 'The days when the class will have sessions, the format is l/m/m/j/v/s/d and every letter can contain 0 or 1 depending if the day is active')
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
        string $classDays
        ) {

        
        // Global variables.
        global $DB,$USER;
        
        $classInfo = $DB->get_record('gmk_class', ['id'=>$classId]);
        
        //Get the section
        $section = $DB->get_record('course_sections', ['id' => $classInfo->coursesectionid]);
        
        //Get the current course
        $learningCourse= $DB->get_record('local_learning_courses',['id'=>$classInfo->courseid]);
        $coreCourseId = $learningCourse->courseid;
        $course = $DB->get_record('course', ['id' => $coreCourseId]);
        
        //Delete created resources before the update
        $sectionnum = $section->section;
        $sectioninfo = get_fast_modinfo($course)->get_section_info($sectionnum);    
        course_delete_section($course, $sectioninfo, true, true);
        rebuild_course_cache($coreCourseId, true);

        //Get the real course Id from the courses table for the new course.
        $learningCourse= $DB->get_record('local_learning_courses',['id'=>$courseId]);
        $coreCourseId = $learningCourse->courseid;
        $course = $DB->get_record('course', ['id' => $coreCourseId]);
        
        //----------------------------------------Update Group-----------------------------------------
        
        $groupInfo = new stdClass();
        $groupInfo->id = $classInfo->groupid;
        $groupInfo->name = $name.'-'.$classId;
        $groupInfo->courseid = $coreCourseId;
        $groupInfo->timemodified = time();
        
        $groupUpdated = $DB->update_record('groups',$groupInfo);
        
        //---------------------------------------Update Class------------------------------------------
        
        $classInfo->name           = $name;
        $classInfo->type           = $type;
        $classInfo->learningplanid = $learningPlanId;
        $classInfo->periodid       = $periodId;
        $classInfo->courseid       = $courseId;
        $classInfo->instructorid   = $instructorId;
        $classInfo->inittime       = $initTime;
        $classInfo->endtime        = $endTime;
        $classInfo->classdays      = $classDays;
        $classInfo->usermodified   = $USER->id;
        $classInfo->timemodified   = time();
        
        $classUpdated = $DB->update_record('gmk_class', $classInfo);
        
        //-------------------------------Delete section and recreate it-------------------------------
        
                
        $classSection = grupomakro_core_create_class_section($classInfo,$coreCourseId,$classInfo->groupid );
        rebuild_course_cache($coreCourseId, true);
        
        $classInfo->coursesectionid      = $classSection->id;
        $classUpdated = $DB->update_record('gmk_class', $classInfo);
        
        $instructorUserId = $DB->get_record('local_learning_users',['id'=>$instructorId])->userid;
        grupomakro_core_create_class_activities($classInfo,$course, $type, $classSection->section,$classInfo->groupid,$instructorUserId);
    
        // Return the result.
        return ['status' => $classUpdated];
    }


    /**
     * Describes the return value of the {@see self::execute()} method.
     *
     * @return external_description
     */
    public static function execute_returns(): external_description {
        return new external_single_structure(
            array(
                'status' => new external_value(PARAM_BOOL, 'True if the class is updated, false otherwise.'),
            )
        );
    }
}
