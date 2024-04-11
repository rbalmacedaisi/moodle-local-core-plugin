<?php

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->dirroot . '/grade/querylib.php');

use core\message\message;

define('COURSE_NO_AVAILABLE',0);
define('COURSE_AVAILABLE',1);
define('COURSE_IN_PROGRESS',2);
define('COURSE_COMPLETED',3);
define('COURSE_APPROVED',4);
define('COURSE_FAILED',5);
define('COURSE_PENDING_REVALID',6);
define('COURSE_REVALIDATING',7);
define('MINIMUM_ATTENDANCE_TIME_PERCENTAGE',0);
define('MINIMUM_ATTENDANCE_TIME_EXTEND_PERCENTAGE',105);

class local_grupomakro_progress_manager {
    
    public $USER_COURSE_STATUS = [
        COURSE_NO_AVAILABLE=>'No disponible', 
        COURSE_AVAILABLE=>'Disponible',
        COURSE_IN_PROGRESS=>'Cursando',
        COURSE_COMPLETED=>'Completado',
        COURSE_APPROVED=>'Aprobada',
        COURSE_FAILED=>'Reprobada',
        COURSE_PENDING_REVALID=>'Pendiente Revalida',
        COURSE_REVALIDATING=>'Revalidando curso'
    ];
    
    public static function create_learningplan_user_progress($learningPlanUserId, $learningPlanId, $userRoleId){
        global $DB;
        
        try{
            
            $studentRoleId = $DB->get_record('role',['shortname'=>'student'])->id;
            if($studentRoleId != $userRoleId){
                return;
            }
            
            $learningPlanCourses = $DB->get_records_sql('
                SELECT lpc.*, c.fullname as coursename, lpp.name as periodname
                FROM {local_learning_courses} lpc
                JOIN {course} c ON (c.id = lpc.courseid)
                JOIN {local_learning_periods} lpp ON (lpp.id = lpc.periodid)
                WHERE lpc.learningplanid = :learningplanid',
            [
                'learningplanid' => $learningPlanId
            ]);
            $firstLearningPlanPeriodId = $DB->get_field('local_learning_periods','id',['learningplanid'=>$learningPlanId]);
            $courseCustomFieldhandler = \core_course\customfield\course_handler::create();
            
            foreach($learningPlanCourses as $learningPlanCourse){
                try{
                    $learningPlanCourse->userid = $learningPlanUserId;
                    $learningPlanCourse->status = $firstLearningPlanPeriodId == $learningPlanCourse->periodid? COURSE_AVAILABLE:COURSE_NO_AVAILABLE;
                    
                    $courseCustomFields = $courseCustomFieldhandler->get_instance_data($learningPlanCourse->courseid);
                    $courseCustomFieldsKeyValue = [];
                    foreach($courseCustomFields as $customField){
                        $courseCustomFieldsKeyValue[$customField->get_field()->get('shortname')] = $customField->get_value();
                    }

                    if(array_key_exists('credits',$courseCustomFieldsKeyValue)){
                        $learningPlanCourse->credits =$courseCustomFieldsKeyValue['credits'];
                    }
                    if(array_key_exists('pre',$courseCustomFieldsKeyValue)){
                        $prerequisitesShortNames = explode(',',$courseCustomFieldsKeyValue['pre']);
                        $learningPlanCourse->prerequisites = json_encode(array_filter(array_map(function($prerequisiteShortName) use ($DB){
                            $requiredCourse = $DB->get_record('course',['shortname'=>$prerequisiteShortName]);
                            if(!$requiredCourse){
                                return null;
                            }
                            return ['name'=>$requiredCourse->fullname, 'id'=>$requiredCourse->id];
                        },$prerequisitesShortNames)));
                    }
                    if(array_key_exists('tc',$courseCustomFieldsKeyValue)){
                        $learningPlanCourse->tc = $courseCustomFieldsKeyValue['tc'];
                    }
                    if(array_key_exists('p',$courseCustomFieldsKeyValue)){
                        $learningPlanCourse->practicalhours =$courseCustomFieldsKeyValue['p'];
                    }
                    if(array_key_exists('t',$courseCustomFieldsKeyValue)){
                        $learningPlanCourse->teoricalhours = $courseCustomFieldsKeyValue['t'];
                    }
                    
                    $learningPlanCourse->timecreated = time();
                    $learningPlanCourse->timemodified = time();
                    $DB->insert_record('gmk_course_progre',$learningPlanCourse);
                }catch (Exception $e){
                    continue;
                }
            }
            return;
        }catch(Exception $e){
            throw $e;
        }
    }
    
    public static function delete_learningplan_user_progress($learningPlanId,$userId){
        global $DB;
        
        try{
            $DB->delete_records('gmk_course_progre',['userid'=>$userId, 'learningplanid'=>$learningPlanId]);
            return;
        }catch (Exception $e){
            throw $e;
        }
    }
    
    public static function mark_bigbluebutton_related_attendance_session($userId,$coursemod,$course,$attendanceModuleRecord, $bbbModuleInstance,$classAttendanceBBBRelatedSessionid){
        global $DB,$CFG;
        require_once($CFG->dirroot . '/mod/attendance/classes/attendance_webservices_handler.php');
        // use mod_bigbluebuttonbn\instance;

        $attendanceInstance = $attendanceModuleRecord->instance;
        $attendanceDBRecord = $DB->get_record('attendance',['id'=>$attendanceInstance]);
        $attendanceBBBRelatedSession = $DB->get_record('attendance_sessions',['id'=>$classAttendanceBBBRelatedSessionid]);
        $attendanceStructure = new mod_attendance_structure($attendanceDBRecord,$attendanceModuleRecord,$course);
        
        $attendanceHiguestStatus = attendance_session_get_highest_status($attendanceStructure,$attendanceBBBRelatedSession);
        $attendanceStatusSet = implode(',', array_keys(attendance_get_statuses($attendanceInstance)));
        
        attendance_handler::update_user_status($attendanceBBBRelatedSession->id,$userId,$userId,$attendanceHiguestStatus,$attendanceStatusSet);
        $attendanceStructure->update_users_grade([$userId]);
    }
    
    public static function calculate_learning_plan_user_course_progress($courseId,$userId,$moduleId,$completionState=0){
        global $DB,$CFG;
        
        $coursemod = get_fast_modinfo($courseId,$userId);
        $moduleUpdated = $coursemod->get_cm($moduleId);

        if($groupClass = $DB->get_record('gmk_class',['coursesectionid'=>$moduleUpdated->section],'id,attendancemoduleid,coursesectionid')){
            
            $moduleUpdatedRecord= $moduleUpdated->get_course_module_record(true);
            $course = $coursemod->get_course();
            $completion = new completion_info($course);
            $userGroups = $coursemod->get_groups();
            
            $moduleComponent = $moduleUpdated->get_module_type_name()->get_component();
            $attendanceModule= $coursemod->get_cm($groupClass->attendancemoduleid);
            $attendanceModuleRecord = $attendanceModule->get_course_module_record(false);
            
            if($moduleComponent==='bigbluebuttonbn' && $completionState){
                
                $classAttendanceBBBRelatedSessionid = $DB->get_field('gmk_bbb_attendance_relation','attendancesessionid',['classid'=>$groupClass->id,'bbbmoduleid'=>$moduleId]);
                
                if($classAttendanceBBBRelatedSessionid){
                    self::mark_bigbluebutton_related_attendance_session($userId,$coursemod,$course,$attendanceModuleRecord,$moduleUpdatedRecord->instance,$classAttendanceBBBRelatedSessionid);
                }

            }
            $attendanceInstance = $attendanceModuleRecord->instance;
            
            $attendance = new mod_attendance_summary($attendanceInstance, [$userId]);
            $attendancePercentage =(float)rtrim($attendance->get_all_sessions_summary_for($userId)->allsessionspercentage, '%');
            
            //Calculate the obtained grade based on the gradable activities
            $gradableActivities = grade_get_gradable_activities($courseId);
            $classSectionNumber = $coursemod->get_section_info_by_id($groupClass->coursesectionid)->__get('section');
            $totalWeightedSum = 0;
            $numActivities=0;
            
            foreach($coursemod->get_sections()[$classSectionNumber] as $sectionModule){
                $module = $coursemod->get_cm($sectionModule);
                $moduleRecord= $module->get_course_module_record(true);
                $moduleType= $moduleRecord->modname;
                if($moduleType === 'bigbluebuttonbn'){
                    continue;
                }
                if(!array_key_exists($moduleRecord->id,$gradableActivities)){
                    continue;
                }
                $numActivities +=1;
                
                $moduleGrade = grade_get_grades($courseId,'mod',$moduleType,$moduleRecord->instance,$userId)->items[0];

                $moduleMaxGrade = $moduleGrade->grademax;
                $moduleUserGrade = $moduleGrade->grades[$userId]->grade;
                
                $normalizedGrade = min(1.0, max(0.0, $moduleUserGrade / $moduleMaxGrade));
                $totalWeightedSum += $normalizedGrade;

            }
            if ($numActivities == 0) {
                $finalGrade = 0;
            } else {
                $finalGrade = min(100, max(0, $totalWeightedSum / $numActivities * 100));
            }
            
            //Save the updated progress;
            
            $userCourseProgress = $DB->get_record('gmk_course_progre',['userid'=>$userId, 'courseid'=>$courseId]);
            $userCourseProgress->progress = $attendancePercentage;
            $userCourseProgress->grade = $finalGrade;
            if($attendancePercentage == 100){
                $userCourseProgress->status = COURSE_COMPLETED;
            }
            $DB->update_record('gmk_course_progre',$userCourseProgress);
        }
    }
    
    public static function close_class_grades_and_open_revalids(){
        global $DB;
        
        $studentPensumProgress = $DB->get_records('gmk_course_progre',['status'=>COURSE_IN_PROGRESS],'','id,userid,courseid,progress,grade,practicalhours, teoricalhours, status');
        // print_object($studentPensumProgress);
        // die;
        
        foreach($studentPensumProgress as $studentCourse){
            if($studentCourse->progress >= 75 && $studentCourse->grade > 70.4){
                $studentCourse->status = COURSE_APPROVED;
            }
            else if($studentCourse->progress >= 75 && $studentCourse->practicalhours == 0 && $studentCourse->grade >= 60 && $studentCourse->grade <= 70.4 ){
                $studentCourse->status = COURSE_PENDING_REVALID;
                self::send_revalidation_message($studentCourse->courseid, $studentCourse->userid,$studentCourse->id );
            }
            else if($studentCourse->progress >= 75 && $studentCourse->practicalhours == 0 && $studentCourse->grade <= 60){
                $studentCourse->status = COURSE_FAILED;
            }
            else if($studentCourse->progress >= 75 && $studentCourse->practicalhours > 0 && $studentCourse->grade <= 70.4){
                $studentCourse->status = COURSE_FAILED;
            }
            else if($studentCourse->progress < 75 ){
                $studentCourse->status = COURSE_FAILED;
            }
            $DB->update_record('gmk_course_progre',$studentCourse);
        }
        //print_object('Correos enviados');
    }
    
    public static function send_revalidation_message($courseId, $userId, $progreCourseId){
        global $OUTPUT;

        $rescheduleURL = self::get_revalid_payment_url($courseId,$userId,$progreCourseId);
        $course = get_fast_modinfo($courseId,$userId)->get_course();
        $strData = new stdClass();
        $strData->courseName=$course->fullname;
        $strData->payRevalidUrl=$rescheduleURL;
        
        $messageBody = get_string('msg:send_revalidation_message:body','local_grupomakro_core', $strData);
        $messageHtml = $OUTPUT->render_from_template( 'local_grupomakro_core/messages/revalidation_message',array('messageBody'=>$messageBody));
        
        $messageDefinition = new message();
        $messageDefinition->userto=$userId;
        $messageDefinition->component = 'local_grupomakro_core'; // Set the message component
        $messageDefinition->name ='send_revalidation_message'; // Set the message name
        $messageDefinition->userfrom = core_user::get_noreply_user(); // Set the message sender
        $messageDefinition->subject = get_string('msg:send_revalidation_message:subject','local_grupomakro_core'); // Set the message subject
        $messageDefinition->fullmessage = $messageHtml; // Set the message body
        $messageDefinition->fullmessageformat = FORMAT_HTML; // Set the message body format
        $messageDefinition->fullmessagehtml = $messageHtml;
        $messageDefinition->notification = 1;
        $messageDefinition->contexturl =$rescheduleURL;
        $messageDefinition->contexturlname = get_string('msg:send_revalidation_message:contexturlname','local_grupomakro_core');

        $messageid = message_send($messageDefinition);
    }
    
    public static function close_revalids_and_consolidate_grades(){
        
    }
    
    public static function enrol_user_in_revalid_group($progressId){
        global $DB;
        $userProgressRecord = $DB->get_record('gmk_course_progre',['id'=>$progressId],'id,userid,courseid');
        $courseMod = get_fast_modinfo($userProgressRecord->courseid);
        
        
        //print_object($courseMod->get_groups());
    }
    
    public static function get_revalid_payment_url($courseId,$userId,$progreCourseId){
        global $CFG;
        $envDic=['development'=>'-dev','staging'=>'-staging','production'=>''];
        return 'https://lxp'.$envDic[$CFG->environment_type].'.soluttolabs.com/local/grupomakro_core/pages/payment.php?courseId='.$courseId.'&userId='.$userId.'&progreId='.$progreCourseId;

    }
    
    public static function assign_class_to_course_progress($userId,$class){
        global $DB;
        $courseProgress = $DB->get_record('gmk_course_progre',['userid'=>$userId, 'courseid'=>$class->corecourseid]);
        $courseProgress->classid = $class->id;
        $courseProgress->groupid = $class->groupid;
        $courseProgress->status =COURSE_IN_PROGRESS;
        
        return $DB->update_record('gmk_course_progre',$courseProgress);;
        
    }
    
    public static function get_revalids_for_user($userId){
        global $DB;
        $revalidCourses = $DB->get_records('gmk_course_progre', ['userid'=>$userId, 'status'=>COURSE_REVALIDATING]);
        
        $revalidCoursesData = [];
        
        foreach($revalidCourses as $revalidCourse){
            $revalidInfo = new stdClass();
            $courseMod = get_fast_modinfo($revalidCourse->courseid, $userId);
            $revalidInfo->courseId = $revalidCourse->courseid;
            $revalidInfo->courseName = $courseMod->get_course()->fullname;
            $revalidInfo->courseImage =\core_course\external\course_summary_exporter::get_course_image($courseMod->get_course());
            
            $revalidCourseSubperiodId = $DB->get_field('local_learning_courses','subperiodid',['periodid'=>$revalidCourse->periodid, 'courseid'=>$revalidCourse->courseid]);
            $revalidInfo->period = $DB->get_field('local_learning_subperiods','name',['id'=>$revalidCourseSubperiodId]);
            
            $courseSections = $courseMod->get_sections();
            foreach($courseSections as $courseSectionPosition => $courseSectionModules){
                $sectionName = $courseMod->get_section_info($courseSectionPosition)->__get('name');
                if($sectionName === 'Reválida'){
                    $activityModule = $courseMod->get_cm($courseSectionModules[0]); 
                    $revalidInfo->revalidUrl = $activityModule->get_url()->out();
                }
            }
            $revalidCoursesData[]=$revalidInfo;
        }
        return $revalidCoursesData;
    }
    
    public static function handle_qr_marked_attendance($courseId,$studentId,$attendanceModuleId,$attendanceId,$attendanceSessionId){
        global $CFG,$DB;
        require_once($CFG->dirroot.'/mod/attendance/classes/attendance_webservices_handler.php');
        
        $courseMod = get_fast_modinfo($courseId);
        $course = $courseMod->get_course();
        $attendanceModule = $courseMod->get_cm($attendanceModuleId);
        $attendanceModuleRecord =$attendanceModule->get_course_module_record(true);
        $attendanceDBRecord = $DB->get_record('attendance',['id'=>$attendanceId]);
        $attendanceSession = $DB->get_record('attendance_sessions',['attendanceid'=>$attendanceId,'id'=>$attendanceSessionId]);
        
        $attendanceTakenTempRecords = array_values($DB->get_records('gmk_attendance_temp',['sessionid'=>$attendanceSessionId, 'studentid'=>$studentId,'courseid'=>$courseId]));
        $attendanceStructure = new mod_attendance_structure($attendanceDBRecord, $attendanceModuleRecord, $course);
        
        if(empty($attendanceTakenTempRecords)){
            self::delete_assist_attendance($attendanceSessionId, $studentId,$attendanceStructure);
            self::insert_attendance_temp_record($courseId, $studentId,$attendanceSessionId );
            return;
        }
        
        if($sessionTempRecords = count($attendanceTakenTempRecords)){
            $nowTimestamp = time();
            $percentageOfSessionTimeElapsed = (($nowTimestamp-$attendanceTakenTempRecords[0]->timetaken)/$attendanceSession->duration)*100;
            if($percentageOfSessionTimeElapsed>MINIMUM_ATTENDANCE_TIME_EXTEND_PERCENTAGE ||$sessionTempRecords>1 ){
                return;
            }
            if($percentageOfSessionTimeElapsed<MINIMUM_ATTENDANCE_TIME_PERCENTAGE){
                self::delete_assist_attendance($attendanceSessionId, $studentId,$attendanceStructure);
                return;
            }
            $statusId  = attendance_session_get_highest_status($attendanceStructure, $attendanceSession);
            $statusset = implode(',', array_keys(attendance_get_statuses($attendanceId, true, $attendanceSession->statusset)));
            $recordAttendance = attendance_handler::update_user_status($attendanceSessionId,$studentId,$studentId,$statusId,$statusset);
            $attendanceStructure->update_users_grade([$studentId]);
            self::insert_attendance_temp_record($courseId, $studentId,$attendanceSessionId );
            self::calculate_learning_plan_user_course_progress($courseId,$studentId,$attendanceModuleId);
        }
    }
    
    public static function insert_attendance_temp_record($courseId,$studentId,$attendanceSessionId){
        global $DB;
        
        $logAttendanceTemp = new stdClass();
        $logAttendanceTemp->sessionid = $attendanceSessionId;
        $logAttendanceTemp->studentid = $studentId;
        $logAttendanceTemp->courseid  = $courseId;
        $logAttendanceTemp->timetaken = time();
        $logAttendanceTemp->takenby   = $studentId;
        
        $DB->insert_record('gmk_attendance_temp', $logAttendanceTemp, false);
    }
    
    public static function delete_assist_attendance($attendanceSessionId, $studentId,$attendanceStructure){
        global $DB;
     
        $DB->delete_records('attendance_log', ['sessionid' => $attendanceSessionId, 'studentid' => $studentId]);
        attendance_update_users_grade($attendanceStructure);
        return;
    }
} 