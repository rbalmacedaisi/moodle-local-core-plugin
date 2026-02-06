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
        global $DB, $USER, $CFG, $PAGE;

        try {
            // Ensure question engine is loaded
            require_once($CFG->dirroot . '/question/engine/lib.php');
            require_once($CFG->dirroot . '/mod/quiz/locallib.php');

            $params = self::validate_parameters(self::execute_parameters(), array(
                'attemptid' => $attemptid,
                'slot' => $slot,
                'mark' => $mark,
                'comment' => $comment
            ));
            
            $attemptobj = \quiz_attempt::create($params['attemptid']);
            $context = \context_module::instance($attemptobj->get_cm()->id);
            self::validate_context($context);
            
            require_capability('mod/quiz:grade', $context);

            // Initialize PAGE if needed
            if (!$PAGE->headerprinted) {
                $PAGE->set_context($context);
            }

            // Prepare the action data for the question engine
            // Moodle expects data in a specific format for manual grading
            $qa = $attemptobj->get_question_attempt($slot);
            $prefix = $qa->get_field_prefix();
            
            $data = array(
                $prefix . '-mark' => $params['mark'],
                $prefix . '-comment' => $params['comment'],
                $prefix . '-commentformat' => FORMAT_HTML
            );

            // Process the action
            $attemptobj->process_all_actions(time(), $data);
            
            return array(
                'status' => 'success',
                'message' => 'Calificación guardada correctamente'
            );

        } catch (\Exception $e) {
            error_log("[GMK] Error saving quiz grading: " . $e->getMessage());
            return array(
                'status' => 'error',
                'message' => $e->getMessage()
            );
        }
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
