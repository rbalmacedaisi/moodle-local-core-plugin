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


namespace local_grupomakro_core\external\period;
 
use stdClass;
use external_api;
use external_description;
use external_function_parameters;
use external_single_structure;
use external_value;
use Exception;


defined('MOODLE_INTERNAL') || die;

require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

class close_current_period extends external_api {
    
    public static function execute_parameters(): external_function_parameters{
         return new external_function_parameters([]);
    }
    
    /**
     * Get data for the monthly calendar view.
     *
     * @param int $year The year to be shown
     * @return  array
     */
    public static function execute() {
        global $DB, $USER, $PAGE;
        
        try{
            $periodClosureResult = close_current_period();
            
            return ['status'=>1];
        }catch (Exception $e){
            return ['status'=>-1,'message'=>$e->getMessage()];
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
                'status' =>new external_value(PARAM_INT, '1 or -1 if success/error'),
                'message' => new external_value(PARAM_TEXT, 'The error message or Ok.',VALUE_DEFAULT,'ok'),
            )
        );
    }
}