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
 * Class definition for the local_grupomakro_get_learning_plans_data external function.
 *
 * @package    local_grupomakro_core
 * @copyright  2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\learningPlan;

use external_api;
use external_description;
use external_function_parameters;
use Exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->dirroot . '/local/sc_learningplans/external/learning/get_learning_plans.php');

/**
 * External function 'local_grupomakro_get_learning_plans_data' implementation.
 *
 * @package     local_grupomakro_core
 * @category    external
 * @copyright   2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_learning_plans_data extends external_api
{

    /**
     * Describes parameters of the {@see self::execute()} method.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters
    {
        return \get_learning_plans_external::get_learning_plans_parameters();
    }

    /**
     * TODO describe what the function actually does.
     *
     * @param string id
     * @return mixed TODO document
     */
    public static function execute($page, $resultsperpage)
    {

        // Validate the parameters passed to the function.
        $params = self::validate_parameters(self::execute_parameters(), [
            'page' => $page,
            'resultsperpage' => $resultsperpage,
        ]);
        global $DB, $USER;
        try {
            $learningPlansData = \get_learning_plans_external::get_learning_plans($params['page'], $params['resultsperpage']);

            foreach ($learningPlansData['learningplans'] as &$learningPlan) {
                $userGmkCourseProgress = $DB->get_records('gmk_course_progre', ['userid' => $USER->id, 'learningplanid' => $learningPlan['learningplanid']], '', 'courseid,progress,credits');
                foreach ($learningPlan['periodsdata'] as &$period) {
                    $courseTypes = ['requiredcourses', 'optionalcourses'];
                    foreach ($courseTypes as $type) {
                        if (!isset($period[$type])) {
                            continue;
                        }
                        foreach ($period[$type] as &$course) {
                            $courseProgre = isset($userGmkCourseProgress[$course['id']]) ? $userGmkCourseProgress[$course['id']] : null;
                            $progress = $courseProgre ? $courseProgre->progress : 0;
                            
                            // [VIRTUAL FALLBACK] Check gradebook directly if progress is not 100.
                            if ($progress < 100) {
                                $gradeObj = grade_get_course_grade($USER->id, $course['id']);
                                if ($gradeObj && $gradeObj->grade >= 70) {
                                    $progress = 100;
                                }
                            }

                            $course['realprogress'] = $progress;
                            $course['showprogress'] = $progress;
                            $course['progress'] = (float)$progress; // [FIX] Exact key 'progress'
                            
                            // [FIX] Populate credits from progress record or fallback to local_learning_courses.
                            if ($courseProgre && !empty($courseProgre->credits)) {
                                $course['credits'] = (int)$courseProgre->credits;
                            } else {
                                 // Fallback to the credits defined in the learning plan course.
                                 $lpCourse = $DB->get_record('local_learning_courses', ['courseid' => $course['id'], 'learningplanid' => $learningPlan['learningplanid']], 'credits');
                                 $course['credits'] = $lpCourse ? (int)$lpCourse->credits : 0;
                            }
                        }
                    }
                }
            }
            return $learningPlansData;
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
        return \get_learning_plans_external::get_learning_plans_returns();
    }
}
