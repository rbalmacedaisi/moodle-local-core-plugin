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

        // 2. Prepare grade data
        $data = new stdClass();
        $data->grade = $params['grade'];
        $data->attemptnumber = -1; // Current attempt
             
        // Plugin specific data (feedback comments)
        // Moodle assign feedback plugins usually look for 'assignfeedbackcomments_editor'
        // But for a simple backend API without an editor, we might need to manually construct logic or use `save_grade` carefully.
        
        // The core `save_grade` function takes $data where keys correspond to plugin data
        // For 'comments' feedback plugin:
        $data->assignfeedbackcomments_editor = [
            'text' => $params['feedback'],
            'format' => FORMAT_HTML
        ];

        // 3. Save
        // apply_grade_to_user($data, $userid, $attemptnumber)
        $result = $assign->save_grade($params['studentid'], $data);
        
        return array(
            'status' => $result ? 'success' : 'error',
            'message' => $result ? 'Calificación guardada correctamente' : 'Error al guardar calificación'
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
