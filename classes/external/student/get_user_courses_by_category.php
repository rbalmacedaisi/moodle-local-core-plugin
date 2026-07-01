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
use local_sc_learningplans\local\credit_resolver;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/pages/absence_helpers.php');
require_once($CFG->dirroot . '/local/sc_learningplans/classes/local/credit_resolver.php');

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
            $userGmkCourseProgress = $DB->get_records('gmk_course_progre', ['userid' => $params['userid']], '', 'courseid,learningplanid,progress,credits');
            $courseids = [];
            foreach ($courseCategories as $courseCategory) {
                foreach ($courseCategory->courses as $course) {
                    $courseids[] = (int)$course->id;
                }
            }
            $passedmap = gmk_get_user_passed_course_map_fast((int)$params['userid'], $courseids, 70.0);

            foreach ($courseCategories as &$courseCategory) {
                foreach ($courseCategory->courses as &$course) {
                    $courseProgre = isset($userGmkCourseProgress[$course->id]) ? $userGmkCourseProgress[$course->id] : null;
                    $progress = $courseProgre ? $courseProgre->progress : 0;
                    
                    // [VIRTUAL FALLBACK] Fast direct grade check (no grade tree traversal).
                    if ($progress < 100 && !empty($passedmap[(int)$course->id])) {
                        $progress = 100;
                    }

                    $course->progress = (float)$progress;

                    // [FIX] Resolve credits from the canonical per-(plan, course) store,
                    // with the per-student snapshot and the legacy junction as fallbacks.
                    $planid = $courseProgre ? (int)$courseProgre->learningplanid : 0;
                    $resolved = credit_resolver::resolve($planid, (int)$course->id);
                    if ($resolved <= 0 && $courseProgre && !empty($courseProgre->credits)) {
                        $resolved = (int)$courseProgre->credits;
                    }
                    if ($resolved <= 0) {
                        $resolved = (int)$DB->get_field(
                            'local_learning_courses',
                            'credits',
                            ['courseid' => $course->id],
                            IGNORE_MULTIPLE
                        );
                    }
                    $course->credits = $resolved;

                    // Absence alert payload (per-class, max severity).
                    $absence = absd_get_course_absence_for_user((int)$params['userid'], (int)$course->id);
                    $course->absence = $absence === null ? [
                        'count'             => 0,
                        'level'             => 0,
                        'blocked'           => false,
                        'classid'           => 0,
                        'info_dismissed'    => false,
                        'warning_dismissed' => false,
                    ] : $absence;
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
