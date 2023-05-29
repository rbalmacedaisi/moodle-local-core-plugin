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
 * Class definition for the local_grupomakro_create_institution external function.
 *
 * @package    local_grupomakro_core
 * @copyright  2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\contractUser;

use context_system;
use external_api;
use external_description;
use external_function_parameters;
use external_single_structure;
use external_value;
use stdClass;
use Exception;



defined('MOODLE_INTERNAL') || die();

require_once $CFG->libdir . '/externallib.php';
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

/**
 * External function 'local_grupomakro_create_institution' implementation.
 *
 * @package     local_grupomakro_core
 * @category    external
 * @copyright   2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_contract_user extends external_api {

    /**
     * Describes parameters of the {@see self::execute()} method.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'userId' => new external_value(PARAM_TEXT, 'The id of the user'),
                'contractId' => new external_value(PARAM_TEXT, 'The id of the contract'),
                'courseIds' => new external_value(PARAM_TEXT, 'The courses in the contract'),
                
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
            string $userId,
            string $contractId,
            string $courseIds
        ) {
        global $DB,$USER;
        
        try{
            $enrolplugin = enrol_get_plugin('manual');
            $courseIds = explode(',',$courseIds);
            
            $contractUserRecords = new stdClass();
            $contractUserRecords->failure = array();
            $contractUserRecords->success = array();
            
            foreach($courseIds as $courseId){
                $instance = get_manual_enroll($courseId);
                if($DB->get_record('gmk_contract_user',['userid'=>$userId, 'contractid'=>$contractId, 'courseid'=>$courseId]) || !$instance){
                    $contractUserRecords->failure[]=$courseId;
                    continue;
                }
                $enrolled = $enrolplugin->enrol_user($instance, $userId, 5);
                
                $newContractUserRecord = new stdClass();
                $newContractUserRecord->userid = $userId;
                $newContractUserRecord->contractid = $contractId;
                $newContractUserRecord->courseid = $courseId;
                $newContractUserRecord->timecreated = time();
                $newContractUserRecord->timemodified = time();
                $newContractUserRecord->usermodified = $USER->id;
                
                $newContractUserRecord->id = $DB->insert_record('gmk_contract_user',$newContractUserRecord);
                $contractUserRecords->success[]=$courseId;
            }
            
            $message = 'ok';
            if(count($contractUserRecords->failure)>0){
                $message = 'Los siguientes cursos no se pudieron agregar al contrato, puede que ya esten inscritos o haya habido un error: ';
                foreach($contractUserRecords->failure as $failedCourseId){
                    $message=$message.$DB->get_record('course',['id'=>$failedCourseId])->fullname.'('.$failedCourseId.'), ';
                }

                if(count($contractUserRecords->success)===0){
                    throw new Exception($message);
                }
            }
            return ['contractUserId' => 1, 'message'=>$message];
        }
        
        catch (Exception $e) {
            return ['contractUserId' => -1, 'message' => $e->getMessage()];
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
                'contractUserId' => new external_value(PARAM_INT, 'The ID of the delete class or -1 if there was an error.'),
                'message' => new external_value(PARAM_TEXT, 'The error message or Ok.'),
            )
        );
    }
}
