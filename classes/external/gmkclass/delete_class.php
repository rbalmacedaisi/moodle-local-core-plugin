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
 * Class definition for the local_grupomakro_delete_class external function.
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
require_once $CFG->dirroot . '/group/externallib.php';

/**
 * External function 'local_grupomakro_delete_class' implementation.
 *
 * @package     local_grupomakro_core
 * @category    external
 * @copyright   2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class delete_class extends external_api {

    /**
     * Describes parameters of the {@see self::execute()} method.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'id' => new external_value(PARAM_TEXT, 'ID of the class to be delete.')
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
        string $id
        ) {
        
        // Validate the parameters passed to the function.
        $params = self::validate_parameters(self::execute_parameters(), [
            'id' => $id,
        ]);
        
        // Global variables.
        global $DB;
        
        $classInfo = $DB->get_record('gmk_class', ['id'=>$id]);
        
        //Get the section.
        $section = $DB->get_record('course_sections', ['id' => $classInfo->coursesectionid]);
        
        //Get the current course.
        $learningCourse= $DB->get_record('local_learning_courses',['id'=>$classInfo->courseid]);
        $coreCourseId = $learningCourse->courseid;
        $course = $DB->get_record('course', ['id' => $coreCourseId]);
        
        //Delete created resources.
        $sectionnum = $section->section;
        $sectioninfo = get_fast_modinfo($course)->get_section_info($sectionnum);    
        course_delete_section($course, $sectioninfo, true, true);
        rebuild_course_cache($coreCourseId, true);
        
        //Delete the class group.
        $groupIds = [$classInfo->groupid];
        $deleteGroup = \core_group_external::delete_groups($groupIds);

        //Lastly, delete the class itself.
        $deleteClassId = $DB->delete_records('gmk_class',['id'=>$id]);

        // Return the result.
        return ['status' => $deleteClassId, 'message' => 'ok'];
    }


    /**
     * Describes the return value of the {@see self::execute()} method.
     *
     * @return external_description
     */
    public static function execute_returns(): external_description {
        return new external_single_structure(
            array(
                'status' => new external_value(PARAM_INT, 'The ID of the delete class or -1 if there was an error.'),
                'message' => new external_value(PARAM_TEXT, 'The error message or Ok.'),
            )
        );
    }
}
