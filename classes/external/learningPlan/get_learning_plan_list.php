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
class get_learning_plan_list extends external_api {

    /**
     * Describes parameters of the {@see self::execute()} method.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'learningPlanId' => new external_value(PARAM_TEXT, 'ID of the learning plan.',VALUE_DEFAULT,null)
        ]);
    }

    /**
     * TODO describe what the function actually does.
     *
     * @param string id
     * @return mixed TODO document
     */
    public static function execute($learningPlanId) {
        
        // Validate the parameters passed to the function.
        $params = self::validate_parameters(self::execute_parameters(), [
            'learningPlanId' => $learningPlanId,
        ]);
        
        $filters=[];
        if($params['learningPlanId']){
            $filters['id']=$params['learningPlanId'];
        }
        
        try{
            global $DB;
            
            $learningPlans = $DB->get_records('local_learning_plans',$filters);
            $learningPlans = array_values(array_map(function ($learningPlan){
                $learningPlanSummary = new stdClass();
                $learningPlanSummary->id = $learningPlan->id;
                $learningPlanSummary->name = $learningPlan->name;
                $learningPlanSummary->shortname = $learningPlan->shortname;
                $learningPlanSummary->courseCount = $learningPlan->coursecount;
                $learningPlanSummary->periodCount = $learningPlan->periodcount;
                $learningPlanSummary->imageUrl = get_learning_plan_image($learningPlan->id);
                return $learningPlanSummary;
            },$learningPlans));
            
            return ['learningPlans'=>json_encode($learningPlans)];
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
                'learningPlans' => new external_value(PARAM_TEXT, 'json encode object with the active classes array',VALUE_DEFAULT,null),
                'message' => new external_value(PARAM_TEXT, 'The error message or ok.',VALUE_DEFAULT,'ok'),
            )
        );
    }
}
