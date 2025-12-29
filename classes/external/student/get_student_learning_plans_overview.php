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
 * Class definition for the local_grupomakro_get_student_learning_plans_overview external function.
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
 * External function 'local_grupomakro_get_student_learning_plans_overview' implementation.
 *
 * @package     local_grupomakro_core
 * @category    external
 * @copyright   2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_student_learning_plans_overview extends external_api
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
                'userId' => new external_value(PARAM_TEXT, 'ID of the student.', VALUE_REQUIRED)
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
        $userId
    ) {

        // Validate the parameters passed to the function.
        $params = self::validate_parameters(self::execute_parameters(), [
            'userId' => $userId,
        ]);

        try {
            global $DB;
            $studentRoleId = $DB->get_field('role', 'id', ['shortname' => 'student']);
            $learningPlansOverview = $DB->get_records_sql(
                'SELECT lp.id, lp.name, lp.coursecount, lp.periodcount
                 FROM {local_learning_users} lpu
                 JOIN {local_learning_plans} lp ON lp.id = lpu.learningplanid
                 WHERE lpu.userid = :userid AND lpu.userroleid = :studentroleid',
                [
                    'userid' => $params['userId'],
                    'studentroleid' => $studentRoleId
                ]
            );

            foreach ($learningPlansOverview as $learningPlan) {
                $learningPlan->progress = self::get_learning_plan_progress($params['userId'], $learningPlan->id);
                $learningPlan->imageUrl = get_learning_plan_image($learningPlan->id);
            }
            return ['overview' => json_encode($learningPlansOverview)];
        } catch (Exception $e) {
            return ['status' => -1, 'message' => $e->getMessage()];
        }
    }

    public function get_learning_plan_progress($userId, $learningPlanId)
    {
        global $DB;
        $userLearningPlanProgressRecords = $DB->get_records('gmk_course_progre', ['userid' => $userId, 'learningplanid' => $learningPlanId], '', 'courseid,credits');
        $totalWeightedCompletion = 0;
        $totalCredits = 0;
        foreach ($userLearningPlanProgressRecords as $userLearningPlanProgressRecord) {
            $course = get_course($userLearningPlanProgressRecord->courseid);
            $courseCompletionInfo = new \completion_info($course);
            
            // Criteria 1: Native Moodle Completion
            $isComplete = $courseCompletionInfo->is_course_complete($userId);
            
            // Criteria 2: Approved Status in GMK (Local)
            if (!$isComplete && in_array($userLearningPlanProgressRecord->status, [COURSE_COMPLETED, COURSE_APPROVED])) {
                $isComplete = true;
            }
            
            // Criteria 3: Approved Grade (Local)
            if (!$isComplete) {
                $gradeObj = grade_get_course_grade($userId, $userLearningPlanProgressRecord->courseid);
                if ($gradeObj && $gradeObj->grade >= 70) {
                    $isComplete = true;
                }
            }

            $totalWeightedCompletion += ($isComplete ? 1 : 0) * $userLearningPlanProgressRecord->credits;
            $totalCredits += $userLearningPlanProgressRecord->credits;
        }
        return $totalCredits > 0 ? round(($totalWeightedCompletion / $totalCredits) * 100) : 0;
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
                'overview' => new external_value(PARAM_RAW, 'json encode object with the learning plans overview', VALUE_DEFAULT, null),
                'message' => new external_value(PARAM_TEXT, 'The error message or ok.', VALUE_DEFAULT, 'ok'),
            )
        );
    }
}
