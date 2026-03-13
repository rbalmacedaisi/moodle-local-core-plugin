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
 * This is the main lib file for the plugin.
 *
 * @package    local_grupomakro_core
 * @copyright  2022 Solutto Consulting <dev@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/group/lib.php');
require_once($CFG->dirroot . '/grade/lib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/calendar/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/attendance/locallib.php');
require_once($CFG->dirroot . '/vendor/autoload.php');
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/progress_manager.php');
require_once($CFG->dirroot . '/user/profile/lib.php');

if (!function_exists('gmk_log')) {
    /**
     * Helper function to log debug messages to a local file.
     * @param string $message
     */
    function gmk_log($message) {
        $logfile = __DIR__ . '/gmk_debug.log';
        $timestamp = date('Y-m-d H:i:s');
        $formatted = "[$timestamp] $message\n";
        file_put_contents($logfile, $formatted, FILE_APPEND);
    }
}

if (!function_exists('gmk_best_effort_db_commit')) {
    /**
     * Try to commit any pending DB transaction without throwing.
     * Useful when upstream code swallows exceptions after partial writes.
     */
    function gmk_best_effort_db_commit($context = '') {
        global $DB;
        try {
            $DB->execute('COMMIT');
            if ($context !== '') {
                gmk_log("INFO: best-effort COMMIT OK ($context)");
            }
            return true;
        } catch (Throwable $e) {
            if ($context !== '') {
                gmk_log("WARNING: best-effort COMMIT fallo ($context): " . $e->getMessage());
            }
            return false;
        }
    }
}

if (!function_exists('gmk_get_user_passed_course_map_fast')) {
    /**
     * Fast pass/fail map by course using grade_items/grade_grades directly.
     * Avoids gradebook tree traversal (grade_category::get_children) used by grade_get_course_grade().
     *
     * @param int $userid
     * @param array $courseids
     * @param float $passgrade
     * @return array<int,bool> courseid => passed
     */
    function gmk_get_user_passed_course_map_fast(int $userid, array $courseids, float $passgrade = 70.0): array {
        global $DB;

        $cleanids = [];
        foreach ($courseids as $cid) {
            $cid = (int)$cid;
            if ($cid > 0) {
                $cleanids[$cid] = $cid;
            }
        }
        $cleanids = array_values($cleanids);
        if (empty($cleanids)) {
            return [];
        }

        list($insql, $inparams) = $DB->get_in_or_equal($cleanids, SQL_PARAMS_NAMED, 'cid');
        $sql = "SELECT gi.courseid,
                       MAX(COALESCE(gg.finalgrade, gg.rawgrade)) AS gradeval
                  FROM {grade_items} gi
             LEFT JOIN {grade_grades} gg
                    ON gg.itemid = gi.id
                   AND gg.userid = :userid
                 WHERE gi.itemtype = 'course'
                   AND gi.courseid $insql
              GROUP BY gi.courseid";
        $rows = $DB->get_records_sql($sql, ['userid' => $userid] + $inparams);

        $passed = [];
        foreach ($cleanids as $cid) {
            $passed[$cid] = false;
        }
        foreach ($rows as $row) {
            $cid = (int)$row->courseid;
            $grade = is_null($row->gradeval) ? null : (float)$row->gradeval;
            $passed[$cid] = (!is_null($grade) && $grade >= $passgrade);
        }

        return $passed;
    }
}

if (!function_exists('gmk_secondary_db_activity_check')) {
    /**
     * Verify from a second DB connection that class activity writes are visible (committed).
     *
     * @return array{
     *   enabled: bool,
     *   ok: bool|null,
     *   class_attendancemoduleid: int|null,
     *   class_groupid: int|null,
     *   class_coursesectionid: int|null,
     *   cm_exists: int|null,
     *   section_modules_att_bbb: int|null,
     *   error?: string
     * }
     */
    function gmk_secondary_db_activity_check(int $classid, int $courseid = 0, int $sectionid = 0, int $attcmid = 0): array {
        global $CFG;

        $out = [
            'enabled' => false,
            'ok' => null,
            'class_attendancemoduleid' => null,
            'class_groupid' => null,
            'class_coursesectionid' => null,
            'cm_exists' => null,
            'section_modules_att_bbb' => null,
        ];

        if (!in_array($CFG->dbtype, ['mysqli', 'mariadb'], true)) {
            $out['error'] = 'dbtype no soportado: ' . $CFG->dbtype;
            return $out;
        }
        if (!class_exists('mysqli')) {
            $out['error'] = 'ext mysqli no disponible';
            return $out;
        }

        $host = (string)($CFG->dbhost ?? '');
        $user = (string)($CFG->dbuser ?? '');
        $pass = (string)($CFG->dbpass ?? '');
        $name = (string)($CFG->dbname ?? '');
        $port = (int)($CFG->dboptions['dbport'] ?? ($CFG->dbport ?? 3306));
        $prefix = (string)($CFG->prefix ?? 'mdl_');

        mysqli_report(MYSQLI_REPORT_OFF);
        $mysqli = @new mysqli($host, $user, $pass, $name, $port);
        if ($mysqli->connect_errno) {
            $out['error'] = 'mysqli connect error: ' . $mysqli->connect_error;
            return $out;
        }

        try {
            $out['enabled'] = true;

            $classsql = "SELECT attendancemoduleid, groupid, coursesectionid
                           FROM {$prefix}gmk_class
                          WHERE id = ?";
            if ($stmt = $mysqli->prepare($classsql)) {
                $stmt->bind_param('i', $classid);
                if ($stmt->execute() && ($res = $stmt->get_result())) {
                    $row = $res->fetch_assoc();
                    if ($row) {
                        $out['class_attendancemoduleid'] = isset($row['attendancemoduleid']) ? (int)$row['attendancemoduleid'] : null;
                        $out['class_groupid'] = isset($row['groupid']) ? (int)$row['groupid'] : null;
                        $out['class_coursesectionid'] = isset($row['coursesectionid']) ? (int)$row['coursesectionid'] : null;
                    }
                }
                $stmt->close();
            }

            if ($attcmid > 0) {
                $cmsql = "SELECT cm.id
                            FROM {$prefix}course_modules cm
                           WHERE cm.id = ?";
                if ($stmt = $mysqli->prepare($cmsql)) {
                    $stmt->bind_param('i', $attcmid);
                    if ($stmt->execute() && ($res = $stmt->get_result())) {
                        $row = $res->fetch_assoc();
                        $out['cm_exists'] = $row ? 1 : 0;
                    }
                    $stmt->close();
                }
            }

            if ($courseid > 0 && $sectionid > 0) {
                $cntsql = "SELECT COUNT(1) AS c
                             FROM {$prefix}course_modules cm
                             JOIN {$prefix}modules m ON m.id = cm.module
                            WHERE cm.course = ?
                              AND cm.section = ?
                              AND m.name IN ('attendance','bigbluebuttonbn')";
                if ($stmt = $mysqli->prepare($cntsql)) {
                    $stmt->bind_param('ii', $courseid, $sectionid);
                    if ($stmt->execute() && ($res = $stmt->get_result())) {
                        $row = $res->fetch_assoc();
                        $out['section_modules_att_bbb'] = $row ? (int)$row['c'] : 0;
                    }
                    $stmt->close();
                }
            }

            // "ok" means persisted in second connection consistently.
            if ($attcmid > 0) {
                $out['ok'] =
                    ((int)($out['class_attendancemoduleid'] ?? 0) === $attcmid) &&
                    ((int)($out['cm_exists'] ?? 0) === 1) &&
                    ((int)($out['section_modules_att_bbb'] ?? 0) > 0);
            } else {
                $out['ok'] = false;
            }
        } catch (Throwable $e) {
            $out['error'] = $e->getMessage();
        } finally {
            $mysqli->close();
        }

        return $out;
    }
}

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;


function get_teachers_disponibility($params)
{
    $timePattern = "/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/";

    $incomingTimestampRange = null;
    if (array_key_exists('initTime', $params) && array_key_exists('endTime', $params)) {
        $params['initTime'] = preg_match($timePattern, $params['initTime']) ? $params['initTime'] : null;
        $params['endTime'] = preg_match($timePattern, $params['endTime']) ? $params['endTime'] : null;
        $incomingTimestampRange = $params['initTime'] && $params['endTime'] ? convert_time_range_to_timestamp_range([$params['initTime'], $params['endTime']]) : null;
        $incomingTimestampRangeObject = new stdClass();
        if ($incomingTimestampRange) {
            $incomingTimestampRangeObject->st = $incomingTimestampRange['initTS'];
            $incomingTimestampRangeObject->et = $incomingTimestampRange['endTS'];
        }
    }

    global $DB, $PAGE;
    $teacherSkills = $DB->get_records('gmk_teacher_skill');
    $disponibilityRecords = $DB->get_records('gmk_teacher_disponibility', $params['instructorId'] ? ['userid' => $params['instructorId']] : []);
    $weekdays = array(
        'disp_monday' => 'Lunes',
        'disp_tuesday' => 'Martes',
        'disp_wednesday' => 'Miércoles',
        'disp_thursday' => 'Jueves',
        'disp_friday' => 'Viernes',
        'disp_saturday' => 'Sábado',
        'disp_sunday' => 'Domingo'
    );
    $teachersDisponibility = array();
    foreach ($disponibilityRecords as $disponibilityRecord) {
        $teacherId = $disponibilityRecord->userid;
        $teachersDisponibility[$teacherId] = new stdClass();
        $teachersDisponibility[$teacherId]->instructorId = $teacherId;

        $teacherInfo = $DB->get_record('user', ['id' => $teacherId]);
        $teachersDisponibility[$teacherId]->instructorName = $teacherInfo->firstname . ' ' . $teacherInfo->lastname;
        //$teachersDisponibility[$teacherId]->instructorPicture =get_user_picture_url($teacherId);
        $userpicture = new user_picture(core_user::get_user($teacherId));
        $userpicture->size = 1; // Size f1.
        $teachersDisponibility[$teacherId]->instructorPicture = $userpicture->get_url($PAGE)->out(false);


        $teacherSkillsRelations = $DB->get_records('gmk_teacher_skill_relation', ['userid' => $teacherId]);
        $teachersDisponibility[$teacherId]->instructorSkills = [];
        foreach ($teacherSkillsRelations as $teacherSkillsRelation) {
            $teachersDisponibility[$teacherId]->instructorSkills[] = ['name' => $teacherSkills[$teacherSkillsRelation->skillid]->name, 'id' => $teacherSkillsRelation->skillid];
        }

        $teachersDisponibility[$teacherId]->disponibilityRecords = array();

        $teachersDisponibility[$teacherId]->rangeFilterFounded = false;
        foreach ($weekdays as $dayColumnName => $day) {
            if ($incomingTimestampRange && !$teachersDisponibility[$teacherId]->rangeFilterFounded) {
                $teachersDisponibility[$teacherId]->rangeFilterFounded = check_if_time_range_is_contained(json_decode($disponibilityRecord->{$dayColumnName}), $incomingTimestampRangeObject);
            }
            $timeSlots = convert_timestamp_ranges_to_time_ranges($disponibilityRecord->{$dayColumnName});
            if (empty($timeSlots)) {
                continue;
            };
            $teachersDisponibility[$teacherId]->disponibilityRecords[$day] = $timeSlots;
            $teachersDisponibility[$teacherId]->days[] = $day;
        }
    }
    if ($incomingTimestampRange) {
        $teachersDisponibility = array_filter($teachersDisponibility, function ($teacherDisponibilityRecord) {
            return $teacherDisponibilityRecord->rangeFilterFounded;
        });
    }

    return $teachersDisponibility;
}

function check_class_schedule_availability($instructorId, $classDays, $initTime, $endTime, $classroomId = '', $classId = null, $initDate = null, $endDate = null)
{
    //Check the instructor availability
    global $DB;
    
    // Guard: If no instructor is assigned, skip all availability checks
    if (empty($instructorId) || intval($instructorId) <= 0) {
        return true;
    }
    
    $weekdays = array(
        0 => 'Lunes',
        1 => 'Martes',
        2 => 'Miércoles',
        3 => 'Jueves',
        4 => 'Viernes',
        5 => 'Sábado',
        6 => 'Domingo'
    );
    $errors = array();

    $incomingClassSchedule = explode('/', $classDays);
    $incomingTimestampRange = convert_time_range_to_timestamp_range([$initTime, $endTime]);

    // Parse incoming dates to timestamps
    $incomingInitDateTS = $initDate ? strtotime($initDate) : 0;
    $incomingEndDateTS = $endDate ? strtotime($endDate) : 0;

    $availabilityRecords = get_teachers_disponibility(['instructorId' => $instructorId])[$instructorId]->disponibilityRecords;

    for ($i = 0; $i < 7; $i++) {

        if ($incomingClassSchedule[$i] === "1" && !array_key_exists($weekdays[$i], $availabilityRecords)) {
            $errorString = "El instructor no esta disponible el día " . $weekdays[$i];
            $errors[] = $errorString;
        } else if ($incomingClassSchedule[$i] === "1" && array_key_exists($weekdays[$i], $availabilityRecords)) {
            $foundedAvailableRange = false;
            foreach ($availabilityRecords[$weekdays[$i]] as $timeRange) {
                $availabilityTimestampRange = convert_time_range_to_timestamp_range(explode(', ', $timeRange));
                if ($incomingTimestampRange["initTS"] >= $availabilityTimestampRange["initTS"] && $incomingTimestampRange["endTS"] <= $availabilityTimestampRange["endTS"]) {
                    $foundedAvailableRange = true;
                    break;
                }
            }
            if (!$foundedAvailableRange) {
                $errorString = "El instructor no esta disponible el día " . $weekdays[$i] . " en el horário: " . $initTime . " - " . $endTime;
                $errors[] = $errorString;
            }
        }
    }
    $alreadyAsignedClasses = list_classes(['instructorid' => strval($instructorId)]);

    if ($classId) {
        unset($alreadyAsignedClasses[$classId]);
    }

    foreach ($alreadyAsignedClasses as $alreadyAsignedClass) {
        
        // Date Range Check: Skip if ranges do not overlap
        // Only check if both incoming and existing have valid dates. If any are missing, assume checking is required (or maybe assume overlap).
        // Let's assume strict checking: if dates are provided, we check.
        $existingInitDateTS = $alreadyAsignedClass->initdate;
        $existingEndDateTS = $alreadyAsignedClass->enddate;

        // Overlap Condition: (StartA <= EndB) and (EndA >= StartB)
        // If dates are 0 or null, we treat them as 'always active' or maybe we should default to skipping?
        // Let's assume if dates are set ( > 0 ), we check for non-overlap.
        
        if ($incomingInitDateTS > 0 && $incomingEndDateTS > 0 && $existingInitDateTS > 0 && $existingEndDateTS > 0) {
            // Check if they DO NOT overlap
            if ($incomingInitDateTS > $existingEndDateTS || $incomingEndDateTS < $existingInitDateTS) {
                continue; // No date overlap, so no time conflict is possible.
            }
        }

        $alreadyAsignedClassSchedule = explode('/', $alreadyAsignedClass->classdays);
        $classInitTime = $alreadyAsignedClass->inittimets;
        $classEndTime = $alreadyAsignedClass->endtimets;

        for ($i = 0; $i < 7; $i++) {
            if ($incomingClassSchedule[$i] == $alreadyAsignedClassSchedule[$i] && $incomingClassSchedule[$i] === '1') {
                if (($incomingTimestampRange["initTS"] >= $classInitTime && $incomingTimestampRange["endTS"] <= $classEndTime) || ($incomingTimestampRange["initTS"] < $classInitTime && $incomingTimestampRange["endTS"] > $classInitTime) || ($incomingTimestampRange["initTS"] < $classEndTime && $incomingTimestampRange["endTS"] > $classEndTime)) {
                    $errorString = "La clase " . $alreadyAsignedClass->name . ": " . $weekdays[$i] . " (" . $alreadyAsignedClass->inithourformatted . " - " . $alreadyAsignedClass->endhourformatted . ") se cruza con el horario escogido";
                    $errors[] = $errorString;
                }
            }
        }
    }

    // if($classroomId!==''){
    //     $classesWithSameClassroom= array_filter($classes, function($class) use ($classroomId) {
    //         return $class->classroomid === strval($classroomId);
    //     });
    //     $newClassDaysArray = array_map('intval', explode('/', $classDays));
    //     foreach($classesWithSameClassroom as $class){
    //         $existingClassDaysArray = array_map('intval', explode('/', $class->classdays));
    //         $length = count($newClassDaysArray);

    //         for ($i = 0; $i < $length; $i++) {
    //             if ($newClassDaysArray[$i] === 1 && $existingClassDaysArray[$i] === 1) {
    //                 if (
    //                     ($initTime >= $class->inittime && $initTime <= $class->endtime) ||
    //                     ($endTime >= $class->inittime && $endTime <= $class->endtime) ||
    //                     ($class->inittime >= $initTime && $class->inittime <= $endTime) ||
    //                     ($class->endtime >= $initTime && $class->endtime <= $endTime)
    //                 ) {
    //                     $errorString =  "El salon de clase no esta disponible el día ".$weekdays[$i]." en el horário: ".$initTime." - ".$endTime ;
    //                     $errors[]=$errorString;
    //                 }
    //             }
    //         }
    //     }
    // }
    if (!empty($errors)) {
        throw new Exception(json_encode($errors));
    }
    return true;
}

function get_potential_class_teachers($params)
{

    global $USER, $DB;
    $timePattern = "/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/";
    $weekdays = array(
        0 => 'Lunes',
        1 => 'Martes',
        2 => 'Miércoles',
        3 => 'Jueves',
        4 => 'Viernes',
        5 => 'Sábado',
        6 => 'Domingo'
    );

    $params['classDays'] = $params['classDays'] !== '0/0/0/0/0/0/0' ? $params['classDays'] : null;
    $params['initTime'] = preg_match($timePattern, $params['initTime']) ? $params['initTime'] : null;
    $params['endTime'] = preg_match($timePattern, $params['endTime']) ? $params['endTime'] : null;


    $teacherSkills = $DB->get_records('gmk_teacher_skill');

    //Get the learning plan teachers and complete fullname, email and skills attributes
    $learningPlanTeachers = $DB->get_records("local_learning_users", ['learningplanid' => $params['learningPlanId'], 'userroleid' => 4]);

    $learningPlanTeachers = array_map(function ($teacher) use ($DB, $teacherSkills) {
        try {
            $coreUser = core_user::get_user($teacher->userid);
            if ($coreUser) {
                $teacher->id = (int)$coreUser->id;
                $teacher->fullname = fullname($coreUser);
                $teacher->email = $coreUser->email;
                $teacherSkillsRelations = $DB->get_records('gmk_teacher_skill_relation', ['userid' => $teacher->userid]);
                $teacher->instructorSkills = [];
                foreach ($teacherSkillsRelations as $teacherSkillsRelation) {
                    $teacher->instructorSkills[] = $teacherSkills[$teacherSkillsRelation->skillid]->name;
                }
                return $teacher;
            }
        } catch (Exception $e) {
            return null;
        }
        return null;
    }, $learningPlanTeachers);
    $learningPlanTeachers = array_filter($learningPlanTeachers);


    //Get the learning plan course for the course id given

    if ($params['courseId']) {
        $learningPlanCourse =  $DB->get_record("local_learning_courses", ['id' => $params['courseId']]);
        $learningPlanCourse->fullname = $DB->get_record("course", ['id' => $learningPlanCourse->courseid])->fullname;

        $learningPlanTeachers = array_filter(array_map(function ($teacher) use ($DB, $params, $learningPlanCourse) {
            $teacherHasSkill = false;
            foreach ($teacher->instructorSkills as $teacherSkill) {
                if (containsSubstringIgnoringCaseAndTildes($teacherSkill, $learningPlanCourse->fullname)) {
                    return $teacher;
                }
            }
            return null;
        }, $learningPlanTeachers));
    }



    if ($params['classDays'] && !$params['initTime'] && !$params['endTime']) {
        $incomingClassSchedule = explode('/', $params['classDays']);
        $learningPlanTeachers = array_filter(array_map(function ($teacher) use ($incomingClassSchedule, $weekdays) {
            $availabilityRecords = get_teachers_disponibility(['instructorId' => $teacher->userid])[$teacher->userid]->disponibilityRecords;
            for ($i = 0; $i < 7; $i++) {
                if ($incomingClassSchedule[$i] === "1" && !array_key_exists($weekdays[$i], $availabilityRecords)) {
                    return null;
                }
            }
            return $teacher;
        }, $learningPlanTeachers));
    }
    if ($params['initTime'] || $params['endTime']) {

        $initTime = $params['initTime'] ? $params['initTime'] : updateTimeByMinutes($params['endTime'], -1);
        $endTime = $params['endTime'] ? $params['endTime'] : updateTimeByMinutes($params['initTime'], 1);
        $classDays = $params['classDays'] ? $params['classDays'] : '1/1/1/1/1/1/1';

        $incomingClassSchedule = explode('/', $classDays);
        $incomingTimestampRange = convert_time_range_to_timestamp_range([$initTime, $endTime]);

        $learningPlanTeachers = array_filter(array_map(function ($teacher) use ($incomingClassSchedule, $incomingTimestampRange, $weekdays, $classDays, $params) {
            $availableDays = [];
            $dispResult = get_teachers_disponibility(['instructorId' => $teacher->userid]);
            $dispEntry = isset($dispResult[$teacher->userid]) && is_object($dispResult[$teacher->userid]) ? $dispResult[$teacher->userid] : null;
            $availabilityRecords = ($dispEntry && isset($dispEntry->disponibilityRecords)) ? $dispEntry->disponibilityRecords : null;
            for ($dayIndex = 0; $dayIndex < 7; $dayIndex++) {
                if ($classDays !== '1/1/1/1/1/1/1' && $incomingClassSchedule[$dayIndex] === "1" && !array_key_exists($weekdays[$dayIndex], (array)$availabilityRecords)) {
                    return null;
                }
                if ($incomingClassSchedule[$dayIndex] === "1" && is_array($availabilityRecords) && array_key_exists($weekdays[$dayIndex], $availabilityRecords)) {;
                    $foundedAvailableRange = false;
                    foreach ($availabilityRecords[$weekdays[$dayIndex]] as $timeRange) {
                        $availabilityTimestampRange = convert_time_range_to_timestamp_range(explode(', ', $timeRange));
                        if ($incomingTimestampRange["initTS"] >= $availabilityTimestampRange["initTS"] && $incomingTimestampRange["endTS"] <= $availabilityTimestampRange["endTS"]) {
                            $foundedAvailableRange = true;
                            break;
                        }
                    }
                    if ($classDays !== '1/1/1/1/1/1/1' && !$foundedAvailableRange) {
                        return null;
                    }
                    if ($foundedAvailableRange) {
                        $availableDays[] = $weekdays[$dayIndex];
                    }
                }
            }
            $availableDays = array_filter($availableDays);
            if (!$availableDays) {
                return null;
            }

            $alreadyAsignedClasses = list_classes(['instructorid' => $teacher->userid]);
            if ($params['classId']) {
                // print_object($alreadyAsignedClasses);
                unset($alreadyAsignedClasses[$params['classId']]);
            }
            foreach ($alreadyAsignedClasses as $alreadyAsignedClass) {
                $alreadyAsignedClassSchedule = explode('/', $alreadyAsignedClass->classdays);
                $classInitTime = $alreadyAsignedClass->inittimets;
                $classEndTime = $alreadyAsignedClass->endtimets;

                for ($dayIndex = 0; $dayIndex < 7; $dayIndex++) {
                    if ($incomingClassSchedule[$dayIndex] == $alreadyAsignedClassSchedule[$dayIndex] && $incomingClassSchedule[$dayIndex] === '1') {
                        if (($incomingTimestampRange["initTS"] >= $classInitTime && $incomingTimestampRange["endTS"] <= $classEndTime) || ($incomingTimestampRange["initTS"] < $classInitTime && $incomingTimestampRange["endTS"] > $classInitTime) || ($incomingTimestampRange["initTS"] < $classEndTime && $incomingTimestampRange["endTS"] > $classEndTime)) {
                            $index = array_search($weekdays[$dayIndex], $availableDays);
                            if ($classDays !== '1/1/1/1/1/1/1' && $index !== false) {
                                return null;
                            }
                            if ($index !== false) {
                                unset($availableDays[$index]);
                            }
                        }
                    }
                }
            }
            $availableDays = array_filter($availableDays);
            if (!$availableDays) {
                return null;
            }
            return $teacher;
        }, $learningPlanTeachers));
    }
    // CRITICAL: Ensure the current instructor is ALWAYS included and deduplicate everything by Name/Moodle User ID
    // We deduplicate by Name because we found multiple accounts (e.g. legacy vs new) for the same person (e.g. Lorenzo)
    $finalTeachersMap = [];
    $namesToIds = []; // Map name -> preferred ID

    // Helper to detect MD5-like hashes (32 chars hex)
    $is_hash = function($str) {
        return preg_match('/^[a-f0-9]{32}$/i', trim($str));
    };

    // First, process the filtered learning plan teachers
    foreach ($learningPlanTeachers as $teacher) {
        $moodleId = (int)(property_exists($teacher, 'userid') ? $teacher->userid : $teacher->id);
        if ($moodleId <= 0) continue;

        $nameKey = trim(mb_strtolower($teacher->fullname));
        $hasRealEmail = !$is_hash($teacher->email);

        // Deduplication logic: If name exists, check if we should replace the existing entry
        if (!isset($namesToIds[$nameKey])) {
            $namesToIds[$nameKey] = $moodleId;
            $teacher->id = $moodleId;
            $teacher->userid = $moodleId;
            if (!$hasRealEmail) $teacher->email = ''; // Clean hash
            $finalTeachersMap[$moodleId] = $teacher;
        } else {
            $existingId = $namesToIds[$nameKey];
            $existingTeacher = $finalTeachersMap[$existingId];
            $existingHasRealEmail = !$is_hash($existingTeacher->email);

            // If new one has real email and old one doesn't, swap them
            if ($hasRealEmail && !$existingHasRealEmail) {
                unset($finalTeachersMap[$existingId]);
                $namesToIds[$nameKey] = $moodleId;
                $teacher->id = $moodleId;
                $teacher->userid = $moodleId;
                $finalTeachersMap[$moodleId] = $teacher;
            }
        }
    }

    // Second, ensure the currently assigned instructor is present (even if legacy)
    if ($params['classId']) {
        $currentClass = $DB->get_record('gmk_class', ['id' => $params['classId']], 'instructorid');
        if ($currentClass && !empty($currentClass->instructorid)) {
            $instructorId = (int)$currentClass->instructorid;
            
            if (!isset($finalTeachersMap[$instructorId])) {
                try {
                    $currentTeacherUser = core_user::get_user($instructorId);
                    if ($currentTeacherUser) {
                        $currentNameKey = trim(mb_strtolower(fullname($currentTeacherUser)));
                        
                        // If we have another account with the same name, we should MERGE them
                        // specifically for the UI to show only one entry but selectable as the current ID.
                        $merged = false;
                        if (isset($namesToIds[$currentNameKey])) {
                            $matchingIdInList = $namesToIds[$currentNameKey];
                            
                            // If the one in the list is "better" (real email), we swap the ID 
                            // of the list item to the current instructor ID so it shows as "(Actual)"
                            // but with the good data.
                            $existingTeacher = $finalTeachersMap[$matchingIdInList];
                            if (!$is_hash($existingTeacher->email)) {
                                unset($finalTeachersMap[$matchingIdInList]);
                                $existingTeacher->id = $instructorId;
                                $existingTeacher->userid = $instructorId;
                                $finalTeachersMap[$instructorId] = $existingTeacher;
                                $merged = true;
                            }
                        }

                        if (!$merged) {
                            $teacherObj = new stdClass();
                            $teacherObj->id = $instructorId;
                            $teacherObj->userid = $instructorId;
                            $teacherObj->fullname = fullname($currentTeacherUser);
                            $teacherObj->email = $is_hash($currentTeacherUser->email) ? '' : $currentTeacherUser->email;
                            $teacherObj->instructorSkills = [];
                            $finalTeachersMap[$instructorId] = $teacherObj;
                        }
                    }
                } catch (Exception $e) {
                    gmk_log("ERROR get_potential_class_teachers: Could not fetch current instructor $instructorId");
                }
            }
        }
    }

    return array_values($finalTeachersMap);
}

function create_class($classParams)
{
    global $DB, $USER;

    try {
        $newClass = new stdClass();
        $newClass->name           = $classParams["name"];
        $newClass->type           = $classParams["type"];
        $newClass->learningplanid = $classParams["learningPlanId"];
        $newClass->periodid       = $classParams["periodId"];
        $newClass->courseid       = $classParams["courseId"];
        $newClass->instructorid   = $classParams["instructorId"];
        $newClass->inittime       = $classParams["initTime"];
        $newClass->endtime        = $classParams["endTime"];
        $newClass->initdate       = isset($classParams["initDate"]) ? strtotime($classParams["initDate"]) : 0;
        $newClass->enddate        = isset($classParams["endDate"]) ? strtotime($classParams["endDate"]) : 0;
        $newClass->classdays      = $classParams["classDays"];
        $newClass->classroomid    = $classParams["classroomId"];
        $newClass->classroomcapacity = $classParams["classroomCapacity"];
        $newClass->usermodified   = $USER->id;
        $newClass->timecreated    = time();
        $newClass->timemodified   = time();

        $newClass = fill_computed_class_values($newClass, $classParams);

        //Save the class with the current data and get its ID
        $newClass->id = $DB->insert_record('gmk_class', $newClass);
    } catch (Exception $e) {
        throw $e;
    }

    try {
        //Create the class group and enrol the instructor in it.
        $newClass->groupid = create_class_group($newClass);

        //Create the class course section.
        $newClass->coursesectionid = create_class_section($newClass);

        $updatedClass = $DB->update_record('gmk_class', $newClass);

        create_class_activities($newClass);
    } catch (Exception $e) {
        delete_class($newClass->id);
        throw $e;
    }

    return $newClass->id;
}

/**
 * Build the Moodle group name for a class using the nomenclature:
 * {PERIOD} ({SHIFT_INITIAL}) {SUBJECT_NAME} ({CLASS_TYPE}) {CLASSROOM}
 * Example: 2026-I (S) INGLÉS I (PRESENCIAL) AULA Z
 */
function build_class_group_name($class) {
    global $DB;

    // --- Period name ---
    $periodName = '';
    if (!empty($class->periodid)) {
        $periodName = $DB->get_field('gmk_academic_periods', 'name', ['id' => $class->periodid]) ?: '';
    }

    // --- Shift initial ---
    $shift = !empty($class->shift) ? trim($class->shift) : '';
    $shiftInitialMap = [
        'Sabatino'  => 'S',
        'Diurno'    => 'D',
        'Nocturno'  => 'N',
    ];
    $shiftInitial = $shiftInitialMap[$shift] ?? ($shift ? strtoupper(mb_substr($shift, 0, 1)) : '');

    // --- Subject name ---
    $subjectName = !empty($class->name) ? strtoupper(trim($class->name)) : '';

    // --- Class type label ---
    $typeLabel = '';
    if (!empty($class->typelabel)) {
        $typeLabel = strtoupper(trim($class->typelabel));
    } else {
        $typeMap = [0 => 'PRESENCIAL', 1 => 'VIRTUAL', 2 => 'MIXTA'];
        $typeLabel = $typeMap[$class->type ?? 0] ?? 'PRESENCIAL';
    }

    // --- Classroom ---
    $classroomPart = '';
    if (!empty($class->classroomid)) {
        $roomName = $DB->get_field('gmk_classrooms', 'name', ['id' => $class->classroomid]);
        if ($roomName) {
            $classroomPart = strtoupper(trim($roomName));
        }
    }

    // Assemble: PERIOD (SHIFT) SUBJECT (TYPE) ROOM
    $parts = [];
    if ($periodName) $parts[] = $periodName;
    if ($shiftInitial) $parts[] = "($shiftInitial)";
    if ($subjectName) $parts[] = $subjectName;
    if ($typeLabel) $parts[] = "($typeLabel)";
    if ($classroomPart) $parts[] = $classroomPart;

    return implode(' ', $parts) ?: ($class->name . '-' . $class->id);
}

function create_class_group($class)
{
    global $DB;
    $groupName = build_class_group_name($class);
    $idnumber  = $class->name . '-' . $class->id;

    // Reuse existing group if one with this idnumber already exists in the course
    // (can happen if a previous publish failed after creating the group but before saving groupid).
    $existingGroup = $DB->get_record('groups', ['idnumber' => $idnumber, 'courseid' => $class->corecourseid]);
    if ($existingGroup) {
        gmk_log("INFO: create_class_group — reutilizando grupo existente id={$existingGroup->id} idnumber=$idnumber");
        $newClassGroup = $existingGroup;
    } else {
        $newClassGroup = new stdClass();
        $newClassGroup->idnumber = $idnumber;
        $newClassGroup->name = $groupName;
        $newClassGroup->courseid = $class->corecourseid;
        $newClassGroup->description = 'Group for the ' . $idnumber . ' class';
        $newClassGroup->descriptionformat = 1;
        $newClassGroup->id = groups_create_group($newClassGroup);

        if (!$newClassGroup->id) {
            throw new Exception('Error creating class group');
        }
    }

    if (!empty($class->instructorid) && $class->instructorid > 0) {
        // Verify the instructor exists and is not deleted/suspended before any group operation
        $instructorExists = $GLOBALS['DB']->record_exists('user', [
            'id' => $class->instructorid, 'deleted' => 0, 'suspended' => 0
        ]);
        if ($instructorExists) {
            // Ensure the instructor is enrolled in the course before adding to the group
            $enrolplugin = enrol_get_plugin('manual');
            $courseInstance = get_manual_enroll($class->corecourseid);
            $teacherRoleId = $GLOBALS['DB']->get_field('role', 'id', ['shortname' => 'editingteacher']);
            if ($enrolplugin && $courseInstance && $teacherRoleId) {
                $enrolplugin->enrol_user($courseInstance, $class->instructorid, $teacherRoleId);
            }
            // Non-fatal: log if group membership fails but don't abort class creation
            if (!groups_add_member($newClassGroup->id, $class->instructorid)) {
                gmk_log("WARNING: Could not add instructor {$class->instructorid} to group {$newClassGroup->id}");
            }
        } else {
            gmk_log("WARNING: Instructor {$class->instructorid} not found or suspended, skipping group assignment");
        }
    }
    return $newClassGroup->id;
}

function create_class_section($class)
{

    $section = course_create_section($class->corecourseid);
    course_update_section($class->corecourseid, $section, [
        'name' => $class->name . '-' . $class->id,
        'availability' => '{"op":"&","c":[{"type":"group","id":' . $class->groupid . '}],"showc":[true]}'
    ]);
    return $section->id;
}

/**
 * Validate that the class group exists and belongs to the class course.
 */
function gmk_is_valid_class_group($class, &$reason = '')
{
    global $DB;

    $reason = '';
    if (empty($class->groupid)) {
        $reason = 'groupid vacio';
        return false;
    }

    $group = $DB->get_record('groups', ['id' => $class->groupid], 'id, courseid');
    if (!$group) {
        $reason = 'grupo no existe';
        return false;
    }

    if (!empty($class->corecourseid) && (int)$group->courseid !== (int)$class->corecourseid) {
        $reason = "grupo en curso {$group->courseid}, esperado {$class->corecourseid}";
        return false;
    }

    return true;
}

/**
 * Validate that the class section exists and belongs to the class course.
 */
function gmk_is_valid_class_section($class, &$reason = '')
{
    global $DB;

    $reason = '';
    if (empty($class->coursesectionid)) {
        $reason = 'coursesectionid vacio';
        return false;
    }

    $section = $DB->get_record('course_sections', ['id' => $class->coursesectionid], 'id, course');
    if (!$section) {
        $reason = 'seccion no existe';
        return false;
    }

    if (!empty($class->corecourseid) && (int)$section->course !== (int)$class->corecourseid) {
        $reason = "seccion en curso {$section->course}, esperado {$class->corecourseid}";
        return false;
    }

    return true;
}

/**
 * Validate that the attendance cmid exists and belongs to this class course/section.
 */
function gmk_is_valid_class_attendance_module($class, &$reason = '')
{
    global $DB;

    $reason = '';
    if (empty($class->attendancemoduleid)) {
        $reason = 'attendancemoduleid vacio';
        return false;
    }

    if (empty($class->coursesectionid)) {
        $reason = 'coursesectionid vacio';
        return false;
    }

    $cm = $DB->get_record_sql(
        "SELECT cm.id, cm.course, cm.section, cm.instance, m.name AS modulename
           FROM {course_modules} cm
           JOIN {modules} m ON m.id = cm.module
          WHERE cm.id = :cmid",
        ['cmid' => (int)$class->attendancemoduleid]
    );
    if (!$cm) {
        $reason = 'course module no existe';
        return false;
    }

    if ($cm->modulename !== 'attendance') {
        $reason = "course module no es attendance ({$cm->modulename})";
        return false;
    }

    if (!empty($class->corecourseid) && (int)$cm->course !== (int)$class->corecourseid) {
        $reason = "attendance en curso {$cm->course}, esperado {$class->corecourseid}";
        return false;
    }

    if (!empty($class->coursesectionid) && (int)$cm->section !== (int)$class->coursesectionid) {
        $reason = "attendance en seccion {$cm->section}, esperado {$class->coursesectionid}";
        return false;
    }

    if (!$DB->record_exists('attendance', ['id' => $cm->instance])) {
        $reason = "instancia attendance {$cm->instance} no existe";
        return false;
    }

    return true;
}

/**
 * Resolve a Moodle module id by name, tolerating accidental duplicate rows.
 */
function gmk_get_module_id_by_name($modulename)
{
    global $DB;

    $mods = $DB->get_records('modules', ['name' => $modulename], 'id ASC', 'id,name');
    if (empty($mods)) {
        throw new \Exception("No existe el modulo '{$modulename}' en la tabla modules");
    }

    $first = reset($mods);
    if (count($mods) > 1) {
        gmk_log(
            "WARNING: Se encontraron " . count($mods) . " registros en modules para '{$modulename}' " .
            "(ids=" . implode(',', array_keys($mods)) . "). Se usara id={$first->id}."
        );
    }

    return (int)$first->id;
}

/**
 * Normalize malformed course grade item rows that break grade_update() during module creation.
 *
 * Some legacy courses have a single course grade item with iteminstance != courseid
 * (e.g. iteminstance = grade_category.id). When attendance add_instance triggers grade_update,
 * that inconsistency can cause duplicate-key errors in grade_grades and leave transactions broken.
 */
function gmk_heal_course_gradebook_course_item($courseid) {
    global $DB;

    $courseid = (int)$courseid;
    if ($courseid <= 0) {
        return;
    }

    $rootcat = $DB->get_record_sql(
        "SELECT id FROM {grade_categories}
          WHERE courseid = :courseid AND depth = 1
       ORDER BY id ASC LIMIT 1",
        ['courseid' => $courseid]
    );
    $rootcatid = $rootcat ? (int)$rootcat->id : 0;

    $courseitems = $DB->get_records('grade_items', ['courseid' => $courseid, 'itemtype' => 'course'], 'id ASC', 'id,iteminstance,categoryid');
    if (empty($courseitems)) {
        return;
    }

    $valid = [];
    foreach ($courseitems as $it) {
        if ((int)$it->iteminstance === $courseid) {
            $valid[] = $it;
        }
    }

    if (!empty($valid)) {
        // Keep valid rows untouched; just align category to root when possible.
        if ($rootcatid > 0) {
            foreach ($valid as $it) {
                if ((int)$it->categoryid !== $rootcatid) {
                    $DB->set_field('grade_items', 'categoryid', $rootcatid, ['id' => (int)$it->id]);
                    gmk_log("INFO: gradebook heal - course item {$it->id} recategorizado a root {$rootcatid} (courseid={$courseid})");
                }
            }
        }
        return;
    }

    // No valid iteminstance=courseid: salvage one existing course item instead of creating a new one.
    $chosen = reset($courseitems);
    if ($chosen) {
        $chosenid = (int)$chosen->id;
        $DB->set_field('grade_items', 'iteminstance', $courseid, ['id' => $chosenid]);
        if ($rootcatid > 0) {
            $DB->set_field('grade_items', 'categoryid', $rootcatid, ['id' => $chosenid]);
        }
        gmk_log("INFO: gradebook heal - course item {$chosenid} iteminstance corregido a {$courseid}" .
            ($rootcatid > 0 ? " y categoryid={$rootcatid}" : '') .
            " (courseid={$courseid})");
    }
}

/**
 * Detects the "more than one record in read()" family of Moodle errors.
 */
function gmk_is_duplicate_read_error($message): bool {
    $msg = (string)$message;
    if (function_exists('mb_strtolower')) {
        $msg = mb_strtolower($msg);
    } else {
        $msg = strtolower($msg);
    }
    return (
        strpos($msg, 'more than one record in read') !== false ||
        strpos($msg, 'mas de un registro en lectura') !== false ||
        strpos($msg, 'más de un registro en lectura') !== false
    );
}

/**
 * Rebuild grade category paths/depth recursively from a parent node.
 */
function gmk_rebuild_grade_category_tree(int $parentid, string $parentpath, int $depth, int $maxdepth = 30): void {
    global $DB;

    if ($depth > $maxdepth) {
        gmk_log("WARNING: gradebook repair maxdepth alcanzado parent={$parentid}");
        return;
    }

    $children = $DB->get_records('grade_categories', ['parent' => $parentid], 'id ASC', 'id,parent,depth,path');
    foreach ($children as $child) {
        $newpath = $parentpath . $child->id . '/';
        if ((int)$child->depth !== $depth) {
            $DB->set_field('grade_categories', 'depth', $depth, ['id' => (int)$child->id]);
        }
        if ((string)$child->path !== $newpath) {
            $DB->set_field('grade_categories', 'path', $newpath, ['id' => (int)$child->id]);
        }
        gmk_rebuild_grade_category_tree((int)$child->id, $newpath, $depth + 1, $maxdepth);
    }
}

/**
 * Move grade rows from a duplicate grade_item into a canonical one.
 *
 * Returns number of processed source grade rows.
 */
function gmk_merge_grade_item_grades(int $sourceitemid, int $targetitemid): int {
    global $DB;

    $sourceitemid = (int)$sourceitemid;
    $targetitemid = (int)$targetitemid;
    if ($sourceitemid <= 0 || $targetitemid <= 0 || $sourceitemid === $targetitemid) {
        return 0;
    }

    $processed = 0;
    $sourcegrades = $DB->get_records(
        'grade_grades',
        ['itemid' => $sourceitemid],
        'id ASC',
        'id,userid,rawgrade,finalgrade,rawgrademax,rawgrademin,rawscaleid,overridden,excluded,feedback,feedbackformat,information,informationformat'
    );

    foreach ($sourcegrades as $gg) {
        // Avoid get_record() warnings when legacy corruption left duplicate rows per (itemid, userid).
        $existing = $DB->get_record_sql(
            "SELECT id, rawgrade, finalgrade, rawgrademax, rawgrademin, rawscaleid, feedback, information
               FROM {grade_grades}
              WHERE itemid = :itemid
                AND userid = :userid
           ORDER BY id ASC LIMIT 1",
            ['itemid' => $targetitemid, 'userid' => (int)$gg->userid]
        );

        if ($existing) {
            $upd = new stdClass();
            $upd->id = (int)$existing->id;
            $changed = false;

            if ($existing->finalgrade === null && $gg->finalgrade !== null) {
                $upd->finalgrade = $gg->finalgrade;
                $changed = true;
            }
            if ($existing->rawgrade === null && $gg->rawgrade !== null) {
                $upd->rawgrade = $gg->rawgrade;
                $changed = true;
            }
            if ($existing->rawgrademax === null && $gg->rawgrademax !== null) {
                $upd->rawgrademax = $gg->rawgrademax;
                $changed = true;
            }
            if ($existing->rawgrademin === null && $gg->rawgrademin !== null) {
                $upd->rawgrademin = $gg->rawgrademin;
                $changed = true;
            }
            if ($existing->rawscaleid === null && $gg->rawscaleid !== null) {
                $upd->rawscaleid = $gg->rawscaleid;
                $changed = true;
            }
            if (empty($existing->feedback) && !empty($gg->feedback)) {
                $upd->feedback = $gg->feedback;
                $upd->feedbackformat = $gg->feedbackformat;
                $changed = true;
            }
            if (empty($existing->information) && !empty($gg->information)) {
                $upd->information = $gg->information;
                $upd->informationformat = $gg->informationformat;
                $changed = true;
            }

            if ($changed) {
                $upd->timemodified = time();
                $DB->update_record('grade_grades', $upd);
            }

            $DB->delete_records('grade_grades', ['id' => (int)$gg->id]);
            $processed++;
            continue;
        }

        $DB->set_field('grade_grades', 'itemid', $targetitemid, ['id' => (int)$gg->id]);
        $processed++;
    }

    return $processed;
}

/**
 * Repairs duplicate root categories / malformed course grade items for one course.
 *
 * @return array{
 *   rootCandidates:int,
 *   rootcats:int,
 *   courseitems:int,
 *   canonicalRootId:int,
 *   mergedRoots:int,
 *   deletedCourseItems:int,
 *   relinkedCourseItems:int
 * }
 */
function gmk_repair_course_gradebook_duplicates(int $courseid): array {
    global $DB, $CFG;

    require_once($CFG->libdir . '/gradelib.php');

    $stats = [
        'rootCandidates' => 0,
        'rootcats' => 0,
        'courseitems' => 0,
        'canonicalRootId' => 0,
        'mergedRoots' => 0,
        'deletedCourseItems' => 0,
        'relinkedCourseItems' => 0,
        'createdCourseItems' => 0,
        'fixedOrphanCategoryItems' => 0,
        'dedupedCategoryItems' => 0,
        'mergedGradeRows' => 0,
    ];

    $courseid = (int)$courseid;
    if ($courseid <= 0) {
        return $stats;
    }

    // First, normalize malformed course item instance/category links.
    gmk_heal_course_gradebook_course_item($courseid);

    // Ensure there is at least one root candidate.
    // A course can have many grade_categories; pick one deterministically without get_record() duplicate warnings.
    $anycat = $DB->get_record_sql(
        "SELECT id
           FROM {grade_categories}
          WHERE courseid = :courseid
       ORDER BY id ASC LIMIT 1",
        ['courseid' => $courseid]
    );
    if ($anycat) {
        $DB->set_field('grade_categories', 'parent', null, ['id' => (int)$anycat->id]);
    }

    $rootcandidates = $DB->get_records_select(
        'grade_categories',
        'courseid = :c AND (parent IS NULL OR parent = 0)',
        ['c' => $courseid],
        'id ASC',
        'id,courseid,parent,depth,path,fullname'
    );
    $stats['rootCandidates'] = count($rootcandidates);

    $rootcats = array_filter($rootcandidates, static function($r) {
        return (int)$r->depth === 1;
    });
    $stats['rootcats'] = count($rootcats);

    $courseitems = $DB->get_records('grade_items', [
        'courseid' => $courseid,
        'itemtype' => 'course',
        'iteminstance' => $courseid
    ], 'id ASC', 'id,categoryid');
    $stats['courseitems'] = count($courseitems);

    $rootids = array_map('intval', array_keys($rootcandidates));
    $canonicalrootid = 0;
    foreach ($courseitems as $it) {
        $cid = (int)$it->categoryid;
        if ($cid > 0 && in_array($cid, $rootids, true)) {
            $canonicalrootid = $cid;
            break;
        }
    }
    if ($canonicalrootid <= 0 && !empty($rootcats)) {
        $canonicalrootid = (int)array_key_first($rootcats);
    }
    if ($canonicalrootid <= 0 && !empty($rootids)) {
        $canonicalrootid = (int)min($rootids);
    }
    if ($canonicalrootid <= 0 && $anycat) {
        $canonicalrootid = (int)$anycat->id;
    }
    $stats['canonicalRootId'] = $canonicalrootid;

    if ($canonicalrootid > 0) {
        foreach ($rootcandidates as $rid => $cat) {
            $rid = (int)$rid;
            if ($rid === $canonicalrootid) {
                continue;
            }

            // Move child categories and grade items to canonical root.
            if ($DB->record_exists('grade_categories', ['parent' => $rid])) {
                $DB->set_field('grade_categories', 'parent', $canonicalrootid, ['parent' => $rid]);
            }
            if ($DB->record_exists('grade_items', ['categoryid' => $rid])) {
                $DB->set_field('grade_items', 'categoryid', $canonicalrootid, ['categoryid' => $rid]);
            }
            if ($DB->record_exists('grade_items', ['courseid' => $courseid, 'itemtype' => 'category', 'iteminstance' => $rid])) {
                $DB->set_field('grade_items', 'iteminstance', $canonicalrootid, [
                    'courseid' => $courseid,
                    'itemtype' => 'category',
                    'iteminstance' => $rid
                ]);
            }

            $DB->delete_records('grade_categories', ['id' => $rid]);
            $stats['mergedRoots']++;
        }

        // Ensure canonical root has valid root shape, then fix descendants.
        $DB->set_field('grade_categories', 'parent', null, ['id' => $canonicalrootid]);
        $DB->set_field('grade_categories', 'depth', 1, ['id' => $canonicalrootid]);
        $DB->set_field('grade_categories', 'path', '/' . $canonicalrootid . '/', ['id' => $canonicalrootid]);
        gmk_rebuild_grade_category_tree($canonicalrootid, '/' . $canonicalrootid . '/', 2);

        // Sanitize broken category parent links that can break get_children recursion.
        $allcats = $DB->get_records('grade_categories', ['courseid' => $courseid], 'id ASC', 'id,parent');
        $allcatids = array_map('intval', array_keys($allcats));
        foreach ($allcats as $catid => $catrow) {
            $catid = (int)$catid;
            if ($catid === $canonicalrootid) {
                continue;
            }
            $parentid = (int)$catrow->parent;
            $parentexists = ($parentid > 0) && in_array($parentid, $allcatids, true);
            $selfparent = ($parentid === $catid);
            if ($selfparent || !$parentexists) {
                $DB->set_field('grade_categories', 'parent', $canonicalrootid, ['id' => $catid]);
            }
        }
        gmk_rebuild_grade_category_tree($canonicalrootid, '/' . $canonicalrootid . '/', 2);
    }

    // Fix orphan category-total items (iteminstance points to missing category).
    $categoryitems = $DB->get_records('grade_items', [
        'courseid' => $courseid,
        'itemtype' => 'category'
    ], 'id ASC', 'id,courseid,iteminstance,categoryid');
    foreach ($categoryitems as $it) {
        $inst = (int)$it->iteminstance;
        $existscat = ($inst > 0) ? $DB->record_exists('grade_categories', ['id' => $inst, 'courseid' => $courseid]) : false;
        if ($existscat) {
            continue;
        }

        if ($canonicalrootid > 0) {
            $DB->set_field('grade_items', 'iteminstance', $canonicalrootid, ['id' => (int)$it->id]);
            if ((int)$it->categoryid !== $canonicalrootid) {
                $DB->set_field('grade_items', 'categoryid', $canonicalrootid, ['id' => (int)$it->id]);
            }
            $stats['fixedOrphanCategoryItems']++;
        } else {
            $hasgrades = $DB->record_exists('grade_grades', ['itemid' => (int)$it->id]);
            if (!$hasgrades) {
                $DB->delete_records('grade_items', ['id' => (int)$it->id]);
                $stats['fixedOrphanCategoryItems']++;
            }
        }
    }

    // Deduplicate category-total grade items by iteminstance (must be unique per category).
    $categoryitems = $DB->get_records('grade_items', [
        'courseid' => $courseid,
        'itemtype' => 'category'
    ], 'id ASC', 'id,iteminstance');
    $byinstance = [];
    foreach ($categoryitems as $it) {
        $iid = (int)$it->iteminstance;
        if ($iid <= 0) {
            continue;
        }
        if (!isset($byinstance[$iid])) {
            $byinstance[$iid] = [];
        }
        $byinstance[$iid][] = (int)$it->id;
    }
    foreach ($byinstance as $iid => $itemids) {
        if (count($itemids) <= 1) {
            continue;
        }

        $keepid = 0;
        foreach ($itemids as $candidate) {
            if ($DB->record_exists('grade_grades', ['itemid' => (int)$candidate])) {
                $keepid = (int)$candidate;
                break;
            }
        }
        if ($keepid <= 0) {
            $keepid = (int)$itemids[0];
        }

        foreach ($itemids as $dupitemid) {
            $dupitemid = (int)$dupitemid;
            if ($dupitemid === $keepid) {
                continue;
            }
            $stats['mergedGradeRows'] += gmk_merge_grade_item_grades($dupitemid, $keepid);
            $DB->delete_records('grade_items', ['id' => $dupitemid]);
            $stats['dedupedCategoryItems']++;
        }
    }

    // Ensure a course total grade_item exists (itemtype=course, iteminstance=courseid).
    $courseitems = $DB->get_records('grade_items', [
        'courseid' => $courseid,
        'itemtype' => 'course',
        'iteminstance' => $courseid
    ], 'id ASC', 'id,categoryid');
    if (empty($courseitems) && $canonicalrootid > 0) {
        try {
            $courseitem = new \grade_item();
            $courseitem->courseid = $courseid;
            $courseitem->categoryid = $canonicalrootid;
            $courseitem->itemtype = 'course';
            $courseitem->iteminstance = $courseid;
            $courseitem->itemnumber = 0;
            $courseitem->gradetype = GRADE_TYPE_VALUE;
            $courseitem->grademax = 100;
            $courseitem->grademin = 0;
            $courseitem->sortorder = 1;
            $courseitem->insert();
            $stats['createdCourseItems']++;
        } catch (\Throwable $ce) {
            gmk_log("WARNING: gradebook repair no pudo crear course grade_item courseid={$courseid}: " . $ce->getMessage());
        }
    }

    // Deduplicate course grade items if multiple rows still exist.
    $courseitems = $DB->get_records('grade_items', [
        'courseid' => $courseid,
        'itemtype' => 'course',
        'iteminstance' => $courseid
    ], 'id ASC', 'id,categoryid');

    if (count($courseitems) > 1) {
        $keep = null;
        foreach ($courseitems as $it) {
            if ($canonicalrootid > 0 && (int)$it->categoryid === $canonicalrootid) {
                $keep = $it;
                break;
            }
        }
        if (!$keep) {
            $keep = reset($courseitems);
        }
        $keepid = (int)$keep->id;

        foreach ($courseitems as $itid => $it) {
            $itid = (int)$itid;
            if ($itid === $keepid) {
                continue;
            }
            $stats['mergedGradeRows'] += gmk_merge_grade_item_grades($itid, $keepid);
            $DB->delete_records('grade_items', ['id' => $itid]);
            $stats['deletedCourseItems']++;
        }
    }

    if ($canonicalrootid > 0) {
        $courseitems = $DB->get_records('grade_items', [
            'courseid' => $courseid,
            'itemtype' => 'course',
            'iteminstance' => $courseid
        ], 'id ASC', 'id,categoryid');
        foreach ($courseitems as $it) {
            if ((int)$it->categoryid !== $canonicalrootid) {
                $DB->set_field('grade_items', 'categoryid', $canonicalrootid, ['id' => (int)$it->id]);
                $stats['relinkedCourseItems']++;
            }
        }
    }

    return $stats;
}

/**
 * True if the course module id is linked in the section sequence.
 */
function gmk_section_sequence_contains_cmid($sectionid, $cmid)
{
    global $DB;

    if (empty($sectionid) || empty($cmid)) {
        return false;
    }

    $sequence = $DB->get_field('course_sections', 'sequence', ['id' => (int)$sectionid]);
    if ($sequence === false || $sequence === null || $sequence === '') {
        return false;
    }

    $cmids = array_values(array_filter(array_map('intval', explode(',', (string)$sequence))));
    return in_array((int)$cmid, $cmids, true);
}

/**
 * Ensure a course module id is present in section sequence for Moodle course display.
 */
function gmk_ensure_cmid_in_section_sequence($sectionid, $cmid)
{
    global $DB;

    $sectionid = (int)$sectionid;
    $cmid = (int)$cmid;
    if ($sectionid <= 0 || $cmid <= 0) {
        return false;
    }

    $section = $DB->get_record('course_sections', ['id' => $sectionid], 'id,sequence', MUST_EXIST);
    $sequence = trim((string)$section->sequence);
    $cmids = $sequence === '' ? [] : array_values(array_filter(array_map('intval', explode(',', $sequence))));
    if (in_array($cmid, $cmids, true)) {
        return true;
    }

    $cmids[] = $cmid;
    $newsequence = implode(',', $cmids);
    $DB->set_field('course_sections', 'sequence', $newsequence, ['id' => $sectionid]);
    return true;
}

/**
 * Strict activity stack validation: attendance + sessions + BBB links.
 */
function gmk_is_class_activity_stack_complete($class, &$reason = '')
{
    global $DB;

    $reason = '';
    $attReason = '';
    if (!gmk_is_valid_class_attendance_module($class, $attReason)) {
        $reason = $attReason;
        return false;
    }

    $attendanceid = $DB->get_field('course_modules', 'instance', ['id' => (int)$class->attendancemoduleid]);
    if (empty($attendanceid)) {
        $reason = "attendance instance no encontrada para cmid {$class->attendancemoduleid}";
        return false;
    }

    $sessionCount = $DB->count_records('attendance_sessions', ['attendanceid' => (int)$attendanceid]);
    if ($sessionCount <= 0) {
        $reason = "attendance {$attendanceid} sin sesiones";
        return false;
    }

    $relationCount = $DB->count_records('gmk_bbb_attendance_relation', [
        'classid' => (int)$class->id,
        'attendancemoduleid' => (int)$class->attendancemoduleid
    ]);
    if ($relationCount <= 0) {
        $reason = "sin relaciones gmk_bbb_attendance_relation para la clase";
        return false;
    }

    $params = ['classid' => (int)$class->id, 'attcmid' => (int)$class->attendancemoduleid];
    $bbbRows = $DB->get_records_sql(
        "SELECT DISTINCT bbbmoduleid
           FROM {gmk_bbb_attendance_relation}
          WHERE classid = :classid
            AND attendancemoduleid = :attcmid
            AND bbbmoduleid IS NOT NULL
            AND bbbmoduleid > 0",
        $params
    );
    if (empty($bbbRows)) {
        $reason = 'sin modulos BBB vinculados a las sesiones';
        return false;
    }

    $validBBB = 0;
    foreach ($bbbRows as $row) {
        $bbbcmid = (int)$row->bbbmoduleid;
        $bbbcm = $DB->get_record_sql(
            "SELECT cm.id, cm.course, cm.section, m.name AS modulename
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.id = :cmid",
            ['cmid' => $bbbcmid]
        );
        if (!$bbbcm) {
            continue;
        }
        if ($bbbcm->modulename !== 'bigbluebuttonbn') {
            continue;
        }
        if (!empty($class->corecourseid) && (int)$bbbcm->course !== (int)$class->corecourseid) {
            continue;
        }
        if (!empty($class->coursesectionid) && (int)$bbbcm->section !== (int)$class->coursesectionid) {
            continue;
        }
        if (!gmk_section_sequence_contains_cmid((int)$class->coursesectionid, (int)$bbbcmid)) {
            continue;
        }
        $validBBB++;
    }

    if ($validBBB <= 0) {
        $reason = 'los BBB vinculados no son validos o no estan visibles en la seccion';
        return false;
    }

    return true;
}

function delete_class($classId, $reason =  null)
{
    global $DB, $USER;

    // Check if class is closed
    if (is_class_closed($classId)) {
        throw new \moodle_exception('error_class_closed_modification', 'local_grupomakro_core');
    }

    $class = $DB->get_record('gmk_class', ['id' => $classId]);

    if ($class->gradecategoryid && !empty($class->corecourseid) && $DB->record_exists('course', ['id' => $class->corecourseid])) {
        // Performance: avoid building full grade_tree (expensive recursive get_children on every class delete).
        $classGradeCategory = \grade_category::fetch([
            'id' => (int)$class->gradecategoryid,
            'courseid' => (int)$class->corecourseid
        ]);
        if ($classGradeCategory) {
            $classGradeCategory->delete();
        }
    }

    //Delete section if it's already created and all the activities in it.
    if ($class->coursesectionid && !empty($class->corecourseid) && $DB->record_exists('course', ['id' => $class->corecourseid])) {
        $section = $DB->get_field('course_sections', 'section', ['id' => $class->coursesectionid]);
        if ($section !== false) {
            course_delete_section($class->corecourseid, $section, true, true);
        }
        $DB->delete_records('gmk_bbb_attendance_relation', ['classid' => $class->id]);
    }

    //Delete class group if it's already created
    if ($class->groupid) {
        groups_delete_group($class->groupid);
    }

    //Delete registry and queue record related to the class
    $DB->delete_records('gmk_class_pre_registration', ['classid' => $class->id]);
    $DB->delete_records('gmk_class_queue', ['classid' => $class->id]);

    //Add the deletion message to the table
    $classDeletionMessage = new stdClass();
    $classDeletionMessage->classid = $class->id;
    $classDeletionMessage->deletionmessage = $reason;
    $classDeletionMessage->usermodified = $USER->id;
    $classDeletionMessage->timecreated = time();
    $classDeletionMessage->timemodified = time();
    $DB->insert_record('gmk_class_deletion_message', $classDeletionMessage);

    //Delete the class
    return $DB->delete_records('gmk_class', ['id' => $class->id]);
}
/**
 * Create or updated (delete and recreate) the activities for the given class
 *
 * @return array
 */
function create_class_activities($class, $updating = false)
{
    global $DB, $USER;
    // if($classParams["classroomId"]!== ''){
    //         $classroomsReservations = createClassroomReservations($newClass);
    //     }
    $attendanceStructure = null;

    $class->course = get_course($class->corecourseid);
    $classSectionInfo = $DB->get_record('course_sections', ['id' => $class->coursesectionid], 'id,course,section');
    if (!$classSectionInfo) {
        throw new \Exception("La seccion {$class->coursesectionid} no existe para la clase {$class->id}");
    }
    if ((int)$classSectionInfo->course !== (int)$class->corecourseid) {
        throw new \Exception(
            "La seccion {$class->coursesectionid} pertenece al curso {$classSectionInfo->course} " .
            "y no al curso {$class->corecourseid} de la clase {$class->id}"
        );
    }
    $classSectionNumber = (int)$classSectionInfo->section;

    $attendanceReason = '';
    $hasValidAttendance = gmk_is_valid_class_attendance_module($class, $attendanceReason);
    if (!$hasValidAttendance && !empty($class->attendancemoduleid)) {
        gmk_log("WARNING: class {$class->id} tiene attendancemoduleid invalido {$class->attendancemoduleid}: {$attendanceReason}. Se recreara attendance.");
        $class->attendancemoduleid = 0;
        $class->bbbmoduleids = null;
        if (!empty($class->id)) {
            $DB->set_field('gmk_class', 'attendancemoduleid', 0, ['id' => $class->id]);
            $DB->set_field('gmk_class', 'bbbmoduleids', null, ['id' => $class->id]);
            $DB->delete_records('gmk_bbb_attendance_relation', ['classid' => $class->id]);
        }
        $updating = false;
    }

    if ($updating && !$hasValidAttendance) {
        // Defensive fallback: never run update mode if the base attendance module is invalid.
        $updating = false;
    }

    if ($updating) {
        //Delete Big Blue Button Sessions
        foreach (explode(",", $class->bbbmoduleids) as $BBBModuleId) {
            if (empty($BBBModuleId)) {
                continue;
            }
            try {
                course_delete_module($BBBModuleId);
            } catch (Exception $e) {
                // Ignore errors if module cannot be deleted (already gone, etc)
                // We just want to ensure cleanup proceeds.
            }
        }

        //Delete attendance sessions
        $attendanceCourseModule  = get_coursemodule_from_id('attendance', $class->attendancemoduleid, 0, false, MUST_EXIST);
        $attendanceRecord = $DB->get_record('attendance', array('id' => $attendanceCourseModule->instance), '*', MUST_EXIST);
        $attendanceStructure = new \mod_attendance_structure($attendanceRecord, $attendanceCourseModule, $class->course);
        gmk_ensure_cmid_in_section_sequence((int)$class->coursesectionid, (int)$attendanceCourseModule->id);
        $attendanceSessionIdsToBeDeleted = $DB->get_records('gmk_bbb_attendance_relation', ['classid' => $class->id], '', 'attendancesessionid');
        if (!empty(array_keys($attendanceSessionIdsToBeDeleted))) {
            $attendanceStructure->delete_sessions(array_keys($attendanceSessionIdsToBeDeleted));
        }

        //Delete attendance - BBB sessions relation
        $DB->delete_records('gmk_bbb_attendance_relation', ['classid' => $class->id]);
    } else {
        // Reuse attendance module if already created and still valid for this class.
        if (gmk_is_valid_class_attendance_module($class, $attendanceReason)) {
            $attendanceCourseModule = get_coursemodule_from_id('attendance', $class->attendancemoduleid, 0, false, MUST_EXIST);
            $attendanceRecord = $DB->get_record('attendance', ['id' => $attendanceCourseModule->instance], '*', MUST_EXIST);
            $attendanceStructure = new \mod_attendance_structure($attendanceRecord, $attendanceCourseModule, $class->course);
            gmk_ensure_cmid_in_section_sequence((int)$class->coursesectionid, (int)$attendanceCourseModule->id);
            gmk_log("INFO: create_class_activities — reutilizando attendance existente cmid={$class->attendancemoduleid}");
        } else {
            // Prevent known legacy gradebook corruption from breaking attendance module creation.
            try {
                gmk_heal_course_gradebook_course_item((int)$class->corecourseid);
            } catch (Throwable $healErr) {
                gmk_log("WARNING: gradebook heal fallo para courseid={$class->corecourseid}: " . $healErr->getMessage());
            }

            try {
                $attendanceActivityInfo = create_attendance_activity($class, $classSectionNumber);
            } catch (Throwable $attErr) {
                // add_moduleinfo throws when grade recalc or messaging fails.
                // The module IS often created in the DB before the exception — try to recover it.
                $attErrMsg = $attErr->getMessage();
                $attErrClass = get_class($attErr);
                $attErrLocation = basename($attErr->getFile()) . ':' . $attErr->getLine();
                $attErrDebugInfo = (property_exists($attErr, 'debuginfo') && !empty($attErr->debuginfo))
                    ? ' | debuginfo: ' . (string)$attErr->debuginfo
                    : '';
                gmk_log("WARNING create_attendance_activity threw for class {$class->id}: {$attErrMsg}"
                    . " (courseid={$class->corecourseid}, sectionid={$class->coursesectionid}, sectionnum={$classSectionNumber})");
                $attModId = gmk_get_module_id_by_name('attendance');
                // course_modules.section stores the course_sections.id (not the section number)
                $existingCm = $attModId ? $DB->get_record_sql(
                    "SELECT id, instance FROM {course_modules} WHERE course=:c AND module=:m AND section=:s ORDER BY id DESC LIMIT 1",
                    ['c' => $class->corecourseid, 'm' => $attModId, 's' => $class->coursesectionid]
                ) : null;
                // Fallback: recover by unique class-id suffix in attendance name.
                if (!$existingCm && $attModId) {
                    $existingCm = $DB->get_record_sql(
                        "SELECT cm.id, cm.instance
                           FROM {course_modules} cm
                           JOIN {attendance} a ON a.id = cm.instance
                          WHERE cm.course = :c
                            AND cm.module = :m
                            AND " . $DB->sql_like('a.name', ':suffix') . "
                       ORDER BY cm.id DESC LIMIT 1",
                        ['c' => $class->corecourseid, 'm' => $attModId, 'suffix' => '%-' . $class->id]
                    );
                }
                if ($existingCm) {
                    $attendanceActivityInfo = (object)['coursemodule' => $existingCm->id];
                    gmk_log("INFO: Recuperado attendance cmid={$existingCm->id} para clase {$class->id}");
                } else {
                    // Module was NOT created at all — report with original error
                    gmk_log("ERROR: No se pudo crear ni recuperar attendance para clase {$class->id}: {$attErrMsg}");
                    throw new \Exception(
                        "No se pudo crear attendance para clase {$class->id}: [{$attErrClass} @ {$attErrLocation}] {$attErrMsg}{$attErrDebugInfo}",
                        0,
                        $attErr
                    );
                }
            }
            $attendanceCourseModule  = get_coursemodule_from_id('attendance', $attendanceActivityInfo->coursemodule, 0, false, MUST_EXIST);
            $attendanceRecord = $DB->get_record('attendance', ['id' => $attendanceCourseModule->instance], '*', MUST_EXIST);
            $attendanceStructure = new \mod_attendance_structure($attendanceRecord, $attendanceCourseModule, $class->course);
            $class->attendancemoduleid = $attendanceStructure->cmid;
            gmk_ensure_cmid_in_section_sequence((int)$class->coursesectionid, (int)$attendanceStructure->cmid);
            $DB->update_record('gmk_class', $class);
        }

        // Reuse grade category if already created.
        // Use class id suffix to find categories from partial previous publishes regardless of name changes.
        $existingCatId = null;
        if (!empty($class->gradecategoryid) && $DB->record_exists('grade_categories', ['id' => $class->gradecategoryid, 'courseid' => $class->corecourseid])) {
            $existingCatId = (int)$class->gradecategoryid;
            gmk_log("INFO: create_class_activities — reutilizando gradecategory por id={$existingCatId}");
        } else {
            // Search by the class id suffix in fullname — reliable even if class name changed between publishes.
            $existingCat = $DB->get_record_sql(
                "SELECT id FROM {grade_categories} WHERE courseid = :courseid AND " . $DB->sql_like('fullname', ':suffix'),
                ['courseid' => $class->corecourseid, 'suffix' => '%-' . $class->id . ' grade category']
            );
            if ($existingCat) {
                $existingCatId = (int)$existingCat->id;
                $class->gradecategoryid = $existingCatId;
                $DB->update_record('gmk_class', $class);
                gmk_log("INFO: create_class_activities — reutilizando gradecategory por sufijo id={$existingCatId} classid={$class->id}");
            }
        }

        if (!$existingCatId) {
            // Grade category creation is non-critical: attendance activity works without it.
            // We attempt creation via the grade_category PHP class (not the external API, which
            // triggers grade_regrade_final_grades and causes Duplicate entry errors on grade_grades).
            try {
                $class->gradecategoryid = create_class_grade_category($class);
                $DB->update_record('gmk_class', $class);

                // Move the attendance grade item into the class grade category via direct DB update
                // to avoid triggering another grade recalculation cascade.
                $attendanceGradeItemId = $DB->get_field('grade_items', 'id', [
                    'itemmodule'  => 'attendance',
                    'iteminstance' => $attendanceCourseModule->instance,
                    'courseid'    => $class->corecourseid,
                ]);
                if ($attendanceGradeItemId) {
                    $DB->set_field('grade_items', 'categoryid', $class->gradecategoryid, ['id' => $attendanceGradeItemId]);
                    gmk_log("INFO: attendance grade_item $attendanceGradeItemId movido a categoría {$class->gradecategoryid}");
                }
            } catch (dml_exception $de) {
                // Duplicate grade_grades entry — gradebook recalc conflict. Category creation failed but
                // attendance activity is already created and functional. Log and continue.
                gmk_log("WARNING: No se pudo crear grade_category para clase {$class->id} (courseid={$class->corecourseid}): " . $de->getMessage());
                $class->gradecategoryid = 0;
                $DB->set_field('gmk_class', 'gradecategoryid', 0, ['id' => $class->id]);
            } catch (Exception $e) {
                gmk_log("WARNING: Error inesperado en grade_category para clase {$class->id}: " . $e->getMessage());
                $class->gradecategoryid = 0;
                $DB->set_field('gmk_class', 'gradecategoryid', 0, ['id' => $class->id]);
            }
        }
    }

    $BBBModuleId = gmk_get_module_id_by_name('bigbluebuttonbn');
    $BBBCourseModulesInfo = [];
    $attendanceSessions = [];

    // Build holiday set for fast lookup (YYYY-MM-DD strings).
    $holidaySet = [];
    if (!empty($class->periodid)) {
        $periodHolidays = $DB->get_records('gmk_holidays', ['academicperiodid' => $class->periodid], '', 'date');
        foreach ($periodHolidays as $h) {
            $holidaySet[date('Y-m-d', $h->date)] = true;
        }
    }

    // Try to use planning-board session records (assigned_dates + excluded_dates).
    $scheduleRecords = $DB->get_records('gmk_class_schedules', ['classid' => $class->id], 'id ASC');

    if (!empty($scheduleRecords)) {
        // --- Precise path: use gmk_class_schedules rows ---
        foreach ($scheduleRecords as $sched) {
            $sessionDuration = (int)$class->classduration;
            // If session has its own start/end times, compute duration from those.
            if (!empty($sched->start_time) && !empty($sched->end_time)) {
                $startSecs = strtotime('1970-01-01 ' . $sched->start_time . ':00 UTC');
                $endSecs   = strtotime('1970-01-01 ' . $sched->end_time   . ':00 UTC');
                if ($endSecs > $startSecs) {
                    $sessionDuration = $endSecs - $startSecs;
                }
            }
            $sessionStartTime = !empty($sched->start_time) ? $sched->start_time : $class->inittime;

            // Build candidate date list: use assigned_dates when present, else fall back to bitmask.
            $assignedDates  = (!empty($sched->assigned_dates))  ? json_decode($sched->assigned_dates,  true) : null;
            $excludedDates  = (!empty($sched->excluded_dates))  ? json_decode($sched->excluded_dates,  true) : [];
            $excludedSet    = array_flip(is_array($excludedDates) ? $excludedDates : []);

            if (!empty($assignedDates) && is_array($assignedDates)) {
                $candidateDates = $assignedDates;
            } else {
                // Fallback: generate all matching weekdays in the class date range.
                $candidateDates = [];
                $dayNameMap = [
                    'Lunes' => 'Monday', 'Martes' => 'Tuesday', 'Miércoles' => 'Wednesday',
                    'Miercoles' => 'Wednesday', 'Jueves' => 'Thursday', 'Viernes' => 'Friday',
                    'Sábado' => 'Saturday', 'Sabado' => 'Saturday', 'Domingo' => 'Sunday'
                ];
                $targetEnglishDay = $dayNameMap[$sched->day] ?? null;
                if ($targetEnglishDay) {
                    $initDate  = $class->initdate ? date('Y-m-d', $class->initdate) : date('Y-m-d');
                    $endDate   = $class->enddate  ? date('Y-m-d', $class->enddate)  : date('Y-m-d', strtotime('+2 months'));
                    $cur = new DateTime($initDate);
                    $end = new DateTime($endDate);
                    while ($cur <= $end) {
                        if ($cur->format('l') === $targetEnglishDay) {
                            $candidateDates[] = $cur->format('Y-m-d');
                        }
                        $cur->modify('+1 day');
                    }
                }
            }

            foreach ($candidateDates as $dateStr) {
                if (isset($excludedSet[$dateStr])) continue;   // Manually excluded
                if (isset($holidaySet[$dateStr]))  continue;   // Public holiday

                $sessionDateTS = strtotime($dateStr . ' ' . $sessionStartTime . ':00');
                $BBBCourseModuleInfo = null;
                $activityEndTS = $sessionDateTS + $sessionDuration;
                try {
                    $BBBCourseModuleInfo = create_big_blue_button_activity($class, $sessionDateTS, $activityEndTS, $BBBModuleId, $classSectionNumber);
                    gmk_ensure_cmid_in_section_sequence((int)$class->coursesectionid, (int)$BBBCourseModuleInfo->coursemodule);
                    $BBBCourseModulesInfo[] = $BBBCourseModuleInfo;
                } catch (Throwable $bbbErr) {
                    $recoveredBBB = gmk_recover_big_blue_button_activity($class, $sessionDateTS, $BBBModuleId, (int)$class->coursesectionid);
                    if ($recoveredBBB) {
                        $BBBCourseModuleInfo = $recoveredBBB;
                        $BBBCourseModulesInfo[] = $BBBCourseModuleInfo;
                        gmk_log("INFO: BBB recuperado para clase {$class->id} date {$dateStr}: cmid={$BBBCourseModuleInfo->coursemodule}");
                    } else {
                        gmk_log("WARNING: BBB creation failed for class {$class->id} date {$dateStr}: " . $bbbErr->getMessage());
                        $BBBCourseModuleInfo = null;
                    }
                }
                $attendanceSessions[] = create_attendance_session_object($class, $sessionDateTS, $sessionDuration, $BBBCourseModuleInfo);
            }
        }
    } else {
        // --- Legacy path: no schedule records, iterate by classdays bitmask ---
        $initDate  = $class->initdate ? date('Y-m-d', $class->initdate) : date('Y-m-d');
        $endDate   = $class->enddate  ? date('Y-m-d', $class->enddate)  : date('Y-m-d', strtotime('+2 months'));
        $startDateTS  = strtotime($initDate . ' ' . $class->inittime . ':00');
        $endDateTS    = strtotime($endDate  . ' ' . $class->endtime  . ':00');
        $classDaysNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        $classDaysList  = array_combine($classDaysNames, explode('/', $class->classdays));
        $currentDateTS  = $startDateTS;

        while ($currentDateTS < $endDateTS) {
            $day     = $classDaysList[date('l', $currentDateTS)];
            $dateStr = date('Y-m-d', $currentDateTS);
            if ($day === '1' && !isset($holidaySet[$dateStr])) {
                $BBBCourseModuleInfo = null;
                $activityEndTS = $currentDateTS + (int)$class->classduration;
                try {
                    $BBBCourseModuleInfo = create_big_blue_button_activity($class, $currentDateTS, $activityEndTS, $BBBModuleId, $classSectionNumber);
                    gmk_ensure_cmid_in_section_sequence((int)$class->coursesectionid, (int)$BBBCourseModuleInfo->coursemodule);
                    $BBBCourseModulesInfo[] = $BBBCourseModuleInfo;
                } catch (Throwable $bbbErr) {
                    $recoveredBBB = gmk_recover_big_blue_button_activity($class, $currentDateTS, $BBBModuleId, (int)$class->coursesectionid);
                    if ($recoveredBBB) {
                        $BBBCourseModuleInfo = $recoveredBBB;
                        $BBBCourseModulesInfo[] = $BBBCourseModuleInfo;
                        gmk_log("INFO: BBB recuperado para clase {$class->id} date {$dateStr}: cmid={$BBBCourseModuleInfo->coursemodule}");
                    } else {
                        gmk_log("WARNING: BBB creation failed for class {$class->id} date {$dateStr}: " . $bbbErr->getMessage());
                        $BBBCourseModuleInfo = null;
                    }
                }
                $attendanceSessions[] = create_attendance_session_object($class, $currentDateTS, (int)$class->classduration, $BBBCourseModuleInfo);
            }
            $dateTime = new DateTime('@' . $currentDateTS);
            $dateTime->modify('+1 day');
            $currentDateTS = $dateTime->getTimestamp();
        }
    }

    $class->bbbmoduleids = count($BBBCourseModulesInfo) > 0 ? implode(",", array_map(function ($BBBCourseModuleInfo) {
        return $BBBCourseModuleInfo->coursemodule;
    }, $BBBCourseModulesInfo)) : null;

    foreach ($attendanceSessions as $session) {
        $attendanceSessionId = $attendanceStructure->add_session($session);

        $classAttendanceBBBRelation = new stdClass();
        $classAttendanceBBBRelation->attendancesessionid = $attendanceSessionId;
        $classAttendanceBBBRelation->bbbmoduleid = $session->bbbCourseModuleInfo ? $session->bbbCourseModuleInfo->coursemodule : null;
        $classAttendanceBBBRelation->bbbid = $session->bbbCourseModuleInfo ? $session->bbbCourseModuleInfo->instance : null;
        $classAttendanceBBBRelation->classid = $class->id;
        $classAttendanceBBBRelation->attendancemoduleid = $attendanceStructure->cmid;
        $classAttendanceBBBRelation->attendanceid = $attendanceStructure->id;
        $classAttendanceBBBRelation->sectionid = $class->coursesectionid;
        $DB->insert_record('gmk_bbb_attendance_relation', $classAttendanceBBBRelation);
    }
    $DB->update_record('gmk_class', $class);

    return ['status' => 'created'];
}

function create_big_blue_button_activity($class, $initDateTS, $endDateTS, $BBBmoduleId, $classSectionId)
{

    $bbbActivityDefinition                                  = new stdClass();

    $bbbActivityDefinition->type                            = '0';
    $bbbActivityDefinition->name                            = $class->name . '-' . $class->id . '-' . $initDateTS;
    $bbbActivityDefinition->welcome                         = "Le damos la bienvenida a la sala de clases online de la clase " . $class->name;
    $bbbActivityDefinition->participants                    = '[{"selectiontype":"user","selectionid":' . $class->instructorid . ',"role":"moderator"},{"selectiontype":"all","selectionid":"all","role":"viewer"}]';
    $bbbActivityDefinition->openingtime                     = $initDateTS - 600;
    $bbbActivityDefinition->closingtime                     = $endDateTS;
    $bbbActivityDefinition->cmidnumber                      = substr($class->name . '-' . $class->id . '-' . $initDateTS, 0, 100);
    $bbbActivityDefinition->groupmode                       = '0';
    $bbbActivityDefinition->modulename                      = 'bigbluebuttonbn';
    $bbbActivityDefinition->intro                           = "Sala de clases online de la clase " . $class->name;
    $bbbActivityDefinition->introformat                     = "1";
    $bbbActivityDefinition->section                         = $classSectionId;
    $bbbActivityDefinition->module                          = $BBBmoduleId;
    $bbbActivityDefinition->record                          = 1;
    $bbbActivityDefinition->wait                            = 1;
    $bbbActivityDefinition->visible                         = 1;
    $bbbActivityDefinition->recordallfromstart              = 1;
    $bbbActivityDefinition->recordhidebutton                = 1;
    $bbbActivityDefinition->completion                      = 2;
    $bbbActivityDefinition->availability                      = '{"op":"&","c":[{"type":"date","d":">=","t":' . $bbbActivityDefinition->openingtime . '}],"showc":[true]}';
    $bbbActivityDefinition->completionattendanceenabled     = 1;
    $bbbActivityDefinition->completionattendance            = 1;

    global $CFG;
    $prevMsg = $CFG->messaging ?? true;
    $CFG->messaging = false;
    try {
        $bbbActivityInfo = add_moduleinfo($bbbActivityDefinition, $class->course);
    } finally {
        $CFG->messaging = $prevMsg;
    }

    $bbbInstanceInfo = new stdClass();
    $bbbInstanceInfo->coursemodule = $bbbActivityInfo->coursemodule;
    $bbbInstanceInfo->instance = $bbbActivityInfo->instance;
    $bbbInstanceInfo->name = $bbbActivityDefinition->name;

    return $bbbInstanceInfo;
}

/**
 * Recover BBB module when add_moduleinfo throws after partial insert.
 */
function gmk_recover_big_blue_button_activity($class, $initDateTS, $BBBmoduleId, $sectionid = 0)
{
    global $DB;

    $name = $class->name . '-' . $class->id . '-' . $initDateTS;
    $existing = $DB->get_record_sql(
        "SELECT cm.id AS coursemodule, cm.instance, b.name
           FROM {course_modules} cm
           JOIN {bigbluebuttonbn} b ON b.id = cm.instance
          WHERE cm.course = :courseid
            AND cm.module = :moduleid
            AND b.name = :name
       ORDER BY cm.id DESC LIMIT 1",
        [
            'courseid' => (int)$class->corecourseid,
            'moduleid' => (int)$BBBmoduleId,
            'name' => $name
        ]
    );
    if (!$existing) {
        return null;
    }

    if (!empty($sectionid)) {
        gmk_ensure_cmid_in_section_sequence((int)$sectionid, (int)$existing->coursemodule);
    }
    return $existing;
}

function create_attendance_activity($class, $classSectionNumber)
{

    global $DB;

    $attendanceActivityDefinition                             = new stdClass();
    $attendanceActivityDefinition->modulename                 = 'attendance';
    $attendanceActivityDefinition->name                       = 'Asistencia ' . $class->name . '-' . $class->id;
    $attendanceActivityDefinition->cmidnumber                 = '';
    $attendanceActivityDefinition->intro                      = "Registro de asistencia para la clase " . $class->name;
    $attendanceActivityDefinition->section                    = $classSectionNumber;
    $attendanceActivityDefinition->module                     = gmk_get_module_id_by_name($attendanceActivityDefinition->modulename);
    $attendanceActivityDefinition->subnet                     = '';
    $attendanceActivityDefinition->groupmode                  = 1;
    $attendanceActivityDefinition->visible                    = 1;
    $attendanceActivityDefinition->grade                      = 100;
    $attendanceActivityDefinition->gradepass                  = 74;
    $attendanceActivityDefinition->completion                 = 2;
    $attendanceActivityDefinition->completionusegrade         = 1;
    $attendanceActivityDefinition->completionpassgrade        = 1;

    global $CFG;
    $prevMsg = $CFG->messaging ?? true;
    $CFG->messaging = false;
    try {
        $attendanceActivityInfo = add_moduleinfo($attendanceActivityDefinition, $class->course);
    } finally {
        $CFG->messaging = $prevMsg;
    }
    return $attendanceActivityInfo;
}

function create_attendance_session_object($class, $initDateTS, $classDurationInSeconds, $BBBCourseModuleInfo = null)
{

    $attendanceSessionDefinition = new stdClass();
    $attendanceSessionDefinition->sessdate        = $initDateTS;
    $attendanceSessionDefinition->duration = $classDurationInSeconds;
    $attendanceSessionDefinition->groupid         = $class->groupid;
    $attendanceSessionDefinition->timemodified    = time();
    $attendanceSessionDefinition->description     = $BBBCourseModuleInfo ? "Sesión de asistencia - bbbModule:" . $BBBCourseModuleInfo->name . '.' : 'Sesión de clase presencial.';
    $attendanceSessionDefinition->calendarevent   = 1;
    $attendanceSessionDefinition->includeqrcode   = 1;
    $attendanceSessionDefinition->rotateqrcode    = 1;
    $attendanceSessionDefinition->studentscanmark = 1;
    $attendanceSessionDefinition->autoassignstatus = 1;
    $attendanceSessionDefinition->automark  = 2;
    $attendanceSessionDefinition->studentsearlyopentime = 120;
    $attendanceSessionDefinition->descriptionitemid = 0;
    $attendanceSessionDefinition->descriptionformat = "1";
    $attendanceSessionDefinition->absenteereport = 1;
    $attendanceSessionDefinition->statusset = 0;
    $attendanceSessionDefinition->rotateqrcodesecret = null;
    $attendanceSessionDefinition->bbbCourseModuleInfo = $BBBCourseModuleInfo;

    return $attendanceSessionDefinition;
}

function create_class_grade_category($class)
{
    global $DB;
    // Insert directly into grade_categories and grade_items to avoid ANY call to
    // grade_regrade_final_grades(). Both grade_category::insert() and the external
    // create_gradecategories API trigger that recalculation, which inserts grade_grades
    // for all enrolled users and causes Duplicate entry errors on the course root item.
    $now = time();

    // 1. Find the parent category (the course root category).
    $parentCat = $DB->get_record_sql(
        "SELECT id FROM {grade_categories} WHERE courseid = :courseid AND depth = 1 LIMIT 1",
        ['courseid' => $class->corecourseid]
    );
    $parentId = $parentCat ? (int)$parentCat->id : null;

    // 2. Insert the grade_categories row.
    $catRec = new stdClass();
    $catRec->courseid         = (int)$class->corecourseid;
    $catRec->fullname         = $class->name . '-' . $class->id . ' grade category';
    $catRec->aggregation      = 10; // GRADE_AGGREGATE_WEIGHTED_MEAN2
    $catRec->keephigh         = 0;
    $catRec->droplow          = 0;
    $catRec->aggregateonlygraded  = 0;
    $catRec->aggregateoutcomes   = 0;
    $catRec->timecreated      = $now;
    $catRec->timemodified     = $now;
    $catRec->hidden           = 0;
    $catRec->parent           = $parentId ?: null;
    // depth and path are updated after we have the id.
    $catRec->depth  = $parentId ? 2 : 1;
    $catRec->path   = '/0/'; // placeholder, updated below
    $catId = $DB->insert_record('grade_categories', $catRec);

    // Update path/depth now that we have the id.
    $path = $parentId ? "/{$parentId}/{$catId}/" : "/{$catId}/";
    $depth = $parentId ? 2 : 1;
    $DB->set_field('grade_categories', 'path',  $path, ['id' => $catId]);
    $DB->set_field('grade_categories', 'depth', $depth, ['id' => $catId]);

    // 3. Insert the associated grade_item (category total item).
    $itemRec = new stdClass();
    $itemRec->courseid        = (int)$class->corecourseid;
    $itemRec->categoryid      = $parentId ?? $catId;
    $itemRec->itemname        = 'Total ' . $class->name . '-' . $class->id . ' grade';
    $itemRec->itemtype        = 'category';
    $itemRec->iteminstance    = $catId;
    $itemRec->itemnumber      = 0;
    $itemRec->gradetype       = 1; // GRADE_TYPE_VALUE
    $itemRec->grademax        = 100;
    $itemRec->grademin        = 0;
    $itemRec->gradepass       = 70;
    $itemRec->multfactor      = 1.0;
    $itemRec->plusfactor      = 0.0;
    $itemRec->aggregationcoef = 0;
    $itemRec->aggregationcoef2 = 0;
    $itemRec->sortorder       = 999;
    $itemRec->display         = 0;
    $itemRec->decimals        = null;
    $itemRec->hidden          = 0;
    $itemRec->locked          = 0;
    $itemRec->locktime        = 0;
    $itemRec->needsupdate     = 0;
    $itemRec->weightoverride  = 0;
    $itemRec->scaleid         = null;
    $itemRec->outcomeid       = null;
    $itemRec->timecreated     = $now;
    $itemRec->timemodified    = $now;
    $DB->insert_record('grade_items', $itemRec);

    gmk_log("INFO: create_class_grade_category — categoría creada id=$catId para clase {$class->id} (courseid={$class->corecourseid})");
    return $catId;
}

function replace_attendance_session($moduleId, $sessionIdToBeRemoved, $sessionDate, $classDurationInSeconds, $class)
{

    global $DB;

    // Check if class is closed
    if (is_class_closed($class->id)) {
        throw new \moodle_exception('error_class_closed_modification', 'local_grupomakro_core');
    }

    $attendanceCourseModule = get_coursemodule_from_id('attendance', $moduleId, 0, false, MUST_EXIST);
    $attendanceRecord = $DB->get_record('attendance', ['id' => $attendanceCourseModule->instance], '*', MUST_EXIST);
    $context = \context_module::instance($attendanceCourseModule->id);
    $attendance = new \mod_attendance_structure($attendanceRecord, $attendanceCourseModule, $class->course, $context);

    //Remove the attendance session that will be reschedule

    $attendance->delete_sessions([$sessionIdToBeRemoved]);

    //Create the new attendance session with the new values

    $attendanceSession = create_attendance_session_object($class, $sessionDate, $classDurationInSeconds);

    return $attendance->add_sessions([$attendanceSession]);
}

function list_classes($filters)
{
    global $DB, $PAGE, $OUTPUT;

    $fetchedLearningPlans = [];
    $fetchedLearningPlanPeriods = [];
    $fetchedCourses = [];
    $fetchedInstructors = [];

    $classes = $DB->get_records('gmk_class', $filters);
    
    // We need a mapping to derive metadata from subject if needed
    $subjects_metadata_cache = [];

    foreach ($classes as &$class) {
        
        // Derive Academic Metadata from Subject ID (gmk_class.courseid)
        if (!empty($class->courseid)) {
            $cacheKey = $class->courseid . '_' . ($class->learningplanid ?? 0);
            if (!isset($subjects_metadata_cache[$cacheKey])) {
                // First try: assume courseid is the Subject ID (local_learning_courses.id)
                $subj = $DB->get_record('local_learning_courses', ['id' => $class->courseid], 'id, learningplanid, periodid, courseid');
                
                // Fallback: if not found, maybe it's a Moodle courseid? (legacy or error)
                if (!$subj) {
                    $searchParams = ['courseid' => $class->courseid];
                    // CRITICAL: If we already have a learningplanid, use it to narrow down the correct record!
                    if (!empty($class->learningplanid)) {
                        $searchParams['learningplanid'] = $class->learningplanid;
                    }

                    $subj = $DB->get_record('local_learning_courses', $searchParams, 'id, learningplanid, periodid, courseid', IGNORE_MULTIPLE);

                    // Last resort: try by Moodle courseid without learningplanid filter (corecourseid cross-check)
                    if (!$subj && !empty($class->corecourseid)) {
                        $subj = $DB->get_record('local_learning_courses', ['courseid' => $class->corecourseid], 'id, learningplanid, periodid, courseid', IGNORE_MULTIPLE);
                    }

                    if ($subj) {
                        gmk_log("DEBUG: list_classes encontró materia via FALLBACK (Moodle Course ID) para la clase " . ($class->id ?? 'new') . " con courseid " . $class->courseid . " y plan " . ($class->learningplanid ?? 'N/A'));
                    } else {
                        gmk_log("DEBUG: list_classes NO encontró metadatos para courseid: " . $class->courseid . " en clase: " . ($class->name ?? 'sin nombre'));
                    }
                }
                
                $subjects_metadata_cache[$cacheKey] = $subj ?: null;
            }

            $meta = $subjects_metadata_cache[$cacheKey];
            if ($meta) {
                $oldPlan = $class->learningplanid;
                $oldPeriod = $class->periodid;
                $oldCourse = $class->courseid;

                // Always trust meta over stored value (meta comes from the actual subject record)
                if (empty($class->learningplanid) || (int)$class->learningplanid !== (int)$meta->learningplanid) {
                    $class->learningplanid = (int)$meta->learningplanid;
                }
                
                // Helper field for the Level ID
                $class->academic_period_id = (int)$meta->periodid; 
                
                // Keep institutional ID
                if (!isset($class->institutional_period_id)) {
                    $class->institutional_period_id = (int)$class->periodid;
                }
                
                // Override for editclass.php
                $class->periodid = (int)$meta->periodid; 
                
                // Sync Course ID
                if ($class->courseid == $meta->courseid && $class->courseid != $meta->id) {
                    $class->courseid = (int)$meta->id;
                } else {
                    $class->courseid = (int)$class->courseid;
                }
                $class->corecourseid = (int)$meta->courseid;

                gmk_log("HEALING list_classes: Class {$class->id} - Plan: $oldPlan->{$class->learningplanid}, Period: $oldPeriod->{$class->periodid}, Course: $oldCourse->{$class->courseid}");
            }
        }

        //Set the type class icon and label robustness
        $typeMap = ['0' => 'Presencial', '1' => 'Virtual', '2' => 'Mixta'];
        if (empty($class->typelabel) && isset($typeMap[$class->type])) {
            $class->typelabel = $typeMap[$class->type];
        }
        
        if ($class->type === '0') {
            $class->icon = 'fa fa-group';
        } else if ($class->type === '2') {
            $class->icon = 'fa fa-handshake-o'; // Icon for Mixta
        } else {
            $class->icon = 'fa fa-desktop';
        }

        //Set the list of choosen days
        $daysES = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
        $daysEN = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        $daysString = $class->classdays;
        $selectedDaysES = [];
        $selectedDaysEN = [];
        foreach ($daysES as $index => $day) {
            $includedDay = intval(substr($daysString, 0, 1)) === 1;
            $includedDay ? array_push($selectedDaysES, $day) : null;
            $includedDay ? array_push($selectedDaysEN, $daysEN[$index]) : null;
            $daysString = substr($daysString, 2);
        }
        $class->selectedDaysES = $selectedDaysES;
        $class->selectedDaysEN = $selectedDaysEN;
        $class->classDaysString = implode(' - ', $class->selectedDaysES);
        $class->daysFilters = [];
        // Define time constants for readability
        $DAY_START_TS = 21600;  // 6:00 AM in seconds
        $DAY_END_TS = 64800;    // 6:00 PM in seconds

        // Check for Diurna (Daytime)
        if (($class->inittimets >= $DAY_START_TS && $class->inittimets < $DAY_END_TS) ||
            ($class->endtimets >= $DAY_START_TS && $class->endtimets < $DAY_END_TS)
        ) {
            $class->daysFilters[] = 'Diurna';
        }

        // Check for Nocturna (Nighttime)
        if (($class->inittimets < $DAY_START_TS || $class->inittimets >= $DAY_END_TS) ||
            ($class->endtimets < $DAY_START_TS || $class->endtimets >= $DAY_END_TS)
        ) {
            $class->daysFilters[] = 'Nocturna';
        }

        if (in_array('Saturday', $class->selectedDaysEN)) {
            $class->daysFilters[] = 'Sabatina';
        }
        if (in_array('Sunday', $class->selectedDaysEN)) {
            $class->daysFilters[] = 'Dominical';
        }
        //set the 

        //set the hour range string
        $class->hourRangeString = $class->inithourformatted . ' - ' . $class->endhourformatted;

        //Set class instructor Info
        $class->instructorName = 'No asignado'; // Fallback to avoid iterative get_string warnings while cache refreshes.
        if (!empty($class->instructorid)) {
            if (!array_key_exists($class->instructorid, $fetchedInstructors)) {
                $instructors = user_get_users_by_id([$class->instructorid]);
                if (isset($instructors[$class->instructorid])) {
                    $classInstructor = $instructors[$class->instructorid];
                    $fetchedInstructors[$class->instructorid] = $classInstructor;
                } else {
                    $fetchedInstructors[$class->instructorid] = null;
                }
            } else {
                $classInstructor = $fetchedInstructors[$class->instructorid];
            }

            if (!empty($classInstructor)) {
                $class->instructorName = $classInstructor->firstname . ' ' . $classInstructor->lastname;
            }
        }

        //Set Learning plan Info
        $class->learningPlanName = 'N/A';
        if (!empty($class->learningplanid)) {
            if (!array_key_exists($class->learningplanid, $fetchedLearningPlans)) {
                $classLearningPlan = $DB->get_record('local_learning_plans', ['id' => $class->learningplanid]);
                $fetchedLearningPlans[$class->learningplanid] = $classLearningPlan;
            } else {
                $classLearningPlan = $fetchedLearningPlans[$class->learningplanid];
            }
            if ($classLearningPlan) {
                $class->learningPlanName = $classLearningPlan->name;
                $class->learningPlanShortname = $classLearningPlan->shortname;
            }
        }

        //Set period Info
        $class->periodName = 'N/A';
        if (!empty($class->periodid)) {
            if (!array_key_exists($class->periodid, $fetchedLearningPlanPeriods)) {
                $classLearningPlanPeriod = $DB->get_record('local_learning_periods', ['id' => $class->periodid]);
                $fetchedLearningPlanPeriods[$class->periodid] = $classLearningPlanPeriod;
            } else {
                $classLearningPlanPeriod = $fetchedLearningPlanPeriods[$class->periodid];
            }
            if ($classLearningPlanPeriod) {
                $class->periodName = $classLearningPlanPeriod->name;
            }
        }

        //Set the course Info
        $class->coreCourseName = 'N/A';
        if (!empty($class->corecourseid)) {
            if (!array_key_exists($class->corecourseid, $fetchedCourses)) {
                $classCourse = $DB->get_record('course', ['id' => $class->corecourseid]);
                if ($classCourse) {
                    $courseCustomFields = \core_course\customfield\course_handler::create()->get_instance_data($class->corecourseid);
                    foreach ($courseCustomFields as $courseCustomField) {
                        $classCourse->{$courseCustomField->get_field()->get('shortname')} = $courseCustomField->get_value();
                    }
                    $fetchedCourses[$class->corecourseid] = $classCourse;
                } else {
                    $fetchedCourses[$class->corecourseid] = null;
                }
            } else {
                $classCourse = $fetchedCourses[$class->corecourseid];
            }

            if ($classCourse) {
                $class->course = $classCourse;
                $class->coreCourseName = $class->course->fullname;
            }
        }
        $class->coursesectionid = $class->coursesectionid;

        //Set the number of students registered for the class
        $classParticipants = get_class_participants($class);
        $class->enroledStudents = count($classParticipants->enroledStudents);
        $class->preRegisteredStudents = count($classParticipants->preRegisteredStudents);
        $class->queuedStudents = count($classParticipants->queuedStudents);
        $class->classFull = $class->preRegisteredStudents >= $class->classroomcapacity;

        //Instructor profile image
        $class->instructorProfileImage = $OUTPUT->image_url('u/f1'); // Default fallback
        if (!empty($class->instructorid)) {
            $user = core_user::get_user($class->instructorid);
            if ($user) {
                $userpicture = new user_picture($user);
                $userpicture->size = 1; // Size f1.
                $class->instructorProfileImage = $userpicture->get_url($PAGE)->out(false);
            }
        }

        //Setting other variables
        $class->startDate =  date('Y-m-d');
        $class->available = !$class->approved;
    }
    // die;
    return $classes;
}

function get_class_course_info($coreCourseId)
{
    $course = get_course($coreCourseId);
    $courseCustomFields = \core_course\customfield\course_handler::create()->get_instance_data($coreCourseId);
    foreach ($courseCustomFields as $courseCustomField) {
        $course->{$courseCustomField->get_field()->get('shortname')} = $courseCustomField->get_value();
    }
    return $course;
}

function update_class($classParams)
{
    global $DB, $USER;
    
    // Check if class is closed
    if (is_class_closed($classParams['classId'])) {
        throw new \moodle_exception('error_class_closed_modification', 'local_grupomakro_core');
    }
    
    $class = $DB->get_record('gmk_class', ['id' => $classParams['classId']]);
    $oldClass = clone $class; // Save copy for diff comparison
    $classOldInstructorId = $class->instructorid;

    $class->name           = $classParams["name"];
    $class->type           = $classParams["type"];
    $class->learningplanid = $classParams["learningPlanId"];
    $class->periodid       = $classParams["periodId"];
    $class->courseid       = $classParams["courseId"];
    $class->instructorid   = $classParams["instructorId"];
    $class->inittime       = $classParams["initTime"];
    $class->endtime        = $classParams["endTime"];
    $class->initdate       = isset($classParams["initDate"]) ? strtotime($classParams["initDate"]) : 0;
    $class->enddate        = isset($classParams["endDate"]) ? strtotime($classParams["endDate"]) : 0;
    $class->classdays      = $classParams["classDays"];
    $class->usermodified   = $USER->id;
    $class->timemodified   = time();

    // Update classroom capacity if provided (non-zero means explicitly set)
    if (isset($classParams["classroomCapacity"]) && $classParams["classroomCapacity"] > 0) {
        $class->classroomcapacity = $classParams["classroomCapacity"];
    }

    $class = fill_computed_class_values($class, $classParams);

    $classUpdated = $DB->update_record('gmk_class', $class);

    if ($class->instructorid !== $classOldInstructorId) {
        update_class_group($class, $classOldInstructorId);
    }
    
    // Performance Optimization: Only recreate activities if schedule parameters changed
    // Activities creation involves nuking old modules and creating new ones (very slow).
    $scheduleChanged = (
        $class->type != $oldClass->type ||
        $class->instructorid != $oldClass->instructorid ||
        $class->inittime != $oldClass->inittime ||
        $class->endtime != $oldClass->endtime ||
        $class->initdate != $oldClass->initdate ||
        $class->enddate != $oldClass->enddate ||
        $class->classdays != $oldClass->classdays
    );

    // Always create if it's a new class (not covered here) or if critical params changed.
    // If only name changed, we SKIP the heavy activity rebuild.
    if ($scheduleChanged) {
        $task = new \local_grupomakro_core\task\update_class_activities();
        $task->set_custom_data(['classId' => $class->id, 'updating' => true, 'userId' => $USER->id]);
        \core\task\manager::queue_adhoc_task($task);
    }
}

function update_class_group($class, $oldInstructorId)
{

    $updatedClassGroup = new stdClass();
    $updatedClassGroup->id = $class->groupid;
    $updatedClassGroup->name = $class->name . '-' . $class->id;
    $updatedClassGroup->courseid = $class->corecourseid;
    $updatedClassGroup->description = 'Group for the ' . $updatedClassGroup->name . ' class';
    $updatedClassGroup->descriptionformat = 1;
    $updatedClassGroup->updatedGroup = groups_update_group($updatedClassGroup);

    if (!$updatedClassGroup->updatedGroup) {
        throw new Exception('Error updating class group');
    }

    //Remove the previous instructor and add the new one to the group
    $groupInstructorRemoved = groups_remove_member($class->groupid, $oldInstructorId);
    $groupInstructorAdded = groups_add_member($class->groupid, $class->instructorid);

    return $updatedClassGroup->updatedGroup;
}

function fill_computed_class_values($class, $classParams)
{
    global $DB;
    //Let's fill the computed fields ----------------------------------------------------------------------------------------------------------------------

    //Type label
    $classLabels = ['1' => 'Virtual', '0' => 'Presencial', '2' => 'Mixta'];
    $class->typelabel = $classLabels[$classParams["type"]];

    //Core course ID
    $learningCourseId = $DB->get_field('local_learning_courses', 'courseid', ['id' => $classParams["courseId"]]);
    $course = get_course($learningCourseId);
    $class->corecourseid = $course->id;

    //Instructor learning plan ID
    $class->instructorlpid = $DB->get_field('local_learning_users', 'id', ['userid' => $classParams["instructorId"], 'learningplanid' => $classParams["learningPlanId"]]);

    //Hours formatted, hours timestamps (seconds after midnight) and classduration (seconds)
    $class->inithourformatted = date('h:i A', strtotime($classParams["initTime"]));
    $class->endhourformatted = date('h:i A', strtotime($classParams["endTime"]));
    $classTimestamps = convert_time_range_to_timestamp_range([$classParams["initTime"], $classParams["endTime"]]);
    $class->inittimets = $classTimestamps["initTS"];
    $class->endtimets = $classTimestamps["endTS"];
    $class->classduration = $classTimestamps["endTS"] - $classTimestamps["initTS"];

    return $class;
}

function get_class_participants($class)
{
    global $DB;

    $instructorId = (int)($class->instructorid ?? 0);

    $classParticipants = new stdClass();

    // enroledStudents: group members excluding the instructor.
    // For classes without a Moodle group (groupid=0), use gmk_course_progre as the enrolled list
    // only when the class is approved — before approval the group is the source of truth.
    if (empty($class->groupid) && !empty($class->approved)) {
        $classParticipants->enroledStudents = $instructorId
            ? $DB->get_records_select('gmk_course_progre', 'classid = :cid AND userid != :uid', ['cid' => $class->id, 'uid' => $instructorId])
            : $DB->get_records('gmk_course_progre', ['classid' => $class->id]);
    } else if ($instructorId) {
        $classParticipants->enroledStudents = $DB->get_records_select(
            'groups_members', 'groupid = :gid AND userid != :uid',
            ['gid' => $class->groupid, 'uid' => $instructorId]
        );
    } else {
        $classParticipants->enroledStudents = $DB->get_records('groups_members', ['groupid' => $class->groupid]);
    }

    // preRegisteredStudents: exclude instructor
    if ($instructorId) {
        $classParticipants->preRegisteredStudents = $DB->get_records_select(
            'gmk_class_pre_registration', 'classid = :cid AND userid != :uid',
            ['cid' => $class->id, 'uid' => $instructorId]
        );
    } else {
        $classParticipants->preRegisteredStudents = $DB->get_records('gmk_class_pre_registration', ['classid' => $class->id]);
    }

    // queuedStudents: exclude instructor
    if ($instructorId) {
        $classParticipants->queuedStudents = $DB->get_records_select(
            'gmk_class_queue', 'classid = :cid AND userid != :uid',
            ['cid' => $class->id, 'uid' => $instructorId]
        );
    } else {
        $classParticipants->queuedStudents = $DB->get_records('gmk_class_queue', ['classid' => $class->id]);
    }

    $classParticipants->progreStudents = $DB->get_records('gmk_course_progre', ['classid' => $class->id]);

    // 1. Remove from pre_registration and queue anyone already in enroledStudents.
    $enrolledUserIds = [];
    foreach ((array)$classParticipants->enroledStudents as $e) {
        if (!empty($e->userid)) {
            $enrolledUserIds[] = (int)$e->userid;
        }
    }
    if (!empty($enrolledUserIds)) {
        $enrolledSet = array_flip($enrolledUserIds);
        $classParticipants->preRegisteredStudents = array_filter(
            (array)$classParticipants->preRegisteredStudents,
            fn($s) => !isset($enrolledSet[(int)$s->userid])
        );
        $classParticipants->queuedStudents = array_filter(
            (array)$classParticipants->queuedStudents,
            fn($s) => !isset($enrolledSet[(int)$s->userid])
        );
    }

    // 2. If the same student is in both pre_registration AND queue, keep only pre_registration.
    //    This prevents double-counting when both tables have the same userid for the same class.
    $preRegUserIds = [];
    foreach ((array)$classParticipants->preRegisteredStudents as $s) {
        if (!empty($s->userid)) {
            $preRegUserIds[] = (int)$s->userid;
        }
    }
    if (!empty($preRegUserIds)) {
        $preRegSet = array_flip($preRegUserIds);
        $classParticipants->queuedStudents = array_filter(
            (array)$classParticipants->queuedStudents,
            fn($s) => !isset($preRegSet[(int)$s->userid])
        );
    }

    return $classParticipants;
}

function get_enrolled_students_by_courseid($corecourseid)
{
    global $DB;

    $sql = "SELECT u.id, u.firstname, u.lastname, u.email, u.username, u.idnumber
            FROM {user} u
            JOIN {user_enrolments} ue ON u.id = ue.userid
            JOIN {enrol} e ON ue.enrolid = e.id
            WHERE e.courseid = :courseid
            AND u.deleted = 0
            ORDER BY u.lastname, u.firstname";

    $users = $DB->get_records_sql($sql, ['courseid' => $corecourseid]);

    // Format to match existing structure (with userid field for compatibility)
    return array_map(function ($u) {
        return (object)[
            'id' => $u->id,
            'userid' => $u->id,  // Add userid for compatibility with existing frontend code
            'firstname' => $u->firstname,
            'lastname' => $u->lastname,
            'email' => $u->email,
            'username' => $u->username,
            'idnumber' => $u->idnumber
        ];
    }, $users);
}

function check_course_alternative_schedules($selectedClass, $userId)
{
    global $DB;

    $alternatives = student_get_active_classes($userId, $selectedClass->corecourseid);

    unset($alternatives[$selectedClass->corecourseid]['schedules'][$selectedClass->id]);

    $alternatives[$selectedClass->corecourseid]['schedules'] = array_filter($alternatives[$selectedClass->corecourseid]['schedules'], function ($courseClass) {
        return !$courseClass->classFull;
    });
    return $alternatives;
}

function add_user_to_class_queue($userId, $class)
{
    global $DB, $USER;

    if ($DB->record_exists('gmk_class_queue', ['classid' => $class->id, 'userid' => $userId])) {
        return true;
    }

    $classQueueRecord = new stdClass();
    $classQueueRecord->timecreated = time();
    $classQueueRecord->timemodified = time();
    $classQueueRecord->usermodified = $USER->id;
    $classQueueRecord->userid = $userId;
    $classQueueRecord->classid = $class->id;
    $classQueueRecord->courseid = $class->corecourseid;

    return !!$DB->insert_record('gmk_class_queue', $classQueueRecord);
}

function add_user_to_class_pre_registry($userId, $class)
{
    global $DB, $USER;

    if ($DB->record_exists('gmk_class_pre_registration', ['classid' => $class->id, 'userid' => $userId])) {
        return true;
    }

    $classPreRegistryRecord = new stdClass();
    $classPreRegistryRecord->timecreated = time();
    $classPreRegistryRecord->timemodified = time();
    $classPreRegistryRecord->usermodified = $USER->id;
    $classPreRegistryRecord->userid = $userId;
    $classPreRegistryRecord->classid = $class->id;
    $classPreRegistryRecord->courseid = $class->corecourseid;

    return !!$DB->insert_record('gmk_class_pre_registration', $classPreRegistryRecord);
}

function get_class_schedules_overview($params)
{
    global $DB;

    $learningPlansCoursesSchedules = get_learning_plan_course_schedules($params);


    $learningPlansCoursesSchedules = array_map(function ($course) {
        $course->numberOfClasses = count($course->schedules);
        $course->totalParticipants = 0;
        $course->totalCapacity = 0;
        foreach ($course->schedules as $schedule) {
            $course->totalCapacity += $schedule->classroomcapacity;
            $course->totalParticipants += $schedule->preRegisteredStudents + $schedule->queuedStudents;
        }
        $course->remainingCapacity = $course->totalCapacity - $course->totalParticipants;
        $course->capacityPercent = $course->remainingCapacity / $course->totalCapacity;

        $course->capacityColor = '#FFECB3';
        $course->capacityPercent === 1 ? $course->capacityColor = '#00E676' : ($course->capacityPercent < 0.20 ? '#FF5252' : null);
        return $course;
    }, $learningPlansCoursesSchedules);

    return $learningPlansCoursesSchedules;
}

function get_learning_plan_course_schedules($params)
{
    global $DB;

    error_log("DEBUG get_learning_plan_course_schedules: params=" . json_encode($params));

    //Set the filters if provided
    $filters = construct_course_schedules_filter($params);

    error_log("DEBUG get_learning_plan_course_schedules: filters=" . json_encode($filters));

    $openClasses = [];
    foreach ($filters as $filter) {
        $found = list_classes($filter);
        error_log("DEBUG get_learning_plan_course_schedules: filter=" . json_encode($filter) . " found=" . count($found) . " classes");
        
        // Also check: how many total classes exist for this corecourseid regardless of closed/period?
        if (empty($found) && !empty($filter['corecourseid'])) {
            $allForCourse = $DB->count_records('gmk_class', ['corecourseid' => $filter['corecourseid']]);
            $closedForCourse = $DB->count_records('gmk_class', ['corecourseid' => $filter['corecourseid'], 'closed' => 1]);
            $periodForCourse = !empty($filter['periodid']) ? $DB->count_records('gmk_class', ['corecourseid' => $filter['corecourseid'], 'periodid' => $filter['periodid']]) : 0;
            error_log("DEBUG: For corecourseid={$filter['corecourseid']}: total=$allForCourse, closed=$closedForCourse, with periodid={$filter['periodid']}=$periodForCourse");
        }
        
        $openClasses = array_merge($openClasses, $found);
    }

    error_log("DEBUG get_learning_plan_course_schedules: total openClasses=" . count($openClasses));

    $classesByCoursePeriodAndLearningPlan = [];

    foreach ($openClasses as $class) {

        $containerKey = $class->coreCourseName . '-' . $class->periodName . '-' . ($class->course->tc ? 'tc' : $class->learningplanid);

        if (!array_key_exists($containerKey, $classesByCoursePeriodAndLearningPlan)) {
            $course = new stdClass();
            $course->courseId = $class->corecourseid;
            $course->courseName = $class->coreCourseName;
            $course->periodNames = [$class->periodName];
            $course->periodIds = [$class->periodid];
            $course->learningPlanIds = [$class->learningplanid];
            $course->learningPlanNames = [$class->learningPlanName];
            $course->tc =  $class->course->tc;
            $course->schedules = [$class];

            $classesByCoursePeriodAndLearningPlan[$containerKey] = $course;
            continue;
        }
        if ($class->course->tc && !in_array($class->learningPlanName, $classesByCoursePeriodAndLearningPlan[$containerKey]->learningPlanNames)) {
            $classesByCoursePeriodAndLearningPlan[$containerKey]->learningPlanNames[] = $class->learningPlanName;
            $classesByCoursePeriodAndLearningPlan[$containerKey]->learningPlanIds[] = $class->learningplanid;
        }
        if ($class->course->tc && !in_array($class->periodid, $classesByCoursePeriodAndLearningPlan[$containerKey]->periodIds)) {
            $classesByCoursePeriodAndLearningPlan[$containerKey]->periodIds[] = $class->periodid;
            !in_array($class->periodName, $classesByCoursePeriodAndLearningPlan[$containerKey]->periodNames) ? $classesByCoursePeriodAndLearningPlan[$containerKey]->periodNames[] = $class->periodName : null;
        }
        $classesByCoursePeriodAndLearningPlan[$containerKey]->schedules[] = $class;
    }

    $classesByCoursePeriodAndLearningPlan = array_map(function ($course) {
        $course->learningPlanNames = implode(',', $course->learningPlanNames);
        $course->periodIds = implode(',', $course->periodIds);
        $course->periodNames = implode(',', $course->periodNames);
        $course->learningPlanIds = implode(',', $course->learningPlanIds);
        return $course;
    }, $classesByCoursePeriodAndLearningPlan);

    return $classesByCoursePeriodAndLearningPlan;
}

function construct_course_schedules_filter($params)
{
    global $DB;

    $filtersArray = [];
    $filters = ['closed' => 0];

    $params['skipApproved'] ? $filters['approved'] = '0' : null;
    $params['courseId'] ? $filters['corecourseid'] = $params['courseId'] : null;

    if ($params['periodIds']) {
        $periods = explode(",", $params['periodIds']);
        
        // The periodIds from the schedule panel are academic-level IDs
        // (from local_learning_courses.periodid), but gmk_class.periodid stores 
        // the institutional period ID (from gmk_academic_periods).
        // We need to resolve them: find the institutional periods for these classes.
        $resolvedPeriods = [];
        
        if (!empty($params['courseId'])) {
            foreach ($periods as $period) {
                $period = trim($period);
                
                // First, try direct match (works if periodid IS the institutional period)
                $directCount = $DB->count_records('gmk_class', array_merge($filters, ['periodid' => $period]));
                if ($directCount > 0) {
                    $resolvedPeriods[] = $period;
                    continue;
                }
                
                // If no direct match, this is likely an academic-level periodid.
                // Find the subjects in local_learning_courses for this academicPeriodId + courseId,
                // then look up what institutional period their classes belong to.
                $subjects = $DB->get_records('local_learning_courses', [
                    'courseid' => $params['courseId'],
                    'periodid' => $period
                ], '', 'id');
                
                if (!empty($subjects)) {
                    $subjectIds = array_keys($subjects);
                    foreach ($subjectIds as $sid) {
                        $classesForSubj = $DB->get_records('gmk_class', array_merge($filters, ['courseid' => $sid]), '', 'DISTINCT periodid');
                        foreach ($classesForSubj as $cls) {
                            if (!in_array($cls->periodid, $resolvedPeriods)) {
                                $resolvedPeriods[] = $cls->periodid;
                            }
                        }
                    }
                }
                
                // If still nothing found, keep the original period as fallback
                if (empty($resolvedPeriods)) {
                    $resolvedPeriods[] = $period;
                }
            }
        } else {
            $resolvedPeriods = $periods;
        }
        
        error_log("DEBUG construct_course_schedules_filter: input periods=[" . implode(',', $periods) . "] resolved=[" . implode(',', $resolvedPeriods) . "]");
        
        foreach ($resolvedPeriods as $period) {
            $filtersCopy = $filters;
            $filtersCopy['periodid'] = $period;
            $filtersArray[] = $filtersCopy;
        }
    } else {
        $filtersArray[] = $filters;
    }
    return $filtersArray;
}

function approve_course_schedules($approvingSchedules)
{
    global $DB, $USER;

    $approveResults = [];
    foreach ($approvingSchedules as $schedule) {
        // Get the class record with all fields
        $class = $DB->get_record('gmk_class', ['id' => $schedule['classId']], '*', MUST_EXIST);

        // If already approved, still process any pending students (idempotent re-enrolment).
        $alreadyApproved = (bool)$class->approved;

        $schedulePreRegisteredStudents = $DB->get_records('gmk_class_pre_registration', ['classid' => $schedule['classId']]);
        $scheduleQueuedStudents = $DB->get_records('gmk_class_queue', ['classid' => $schedule['classId']]);

        gmk_log("approve_course_schedules: classId={$schedule['classId']} groupid={$class->groupid} corecourseid={$class->corecourseid} approved={$class->approved} alreadyApproved=" . ($alreadyApproved ? 'true' : 'false'));
        gmk_log("approve_course_schedules: preReg=" . count($schedulePreRegisteredStudents) . " queued=" . count($scheduleQueuedStudents));

        // Deduplicate by userid — a student may appear in both tables
        $allStudents = [];
        foreach (array_merge($schedulePreRegisteredStudents, $scheduleQueuedStudents) as $s) {
            $allStudents[$s->userid] = $s;
        }

        gmk_log("approve_course_schedules: deduped students=" . count($allStudents) . " userids=" . implode(',', array_keys($allStudents)));

        $enrolmentResults = enrolApprovedScheduleStudents($allStudents, $class);
        gmk_log("approve_course_schedules: enrolmentResults=" . json_encode($enrolmentResults));

        // Make sure the id field is set before updating
        if (!isset($class->id)) {
            throw new Exception('Class ID is missing from the record');
        }

        if (!$alreadyApproved) {
            $class->approved = 1;
            $classApproved = $DB->update_record('gmk_class', $class);
        } else {
            $classApproved = true;
        }

        // NOTE: queue/pre_reg records are intentionally preserved — they represent
        // the academic plan (who is assigned to this class) and must persist so that
        // the student list reappears correctly if the enrollment dialog is reopened.

        // Create Moodle attendance & BBB activities if not yet created.
        // Validate module ownership to avoid reusing stale cmids from other classes.
        $attReason = '';
        if (!gmk_is_valid_class_attendance_module($class, $attReason)) {
            create_class_activities($class);
        }

        // Only insert approval message if one was provided
        $approvalMessageSaved = false;
        if (!empty($schedule['approvalMessage'])) {
            $classApprovedMessage = new stdClass();
            $classApprovedMessage->classid = $schedule['classId'];
            $classApprovedMessage->approvalmessage = $schedule['approvalMessage'];
            $classApprovedMessage->usermodified = $USER->id;
            $classApprovedMessage->timecreated = time();
            $classApprovedMessage->timemodified = time();

            $approvalMessageSaved = !!$DB->insert_record('gmk_class_approval_message', $classApprovedMessage);
        }

        $approveResults[$schedule['classId']] = [
            "enrolmentResults" => $enrolmentResults,
            'classApproved' => $classApproved,
            'approvalMessageSaved' => $approvalMessageSaved
        ];
    }
    return $approveResults;
}

function enrolApprovedScheduleStudents($students, $class)
{
    global $DB;
    $enrolmentResults = [];
    $studentRoleId = $DB->get_record('role', ['shortname' => 'student'])->id;
    $enrolplugin = enrol_get_plugin('manual');
    $courseInstance = get_manual_enroll($class->corecourseid);

    gmk_log("enrolApprovedScheduleStudents: classid={$class->id} groupid={$class->groupid} corecourseid={$class->corecourseid} studentRoleId={$studentRoleId} courseInstance=" . ($courseInstance ? $courseInstance->id : 'NULL'));

    foreach ($students as $student) {
        // Enrol user in Moodle course first to avoid groups_add_member failure
        if ($courseInstance && $enrolplugin && $studentRoleId) {
            $enrolplugin->enrol_user($courseInstance, $student->userid, $studentRoleId);
            gmk_log("enrolApprovedScheduleStudents: enrol_user courseid={$class->corecourseid} userid={$student->userid}");
        } else {
            gmk_log("enrolApprovedScheduleStudents: SKIP enrol_user - courseInstance=" . ($courseInstance ? 'ok' : 'NULL') . " enrolplugin=" . ($enrolplugin ? 'ok' : 'NULL') . " studentRoleId=$studentRoleId");
        }

        // Only add to group if the class has a valid Moodle group
        if (!empty($class->groupid)) {
            $enrolmentResults[$student->userid] = groups_add_member($class->groupid, $student->userid);
        } else {
            $enrolmentResults[$student->userid] = true; // No group — enrolment to course is sufficient
        }

        if ($enrolmentResults[$student->userid]) {
            gmk_log("enrolApprovedScheduleStudents: calling assign_class_to_course_progress userid={$student->userid} classid={$class->id}");
            local_grupomakro_progress_manager::assign_class_to_course_progress($student->userid, $class);
        } else {
            gmk_log("enrolApprovedScheduleStudents: enrolment FAILED for userid={$student->userid}");
        }
    }
    return $enrolmentResults;
}

function change_students_schedules($movingStudents)
{
    global $DB, $USER;
    $changeResults = [];
    $fetchedClasses = [];

    foreach ($movingStudents as $changeInfo) {
        $isPreregisteredStudent = $DB->get_record('gmk_class_pre_registration', ['classid' => $changeInfo["currentClassId"], 'userid' => $changeInfo["studentId"]]);

        $newClass = list_classes(['id' => $changeInfo["newClassId"]])[$changeInfo["newClassId"]];
        if ($isPreregisteredStudent) {
            if ($newClass->classFull) {
                $newClassQueueStudent = createSchedulePreregistryOrQueueObject($changeInfo["studentId"], $newClass->id, $newClass->corecourseid);
                $newClassQueueStudent->id = $DB->insert_record('gmk_class_queue', $newClassQueueStudent);
                $DB->delete_records('gmk_class_pre_registration', ['id' => $isPreregisteredStudent->id]);

                $changeResults[$changeInfo["studentId"]] = ['changedSchedule' => !!$newClassQueueStudent->id, 'sendedTo' => 'Queue'];
                continue;
            }

            $isPreregisteredStudent->classid = $changeInfo["newClassId"];
            $isPreregisteredStudent->timemodified = time();
            $isPreregisteredStudent->usermodified = $USER->id;
            $changeResults[$changeInfo["studentId"]] = ['changedSchedule' => $DB->update_record('gmk_class_pre_registration', $isPreregisteredStudent), 'sendedTo' => 'Preregistry'];
        }

        $isQueuedStudent = $DB->get_record('gmk_class_queue', ['classid' => $changeInfo["currentClassId"], 'userid' => $changeInfo["studentId"]]);
        if ($isQueuedStudent) {
            if (!$newClass->classFull) {
                $newClassPreregistryStudent = createSchedulePreregistryOrQueueObject($changeInfo["studentId"], $newClass->id, $newClass->corecourseid);
                $newClassPreregistryStudent->id = $DB->insert_record('gmk_class_pre_registration', $newClassPreregistryStudent);
                $DB->delete_records('gmk_class_queue', ['id' => $isQueuedStudent->id]);

                $changeResults[$changeInfo["studentId"]] = ['changedSchedule' => !!$newClassPreregistryStudent->id, 'sendedTo' => 'Preregistry'];
                continue;
            }

            $isQueuedStudent->classid = $changeInfo["newClassId"];
            $isQueuedStudent->timemodified = time();
            $isQueuedStudent->usermodified = $USER->id;
            $changeResults[$changeInfo["studentId"]] = ['changedSchedule' => $DB->update_record('gmk_class_queue', $isQueuedStudent), 'sendedTo' => 'Queue'];
        }
    }
    return $changeResults;
}

function createSchedulePreregistryOrQueueObject($userId, $classId, $courseId)
{
    global $USER;
    $preregistryOrQueueObject = new stdClass();
    $preregistryOrQueueObject->userid = $userId;
    $preregistryOrQueueObject->classid = $classId;
    $preregistryOrQueueObject->courseid = $courseId;
    $preregistryOrQueueObject->timecreated = time();
    $preregistryOrQueueObject->timemodified = time();
    $preregistryOrQueueObject->usermodified = $USER->id;

    return $preregistryOrQueueObject;
}

function deleteStudentFromClassSchedule($deletedStudents)
{
    global $DB;

    foreach ($deletedStudents as $student) {
        $deletedFromPreregistry = $DB->delete_records('gmk_class_pre_registration', ['classid' => $student['classId'], 'userid' => $student['studentId']]);
        $deletedFromQueue = $DB->delete_records('gmk_class_queue', ['classid' => $student['classId'], 'userid' => $student['studentId']]);
    }

    return;
}

function get_course_students_by_class_schedule($classId, $activePeriodId = null)
{
    global $DB;
    $class = $DB->get_record('gmk_class', ['id' => $classId]);

    // Determine if external class
    $isExternal = false;
    if ($activePeriodId && $class->periodid != $activePeriodId) {
        $isExternal = true;
    }

    // EXTERNAL CLASS WITHOUT GROUP: use queue/progre records (source of truth for this class).
    // Do NOT fall back to get_enrolled_students_by_courseid — that returns ALL students enrolled
    // in the Moodle course (could be hundreds from other plans/periods).
    if ($isExternal && !$class->groupid) {
        $classStudents = get_class_participants($class);
        // Resolve user names for each student list (same as the normal path below)
        $resolveExternal = function($student) use ($DB) {
            if (empty($student->userid)) return $student;
            $u = $DB->get_record('user', ['id' => $student->userid, 'deleted' => 0], 'id,firstname,lastname,email,idnumber', IGNORE_MISSING);
            if ($u) {
                $student->firstname = $u->firstname;
                $student->lastname  = $u->lastname;
                $student->email     = $u->email;
                $student->idnumber  = $u->idnumber;
            }
            $student->profilePicture = get_user_picture_url($student->userid);
            return $student;
        };
        $classStudents->queuedStudents         = array_map($resolveExternal, (array)$classStudents->queuedStudents);
        $classStudents->progreStudents         = array_map($resolveExternal, (array)$classStudents->progreStudents);
        $classStudents->enroledStudents        = array_map($resolveExternal, (array)$classStudents->enroledStudents);
        $classStudents->preRegisteredStudents  = array_map($resolveExternal, (array)$classStudents->preRegisteredStudents);
        return $classStudents;
    }

    // NORMAL CLASS OR EXTERNAL CLASS WITH GROUP: Use existing logic
    // External classes with groupid will use groups_members table
    $classStudents = get_class_participants($class);

    // Helper function to resolve a userid that might be an idnumber string
    $resolveUser = function($userid) use ($DB) {
        // If userid is numeric, use the standard lookup
        if (is_numeric($userid)) {
            $result = user_get_users_by_id([$userid]);
            return isset($result[$userid]) ? $result[$userid] : null;
        }
        // Otherwise, it's an idnumber string — look up by idnumber
        return $DB->get_record('user', ['idnumber' => $userid, 'deleted' => 0]);
    };

    $classStudents->enroledStudents = array_map(function ($student) use ($resolveUser) {
        $studentInfo = $resolveUser($student->userid);
        if ($studentInfo) {
            $student->email = $studentInfo->email;
            $student->firstname = $studentInfo->firstname;
            $student->lastname = $studentInfo->lastname;
        }
        $student->profilePicture = get_user_picture_url($studentInfo ? $studentInfo->id : 0);
        return $student;
    }, $classStudents->enroledStudents);

    $classStudents->preRegisteredStudents = array_map(function ($student) use ($resolveUser) {
        $studentInfo = $resolveUser($student->userid);
        if ($studentInfo) {
            $student->email = $studentInfo->email;
            $student->firstname = $studentInfo->firstname;
            $student->lastname = $studentInfo->lastname;
        }
        $student->profilePicture = get_user_picture_url($studentInfo ? $studentInfo->id : 0);
        return $student;
    }, $classStudents->preRegisteredStudents);

    $classStudents->queuedStudents = array_map(function ($student) use ($resolveUser) {
        $studentInfo = $resolveUser($student->userid);
        if ($studentInfo) {
            $student->email = $studentInfo->email;
            $student->firstname = $studentInfo->firstname;
            $student->lastname = $studentInfo->lastname;
        }
        $student->profilePicture = get_user_picture_url($studentInfo ? $studentInfo->id : 0);
        return $student;
    }, $classStudents->queuedStudents);

    $classStudents->progreStudents = array_map(function ($student) use ($resolveUser) {
        $studentInfo = $resolveUser($student->userid);
        if ($studentInfo) {
            $student->email = $studentInfo->email;
            $student->firstname = $studentInfo->firstname;
            $student->lastname = $studentInfo->lastname;
        }
        $student->profilePicture = get_user_picture_url($studentInfo ? $studentInfo->id : 0);
        return $student;
    }, $classStudents->progreStudents);

    return $classStudents;
}

function get_scheduleless_students($params)
{
    global $DB;

    // Get all students who have this course in their learning plan with specific statuses:
    // Status 0 = No Disponible (Prerequisites not met)
    // Status 1 = Disponible (Available to take)
    // Status 5 = Reprobada (Failed - needs to retake)
    // Status 99 = Migración Pendiente (Migration Pending)
    $sql = "SELECT DISTINCT gcp.userid, gcp.status
            FROM {gmk_course_progre} gcp
            WHERE gcp.courseid = :courseid
              AND gcp.status IN (0, 1, 5, 99)
              AND NOT EXISTS (
                  SELECT 1 FROM {gmk_class_pre_registration} pr
                  WHERE pr.userid = gcp.userid AND pr.courseid = :courseid_pr
              )
              AND NOT EXISTS (
                  SELECT 1 FROM {gmk_class_queue} q
                  WHERE q.userid = gcp.userid AND q.courseid = :courseid_q
              )";

    $params_sql = [
        'courseid' => $params['courseId'],
        'courseid_pr' => $params['courseId'],
        'courseid_q' => $params['courseId']
    ];

    $usersWithCourseStatus = $DB->get_records_sql($sql, $params_sql);

    // Format the results
    $schedulelessUsers = array_map(function ($record) {
        $studentInfo = user_get_users_by_id([$record->userid])[$record->userid];
        $student = new stdClass();
        $student->id = $record->userid;
        $student->email = $studentInfo->email;
        $student->firstname = $studentInfo->firstname;
        $student->lastname = $studentInfo->lastname;
        $student->profilePicture = get_user_picture_url($student->id);
        $student->course_status = $record->status; // Include status for debugging if needed
        return $student;
    }, $usersWithCourseStatus);

    return $schedulelessUsers;
}

function add_teacher_disponibility($params)
{
    global $DB, $USER;
    $errors = [];
    if ($DB->get_record('gmk_teacher_disponibility', ['userid' => $params['instructorId']])) {
        $errors[] = 'Disponibility already defined for the user with id ' . $params['instructorId'] . '.';
    }
    $dayENLabels = array(
        'lunes' => 'disp_monday',
        'martes' => 'disp_tuesday',
        'miercoles' => 'disp_wednesday',
        'jueves' => 'disp_thursday',
        'viernes' => 'disp_friday',
        'sabado' => 'disp_saturday',
        'domingo' => 'disp_sunday'
    );

    $fetchedSkills = [];
    foreach ($params['skills'] as $skillId) {
        if (!$skill = $DB->get_record('gmk_teacher_skill', ['id' => $skillId])) {
            $errors[] = 'Invalid skill id: ' . $skillId . '.';
        }
        $fectchedSkills[$skillId] = $skill;
    }

    $teacherDisponibility = new stdClass();
    $teacherDisponibility->userid = $params['instructorId'];

    foreach ($params['newDisponibilityRecords'] as $newDisponibilityRecord) {
        $day = strtolower(str_replace(['á', 'é', 'í', 'ó', 'ú', 'ñ'], ['a', 'e', 'i', 'o', 'u', 'n'], $newDisponibilityRecord['day']));
        $teacherDisponibility->{$dayENLabels[$day]} = json_encode(calculate_disponibility_range($newDisponibilityRecord['timeslots']));
    }
    foreach ($dayENLabels as $dayLabel) {
        !property_exists($teacherDisponibility, $dayLabel) ? $teacherDisponibility->{$dayLabel} = "[]" : null;
    }
    if (!empty($errors)) {
        throw new Exception(json_encode($errors));
    }
    $disponibilityRecordId = $DB->insert_record('gmk_teacher_disponibility', $teacherDisponibility);
    foreach ($params['skills'] as $skillId) {
        $teacherSkillRelation = new stdClass();
        $teacherSkillRelation->skillid = $skillId;
        $teacherSkillRelation->userid = $params['instructorId'];
        $teacherSkillRelation->usermodified = $USER->id;
        $teacherSkillRelation->timecreated = time();
        $teacherSkillRelation->timemodified = time();

        $DB->insert_record('gmk_teacher_skill_relation', $teacherSkillRelation);
    }

    return $disponibilityRecordId;
}

function update_teacher_disponibility($params)
{
    global $DB, $USER;
    $errors = [];
    $dayENLabels = array(
        'lunes' => 'disp_monday',
        'martes' => 'disp_tuesday',
        'miercoles' => 'disp_wednesday',
        'jueves' => 'disp_thursday',
        'viernes' => 'disp_friday',
        'sabado' => 'disp_saturday',
        'domingo' => 'disp_sunday'
    );
    $weekdays = [
        "Monday" => "Lunes",
        "Tuesday" => "Martes",
        "Wednesday" => "Miércoles",
        "Thursday" => "Jueves",
        "Friday" => "Viernes",
        "Saturday" => "Sábado",
        "Sunday" => "Domingo"
    ];
    foreach ($params['skills'] as $skillId) {
        if (!$DB->get_record('gmk_teacher_skill', ['id' => $skillId])) {
            $errors[] = 'Invalid skill id: ' . $skillId . '.';
        }
    }
    $teacherDisponibilityId = $DB->get_record('gmk_teacher_disponibility', ['userid' => $params['instructorId']])->id;
    $teacherDisponibility = new stdClass();
    $teacherDisponibility->id = $teacherDisponibilityId;
    $teacherDisponibility->userid = $params['instructorId'];

    if ($params['newDisponibilityRecords']) {

        $disponibilityDays = array();
        foreach ($params['newDisponibilityRecords'] as $newDisponibilityRecord) {
            $day = strtolower(str_replace(['á', 'é', 'í', 'ó', 'ú', 'ñ'], ['a', 'e', 'i', 'o', 'u', 'n'], $newDisponibilityRecord['day']));
            $teacherDisponibility->{$dayENLabels[$day]} = calculate_disponibility_range($newDisponibilityRecord['timeslots']);
            $disponibilityDays[] = explode('_', $dayENLabels[$day])[1];
        }

        $instructorAsignedClasses = list_classes(['instructorid' => $params['instructorId']]);
        $classLearningPlans = array();

        foreach ($instructorAsignedClasses as $instructorAsignedClass) {

            if (!in_array($instructorAsignedClass->learningplanid, $classLearningPlans)) {
                $classLearningPlans[] = $instructorAsignedClass->learningplanid;
            }

            // Check if a day that is already defined for a class is missing in the new disponibility
            foreach ($instructorAsignedClass->selectedDaysEN as $classDay) {
                if (!in_array(strtolower($classDay), $disponibilityDays)) {
                    $errorString = "El horario de la clase " . $instructorAsignedClass->coreCourseName . " (" . $weekdays[$classDay] . " " . $instructorAsignedClass->inithourformatted . '-' . $instructorAsignedClass->endhourformatted . "), no esta definido en la nueva disponibilidad; no se puede actualizar.";
                    $errors[] = $errorString;
                }

                $foundedRange = false;
                $dayDisponibilities = $teacherDisponibility->{'disp_' . strtolower($classDay)};
                foreach ($dayDisponibilities as $dayDisponibility) {
                    if ($instructorAsignedClass->inittimets >= $dayDisponibility->st &&  $instructorAsignedClass->endtimets  <= $dayDisponibility->et) {
                        $foundedRange = true;
                        break;
                    }
                }
                if (!$foundedRange) {
                    $errorString = "El horario de la clase " . $instructorAsignedClass->coreCourseName . " (" . $weekdays[$classDay] . " " . $instructorAsignedClass->inithourformatted . '-' . $instructorAsignedClass->endhourformatted . "), no esta definido en la nueva disponibilidad; no se puede actualizar.";
                    $errors[] = $errorString;
                }
            }
        }


        //Check if there is a change in the user availability owner
        if ($params['newInstructorId'] && $params['newInstructorId'] !== $params['instructorId'] && empty($errors)) {
            $validNewInstructor = true;
            if ($DB->get_record('gmk_teacher_disponibility', array('userid' => $params['newInstructorId']))) {
                $errorString = 'El nuevo instructor ya tiene una disponibilidad definida.';
                $errors[] = $errorString;
                $validNewInstructor = false;
            }

            foreach ($classLearningPlans as $classLearningPlan) {
                if (!$DB->get_record('local_learning_users', array('userid' => $params['newInstructorId'], 'learningplanid' => $classLearningPlan, 'userrolename' => 'teacher'))) {
                    $errorString = 'El nuevo instructor no esta en el plan de aprendizaje ' . $DB->get_record('local_learning_plans', array('id' => $classLearningPlan))->name . '.';
                    $errors[] = $errorString;
                    $validNewInstructor = false;
                }
            }

            if ($validNewInstructor) {
                foreach ($instructorAsignedClasses as $instructorAsignedClass) {
                    $classRecord = $DB->get_record('gmk_class', array('id' => $instructorAsignedClass->id));
                    $classRecord->instructorid = $params['newInstructorId'];
                    $updateClassInstructor = $DB->update_record('gmk_class', $classRecord);

                    //Update the group with the new instructor
                    $classGroupId = $instructorAsignedClass->groupid;

                    $groupInstructorRemoved = groups_remove_member($classGroupId, $params['instructorId']);
                    $groupInstructorAdded = groups_add_member($classGroupId, $params['newInstructorId']);
                }
                $teacherDisponibility->userid = $params['newInstructorId'];
            }
        }
        foreach ($teacherDisponibility as $columnKey => $columnValue) {
            if (strpos($columnKey, 'disp_') !== false) {
                $teacherDisponibility->{$columnKey} = json_encode($columnValue);
            }
        }
        foreach ($dayENLabels as $dayLabel) {
            !property_exists($teacherDisponibility, $dayLabel) ? $teacherDisponibility->{$dayLabel} = "[]" : null;
        }
    }

    if (!empty($errors)) {
        throw new Exception(json_encode($errors));
    }
    if ($params['newDisponibilityRecords']) {
        $disponibilityRecordUpdated = $DB->update_record('gmk_teacher_disponibility', $teacherDisponibility);
    }

    $DB->delete_records('gmk_teacher_skill_relation', ['userid' => $params['instructorId']]);
    foreach ($params['skills'] as $skillId) {

        // if($DB->get_record('gmk_teacher_skill_relation',['userid'=>$teacherDisponibility->userid,'skillid'=>$skillId])){
        //     continue;
        // }
        $teacherSkillRelation = new stdClass();
        $teacherSkillRelation->skillid = $skillId;
        $teacherSkillRelation->userid = $teacherDisponibility->userid;
        $teacherSkillRelation->usermodified = $USER->id;
        $teacherSkillRelation->timecreated = time();
        $teacherSkillRelation->timemodified = time();
        $DB->insert_record('gmk_teacher_skill_relation', $teacherSkillRelation);
    }

    return true;
}

function bulk_update_teachers_disponibilities($disponibilityRecords)
{
    $results = [];

    global $DB;
    $userDocumentCustomFieldId = $DB->get_record('user_info_field', ['shortname' => 'documentnumber'])->id;
    foreach ($disponibilityRecords as $disponibilityRecord) {
        $instructorDocument = $disponibilityRecord['instructorId'];

        $results[$instructorDocument] = [];
        $results[$instructorDocument]['instructorId'] = $instructorDocument;

        $disponibilityRecord['instructorId'] = $DB->get_record_sql(
            "SELECT userid FROM {user_info_data} WHERE fieldid = ? AND " . $DB->sql_compare_text('data') . " = ?",
            [$userDocumentCustomFieldId, $disponibilityRecord['instructorId']]
        )->userid;
        try {
            if (!$disponibilityRecord['instructorId']) {
                throw new Exception(json_encode(['No hay usuario con el número de documento ' . $instructorDocument]));
            }
            if (!$DB->get_record('gmk_teacher_disponibility', ['userid' => $disponibilityRecord['instructorId']])) {
                $newDisponibilityId = add_teacher_disponibility($disponibilityRecord);
                $results[$instructorDocument]['status'] = 1;
                $results[$instructorDocument]['message'] = 'Disponibilidad creada con id ' . $newDisponibilityId;
                continue;
            }
            $disponibilityUpdated = update_teacher_disponibility($disponibilityRecord);
            $results[$instructorDocument]['status'] = 1;
            $results[$instructorDocument]['message'] = 'Disponibilidad actualizada';
        } catch (Exception $e) {
            $results[$instructorDocument]['status'] = -1;
            $results[$instructorDocument]['message'] = $e->getMessage();
        }
    }
    return $results;
}

function get_academic_calendar_period($filters)
{
    global $DB;

    $calendarRecords = $DB->get_records('gmk_academic_calendar', $filters);
    // print_object($calendarRecords);
    $calendarRecordsFormatted = [];
    foreach ($calendarRecords as $calendarRecord) {
        if (!array_key_exists($calendarRecord->period, $calendarRecordsFormatted)) {
            $periodRecordFormatted = new stdClass();
            $calendarRecordsFormatted[$calendarRecord->period] = $periodRecordFormatted;
        } else {
            $periodRecordFormatted = $calendarRecordsFormatted[$calendarRecord->period];
        }
        $bimesterNumber = $calendarRecord->bimesternumber;

        $periodRecordFormatted->period = $calendarRecord->period;
        $periodRecordFormatted->bimesters[$bimesterNumber] = $calendarRecord->bimester;


        $periodRecordFormatted->start[$bimesterNumber] = date('d-m-Y', $calendarRecord->periodstart);
        $periodRecordFormatted->end[$bimesterNumber] = date('d-m-Y', $calendarRecord->periodend);
        $periodRecordFormatted->induction = date('d-m-Y', $calendarRecord->induction);

        $finalExamRangeStrData = new stdClass();
        $finalExamRangeStrData->examFrom = date('d-m-y', $calendarRecord->finalexamfrom);
        $finalExamRangeStrData->examUntil = date('d-m-y', $calendarRecord->finalexamuntil);

        $periodRecordFormatted->finalExamRange[$bimesterNumber] = get_string('academiccalendar:academic_calendar_table:final_exam_cell', 'local_grupomakro_core', $finalExamRangeStrData);

        $periodRecordFormatted->loadnotesandclosesubjects[$bimesterNumber] = date('d-m-Y', $calendarRecord->loadnotesandclosesubjects);
        $periodRecordFormatted->delivoflistforrevalbyteach[$bimesterNumber] = date('d-m-Y', $calendarRecord->delivoflistforrevalbyteach);
        $periodRecordFormatted->notiftostudforrevalidations[$bimesterNumber] = date('d-m-Y', $calendarRecord->notiftostudforrevalidations);
        $periodRecordFormatted->deadlforpayofrevalidations[$bimesterNumber] = date('d-m-Y', $calendarRecord->deadlforpayofrevalidations);
        $periodRecordFormatted->revalidationprocess[$bimesterNumber] = $calendarRecord->revalidationprocess === "0" ? '' : date('d-m-Y', $calendarRecord->revalidationprocess);

        if ($calendarRecord->registrationsfrom === '0' || $calendarRecord->registrationsuntil === '0') {
            $periodRecordFormatted->registrationRange[$bimesterNumber] = '';
        } else {
            $registrationRangeStrData = new stdClass();
            $registrationRangeStrData->registrationFrom = date('d-m-y', $calendarRecord->registrationsfrom);
            $registrationRangeStrData->registrationUntil = date('d-m-y', $calendarRecord->registrationsuntil);
            $periodRecordFormatted->registrationRange[$bimesterNumber] = get_string('academiccalendar:academic_calendar_table:registration_cell', 'local_grupomakro_core', $registrationRangeStrData);
        }
        $periodRecordFormatted->graduationdate[$bimesterNumber] = $calendarRecord->graduationdate === "0" ? '' : date('d-m-Y', $calendarRecord->graduationdate);
    }
    return $calendarRecordsFormatted;
}

function parse_academic_calendar_period_excel($academicCalendarPeriod)
{

    if (!defined('COLUMN_PERIOD')) define('COLUMN_PERIOD', 'A');
    if (!defined('COLUMN_BIMESTER')) define('COLUMN_BIMESTER', 'B');
    if (!defined('COLUMN_PERIOD_START')) define('COLUMN_PERIOD_START', 'C');
    if (!defined('COLUMN_PERIOD_END')) define('COLUMN_PERIOD_END', 'D');
    if (!defined('COLUMN_INDUCTION')) define('COLUMN_INDUCTION', 'E');
    if (!defined('COLUMN_FINAL_EXAM_FROM')) define('COLUMN_FINAL_EXAM_FROM', 'F');
    if (!defined('COLUMN_FINAL_EXAM_UNTIL')) define('COLUMN_FINAL_EXAM_UNTIL', 'G');
    if (!defined('COLUMN_LOAD_NOTES')) define('COLUMN_LOAD_NOTES', 'H');
    if (!defined('COLUMN_DELIVERY_LIST')) define('COLUMN_DELIVERY_LIST', 'I');
    if (!defined('COLUMN_NOTIFICATION')) define('COLUMN_NOTIFICATION', 'J');
    if (!defined('COLUMN_DEADLINES')) define('COLUMN_DEADLINES', 'K');
    if (!defined('COLUMN_REVALIDATION')) define('COLUMN_REVALIDATION', 'L');
    if (!defined('COLUMN_REGISTRATIONS_FROM')) define('COLUMN_REGISTRATIONS_FROM', 'M');
    if (!defined('COLUMN_REGISTRATIONS_UNTIL')) define('COLUMN_REGISTRATIONS_UNTIL', 'N');
    if (!defined('COLUMN_GRADUATION_DATE')) define('COLUMN_GRADUATION_DATE', 'O');

    $romanNumerals = [
        'I' => 1,
        'II' => 2,
        'III' => 3,
        'IV' => 4,
        'V' => 5,
        'VI' => 6,
    ];


    $content = $academicCalendarPeriod->get_content();
    // Create a temporary file to load the content
    $tempFilePath = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($tempFilePath, $content);

    // Load the XLSX file using PhpSpreadsheet
    $spreadsheet = IOFactory::load($tempFilePath);

    // Process the sheet with ranges
    $rangeSheet = $spreadsheet->getSheet(0);

    $bimesterDatesRecords = [];
    $createdBimesterDatesRecordsIds = [];
    $updatedBimesterDatesRecordsIds = [];

    global $DB, $USER;

    try {
        foreach ($rangeSheet->getRowIterator(2) as $row) {

            // Create an instance of stdClass
            $bimesterDates = new stdClass();
            $bimesterDates->period = $rangeSheet->getCell(COLUMN_PERIOD . $row->getRowIndex())->getValue();

            if (!$bimesterDates->period) {
                continue;
            }

            $periodExploded = explode('-', $bimesterDates->period);
            $bimesterDates->year = $periodExploded[0];
            $bimesterDates->yearquarter = $romanNumerals[$periodExploded[1]];
            $bimesterDates->bimester = $rangeSheet->getCell(COLUMN_BIMESTER . $row->getRowIndex())->getValue();
            $bimesterDates->bimesternumber = $romanNumerals[explode(' ', $bimesterDates->bimester)[0]];
            $bimesterDates->periodstart = Date::excelToDateTimeObject($rangeSheet->getCell(COLUMN_PERIOD_START . $row->getRowIndex())->getValue())->getTimestamp();
            $bimesterDates->periodend = Date::excelToDateTimeObject($rangeSheet->getCell(COLUMN_PERIOD_END . $row->getRowIndex())->getValue())->getTimestamp();
            $bimesterDates->induction = Date::excelToDateTimeObject($rangeSheet->getCell(COLUMN_INDUCTION . $row->getRowIndex())->getValue())->getTimestamp();
            $bimesterDates->finalexamfrom = Date::excelToDateTimeObject($rangeSheet->getCell(COLUMN_FINAL_EXAM_FROM . $row->getRowIndex())->getValue())->getTimestamp();
            $bimesterDates->finalexamuntil = Date::excelToDateTimeObject($rangeSheet->getCell(COLUMN_FINAL_EXAM_UNTIL . $row->getRowIndex())->getValue())->getTimestamp();
            $bimesterDates->loadnotesandclosesubjects = Date::excelToDateTimeObject($rangeSheet->getCell(COLUMN_LOAD_NOTES . $row->getRowIndex())->getValue())->getTimestamp();
            $bimesterDates->delivoflistforrevalbyteach = Date::excelToDateTimeObject($rangeSheet->getCell(COLUMN_DELIVERY_LIST . $row->getRowIndex())->getValue())->getTimestamp();
            $bimesterDates->notiftostudforrevalidations = Date::excelToDateTimeObject($rangeSheet->getCell(COLUMN_NOTIFICATION . $row->getRowIndex())->getValue())->getTimestamp();
            $bimesterDates->deadlforpayofrevalidations = Date::excelToDateTimeObject($rangeSheet->getCell(COLUMN_DEADLINES . $row->getRowIndex())->getValue())->getTimestamp();
            $bimesterDates->revalidationprocess = Date::excelToDateTimeObject($rangeSheet->getCell(COLUMN_REVALIDATION . $row->getRowIndex())->getValue())->getTimestamp();
            $bimesterDates->registrationsfrom = Date::excelToDateTimeObject($rangeSheet->getCell(COLUMN_REGISTRATIONS_FROM . $row->getRowIndex())->getValue())->getTimestamp();
            $bimesterDates->registrationsuntil = Date::excelToDateTimeObject($rangeSheet->getCell(COLUMN_REGISTRATIONS_UNTIL . $row->getRowIndex())->getValue())->getTimestamp();
            $bimesterDates->graduationdate = Date::excelToDateTimeObject($rangeSheet->getCell(COLUMN_GRADUATION_DATE . $row->getRowIndex())->getValue())->getTimestamp();
            $bimesterDates->academicperiodid = $bimesterDates->year . $bimesterDates->yearquarter . $bimesterDates->bimesternumber;

            $bimesterDatesRecords[] = $bimesterDates;
        }

        foreach ($bimesterDatesRecords as $bimesterDates) {
            $bimesterDates->timemodified = time();
            $bimesterDates->usermodified = $USER->id;
            if ($bimerterDateId = $DB->get_field('gmk_academic_calendar', 'id', ['academicperiodid' => $bimesterDates->academicperiodid])) {
                $bimesterDates->id = $bimerterDateId;
                $DB->update_record('gmk_academic_calendar', $bimesterDates);
                $updatedBimesterDatesRecordsIds[] = $bimesterDates->id;
                continue;
            }
            $bimesterDates->timecreated = time();
            $createdBimesterDatesRecordsIds[] = $DB->insert_record('gmk_academic_calendar', $bimesterDates);
        }

        $academicCalendarPeriod->delete();
        return ['created' => $createdBimesterDatesRecordsIds, 'updatded' => $updatedBimesterDatesRecordsIds];
    } catch (Exception $e) {
        $academicCalendarPeriod->delete();
        $DB->delete_records_list('gmk_academic_calendar', 'id', $createdBimesterDatesRecordsIds);
        throw $e;
    }
}

function parse_bulk_disponibilities_CSV($bulkDisponibilitiesFile)
{
    global $DB;

    $teacherSkills = $DB->get_records('gmk_teacher_skill');
    $teacherSkillsMinimized = [];
    foreach ($teacherSkills as $teacherSkill) {
        $teacherSkillsMinimized[cleanString($teacherSkill->name)] = $teacherSkill->id;
    }
    $disponibilityRecords = [];

    $days = [
        'lunes',
        'martes',
        'miercoles',
        'jueves',
        'viernes',
        'sabado',
        'domingo',
    ];

    $errors = [];
    // Get the file content
    $content = $bulkDisponibilitiesFile->get_content();

    // Create a temporary file to load the content
    $tempFilePath = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($tempFilePath, $content);

    // Load the XLSX file using PhpSpreadsheet
    $spreadsheet = IOFactory::load($tempFilePath);

    // Process the sheet with ranges
    $rangeSheet = $spreadsheet->getSheet(0);
    foreach ($rangeSheet->getRowIterator(2) as $row) {
        $instructorId = $rangeSheet->getCell('A' . $row->getRowIndex())->getValue();
        if (!$instructorId) {
            // $errors[]='Error en hoja horario: columna A, fila '.$row->getRowIndex().'. El número de documento es requerido.';
            continue;
        }

        $day = cleanString($rangeSheet->getCell('B' . $row->getRowIndex())->getValue());
        if (!in_array($day, $days)) {
            $errors[] = 'Error en hoja horario: columna B, fila ' . $row->getRowIndex() . '. Día ' . $day . ' no definido.';
            continue;
        }

        $schedule = $rangeSheet->getCell('C' . $row->getRowIndex())->getValue();
        if (!isValidTimeRange($schedule)) {
            $errors[] = 'Error en hoja horario: columna C, fila ' . $row->getRowIndex() . '. El rango ' . $schedule . ' tiene mal formato.';
            continue;
        }
        $timeRange = '[' . $schedule . ']';

        if (!isset($disponibilityRecords[$instructorId])) {
            $disponibilityRecords[$instructorId]['instructorId'] = $instructorId;
            $disponibilityRecords[$instructorId]['newDisponibilityRecords'][$day]['day'] = $day;
            $disponibilityRecords[$instructorId]['newDisponibilityRecords'][$day]['timeslots'] = $timeRange;
            $disponibilityRecords[$instructorId]['skills'] = [];
            continue;
        }
        if (!isset($disponibilityRecords[$instructorId]['newDisponibilityRecords'][$day])) {
            $disponibilityRecords[$instructorId]['newDisponibilityRecords'][$day]['day'] = $day;
            $disponibilityRecords[$instructorId]['newDisponibilityRecords'][$day]['timeslots'] = $timeRange;
            continue;
        }
        $disponibilityRecords[$instructorId]['newDisponibilityRecords'][$day]['timeslots'] .= $timeRange;
    }

    // Process the sheet with skills
    $skillsSheet = $spreadsheet->getSheet(1);

    foreach ($skillsSheet->getRowIterator(2) as $row) {
        $instructorId = $skillsSheet->getCell('A' . $row->getRowIndex())->getValue();
        if (!$instructorId) {
            // $errors[]='Error en hoja habilidades: columna A, fila '.$row->getRowIndex().'. El número de documento es requerido.';
            continue;
        }

        $skill = $skillsSheet->getCell('B' . $row->getRowIndex())->getValue();
        // $teacherSkills
        if (!$skill) {
            // $errors[]='Error en hoja habilidades: columna B, fila '.$row->getRowIndex().'. El ID debe ser númerico.';
            continue;
        }
        $skill = cleanString($skill);
        if (!array_key_exists($skill, $teacherSkillsMinimized)) {
            $errors[] = 'Error en hoja habilidades: columna B, fila ' . $row->getRowIndex() . '. La competencia ' . $skill . ' no es valida.';
            continue;
        }

        if (!isset($disponibilityRecords[$instructorId])) {
            $disponibilityRecords[$instructorId]['instructorId'] = $instructorId;
            $disponibilityRecords[$instructorId]['newDisponibilityRecords'] = [];
            $disponibilityRecords[$instructorId]['skills'] = [$teacherSkillsMinimized[$skill]];
            continue;
        }
        $disponibilityRecords[$instructorId]['skills'][] = $teacherSkillsMinimized[$skill];
    }

    unlink($tempFilePath);

    $bulkDisponibilitiesFile->delete();

    if (!empty($errors)) {

        throw new Exception(json_encode($errors));
    }

    $disponibilityRecords = array_map(function ($disponibilityRecord) {
        $disponibilityRecord['newDisponibilityRecords'] = array_map(function ($dayRange) {
            $dayRange['timeslots'] = parse_bulk_time_ranges($dayRange['timeslots']);
            return $dayRange;
        }, $disponibilityRecord['newDisponibilityRecords']);
        return $disponibilityRecord;
    }, $disponibilityRecords);
    return $disponibilityRecords;
}

function isValidTimeRange($timeString)
{
    // Regular expression to match 'HH:MM' format
    $pattern = '/^(?:2[0-3]|[01][0-9]):[0-5][0-9]-(?:2[0-3]|[01][0-9]):[0-5][0-9]$/';

    // Check if the string matches the pattern
    return preg_match($pattern, $timeString) === 1;
}

function parse_bulk_time_ranges($timeRanges)
{
    $pattern = '/\[(\d{2}:\d{2}-\d{2}:\d{2})\]/';
    preg_match_all($pattern, $timeRanges, $matches);
    $timeslots = [];
    foreach ($matches[1] as $match) {
        $range = str_replace('-', ', ', $match);
        $timeslots[] = $range;
    }
    return $timeslots;
}

function close_current_period()
{
    global $DB, $USER;

    $prerequisiteCustomFieldId = $DB->get_record('customfield_field', ['shortname' => 'pre'])->id;
    $studentRoleId = $DB->get_record('role', ['shortname' => 'student'])->id;
    $enrolplugin = enrol_get_plugin('manual');
    $learningPlans = $DB->get_records('local_learning_plans');

    $learningPlans = ['35' => $learningPlans['35']];

    foreach ($learningPlans as $learningPlan) {

        $learningPlanPeriods = $DB->get_records('local_learning_periods', ['learningplanid' => $learningPlan->id]);
        $learningPlanCourses = $DB->get_records('local_learning_courses', ['learningplanid' => $learningPlan->id]);

        foreach ($learningPlanPeriods as $learningPlanPeriod) {

            $LPPeriodStudents = $DB->get_records('local_learning_users', ['learningplanid' => $learningPlan->id, 'currentperiodid' => $learningPlanPeriod->id, 'userroleid' => 5]);
            if (!$LPPeriodStudents) {
                continue;
            }
            $nextPeriod = $learningPlanPeriods[$learningPlanPeriod->id + 1];
            if (!$nextPeriod) {
                continue;
            }
            $nextPeriodCourses = array_filter($learningPlanCourses, function ($course) use ($learningPlanPeriod) {
                return $course->periodid == $learningPlanPeriod->id + 1;
            });

            $DB->get_records('local_learning_courses', ['learningplanid' => $learningPlan->id, 'periodid' => $learningPlanPeriod->id]);
            $nextPeriodCourses =  $DB->get_records('local_learning_courses', ['learningplanid' => $learningPlan->id, 'periodid' => $nextPeriod->id]);

            foreach ($nextPeriodCourses as $nextPeriodCourse) {
                $course = get_course($nextPeriodCourse->courseid);
                $coursePreRequisites = explode(',', $DB->get_record('customfield_data', ['fieldid' => $prerequisiteCustomFieldId, 'instanceid' => $nextPeriodCourse->courseid])->value);

                foreach ($LPPeriodStudents as $LPPeriodStudent) {
                    $LPPeriodStudent->currentperiodid = $nextPeriod->id;
                    $DB->update_record('local_learning_users', $LPPeriodStudent);

                    if ($coursePreRequisites) {
                        $preRequisitesComplete = true;

                        foreach ($coursePreRequisites as $coursePreRequisite) {
                            $preRequisiteCourse = $DB->get_record('course', ['shortname' => $coursePreRequisite]);
                            $preRequisiteCourseCompletion = new completion_info($preRequisiteCourse);
                            $preRequisiteCourseComplete = $preRequisiteCourseCompletion->is_course_complete($LPPeriodStudent->userid);

                            if (!$preRequisiteCourseComplete) {
                                $preRequisitesComplete = false;
                                break;
                            }
                        }
                        if (!$preRequisitesComplete) {
                            continue;
                        }
                    }
                    $courseInstance = get_manual_enroll($course->id);
                    $enrolled = $enrolplugin->enrol_user($courseInstance, $LPPeriodStudent->userid, $studentRoleId);
                }
            }
        }
    }

    die;
}

function get_teacher_available_courses($params)
{
    global $DB;

    $teacherSkills = $DB->get_records('gmk_teacher_skill_relation', ['userid' => $params['instructorId']]);

    $learningPlanPeriodCourses = $DB->get_records('local_learning_courses', ['learningplanid' => $params['learningPlanId'], 'periodid' => $params['periodId']]);
    $learningPlanPeriodCourses = array_filter(array_map(function ($course) use ($DB, $teacherSkills) {
        $course->name = $DB->get_record('course', ['id' => $course->courseid])->fullname;
        $foundedRequiredSkill = false;
        foreach ($teacherSkills as $teacherSkill) {
            $teacherSkillName = $DB->get_record('gmk_teacher_skill', ['id' => $teacherSkill->skillid])->name;
            $foundedRequiredSkill = containsSubstringIgnoringCaseAndTildes($teacherSkillName, $course->name);
            if ($foundedRequiredSkill) {
                return $course;
            }
        }
        return null;
    }, $learningPlanPeriodCourses));
    return $learningPlanPeriodCourses;
}

function check_reschedule_conflicts($params)
{

    global $DB;

    $errors = [];
    $weekdays = array(
        'Monday' => 'Lunes',
        'Tuesday' => 'Martes',
        'Wednesday' => 'Miércoles',
        'Thursday' => 'Jueves',
        'Friday' => 'Viernes',
        'Saturday' => 'Sábado',
        'Sunday' => 'Domingo'
    );

    $classInfo = list_classes(['id' => $params['classId']])[$params['classId']];

    //Check the instructor availability
    $instructorUserId = $classInfo->instructorid;

    //Get the day of the week in English from the Unix timestamp
    $incomingWeekDay = $weekdays[date('l', strtotime($params['date']))];
    $incomingTimeRangeTS = convert_time_range_to_timestamp_range([$params['initTime'], $params['endTime']]);

    $instructorEvents = get_teacher_disponibility_calendar($instructorUserId)[$instructorUserId];
    $incomingDayAvailableTime = $instructorEvents->daysFree[$params['date']];

    $foundedAvailableRange = false;
    for ($i = 0; $i < count($incomingDayAvailableTime); $i += 2) {
        $freeTimeRangeTS = convert_time_range_to_timestamp_range([$incomingDayAvailableTime[$i], $incomingDayAvailableTime[$i + 1]]);
        if ($incomingTimeRangeTS['initTS'] >= $freeTimeRangeTS['initTS'] && $incomingTimeRangeTS['endTS'] <= $freeTimeRangeTS['endTS']) {
            $foundedAvailableRange = true;
            break;
        }
    }
    if (!$foundedAvailableRange) {
        $errors[] = "El instructor no esta disponible el día " . $incomingWeekDay . " en el horario " . $params['initTime'] . " - " . $params['endTime'] . '.';
    }

    //Check the group members and count how many students are in conflict with the new date and time

    $groupMembers = $DB->get_records('groups_members', array('groupid' => $classInfo->groupid));

    foreach ($groupMembers as $key => $groupMember) {
        if ($groupMember->userid == $instructorUserId) {
            unset($groupMembers[$key]);
            continue;
        }
        $studentEvents = get_class_events($groupMember->userid);
        foreach ($studentEvents as $studentEvent) {
            $eventStart = explode(' ', $studentEvent->start);
            $eventEnd = explode(' ', $studentEvent->end);
            // if ($eventStart[0] === $date) {
            //     continue;
            // }
            $eventTimeRangeTS = convert_time_range_to_timestamp_range([$eventStart[1], $eventEnd[1]]);

            if (($incomingTimeRangeTS['initTS'] >= $eventTimeRangeTS['initTS'] && $incomingTimeRangeTS['endTS'] <= $eventTimeRangeTS['endTS'])
                || ($incomingTimeRangeTS['initTS'] < $eventTimeRangeTS['initTS'] && $incomingTimeRangeTS['endTS'] > $eventTimeRangeTS['initTS'])
                || ($incomingTimeRangeTS['initTS'] < $eventTimeRangeTS['endTS'] && $incomingTimeRangeTS['endTS'] > $eventTimeRangeTS['endTS'])
            ) {
                $userInfo = $DB->get_record('user', ['id' => $groupMember->userid]);
                $errors[] = 'El estudiante ' . $userInfo->firstname . ' ' . $userInfo->lastname . ' presenta conflictos con el horario de la clase ' . $studentEvent->className;
                break;
            }
        }
    }
    // --------------------------------------------------------------------
    $rescheduleConflicts = !empty($errors);

    return ['hasConflicts' => $rescheduleConflicts, 'conflicts' => $errors];
}

function reschedule_class_activity($params)
{
    global $DB;

    //First we get the modules id defined in the modules table, this can vary between moodle installations, so we make sure we hace the correct ids
    $attendanceModuleId = $DB->get_record('modules', array('name' => 'attendance'), '*', MUST_EXIST)->id;
    $bigBlueButtonModuleId = $DB->get_record('modules', array('name' => 'bigbluebuttonbn'), '*', MUST_EXIST)->id;

    //Get the module activity and the class type
    $moduleInfo =  $DB->get_record('course_modules', array('id' => $params['moduleId']), '*', MUST_EXIST);
    $courseModule = $DB->get_record('modules', array('id' => $moduleInfo->module), '*', MUST_EXIST);
    $moduleActivity =  $DB->get_record('modules', array('id' => $moduleInfo->module), '*', MUST_EXIST)->name;
    $classInfo = list_classes(['id' => $params['classId']])[$params['classId']];
    $classSectionNumber = $DB->get_record('course_sections', ['id' => $classInfo->coursesectionid])->section;

    $initDateTime = $params['date'] . ' ' . $params['initTime'];
    $endDateTime = $params['date'] . ' ' . $params['endTime'];
    $initTimestamp = strtotime($initDateTime);
    $endTimestamp = strtotime($endDateTime);
    $classDurationInSeconds = $endTimestamp - $initTimestamp;

    $BBBmoduleId = $DB->get_record('modules', ['name' => 'bigbluebuttonbn'])->id;

    // If the class type is 0 (presencial), just replace the session on the attendance module
    if ($classInfo->type === '0') {
        $attendanceSessionRescheduled = replace_attendance_session($params['moduleId'], $params['sessionId'], $initTimestamp, $classDurationInSeconds, $classInfo);
    }

    // If the class type is 1 (virtual), we need to replace the big blue button module
    else if ($classInfo->type === '1') {
        course_delete_module($params['moduleId']);
        $bigBluebuttonActivityRescheduled = create_big_blue_button_activity($classInfo, $initTimestamp, $endTimestamp, $BBBmoduleId, $classSectionNumber);
    }
    // If the class type is 2 (mixta), we need to reschedule both big blue button activity and attendance session
    else if ($classInfo->type === '2') {

        // print_object($moduleInfo->id);
        // die;
        if ($moduleActivity === 'bigbluebuttonbn') {
            $bigBlueButtonInstanceModuleId = $moduleInfo->id;
            $bigBlueButtonActivityInfo =  $DB->get_record('bigbluebuttonbn', ['id' => $moduleInfo->instance]);
            $bigBlueButtonActivityInitTS = $bigBlueButtonActivityInfo->openingtime;

            // If the reschedule was triggered from the big blue button activity, we must search the attendance session that begins with the same timestamp 
            $classAttendanceModule = $DB->get_record('course_modules', ['section' => $classInfo->coursesectionid, 'module' => $attendanceModuleId]);
            $classAttendanceModuleId = $classAttendanceModule->id;
            $classAttendanceSessionId = $DB->get_record('attendance_sessions', ['attendanceid' => $classAttendanceModule->instance, 'sessdate' => $bigBlueButtonActivityInitTS])->id;
        } else if ($moduleActivity === 'attendance') {
            $classAttendanceModuleId = $moduleInfo->id;
            $classAttendanceSessionId = $params['sessionId'];

            // If the reschedule was triggered from the attendance session, we must search the big bluebutton activity that begins with the same timestamp 
            $classAttendanceSessionInitTS = $DB->get_record('attendance_sessions', ['id' => $classAttendanceSessionId])->sessdate;
            $bigBlueButtonActivityInfo = null;
            $bigBlueButtonActivityItems = $DB->get_records('bigbluebuttonbn', ['openingtime' => $classAttendanceSessionInitTS, 'name' => $classInfo->name . '-' . $classInfo->id . '-' . $classAttendanceSessionInitTS]);
            foreach ($bigBlueButtonActivityItems as $bigBlueButtonActivityItem) {
                if (!$bigBlueButtonActivityInfo) {
                    $bigBlueButtonActivityInfo = $bigBlueButtonActivityItem;
                    continue;
                }
                $bigBlueButtonActivityInfo = $bigBlueButtonActivityItem->timecreated > $bigBlueButtonActivityInfo->timecreated ? $bigBlueButtonActivityItem : $bigBlueButtonActivityInfo;
            }
            $bigBlueButtonInstanceModuleId = $DB->get_record('course_modules', ['instance' => $bigBlueButtonActivityInfo->id, 'module' => $bigBlueButtonModuleId])->id;
        }

        //With the ids required to do the reschedule setted, lets use the methods to reschedute them

        //For attendance
        $attendanceSessionRescheduled = replace_attendance_session($classAttendanceModuleId, $classAttendanceSessionId, $initTimestamp, $classDurationInSeconds, $classInfo);

        //For BBB
        course_delete_module($bigBlueButtonInstanceModuleId);
        $bigBluebuttonActivityRescheduled = create_big_blue_button_activity($classInfo, $initTimestamp, $endTimestamp, $BBBmoduleId, $classSectionNumber);
    }
    return true;
}

//Por revisar

function get_class_events($userId = null, $initDate = null, $endDate = null)
{
    global $DB;

    // Set the date range to look for the events (should be required always from arguments)
    $initDate = $initDate ? $initDate : date('Y-01-01');
    $endDate = $endDate ? $endDate : date('Y-12-31', strtotime('+1 year'));

    //Initialize events array
    $events = [];

    //If the user is provided, get the events that corresponds to the classes he/she is enrolled to
    if ($userId) {
        //First, we get the groups and the courses of the user
        $userGroups = $DB->get_records_sql('SELECT gm.groupid, g.courseid
        FROM {groups_members} gm
        JOIN {groups} g ON (gm.groupid = g.id)
        WHERE gm.userid = :userid', ['userid' => $userId]);

        // Get the user group ids and course ids arrays of the user.
        $userGroupIds = array_unique(array_map(function ($group) {
            return $group->groupid;
        }, $userGroups));
        $userCourseIds = array_unique(array_map(function ($group) {
            return $group->courseid;
        }, $userGroups));
        
        //Get the events filtered by date range, groups and courses.
        $events = calendar_get_events(strtotime($initDate), strtotime($endDate), false, $userGroupIds, $userCourseIds, true);

        // Build a Set of groupIds the student actually belongs to, for post-fetch filtering.
        // calendar_get_events returns events for ALL groups in a course when courseIds are passed,
        // so we must filter down to only the groups the student is enrolled in.
        $userGroupIdSet = array_flip($userGroupIds);
    }
    // If the user is null, let's get all the class events.
    else {
        // Fetch active classes to get their course and group IDs.
        $classes = $DB->get_records('gmk_class', ['closed' => 0], '', 'corecourseid, groupid');
        $courseIds = array_unique(array_filter(array_column($classes, 'corecourseid')));
        $groupIds = array_unique(array_filter(array_column($classes, 'groupid')));

        if (!empty($courseIds) || !empty($groupIds)) {
            // Fetch events for these specific courses and groups.
            $events = calendar_get_events(strtotime($initDate), strtotime($endDate), false, $groupIds ?: false, $courseIds ?: false, true);
        }
    }

    $fetchedClasses = [];
    $eventsFiltered = [];

    foreach ($events as $event) {
        if ($event->modulename === 'attendance') {
            $eventComplete = complete_class_event_information($event, $fetchedClasses);
        } elseif ($event->modulename === 'bigbluebuttonbn') {
             $eventComplete = complete_class_event_information_bbb($event, $fetchedClasses);
        } elseif (in_array($event->eventtype, ['due', 'gradingdue', 'close', 'open'])) {
             // Handle deadlines and other activity events
             $eventComplete = complete_generic_module_event_information($event, $fetchedClasses);
        } else {
             // Other modules ignored for now, or add generic handler if needed
             continue;
        }

        if (!$eventComplete) {
            continue;
        }

        // For student requests: filter out events from groups the student is NOT enrolled in.
        // calendar_get_events returns events for all groups in a course, not just the student's group.
        if ($userId && isset($userGroupIdSet) && !empty($eventComplete->groupid)) {
            if (!isset($userGroupIdSet[$eventComplete->groupid])) {
                continue; // Event belongs to a group the student is not part of
            }
        }

        //If the user is provided, let get the role that he plays in the event
        if ($userId) {
            $courseContext = context_course::instance($eventComplete->courseid);
            $userRolesInCourse = array_values(array_map(function ($role) {
                return $role->shortname;
            }, get_user_roles($courseContext, $userId, false)));

            if (in_array('student', $userRolesInCourse)) {
                $eventComplete->role = 'student';
                unset($eventComplete->attendanceActivityUrl);
                unset($eventComplete->sessionId);
            } else if (in_array('teacher', $userRolesInCourse) || in_array('editingteacher', $userRolesInCourse)) {
                $eventComplete->role = 'teacher';
            }
        }
        $eventsFiltered[] = $eventComplete;
    }
    // Filter out BBB events if an Attendance event exists for the same Class and nearby Start Time
    // BBB sessions are typically created 10 mins (600s) before class start, while Attendance is at class start.
    $attendanceEvents = [];
    foreach ($eventsFiltered as $event) {
        if ($event->modulename === 'attendance' && !empty($event->classId)) {
            $attendanceEvents[$event->classId][] = $event->timestart;
        }
    }

    $finalEvents = [];
    foreach ($eventsFiltered as $event) {
        if ($event->modulename === 'bigbluebuttonbn' && !empty($event->classId)) {
            $isDuplicate = false;
            if (isset($attendanceEvents[$event->classId])) {
                foreach ($attendanceEvents[$event->classId] as $attTime) {
                    // Check if times are within 10 minutes (600s) of each other
                    // Covers exact match and the -600s opening time
                    if (abs($attTime - $event->timestart) <= 601) { 
                        $isDuplicate = true;
                        break;
                    }
                }
            }
            if ($isDuplicate) {
                continue;
            }
        }
        $finalEvents[] = $event;
    }

    return $finalEvents;
}

function complete_class_event_information($event, &$fetchedClasses)
{
    global $DB, $CFG;

    if (!defined('PRESENCIAL_CLASS_TYPE_INDEX')) define('PRESENCIAL_CLASS_TYPE_INDEX', '0');
    if (!defined('VIRTUAL_CLASS_TYPE_INDEX')) define('VIRTUAL_CLASS_TYPE_INDEX', '1');
    if (!defined('MIXTA_CLASS_TYPE_INDEX')) define('MIXTA_CLASS_TYPE_INDEX', '2');
    if (!defined('PRESENCIAL_CLASS_COLOR')) define('PRESENCIAL_CLASS_COLOR', '#00bcd4');
    if (!defined('VIRTUAL_CLASS_COLOR')) define('VIRTUAL_CLASS_COLOR', '#2196f3');
    if (!defined('MIXTA_CLASS_COLOR')) define('MIXTA_CLASS_COLOR', '#673ab7');

    $eventColors = [
        PRESENCIAL_CLASS_TYPE_INDEX => PRESENCIAL_CLASS_COLOR,
        VIRTUAL_CLASS_TYPE_INDEX => VIRTUAL_CLASS_COLOR,
        MIXTA_CLASS_TYPE_INDEX => MIXTA_CLASS_COLOR
    ];

    $attendanceSessionId = $DB->get_field('attendance_sessions', 'id', ['attendanceid' => $event->instance, 'caleventid' => $event->id]);
    $eventModuleClassRelation = $DB->get_record('gmk_bbb_attendance_relation', ['attendanceid' => $event->instance, 'attendancesessionid' => $attendanceSessionId], 'classid,bbbmoduleid,attendancemoduleid,attendancesessionid');

    $gmkClass = null;
    //Save the fetched classes to minimize db queries
    if (!$eventModuleClassRelation) {
        return false;
    }

    $eventClassId = $eventModuleClassRelation->classid;
    $eventAttendanceModuleId = $eventModuleClassRelation->attendancemoduleid;

    if ($eventModuleClassRelation && array_key_exists($eventClassId, $fetchedClasses)) {
        $gmkClass = $fetchedClasses[$eventClassId];
    } else if ($eventModuleClassRelation && !array_key_exists($eventClassId, $fetchedClasses)) {
        $gmkClass = list_classes(["id" => $eventClassId])[$eventClassId];
        $fetchedClasses[$eventClassId] = $gmkClass;
    }

    //Set the class information for the event
    $event->instructorName = $gmkClass->instructorName;
    $event->timeRange = $gmkClass->inithourformatted . ' - ' . $gmkClass->endhourformatted;
    $event->classDaysES = $gmkClass->selectedDaysES;
    $event->classDaysEN = $gmkClass->selectedDaysEN;
    $event->typelabel = $gmkClass->typelabel;
    $event->classType = $gmkClass->type;
    $event->className = $gmkClass->name;
    $event->classId = $gmkClass->id;
    $event->instructorlpid = $gmkClass->instructorlpid;
    $event->instructorid = $gmkClass->instructorid;
    $event->groupid = $gmkClass->groupid;
    $event->coursename = $gmkClass->course->fullname;
    $event->courseShortName = $gmkClass->course->shortname;
    $event->timeduration = $gmkClass->classduration;
    $event->color = $eventColors[$event->classType];

    //Add the attendance module info to the event.
    $event->moduleId = $eventAttendanceModuleId;
    $event->attendanceActivityUrl = $CFG->wwwroot . '/mod/attendance/view.php?id=' . $eventAttendanceModuleId;
    $event->sessionId = $attendanceSessionId;

    //If the class is virtual or mixed, set the BBB activity url
    $event->bigBlueButtonActivityUrl = $event->classType !== '0' ? $CFG->wwwroot . '/mod/bigbluebuttonbn/view.php?id=' . $eventModuleClassRelation->bbbmoduleid : null;

    //Set the initial date and the end date of the event
    $event->start = date('Y-m-d H:i:s', $event->timestart);
    $event->end = date('Y-m-d H:i:s', $event->timestart + $event->timeduration);

    return $event;
}

function createClassroomReservations($classInfo)
{

    $initDate = '2023-08-01';
    $endDate = '2023-08-08';


    //Calculate the class session duration in seconds
    $initDateTime = DateTime::createFromFormat('H:i', $classInfo->inittime);
    $endDateTime = DateTime::createFromFormat('H:i', $classInfo->endtime);
    $classDurationInSeconds = strtotime($endDateTime->format('Y-m-d H:i:s')) - strtotime($initDateTime->format('Y-m-d H:i:s'));
    //

    //Get the period start date in seconds and the day name
    $startDate = DateTime::createFromFormat('Y-m-d H:i:s', $initDate . ' ' . $classInfo->inittime . ':00');
    $startDateTS = strtotime($startDate->format('Y-m-d H:i:s'));
    //

    //Get the period end date timestamp(seconds)
    $endDate = DateTime::createFromFormat('Y-m-d H:i:s', $endDate . ' ' . $classInfo->endtime . ':00');
    $endDateTS = strtotime($endDate->format('Y-m-d H:i:s'));
    //

    //Format the class days
    $classDaysNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    $classDaysList = array_combine($classDaysNames, explode('/', $classInfo->classdays));

    //Define some needed constants
    $currentDateTS = $startDateTS;
    $dayInSeconds = 86400;


    // Create a new cURL resource
    $curl = curl_init();

    // Set the request URL
    $url = 'https://isi-panama-staging-8577170.dev.odoo.com/api/classrooms/' . $classInfo->classroomid . '/reservations';

    // Set the options for the cURL request
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); // Return the response as a string instead of outputting it
    // You can set additional options such as headers, request type, data, etc. if needed
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Authorization: tokendepruebas123'
    ));


    $results = array('success' => [], 'failure' => []);

    while ($currentDateTS < $endDateTS) {
        $day =  $classDaysList[date('l', $currentDateTS)];
        if ($day === '1') {
            $data = array(
                'name' => $classInfo->name . '-' . $classInfo->id . '-' . $currentDateTS,
                'start_date' => $currentDateTS + 3600,
                'end_date' => $currentDateTS + $classDurationInSeconds + 3600,
                'classroom_id' => $classInfo->classroomid
            );
            $data_json = json_encode($data);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data_json);

            // Execute the cURL request and get the response
            $response = curl_exec($curl);
            // Check if an error occurred
            if (curl_errno($curl)) {
                $error = curl_error($curl);
                // Handle the error appropriately
                // For example, you can log the error or display a custom error message
                echo "cURL Error: " . $error;
            }

            // Process the response
            if ($response) {
                // var_dump($response);
            }
        }
        $currentDateTS += $dayInSeconds;
    }
    // Close the cURL resource
    curl_close($curl);

    return $url;
}

function get_teacher_disponibility_calendar($instructorId, $initDate = null, $endDate = null)
{
    global $DB;

    $gmkTeacherDisponibilityTableDayHeaders = array(
        'disp_monday' => 'monday',
        'disp_tuesday' => 'tuesday',
        'disp_wednesday' => 'wednesday',
        'disp_thursday' => 'thursday',
        'disp_friday' => 'friday',
        'disp_saturday' => 'saturday',
        'disp_sunday' => 'sunday'
    );

    $initDate = $initDate ? $initDate :  date('Y-m-d', strtotime('-1 months'));
    $endDate = $endDate ? $endDate : date('Y-m-d', strtotime('+1 months'));

    //Get the teacher disponibility record
    $teacherDisponibilityRecord = $DB->get_record('gmk_teacher_disponibility', ['userid' => $instructorId], '*', MUST_EXIST);

    //Initialize the disponibility calendar object and fill some basic information about the teacher.
    $teacherDisponibilityCalendar = new stdClass();
    $teacherInfo = core_user::get_user($instructorId, 'firstname,lastname', MUST_EXIST);
    $teacherDisponibilityCalendar->id = $instructorId;
    $teacherDisponibilityCalendar->name = $teacherInfo->firstname . ' ' . $teacherInfo->lastname;

    //Lets get the events created for the teacher.
    $teacherDisponibilityCalendar->events = get_class_events($instructorId, $initDate, $endDate);
    $eventsTimesToSubstractFromDisponibility = array();

    foreach ($teacherDisponibilityCalendar->events as $event) {
        $eventInitDateAndTime = explode(' ', $event->start);
        $eventEndDateAndTime = explode(' ', $event->end);

        $eventDate = $eventInitDateAndTime[0];
        $eventInitTime = substr($eventInitDateAndTime[1], 0, 5);
        $eventEndTime = substr($eventEndDateAndTime[1], 0, 5);

        $eventInitTimeTS = strtotime($eventInitTime) - strtotime('today');
        $eventEndTimeTS = strtotime($eventEndTime) - strtotime('today');

        $eventTimeRange = new stdClass();
        $eventTimeRange->st = $eventInitTimeTS;
        $eventTimeRange->et = $eventEndTimeTS;

        if (array_key_exists($eventInitDateAndTime[0], $eventsTimesToSubstractFromDisponibility)) {
            $eventsTimesToSubstractFromDisponibility[$eventInitDateAndTime[0]][] = $eventTimeRange;
            continue;
        }
        $eventsTimesToSubstractFromDisponibility[$eventInitDateAndTime[0]] = [$eventTimeRange];
    }

    $dayDisponibility = [];

    foreach ($gmkTeacherDisponibilityTableDayHeaders as $dayHeader => $day) {
        $dayAvailabilities = json_decode($teacherDisponibilityRecord->{$dayHeader});
        $dayDisponibilityHours = $dayAvailabilities;
        if (empty($dayDisponibilityHours)) {
            continue;
        };
        $dayDisponibility[$day] = $dayDisponibilityHours;
    }

    $currentDate = new DateTime($initDate);
    $lastDate = new DateTime($endDate);
    $teacherDayFreeRanges = [];

    while ($currentDate <= $lastDate) {
        $currentDateDay = $currentDate->format('Y-m-d');
        $currentDate->modify('+1 day');
        $currentDateDayLabel = strtolower(date('l', strtotime($currentDateDay)));
        if (!array_key_exists($currentDateDayLabel, $dayDisponibility)) {
            continue;
        }
        $teacherDayFreeRanges[$currentDateDay] = $dayDisponibility[$currentDateDayLabel];

        if (array_key_exists($currentDateDay, $eventsTimesToSubstractFromDisponibility)) {
            foreach ($eventsTimesToSubstractFromDisponibility[$currentDateDay] as $currentDayEvent) {
                $teacherDayFreeRanges[$currentDateDay] = substract_timerange_from_teacher_disponibility($teacherDayFreeRanges[$currentDateDay], $currentDayEvent);
            }
        }
        if (empty($teacherDayFreeRanges[$currentDateDay])) {
            unset($teacherDayFreeRanges[$currentDateDay]);
            continue;
        }

        $rangeHolder = [];
        foreach ($teacherDayFreeRanges[$currentDateDay] as $dayRange) {
            $rangeHolder[] = sprintf('%02d:%02d', floor($dayRange->st / 3600), floor(($dayRange->st % 3600) / 60));
            $rangeHolder[] = sprintf('%02d:%02d', floor($dayRange->et / 3600), floor(($dayRange->et % 3600) / 60));
        }
        $teacherDayFreeRanges[$currentDateDay] = $rangeHolder;
    }

    $teacherDisponibilityCalendar->daysFree = $teacherDayFreeRanges;
    return $teacherDisponibilityCalendar;
}

function list_instructors()
{

    global $DB;
    //Get the role ids related to teaching.
    $editingTeacherRoleId = $DB->get_field('role', 'id', ["shortname" => 'editingteacher']);
    $teacherRoleId = $DB->get_field('role', 'id', ["shortname" => 'teacher']);
    $scTeacherRoleId = $DB->get_field('role', 'id', ["shortname" => 'scteachrole']);

    //Get the ids of the users who have the roles.
    $editingTeacherUsers = $DB->get_fieldset_select('role_assignments', 'userid', 'roleid = :roleid', ['roleid' => $editingTeacherRoleId]);
    $teacherUsers = $DB->get_fieldset_select('role_assignments', 'userid', 'roleid = :roleid', ['roleid' => $teacherRoleId]);
    $scTeacherUsers = $DB->get_fieldset_select('role_assignments', 'userid', 'roleid = :roleid', ['roleid' => $scTeacherRoleId]);

    //If the usertype user customfield is defined in the platform, obtain the ids of the users who have 'Instructor' value in.
    $customFieldDefinedTeachers = [];
    if ($userTypeCustomFieldId = $DB->get_field('user_info_field', 'id', ['shortname' => 'usertype'])) {
        $userTypeTeacherValue = 'Instructor';
        $customFieldDefinedTeachers = $DB->get_fieldset_select(
            'user_info_data',
            'userid',
            'fieldid = :fieldid AND data = :usertypeteachervalue',
            ['fieldid' => $userTypeCustomFieldId, 'usertypeteachervalue' => $userTypeTeacherValue]
        );
    }

    //Merge all the arrays and get the unique values.
    $instructorIds = array_unique(array_merge($editingTeacherUsers, $teacherUsers, $scTeacherUsers, $customFieldDefinedTeachers));

    //Filter the suspended and unexisting users, and return an array with the necessary information.
    $instructors = array_filter(array_map(function ($instructorId) {
        $userInfo = core_user::get_user($instructorId, 'id,suspended,firstname,lastname');
        if (!$userInfo || $userInfo->suspended === '1') {
            return null;
        }
        $userInfo->fullname = $userInfo->firstname . ' ' . $userInfo->lastname;
        return $userInfo;
    }, $instructorIds));

    return $instructors;
}

function grupomakro_core_list_instructors_with_disponibility_flag()
{
    global $DB;
    $instructors = list_instructors();
    foreach ($instructors as $instructor) {
        $instructor->hasDisponibility = $DB->record_exists('gmk_teacher_disponibility', ["userid" => $instructor->id]);
    }
    return $instructors;
}

function calculate_disponibility_range($timeRanges)
{

    $ranges = [];

    foreach ($timeRanges as $range) {
        $times = explode(',', $range);
        $start = strtotime($times[0]);
        $end = strtotime($times[1]);

        $merged = false;
        foreach ($ranges as $key => $existing) {
            if ($start >= $existing->st && $end <= $existing->et) {
                // New range is completely contained in an existing range
                $merged = true;
                break;
            } elseif ($start <= $existing->st && $end >= $existing->et) {
                // New range completely contains an existing range
                $existing->st = $start;
                $existing->et = $end;
                $merged = true;
                break;
            } elseif ($start <= $existing->et && $end >= $existing->et) {
                // New range overlaps the end of an existing range
                $existing->et = $end;
                $merged = true;
                break;
            } elseif ($end >= $existing->st && $start <= $existing->st) {
                // New range overlaps the start of an existing range
                $existing->st = $start;
                $merged = true;
                break;
            }
        }

        if (!$merged) {
            $ranges[] = (object)['st' => $start, 'et' => $end];
        }
    }

    $result = [];
    foreach ($ranges as $range) {
        $result[] = (object)['st' => $range->st - strtotime('today'), 'et' => $range->et - strtotime('today')];
    }
    return ($result);
}

function getActivityInfo($moduleId, $sessionId = null)
{

    global $DB;

    //First we get the modules id defined in the modules table, this can vary between moodle installations, so we make sure we hace the correct ids
    $attendanceModuleId = $DB->get_record('modules', array('name' => 'attendance'), '*', MUST_EXIST)->id;
    $bigBlueButtonModuleId = $DB->get_record('modules', array('name' => 'bigbluebuttonbn'), '*', MUST_EXIST)->id;

    //Get the module info
    $moduleInfo = $DB->get_record('course_modules', array('id' => $moduleId), '*', MUST_EXIST);

    if ($moduleInfo->module === $bigBlueButtonModuleId) {

        $activityInfo = $DB->get_record('bigbluebuttonbn', array('id' => $moduleInfo->instance), '*', MUST_EXIST);
        $activityInitTS = $activityInfo->openingtime;
        $activityEndTS = $activityInfo->closingtime;
    } else if ($moduleInfo->module === $attendanceModuleId) {
        $sessionInfo = $DB->get_record('attendance_sessions', array('id' => $sessionId), '*', MUST_EXIST);
        $activityInitTS = $sessionInfo->sessdate;
        $activityEndTS = $sessionInfo->sessdate +  $sessionInfo->duration;
    }

    $activityInitDate = date('Y-m-d', $activityInitTS);
    $activityInitTime = date('H:i', $activityInitTS);
    $activityEndTime = date('H:i', $activityEndTS);

    $activityInfo = new stdClass();
    $activityInfo->activityInitDate = $activityInitDate;
    $activityInfo->activityInitTime = $activityInitTime;
    $activityInfo->activityEndTime = $activityEndTime;

    return $activityInfo;
}

function get_institutions($filters = null)
{
    global $DB; // Assuming $DB is a globally accessible database object

    // Retrieve records from the 'gmk_institution' table
    $institutions = $DB->get_records('gmk_institution', $filters);

    // Iterate through each institution
    foreach ($institutions as $institution) {
        // Count the number of contracts associated with the institution

        $institution->contracts = get_institution_contracts(['institutionid' => $institution->id]);
        $institution->numberOfContracts = count($institution->contracts);

        $institution->contractNames = [];
        foreach ($institution->contracts as $contract) {
            $institution->contractNames[] = ['id' => $contract->id, 'contractId' => $contract->contractid];
        }
    }

    // Return the updated array of institution objects
    return array_values($institutions);
}

function get_institution_contracts($filters = null)
{
    global $DB; // Assuming $DB is a globally accessible database object

    // Retrieve records from the 'gmk_institution' table
    $institutionContracts = $DB->get_records('gmk_institution_contract', $filters);


    foreach ($institutionContracts as $institutionContract) {
        $institutionContract->formattedInitDate = date('Y-m-d', $institutionContract->initdate);
        $institutionContract->formattedExpectedEndDate = date('Y-m-d', $institutionContract->expectedenddate);
        $institutionContract->formattedBudget = number_format($institutionContract->budget, 0, '.', '.');
        $institutionContract->formattedBillingCondition = $institutionContract->billingcondition . '%';

        $institutionContract->users = get_contract_users($institutionContract->contractid, ['contractid' => $institutionContract->id]);
        $institutionContract->usersCount = 0;
        foreach ($institutionContract->users as $institutionContractUser) {
            $institutionContract->usersCount += count($institutionContractUser->courses);
        }
    }
    // Return the updated array of institution objects
    return array_values($institutionContracts);
}

function get_contract_users($contractName, $filters = null)
{
    global $DB, $CFG; // Assuming $DB is a globally accessible database object
    $contractUserRecords = $DB->get_records('gmk_contract_user', $filters);
    $contractUsers = [];



    foreach ($contractUserRecords as $contractUserRecord) {
        $contractCourse = $DB->get_record('course', ['id' => $contractUserRecord->courseid]);
        $contractUserRecordInstance = clone $contractUserRecord;
        $contractUserRecordInstance->courseName = $contractCourse->fullname;
        $contractUserRecordInstance->contractName = $contractName;

        if (array_key_exists($contractUserRecord->userid, $contractUsers)) {
            $contractUsers[$contractUserRecord->userid]->contractInstances[] = $contractUserRecordInstance;
            $contractUsers[$contractUserRecord->userid]->courses[] = $contractCourse->fullname;
            continue;
        }
        $contractUserRecord->contractInstances = [$contractUserRecordInstance];
        $userInfo = $DB->get_record('user', ['id' => $contractUserRecord->userid]);
        $contractUserRecord->phone = $userInfo->phone1 ? $userInfo->phone1 : 'Sin definir';
        $contractUserRecord->email = $userInfo->email;
        $contractUserRecord->fullname = $userInfo->firstname . ' ' . $userInfo->lastname;
        $contractUserRecord->avatar = get_user_picture_url($userInfo->id);
        $contractUserRecord->profileUrl = $CFG->wwwroot . '/user/profile.php?id=' . $userInfo->id;
        $contractUserRecord->courses = [$contractCourse->fullname];

        $contractUsers[$contractUserRecord->userid] = $contractUserRecord;
    }
    return array_values($contractUsers);
}

function get_contract_users_by_institution($institutionContracts)
{

    $contractUsers = [];
    foreach ($institutionContracts as $institutionContract) {
        foreach ($institutionContract->users as $institutionContractUser) {
            if (!array_key_exists($institutionContractUser->userid, $contractUsers)) {
                $institutionContractUserInstance = new stdClass();
                $institutionContractUserInstance->userid = $institutionContractUser->userid;
                $institutionContractUserInstance->phone = $institutionContractUser->phone;
                $institutionContractUserInstance->email = $institutionContractUser->email;
                $institutionContractUserInstance->fullname = $institutionContractUser->fullname;
                $institutionContractUserInstance->avatar = $institutionContractUser->avatar;
                $institutionContractUserInstance->profileUrl = $institutionContractUser->profileUrl;
                $institutionContractUserInstance->courses = $institutionContractUser->courses;
                $institutionContractUserInstance->acquiredContracts = 1;
                $institutionContractUserInstance->contracts = [];
                foreach ($institutionContractUser->contractInstances as $contractInstance) {
                    $institutionContractUserInstance->contracts[] = ['id' => $contractInstance->id, 'contractId' => $contractInstance->contractName, 'courseName' => $contractInstance->courseName];
                }
                $contractUsers[$institutionContractUser->userid] = $institutionContractUserInstance;
                continue;
            }
            $contractUsers[$institutionContractUser->userid]->acquiredContracts += 1;
            foreach ($institutionContractUser->contractInstances as $contractInstance) {
                $contractUsers[$institutionContractUser->userid]->contracts[] = ['id' => $contractInstance->id, 'contractId' => $contractInstance->contractName, 'courseName' => $contractInstance->courseName];
            }
            foreach ($institutionContractUser->courses as $institutionContractUserCourse) {
                !in_array($institutionContractUserCourse, $contractUsers[$institutionContractUser->userid]->courses) ?
                    $contractUsers[$institutionContractUser->userid]->courses[] = $institutionContractUserCourse :
                    null;
            }
        }
    }

    foreach ($contractUsers as $contractUser) {
        $contractUser->coursesString = implode(', ', $contractUser->courses);
    }

    return $contractUsers;
}

function get_institution_contract_panel_info($institutionId, $institutionContractFilter = null, $institutionContractUserFilter = null)
{
    $institutionDetailedInfo = new stdClass();
    $institutionDetailedInfo->institutionInfo = get_institutions(['id' => $institutionId])[0];
    $institutionDetailedInfo->contractUsers = get_contract_users_by_institution($institutionDetailedInfo->institutionInfo->contracts);
    $institutionDetailedInfo->institutionInfo->numberOfUsers = count($institutionDetailedInfo->contractUsers);

    if ($institutionContractFilter) {
        $filteredContracts = [];
        foreach ($institutionDetailedInfo->institutionInfo->contracts as $institutionContract) {
            if (stripos($institutionContract->contractid, $institutionContractFilter) !== false) {
                $filteredContracts[] = $institutionContract;
            }
        }
        $institutionDetailedInfo->institutionInfo->contracts = $filteredContracts;
    }

    if ($institutionContractUserFilter) {
        $filteredContractUsers = [];
        foreach ($institutionDetailedInfo->contractUsers as $institutionContractUser) {
            if (stripos($institutionContractUser->fullname, $institutionContractUserFilter) !== false || stripos($institutionContractUser->email, $institutionContractUserFilter) !== false) {
                $filteredContractUsers[] = $institutionContractUser;
            }
        }
        $institutionDetailedInfo->contractUsers = $filteredContractUsers;
    }

    return $institutionDetailedInfo;
}

function check_enrol_link_validity($token)
{
    global $DB;
    $plugin_name = 'local_grupomakro_core';
    $enrolLinkRecord = $DB->get_record('gmk_contract_enrol_link', ['token' => $token]);
    if (!$enrolLinkRecord) {
        throw new Exception(get_string('invalidtoken', $plugin_name));
    } else if (time() > $enrolLinkRecord->expirationdate) {

        throw new Exception(get_string('contractenrollinkexpirated', $plugin_name));
    }

    $enrolLinkRecord->courseName = $DB->get_record('course', ['id' => $enrolLinkRecord->courseid])->fullname;
    $enrolLinkRecord->contractId = $DB->get_record('gmk_institution_contract', ['id' => $enrolLinkRecord->contractid])->contractid;

    return $enrolLinkRecord;
}

function create_contract_user($user)
{
    global $DB, $USER;
    $enrolplugin = enrol_get_plugin('manual');
    $userContractRecordsResult = array();

    $courseIds = explode(',', $user['courseIds']);
    $contractUserRecords = new stdClass();
    $contractUserRecords->failure = array();
    $contractUserRecords->success = array();
    //loop for each course id and try to enrol the user; if so, add the record to the user contract table
    foreach ($courseIds as $courseId) {
        if (!$DB->get_record('course', ['id' => $courseId])) {
            $contractUserRecords->failure[] = ['courseId' => $courseId, 'message' => 'El curso con el id ' . $courseId . ' no existe'];
        }

        $instance = get_manual_enroll($courseId);
        if ($DB->get_record('gmk_contract_user', ['userid' => $user['userId'], 'contractid' => $user['contractId'], 'courseid' => $courseId]) || !$instance) {
            $contractUserRecords->failure[] = ['courseId' => $courseId, 'message' => 'El curso ' . $DB->get_record('course', ['id' => $courseId])->fullname . ' con id ' . $courseId . ' ya esta matriculado para este contrato y este usuario'];
            continue;
        }
        $enrolled = $enrolplugin->enrol_user($instance, $user['userId'], 5);

        $newContractUserRecord = new stdClass();
        $newContractUserRecord->userid = $user['userId'];
        $newContractUserRecord->contractid = $user['contractId'];
        $newContractUserRecord->courseid = $courseId;
        $newContractUserRecord->timecreated = time();
        $newContractUserRecord->timemodified = time();
        $newContractUserRecord->usermodified = $USER->id;

        $newContractUserRecord->id = $DB->insert_record('gmk_contract_user', $newContractUserRecord);
        $contractUserRecords->success[] = ['courseId' => $courseId, 'message' => 'ok'];
    }
    $userContractRecordsResult[$user['userId']] = ['success' => $contractUserRecords->success, 'failure' => $contractUserRecords->failure];
    return $userContractRecordsResult;
}

function create_student_user($user)
{

    $user->mnethostid = 1;
    try {
        $newUserId = user_create_user($user);
        return $newUserId;
    } catch (Exception $e) {
        return $e;
    }
}

function get_classrooms()
{
    global $DB;
    $records = $DB->get_records('gmk_classrooms', ['active' => 1], 'name ASC');
    return array_values(array_map(function ($classroom) {
        return [
            'label'    => $classroom->name . ', Cap: ' . ($classroom->capacity ?? 0),
            'value'    => (int)$classroom->id,
            'capacity' => (int)($classroom->capacity ?? 0),
        ];
    }, $records));
}

function student_get_active_classes($userId, $courseId = null)
{
    global $DB;
    $courseCustomFieldHandler = core_course\customfield\course_handler::create();
    $user = $DB->get_record('user', array('id' => $userId), '*', MUST_EXIST);
    profile_load_data($user);
    $userDayFilter = $user->profile_field_gmkjourney;

    $userLearningPlans = $DB->get_records('local_learning_users', array('userid' => $userId));
    $activeClasses = array();
    $activeClasses['userDayFilter'] = $userDayFilter;

    // TODO: Retrieve the final enrolment day from the academic calendar
    $finalEnrolmentDate = new DateTime();
    $finalEnrolmentDate = $finalEnrolmentDate->add(new DateInterval('P7D'));
    $finalEnrolmentDate = $finalEnrolmentDate->format('Y-m-d H:i:s');
    $activeClasses['finalEnrolmentDate'] = $finalEnrolmentDate;

    foreach ($userLearningPlans as $userLearningPlan) {
        $courseFilter = ['learningplanid' => $userLearningPlan->learningplanid];
        $courseId ? $courseFilter['courseid'] = $courseId : null;

        $learningPlanUserCourses = $DB->get_records('gmk_course_progre', $courseFilter);
        $learningPlanUserCoursesIndexed = [];
        foreach ($learningPlanUserCourses as $learningPlanUserCourse) {
            // Use the course ID as the index for the new array
            $learningPlanUserCourse->prerequisites = json_decode($learningPlanUserCourse->prerequisites);
            $learningPlanUserCoursesIndexed[$learningPlanUserCourse->courseid] = $learningPlanUserCourse;
        }
        $learningPlanUserCourses = $learningPlanUserCoursesIndexed;

        // Initialize an array to store failed prerequisites
        $neededCourses  = [];

        // Iterate over each course in the learning plan
        foreach ($learningPlanUserCourses as $courseId => $course) {
            // Check if the course belongs to the current period
            if ($course->periodid != $userLearningPlan->currentperiodid) {
                continue;
            }
            // Check the status of prerequisites
            $neededCourses = $neededCourses + checkPrerequisites($course, $learningPlanUserCourses);
        }
        // print_object($neededCourses);
        foreach ($neededCourses as $neededCourse) {
            $classFilter = ['corecourseid' => $neededCourse->courseid, 'approved' => '0', 'closed' => '0'];

            $courseCustomFields = $courseCustomFieldHandler->get_instance_data($neededCourse->courseid);
            $tc = '0';
            foreach ($courseCustomFields as $customField) {
                if ($customField->get_field()->get('shortname') === 'tc') {
                    $tc = $customField->get_value();
                }
            }
            $tc === 0 ? $classFilter['learningplanid'] = $userLearningPlan->learningplanid : null;
            $courseActiveClasses = list_classes($classFilter);

            foreach ($courseActiveClasses as $courseActiveClass) {
                $activeSchedule = construct_active_schedule_object($courseActiveClass, $userId);
                // print_object($activeSchedule);
                // die;
                if (!array_key_exists($neededCourse->courseid, $activeClasses['classes'])) {
                    $activeClasses['classes'][$neededCourse->courseid]["id"] = $neededCourse->courseid;
                    $activeClasses['classes'][$neededCourse->courseid]["name"] = $courseActiveClass->course->fullname;
                    $activeClasses['classes'][$neededCourse->courseid]["schedules"] = [$activeSchedule->classId => $activeSchedule];
                    $activeClasses['classes'][$neededCourse->courseid]["selected"] ?  null : $activeClasses['classes'][$neededCourse->courseid]["selected"] = $activeSchedule->selected;
                    continue;
                }
                $activeClasses['classes'][$neededCourse->courseid]["schedules"][$activeSchedule->classId] = $activeSchedule;
                $activeClasses['classes'][$neededCourse->courseid]["selected"] ?  null : $activeClasses['classes'][$neededCourse->courseid]["selected"] = $activeSchedule->selected;
            }
        }
    };
    return $activeClasses;
}

function checkPrerequisites($course, $learningPlanUserCourses)
{

    $neededCourses = [];
    $failedPrerequisites = [];

    foreach ($course->prerequisites as $prerequisite) {

        $prerequisiteCourseId = $prerequisite->id;
        if (!isset($learningPlanUserCourses[$prerequisiteCourseId])) {
            continue;
        }

        $prerequisiteCourse = $learningPlanUserCourses[$prerequisiteCourseId];

        if ($prerequisiteCourse->status == 4) {
            continue;
        }
        // Prerequisite not completed, recursively check its prerequisites
        $nestedFailedPrerequisites = checkPrerequisites($prerequisiteCourse, $learningPlanUserCourses);
        $failedPrerequisites = $failedPrerequisites + $nestedFailedPrerequisites;

        $courseInSamePeriod = true;
        foreach ($failedPrerequisites as $failedPrerequisite) {
            if ($failedPrerequisite->periodid !== $course->periodid) {
                $courseInSamePeriod = false;
            }
        }
        if ($courseInSamePeriod) {
            $failedPrerequisites = $failedPrerequisites + [$course->courseid => $course];
        }
    }
    $neededCourses = empty($failedPrerequisites) ? [$course->courseid => $course] : $failedPrerequisites;
    return $neededCourses;
}

function construct_active_schedule_object($class, $userId)
{

    global $DB;

    $learningPlanActiveSchedule = new stdClass();
    $learningPlanActiveSchedule->days = "";
    foreach ($class->selectedDaysES as $index => $classDay) {
        $learningPlanActiveSchedule->days .=  $classDay . ($index === count($class->selectedDaysES) - 1 ? "" : " - ");
    }
    $learningPlanActiveSchedule->start = $class->inithourformatted;
    $learningPlanActiveSchedule->end = $class->endhourformatted;
    $learningPlanActiveSchedule->instructor = $class->instructorName;
    $learningPlanActiveSchedule->type = $class->typelabel;
    $learningPlanActiveSchedule->groupId = $class->groupid;
    $learningPlanActiveSchedule->classId = $class->id;
    $learningPlanActiveSchedule->selected = !!$DB->get_record('gmk_class_pre_registration', ['classid' => $class->id, 'userid' => $userId]);;
    $learningPlanActiveSchedule->inQueue = !!$DB->get_record('gmk_class_queue', ['classid' => $class->id, 'userid' => $userId]);;
    $learningPlanActiveSchedule->available = $class->available;
    $learningPlanActiveSchedule->preRegisteredStudents = $class->preRegisteredStudents;
    $learningPlanActiveSchedule->queuedStudents = $class->queuedStudents;
    $learningPlanActiveSchedule->classFull = $class->classFull;
    $learningPlanActiveSchedule->daysFilters = $class->daysFilters;
    return $learningPlanActiveSchedule;
}

//Util functions------------------------------------------------------------------------------------------------------------------------------

function convert_time_range_to_timestamp_range($timeRange)
{
    $rangeInitHour = intval(substr($timeRange[0], 0, 2));
    $rangeInitMinutes = substr($timeRange[0], 3, 2);
    $rangeEndHour = intval(substr($timeRange[1], 0, 2));
    $rangeEndMinutes = substr($timeRange[1], 3, 2);
    $rangeInitTimeTS = $rangeInitHour * 3600 + $rangeInitMinutes * 60;
    $rangeEndTimeTS = $rangeEndHour * 3600 + $rangeEndMinutes * 60;

    return array("initTS" => $rangeInitTimeTS, "endTS" => $rangeEndTimeTS);
}

/**
 * Convert time ranges from input format to formatted time ranges.
 *
 * @param string $ranges_json The time ranges in JSON format.
 * @return array The time ranges as an array of formatted time ranges.
 */
function convert_timestamp_ranges_to_time_ranges($timestampRanges)
{
    // Parse the input as a JSON array
    $timestampRanges = json_decode($timestampRanges, true);

    $timeRanges = array();
    foreach ($timestampRanges as $range) {
        // Convert start and end times to DateTime objects
        $start = new DateTime('midnight');
        $start->add(new DateInterval('PT' . $range['st'] . 'S'));

        $end = new DateTime('midnight');
        $end->add(new DateInterval('PT' . $range['et'] . 'S'));

        // Format the start and end times as strings
        $startStr = $start->format('H:i');
        $endStr = $end->format('H:i');

        // Add the formatted time range to the result array
        $timeRanges[] = "$startStr, $endStr";
    }
    // Return the result array
    return $timeRanges;
}

/**
 * Get the URL for the user picture.
 *
 * @param int $userid The ID of the user.
 * @param int $size The size of the picture (in pixels).
 * @return string The URL of the user picture.
 */
function get_user_picture_url($userid, $size = 100)
{
    global $DB;
    try {
        $user = $DB->get_record('user', array('id' => $userid));
        if (!$user) {
            return '';
        }
        $context = \context_user::instance($user->id);
        $url = \moodle_url::make_pluginfile_url(
            $context->id,
            'user',
            'icon',
            null,
            null,
            null,
            $size
        );
        return $url->out();
    } catch (Exception $error) {
        return null;
    }
}

function get_learning_plan_image($learningPlanId)
{

    $fs = get_file_storage();
    $context = context_system::instance();
    $files = $fs->get_area_files($context->id, 'local_sc_learningplans', 'learningplan_image', $learningPlanId);
    $urlimg = '';
    foreach ($files as $file) {
        $imageurl = \moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            $file->get_component(),
            $file->get_filearea(),
            $file->get_itemid(),
            $file->get_filepath(),
            $file->get_filename(),
            false
        );
        $urlimg = $imageurl->out(false);
    }
    return $urlimg;
}

function get_logged_user_token()
{
    global $DB;
    $service = $DB->get_record('external_services', array('shortname' => 'moodle_mobile_app', 'enabled' => 1));
    return json_encode(external_generate_token_for_current_user($service)->token);
}

function get_theme_token()
{
    global $DB;
    $themeExternalService = $DB->get_record('external_services', array('shortname' => 'Settings_Theme', 'enabled' => 1));
    return json_encode(external_generate_token_for_current_user($themeExternalService)->token);
}

function containsSubstringIgnoringCaseAndTildes($needle, $haystack)
{
    // Convert both strings to lowercase
    $needle = mb_strtolower($needle, 'UTF-8');
    $haystack = mb_strtolower($haystack, 'UTF-8');

    $transliterator = Transliterator::create('NFD;[:Nonspacing Mark:] Remove;NFC');

    // Remove diacritic marks (tildes) using iconv
    $needle = $transliterator->transliterate($needle);
    $haystack = $transliterator->transliterate($haystack);

    // Use strpos to check if $needle is in $haystack
    return strpos($haystack, $needle) !== false;
}

function cleanString($string)
{
    $string = mb_strtolower($string, 'UTF-8');

    $transliterator = Transliterator::create('NFD;[:Nonspacing Mark:] Remove;NFC');

    $string = $transliterator->transliterate($string);

    return $string;
}

function updateTimeByMinutes($timeString, $minutesToAdd = 1)
{
    list($hour, $minute) = explode(":", $timeString);

    // Convert hour and minute to integers
    $hour = intval($hour);
    $minute = intval($minute);

    // Check if we should add or subtract minutes
    if ($minutesToAdd >= 0) {
        $minute += $minutesToAdd;
    } else {
        $minute -= abs($minutesToAdd);
    }

    // Handle overflow and underflow
    while ($minute < 0) {
        $hour -= 1;
        $minute += 60;
    }

    while ($minute > 59) {
        $hour += 1;
        $minute -= 60;
    }

    // Format the updated time back into "HH:MM"
    $updatedTime = sprintf("%02d:%02d", $hour, $minute);

    return $updatedTime;
}

function check_if_time_range_is_contained($rangeArray, $inputRange)
{
    $rangeContained = false;
    foreach ($rangeArray as $key => $range) {
        if ($range->st <= $inputRange->st && $inputRange->et <= $range->et) {
            // input range is fully contained within the current range
            $rangeContained = true;
            break;
        }
    }
    return $rangeContained;
}

function substract_timerange_from_teacher_disponibility($rangeArray, $inputRange)
{
    foreach ($rangeArray as $rangeIndex => $range) {
        if ($range->st <= $inputRange->st && $inputRange->et <= $range->et) {
            // input range is fully contained within the current range
            if ($range->st == $inputRange->st && $range->et == $inputRange->et) {
                // input range is identical to current range, so remove it completely
                unset($rangeArray[$rangeIndex]);
            } else {
                // input range is within current range, so split it
                $newRange1 = new stdClass();
                $newRange1->st = $range->st;
                $newRange1->et = $inputRange->st; // - 1;

                $newRange2 = new stdClass();
                $newRange2->st = $inputRange->et; // + 1;
                $newRange2->et = $range->et;

                // remove the current range from the range array and add the two new ranges
                unset($rangeArray[$rangeIndex]);

                // if the input range is not completely contained in the beginning of the current range
                if ($newRange1->et > $newRange1->st) {
                    $rangeArray[] = $newRange1;
                }

                // if the input range is not completely contained in the end of the current range
                if ($newRange2->et > $newRange2->st) {
                    $rangeArray[] = $newRange2;
                }
            }
            return $rangeArray;
        }
    }
    return $rangeArray;
}

/**
 * Get instance of manual enrol
 *
 * @param int $courseid
 * @return stdClass instance
 */
function get_manual_enroll($courseid)
{
    $instances = enrol_get_instances($courseid, true);
    foreach ($instances as $instance) {
        if ($instance->enrol = 'manual') {
            return $instance;
        }
    }
    return false;
}

/**
 * Check if a class is closed.
 *
 * @param int $classId The class ID.
 * @return bool True if closed, false otherwise.
 */
function is_class_closed($classId) {
    global $DB;
    return (bool) $DB->get_field('gmk_class', 'closed', ['id' => $classId]);
}

/**
 * Toggle the lock status of the grade category associated with a class.
 *
 * @param int $classId The class ID.
 * @param bool $locked True to lock, false to unlock.
 * @return void
 */
function toggle_class_grade_lock($classId, $locked) {
    global $DB;
    
    $class = $DB->get_record('gmk_class', ['id' => $classId]);
    if (!$class || empty($class->gradecategoryid)) {
        return;
    }

    require_once($GLOBALS['CFG']->libdir . '/gradelib.php');
    
    $grade_category = \grade_category::fetch(['id' => $class->gradecategoryid, 'courseid' => $class->corecourseid]);
    if ($grade_category) {
        $grade_category->set_locked($locked);
    }
}

/**
 * Ensures assign moduleinfo contains non-null defaults for required columns.
 *
 * Some forks/versions are strict on NOT NULL assign fields and can fail with:
 * "Column 'submissiondrafts' cannot be null".
 */
function local_grupomakro_apply_assign_defaults(stdClass &$moduleinfo, array $assigncols): void {
    $assigncols = array_change_key_case($assigncols, CASE_LOWER);
    $defaults = [
        'alwaysshowdescription' => 1,
        // In this fork, 0 can be normalized to NULL during module creation path.
        // Use 1 to keep NOT NULL constraints satisfied.
        'submissiondrafts' => 1,
        'requiresubmissionstatement' => 0,
        'sendnotifications' => 0,
        'sendlatenotifications' => 0,
        'sendstudentnotifications' => 1,
        'duedate' => 0,
        'cutoffdate' => 0,
        'gradingduedate' => 0,
        'allowsubmissionsfromdate' => 0,
        'grade' => 100,
        'completionsubmit' => 0,
        'teamsubmission' => 0,
        'requireallteammemberssubmit' => 0,
        'teamsubmissiongroupingid' => 0,
        'blindmarking' => 0,
        'hidegrader' => 0,
        'revealidentities' => 0,
        'attemptreopenmethod' => 'none',
        'maxattempts' => -1,
        'markingworkflow' => 0,
        'markingallocation' => 0,
        'preventsubmissionnotingroup' => 0,
    ];

    foreach ($defaults as $field => $value) {
        if (!array_key_exists($field, $assigncols)) {
            continue;
        }
        if (!property_exists($moduleinfo, $field) || $moduleinfo->{$field} === null) {
            $moduleinfo->{$field} = $value;
        }
    }
}

/**
 * Creates an activity (BBB, Assignment, etc.) with pre-calculated parameters.
 * Part of the innovative Teacher Experience.
 */
function local_grupomakro_create_express_activity($classid, $type, $name, $intro, $extra = []) {
    global $DB, $CFG;
    require_once($CFG->dirroot . '/course/modlib.php');
    
    // DEBUG LOG
    file_put_contents(__DIR__ . '/debug.log', date('Y-m-d H:i:s') . " - Creating activity: $name type: $type classid: $classid\n", FILE_APPEND);

    if ($classid == -1) {
        $course = get_course(SITEID); // Use Front Page
        // Get section 1 of front page or create if needed
        $modinfo = get_fast_modinfo($course);
        // Use first available section typically
        $section = $DB->get_record('course_sections', ['course' => $course->id, 'section' => 1]);
        if (!$section) {
            // Fallback to section 0 if 1 doesn't exist (unlikely on front page usually)
             $section = $DB->get_record('course_sections', ['course' => $course->id, 'section' => 0]);
        }
    } else {
        $class = $DB->get_record('gmk_class', ['id' => $classid], '*', MUST_EXIST);
        $course = get_course($class->corecourseid);
        $section = $DB->get_record('course_sections', ['id' => $class->coursesectionid], '*', MUST_EXIST);
    }
    
    $module = (object)[
        'id' => gmk_get_module_id_by_name($type)
    ];
    
    $moduleinfo = new stdClass();
    $moduleinfo->modulename = $type;
    $moduleinfo->module     = $module->id;
    $moduleinfo->name       = $name;
    $moduleinfo->intro      = $intro;
    $moduleinfo->introformat = FORMAT_HTML;
    $moduleinfo->course     = $course->id;
    $moduleinfo->section    = $section->section;
    $moduleinfo->visible    = 1;
    $moduleinfo->groupmode  = 1; // Separate groups
    
    if (!empty($extra['gradecat'])) {
        $moduleinfo->gradecat = $extra['gradecat'];
    }
    
    if ($type === 'bigbluebuttonbn') {
        $moduleinfo->type = 0; // All features
        $moduleinfo->participants = '[{"selectiontype":"all","selectionid":"all","role":"viewer"}]';
        $moduleinfo->record = 1;
        if (!empty($extra['guest'])) {
            // $moduleinfo->guest = 1; // Disabled: Column does not exist in this BBB version
        }
    } else if ($type === 'assign') {
        $moduleinfo->grade = 100;
        $moduleinfo->duedate = !empty($extra['duedate']) ? $extra['duedate'] : 0;
        $moduleinfo->assignsubmission_file_enabled = 1;
        $moduleinfo->assignsubmission_onlinetext_enabled = 1;
        // Keep assign defaults conservative and explicitly non-null.
        $assigncols = $DB->get_columns('assign');
        local_grupomakro_apply_assign_defaults($moduleinfo, $assigncols);
        if (array_key_exists('submissiondrafts', array_change_key_case($assigncols, CASE_LOWER))) {
            // Force non-empty truthy value so this fork does not convert it to NULL.
            $moduleinfo->submissiondrafts = 1;
        }

        // Preflight repair for known gradebook corruption that breaks assign/grade insert.
        try {
            $gbstats = gmk_repair_course_gradebook_duplicates((int)$course->id);
            gmk_log(
                "INFO: assign gradebook preflight courseid={$course->id} " .
                "roots={$gbstats['rootCandidates']} rootcats={$gbstats['rootcats']} " .
                "courseitems={$gbstats['courseitems']} canonical={$gbstats['canonicalRootId']} " .
                "merged={$gbstats['mergedRoots']} deleteditems={$gbstats['deletedCourseItems']} " .
                "relinked={$gbstats['relinkedCourseItems']} createdcourse={$gbstats['createdCourseItems']} " .
                "fixedorphan={$gbstats['fixedOrphanCategoryItems']} dedupcat={$gbstats['dedupedCategoryItems']} " .
                "mergedgrades={$gbstats['mergedGradeRows']}"
            );
        } catch (\Throwable $repairerr) {
            gmk_log("WARNING: assign gradebook preflight fallo courseid={$course->id}: " . $repairerr->getMessage());
        }
    } else if ($type === 'quiz') {
        $moduleinfo->grade = 10; // Default max grade
        $moduleinfo->timeopen = !empty($extra['timeopen']) ? $extra['timeopen'] : 0;
        $moduleinfo->timeclose = !empty($extra['timeclose']) ? $extra['timeclose'] : 0;
        $moduleinfo->timelimit = !empty($extra['timelimit']) ? intval($extra['timelimit']) : 0; // seconds
        $moduleinfo->attempts = !empty($extra['attempts']) ? intval($extra['attempts']) : 1;
        $moduleinfo->grademethod = !empty($extra['grademethod']) ? intval($extra['grademethod']) : 1; // 1 = Highest grade
        
        // Additional Quiz Defaults
        $moduleinfo->preferredbehaviour = 'deferredfeedback';
        $moduleinfo->attemptonlast = 0;
        $moduleinfo->browsersecurity = '-';
        $moduleinfo->shuffleanswers = 1;
        
        // Review Options (Default: Show everything after close)
        // Explicitly set bits for standard behavior if defaults aren't picked up
        $quiz_review_mask = 0x10000 | 0x01000 | 0x00100 | 0x00010 | 0x00001; // Example mask
        
        // Critical: Missing DB defaults
        // WORKAROUND: Moodle unsets empty passwords, but DB schema forbids NULL.
        // We set a dummy password, let it save, then clear it immediately via SQL.
        $moduleinfo->password = 'temp_pass'; 
        $moduleinfo->subnet = '';
        $moduleinfo->delay1 = 0;
        $moduleinfo->delay2 = 0;
        $moduleinfo->showuserpicture = 0;
        $moduleinfo->showblocks = 0;
        $moduleinfo->navmethod = 'free';
        $moduleinfo->overduehandling = 'autosubmit';
        $moduleinfo->graceperiod = 0;
        $moduleinfo->canredoquestions = 0;
        $moduleinfo->allowofflineattempts = 0;
        
        // Review options (during, immediately, open, closed)
        // 0 = none, or specific bitmask. We set 0 for now or standard.
        // Moodle 3.x/4.x requires these columns
        $moduleinfo->reviewattempt = 0;
        $moduleinfo->reviewcorrectness = 0;
        $moduleinfo->reviewmarks = 0;
        $moduleinfo->reviewspecificfeedback = 0;
        $moduleinfo->reviewgeneralfeedback = 0;
        $moduleinfo->reviewrightanswer = 0;
        $moduleinfo->reviewoverallfeedback = 0;
        
        // Completion defaults if enabled site-wide
        $moduleinfo->completionpass = 0;
        $moduleinfo->completionattemptsexhausted = 0;
    } else if ($type === 'forum') {
        $moduleinfo->type = 'general'; // Standard general forum
        $moduleinfo->forcesubscribe = 1; // Auto-subscribe
        $moduleinfo->maxbytes = 0; // Course limit
        $moduleinfo->maxattachments = 9;
    }
    
    
    // DEBUG: Force crash to see state
    // throw new Exception("DEBUG STOP: Type: $type. Pass: " . (isset($moduleinfo->password) ? $moduleinfo->password : 'UNSET'));

    // Try redundant mapping for Moodle form weirdness
    $moduleinfo->quizpassword = 'temp_pass';

    try {
        if ($type === 'assign') {
            file_put_contents(
                __DIR__ . '/gmk_debug.log',
                '[' . date('Y-m-d H:i:s') . '] INFO assign payload classid=' . $classid .
                ' submissiondrafts=' . (property_exists($moduleinfo, 'submissiondrafts') ? var_export($moduleinfo->submissiondrafts, true) : 'MISSING') .
                ' grade=' . (property_exists($moduleinfo, 'grade') ? var_export($moduleinfo->grade, true) : 'MISSING') .
                ' duedate=' . (property_exists($moduleinfo, 'duedate') ? var_export($moduleinfo->duedate, true) : 'MISSING') .
                PHP_EOL,
                FILE_APPEND
            );
        }
        $result = add_moduleinfo($moduleinfo, $course);
    } catch (\Throwable $e) {
        file_put_contents(
            __DIR__ . '/gmk_debug.log',
            '[' . date('Y-m-d H:i:s') . '] WARNING add_moduleinfo failed type=' . $type .
            ' classid=' . $classid . ' msg=' . $e->getMessage() .
            ' trace=' . str_replace(["\r", "\n"], ' | ', $e->getTraceAsString()) . PHP_EOL,
            FILE_APPEND
        );

        // Assign is the most version-sensitive module in this fork.
        // Retry with a minimal payload to maximize compatibility.
        if ($type !== 'assign') {
            throw $e;
        }

        // If Moodle reports duplicate-read corruption, repair gradebook and retry once.
        if (gmk_is_duplicate_read_error($e->getMessage())) {
            try {
                $gbstats = gmk_repair_course_gradebook_duplicates((int)$course->id);
                gmk_log(
                    "INFO: assign gradebook reactive-repair courseid={$course->id} " .
                    "roots={$gbstats['rootCandidates']} rootcats={$gbstats['rootcats']} " .
                    "courseitems={$gbstats['courseitems']} canonical={$gbstats['canonicalRootId']} " .
                    "merged={$gbstats['mergedRoots']} deleteditems={$gbstats['deletedCourseItems']} " .
                    "relinked={$gbstats['relinkedCourseItems']} createdcourse={$gbstats['createdCourseItems']} " .
                    "fixedorphan={$gbstats['fixedOrphanCategoryItems']} dedupcat={$gbstats['dedupedCategoryItems']} " .
                    "mergedgrades={$gbstats['mergedGradeRows']}"
                );
            } catch (\Throwable $repairerr2) {
                gmk_log("WARNING: assign reactive gradebook repair fallo courseid={$course->id}: " . $repairerr2->getMessage());
            }
        }

        $minimal = new stdClass();
        $minimal->modulename = 'assign';
        $minimal->module = $module->id;
        $minimal->name = $name;
        $minimal->intro = $intro;
        $minimal->introformat = FORMAT_HTML;
        $minimal->course = $course->id;
        $minimal->section = $section->section;
        $minimal->visible = 1;
        $minimal->groupmode = 1;
        $minimal->grade = 100;
        $minimal->duedate = !empty($extra['duedate']) ? $extra['duedate'] : 0;
        $minimal->assignsubmission_file_enabled = 1;
        $minimal->assignsubmission_onlinetext_enabled = 1;
        $minimalassigncols = $DB->get_columns('assign');
        local_grupomakro_apply_assign_defaults($minimal, $minimalassigncols);
        if (array_key_exists('submissiondrafts', array_change_key_case($minimalassigncols, CASE_LOWER))) {
            $minimal->submissiondrafts = 1;
        }
        if (!empty($extra['gradecat'])) {
            $minimal->gradecat = $extra['gradecat'];
        }

        try {
            file_put_contents(
                __DIR__ . '/gmk_debug.log',
                '[' . date('Y-m-d H:i:s') . '] INFO assign retry payload classid=' . $classid .
                ' submissiondrafts=' . (property_exists($minimal, 'submissiondrafts') ? var_export($minimal->submissiondrafts, true) : 'MISSING') .
                ' grade=' . (property_exists($minimal, 'grade') ? var_export($minimal->grade, true) : 'MISSING') .
                ' duedate=' . (property_exists($minimal, 'duedate') ? var_export($minimal->duedate, true) : 'MISSING') .
                PHP_EOL,
                FILE_APPEND
            );
            $result = add_moduleinfo($minimal, $course);
        } catch (\Throwable $retrye) {
            file_put_contents(
                __DIR__ . '/gmk_debug.log',
                '[' . date('Y-m-d H:i:s') . '] ERROR add_moduleinfo retry failed type=assign classid=' . $classid .
                ' msg=' . $retrye->getMessage() . PHP_EOL,
                FILE_APPEND
            );
            throw $retrye;
        }
    }
    if (empty($result) || empty($result->coursemodule)) {
        throw new \moodle_exception('No se pudo crear la actividad en el curso.');
    }
    
    // WORKAROUND: Clear the dummy password we set earlier
    if ($type === 'quiz' && isset($moduleinfo->instance) && $moduleinfo->password === 'temp_pass') {
        $DB->set_field('quiz', 'password', '', array('id' => $moduleinfo->instance));
    }
    
    // Handle template saving if requested
    if (!empty($extra['save_as_template'])) {
        local_grupomakro_save_activity_template($name, $type, $moduleinfo);
    }
    
    return $result;
}

/**
 * Saves an activity configuration as a reusable template.
 */
function local_grupomakro_save_activity_template($name, $type, $configdata) {
    global $DB, $USER;
    $template = new stdClass();
    $template->name = $name;
    $template->module = $type;
    $template->configdata = json_encode($configdata);
    $template->usermodified = $USER->id;
    $template->timecreated = time();
    return $DB->insert_record('gmk_activity_templates', $template);
}

/**
 * Sets a break timer for a class, stored in cache for synchronization.
 * Innovative Feature 6: Break Manager.
 */
function local_grupomakro_set_break_timer($classid, $duration_minutes) {
    $cache = \cache::make('local_grupomakro_core', 'break_timers');
    $endtime = time() + ($duration_minutes * 60);
    $cache->set($classid, $endtime);
    return $endtime;
}

/**
 * Gets the current break status for a class.
 */
function local_grupomakro_get_break_status($classid) {
    $cache = \cache::make('local_grupomakro_core', 'break_timers');
    $endtime = $cache->get($classid);
    if (!$endtime || $endtime < time()) {
        return null;
    }
    return $endtime;
}

/**
 * Saves anonymous student feedback for a session.
 * Innovative Feature 8: Climate Surveys.
 */
function local_grupomakro_save_climate_feedback($sessionid, $rating) {
    global $DB;
    $feedback = new stdClass();
    $feedback->sessionid = $sessionid;
    $feedback->rating = $rating;
    $feedback->timecreated = time();
    return $DB->insert_record('gmk_climate_feedback', $feedback);
}

/**
 * Syncs student financial status from Odoo Proxy.
 *
 * @param array $userids Optional list of specific user IDs to sync. If empty, syncs a batch of oldest updated users.
 * @return array Result stats.
 */
function local_grupomakro_sync_financial_status($userids = []) {
    global $DB, $CFG;
    require_once($CFG->libdir . '/filelib.php');

    // 1. Identify users to sync
    $fieldDoc = $DB->get_record('user_info_field', array('shortname' => 'documentnumber'));
    if (!$fieldDoc) {
        return ['error' => 'Field documentnumber not found'];
    }

    $sql = "SELECT u.id, d.data as documentnumber
            FROM {user} u
            JOIN {user_info_data} d ON (d.userid = u.id AND d.fieldid = :fieldid)
            LEFT JOIN {gmk_financial_status} fs ON (fs.userid = u.id)
            WHERE u.deleted = 0 AND d.data IS NOT NULL AND d.data != ''";
    $params = ['fieldid' => $fieldDoc->id];

    if (!empty($userids)) {
        list($insql, $inparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $sql .= " AND u.id $insql";
        $params = array_merge($params, $inparams);
    } else {
        // Prevent infinite loops: Only pick users not updated in the last hour
        // or never updated.
        $cutoff = time() - 3600; 
        $sql .= " AND (fs.lastupdated IS NULL OR fs.lastupdated < :cutoff)";
        $params['cutoff'] = $cutoff;

        // Prioritize those that have never been updated (fs.id IS NULL) or oldest update
        $sql .= " ORDER BY COALESCE(fs.lastupdated, 0) ASC";
    }

    // Limit batch size to 50 if automated (no IDs provided) to avoid timeouts
    $limit = empty($userids) ? 50 : 0;
    
    $users = $DB->get_records_sql($sql, $params, 0, $limit);
    if (empty($users)) {
        return ['updated' => 0, 'message' => 'No users to update'];
    }

    $docNumbersMap = [];
    foreach ($users as $u) {
        $doc = trim($u->documentnumber);
        // Basic cleanup of doc number if needed
        if ($doc) {
            $docNumbersMap[$doc] = $u->id;
        }
    }

    if (empty($docNumbersMap)) {
         return ['updated' => 0, 'message' => 'No valid document numbers found in batch'];
    }

    // 2. Call Odoo Proxy (Native cURL to bypass Moodle localhost block)
    // TODO: Move to plugin settings
    $proxyUrl = get_config('local_grupomakro_core', 'odoo_proxy_url');
    if (empty($proxyUrl)) {
        $proxyUrl = 'https://lms.isi.edu.pa:4000'; // Hardcoded fallback
    }
    $endpoint = rtrim($proxyUrl, '/') . '/api/odoo/status/bulk';

    $payload = json_encode(['documentNumbers' => array_keys($docNumbersMap)]);
    
    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        debugging("Odoo Proxy Connection Error: " . $curl_error, DEBUG_DEVELOPER);
        return ['error' => 'Proxy Connection Error: ' . $curl_error];
    }

    if ($http_code !== 200 && $http_code !== 201) {
        debugging("Odoo Proxy HTTP Error: " . $http_code . " - " . $response, DEBUG_DEVELOPER);
        return ['error' => 'Proxy Error: ' . $http_code, 'details' => substr($response, 0, 200)];
    }

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['error' => 'Invalid JSON from Proxy', 'details' => substr($response, 0, 100)];
    }

    // 3. Update Status
    $updated = 0;
    $time = time();
    $transaction = $DB->start_delegated_transaction();

    foreach ($data as $doc => $statusInfo) {
        if (!isset($docNumbersMap[$doc])) continue;
        
        $userid = $docNumbersMap[$doc];
        
        $record = new stdClass();
        $record->userid = $userid;
        // Use 'status' if available from Odoo, otherwise fall back to 'reason'
        // This is safer if Odoo API changes or if 'reason' was being used as status incorrectly
        $newStatus = isset($statusInfo['status']) ? $statusInfo['status'] : (isset($statusInfo['reason']) ? $statusInfo['reason'] : 'none');
        $record->status = $newStatus;
        
        // Increase truncation to 255 to match new DB schema
        $record->reason = isset($statusInfo['reason']) ? substr($statusInfo['reason'], 0, 255) : '';
        $record->json_data = json_encode($statusInfo);
        $record->lastupdated = $time;
        $record->timemodified = $time;

        // Check if exists
        $existing = $DB->get_record('gmk_financial_status', ['userid' => $userid]);
        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('gmk_financial_status', $record);
        } else {
            $DB->insert_record('gmk_financial_status', $record);
        }
        $updated++;
    }
    
    $transaction->allow_commit();

    return [
        'success' => true,
        'updated' => $updated, 
        'total_requested' => count($docNumbersMap),
        'total_fetched' => count($data)
    ];
}

function complete_class_event_information_bbb($event, &$fetchedClasses)
{
    global $DB, $CFG;

    // Define constants if not already defined in scope (safe to redefine or check defined)
    if (!defined('PRESENCIAL_CLASS_TYPE_INDEX')) define('PRESENCIAL_CLASS_TYPE_INDEX', '0');
    if (!defined('VIRTUAL_CLASS_TYPE_INDEX')) define('VIRTUAL_CLASS_TYPE_INDEX', '1');
    if (!defined('MIXTA_CLASS_TYPE_INDEX')) define('MIXTA_CLASS_TYPE_INDEX', '2');
    if (!defined('PRESENCIAL_CLASS_COLOR')) define('PRESENCIAL_CLASS_COLOR', '#00bcd4');
    if (!defined('VIRTUAL_CLASS_COLOR')) define('VIRTUAL_CLASS_COLOR', '#2196f3');
    if (!defined('MIXTA_CLASS_COLOR')) define('MIXTA_CLASS_COLOR', '#673ab7');

    $eventColors = [
        PRESENCIAL_CLASS_TYPE_INDEX => PRESENCIAL_CLASS_COLOR,
        VIRTUAL_CLASS_TYPE_INDEX => VIRTUAL_CLASS_COLOR,
        MIXTA_CLASS_TYPE_INDEX => MIXTA_CLASS_COLOR
    ];

    // Attempt to link this BBB activity to a Class
    // 1. Try relation table first
    $cm = get_coursemodule_from_instance('bigbluebuttonbn', $event->instance, $event->courseid);
    $relation = null;
    if ($cm) {
        $relation = $DB->get_record('gmk_bbb_attendance_relation', ['bbbid' => $event->instance, 'bbbmoduleid' => $cm->id], '*', IGNORE_MULTIPLE);
        $event->cmid = $cm->id; // Verify this is set for later use
    }
    
    $gmkClass = null;

    if ($relation) {
        $eventClassId = $relation->classid;
    } else {
        // 2. heuristic: Find class by Group ID if event has one
        if (!empty($event->groupid)) {
            $gmkClass = $DB->get_record('gmk_class', ['groupid' => $event->groupid, 'closed' => 0]);
        }
        
        // 3. Fallback: Find class by Course ID (if only one active class exists for this course)
        if (!$gmkClass) {
             $classes = $DB->get_records('gmk_class', ['corecourseid' => $event->courseid, 'closed' => 0]);
             if (count($classes) == 1) {
                 $gmkClass = reset($classes);
             }
        }
    }

    if (isset($eventClassId) && !$gmkClass) {
        if (array_key_exists($eventClassId, $fetchedClasses)) {
            $gmkClass = $fetchedClasses[$eventClassId];
        } else {
            $gmkClass = $DB->get_record('gmk_class', ['id' => $eventClassId]);
            if ($gmkClass) $fetchedClasses[$eventClassId] = $gmkClass;
        }
    }

    if (!$gmkClass) {
        // If we can't link it to a specific Makro Class, we return generic event info or skip?
        // Let's return generic info so it at least shows up
        $event->color = VIRTUAL_CLASS_COLOR; // Default to virtual
        $event->className = $event->course->fullname ?? 'Actividad Virtual'; 
        // Need basic fields to prevent JS errors if it expects them
        $event->instructorName = '';
        $event->instructorid = 0;
        $event->timeRange = date('H:i', $event->timestart) . ' - ' . date('H:i', $event->timestart + $event->timeduration);
        $event->classDaysES = [];
        $event->classDaysEN = [];
        $event->classType = 1; 
        $event->typelabel = 'VIRTUAL';
        $event->coursename = $event->className;
    } else {
        // Populate from Class
        // Ensure class has helper fields if we fetched it raw (using list_classes for consistency)
        if (!isset($gmkClass->selectedDaysES)) {
             $enrichedClasses = list_classes(['id' => $gmkClass->id]);
             if (!empty($enrichedClasses)) {
                  $gmkClass = $enrichedClasses[$gmkClass->id];
                  $fetchedClasses[$gmkClass->id] = $gmkClass;
             }
        }

        $event->instructorName = $gmkClass->instructorName ?? '';
        $event->instructorid = $gmkClass->instructorid ?? 0;
        $event->timeRange = ($gmkClass->inithourformatted ?? '') . ' - ' . ($gmkClass->endhourformatted ?? '');
        $event->classDaysES = $gmkClass->selectedDaysES ?? [];
        $event->classDaysEN = $gmkClass->selectedDaysEN ?? [];
        $event->typelabel = $gmkClass->typelabel ?? ($gmkClass->type == 1 ? 'VIRTUAL' : 'PRESENCIAL');
        $event->classType = $gmkClass->type ?? 1;
        $event->className = $gmkClass->name ?? '';
        $event->coursename = $gmkClass->course->fullname ?? $event->className;
        $event->classId = $gmkClass->id;
        $event->groupid = $gmkClass->groupid;
        $event->color = isset($eventColors[$event->classType]) ? $eventColors[$event->classType] : VIRTUAL_CLASS_COLOR;
        $event->timeduration = $gmkClass->classduration ?? $event->timeduration;
    }

    $event->bigBlueButtonActivityUrl = $CFG->wwwroot . '/mod/bigbluebuttonbn/view.php?id=' . $event->cmid; // Need CMID. Event usually has 'cmid' or we find it.
    // Event object from calendar_get_events usually has 'modulename', 'instance', 'courseid'. 
    // It might NOT have 'cmid'.
    if (empty($event->cmid)) {
        $cm = get_coursemodule_from_instance('bigbluebuttonbn', $event->instance, $event->courseid);
        $event->bigBlueButtonActivityUrl = $CFG->wwwroot . '/mod/bigbluebuttonbn/view.php?id=' . ($cm ? $cm->id : 0);
    }

    $event->start = date('Y-m-d H:i:s', $event->timestart);
    $event->end = date('Y-m-d H:i:s', $event->timestart + $event->timeduration);

    return $event;
}

/**
 * Completes event information for generic modules (assign, quiz, etc.) 
 * to show deadlines in the teacher calendar.
 */
function complete_generic_module_event_information($event, &$fetchedClasses) {
    global $DB, $CFG;

    // Try to link this activity to a Class
    $gmkClass = null;

    // 1. Heuristic: Find class by Group ID if event has one
    if (!empty($event->groupid)) {
        if (array_key_exists('group_' . $event->groupid, $fetchedClasses)) {
             $gmkClass = $fetchedClasses['group_' . $event->groupid];
        } else {
             $gmkClass = $DB->get_record('gmk_class', ['groupid' => $event->groupid, 'closed' => 0]);
             if ($gmkClass) $fetchedClasses['group_' . $event->groupid] = $gmkClass;
        }
    }
    
    // 2. Fallback: Find class by Course ID (if only one active class exists for this course)
    if (!$gmkClass) {
         if (array_key_exists('course_' . $event->courseid, $fetchedClasses)) {
             $gmkClass = $fetchedClasses['course_' . $event->courseid];
         } else {
             $classes = $DB->get_records('gmk_class', ['corecourseid' => $event->courseid, 'closed' => 0]);
             if (count($classes) == 1) {
                 $gmkClass = reset($classes);
                 $fetchedClasses['course_' . $event->courseid] = $gmkClass;
             }
         }
    }

    if (!$gmkClass) {
        // If we can't link it to a specific Class, just provide basic course info
        $course = $DB->get_record('course', ['id' => $event->courseid], 'id, fullname');
        $event->className = $course ? $course->fullname : 'Actividad';
        $event->coursename = $event->className;
        $event->classId = 0;
    } else {
        // Populate from Class
        // Ensure class has helper fields
        if (!isset($gmkClass->selectedDaysES) && !empty($gmkClass->id)) {
             $enrichedClasses = list_classes(['id' => $gmkClass->id]);
             if (!empty($enrichedClasses)) {
                  $gmkClass = $enrichedClasses[$gmkClass->id];
                  $fetchedClasses['group_' . $gmkClass->groupid] = $gmkClass;
             }
        }

        $event->instructorName = $gmkClass->instructorName ?? '';
        $event->instructorid = $gmkClass->instructorid ?? 0;
        $event->className = $gmkClass->name ?? '';
        $event->coursename = $gmkClass->course->fullname ?? $event->className;
        $event->courseShortName = $gmkClass->course->shortname ?? '';
        $event->classId = $gmkClass->id;
        $event->groupid = $gmkClass->groupid;
    }

    // Set specialized colors and prefixes based on event type
    switch ($event->eventtype) {
        case 'due':
            $event->color = '#FF9800'; // Orange
            $event->name = "Vencimiento: " . $event->name;
            break;
        case 'gradingdue':
            $event->color = '#E91E63'; // Pink/Red
            $event->name = "Por calificar: " . $event->name;
            $event->is_grading_task = true;
            break;
        case 'close':
            $event->color = '#F44336'; // Red
            $event->name = "Cierre: " . $event->name;
            break;
        default:
            $event->color = '#9E9E9E'; // Grey
            break;
    }

    // Ensure fields always present so the LXP frontend doesn't throw on missing properties
    $event->classDaysES   = $event->classDaysES   ?? [];
    $event->classDaysEN   = $event->classDaysEN   ?? [];
    $event->timeRange     = $event->timeRange     ?? date('H:i', $event->timestart);
    $event->typelabel     = $event->typelabel     ?? 'Actividad';
    $event->instructorName = $event->instructorName ?? '';

    $event->timeduration = 0; // Deadlines are usually points in time
    $event->start = date('Y-m-d H:i:s', $event->timestart);
    $event->end = $event->start;

    return $event;
}

/**
 * Fetches all items pending for grading (Assignments and Quizzes).
 * 
 * @param int $userid The teacher ID.
 * @param int $classid Optional class ID to filter.
 * @return array List of pending items.
 */
function gmk_get_pending_grading_items($userid, $classid = 0, $status = 'pending') {
    global $DB;
    
    $results = [];
    $is_admin = is_siteadmin($userid);
    $class = null;

    if ($classid > 0) {
        $class = $DB->get_record('gmk_class', ['id' => $classid]);
        if (!$class) {
            return [];
        }
        if (!$is_admin && (int)$class->instructorid !== (int)$userid) {
            return [];
        }
    }
    
    // A. Assignments
    $assign_params = [];
    $assign_course_filter = "";
    $assign_group_filter = "";
    $assign_item_scope_filter = "";

    if ($classid > 0) {
        $cid = !empty($class->corecourseid) ? $class->corecourseid : $class->courseid;
        $assign_course_filter = " AND a.course = :courseid";
        $assign_params['courseid'] = $cid;
        if (!empty($class->groupid)) {
            $assign_group_filter = " AND EXISTS (SELECT 1 FROM {groups_members} gm WHERE gm.groupid = :groupid AND gm.userid = s.userid)";
            $assign_params['groupid'] = $class->groupid;
        } else {
            $assign_group_filter = " AND EXISTS (SELECT 1 FROM {gmk_course_progre} cp2 WHERE cp2.classid = :assignclassid AND cp2.userid = s.userid)";
            $assign_params['assignclassid'] = (int)$classid;
        }

        // Restrict to activities that belong to this class scope (section/category/name suffix).
        $scopes = [];
        if (!empty($class->coursesectionid)) {
            $scopes[] = "EXISTS (
                SELECT 1
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                 WHERE m.name = 'assign'
                   AND cm.course = a.course
                   AND cm.instance = a.id
                   AND cm.section = :assignsectionid
            )";
            $assign_params['assignsectionid'] = (int)$class->coursesectionid;
        }
        if (!empty($class->gradecategoryid)) {
            $scopes[] = "EXISTS (
                SELECT 1
                  FROM {grade_items} gi
                 WHERE gi.courseid = a.course
                   AND gi.itemtype = 'mod'
                   AND gi.itemmodule = 'assign'
                   AND gi.iteminstance = a.id
                   AND gi.categoryid = :assigncatid
            )";
            $assign_params['assigncatid'] = (int)$class->gradecategoryid;
        }
        $scopes[] = $DB->sql_like('a.name', ':assignsuffix', false, false);
        $assign_params['assignsuffix'] = '%-' . (int)$classid;
        $assign_item_scope_filter = " AND (" . implode(" OR ", $scopes) . ")";
    } else if (!$is_admin) {
        // Global teacher view: only activities that belong to classes assigned in gmk_class.
        $assign_course_filter = " AND EXISTS (
            SELECT 1
              FROM {gmk_class} cls
             WHERE cls.instructorid = :instructorid
               AND cls.closed = 0
               AND (cls.corecourseid = a.course OR cls.courseid = a.course)
               AND (
                    (cls.groupid > 0 AND EXISTS (
                        SELECT 1
                          FROM {groups_members} gm2
                         WHERE gm2.groupid = cls.groupid
                           AND gm2.userid = s.userid
                    ))
                    OR
                    (cls.groupid = 0 AND EXISTS (
                        SELECT 1
                          FROM {gmk_course_progre} cp2
                         WHERE cp2.classid = cls.id
                           AND cp2.userid = s.userid
                    ))
               )
        )";
        $assign_params['instructorid'] = $userid;
    }

    $assign_grade_condition = ($status === 'history') ? "(g.grade IS NOT NULL AND g.grade >= 0)" : "(g.grade IS NULL OR g.grade < 0)";

    $sql_assign = "SELECT s.id as submissionid, s.userid, s.assignment as itemid, s.timecreated as submissiontime,
                          a.name as itemname, a.course as courseid, a.duedate,
                          c.fullname as coursename, 
                          u.firstname, u.lastname, u.email, u.picture, u.imagealt,
                          u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename
                   FROM {assign_submission} s
                   JOIN {assign} a ON a.id = s.assignment
                   JOIN {course} c ON c.id = a.course
                   JOIN {user} u ON u.id = s.userid
                   LEFT JOIN {assign_grades} g ON g.assignment = a.id AND g.userid = s.userid AND g.attemptnumber = s.attemptnumber
                   WHERE s.status = 'submitted' AND s.latest = 1 AND $assign_grade_condition
                   $assign_course_filter $assign_group_filter $assign_item_scope_filter";
    
    // Store for debug if requested
    if (isset($GLOBALS['GMK_DEBUG'])) {
        $GLOBALS['GMK_DEBUG']['sql_assign'] = $sql_assign;
        $GLOBALS['GMK_DEBUG']['params_assign'] = $assign_params;
    }

    $assigns = $DB->get_records_sql($sql_assign, $assign_params);
    if ($assigns) {
        foreach ($assigns as $asgn) {
            $it = clone $asgn;
            $it->modname = 'assign';
            $results[] = $it;
        }
    }

    // B. Quizzes
    $quiz_params = [];
    $quiz_course_filter = "";
    $quiz_group_filter = "";
    $quiz_item_scope_filter = "";

    if ($classid > 0) {
        $cid = !empty($class->corecourseid) ? $class->corecourseid : $class->courseid;
        $quiz_course_filter = " AND q.course = :courseid";
        $quiz_params['courseid'] = $cid;
        if (!empty($class->groupid)) {
            $quiz_group_filter = " AND EXISTS (SELECT 1 FROM {groups_members} gm WHERE gm.groupid = :groupid AND gm.userid = quiza.userid)";
            $quiz_params['groupid'] = $class->groupid;
        } else {
            $quiz_group_filter = " AND EXISTS (SELECT 1 FROM {gmk_course_progre} cp2 WHERE cp2.classid = :quizclassid AND cp2.userid = quiza.userid)";
            $quiz_params['quizclassid'] = (int)$classid;
        }

        // Restrict to activities that belong to this class scope (section/category/name suffix).
        $scopes = [];
        if (!empty($class->coursesectionid)) {
            $scopes[] = "EXISTS (
                SELECT 1
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                 WHERE m.name = 'quiz'
                   AND cm.course = q.course
                   AND cm.instance = q.id
                   AND cm.section = :quizsectionid
            )";
            $quiz_params['quizsectionid'] = (int)$class->coursesectionid;
        }
        if (!empty($class->gradecategoryid)) {
            $scopes[] = "EXISTS (
                SELECT 1
                  FROM {grade_items} gi
                 WHERE gi.courseid = q.course
                   AND gi.itemtype = 'mod'
                   AND gi.itemmodule = 'quiz'
                   AND gi.iteminstance = q.id
                   AND gi.categoryid = :quizcatid
            )";
            $quiz_params['quizcatid'] = (int)$class->gradecategoryid;
        }
        $scopes[] = $DB->sql_like('q.name', ':quizsuffix', false, false);
        $quiz_params['quizsuffix'] = '%-' . (int)$classid;
        $quiz_item_scope_filter = " AND (" . implode(" OR ", $scopes) . ")";
    } else if (!$is_admin) {
        // Global teacher view: only activities that belong to classes assigned in gmk_class.
        $quiz_course_filter = " AND EXISTS (
            SELECT 1
              FROM {gmk_class} cls
             WHERE cls.instructorid = :instructorid
               AND cls.closed = 0
               AND (cls.corecourseid = q.course OR cls.courseid = q.course)
               AND (
                    (cls.groupid > 0 AND EXISTS (
                        SELECT 1
                          FROM {groups_members} gm2
                         WHERE gm2.groupid = cls.groupid
                           AND gm2.userid = quiza.userid
                    ))
                    OR
                    (cls.groupid = 0 AND EXISTS (
                        SELECT 1
                          FROM {gmk_course_progre} cp2
                         WHERE cp2.classid = cls.id
                           AND cp2.userid = quiza.userid
                    ))
               )
        )";
        $quiz_params['instructorid'] = $userid;
    }

    $quiz_needsgrading_condition = ($status === 'history') ? "NOT EXISTS" : "EXISTS";

    $sql_quiz = "SELECT quiza.id as submissionid, quiza.userid, quiza.quiz as itemid, quiza.timefinish as submissiontime,
                        q.name as itemname, q.course as courseid, q.timeclose as duedate,
                        c.fullname as coursename, 
                        u.firstname, u.lastname, u.email, u.picture, u.imagealt,
                        u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename
                 FROM {quiz_attempts} quiza
                 JOIN {quiz} q ON q.id = quiza.quiz
                 JOIN {course} c ON c.id = q.course
                 JOIN {user} u ON u.id = quiza.userid
                 WHERE quiza.state = 'finished'
                   AND $quiz_needsgrading_condition (
                       SELECT 1 FROM {question_attempts} qa 
                       JOIN {question_attempt_steps} qas ON qas.questionattemptid = qa.id
                       WHERE qa.questionusageid = quiza.uniqueid 
                         AND qas.sequencenumber = (
                             SELECT MAX(inner_qas.sequencenumber) 
                             FROM {question_attempt_steps} inner_qas 
                             WHERE inner_qas.questionattemptid = qa.id
                         )
                         AND qas.state = 'needsgrading'
                   )
                   $quiz_course_filter $quiz_group_filter $quiz_item_scope_filter";
    
    // Store for debug if requested
    if (isset($GLOBALS['GMK_DEBUG'])) {
        $GLOBALS['GMK_DEBUG']['sql_quiz'] = $sql_quiz;
        $GLOBALS['GMK_DEBUG']['params_quiz'] = $quiz_params;
    }

    $quizzes = $DB->get_records_sql($sql_quiz, $quiz_params);
    if ($quizzes) {
        foreach ($quizzes as $qz) {
            $it = clone $qz;
            $it->modname = 'quiz';
            $results[] = $it;
        }
    }

    // Sort by submission time
    usort($results, function($a, $b) {
        return $a->submissiontime - $b->submissiontime;
    });

    return $results;
}

/**
 * Get student attendance summary (absence count).
 * @param int $userid
 * @param int $classid
 * @return array
 */
function gmk_get_student_attendance_summary($userid, $classid) {
    global $DB;
    
    try {
        $class = $DB->get_record('gmk_class', ['id' => $classid], '*', MUST_EXIST);
        
        // Find attendance instances
        $all_atts = $DB->get_records('attendance', ['course' => $class->courseid], '', 'id');
        if (empty($all_atts) && !empty($class->corecourseid)) {
            $all_atts = $DB->get_records('attendance', ['course' => $class->corecourseid], '', 'id');
        }
        
        if (empty($all_atts)) {
            return ['absences' => 0];
        }
        
        $att = reset($all_atts);
        
        // Get statuses that count as absence (grade 0 and not marked as 'Excused' if possible, but grade 0 is a good proxy)
        $statuses = $DB->get_records('attendance_statuses', ['attendanceid' => $att->id]);
        $absence_status_ids = [];
        foreach ($statuses as $s) {
            if ($s->grade <= 0) { // Absences typically have 0 grade
                $absence_status_ids[] = $s->id;
            }
        }
        
        if (empty($absence_status_ids)) {
            return ['absences' => 0];
        }
        
        list($insql, $inparams) = $DB->get_in_or_equal($absence_status_ids, SQL_PARAMS_NAMED, 'abs');
        $sql = "SELECT COUNT(l.id) 
                FROM {attendance_log} l
                JOIN {attendance_sessions} s ON s.id = l.sessionid
                WHERE l.studentid = :userid
                  AND s.attendanceid = :attid
                  AND s.groupid = :groupid
                  AND l.statusid $insql";
                  
        $inparams['userid'] = $userid;
        $inparams['attid'] = $att->id;
        $inparams['groupid'] = $class->groupid;
        
        $absences = $DB->count_records_sql($sql, $inparams);
        
        return ['absences' => $absences];
    } catch (Exception $e) {
        \gmk_log("Error in gmk_get_student_attendance_summary: " . $e->getMessage());
        return ['absences' => 0];
    }
}
/**
 * Retrieves all unique tags assigned to course modules within a specific course.
 * Used for lesson/label autocomplete in activity creation.
 * 
 * @param int $courseid
 * @return array List of tag names
 */
function gmk_get_course_tags($courseid) {
    global $DB;
    
    $sql = "SELECT DISTINCT t.name
            FROM {tag} t
            JOIN {tag_instance} ti ON ti.tagid = t.id
            JOIN {course_modules} cm ON cm.id = ti.itemid
            WHERE ti.component = 'core'
              AND ti.itemtype = 'course_modules'
              AND cm.course = :courseid";
              
    $records = $DB->get_records_sql($sql, ['courseid' => $courseid]);
    
    $tags = [];
    foreach ($records as $r) {
        $tags[] = $r->name;
    }
    
    return $tags;
}

/**
 * Safely assign tags to a course module, avoiding duplicate key errors.
 *
 * Moodle's core_tag_tag::set_item_tags calls create_if_missing → INSERT INTO mdl_tag,
 * which throws dml_write_exception when the tag already exists (duplicate key on tagcollid+name).
 * This wrapper pre-resolves each tag name to its existing rawname in the DB before calling
 * set_item_tags, so no INSERT is attempted for already-existing tags.
 *
 * @param int    $cmid     Course module id
 * @param object $context  context_module instance for $cmid
 * @param array  $tagnames Array of tag raw names (strings)
 */
function gmk_safe_set_item_tags(int $cmid, $context, array $tagnames) {
    global $DB;

    // Get the tagcollid used for core/course_modules (unique key on mdl_tag is tagcollid+name)
    $tagcollid = $DB->get_field_sql(
        "SELECT tc.id FROM {tag_coll} tc
         JOIN {tag_area} ta ON ta.tagcollid = tc.id
         WHERE ta.component = 'core' AND ta.itemtype = 'course_modules'
         LIMIT 1"
    );

    // Remove any existing tag_instance rows for this cm first (clean slate)
    $DB->delete_records('tag_instance', [
        'component' => 'core',
        'itemtype'  => 'course_modules',
        'itemid'    => $cmid,
        'contextid' => $context->id,
    ]);

    $now = time();
    foreach ($tagnames as $raw) {
        $raw = trim($raw);
        if ($raw === '') continue;
        $normalized = core_text::strtolower($raw);

        // Find or create the tag record safely
        $tag = null;
        if ($tagcollid) {
            $tag = $DB->get_record('tag', ['tagcollid' => $tagcollid, 'name' => $normalized], 'id', IGNORE_MISSING);
        }
        if (!$tag) {
            $tag = $DB->get_record('tag', ['name' => $normalized], 'id', IGNORE_MISSING);
        }

        if (!$tag) {
            // Tag doesn't exist at all — insert it safely
            $newrec = new stdClass();
            $newrec->isstandard   = 0;
            $newrec->userid       = 0;
            $newrec->timemodified = $now;
            $newrec->tagcollid    = $tagcollid ?: 1;
            $newrec->rawname      = $raw;
            $newrec->name         = $normalized;
            try {
                $tagid = $DB->insert_record('tag', $newrec);
            } catch (dml_write_exception $e) {
                // Race condition: another request inserted it just now — fetch it
                $tag = $DB->get_record('tag', ['tagcollid' => $newrec->tagcollid, 'name' => $normalized], 'id', IGNORE_MISSING);
                $tagid = $tag ? $tag->id : null;
            }
        } else {
            $tagid = $tag->id;
        }

        if (!$tagid) continue;

        // Insert tag_instance
        $ti = new stdClass();
        $ti->tagid        = $tagid;
        $ti->component    = 'core';
        $ti->itemtype     = 'course_modules';
        $ti->itemid       = $cmid;
        $ti->contextid    = $context->id;
        $ti->ordering     = 0;
        $ti->timecreated  = $now;
        $ti->timemodified = $now;
        $DB->insert_record('tag_instance', $ti);
    }
}

/**
 * Returns the Moodle file storage component and filearea for a given module name.
 * Used to store teacher-attached files on the activity intro/description.
 *
 * @param string $modname  e.g. 'resource', 'assign', 'quiz', 'forum'
 * @return array|null  ['component' => '...', 'filearea' => '...', 'itemid' => 0] or null if unsupported
 */
function gmk_get_module_fileinfo(string $modname): ?array {
    $map = [
        'resource' => ['component' => 'mod_resource', 'filearea' => 'content',           'itemid' => 0],
        'assign'   => ['component' => 'mod_assign',   'filearea' => 'introattachment',    'itemid' => 0],
        'quiz'     => ['component' => 'mod_quiz',     'filearea' => 'introattachment',    'itemid' => 0],
        'forum'    => ['component' => 'mod_forum',    'filearea' => 'attachment',         'itemid' => 0],
    ];
    return $map[$modname] ?? null;
}
