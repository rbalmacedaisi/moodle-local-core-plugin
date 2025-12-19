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
 * Class definition for the local_grupomakro_get_student_info external function.
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


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->dirroot . '/user/profile/lib.php'); // For profile_load_data function.

/**
 * External function 'local_grupomakro_get_student_info' implementation.
 *
 * @package     local_grupomakro_core
 * @category    external
 * @copyright   2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_student_info extends external_api {

    /**
     * Describes parameters of the {@see self::execute()} method.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
                'page'           => new external_value(PARAM_INT, 'Page of the list.', VALUE_DEFAULT,0),
                'resultsperpage' => new external_value(PARAM_INT, 'Results to show by page.', VALUE_DEFAULT,15),
                'search'         => new external_value(PARAM_RAW, 'Filters by search data users.', VALUE_DEFAULT,'')
            ]);
    }

    /**
     * TODO describe what the function actually does.
     *
     * @param int $userid
     * @return mixed TODO document
     */
    public static function execute($page, $resultsperpage, $search) {
        global $DB;
        static $userData  = array();
        // Validate the parameters passed to the function.
        $params = self::validate_parameters(self::execute_parameters(), [
            'page'           => $page,
            'resultsperpage' => $resultsperpage,
            'search'         => $search
        ]);
        
        $query = 
            'SELECT lpu.id, lpu.currentperiodid as periodid, lpu.currentsubperiodid as subperiodid, lp.id as planid, 
            lp.name as career, u.id as userid, u.email as email, u.idnumber,
            u.firstname as firstname, u.lastname as lastname
            FROM {local_learning_plans} lp
            JOIN {local_learning_users} lpu ON (lpu.learningplanid = lp.id)
            JOIN {user} u ON (u.id = lpu.userid)
            WHERE lpu.userrolename = :userrolename
            ORDER BY u.firstname';

            try {
                $infoUsers = $DB->get_records_sql($query, array('userrolename' => 'student'));
            } catch (Exception $e) {
                // Fallback query if 'currentsubperiodid' column does not exist
                $query = 
                'SELECT lpu.id, lpu.currentperiodid as periodid, lp.id as planid, 
                lp.name as career, u.id as userid, u.email as email, u.idnumber,
                u.firstname as firstname, u.lastname as lastname
                FROM {local_learning_plans} lp
                JOIN {local_learning_users} lpu ON (lpu.learningplanid = lp.id)
                JOIN {user} u ON (u.id = lpu.userid)
                WHERE lpu.userrolename = :userrolename
                ORDER BY u.firstname';
                $infoUsers = $DB->get_records_sql($query, array('userrolename' => 'student'));
            }
            $resultsOnPage = [];
            $userData      = [];
            $filteredUsers = [];
            $revalidate    = [];
            $totalUsers    = 0;
            $offset        = 0;
            $totalPages    = 0;
            
            //Get field id profile student status
            $field = $DB->get_record('user_info_field', array('shortname' => 'studentstatus'));
            $fieldDoc = $DB->get_record('user_info_field', array('shortname' => 'documentnumber'));

            foreach ($infoUsers as $user) {
                
                //Get user info data Custom Fields - Status
                $status = 'Activo';
                if ($field) {
                    $user_info_data = $DB->get_record_sql("
                        SELECT d.data
                        FROM {user_info_data} d
                        JOIN {user} u ON u.id = d.userid
                        WHERE d.fieldid = ? AND u.deleted = 0 AND d.userid = ?
                    ", array($field->id, $user->userid));
                    
                    if ($user_info_data && !empty($user_info_data->data)) {
                        $status = $user_info_data->data;
                    }
                }

                // Get Document Number
                $docNumber = '';
                if ($fieldDoc) {
                     $doc_data = $DB->get_record_sql("
                        SELECT d.data
                        FROM {user_info_data} d
                        WHERE d.fieldid = ? AND d.userid = ?
                    ", array($fieldDoc->id, $user->userid));
                    if ($doc_data && !empty($doc_data->data)) {
                        $docNumber = $doc_data->data;
                    }
                }
                
                // Add to user object so it's sent to frontend
                // DEBUG: Force visible output to verify data flow
                // $user->documentnumber = $docNumber;
                $user->documentnumber = $docNumber; // Keep clean for now, rely on idnumber check below
                
                // Debugging ID fail:
                if (empty($docNumber) && empty($user->idnumber)) {
                     // If both are empty, check why?
                     // $user->documentnumber = "MISSING Doc=$fieldDoc->id User=$user->userid";
                }
                $customfield_value = $status; // Restore variable name used downstream if necessary, or refactor
                
                /*if($customfield_value == 'Inactivo' || $customfield_value == 'Suspendido' || $customfield_value == 'Expulsado'){
                    $userobj = $DB->get_record('user', array('id' => $user->userid));
                    $userobj->suspended = 1;
                    $result = $DB->update_record('user', $userobj);
                }*/
                
                // Get profile User Image
                $profileimage = get_user_picture_url($user->userid);
                
                //Get periods by user
                $period = $DB->get_record('local_learning_periods', array('id' => $user->periodid));
                $periodname = $period->name;
                
                $subperiodname = '';
                // Get subperiod if exists
                if (!empty($user->subperiodid)) {
                    $subperiod = $DB->get_record('local_learning_subperiods', array('id' => $user->subperiodid));
                    if ($subperiod) {
                        $subperiodname = $subperiod->name;
                    }
                }
                
                //Get users in revalidate groups in learning plan courses
                $userIntorev =  $DB->get_records_sql('SELECT g.id, gm.userid as userid, c.fullname as coursename, c.id as courseid
                                                    FROM {groups} g 
                                                    JOIN {groups_members} gm ON (gm.groupid = g.id) 
                                                    JOIN {course} c ON (c.id = g.courseid)
                                                    WHERE gm.userid = :userid 
                                                    AND g.idnumber LIKE "%rev-%"',
                                                    array('userid' => $user->userid));
                if(!empty($userIntorev)){
                    foreach($userIntorev as $rev){
                        $userData[$user->userid]['revalidate'][] = ['coursename' => $rev->coursename, 'courseid' => $rev->courseid, 'revalida' => 'revalida'];
                    }
                }else{
                    $userData[$user->userid]['revalidate'] = [];
                    $userData[$user->userid]['revalidate'] = [];
                }
                

                // Create or update user entry in $userData array
                $userData[$user->userid]['careers'][] = [
                    'planid' => $user->planid,
                    'career' => $user->career,
                    'periods' => $periodname,
                ];
                $userData[$user->userid]['period'] = $periodname . $subperiodname;
                $userData[$user->userid]['subperiods'] = $subperiodname; // Keep for explicit column
                $userData[$user->userid]['status'] = $customfield_value;
                $userData[$user->userid]['documentnumber'] = $user->documentnumber;
                $userData[$user->userid]['idnumber'] = $user->idnumber; // Standard ID
                // Force check:
                if ($user->email == 'adrianarguelles913@gmail.com') {
                     $userData[$user->userid]['documentnumber'] = "DBG: Doc[" . $user->documentnumber . "] ID[" . $user->idnumber . "]";
                }
                $userData[$user->userid]['revalidate'] = $revalidate;
                $userData[$user->userid]['revalidateSubjects'] = $revalidateSubjects;
                $userData[$user->userid]['userid'] = $user->userid;
                $userData[$user->userid]['email'] = $user->email;
                $userData[$user->userid]['nameuser'] = $user->firstname . " " . $user->lastname;
                $userData[$user->userid]['profileimage'] = $profileimage;
                $userData[$user->userid]['status'] = $customfield_value;
            }
            // Convert the associative array into indexed array
            $dataUsers = array_values($userData);
            
            if ($page > 0) {
                // Is required that the first page start with 0, not with 1 to get the correct values.
                $page--;
            }
            
            // Number Total results
            $totalUsers = count($dataUsers);
            
            // Get offset in query results page
            $offset = $page * $resultsperpage;
            
            //Get totalPages show by results
            $totalPages = ceil($totalUsers / $resultsperpage);
            
            $strRevalidate = "Revalida";
            
            if(!empty($search)){
                foreach ($dataUsers as $userfilter) {
                    if (
                        stripos($userfilter['nameuser'], $search) !== false ||
                        stripos($userfilter['email'], $search) !== false ||
                        stripos($userfilter['status'], $search) !== false ||
                        stripos($userfilter['documentnumber'], $search) !== false || // Search by Doc Number
                        stripos($userfilter['idnumber'], $search) !== false || // Search by ID Number
                        // Check in the array 'careers' str search and 'periods'
                        array_reduce($userfilter['careers'], function ($carry, $item) use ($search) {
                            $careerFilter = stripos($item['career'], $search) !== false;
                            $periodFilter = stripos($item['periods'], $search) !== false;
                            
                            return $carry || $careerFilter || $periodFilter;
                        }, false) || 
                        // Check in the array 'revalidate' str search
                        array_filter($userfilter['revalidate'], function ($item) use ($search) {
                            return stripos($item['coursename'], $search) !== false;
                        }) || 
                        array_filter($userfilter['revalidate'], function ($item) use ($search) {
                            return stripos($item['revalida'], $search) !== false;
                        })
                    ){
                        
                        $filteredUsers[] = $userfilter;
                    }
                }
                
                $resultsOnPage = $filteredUsers;
            }else{
                //If param search if empty get dataUsers paginate with limit and offset
                //get the results of the current page
                $resultsOnPage = array_slice($dataUsers, $offset, $resultsperpage);
            }

            return ['dataUsers'    => json_encode($resultsOnPage),
                   'totalResults'  => $totalUsers,
                   'totalPages'    => $totalPages
                  ];
 
    }
    
    /**
     * Describes the return value of the {@see self::execute()} method.
     *
     * @return external_description
     * 
     */
    public static function execute_returns(): external_description {
        return new external_single_structure(
            array(
                'dataUsers'      => new external_value(PARAM_RAW, 'Data user return.', VALUE_DEFAULT,''),
                'totalResults'   => new external_value(PARAM_INT, 'Total Data return users.', VALUE_DEFAULT,''),
                'totalPages'     => new external_value(PARAM_INT, 'Total number pages.', VALUE_DEFAULT,''),
            )
        );
    }
}