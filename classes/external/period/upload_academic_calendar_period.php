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
 * Class definition for the local_bulk_update_teachers_disponibility external function.
 *
 * @package    local_grupomakro_core
 * @copyright  2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\period;

require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
defined('MOODLE_INTERNAL') || die();

use context_system;
use external_api;
use external_description;
use external_function_parameters;
use external_single_structure;
use external_value;
use stdClass;
use Exception;


// require_once $CFG->libdir . '/externallib.php';

/**
 * External function 'local_bulk_update_teachers_disponibility' implementation.
 *
 * @package     local_grupomakro_core
 * @category    external
 * @copyright   2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class upload_academic_calendar_period extends external_api {

    /**
     * Describes parameters of the {@see self::execute()} method.
     *
     * @return external_function_parameters
     */
     public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'contextId' => new external_value(PARAM_INT, 'Excel File context Id',VALUE_REQUIRED),    
                'itemId' => new external_value(PARAM_INT, 'Excel File item Id',VALUE_REQUIRED),    
                'filename' => new external_value(PARAM_TEXT, 'Excel File name',VALUE_REQUIRED),    
            ]
        );
    }

    /**
     * TODO describe what the function actually does.
     *
     * @param int $userid
     * @return mixed TODO document
     */
    public static function execute(
            $contextId,$itemId,$filename
        ) {
            
        $params = self::validate_parameters(self::execute_parameters(), [
            'contextId' => $contextId,
            'itemId' =>$itemId,
            'filename'=>$filename,
        ]);

        
        try{
            $fs = get_file_storage();
            $academicCalendarPeriod = $fs->get_file($params['contextId'],'user','draft',$params['itemId'],'/',$params['filename']);
            
            if (!$academicCalendarPeriod) {
                throw new Exception('File not found.');
            }
            
            $results = parse_academic_calendar_period_excel($academicCalendarPeriod);

            return ['results' => json_encode($results)];
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
                'status' => new external_value(PARAM_INT, '1 if success, -1 otherwise',VALUE_DEFAULT,1),
                'results' => new external_value(PARAM_RAW, 'Bulk update results',VALUE_DEFAULT,null),
                'message' => new external_value(PARAM_TEXT, 'The error message or Ok.',VALUE_DEFAULT,'ok'),
            )
        );
    }
}
