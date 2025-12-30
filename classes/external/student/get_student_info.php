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
                'page'           => new external_value(PARAM_INT, 'Page of the list.', VALUE_DEFAULT, 0),
                'resultsperpage' => new external_value(PARAM_INT, 'Results to show by page.', VALUE_DEFAULT, 15),
                'search'         => new external_value(PARAM_RAW, 'Filters by search data users.', VALUE_DEFAULT, ''),
                'planid'         => new external_value(PARAM_INT, 'Filter by Learning Plan ID.', VALUE_DEFAULT, 0),
                'periodid'       => new external_value(PARAM_INT, 'Filter by Period ID.', VALUE_DEFAULT, 0),
                'status'         => new external_value(PARAM_TEXT, 'Filter by Student Status.', VALUE_DEFAULT, ''),
            ]);
    }

    /**
     * TODO describe what the function actually does.
     *
     * @param int $userid
     * @return mixed TODO document
     */
    public static function execute($page, $resultsperpage, $search, $planid = 0, $periodid = 0, $status = '') {
        global $DB;
        
        // Validate the parameters passed to the function.
        $params = self::validate_parameters(self::execute_parameters(), [
            'page'           => $page,
            'resultsperpage' => $resultsperpage,
            'search'         => $search,
            'planid'         => $planid,
            'periodid'       => $periodid,
            'status'         => $status,
        ]);
        
        $sqlConditions = ["lpu.userrolename = :userrolename"];
        $sqlParams = ['userrolename' => 'student'];

        if ($params['planid']) {
            $sqlConditions[] = "lp.id = :planid";
            $sqlParams['planid'] = $params['planid'];
        }
        if ($params['periodid']) {
            $sqlConditions[] = "lpu.currentperiodid = :periodid";
            $sqlParams['periodid'] = $params['periodid'];
        }

        $whereClause = "WHERE " . implode(' AND ', $sqlConditions);

        $query = "
            SELECT lpu.id, lpu.currentperiodid as periodid, lpu.currentsubperiodid as subperiodid, lp.id as planid, 
            lp.name as career, u.id as userid, u.email as email, u.idnumber,
            u.firstname as firstname, u.lastname as lastname
            FROM {local_learning_plans} lp
            JOIN {local_learning_users} lpu ON (lpu.learningplanid = lp.id)
            JOIN {user} u ON (u.id = lpu.userid)
            $whereClause
            ORDER BY u.firstname";

        try {
            $infoUsers = $DB->get_records_sql($query, $sqlParams);
        } catch (Exception $e) {
            // Fallback for subperiodid if column missing (structural fix for older schemas)
            $query = "
                SELECT lpu.id, lpu.currentperiodid as periodid, lp.id as planid, 
                lp.name as career, u.id as userid, u.email as email, u.idnumber,
                u.firstname as firstname, u.lastname as lastname
                FROM {local_learning_plans} lp
                JOIN {local_learning_users} lpu ON (lpu.learningplanid = lp.id)
                JOIN {user} u ON (u.id = lpu.userid)
                $whereClause
                ORDER BY u.firstname";
            $infoUsers = $DB->get_records_sql($query, $sqlParams);
        }

        $userData = [];
        $activeUsersCount = 0;
        
        $field = $DB->get_record('user_info_field', array('shortname' => 'studentstatus'));
        $fieldDoc = $DB->get_record('user_info_field', array('shortname' => 'documentnumber'));

        foreach ($infoUsers as $user) {
            // Get Status
            $userStatus = 'Activo';
            if ($field) {
                $user_info_data = $DB->get_record('user_info_data', ['fieldid' => $field->id, 'userid' => $user->userid]);
                if ($user_info_data && !empty($user_info_data->data)) {
                    $userStatus = $user_info_data->data;
                }
            }

            // Filter by Status string if provided
            if (!empty($params['status']) && stripos($userStatus, $params['status']) === false) {
                continue;
            }

            if (stripos($userStatus, 'activo') !== false) {
                $activeUsersCount++;
            }

            // If we already have this user (multiple careers), we just add the career info later
            // But we need to check if the user matches the search criteria first
            
            // Get Document Number
            $docNumber = '';
            if ($fieldDoc) {
                $doc_data = $DB->get_record('user_info_data', ['fieldid' => $fieldDoc->id, 'userid' => $user->userid]);
                if ($doc_data && !empty($doc_data->data)) {
                    $docNumber = $doc_data->data;
                }
            }
            
            $finalID = !empty($docNumber) ? $docNumber : $user->idnumber;
            $fullName = $user->firstname . " " . $user->lastname;

            // Search filter
            if (!empty($params['search'])) {
                $match = (
                    stripos($fullName, $params['search']) !== false ||
                    stripos($user->email, $params['search']) !== false ||
                    stripos($userStatus, $params['search']) !== false ||
                    stripos($finalID, $params['search']) !== false ||
                    stripos($user->career, $params['search']) !== false
                );
                if (!$match) continue;
            }

            // Profile Image
            $profileimage = get_user_picture_url($user->userid);

            // Revalidate info
            $revalidate = [];
            $userIntorev = $DB->get_records_sql('
                SELECT g.id, c.fullname as coursename, c.id as courseid
                FROM {groups} g 
                JOIN {groups_members} gm ON (gm.groupid = g.id) 
                JOIN {course} c ON (c.id = g.courseid)
                WHERE gm.userid = :userid AND g.idnumber LIKE "%rev-%"',
                ['userid' => $user->userid]
            );
            foreach ($userIntorev as $rev) {
                $revalidate[] = ['coursename' => $rev->coursename, 'courseid' => $rev->courseid, 'revalida' => 'revalida'];
            }

            // Period names
            $period = $DB->get_record('local_learning_periods', ['id' => $user->periodid]);
            $periodname = $period ? $period->name : '--';

            $subperiodname = '';
            if (!empty($user->subperiodid)) {
                $subperiod = $DB->get_record('local_learning_subperiods', ['id' => $user->subperiodid]);
                if ($subperiod) $subperiodname = $subperiod->name;
            }

            if (!isset($userData[$user->userid])) {
                $userData[$user->userid] = [
                    'userid' => $user->userid,
                    'email' => $user->email,
                    'nameuser' => $fullName,
                    'documentnumber' => $finalID,
                    'status' => $userStatus,
                    'profileimage' => $profileimage,
                    'careers' => [],
                    'periods' => [],
                    'subperiods' => $subperiodname,
                    'revalidate' => $revalidate
                ];
            }

            $userData[$user->userid]['careers'][] = [
                'planid' => $user->planid,
                'career' => $user->career,
                'periodname' => $periodname,
                'periodid' => $user->periodid
            ];
            $userData[$user->userid]['periods'][] = $periodname;
        }

        $allResults = array_values($userData);
        $totalResults = count($allResults);
        
        // Fix Pagination Logic
        $pageForSlice = max(0, $params['page'] - 1);
        $offset = $pageForSlice * $params['resultsperpage'];
        $resultsOnPage = array_slice($allResults, $offset, $params['resultsperpage']);
        $totalPages = ceil($totalResults / $params['resultsperpage']);

        return [
            'dataUsers'    => json_encode($resultsOnPage),
            'totalResults'  => $totalResults,
            'totalPages'    => $totalPages,
            'activeUsers'   => $activeUsersCount
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
                'activeUsers'    => new external_value(PARAM_INT, 'Total active users.', VALUE_DEFAULT, 0),
            )
        );
    }
}