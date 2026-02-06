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

            // Perform manual grading.
            // We use the question_engine API directly because quiz_attempt->get_question_usage()
            // is restricted to unit tests in this Moodle version.
            $quba = \question_engine::load_questions_usage_by_activity($attemptobj->get_uniqueid());
            $qa = $quba->get_question_attempt($slot);
            
            // Parameters: comment, mark, commentformat, timestamp, userid
            $qa->manual_grade($params['comment'], (float)$params['mark'], FORMAT_HTML, time(), $USER->id);
            
            // Persist the changes to the question engine database tables.
            \question_engine::save_questions_usage_by_activity($quba, $DB);

            // Recalculate and update the attempt summarks (points).
            // This ensures the dashboard and gradebook show the updated total.
            $newsum = $attemptobj->get_sum_marks();
            $DB->set_field('quiz_attempts', 'sumgrades', $newsum, array('id' => $params['attemptid']));
            
            // Also trigger a re-assessment of the overall quiz grade for the student.
            // Documentation shows 3 params: quiz, userid, attempts.
            quiz_save_best_grade($attemptobj->get_quiz(), $attemptobj->get_userid(), (array)$attemptobj->get_attempt());
            
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
