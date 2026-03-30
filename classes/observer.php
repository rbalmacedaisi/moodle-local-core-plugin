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

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/progress_manager.php');
require_once($CFG->dirroot . '/local/sc_learningplans/libs/courselib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

define('COURSE_PRACTICAL_HOURS_SHORTNAME', 'p');

class local_grupomakro_core_observer
{
    /**
     * Triggered when 'course_completed' event is triggered.
     *
     * @param \core\event\course_completed $event
     */
    public static function course_completed(\core\event\course_completed  $event)
    {
        // global $DB;
        $eventdata = $event->get_data();

        $content = json_encode($eventdata, JSON_PRETTY_PRINT);

        $folderPath = __DIR__ . '/';
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

    public static function course_updated(\core\event\course_updated $event)
    {
        $eventdata = $event->get_data();
        $courseShortname =$eventdata['other']['shortname'];
        $courseFullname =$eventdata['other']['fullname'];
        $courseId = $eventdata['objectid'];
        try {
            \local_grupomakro_core\local\gmk_teacher_skill::update_course_teacher_skill(
                [
                    'shortname'=>$courseShortname,
                    'fullname'=>$courseFullname,
                    'courseid'=>$courseId
                ]
            );
            return true;
        } catch (Exception $e) {
            return false;
        }
        
    }

    public static function group_member_added(\core\event\group_member_added  $event)
    {
        $eventdata = $event->get_data();
        return;
    }

    public static function course_created(\core\event\course_created $event)
    {

        $eventdata = $event->get_data();
        $courseShortname =$eventdata['other']['shortname'];
        $courseFullname =$eventdata['other']['fullname'];
        $courseId = $eventdata['objectid'];

        $courseRevalidGroup = new stdClass();
        $courseRevalidGroup->idnumber = 'rev-' .$courseShortname ;
        $courseRevalidGroup->name = 'Revalida';
        $courseRevalidGroup->courseid = $courseId;
        $courseRevalidGroup->description = 'Group for revalidating ' .$courseFullname. ' course';
        $courseRevalidGroup->descriptionformat = 1;

        try {

            \local_grupomakro_core\local\gmk_teacher_skill::add_course_teacher_skill(
                [
                    'shortname'=>$courseShortname,
                    'fullname'=>$courseFullname,
                    'courseid'=>$courseId
                ]
            );

            $courseRevalidGroup->id = groups_create_group($courseRevalidGroup);

            $section = course_create_section($courseId);
            course_update_section($courseId, $section, [
                'name' => 'Reválida',
                'availability' => '{"op":"&","c":[{"type":"group","id":' . $courseRevalidGroup->id . '}],"showc":[true]}'
            ]);

            $revalidCategoryData = [
                'fullname' => 'Revalida grade category',
                'options' => [
                    'aggregation' => 6,
                    'aggregateonlygraded' => true,
                    'itemname' => 'Total Revalida grade',
                    'grademax' => 100,
                    'grademin' => 0,
                    'gradepass' => 70,
                ]
            ];
            core_grades\external\create_gradecategories::execute($courseId, [$revalidCategoryData]);
            return true;
        } catch (Exception $e) {
            print_object($e);
            return false;
        }
    }

    public static function course_deleted(\core\event\course_deleted $event)
    {
        // global $DB;
        $eventdata = $event->get_data();
        $courseId =$eventdata['objectid'];
        try {
            \local_grupomakro_core\local\gmk_teacher_skill::delete_course_teacher_skill($courseId);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public static function learningplanuser_removed(\local_sc_learningplans\event\learningplanuser_removed $event)
    {
        $eventdata = $event->get_data();
        $learningPlanId = $eventdata['other']['learningPlanId'];
        $userId = $eventdata['relateduserid'];

        local_grupomakro_progress_manager::delete_learningplan_user_progress($learningPlanId, $userId);
    }

    public static function learningplanuser_added(\local_sc_learningplans\event\learningplanuser_added $event)
    {
        $eventData = $event->get_data();
        $learningPlanUserId = $eventData['relateduserid'];
        $learningPlanId = $eventData['other']['learningPlanId'];
        $userRoleId = $eventData['other']['roleId'];

        local_grupomakro_progress_manager::create_learningplan_user_progress($learningPlanUserId, $learningPlanId, $userRoleId);
    }

    public static function course_module_completion_updated(\core\event\course_module_completion_updated  $event)
    {
        $eventData = $event->get_data();
        $courseId = $eventData['courseid'];
        $userId = $eventData['relateduserid'];
        $moduleId = $eventData['contextinstanceid'];
        $completionState = $eventData['other']['completionstate'];

        local_grupomakro_progress_manager::handle_module_completion($courseId, $userId, $moduleId, $completionState);
    }

    public static function attendance_taken_by_student(\mod_attendance\event\attendance_taken_by_student $event)
    {

        $eventdata = $event->get_data();
        $studentId = $eventdata['userid'];
        $attendanceSessionId = $eventdata['other']['sessionid'];
        $courseId = $eventdata['courseid'];
        $attendanceId = $eventdata['objectid'];
        $attendanceModuleId = $eventdata['contextinstanceid'];

        $content = json_encode($eventdata, JSON_PRETTY_PRINT);
        $folderPath = __DIR__ . '/';
        $filePath = $folderPath . 'attendance_taken_by_student' . $eventdata['userid'] . 'txt';
        $fileHandle = fopen($filePath, 'w');
        if ($fileHandle) {
            fwrite($fileHandle, $content);
        } else {
        }

        local_grupomakro_progress_manager::handle_qr_marked_attendance($courseId, $studentId, $attendanceModuleId, $attendanceId, $attendanceSessionId);
    }

    public static function attendance_taken(\mod_attendance\event\attendance_taken $event)
    {
        global $DB;
        $eventdata = $event->get_data();
        $courseId = $eventdata['courseid'];
        $groupId = $eventdata['other']['grouptype'];
        $attendanceModuleId = $eventdata['contextinstanceid'];
        $attendanceId = $eventdata['objectid'];

        $content = json_encode($eventdata, JSON_PRETTY_PRINT);
        $folderPath = __DIR__ . '/';
        $filePath = $folderPath . 'attendance_taken.txt';
        $fileHandle = fopen($filePath, 'w');
        if ($fileHandle) {
            fwrite($fileHandle, $content);
        } else {
        }
        foreach (array_keys(groups_get_members($groupId)) as $groupMemberId) {
            // FIX: Handle duplicates in gmk_course_progre - use get_records instead of get_record
            $progressRecords = $DB->get_records('gmk_course_progre', ['userid' => $groupMemberId, 'courseid' => $courseId]);
            if (!empty($progressRecords)) {
                local_grupomakro_progress_manager::update_course_progress($courseId, $groupMemberId);
            }
        }
    }

    public static function course_module_created(\core\event\course_module_created $event)
    {
        global $DB;
        try {
            $eventData = $event->get_data();
            $courseid = (int)$eventData['courseid'];
            $cmid = (int)$eventData['contextinstanceid'];

            $courseModInfo = get_fast_modinfo($courseid);
            if (empty($courseModInfo->cms[$cmid])) {
                return true;
            }
            $moduleInfo = $courseModInfo->get_cm($cmid);
            $moduleSectionInfo = $moduleInfo->get_section_info();
            $sectionId = (int)$moduleSectionInfo->__get('id');

            $class = $DB->get_record_sql(
                "SELECT *
                   FROM {gmk_class}
                  WHERE corecourseid = :courseid
                    AND coursesectionid = :sectionid
                    AND closed = 0
               ORDER BY id DESC
                  LIMIT 1",
                ['courseid' => $courseid, 'sectionid' => $sectionId]
            );

            if ($class) {
                // If teacher creates activities directly in Moodle UI, keep gradebook aligned to class category.
                if (in_array((string)$moduleInfo->modname, ['assign', 'quiz', 'attendance'], true)) {
                    $classcatid = gmk_get_or_create_class_grade_category($class);
                    if ($classcatid > 0 && !empty($moduleInfo->instance)) {
                        gmk_move_module_grade_items_to_class_category(
                            $courseid,
                            (string)$moduleInfo->modname,
                            (int)$moduleInfo->instance,
                            (int)$classcatid
                        );
                    }
                }

                $sectionUserIds = [];
                if (!empty($class->groupid)) {
                    $sectionUserIds = $DB->get_fieldset_select(
                        'gmk_course_progre',
                        'userid',
                        'courseid = :courseid AND groupid = :groupid',
                        ['courseid' => $courseid, 'groupid' => (int)$class->groupid]
                    );
                }
                if (empty($sectionUserIds)) {
                    $sectionUserIds = $DB->get_fieldset_select(
                        'gmk_course_progre',
                        'userid',
                        'classid = :classid',
                        ['classid' => (int)$class->id]
                    );
                }

                foreach (array_unique(array_map('intval', $sectionUserIds)) as $userId) {
                    if ($userId <= 0) {
                        continue;
                    }
                    local_grupomakro_progress_manager::update_course_progress($courseid, $userId);
                }
            }
            return true;
        } catch (\Throwable $e) {
            gmk_log("WARNING: observer course_module_created fallo: " . $e->getMessage());
            return true;
        }
    }
    public static function course_module_deleted(\core\event\course_module_deleted $event)
    {
        global $DB;
        try {
            $eventData = $event->get_data();
            $cmid = (int)($eventData['objectid'] ?? ($eventData['contextinstanceid'] ?? 0));
            if ($cmid > 0) {
                // 1) Remove stale relation rows referencing deleted cmid.
                $affectedclassids = [];
                $relrows = $DB->get_records_select(
                    'gmk_bbb_attendance_relation',
                    'bbbmoduleid = :cmid OR attendancemoduleid = :cmid',
                    ['cmid' => $cmid],
                    '',
                    'id,classid,bbbmoduleid,attendancemoduleid'
                );
                foreach ($relrows as $rr) {
                    $cid = (int)($rr->classid ?? 0);
                    if ($cid > 0) {
                        $affectedclassids[$cid] = $cid;
                    }
                }
                if (!empty($relrows)) {
                    $DB->delete_records_select(
                        'gmk_bbb_attendance_relation',
                        'bbbmoduleid = :cmid OR attendancemoduleid = :cmid',
                        ['cmid' => $cmid]
                    );
                }

                // 2) If deleted CM was linked as attendance module, reset class pointers.
                $attendanceclasses = $DB->get_records('gmk_class', ['attendancemoduleid' => $cmid], '', 'id');
                foreach ($attendanceclasses as $ac) {
                    $cid = (int)$ac->id;
                    if ($cid > 0) {
                        $affectedclassids[$cid] = $cid;
                        $DB->set_field('gmk_class', 'attendancemoduleid', 0, ['id' => $cid]);
                        $DB->set_field('gmk_class', 'bbbmoduleids', null, ['id' => $cid]);
                        $DB->delete_records('gmk_bbb_attendance_relation', ['classid' => $cid]);
                    }
                }

                // 3) Recompute bbbmoduleids for affected classes after relation cleanup.
                foreach ($affectedclassids as $cid) {
                    $rows = $DB->get_records('gmk_bbb_attendance_relation', ['classid' => (int)$cid], '', 'bbbmoduleid');
                    $cmids = [];
                    foreach ($rows as $r) {
                        $bbcmid = (int)($r->bbbmoduleid ?? 0);
                        if ($bbcmid > 0) {
                            $cmids[$bbcmid] = $bbcmid;
                        }
                    }
                    $newvalue = empty($cmids) ? null : implode(',', array_values($cmids));
                    $DB->set_field('gmk_class', 'bbbmoduleids', $newvalue, ['id' => (int)$cid]);
                }
            }

            $courseUserProgreRecords = $DB->get_records('gmk_course_progre', ['courseid' => $eventData['courseid']], '', 'userid,groupid');
            foreach ($courseUserProgreRecords as $progreRecord) {
                if (!$progreRecord->groupid) {
                    continue;
                }
                local_grupomakro_progress_manager::update_course_progress($eventData['courseid'], $progreRecord->userid);
            }
            return true;
        } catch (\Throwable $e) {
            gmk_log("WARNING: observer course_module_deleted fallo: " . $e->getMessage());
            return true;
        }
    }
    public static function learningplancourse_added(\local_sc_learningplans\event\learningplancourse_added $event)
    {
        global $DB;
        try {
            $eventData = $event->get_data();
            $learningPlanId = $eventData['other']['learningPlanId'];
            $learningPlanUsers = $DB->get_records("local_learning_users", ['learningplanid' => $learningPlanId]);
            foreach ($learningPlanUsers as $learningPlanUser) {
                local_grupomakro_progress_manager::create_learningplan_user_progress($learningPlanUser->userid, $learningPlanId, $learningPlanUser->userroleid);
            }
            relate_course_with_current_period_courses($eventData['other']['learningCourseId']);
            return true;
        } catch (Exception $e) {
            print_object($e->getMessage());
            return false;
        }
    }
    public static function user_graded(\core\event\user_graded $event)
    {
        $eventData = $event->get_data();
        $courseId = $eventData['courseid'];
        $userId = $eventData['relateduserid'];

        // Keep local progress in sync, but avoid forcing Moodle completion in the same
        // grade-save request to reduce observer/mail side effects and edit conflicts.
        local_grupomakro_progress_manager::update_course_progress($courseId, $userId, null, null, false);
    }

    /**
     * Notify teacher when a student submits an assignment.
     * Innovative Feature 1: Real-time Notifications.
     */
    public static function assign_submission_created(\mod_assign\event\submission_created $event) {
        global $DB;
        $eventdata = $event->get_data();
        $assignment = $DB->get_record('assign', ['id' => $eventdata['objectid']], '*', MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $eventdata['courseid']], '*', MUST_EXIST);
        
        // Find the group/class associated with this submission
        $cm = get_coursemodule_from_instance('assign', $assignment->id, $course->id, false, MUST_EXIST);
        $sectionid = $cm->section;
        
        $class = $DB->get_record('gmk_class', ['coursesectionid' => $sectionid]);
        if (!$class) return;
        
        $instructor = $DB->get_record('user', ['id' => $class->instructorid], '*', MUST_EXIST);
        $student = $DB->get_record('user', ['id' => $eventdata['userid']], '*', MUST_EXIST);

        // Send notification via Moodle Messaging API
        $message = new \core\message\message();
        $message->component = 'local_grupomakro_core';
        $message->name = 'assignment_submission';
        $message->userfrom = $student;
        $message->userto = $instructor;
        $message->subject = "Nueva entrega en: " . $class->name;
        $message->fullmessage = "El estudiante " . fullname($student) . " ha realizado una entrega en la tarea: " . $assignment->name;
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = "<p>El estudiante <b>" . fullname($student) . "</b> ha realizado una entrega en la tarea: <b>" . $assignment->name . "</b>.</p>";
        $message->smallmessage = "Nueva entrega de " . fullname($student);
        $message->courseid = $course->id;
        $message->contexturl = (new \moodle_url('/mod/assign/view.php', ['id' => $cm->id]))->out(false);
        $message->contexturlname = $assignment->name;
        
        message_send($message);
    }

    /**
     * Redirect teachers to their dashboard upon login.
     * Innovative Feature: Automatic Redirection.
     */
    public static function user_loggedin(\core\event\user_loggedin $event) {
        global $DB, $PAGE, $CFG;
        
        $userid = $event->userid;
        
        // DEBUG LOGGING
        $log_file = $CFG->dirroot . '/local/grupomakro_core/redirect_debug.log';
        $log_msg = date('Y-m-d H:i:s') . " - Login Event for User ID: $userid\n";
        
        // 1. Check for ACTIVE classes (Target: Teacher Dashboard)
        $has_active_classes = $DB->record_exists('gmk_class', ['instructorid' => $userid, 'closed' => 0]);
        $log_msg .= " - Has Active Classes: " . ($has_active_classes ? 'YES' : 'NO') . "\n";
        
        if ($has_active_classes) {
            file_put_contents($log_file, $log_msg . " - REDIRECTING to Teacher Dashboard\n", FILE_APPEND);
            $url = new \moodle_url('/local/grupomakro_core/pages/teacher_dashboard.php');
            redirect($url);
        }

        // 2. Check for INACTIVE Teacher status (Target: Inactive Dashboard)
        $has_past_classes = $DB->record_exists('gmk_class', ['instructorid' => $userid]);
        $has_skills = $DB->record_exists('gmk_teacher_skill_relation', ['userid' => $userid]);
        $has_availability = $DB->record_exists('gmk_teacher_disponibility', ['userid' => $userid]);

        $log_msg .= " - Past Classes: " . ($has_past_classes ? 'YES' : 'NO') . "\n";
        $log_msg .= " - Skills: " . ($has_skills ? 'YES' : 'NO') . "\n";
        $log_msg .= " - Availability: " . ($has_availability ? 'YES' : 'NO') . "\n";

        if ($has_past_classes || $has_skills || $has_availability) {
            file_put_contents($log_file, $log_msg . " - REDIRECTING to Inactive Dashboard\n", FILE_APPEND);
            $url = new \moodle_url('/local/grupomakro_core/pages/inactive_teacher_dashboard.php');
            redirect($url);
        }
        
        file_put_contents($log_file, $log_msg . " - NO REDIRECT (Standard Moodle Behavior)\n", FILE_APPEND);
    }
}

