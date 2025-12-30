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
 * Class definition for the local_grupomakro_get_user_courses_by_category external function.
 *
 * @package    local_grupomakro_core
 * @copyright  2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\student;

use external_api;
use external_description;
use external_function_parameters;
use Exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

/**
 * External function 'local_grupomakro_get_user_courses_by_category' implementation.
 *
 * @package     local_grupomakro_core
 * @category    external
 * @copyright   2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_user_courses_by_category extends external_api
{

    /**
     * Describes parameters of the {@see self::execute()} method.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters
    {
        return \local_soluttolms_core\external\getcourses_by_token::execute_parameters();
    }

    /**
     * TODO describe what the function actually does.
     *
     * @param string id
     * @return mixed TODO document
     */
    public static function execute(
        int $userid
    ) {
        // Validate the parameters passed to the function.
        $params = self::validate_parameters(self::execute_parameters(), [
            'userid' => $userid,
        ]);
        global $DB;
        try {
            $userCoursesByCategory = \local_soluttolms_core\external\getcourses_by_token::execute($params['userid']);
            $courseCategories = json_decode($userCoursesByCategory['categoryobj']);
            $userGmkCourseProgress = $DB->get_records('gmk_course_progre', ['userid' => $params['userid']], '', 'courseid,progress');

            foreach ($courseCategories as &$courseCategory) {
                foreach ($courseCategory->courses as &$course) {
                    $courseProgre = isset($userGmkCourseProgress[$course->id]) ? $userGmkCourseProgress[$course->id] : null;
                    $progress = $courseProgre ? $courseProgre->progress : 0;
                    
                    // [VIRTUAL FALLBACK] Check gradebook directly if progress is not 100.
                    if ($progress < 100) {
                        $gradeObj = grade_get_course_grade($params['userid'], $course->id);
                        if ($gradeObj && $gradeObj->grade >= 70) {
                            $progress = 100;
                        }
                    }

                    $course->progress = (float)$progress;
                    
                    // [FIX] Populate credits from progress record or fallback.
                    if ($courseProgre && !empty($courseProgre->credits)) {
                        $course->credits = (int)$courseProgre->credits;
                    } else {
                         // We don't have learningplanid easily here, but we can try to find the first match.
                         $course->credits = (int)$DB->get_field('gmk_course_progre', 'credits', ['courseid' => $course->id, 'userid' => $params['userid']], IGNORE_MULTIPLE);
                         if (empty($course->credits)) {
                             $course->credits = (int)$DB->get_field('local_learning_courses', 'credits', ['courseid' => $course->id], IGNORE_MULTIPLE);
                         }
                    }
                }
            }
            return ['categoryobj' => json_encode($courseCategories)];
        } catch (Exception $e) {
            throw $e;
        }
    }
    /**
     * Describes the return value of the {@see self::execute()} method.
     *
     * @return external_description
     */
    public static function execute_returns(): external_description
    {
        return \local_soluttolms_core\external\getcourses_by_token::execute_returns();
    }
}
