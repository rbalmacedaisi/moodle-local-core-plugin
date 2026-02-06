<?php
namespace local_grupomakro_core\external\teacher;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;
use stdClass;

class get_quiz_attempt_data extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters(
            array(
                'attemptid' => new external_value(PARAM_INT, 'The ID of the quiz attempt', VALUE_REQUIRED)
            )
        );
    }

    public static function execute($attemptid) {
        global $DB, $PAGE, $OUTPUT, $CFG;

        try {
            // Ensure question engine is loaded
            require_once($CFG->dirroot . '/question/engine/lib.php');
            require_once($CFG->dirroot . '/mod/quiz/locallib.php');

            $params = self::validate_parameters(self::execute_parameters(), array(
                'attemptid' => $attemptid
            ));
            
            $attemptobj = \quiz_attempt::create($params['attemptid']);
            $context = \context_module::instance($attemptobj->get_cm()->id);
            self::validate_context($context);
            
            require_capability('mod/quiz:grade', $context);

            // Initialize PAGE/OUTPUT if not done (for AJAX)
            if (!$PAGE->headerprinted) {
                $PAGE->set_context($context);
                $PAGE->set_url('/local/grupomakro_core/ajax.php');
            }

            $attempt = $attemptobj->get_attempt();
            $user = $DB->get_record('user', array('id' => $attempt->userid), '*', MUST_EXIST);
            
            $result = new stdClass();
            $result->attemptid = (int)$attempt->id;
            $result->userid = (int)$attempt->userid;
            $result->username = fullname($user);
            $result->quizname = $attemptobj->get_quiz_name();
            $result->timestart = (int)$attempt->timestart;
            $result->timefinish = (int)$attempt->timefinish;
            
            $result->questions = [];
            
            // Load all questions for the attempt
            $attemptobj->preload_all_attempt_step_users();
            
            foreach ($attemptobj->get_slots() as $slot) {
                $qa = $attemptobj->get_question_attempt($slot);
                $question = $qa->get_question();
                $state = $qa->get_state();
                
                $qitem = new stdClass();
                $qitem->slot = (int)$slot;
                $qitem->questionid = (int)$question->id;
                $qitem->name = $question->name;
                $qitem->maxgrade = (float)$qa->get_max_mark();
                $qitem->currentgrade = $qa->get_mark() !== null ? (float)$qa->get_mark() : 0.0;
                $qitem->state = (string)$state;
                $qitem->needsgrading = $state->is_finished() && !$state->is_graded();
                
                // Render question HTML
                // We wrap it in a try-catch because question rendering can be fragile
                try {
                    $quizrenderer = $PAGE->get_renderer('mod_quiz');
                    $qitem->html = $attemptobj->render_question($slot, false, $quizrenderer);
                } catch (\Exception $renex) {
                    $qitem->html = "<p class='error'>Error rendering question: " . $renex->getMessage() . "</p>";
                }
                
                $result->questions[] = $qitem;
            }

            return $result;

        } catch (\Exception $e) {
            // Log for debugging
            error_log("[GMK] Error in get_quiz_attempt_data: " . $e->getMessage());
            throw $e;
        }
    }

    public static function execute_returns() {
        return new external_single_structure(
            array(
                'attemptid' => new external_value(PARAM_INT, 'Attempt ID'),
                'userid' => new external_value(PARAM_INT, 'User ID'),
                'username' => new external_value(PARAM_TEXT, 'User Fullname'),
                'quizname' => new external_value(PARAM_TEXT, 'Quiz Name'),
                'timestart' => new external_value(PARAM_INT, 'Start Timestamp'),
                'timefinish' => new external_value(PARAM_INT, 'Finish Timestamp'),
                'questions' => new external_multiple_structure(
                    new external_single_structure(
                        array(
                            'slot' => new external_value(PARAM_INT, 'Slot ID'),
                            'questionid' => new external_value(PARAM_INT, 'Question ID'),
                            'name' => new external_value(PARAM_TEXT, 'Question Name'),
                            'maxgrade' => new external_value(PARAM_FLOAT, 'Max Grade'),
                            'currentgrade' => new external_value(PARAM_FLOAT, 'Current Grade', VALUE_OPTIONAL),
                            'state' => new external_value(PARAM_TEXT, 'State'),
                            'needsgrading' => new external_value(PARAM_BOOL, 'Needs Grading?'),
                            'html' => new external_value(PARAM_RAW, 'Rendered Question HTML')
                        )
                    )
                )
            )
        );
    }
}
