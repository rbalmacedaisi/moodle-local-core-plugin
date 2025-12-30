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
 * Class definition for the local_grupomakro_get_data_by_courses external function.
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
 * External function 'local_grupomakro_get_data_by_courses' implementation.
 *
 * @package     local_grupomakro_core
 * @category    external
 * @copyright   2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_data_by_courses extends external_api
{

    /**
     * Describes parameters of the {@see self::execute()} method.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters
    {
        return \local_soluttolms_core\external\get_data_by_courses::execute_parameters();
    }

    /**
     * TODO describe what the function actually does.
     *
     * @param string id
     * @return mixed TODO document
     */
    public static function execute(
        int $courseid,
        int $userid
    ) {
        // Validate the parameters passed to the function.
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'userid' => $userid,
        ]);
        global $DB;
        try {
            $courseData = \local_soluttolms_core\external\get_data_by_courses::execute($params['courseid'], $params['userid']);
            $courseProgre = $DB->get_record('gmk_course_progre', ['courseid' => $params['courseid'], 'userid' => $params['userid']], 'progress,credits');
            if ($courseProgre) {
                $courseData = json_decode($courseData['coursedata']);
                $progress = $courseProgre->progress;

                // [VIRTUAL FALLBACK] Check gradebook directly if progress is not 100.
                if ($progress < 100) {
                    $gradeObj = grade_get_course_grade($params['userid'], $params['courseid']);
                    if ($gradeObj && $gradeObj->grade >= 70) {
                        $progress = 100;
                    }
                }

                $courseData->progress = (float)$progress;
                $courseData->credits = (int)$courseProgre->credits;
                $courseData = ['coursedata' => json_encode($courseData)];
            } else {
                // Fallback attempt for credits if no progress record exists yet.
                $courseData = json_decode($courseData['coursedata']);
                $courseData->progress = 0;
                $courseData->credits = (int)$DB->get_field('local_learning_courses', 'credits', ['courseid' => $params['courseid']], IGNORE_MULTIPLE);
                $courseData = ['coursedata' => json_encode($courseData)];
            }
            return $courseData;
        } catch (Exception $e) {
            return ['status' => -1, 'error' => true, 'message' => $e->getMessage()];
        }
    }
    /**
     * Describes the return value of the {@see self::execute()} method.
     *
     * @return external_description
     */
    public static function execute_returns(): external_description
    {
        return \local_soluttolms_core\external\get_data_by_courses::execute_returns();
    }
}
