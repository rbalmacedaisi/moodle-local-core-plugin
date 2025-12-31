<?php

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->dirroot . '/grade/querylib.php');

use core\message\message;

define('COURSE_NO_AVAILABLE', 0);
define('COURSE_AVAILABLE', 1);
define('COURSE_IN_PROGRESS', 2);
define('COURSE_COMPLETED', 3);
define('COURSE_APPROVED', 4);
define('COURSE_FAILED', 5);
define('COURSE_PENDING_REVALID', 6);
define('COURSE_REVALIDATING', 7);
define('MINIMUM_ATTENDANCE_TIME_PERCENTAGE', 0);
define('MINIMUM_ATTENDANCE_TIME_EXTEND_PERCENTAGE', 105);

class local_grupomakro_progress_manager
{

    public $USER_COURSE_STATUS = [
        COURSE_NO_AVAILABLE => 'No disponible',
        COURSE_AVAILABLE => 'Disponible',
        COURSE_IN_PROGRESS => 'Cursando',
        COURSE_COMPLETED => 'Completado',
        COURSE_APPROVED => 'Aprobada',
        COURSE_FAILED => 'Reprobada',
        COURSE_PENDING_REVALID => 'Pendiente Revalida',
        COURSE_REVALIDATING => 'Revalidando curso'
    ];

    public static function create_learningplan_user_progress($learningPlanUserId, $learningPlanId, $userRoleId)
    {
        global $DB;
        try {

            $studentRoleId = $DB->get_record('role', ['shortname' => 'student'])->id;
            if ($studentRoleId != $userRoleId) {
                return;
            }

            $learningPlanCourses = $DB->get_records_sql(
                '
                SELECT lpc.*, c.fullname as coursename, lpp.name as periodname
                FROM {local_learning_courses} lpc
                JOIN {course} c ON (c.id = lpc.courseid)
                JOIN {local_learning_periods} lpp ON (lpp.id = lpc.periodid)
                WHERE lpc.learningplanid = :learningplanid',
                [
                    'learningplanid' => $learningPlanId
                ]
            );
            // Fix: get_field fails if multiple periods exist. Use get_records with limit.
            $periods = $DB->get_records('local_learning_periods', ['learningplanid' => $learningPlanId], 'id ASC', 'id', 0, 1);
            if (!$periods) {
                 return; // No periods found
            }
            $firstLearningPlanPeriodId = reset($periods)->id;
            $courseCustomFieldhandler = \core_course\customfield\course_handler::create();

            foreach ($learningPlanCourses as $learningPlanCourse) {
                try {
                    $courseProgressRecord = $DB->record_exists('gmk_course_progre', array('userid' => $learningPlanUserId, 'periodid' => $learningPlanCourse->periodid, 'courseid' => $learningPlanCourse->courseid, 'learningplanid' => $learningPlanCourse->learningplanid));
                    if ($courseProgressRecord) {
                        continue;
                    }

                    $learningPlanCourse->userid = $learningPlanUserId;
                    $learningPlanCourse->status = $firstLearningPlanPeriodId == $learningPlanCourse->periodid ? COURSE_AVAILABLE : COURSE_NO_AVAILABLE;

                    $courseCustomFields = $courseCustomFieldhandler->get_instance_data($learningPlanCourse->courseid);
                    $courseCustomFieldsKeyValue = [];
                    foreach ($courseCustomFields as $customField) {
                        $courseCustomFieldsKeyValue[$customField->get_field()->get('shortname')] = $customField->get_value();
                    }

                    if (array_key_exists('credits', $courseCustomFieldsKeyValue)) {
                        $learningPlanCourse->credits = $courseCustomFieldsKeyValue['credits'];
                    }
                    if (array_key_exists('pre', $courseCustomFieldsKeyValue)) {
                        $prerequisitesShortNames = explode(',', $courseCustomFieldsKeyValue['pre']);
                        $learningPlanCourse->prerequisites = json_encode(array_filter(array_map(function ($prerequisiteShortName) use ($DB) {
                            $requiredCourse = $DB->get_record('course', ['shortname' => $prerequisiteShortName]);
                            if (!$requiredCourse) {
                                return null;
                            }
                            return ['name' => $requiredCourse->fullname, 'id' => $requiredCourse->id];
                        }, $prerequisitesShortNames)));
                    }
                    if (array_key_exists('tc', $courseCustomFieldsKeyValue)) {
                        $learningPlanCourse->tc = $courseCustomFieldsKeyValue['tc'];
                    }
                    if (array_key_exists('p', $courseCustomFieldsKeyValue)) {
                        $learningPlanCourse->practicalhours = $courseCustomFieldsKeyValue['p'];
                    }
                    if (array_key_exists('t', $courseCustomFieldsKeyValue)) {
                        $learningPlanCourse->teoricalhours = $courseCustomFieldsKeyValue['t'];
                    }

                    $learningPlanCourse->timecreated = time();
                    $learningPlanCourse->timemodified = time();

                    $DB->insert_record('gmk_course_progre', $learningPlanCourse);
                } catch (Exception $e) {
                    continue;
                }
            }
            return;
        } catch (Exception $e) {
            throw $e;
        }
    }

    public static function delete_learningplan_user_progress($learningPlanId, $userId)
    {
        global $DB;
        try {
            $DB->delete_records('gmk_course_progre', ['userid' => $userId, 'learningplanid' => $learningPlanId]);
            return true;
        } catch (Exception $e) {
            throw $e;
        }
    }

    public static function mark_bigbluebutton_related_attendance_session($userId, $courseMod, $relationInfo)
    {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/attendance/classes/attendance_webservices_handler.php');
        $attendanceModuleRecord = $courseMod->get_cm($relationInfo->attendancemoduleid)->get_course_module_record(false);
        $attendanceDBRecord = $DB->get_record('attendance', ['id' => $relationInfo->attendanceid]);
        $attendanceBBBRelatedSession = $DB->get_record('attendance_sessions', ['id' => $relationInfo->attendancesessionid]);
        $attendanceStructure = new mod_attendance_structure($attendanceDBRecord, $attendanceModuleRecord, $courseMod->get_course());
        $attendanceHiguestStatus = attendance_session_get_highest_status($attendanceStructure, $attendanceBBBRelatedSession);
        $attendanceStatusSet = implode(',', array_keys(attendance_get_statuses($relationInfo->attendanceid)));
        attendance_handler::update_user_status($attendanceBBBRelatedSession->id, $userId, $userId, $attendanceHiguestStatus, $attendanceStatusSet);
        $attendanceStructure->update_users_grade([$userId]);
    }

    public static function handle_module_completion($courseId, $userId, $moduleId, $completionState = 0)
    {
        global $DB;
        $courseMod = get_fast_modinfo($courseId, $userId);
        $moduleUpdated = $courseMod->get_cm($moduleId);
        if ($classId = $DB->get_field('gmk_class', 'id', ['coursesectionid' => $moduleUpdated->section])) {
            $BBBAttendanceRelation = $DB->get_record('gmk_bbb_attendance_relation', ['classid' => $classId, 'bbbmoduleid' => $moduleId]);
            if ($BBBAttendanceRelation && $completionState) {
                try {
                    self::mark_bigbluebutton_related_attendance_session($userId, $courseMod, $BBBAttendanceRelation);
                } catch (Exception $e) {
                    print_object($e);
                }
            }
            self::update_course_progress($courseId, $userId);
        }
    }

    static function update_course_progress($courseId, $userId, $learningPlanId = null, $logFile = null)
    {
        global $DB, $PAGE;
        
        try {
            $conditions = ['userid' => $userId, 'courseid' => $courseId];
            if ($learningPlanId) {
                $conditions['learningplanid'] = $learningPlanId;
            }
            
            $progressRecords = $DB->get_records('gmk_course_progre', $conditions);
            if (!$progressRecords) {
                if ($logFile) file_put_contents($logFile, "[AVISO] No se encontraron registros en gmk_course_progre para User: $userId, Course: $courseId, Plan: " . ($learningPlanId ?? 'ALL') . "\n", FILE_APPEND);
                return false;
            }

            // 1. Get Grade once for all records.
            $gradeObj = grade_get_course_grade($userId, $courseId);
            $userGrade = ($gradeObj && isset($gradeObj->grade)) ? round((float)$gradeObj->grade, 2) : 0.0;
            
            // Reusable components for progress calculation.
            $coursemod = get_fast_modinfo($courseId, $userId);
            $course = $coursemod->get_course();
            $completion = new completion_info($course);
            $renderer = $PAGE->get_renderer('core');

            $allSuccess = true;
            $anyCompleted = false;

            foreach ($progressRecords as $userCourseProgress) {
                $userCourseProgress->grade = $userGrade;
                
                // 2. Calculate module-based progress.
                $completedModules = 0;
                $sectionModuleCount = 0;
                $userGroup = $userCourseProgress->groupid;

                if ($userGroup) {
                    try {
                        $groupSection = $DB->get_field_sql("SELECT coursesectionid FROM {gmk_class} WHERE groupid = ? LIMIT 1", [$userGroup]);
                        if ($groupSection !== false) {
                            foreach ($coursemod->get_cms() as $courseModule) {
                                $courseModuleRecord = $courseModule->get_course_module_record();
                                if ($courseModuleRecord->section == $groupSection && !!$completion->is_enabled($courseModule)) {
                                    $sectionModuleCount += 1;
                                    $exporter = new \core_completion\external\completion_info_exporter($course, $courseModule, $userId);
                                    $moduleCompletionData = (array)$exporter->export($renderer);
                                    $completedModules += $moduleCompletionData['state'] > 0 ? 1 : 0;
                                }
                            }
                        }
                        if ($sectionModuleCount > 0) {
                            $userCourseProgress->progress = round(($completedModules / $sectionModuleCount) * 100, 2);
                        }
                    } catch (Exception $le) {
                        if ($logFile) file_put_contents($logFile, "[AVISO] Error calculando módulos para curso $courseId, usuario $userId, plan {$userCourseProgress->learningplanid}: " . $le->getMessage() . "\n", FILE_APPEND);
                    }
                }

                // 3. Apply Robust Overrides.
                $oldStatus = $userCourseProgress->status;
                $oldProgress = $userCourseProgress->progress;

                if ($userGrade >= 70) {
                    $userCourseProgress->progress = 100;
                    $userCourseProgress->status = COURSE_COMPLETED;
                } else if ($userCourseProgress->progress >= 100) {
                    $userCourseProgress->status = COURSE_COMPLETED;
                }

                if ($userCourseProgress->status == COURSE_COMPLETED) {
                    $anyCompleted = true;
                }

                if ($logFile) {
                    file_put_contents($logFile, "[INFO] Procesado User $userId, Plan {$userCourseProgress->learningplanid}, Curso $courseId: Nota=$userGrade, Progreso=$oldProgress -> {$userCourseProgress->progress}, Status=$oldStatus -> {$userCourseProgress->status}\n", FILE_APPEND);
                }

                if (!$DB->update_record('gmk_course_progre', $userCourseProgress)) {
                    $allSuccess = false;
                }
            }
            
            // 4. Force Moodle completion if any of the plan-course statuses is COMPLETED.
            if ($anyCompleted) {
                self::force_moodle_course_completion($courseId, $userId, $logFile);
            }

            return $allSuccess;
            
        } catch (Exception $e) {
            if ($logFile) file_put_contents($logFile, "[ERROR] Exception crítica en update_course_progress ($courseId, $userId): " . $e->getMessage() . "\n", FILE_APPEND);
            return false;
        }
    }

    public static function close_class_grades_and_open_revalids()
    {
        global $DB;

        $studentPensumProgress = $DB->get_records('gmk_course_progre', ['status' => COURSE_IN_PROGRESS], '', 'id,userid,courseid,progress,grade,practicalhours, teoricalhours, status');
        foreach ($studentPensumProgress as $studentCourse) {
            if ($studentCourse->progress >= 75 && $studentCourse->grade > 70.4) {
                $studentCourse->status = COURSE_APPROVED;
            } else if ($studentCourse->progress >= 75 && $studentCourse->practicalhours == 0 && $studentCourse->grade >= 60 && $studentCourse->grade <= 70.4) {
                $studentCourse->status = COURSE_PENDING_REVALID;
                self::send_revalidation_message($studentCourse->courseid, $studentCourse->userid, $studentCourse->id);
            } else if ($studentCourse->progress >= 75 && $studentCourse->practicalhours == 0 && $studentCourse->grade <= 60) {
                $studentCourse->status = COURSE_FAILED;
            } else if ($studentCourse->progress >= 75 && $studentCourse->practicalhours > 0 && $studentCourse->grade <= 70.4) {
                $studentCourse->status = COURSE_FAILED;
            } else if ($studentCourse->progress < 75) {
                $studentCourse->status = COURSE_FAILED;
            }
            $DB->update_record('gmk_course_progre', $studentCourse);
        }
        //print_object('Correos enviados');
    }

    public static function send_revalidation_message($courseId, $userId, $progreCourseId)
    {
        global $OUTPUT;

        $rescheduleURL = self::get_revalid_payment_url($courseId, $userId, $progreCourseId);
        $course = get_fast_modinfo($courseId, $userId)->get_course();
        $strData = new stdClass();
        $strData->courseName = $course->fullname;
        $strData->payRevalidUrl = $rescheduleURL;

        $messageBody = get_string('msg:send_revalidation_message:body', 'local_grupomakro_core', $strData);
        $messageHtml = $OUTPUT->render_from_template('local_grupomakro_core/messages/revalidation_message', array('messageBody' => $messageBody));

        $messageDefinition = new message();
        $messageDefinition->userto = $userId;
        $messageDefinition->component = 'local_grupomakro_core'; // Set the message component
        $messageDefinition->name = 'send_revalidation_message'; // Set the message name
        $messageDefinition->userfrom = core_user::get_noreply_user(); // Set the message sender
        $messageDefinition->subject = get_string('msg:send_revalidation_message:subject', 'local_grupomakro_core'); // Set the message subject
        $messageDefinition->fullmessage = $messageHtml; // Set the message body
        $messageDefinition->fullmessageformat = FORMAT_HTML; // Set the message body format
        $messageDefinition->fullmessagehtml = $messageHtml;
        $messageDefinition->notification = 1;
        $messageDefinition->contexturl = $rescheduleURL;
        $messageDefinition->contexturlname = get_string('msg:send_revalidation_message:contexturlname', 'local_grupomakro_core');

        $messageid = message_send($messageDefinition);
    }

    public static function close_revalids_and_consolidate_grades()
    {
    }

    public static function enrol_user_in_revalid_group($progressId)
    {
        global $DB;
        $userProgressRecord = $DB->get_record('gmk_course_progre', ['id' => $progressId], 'id,userid,courseid');
        $courseMod = get_fast_modinfo($userProgressRecord->courseid);


        //print_object($courseMod->get_groups());
    }

    public static function get_revalid_payment_url($courseId, $userId, $progreCourseId)
    {
        global $CFG;
        $envDic = ['development' => '-dev', 'staging' => '-staging', 'production' => ''];
        return 'https://lxp' . $envDic[$CFG->environment_type] . '.soluttolabs.com/local/grupomakro_core/pages/payment.php?courseId=' . $courseId . '&userId=' . $userId . '&progreId=' . $progreCourseId;
    }

    public static function assign_class_to_course_progress($userId, $class)
    {
        global $DB;
        $courseProgress = $DB->get_record('gmk_course_progre', ['userid' => $userId, 'courseid' => $class->corecourseid, 'learningplanid' => $class->learningplanid]);
        $courseProgress->classid = $class->id;
        $courseProgress->groupid = $class->groupid;
        $courseProgress->progress = 0;
        $courseProgress->grade = 0;
        $courseProgress->status = COURSE_IN_PROGRESS;

        return $DB->update_record('gmk_course_progre', $courseProgress);;
    }

    public static function unassign_class_from_course_progress($userId, $class)
    {
        global $DB;
        $courseProgress = $DB->get_record('gmk_course_progre', ['userid' => $userId, 'courseid' => $class->corecourseid, 'learningplanid' => $class->learningplanid]);
        if ($courseProgress) {
            $courseProgress->classid = 0; // Or null if allowed, but assign uses integer
            $courseProgress->groupid = 0;
            $courseProgress->progress = 0;
            $courseProgress->grade = 0;
            $courseProgress->status = COURSE_AVAILABLE; 

            return $DB->update_record('gmk_course_progre', $courseProgress);
        }
        return false;
    }

    public static function get_revalids_for_user($userId)
    {
        global $DB;
        $revalidCourses = $DB->get_records('gmk_course_progre', ['userid' => $userId, 'status' => COURSE_REVALIDATING]);

        $revalidCoursesData = [];

        foreach ($revalidCourses as $revalidCourse) {
            $revalidInfo = new stdClass();
            $courseMod = get_fast_modinfo($revalidCourse->courseid, $userId);
            $revalidInfo->courseId = $revalidCourse->courseid;
            $revalidInfo->courseName = $courseMod->get_course()->fullname;
            $revalidInfo->courseImage = \core_course\external\course_summary_exporter::get_course_image($courseMod->get_course());

            $revalidCourseSubperiodId = $DB->get_field('local_learning_courses', 'subperiodid', ['periodid' => $revalidCourse->periodid, 'courseid' => $revalidCourse->courseid]);
            $revalidInfo->period = $DB->get_field('local_learning_subperiods', 'name', ['id' => $revalidCourseSubperiodId]);

            $courseSections = $courseMod->get_sections();
            foreach ($courseSections as $courseSectionPosition => $courseSectionModules) {
                $sectionName = $courseMod->get_section_info($courseSectionPosition)->__get('name');
                if ($sectionName === 'Reválida') {
                    $activityModule = $courseMod->get_cm($courseSectionModules[0]);
                    $revalidInfo->revalidUrl = $activityModule->get_url()->out();
                }
            }
            $revalidCoursesData[] = $revalidInfo;
        }
        return $revalidCoursesData;
    }

    public static function handle_qr_marked_attendance($courseId, $studentId, $attendanceModuleId, $attendanceId, $attendanceSessionId)
    {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/attendance/classes/attendance_webservices_handler.php');

        $courseMod = get_fast_modinfo($courseId);
        $course = $courseMod->get_course();
        $attendanceModule = $courseMod->get_cm($attendanceModuleId);
        $attendanceModuleRecord = $attendanceModule->get_course_module_record(true);
        $attendanceDBRecord = $DB->get_record('attendance', ['id' => $attendanceId]);
        $attendanceSession = $DB->get_record('attendance_sessions', ['attendanceid' => $attendanceId, 'id' => $attendanceSessionId]);

        $attendanceTakenTempRecords = array_values($DB->get_records('gmk_attendance_temp', ['sessionid' => $attendanceSessionId, 'studentid' => $studentId, 'courseid' => $courseId]));
        $attendanceStructure = new mod_attendance_structure($attendanceDBRecord, $attendanceModuleRecord, $course);

        if (empty($attendanceTakenTempRecords)) {
            self::delete_assist_attendance($attendanceSessionId, $studentId, $attendanceStructure);
            self::insert_attendance_temp_record($courseId, $studentId, $attendanceSessionId);
            return;
        }

        if ($sessionTempRecords = count($attendanceTakenTempRecords)) {
            $nowTimestamp = time();
            $percentageOfSessionTimeElapsed = (($nowTimestamp - $attendanceTakenTempRecords[0]->timetaken) / $attendanceSession->duration) * 100;
            if ($percentageOfSessionTimeElapsed > MINIMUM_ATTENDANCE_TIME_EXTEND_PERCENTAGE || $sessionTempRecords > 1) {
                return;
            }
            if ($percentageOfSessionTimeElapsed < MINIMUM_ATTENDANCE_TIME_PERCENTAGE) {
                self::delete_assist_attendance($attendanceSessionId, $studentId, $attendanceStructure);
                return;
            }
            $statusId  = attendance_session_get_highest_status($attendanceStructure, $attendanceSession);
            $statusset = implode(',', array_keys(attendance_get_statuses($attendanceId, true, $attendanceSession->statusset)));
            $recordAttendance = attendance_handler::update_user_status($attendanceSessionId, $studentId, $studentId, $statusId, $statusset);
            $attendanceStructure->update_users_grade([$studentId]);
            self::insert_attendance_temp_record($courseId, $studentId, $attendanceSessionId);
            self::update_course_progress($courseId, $studentId);
        }
    }

    public static function insert_attendance_temp_record($courseId, $studentId, $attendanceSessionId)
    {
        global $DB;

        $logAttendanceTemp = new stdClass();
        $logAttendanceTemp->sessionid = $attendanceSessionId;
        $logAttendanceTemp->studentid = $studentId;
        $logAttendanceTemp->courseid  = $courseId;
        $logAttendanceTemp->timetaken = time();
        $logAttendanceTemp->takenby   = $studentId;

        $DB->insert_record('gmk_attendance_temp', $logAttendanceTemp, false);
    }

    public static function delete_assist_attendance($attendanceSessionId, $studentId, $attendanceStructure)
    {
        global $DB;
        $DB->delete_records('attendance_log', ['sessionid' => $attendanceSessionId, 'studentid' => $studentId]);
        attendance_update_users_grade($attendanceStructure);
        return;
    }
    public static function force_moodle_course_completion($courseId, $userId, $logFile = null)
    {
        global $DB, $CFG;
        if ($logFile) file_put_contents($logFile, "[DEBUG] Entrando a force_moodle_course_completion para User $userId, Curso $courseId\n", FILE_APPEND);
        try {
            $course = get_course($courseId);
            $completion = new \completion_info($course);
            
            if (!$completion->is_enabled()) {
                if ($logFile) file_put_contents($logFile, "[AVISO] Moodle completion NO habilitado para curso $courseId ($course->fullname). Saltando.\n", FILE_APPEND);
                return false;
            }

            // Using completion_completion class to mark as complete.
            // This is more robust than manual DB updates.
            require_once($CFG->libdir . '/completionlib.php');
            $params = ['userid' => $userId, 'course' => $courseId];
            $ccompletion = new \completion_completion($params);
            
            if (!$ccompletion->is_complete()) {
                $ccompletion->mark_complete();
                if ($logFile) file_put_contents($logFile, "[INFO] Moodle completion MARCADO como COMPLETO (API) para User $userId en Curso $courseId.\n", FILE_APPEND);
            } else {
                if ($logFile) file_put_contents($logFile, "[DEBUG] Moodle completion ya estaba marcado como completo para User $userId en Curso $courseId.\n", FILE_APPEND);
            }

            return true;
        } catch (\Exception $e) {
            if ($logFile) file_put_contents($logFile, "[ERROR] Falló force_moodle_course_completion ($courseId, $userId): " . $e->getMessage() . "\n", FILE_APPEND);
            return false;
        }
    }

    /**
     * Synchronize the current period of a student based on their course completion.
     * Useful for migrations where students are imported with grades but stuck in Period 1.
     *
     * @param int $userId Moodle user ID
     * @param int $learningPlanId Learning Plan ID
     * @param string|null $logFile Path to log file
     * @return bool
     */
    public static function sync_student_period($userId, $learningPlanId, $logFile = null)
    {
        global $DB, $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        try {
            // 1. Get all required courses for this plan, ordered by period and position
            $sql = "SELECT lpc.id, lpc.courseid, lpc.periodid, c.fullname
                    FROM {local_learning_courses} lpc
                    JOIN {course} c ON (c.id = lpc.courseid)
                    WHERE lpc.learningplanid = :lpid AND lpc.isrequired = 1
                    ORDER BY lpc.periodid ASC, lpc.position ASC";
            
            $requiredCourses = $DB->get_records_sql($sql, ['lpid' => $learningPlanId]);
            if (!$requiredCourses) {
                if ($logFile) file_put_contents($logFile, "[AVISO] Sincronización de Periodo: No se encontraron materias obligatorias para el Plan $learningPlanId.\n", FILE_APPEND);
                return false;
            }

            $firstIncompletePeriodId = null;
            $lastPeriodId = null;

            foreach ($requiredCourses as $lpc) {
                $lastPeriodId = $lpc->periodid;
                
                // Check completion in gmk_course_progre
                $progre = $DB->get_record('gmk_course_progre', [
                    'userid' => $userId, 
                    'courseid' => $lpc->courseid, 
                    'learningplanid' => $learningPlanId
                ], 'status');

                $isComplete = false;
                if ($progre && $progre->status == COURSE_COMPLETED) {
                    $isComplete = true;
                } else {
                    // Fallback to Moodle Gradebook/Completion
                    $completion = new \completion_info(get_course($lpc->courseid));
                    if ($completion->is_course_complete($userId)) {
                        $isComplete = true;
                    } else {
                        // Check if grade is >= 70
                        $gradeObj = grade_get_course_grade($userId, $lpc->courseid);
                        if ($gradeObj && isset($gradeObj->grade) && (float)$gradeObj->grade >= 70) {
                            $isComplete = true;
                        }
                    }
                }

                if (!$isComplete) {
                    $firstIncompletePeriodId = $lpc->periodid;
                    break;
                }
            }

            // Determine target period
            // If all are complete, target is the last period.
            // If some are incomplete, target is the first incomplete period.
            $targetPeriodId = $firstIncompletePeriodId ?? $lastPeriodId;

            if ($targetPeriodId) {
                $lpUser = $DB->get_record('local_learning_users', [
                    'userid' => $userId, 
                    'learningplanid' => $learningPlanId
                ]);

                if ($lpUser) {
                    if ($lpUser->currentperiodid != $targetPeriodId) {
                        $oldPeriod = $lpUser->currentperiodid;
                        $lpUser->currentperiodid = $targetPeriodId;
                        $lpUser->timemodified = time();
                        $DB->update_record('local_learning_users', $lpUser);
                        
                        if ($logFile) file_put_contents($logFile, "[INFO] Sincronización de Periodo Estudiante $userId: $oldPeriod -> $targetPeriodId (Plan $learningPlanId)\n", FILE_APPEND);
                    }
                    return true;
                }
            }

            return false;
        } catch (Exception $e) {
            if ($logFile) file_put_contents($logFile, "[ERROR] Falló sync_student_period ($userId, $learningPlanId): " . $e->getMessage() . "\n", FILE_APPEND);
            return false;
        }
    }

    /**
     * Synchronize the current period of a student based on the COUNT of completed courses.
     * Specific logic for migrated students: 
     * If plan has 7 courses per period and user has 8, move to Period 2.
     *
     * @param int $userId Moodle user ID
     * @param int $learningPlanId Learning Plan ID
     * @param string|null $logFile Path to log file
     * @return bool
     */
    public static function sync_student_period_by_count($userId, $learningPlanId, $logFile = null)
    {
        global $DB, $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        try {
            // 1. Get ALL required courses for this plan, ordered by period ID ASC
            $sql = "SELECT lpc.id, lpc.courseid, lpc.periodid
                    FROM {local_learning_courses} lpc
                    WHERE lpc.learningplanid = :lpid AND lpc.isrequired = 1
                    ORDER BY lpc.periodid ASC, lpc.position ASC";
            
            $requiredCourses = $DB->get_records_sql($sql, ['lpid' => $learningPlanId]);
            if (!$requiredCourses) {
                return false;
            }

            // 2. Count how many of these are completed by the student
            $completedCount = 0;
            foreach ($requiredCourses as $lpc) {
                $progre = $DB->get_record('gmk_course_progre', [
                    'userid' => $userId, 
                    'courseid' => $lpc->courseid, 
                    'learningplanid' => $learningPlanId
                ], 'id, status');

                $isComplete = false;
                if ($progre && $progre->status == COURSE_COMPLETED) {
                    $isComplete = true;
                } else {
                    $completion = new \completion_info(get_course($lpc->courseid));
                    if ($completion->is_course_complete($userId)) {
                        $isComplete = true;
                    } else {
                        $gradeObj = grade_get_course_grade($userId, $lpc->courseid);
                        if ($gradeObj && isset($gradeObj->grade) && (float)$gradeObj->grade >= 70) {
                            $isComplete = true;
                        }
                    }
                }

                if ($isComplete) {
                    $completedCount++;
                }
            }

            // 3. Map count to periods
            // We group courses by period to know the capacity of each
            $periodsCapacity = [];
            foreach ($requiredCourses as $lpc) {
                if (!isset($periodsCapacity[$lpc->periodid])) {
                    $periodsCapacity[$lpc->periodid] = 0;
                }
                $periodsCapacity[$lpc->periodid]++;
            }

            $targetPeriodId = reset($requiredCourses)->periodid; // Default to first
            $accumulatedCap = 0;
            foreach ($periodsCapacity as $pid => $cap) {
                $accumulatedCap += $cap;
                $targetPeriodId = $pid;
                if ($completedCount < $accumulatedCap) {
                    // Stop at the first period that hasn't been "overfilled"
                    break;
                }
            }

            return self::update_student_period($userId, $learningPlanId, $targetPeriodId, $logFile);

        } catch (Exception $e) {
            if ($logFile) file_put_contents($logFile, "[ERROR] Falló sync_student_period_by_count ($userId, $learningPlanId): " . $e->getMessage() . "\n", FILE_APPEND);
            return false;
        }
    }

    /**
     * Updates the current period for a user in a learning plan.
     *
     * @param int $userId
     * @param int $learningPlanId
     * @param int $targetPeriodId
     * @param string|null $logFile
     * @return bool
     */
    public static function update_student_period($userId, $learningPlanId, $targetPeriodId, $logFile = null)
    {
        global $DB;
        try {
            $lpUser = $DB->get_record('local_learning_users', [
                'userid' => $userId, 
                'learningplanid' => $learningPlanId
            ]);

            if ($lpUser) {
                // Find first sub-period for this period
                $subperiods = $DB->get_records_sql("SELECT id FROM {local_learning_subperiods} WHERE periodid = ? ORDER BY position ASC", [$targetPeriodId], 0, 1);
                $subperiod = reset($subperiods);
                $targetSubperiodId = $subperiod ? $subperiod->id : 0;

                $changed = false;
                if ($lpUser->currentperiodid != $targetPeriodId) {
                    $oldPeriod = $lpUser->currentperiodid;
                    $lpUser->currentperiodid = $targetPeriodId;
                    $changed = true;
                }

                if ($lpUser->currentsubperiodid != $targetSubperiodId) {
                    $lpUser->currentsubperiodid = $targetSubperiodId;
                    $changed = true;
                }

                if ($changed) {
                    $lpUser->timemodified = time();
                    $DB->update_record('local_learning_users', $lpUser);
                    
                    if ($logFile) {
                        $msg = "[INFO] Periodo Actualizado Estudiante $userId: Periodo=$targetPeriodId, Bloque=$targetSubperiodId (Plan $learningPlanId)\n";
                        file_put_contents($logFile, $msg, FILE_APPEND);
                    }
                }
                return true;
            }
            return false;
        } catch (Exception $e) {
            if ($logFile) file_put_contents($logFile, "[ERROR] Falló update_student_period ($userId, $learningPlanId): " . $e->getMessage() . "\n", FILE_APPEND);
            return false;
        }
    }
}
