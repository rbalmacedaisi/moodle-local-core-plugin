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
 * Class definition for the local_grupomakro_mark_bbb_attendance_by_playback external function.
 *
 * @package    local_grupomakro_core
 * @copyright  2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\activity;

use context_module;
use external_api;
use external_description;
use external_function_parameters;
use external_single_structure;
use external_value;
use local_grupomakro_progress_manager;
use mod_bigbluebuttonbn\recording;
use Throwable;

defined('MOODLE_INTERNAL') || die();

require_once $CFG->dirroot. '/local/grupomakro_core/locallib.php';
require_once $CFG->dirroot. '/local/grupomakro_core/classes/local/progress_manager.php';

/**
 * External function 'local_grupomakro_mark_bbb_attendance_by_playback' implementation.
 *
 * Marks the attendance session linked to a BigBlueButton activity once the student
 * has watched enough of its recording.
 *
 * @package     local_grupomakro_core
 * @category    external
 * @copyright   2022 Solutto Consulting <devs@soluttoconsulting.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mark_bbb_attendance_by_playback extends external_api {

    /**
     * Describes parameters of the {@see self::execute()} method.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters(
            [
                'moduleId'=> new external_value(PARAM_TEXT, 'Id of the bbb module.',VALUE_REQUIRED),
                'userId'=> new external_value(PARAM_TEXT, 'Deprecated and ignored: attendance is always marked for the authenticated user.',VALUE_REQUIRED),
                'courseId'=>new external_value(PARAM_TEXT, 'Course ID',VALUE_REQUIRED)
            ]
        );
    }

    /**
     * Mark the attendance session tied to a BBB activity for the calling student.
     *
     * Historical note: this used to call completion_info::update_state() with $override = true,
     * relying on the completion_updated observer to reach the attendance layer. That path can
     * never work when the caller is a student: update_state() throws required_capability_exception
     * for anyone without 'moodle/course:overridecompletion', so the marking silently failed for
     * every student since the feature shipped. We now mark the attendance session directly.
     *
     * The $userId parameter is kept for backwards compatibility with the deployed LXP build but is
     * deliberately ignored: acting on the authenticated user only keeps a student from marking
     * attendance on somebody else's behalf.
     *
     * @param string $moduleId BBB course module id.
     * @param string $userId   Ignored, see above.
     * @param string $courseId Course id the module belongs to.
     * @return array
     */
    public static function execute($moduleId,$userId,$courseId){

        global $USER, $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'moduleId'=>$moduleId,
            'userId'=>$userId,
            'courseId'=>$courseId,
        ]);

        $moduleid = (int)$params['moduleId'];
        $courseid = (int)$params['courseId'];
        $userid   = (int)$USER->id;

        try{

            $cmcontext = context_module::instance($moduleid);
            self::validate_context($cmcontext);

            $modinfo = get_fast_modinfo($courseid, $userid);
            $cm      = $modinfo->get_cm($moduleid);

            if ($cm->modname !== 'bigbluebuttonbn') {
                return self::fail('not_a_bbb_module', 'La actividad indicada no es una sesión de BigBlueButton.');
            }
            if (!$cm->uservisible) {
                return self::fail('module_not_available', 'No tienes acceso a esta sesión.');
            }

            // The BBB activity must be tied to an attendance session.
            $relation = $DB->get_record('gmk_bbb_attendance_relation', ['bbbmoduleid' => $moduleid]);
            if (!$relation || empty($relation->attendancesessionid)) {
                return self::fail('no_attendance_relation', 'Esta sesión no tiene asistencia asociada.');
            }

            $session = $DB->get_record('attendance_sessions', ['id' => (int)$relation->attendancesessionid]);
            if (!$session) {
                return self::fail('attendance_session_missing', 'No se encontró la sesión de asistencia.');
            }

            // A session that has not started yet cannot be recovered by watching a recording.
            if ((int)$session->sessdate > time()) {
                return self::fail('session_not_started', 'La sesión aún no ha ocurrido.');
            }

            // There must be a playable recording: without one there is nothing to have watched.
            $hasrecording = $DB->record_exists_select(
                'bigbluebuttonbn_recordings',
                'bigbluebuttonbnid = :instanceid AND status IN (:status_processed, :status_notified)',
                [
                    'instanceid'       => (int)$cm->instance,
                    'status_processed' => recording::RECORDING_STATUS_PROCESSED,
                    'status_notified'  => recording::RECORDING_STATUS_NOTIFIED,
                ]
            );
            if (!$hasrecording) {
                return self::fail('no_recording', 'Esta sesión todavía no tiene grabación disponible.');
            }

            // Idempotence: don't rewrite the log (and bump lasttaken) if it already holds the
            // status this call would set.
            $targetstatus = self::resolve_target_status($relation, $session, $cm, $modinfo);
            $currentlog   = $DB->get_record('attendance_log', [
                'sessionid' => (int)$relation->attendancesessionid,
                'studentid' => $userid,
            ]);
            if ($currentlog && $targetstatus && (int)$currentlog->statusid === (int)$targetstatus) {
                return ['status' => 1, 'message' => 'already_marked'];
            }

            local_grupomakro_progress_manager::mark_bigbluebutton_related_attendance_session(
                $userid,
                $modinfo,
                $relation
            );

            return ['status' => 1, 'message' => 'ok'];

        }
        catch (Throwable $e) {
            // Surface the failure instead of swallowing it: the LXP needs to know it can retry.
            debugging('mark_bbb_attendance_by_playback failed for user ' . $userid .
                      ' on cm ' . $moduleid . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            return ['status' => -1, 'message' => $e->getMessage()];
        }
    }

    /**
     * Highest status configured for the linked attendance session, or null if it cannot be resolved.
     *
     * @param \stdClass $relation Row of {gmk_bbb_attendance_relation}.
     * @param \stdClass $session  Row of {attendance_sessions}.
     * @param \cm_info  $cm       BBB course module.
     * @param \course_modinfo $modinfo
     * @return int|null
     */
    protected static function resolve_target_status($relation, $session, $cm, $modinfo) {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/attendance/locallib.php');
        try {
            $attendance   = $DB->get_record('attendance', ['id' => (int)$relation->attendanceid]);
            $attendancecm = $modinfo->get_cm((int)$relation->attendancemoduleid)->get_course_module_record(false);
            if (!$attendance || !$attendancecm) {
                return null;
            }
            $structure = new \mod_attendance_structure($attendance, $attendancecm, $modinfo->get_course());
            return attendance_session_get_highest_status($structure, $session);
        } catch (Throwable $e) {
            // If we can't resolve it, fall through and let the marking run normally.
            return null;
        }
    }

    /**
     * Build a failed response.
     *
     * @param string $code
     * @param string $message
     * @return array
     */
    protected static function fail(string $code, string $message): array {
        return ['status' => -1, 'message' => $message . ' (' . $code . ')'];
    }

    /**
     * Describes the return value of the {@see self::execute()} method.
     *
     * @return external_description
     */
    public static function execute_returns(): external_description {
        return new external_single_structure(
            array(
                'status' => new external_value(PARAM_INT, '1 on success, -1 on failure',VALUE_DEFAULT,1),
                'message' => new external_value(PARAM_TEXT, 'The error message or Ok.',VALUE_DEFAULT,'ok'),
            )
        );
    }
}
