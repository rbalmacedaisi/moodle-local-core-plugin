<?php
namespace local_grupomakro_core\external\teacher;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use stdClass;

class save_quiz_grading extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters(
            array(
                'attemptid' => new external_value(PARAM_INT, 'The ID of the quiz attempt', VALUE_REQUIRED),
                'slot' => new external_value(PARAM_INT, 'The slot number of the question', VALUE_REQUIRED),
                'mark' => new external_value(PARAM_FLOAT, 'The mark to give', VALUE_REQUIRED),
                'comment' => new external_value(PARAM_RAW, 'Feedback comment', VALUE_DEFAULT, '')
            )
        );
    }

    public static function execute($attemptid, $slot, $mark, $comment = '') {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), array(
            'attemptid' => $attemptid,
            'slot' => $slot,
            'mark' => $mark,
            'comment' => $comment
        ));
        
        $attemptobj = \quiz_attempt::create($params['attemptid']);
        $context = $attemptobj->get_context();
        self::validate_context($context);
        
        require_capability('mod/quiz:grade', $context);

        // Prepare the action data for the question engine
        // Moodle expects data in a specific format for manual grading
        $prefix = $attemptobj->get_question_usage()->get_question_attempt($slot)->get_field_prefix();
        
        $data = array(
            $prefix . '-mark' => $params['mark'],
            $prefix . '-comment' => $params['comment'],
            $prefix . '-commentformat' => FORMAT_HTML
        );

        // Process the action
        $attemptobj->process_all_actions(time(), $data);
        
        // After processing, if the attempt is finished, we might need to trigger graduation/regrading?
        // Usually, process_all_actions handles the necessary updates to the attempt's sumgrades.
        
        return array(
            'status' => 'success',
            'message' => 'Calificación guardada correctamente'
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
