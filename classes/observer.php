<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Observer
 *
 * @package     local_sc_learningplans
 * @copyright   2022 Solutto <nicolas.castillo@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// require_once($CFG->libdir.'/completionlib.php');
// require_once($CFG->libdir.'/modinfolib.php');
// require_once($CFG->dirroot.'/course/lib.php');
// require_once($CFG->dirroot.'/mod/resource/lib.php');
// require_once($CFG->dirroot.'/course/modlib.php');
// require_once($CFG->dirroot.'/mod/attendance/locallib.php');
// require_once($CFG->dirroot.'/group/lib.php');

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/progress_manager.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

define('COURSE_PRACTICAL_HOURS_SHORTNAME','p');
    
class local_grupomakro_core_observer {
    /**
     * Triggered when 'course_completed' event is triggered.
     *
     * @param \core\event\course_completed $event
     */
    public static function course_completed(\core\event\course_completed  $event) {
        // global $DB;
        $eventdata = $event->get_data();
        
        $content = json_encode($eventdata, JSON_PRETTY_PRINT);
        
        $folderPath = __DIR__.'/';
        $filePath = $folderPath . 'course_completed.txt';
        
        $fileHandle = fopen($filePath, 'w');

        // Check if the file was opened successfully
        if ($fileHandle) {
            // Write content to the file
            fwrite($fileHandle, $content);
        
            // Close the file handle
            // echo "File created successfully.";
        } else {
            // echo "Failed to open the file for writing.";
        }
    }
    public static function course_updated(\core\event\course_updated $event){}
    
    
    public static function group_member_added(\core\event\group_member_added  $event) {
        $eventdata = $event->get_data();
        return;
    }
    public static function course_created(\core\event\course_created $event){
    
        $eventdata = $event->get_data();
        $newClassGroup = new stdClass();
        $newClassGroup->idnumber ='rev-'.$eventdata['other']['shortname'];
        $newClassGroup->name = 'Revalida';
        $newClassGroup->courseid = $eventdata['objectid'];
        $newClassGroup->description = 'Group for revalidating '.$eventdata['other']['fullname'].' course';
        $newClassGroup->descriptionformat = 1;
        $newClassGroup->id =groups_create_group($newClassGroup);
        
        $section = course_create_section($eventdata['objectid']);
        course_update_section($eventdata['objectid'],$section,[
            'name'=>'Reválida',
            'availability'=> '{"op":"&","c":[{"type":"group","id":'.$newClassGroup->id.'}],"showc":[true]}'
        ]);
    }
    public static function learningplanuser_removed(\local_sc_learningplans\event\learningplanuser_removed $event) {
        $eventdata = $event->get_data();
        $learningPlanId = $eventdata['other']['learningPlanId'];
        $userId = $eventdata['relateduserid'];
        
        local_grupomakro_progress_manager::delete_learningplan_user_progress($learningPlanId,$userId);
    }
    
    public static function learningplanuser_added(\local_sc_learningplans\event\learningplanuser_added $event) {
        $eventData = $event->get_data();
        $learningPlanUserId = $eventData['relateduserid'];
        $learningPlanId = $eventData['other']['learningPlanId'];
        $userRoleId = $eventData['other']['roleId'];
        
        local_grupomakro_progress_manager::create_learningplan_user_progress($learningPlanUserId,$learningPlanId,$userRoleId);
    }
    public static function course_module_completion_updated(\core\event\course_module_completion_updated  $event) {
        $eventData = $event->get_data();

        $courseId = $eventData['courseid'];
        $userId = $eventData['relateduserid'];
        $moduleId = $eventData['contextinstanceid'];
        $completionState = $eventData['other']['completionstate'];
        
        local_grupomakro_progress_manager::calculate_learning_plan_user_course_progress($courseId,$userId,$moduleId,$completionState);
    }
    
    public static function attendance_taken_by_student(\mod_attendance\event\attendance_taken_by_student $event){

        $eventdata = $event->get_data();
        $studentId = $eventdata['userid'];
        $attendanceSessionId = $eventdata['other']['sessionid'];
        $courseId = $eventdata['courseid'];
        $attendanceId = $eventdata['objectid'];
        $attendanceModuleId = $eventdata['contextinstanceid'];

        local_grupomakro_progress_manager::handle_qr_marked_attendance($courseId,$studentId,$attendanceModuleId,$attendanceId,$attendanceSessionId);
        
    }
    public static function attendance_taken(\mod_attendance\event\attendance_taken $event){
        global $DB;
        
        $eventdata = $event->get_data();
        $courseId = $eventdata['courseid'];
        $groupId = $eventdata['other']['grouptype'];
        $attendanceModuleId = $eventdata['contextinstanceid'];
        $attendanceId = $eventdata['objectid'];
        
        foreach(array_keys(groups_get_members($groupId)) as $groupMemberId){
            if($DB->get_record('gmk_course_progre',['userid'=>$groupMemberId,'courseid'=>$courseId])){
                local_grupomakro_progress_manager::calculate_learning_plan_user_course_progress($courseId,$groupMemberId,$attendanceModuleId);
            }
        }
    }
}
