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
class create_student_user extends external_api {

    /**
     * Describes parameters of the {@see self::execute()} method.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'username' => new external_value(PARAM_TEXT, 'The id of the contract'),
                'firstname' => new external_value(PARAM_TEXT, 'The course in the contract'),
                'lastname' => new external_value(PARAM_TEXT, 'The course in the contract'),
                'email' => new external_value(PARAM_TEXT, 'The course in the contract'),
                'contractId'=>new external_value(PARAM_TEXT, 'The course in the contract'),
                'courseId'=>new external_value(PARAM_TEXT, 'The course in the contract'),
                
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
            string $username,
            string $firstname,
            string $lastname,
            string $email,
            string $contractId,
            string $courseId
        ) {
        global $DB,$USER;
        
        try{
            
            if($DB->get_record('user', ['username'=>$username])){
                throw new Exception('Ya existe un usuario con este numero de documento');
            }
            if($existingUser = $DB->get_record('user', ['email'=>$email])){
                throw new Exception('Ya existe un usuario con este correo electrónico');
            }

            $newUser = new stdClass();
            $newUser->username=  $username;
            $newUser->firstname=  $firstname;
            $newUser->lastname=  $lastname;
            $newUser->email=  $email;
            
            $newUser->id = create_student_user($newUser);
            
            $createContractResults = create_contract_user(['userId'=>$newUser->id,'contractId'=>$contractId,'courseIds'=>$courseId])[$newUser->id];
            
            if(count($createContractResults['failure'])>0){
                throw new Exception($createContractResults['failure'][0]['message']);
            }
            
            return ['contractEnrolResult' => 1, 'message'=>'ok'];
        }
        
        catch (Exception $e) {
            return ['contractEnrolResult' =>-1,'message' => $e->getMessage()];
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
                'contractEnrolResult' => new external_value(PARAM_INT, 'The ID of the delete class or -1 if there was an error.'),
                'message' => new external_value(PARAM_TEXT, 'The error message or Ok.'),
            )
        );
    }
}
