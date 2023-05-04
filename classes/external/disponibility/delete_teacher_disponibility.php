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
 * Class definition for the local_grupomakro_delete_teacher_disponibility external function.
 *
 * @package    local_grupomakro_core
 * @copyright  2023 Solutto Consulting <devs@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\disponibility;

use context_system;
use external_api;
use external_description;
use external_function_parameters;
use external_single_structure;
use external_multiple_structure;
use external_value;

defined('MOODLE_INTERNAL') || die();

require_once $CFG->libdir . '/externallib.php';
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->dirroot.'/lib/moodlelib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/lib.php');
/**
 * External function 'local_grupomakro_delete_teacher_disponibility' implementation.
 *
 * @package     local_grupomakro_core
 * @category    external
 * @copyright   2023 Solutto Consulting <devs@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class delete_teacher_disponibility extends external_api {

    /**
     * Describes parameters of the {@see self::execute()} method.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'instructorId' => new external_value(PARAM_INT, 'ID of the instructor', VALUE_REQUIRED),
            ],
            'Parameters for deleting instructor availability'
        );
    }
    /**
     * TODO describe what the function actually does.
     *
     * @param int instructorId
     * @return mixed TODO document
     */
    public static function execute(
            $instructorId
        ) {
        
        try{
            // Validate the parameters passed to the function.
            $params = self::validate_parameters(self::execute_parameters(), [
                'instructorId' => $instructorId,
            ]);
            
            // Global variables.
            global $DB;
            
            $instructorLearningPlanUserIds = $DB->get_records('local_learning_users', ['userid'=>$instructorId]);
            $instructorAsignedClasses = array();
            foreach($instructorLearningPlanUserIds as $instructorLearningPlanUserId){
               $instructorAsignedClasses = array_merge($instructorAsignedClasses,grupomakro_core_list_classes(['instructorid'=>$instructorLearningPlanUserId->id]));
            }
            foreach($instructorAsignedClasses as $instructorAsignedClass){
                \local_grupomakro_core\external\gmkclass\delete_class::execute($instructorAsignedClass->id);
            }
            
            $deleteDisponibilityRecord = $DB->delete_records('gmk_teacher_disponibility',['userid'=>$instructorId]);

            // Return the result.
            return ['status' => $deleteDisponibilityRecord, 'message' => 'ok'];
        }
        catch (Exception $e) {
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
                'status' => new external_value(PARAM_INT, 'The ID of the delete class or -1 if there was an error.'),
                'message' => new external_value(PARAM_TEXT, 'The error message or Ok.'),
            )
        );
    }
}
