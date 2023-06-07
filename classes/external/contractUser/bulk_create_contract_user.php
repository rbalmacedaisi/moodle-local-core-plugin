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
use external_multiple_structure;
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
class bulk_create_contract_user extends external_api {

    /**
     * Describes parameters of the {@see self::execute()} method.
     *
     * @return external_function_parameters
     */
     public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'contextId' => new external_value(PARAM_INT, 'The id of the contract user'),    
                'itemId' => new external_value(PARAM_INT, 'The id of the contract user'),    
                'filename' => new external_value(PARAM_TEXT, 'The id of the contract user'),    
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
        global $DB,$USER;
        
        try{
            $fs = get_file_storage();
            $file = $fs->get_file($contextId,'user','draft',$itemId,'/',$filename);

            if (!$file) {
                throw new Exception('File not found.');
            }
            // File found. Read the content of the file.
            $filecontent = $file->get_content();
            $users = array();
            $lines = explode(PHP_EOL, $filecontent);
            $header = str_getcsv(array_shift($lines));
            foreach ($lines as $line) {
                $data = str_getcsv($line);
                $row = new stdClass();
                foreach ($header as $i => $column) {
                    $row->$column = $data[$i];
                }
                $users[] = $row;
            }
            $results = array();
            $results['errors']=[];
            foreach($users as $index =>$user){
                if(!$DB->get_record('gmk_institution_contract',['id'=>$user->contractid])){
                    $results['errors'][] = ['index'=>$index,'error'=>'El id del contrato no existe.'];
                    continue;
                }
                $userInfo = $DB->get_record('user',['username'=>$user->user_document]);
                if(!$userInfo){

                    if($DB->get_record('user',['email'=>$user->user_email])){
                        $results['errors'][] = ['index'=>$index,'error'=>'El correo electrónico ya esta asignado'];
                        continue;
                    }
            
                    $newUser = new stdClass();
                    $newUser->username=  $user->user_document;
                    $newUser->firstname=  $user->user_firstname;
                    $newUser->lastname=  $user->user_lastname;
                    $newUser->email=  $user->user_email;
                    $newUser->id = create_student_user($newUser);
                    
                    $userInfo = $DB->get_record('user',['id'=>$newUser->id]);
                }
                
                $createContractResults = create_contract_user(['userId'=>$userInfo->id,'contractId'=>$user->contractid,'courseIds'=>str_replace('-',',',$user->courseid)])[$userInfo->id];
                foreach($createContractResults['failure'] as $failure){
                    $results['errors'][] = ['index'=>$index+1,'error'=>$failure['message']];
                }
                $results[$userInfo->id]= $createContractResults;
            }

            $deleted = $file->delete();

            return ['result' => json_encode($results), 'message'=>'ok'];
        }
        
        catch (Exception $e) {
            return ['result' => null, 'message' => $e->getMessage()];
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
                'result' => new external_value(PARAM_RAW, 'Results of the enrolments.'),
                'message' => new external_value(PARAM_TEXT, 'The error message or Ok.'),
            )
        );
    }
}
