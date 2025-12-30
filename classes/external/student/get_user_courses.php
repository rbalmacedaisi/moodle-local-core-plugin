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
 * Class definition for the local_grupomakro_get_user_courses external function.
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
require_once($CFG->dirroot . '/enrol/externallib.php');

/**
 * External function 'local_grupomakro_get_user_courses' implementation.
 *
 * @package     local_grupomakro_core
 * @category    external
 * @copyright   2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_user_courses extends external_api
{

    /**
     * Describes parameters of the {@see self::execute()} method.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters
    {
        return \core_enrol_external::get_users_courses_parameters();
    }

    /**
     * TODO describe what the function actually does.
     *
     * @param string id
     * @return mixed TODO document
     */
    public static function execute(
        string $userId
    ) {
        // Validate the parameters passed to the function.
        $params = self::validate_parameters(self::execute_parameters(), [
            'userid' => $userId,
        ]);
        global $DB;
        try {
            $userCourses = \core_enrol_external::get_users_courses($params['userid'], false);
            $userGmkCourseProgress = $DB->get_records('gmk_course_progre', ['userid' => $params['userid']], '', 'courseid,progress,credits');
            foreach ($userCourses as &$course) {
                $courseProgre = isset($userGmkCourseProgress[$course['id']]) ? $userGmkCourseProgress[$course['id']] : null;
                $progress = $courseProgre ? $courseProgre->progress : 0;

                // [VIRTUAL FALLBACK] Check gradebook directly if progress is not 100.
                if ($progress < 100) {
                    $gradeObj = grade_get_course_grade($params['userid'], $course['id']);
                    if ($gradeObj && $gradeObj->grade >= 70) {
                        $progress = 100;
                    }
                }

                $course['progress'] = (float)$progress;
                
                // [FIX] Populate credits from progress record or fallback.
                if ($courseProgre && !empty($courseProgre->credits)) {
                    $course['credits'] = (int)$courseProgre->credits;
                } else {
                     $course['credits'] = (int)$DB->get_field('local_learning_courses', 'credits', ['courseid' => $course['id']], IGNORE_MULTIPLE);
                }
            }
            return $userCourses;
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
        return \core_enrol_external::get_users_courses_returns();
    }
}
