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
 * Class definition for the local_grupomakro_get_course_absences_detail external function.
 *
 * Returns, for a (user, course) pair, the per-class breakdown of absences plus
 * the canonical alert level/blocking state. Used by the academic panel grades
 * modal so a director can click on the absence counter of a course and see the
 * exact list of absent sessions.
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Solutto Consulting <dev@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\student;

use context_system;
use external_api;
use external_description;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/pages/absence_helpers.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

/**
 * External function 'local_grupomakro_get_course_absences_detail' implementation.
 *
 * @package     local_grupomakro_core
 * @category    external
 * @copyright   2026 Solutto Consulting <dev@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_course_absences_detail extends external_api
{

    /**
     * Describes parameters of the {@see self::execute()} method.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters
    {
        return new external_function_parameters([
            'userId'       => new external_value(PARAM_INT, 'Student user id.',  VALUE_REQUIRED),
            'coreCourseId' => new external_value(PARAM_INT, 'Moodle course id.', VALUE_REQUIRED),
        ]);
    }

    /**
     * Compute the per-class absence breakdown for a (student, course) pair.
     *
     * @param int $userId
     * @param int $coreCourseId
     * @return array
     */
    public static function execute(int $userId, int $coreCourseId): array
    {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'userId'       => $userId,
            'coreCourseId' => $coreCourseId,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        $userId       = (int)$params['userId'];
        $coreCourseId = (int)$params['coreCourseId'];
        $nowts        = time();
        $threshold    = absd_get_block_threshold();

        $classOut = [];
        $totalAbsences = 0;

        // Resolve every class this student has been associated with for the
        // given course (both legacy "courseid" and modern "corecourseid" are
        // accepted by gmk_class — check both).
        $classes = $DB->get_records_sql(
            "SELECT DISTINCT c.id, c.name, c.corecourseid, c.courseid, c.groupid,
                    c.periodid, c.learningplanid, c.attendancemoduleid, c.is_module,
                    c.initdate, c.enddate,
                    CONCAT(COALESCE(u.firstname, ''), ' ', COALESCE(u.lastname, '')) AS instructorname
               FROM {gmk_class} c
          LEFT JOIN {user} u ON u.id = c.instructorid
              WHERE (c.corecourseid = :ccid OR c.courseid = :ccid2)
                AND c.gradecategoryid > 0
           ORDER BY c.is_module ASC, c.initdate DESC, c.id DESC",
            ['ccid' => $coreCourseId, 'ccid2' => $coreCourseId]
        );

        foreach ($classes as $class) {
            // Only include classes where the student actually shows up in the
            // progre (or in the legacy membership for this class).
            $isMember = $DB->record_exists('gmk_course_progre', [
                'userid' => $userId,
                'classid' => (int)$class->id,
            ]);
            if (!$isMember) {
                $isMember = !empty($class->groupid)
                    && groups_is_member((int)$class->groupid, $userId);
            }
            if (!$isMember) {
                continue;
            }

            $sessionIds = absd_get_class_past_session_ids($class, $nowts);
            $takenIds   = absd_get_taken_session_ids($sessionIds);

            // Per-session attendance status for this student.
            $absentSessions = [];
            $presentCount   = 0;
            $noLogCount     = 0;

            if (!empty($takenIds)) {
                list($sessin, $sessparams) = $DB->get_in_or_equal($takenIds, SQL_PARAMS_NAMED, 'csess');
                $sessionRows = $DB->get_records_sql(
                    "SELECT s.id, s.sessdate, s.duration, s.description
                       FROM {attendance_sessions} s
                      WHERE s.id $sessin
                   ORDER BY s.sessdate ASC",
                    $sessparams
                );
                $logparams = array_merge($sessparams, ['uid' => $userId]);
                $logRows = $DB->get_records_sql(
                    "SELECT l.sessionid, l.statusid, l.remarks, l.timetaken,
                            COALESCE(ast.acronym, '') AS acronym,
                            COALESCE(ast.description, '') AS statusdesc,
                            COALESCE(ast.grade, 0) AS grade
                       FROM {attendance_log} l
                       JOIN (
                            SELECT sessionid, MAX(id) AS maxid
                              FROM {attendance_log}
                             WHERE studentid = :uid
                               AND sessionid $sessin
                          GROUP BY sessionid
                       ) mx ON mx.maxid = l.id
                  LEFT JOIN {attendance_statuses} ast ON ast.id = l.statusid",
                    $logparams
                );

                foreach ($sessionRows as $session) {
                    $sid  = (int)$session->id;
                    $log  = $logRows[$sid] ?? null;
                    $hasLog = $log !== null;
                    $grade = $hasLog ? (float)$log->grade : null;
                    $present = $hasLog && $grade > 0;

                    if ($present) {
                        $presentCount++;
                        continue;
                    }
                    if (!$hasLog) {
                        $noLogCount++;
                    }

                    $absentSessions[] = [
                        'sessionid'   => $sid,
                        'date'        => userdate((int)$session->sessdate, get_string('strftimedatefullshort', 'langconfig')),
                        'time'        => userdate((int)$session->sessdate, '%H:%M'),
                        'description' => trim((string)($session->description ?? '')),
                        'status'      => $hasLog ? trim((string)$log->statusdesc) : 'Sin registro',
                        'acronym'     => $hasLog ? trim((string)$log->acronym) : '',
                        'has_log'     => $hasLog,
                        'remarks'     => $hasLog ? trim((string)$log->remarks) : '',
                    ];
                }
            }

            $absentCount = count($absentSessions);
            $totalAbsences += $absentCount;

            // Period label.
            $periodName = '';
            if (!empty($class->periodid)) {
                $periodName = (string)$DB->get_field(
                    'local_learning_periods', 'name', ['id' => (int)$class->periodid]
                );
            }

            // Alert state for this (user, class) pair.
            $stateRow = $DB->get_record('gmk_class_absence_state', [
                'userid'  => $userId,
                'classid' => (int)$class->id,
            ]);
            $blocked = false;
            if ($stateRow && absd_is_blocking_enabled()) {
                $blocked = !empty($stateRow->blocked_at) && empty($stateRow->unblocked_at);
            }

            $classOut[] = [
                'classid'         => (int)$class->id,
                'name'            => (string)$class->name,
                'periodid'        => (int)($class->periodid ?? 0),
                'periodname'      => $periodName !== '' ? $periodName : 'Sin período',
                'instructorname'  => trim((string)($class->instructorname ?? '')),
                'is_module'       => !empty($class->is_module) ? 1 : 0,
                'initdate'        => (int)($class->initdate ?? 0),
                'enddate'         => (int)($class->enddate ?? 0),
                'taken_sessions'  => count($takenIds),
                'present_count'   => $presentCount,
                'absent_count'    => $absentCount,
                'absent_sessions' => $absentSessions,
                'alert_level'     => $stateRow ? (int)$stateRow->alert_level : 0,
                'blocked'         => $blocked,
            ];
        }

        return [
            'user_id'        => $userId,
            'core_course_id' => $coreCourseId,
            'threshold'      => $threshold,
            'total_absences' => $totalAbsences,
            'classes'        => $classOut,
        ];
    }

    /**
     * Describes the return value of the {@see self::execute()} method.
     *
     * @return external_description
     */
    public static function execute_returns(): external_description
    {
        $absentSessionStructure = new external_single_structure([
            'sessionid'   => new external_value(PARAM_INT,  'Attendance session id'),
            'date'        => new external_value(PARAM_TEXT, 'Session date (localised)'),
            'time'        => new external_value(PARAM_TEXT, 'Session start time (HH:MM)'),
            'description' => new external_value(PARAM_TEXT, 'Session description / topic'),
            'status'      => new external_value(PARAM_TEXT, 'Status description (Sin registro / Ausente / Tarde...)'),
            'acronym'     => new external_value(PARAM_TEXT, 'Status acronym (A / T / P...)'),
            'has_log'     => new external_value(PARAM_BOOL, 'Whether an attendance_log row exists'),
            'remarks'     => new external_value(PARAM_TEXT, 'Free-text remarks captured by the teacher'),
        ]);

        $classStructure = new external_single_structure([
            'classid'         => new external_value(PARAM_INT,  'gmk_class.id'),
            'name'            => new external_value(PARAM_TEXT, 'Class display name'),
            'periodid'        => new external_value(PARAM_INT,  'local_learning_periods.id'),
            'periodname'      => new external_value(PARAM_TEXT, 'Period display name'),
            'instructorname'  => new external_value(PARAM_TEXT, 'Instructor full name'),
            'is_module'       => new external_value(PARAM_INT,  '1 = independent module class, 0 = regular class'),
            'initdate'        => new external_value(PARAM_INT,  'Class start date (Unix timestamp, 0 = unknown)'),
            'enddate'         => new external_value(PARAM_INT,  'Class end date (Unix timestamp, 0 = open)'),
            'taken_sessions'  => new external_value(PARAM_INT,  'Total sessions taken into account'),
            'present_count'   => new external_value(PARAM_INT,  'Sessions where the student was present'),
            'absent_count'    => new external_value(PARAM_INT,  'Sessions where the student was absent or unmarked'),
            'absent_sessions' => new external_multiple_structure($absentSessionStructure, 'List of absent/unmarked sessions'),
            'alert_level'     => new external_value(PARAM_INT,  '0=none, 1=info, 2=warning, 3=blocked'),
            'blocked'         => new external_value(PARAM_BOOL, 'Whether the class is currently access-blocked'),
        ]);

        return new external_single_structure([
            'user_id'        => new external_value(PARAM_INT,  'Echo of userId'),
            'core_course_id' => new external_value(PARAM_INT,  'Echo of coreCourseId'),
            'threshold'      => new external_value(PARAM_INT,  'Block threshold (default 3)'),
            'total_absences' => new external_value(PARAM_INT,  'Sum of absent_count across all classes for the course'),
            'classes'        => new external_multiple_structure($classStructure, 'Per-class breakdown'),
        ]);
    }
}