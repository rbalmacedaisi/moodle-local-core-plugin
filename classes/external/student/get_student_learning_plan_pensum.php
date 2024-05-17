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
require_once($CFG->dirroot . '/grade/querylib.php');

/**
 * External function 'local_grupomakro_get_student_learning_plan_pensum' implementation.
 *
 * @package     local_grupomakro_core
 * @category    external
 * @copyright   2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_student_learning_plan_pensum extends external_api
{

    const STATUS_LABEL = [
        0 => 'No disponible',
        1 => 'Disponible',
        2 => 'Cursando',
        3 => 'Aprobada',
        4 => 'Aprobada',
        5 => 'Reprobada',
        6 => 'Revalida',
        7 => 'Reprobado',
    ];

    const STATUS_COLOR = [
        0 => '#5e35b1',
        1 => '#1e88e5',
        2 => '#11d1bf',
        3 => '#0cce7b',
        4 => '#0cce7b',
        5 => '#ec407a',
        6 => '#ec407a',
        7 => '#ec407a',
    ];

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
                'learningPlanId' => new external_value(PARAM_TEXT, 'ID of the learningPlan.', VALUE_REQUIRED)
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
            'learningPlanId' => $learningPlanId
        ]);

        global $DB;
        try {
            $userPensumCourses = $userPensumCourses = $DB->get_records_sql(
                "
                SELECT gcp.*, lpc.position
                FROM {gmk_course_progre} gcp
                JOIN {local_learning_courses} lpc ON lpc.courseid =  gcp.courseid 
                WHERE gcp.userid = :userid AND gcp.learningplanid = :learningplanid 
                AND lpc.learningplanid = :lpid
                ORDER BY lpc.position ASC",
                ['userid' => $params['userId'], 'learningplanid' => $params['learningPlanId'], 'lpid' => $params['learningPlanId']]
            );

            $groupedUserPensumCourses = [];
            foreach ($userPensumCourses as $userPensumCourse) {
                $periodName = $DB->get_record('local_learning_periods', ['id' => $userPensumCourse->periodid]);

                $course = get_course($userPensumCourse->courseid);
                $userPensumCourse->coursename = $course->fullname;
                $userPensumCourse->periodname = $periodName->name;
                $userPensumCourse->statusLabel = self::STATUS_LABEL[$userPensumCourse->status];
                $userPensumCourse->statusColor = self::STATUS_COLOR[$userPensumCourse->status];
                $userPensumCourse->prerequisites = json_decode($userPensumCourse->prerequisites);
                $userPensumCourse->grade = grade_get_course_grade($params['userId'], $userPensumCourse->courseid)->str_grade;
                foreach ($userPensumCourse->prerequisites as $prerequisite) {
                    $completion = new \completion_info(get_course($prerequisite->id));
                    $prerequisite->completed = $completion->is_course_complete($params['userId']);
                }
                if (!array_key_exists($userPensumCourse->periodid, $groupedUserPensumCourses)) {
                    $groupedUserPensumCourses[$userPensumCourse->periodid]['id'] = $userPensumCourse->periodid;
                    $groupedUserPensumCourses[$userPensumCourse->periodid]['periodName'] = $userPensumCourse->periodname;
                }
                $groupedUserPensumCourses[$userPensumCourse->periodid]['courses'][] = $userPensumCourse;
            }
            return ['pensum' => json_encode($groupedUserPensumCourses)];
        } catch (Exception $e) {
            return ['status' => -1, 'message' => $e->getMessage()];
        }
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
                'pensum' => new external_value(PARAM_RAW, 'json encode object with the pensum info', VALUE_DEFAULT, null),
                'message' => new external_value(PARAM_TEXT, 'The error message or ok.', VALUE_DEFAULT, 'ok'),
            )
        );
    }
}
