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
 * Class definition for the local_grupomakro_get_student_course_pensum_activities external function.
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
require_once($CFG->dirroot . '/grade/querylib.php');

/**
 * External function 'local_grupomakro_get_student_course_pensum_activities' implementation.
 *
 * @package     local_grupomakro_core
 * @category    external
 * @copyright   2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_student_course_pensum_activities extends external_api
{

    /**
     * Describes parameters of the {@see self::execute()} method.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters
    {
        return new external_function_parameters(
            [
                'userId' => new external_value(PARAM_TEXT, 'ID of the student.', VALUE_REQUIRED),
                'courseId' => new external_value(PARAM_TEXT, 'ID of the course.', VALUE_REQUIRED)
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
        $courseId
    ) {

        // Validate the parameters passed to the function.
        $params = self::validate_parameters(self::execute_parameters(), [
            'userId' => $userId,
            'courseId' => $courseId
        ]);

        try {
            global $DB;
            \gmk_log("DEBUG get_student_course_pensum_activities - UserID: $userId, CourseID: $courseId");
            
            $coursemod = get_fast_modinfo($params['courseId'], $params['userId']);

            $userGroups = $coursemod->get_groups();
            \gmk_log("DEBUG get_student_course_pensum_activities - Groups found: " . count($userGroups));

            $completion = new \completion_info($coursemod->get_course());
            $gradableActivities = grade_get_gradable_activities($params['courseId']);

            $activities = [];

            foreach ($userGroups as $userGroup) {
                $groupClassSection = $DB->get_field('gmk_class', 'coursesectionid', ['groupid' => $userGroup]);

                if (!$groupClassSection) {
                    continue;
                }
                
                try {
                    $section = $coursemod->get_section_info_by_id($groupClassSection);
                    if (!$section) {
                        \gmk_log("DEBUG get_student_course_pensum_activities - Section $groupClassSection not found in course info");
                        continue;
                    }
                    $classSectionNumber = $section->__get('section');
                } catch (Exception $secEx) {
                    \gmk_log("DEBUG get_student_course_pensum_activities - Error getting section info: " . $secEx->getMessage());
                    continue;
                }

                if (!isset($coursemod->get_sections()[$classSectionNumber])) {
                    continue;
                }

                foreach ($coursemod->get_sections()[$classSectionNumber] as $sectionModule) {
                    $module = $coursemod->get_cm($sectionModule);
                    $moduleRecord = $module->get_course_module_record(true);
                    $moduleType = $moduleRecord->modname;
                    
                    if ($moduleType === 'bigbluebuttonbn' || !array_key_exists($moduleRecord->id, $gradableActivities)) {
                        continue;
                    }

                    $activityInfo = new \stdClass();
                    $activityInfo->name = $moduleRecord->name;
                    $activityInfo->completed = $completion->get_grade_completion($module, $userId);
                    
                    $gradeItems = grade_get_grades($courseId, 'mod', $moduleType, $moduleRecord->instance, $userId);
                    $activityGrade = '-';
                    if (!empty($gradeItems->items[0]->grades[$userId])) {
                        $activityGrade = $gradeItems->items[0]->grades[$userId]->str_grade;
                    }
                    
                    $activityInfo->grade = $activityGrade === '-' ? 'Sin calificar' : $activityGrade;
                    $activities[] = $activityInfo;
                }
            }

            \gmk_log("DEBUG get_student_course_pensum_activities - Activities returned: " . count($activities));

            return [
                'status' => 1,
                'message' => 'ok',
                'activities' => json_encode($activities)
            ];
        } catch (Exception $e) {
            \gmk_log("DEBUG get_student_course_pensum_activities - CRASH: " . $e->getMessage());
            return ['status' => -1, 'message' => $e->getMessage()];
        }
    }

    public function convert_name_timestamp($activityName)
    {
        // Extract timestamp from the string
        $timestamp = end(explode("-", $activityName));
        // Convert timestamp to datetime
        $datetime = date("Y-m-d H:i:s", $timestamp);
        // Replace the original timestamp with the new datetime in the string
        return preg_replace("/-\d+$/", "-$datetime", $activityName);
    }


    /**
     * Describes the return value of the {@see self::execute()} method.
     *
     * @return external_description
     */
    public static function execute_returns(): external_description
    {
        return new external_single_structure(
            array(
                'status' => new external_value(PARAM_INT, '1 for success, -1 for failure', VALUE_DEFAULT, 1),
                'activities' => new external_value(PARAM_RAW, 'json encode object with the learning plans overview', VALUE_DEFAULT, null),
                'message' => new external_value(PARAM_TEXT, 'The error message or ok.', VALUE_DEFAULT, 'ok'),
            )
        );
    }
}
