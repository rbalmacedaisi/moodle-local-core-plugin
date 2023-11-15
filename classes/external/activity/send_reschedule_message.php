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
 * Class definition for the local_grupomakro_send_reschedule_message_class external function.
 *
 * @package    local_grupomakro_core
 * @copyright  2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\activity;

use context_system;
use external_api;
use external_description;
use external_function_parameters;
use external_single_structure;
use external_value;
use stdClass;
use Exception;
use core\message\message;
use core_user;



defined('MOODLE_INTERNAL') || die();


require_once($CFG->libdir.'/messagelib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->dirroot .'/mod/bigbluebuttonbn/lib.php');

// Include messaging library
/**
 
 * External function 'local_grupomakro_send_reschedule_message' implementation.
 *
 * @package     local_grupomakro_core
 * @category    external
 * @copyright   2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_reschedule_message extends external_api {

    /**
     * Describes parameters of the {@see self::execute()} method.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'instructorId' => new external_value(PARAM_TEXT, ''),
                'classId' => new external_value(PARAM_TEXT, ''),
                'causes' => new external_value(PARAM_TEXT, ''),
                'moduleId' => new external_value(PARAM_TEXT, ''),
                'originalDate' => new external_value(PARAM_TEXT, ''),
                'originalHour' => new external_value(PARAM_TEXT, ''),
                'proposedDate' => new external_value(PARAM_TEXT, ''),
                'proposedHour' => new external_value(PARAM_TEXT, ''),
                'sessionId' => new external_value(PARAM_TEXT, '',VALUE_DEFAULT,null),
            ]
        );
    }

    /**
     * Sends a rescheduling message to the administrators.
     *
     * @param string $$message
     * @param string $instructorId
     * @param string $classId
     * 
     * @return bool True on success, false on failure.
     *
     * @throws MyException If an error occurs.
     */
    public static function execute(
        string $instructorId,
        string $classId,
        string $causes, 
        string $moduleId,
        string $originalDate,
        string $originalHour,
        string $proposedDate,
        string $proposedHour,
        string $sessionId=null
        ){  
        global $DB,$OUTPUT,$CFG;

        try{
            
            $causes = explode(',',$causes);
            $causeNames=array();
            foreach($causes as $cause){
                $causeNames[]=$DB->get_record('gmk_reschedule_causes',['id'=>$cause])->causename;
            }

            $userInfo = $DB->get_record('user',['id'=>$instructorId]);
            $classInfo= list_classes(['id'=>$classId])[$classId];
            $instructorFullName = $userInfo->firstname.' '.$userInfo->lastname;

            $envDic=['development'=>'-dev','staging'=>'-staging','production'=>''];
            
            $rescheduleUrl=  'https://grupomakro'.$envDic[$CFG->environment_type].'.soluttolabs.com/local/grupomakro_core/pages/editclass.php?class_id='.$classId.'&moduleId='.$moduleId.'&sessionId='.$sessionId.'&proposedDate='.$proposedDate.'&proposedHour='.$proposedHour;
            
            // Set the html message----------------------------------------------------------------------------------------------------------------------------------------------------
            
            $strData = new stdClass();
            $strData->instructorFullName=$instructorFullName;
            $strData->causeNames=implode(', ',$causeNames);
            $strData->originalDate=$originalDate;
            $strData->originalHour=$originalHour;
            $strData->proposedDate=$proposedDate;
            $strData->proposedHour=$proposedHour;
            $strData->rescheduleUrl=$rescheduleUrl;
            $strData->name=$classInfo->name;
            $strData->coreCourseName=$classInfo->coreCourseName;
            $strData->typelabel=$classInfo->typelabel;

            $messageBody = get_string('msg:send_reschedule_message:body','local_grupomakro_core', $strData);
            $messageHtml = $OUTPUT->render_from_template( 'local_grupomakro_core/messages/reschedule_message',array('messageBody'=>$messageBody));

            // --------------------------------------------------------------------------------------------------------------------------------------------------------------------------
    
            // message test (will be deleted)
            $messageDefinition = new message();
            $messageDefinition->component = 'local_grupomakro_core'; // Set the message component
            $messageDefinition->name ='send_reschedule_message'; // Set the message name
            $messageDefinition->userfrom = core_user::get_noreply_user(); // Set the message sender
            $messageDefinition->subject = get_string('msg:send_reschedule_message:subject','local_grupomakro_core'); // Set the message subject
            $messageDefinition->fullmessage = $messageHtml; // Set the message body
            $messageDefinition->fullmessageformat = FORMAT_HTML; // Set the message body format
            $messageDefinition->fullmessagehtml = $messageHtml;
            $messageDefinition->notification = 1;
            $messageDefinition->contexturl =$rescheduleUrl;
            $messageDefinition->contexturlname = get_string('msg:send_reschedule_message:contexturlname','local_grupomakro_core');;
            // -------------------------------
    
            // Find the users that have administrator role---------------------------
    
            $adminIds = array_keys(get_admins());
        
            // Loop the managers array and send the reschedule message-------------------------------------------------------------------------
    
            foreach ($adminIds as $adminId) {
                
                $messageDefinition->userto=$adminId;
                // Send the message notification
                $messageid = message_send($messageDefinition);
            }
            
            // ---------------------------------------------------------------------------------------------------------------------------------

            return ['status' => 1, 'message' => 'ok'];
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
                'status' => new external_value(PARAM_INT, '1 of the message was correctly sended, -1 otherwise.'),
                'message' => new external_value(PARAM_TEXT, 'The error message or Ok.'),
            )
        );
    }
}
