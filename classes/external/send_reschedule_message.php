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
 * Class definition for the local_grupomakro_delete_class external function.
 *
 * @package    local_grupomakro_core
 * @copyright  2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external;

use context_system;
use external_api;
use external_description;
use external_function_parameters;
use external_single_structure;
use external_value;
use stdClass;

defined('MOODLE_INTERNAL') || die();

// require_once $CFG->libdir . '/externallib.php';
// require_once($CFG->libdir . '/filelib.php');
// require_once $CFG->dirroot . '/group/externallib.php';
//require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir.'/messagelib.php');


        // Include messaging library
/**
 
 * External function 'local_grupomakro_delete_class' implementation.
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
                'message' => new external_value(PARAM_TEXT, ''),
                'instructorId' => new external_value(PARAM_TEXT, ''),
                'classId' => new external_value(PARAM_TEXT, ''),
                'roleUser' => new external_value(PARAM_TEXT, '')

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
        string $message, 
        string $instructorId,
        string $classId,
        string $roleUser
        ){  
        global $DB;
        
        
        $userInfo = $DB->get_record('user',['id'=>$instructorId]);
        $userName = $userInfo->username;    
        
        $classInfo = $DB->get_record('gmk_class',['id'=>$classId]);
        $idClass = $classInfo->id;
        
        
        
        
        $userRole= $DB->get_records('role_assignments',['roleid'=>$roleUser]);
        foreach($userRole as $item){
            $itemm = $item->roleid;
            // print_object($itemm);
            if ($itemm===$roleUser){
                foreach($userName as $mail){
                    if(mail($userName, $message->subject,$message->fullmessage)) {
                       echo 'Correo enviado correctamente';
                    } else {
                       echo 'Error al enviar el correo';
                    }
                }
                die();
              print_object('Tienes un mensaje de: '.$userName.' de la clase ['.$idClass.']');
              $message = new \core\message\message();
              $message->userfrom = \core_user::get_noreply_user(); // Set the message sender
              $message->component = 'moodle'; // Set the message component
              $message->name = 'instantmessage'; // Set the message name
              $message->subject = 'New message notification'; // Set the message subject
              $message->fullmessage = 'You have a new message notification in Moodle'; 
              $message->fullmessageformat = FORMAT_PLAIN;
              
            //   print_object($message);
               
            }else{
                echo'doesnt works';
            }
        }
        $message = new \core\message\message();
        $message->component = 'moodle'; // Set the message component
        $message->name = 'instantmessage'; // Set the message name
        $message->userfrom = \core_user::get_noreply_user(); // Set the message sender
        $messageid = message_send($message);
        return ['status' => $deleteClassId, 'message' => 'ok'];
        die();
        // $typeUser= $userRole->roleid;
    
        //     if($typeUser==5){
        //         echo("works");
        //     }
        //     else{
        //         echo("doesnt works");
        //     }
        // die();
        // $typeUser = $userRole->roleid;
        // print_object($typeUser);
        // die();
        // echo($typeUser);
        // die();
        
        // Validate the parameters passed to the function.
       // $params = self::validate_parameters(self::execute_parameters(), [
         //   'message' => $message,
        // ]);
        
        
        // Global variables


        // Create a new message object
        $message = new \core\message\message();
        $message->component = 'moodle'; // Set the message component
        $message->name = 'instantmessage'; // Set the message name
        $message->userfrom = \core_user::get_noreply_user(); // Set the message sender
        
        $message->userto = $USER; // Set the message recipient
        $message->subject = 'New message notification'; // Set the message subject
        $message->fullmessage = 'You have a new message notification in Moodle'; // Set the message body
        $message->fullmessageformat = FORMAT_PLAIN; // Set the message body format
        
        // Send the message notification
        $messageid = message_send($message);
        // Return the result.
        return ['status' => $deleteClassId, 'message' => 'ok'];
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
