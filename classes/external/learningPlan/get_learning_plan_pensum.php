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
 * Class definition for the local_grupomakro_get_learning_plan_list external function.
 *
 * @package    local_grupomakro_core
 * @copyright  2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\learningPlan;

use context_system;
use external_api;
use external_description;
use external_function_parameters;
use external_single_structure;
use external_value;
use stdClass;
use Exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

/**
 * External function 'local_grupomakro_get_learning_plan_list' implementation.
 *
 * @package     local_grupomakro_core
 * @category    external
 * @copyright   2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_learning_plan_pensum extends external_api {

    /**
     * Describes parameters of the {@see self::execute()} method.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'learningPlanId' => new external_value(PARAM_TEXT, 'ID of the learning plan.',VALUE_REQUIRED)
        ]);
    }

    /**
     * TODO describe what the function actually does.
     *
     * @param string id
     * @return mixed TODO document
     */
    public static function execute($learningPlanId) {
        
        $params = self::validate_parameters(self::execute_parameters(), [
            'learningPlanId' => $learningPlanId,
        ]);
        
        try{
            
            global $DB;
            
            $customFields = [
                'credits' => 'credits',
                't' => 'teoricalhours',
                'p' => 'practicalhours',
                'tc' => 'troncocomun',
                'pre' => 'prerequisites',
            ];
            
            $selectFields = [
                'c.id as courseId',
                'c.fullname as coursefullname',
                'c.shortname as courseshortname',
                'llp.name as periodname',
                'llp.id as periodId',
                'lp.name as learningplanname'
            ];
            
            // Add fields for theoretical and practical hours
            $selectFields[] = '(cf_t.value + cf_p.value) as totalhours';
            
            // Add fields for prerequisite course fullnames
            foreach ($customFields as $fieldShortname => $fieldName) {
                if ($fieldShortname === 'pre') {
                    $selectFields[] = 'GROUP_CONCAT(co.fullname) as prerequisite_fullnames';
                } else {
                    $selectFields[] = "cf_$fieldShortname.value as $fieldName";
                }
            }
            
            $selectClause = implode(', ', $selectFields);
            
            $query = "
                SELECT $selectClause
                FROM {local_learning_courses} lpc
                JOIN {local_learning_periods} llp ON llp.id = lpc.periodid
                JOIN {local_learning_plans} lp ON lp.id = lpc.learningplanid
                JOIN {course} c ON c.id = lpc.courseid
            ";
            
            foreach ($customFields as $fieldShortname => $fieldName) {
                if ($fieldShortname === 'pre') {
                    $query .= "
                        LEFT JOIN {customfield_data} cf_$fieldShortname
                        ON cf_$fieldShortname.instanceid = c.id
                        AND cf_$fieldShortname.fieldid = (SELECT id FROM {customfield_field} WHERE shortname = '$fieldShortname')
                        LEFT JOIN {course} co
                        ON FIND_IN_SET(co.shortname, cf_$fieldShortname.value)
                    ";
                } else {
                    $query .= "
                        LEFT JOIN {customfield_data} cf_$fieldShortname
                        ON cf_$fieldShortname.instanceid = c.id
                        AND cf_$fieldShortname.fieldid = (SELECT id FROM {customfield_field} WHERE shortname = '$fieldShortname')
                    ";
                }
            }
            
            $query .= "
                WHERE lpc.learningplanid = :learningplanid
                GROUP BY c.id
            ";
            
            $learningPlanPensum = $DB->get_records_sql($query, ['learningplanid' => $params['learningPlanId']]);
            // Organize results by periodid
            $groupedResults = [];
            
            foreach ($learningPlanPensum as $course) {
                
                $groupedResults['learningPlanName']=$course->learningplanname;
                
                $periodId = $course->periodid;
                $periodName = $course->periodname;
            
                // Create a period entry if it doesn't exist
                if (!isset($groupedResults['learningPlan'][$periodId])) {
                    $groupedResults['learningPlan'][$periodId] = [
                        'periodId' => $periodId,
                        'periodName' => $periodName,
                        'courses' => [],
                    ];
                }
            
                // Add the course to the courses array of the corresponding period
                $groupedResults['learningPlan'][$periodId]['courses'][] = [
                    'courseId' => $course->courseid,
                    'coursefullname' => $course->coursefullname,
                    'courseshortname' => $course->courseshortname,
                    'totalHours' => $course->totalhours,
                    'teoricalHours' => $course->teoricalhours,
                    'practicalHours' => $course->practicalhours,
                    'credits' => $course->credits,
                    'prerequisite_fullnames' => $course->prerequisite_fullnames? explode(',', $course->prerequisite_fullnames):[],
                    // Add other fields as needed
                ];
            }

            return ['pensum'=>json_encode($groupedResults)];
        }catch (Exception $e) {
            return ['status' => -1, 'message' => $e->getMessage()];
        }
    }


    /**
     * Describes the return value of the {@see self::execute()} method.
     *
     * @return external_description
     */
    public static function execute_returns(): external_description {
        return new external_single_structure(
            array(
                'status' => new external_value(PARAM_TEXT, '1 for success, -1 for failure',VALUE_DEFAULT,1),
                'pensum' => new external_value(PARAM_TEXT, 'json encode object with the active classes array',VALUE_DEFAULT,null),
                'message' => new external_value(PARAM_TEXT, 'The error message or ok.',VALUE_DEFAULT,'ok'),
            )
        );
    }
}
