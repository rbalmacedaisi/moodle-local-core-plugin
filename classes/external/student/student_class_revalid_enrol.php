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
 * Class definition for the local_grupomakro_student_class_enrol external function.
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
use stdClass;
use Exception;
use local_grupomakro_progress_manager;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

/**
 * External function 'local_grupomakro_student_class_enrol ' implementation.
 *
 * @package     local_grupomakro_core
 * @category    external
 * @copyright   2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class student_class_revalid_enrol extends external_api {

    /**
     * Describes parameters of the {@see self::execute()} method.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'progressId' => new external_value(PARAM_TEXT, 'ID of the course progress record.',VALUE_REQUIRED)
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
            $progressId
        ) {
        $params = self::validate_parameters(self::execute_parameters(), [
            'progressId' => $progressId
        ]);

        global $DB;
        try{
            // local_grupomakro_progress_manager::enrol_user_in_revalid_group($params['progressId']);
            local_grupomakro_progress_manager::calculate_learning_plan_user_course_progress(54,103);
            die;
            
        
            return [];
        }catch (Exception $e) {
            return ['status' =>-1,'message' => $e->getMessage()];
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
                'status' => new external_value(PARAM_TEXT, '1 for success, -1 for failure',VALUE_DEFAULT, 1),
                'revalidEnroled'=> new external_value(PARAM_BOOL, 'True if the user was enroled in the course revalid group.',VALUE_DEFAULT,null),
                'message' => new external_value(PARAM_TEXT, 'The error message or ok.',VALUE_DEFAULT, 'ok'),
            )
        );
    }
}
