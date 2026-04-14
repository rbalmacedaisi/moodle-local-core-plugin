<?php
namespace local_grupomakro_core\external\teacher;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use stdClass;

class save_grade extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters(
            array(
                'assignmentid' => new external_value(PARAM_INT, 'The ID of the assignment', VALUE_REQUIRED),
                'studentid' => new external_value(PARAM_INT, 'The ID of the student', VALUE_REQUIRED),
                'grade' => new external_value(PARAM_FLOAT, 'The grade to save', VALUE_REQUIRED),
                'feedback' => new external_value(PARAM_RAW, 'Feedback comments', VALUE_DEFAULT, '')
            )
        );
    }

    public static function execute($assignmentid, $studentid, $grade, $feedback = '') {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), array(
            'assignmentid' => $assignmentid,
            'studentid' => $studentid,
            'grade' => $grade,
            'feedback' => $feedback
        ));
        
        $context = \context_system::instance(); // Or module context
        // self::validate_context($context); // Ideally validate module context

        // 1. Load the assignment
        $cm = get_coursemodule_from_instance('assign', $params['assignmentid']);
        if (!$cm) {
            throw new \moodle_exception('invalidcoursemodule');
        }
        $context_module = \context_module::instance($cm->id);
        self::validate_context($context_module);
        
        require_capability('mod/assign:grade', $context_module);

        $assignment_record = $DB->get_record('assign', array('id' => $params['assignmentid']), '*', MUST_EXIST);
        $course = $DB->get_record('course', array('id' => $assignment_record->course), '*', MUST_EXIST);

        $assign = new \assign($context_module, $cm, $course);

        // 2. Prepare grade data with all required fields.
        $data = new stdClass();
        $data->grade            = $params['grade'];
        $data->attemptnumber    = -1;   // -1 = current (latest) attempt
        $data->applytoall       = 0;    // not a team/group submission
        $data->addattempt       = 0;    // do not add a new attempt
        $data->sendstudentnotifications = false;

        // If the assignment uses marking workflow, set state to 'released' so the
        // grade becomes immediately visible to the student.
        if (!empty($assignment_record->markingworkflow)) {
            $data->workflowstate = ASSIGN_MARKING_WORKFLOW_STATE_RELEASED;
        } else {
            $data->workflowstate = '';
        }

        // Feedback comments plugin data.
        $data->assignfeedbackcomments_editor = [
            'text'   => $params['feedback'],
            'format' => FORMAT_HTML,
        ];

        // 3. Save via Moodle assign API.
        $assign->save_grade($params['studentid'], $data);

        // 4. Explicitly push the grade to the Moodle gradebook.
        // save_grade() calls update_grade() internally, but in some AJAX / workflow
        // scenarios the gradebook entry is not flushed.  Calling assign_update_grades()
        // here guarantees the grade_grades table is updated and the student sees it.
        require_once($CFG->dirroot . '/mod/assign/lib.php');
        assign_update_grades($assignment_record, $params['studentid']);

        return array(
            'status'  => 'success',
            'message' => 'Calificación guardada correctamente',
        );
    }

    public static function execute_returns() {
        return new external_single_structure(
            array(
                'status' => new external_value(PARAM_TEXT, 'Status: success or error'),
                'message' => new external_value(PARAM_TEXT, 'Result message')
            )
        );
    }
}
