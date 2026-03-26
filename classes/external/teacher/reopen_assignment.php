<?php
namespace local_grupomakro_core\external\teacher;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;

class reopen_assignment extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'assignmentid' => new external_value(PARAM_INT, 'Assignment instance ID', VALUE_REQUIRED),
            'studentid'    => new external_value(PARAM_INT, 'Student user ID', VALUE_REQUIRED),
        ]);
    }

    public static function execute(int $assignmentid, int $studentid): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'assignmentid' => $assignmentid,
            'studentid'    => $studentid,
        ]);

        $assignmentid = (int)$params['assignmentid'];
        $studentid    = (int)$params['studentid'];

        $cm = get_coursemodule_from_instance('assign', $assignmentid);
        if (!$cm) {
            return ['status' => 'error', 'message' => 'Actividad no encontrada.'];
        }

        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/assign:grade', $context);

        $assignrecord = $DB->get_record('assign', ['id' => $assignmentid], '*', MUST_EXIST);
        $course       = $DB->get_record('course', ['id' => $assignrecord->course], '*', MUST_EXIST);
        $assign       = new \assign($context, $cm, $course);

        // Reopen: change the existing submission status back to 'new' so the
        // student can upload and submit again without losing their previous files.
        $submission = $assign->get_user_submission($studentid, false);
        if ($submission) {
            $submission->status       = ASSIGN_SUBMISSION_STATUS_NEW;
            $submission->timemodified = time();
            $DB->update_record('assign_submission', $submission);
        }

        // Also set an extension due date (30 days from now) so that submissions
        // remain open even if the original deadline has already passed.
        $flags = $assign->get_user_flags($studentid, true); // creates row if missing
        $flags->extensionduedate = time() + (30 * DAYSECS);
        if (isset($flags->id) && $flags->id > 0) {
            $DB->update_record('assign_user_flags', $flags);
        } else {
            $flags->assignment = $assignmentid;
            $flags->userid     = $studentid;
            $DB->insert_record('assign_user_flags', $flags);
        }

        return [
            'status'  => 'success',
            'message' => 'Entrega reabierta. El estudiante puede enviar de nuevo durante los próximos 30 días.',
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status'  => new external_value(PARAM_TEXT, 'success o error'),
            'message' => new external_value(PARAM_TEXT, 'Mensaje de resultado'),
        ]);
    }
}
