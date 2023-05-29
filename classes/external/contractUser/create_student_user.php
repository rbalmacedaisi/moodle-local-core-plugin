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
class generate_contract_enrol_link extends external_api {

    /**
     * Describes parameters of the {@see self::execute()} method.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'contractId' => new external_value(PARAM_TEXT, 'The id of the contract'),
                'courseId' => new external_value(PARAM_TEXT, 'The course in the contract'),
                
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
            string $contractId,
            string $courseId
        ) {
        global $DB,$USER;
        
        try{
            if($contractEnrolLinkRecord = $DB->get_record('gmk_contract_enrol_link',['contractid'=>$contractId, 'courseid'=>$courseId])){
                if(time() > $contractEnrolLinkRecord->expirationdate){
                    $DB->delete_records('gmk_contract_enrol_link',['id'=>$contractEnrolLinkRecord->id]);
                }
                else{
                    $url = 'https://grupomakro-dev.soluttolabs.com/local/grupomakro_core/pages/contractenrol.php?token='.$contractEnrolLinkRecord->token;
                    return ['contractEnrolLink' => $url, 'expirationDate'=>date("Y-m-d", $contractEnrolLinkRecord->expirationdate) ,'message' =>'ok'];
                }
            } 
            $contractEnrolLinkToken = md5(uniqid());
            $contractEnrolLinkExpirationDate = time()+259200 ;
            
            
            $contractEnrolLink = new stdClass();
            $contractEnrolLink->contractid = $contractId;
            $contractEnrolLink->courseid = $courseId;
            $contractEnrolLink->token = $contractEnrolLinkToken;
            $contractEnrolLink->expirationdate = $contractEnrolLinkExpirationDate;
            $contractEnrolLink->timecreated = time();
            $contractEnrolLink->timemodified = time();
            $contractEnrolLink->usermodified = $USER->id;
            
            $contractEnrolLink->id = $DB->insert_record('gmk_contract_enrol_link',$contractEnrolLink);
            
            $contractEnrolLink->url = 'https://grupomakro-dev.soluttolabs.com/local/grupomakro_core/pages/contractenrol.php?token='.$contractEnrolLinkToken;
            
            return ['contractEnrolLink' => $contractEnrolLink->url,'expirationDate'=>date("Y-m-d", $contractEnrolLink->expirationdate), 'message'=>'ok'];
        }
        
        catch (Exception $e) {
            return ['contractEnrolLink' => '-1', 'expirationDate'=>'' ,'message' => $e->getMessage()];
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
                'contractEnrolLink' => new external_value(PARAM_TEXT, 'The ID of the delete class or -1 if there was an error.'),
                'expirationDate' => new external_value(PARAM_TEXT, 'The error message or Ok.'),
                'message' => new external_value(PARAM_TEXT, 'The error message or Ok.'),
            )
        );
    }
}
