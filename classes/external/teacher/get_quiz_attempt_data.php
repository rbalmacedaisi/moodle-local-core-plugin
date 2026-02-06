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
        global $DB, $PAGE, $OUTPUT;

        $params = self::validate_parameters(self::execute_parameters(), array(
            'attemptid' => $attemptid
        ));
        
        $attemptobj = \quiz_attempt::create($params['attemptid']);
        $context = $attemptobj->get_context();
        self::validate_context($context);
        
        require_capability('mod/quiz:grade', $context);

        $attempt = $attemptobj->get_attempt();
        $user = $DB->get_record('user', array('id' => $attempt->userid), '*', MUST_EXIST);
        
        $result = new stdClass();
        $result->attemptid = $attempt->id;
        $result->userid = $attempt->userid;
        $result->username = fullname($user);
        $result->quizname = $attemptobj->get_quiz_name();
        $result->timestart = $attempt->timestart;
        $result->timefinish = $attempt->timefinish;
        
        $result->questions = [];
        
        // Load all questions for the attempt
        $attemptobj->preload_all_attempt_step_users();
        
        foreach ($attemptobj->get_slots() as $slot) {
            $qa = $attemptobj->get_question_attempt($slot);
            
            // Only include questions that are manually gradeable or specifically need grading
            // But for a review-like UI, we might want all finished ones.
            // For now, let's focus on those that NEED grading or are already graded.
            
            $question = $qa->get_question();
            $state = $qa->get_state();
            
            $qitem = new stdClass();
            $qitem->slot = $slot;
            $qitem->questionid = $question->id;
            $qitem->name = $question->name;
            $qitem->maxgrade = $qa->get_max_mark();
            $qitem->currentgrade = $qa->get_mark();
            $qitem->state = (string)$state;
            $qitem->needsgrading = $state->is_finished() && !$state->is_graded();
            
            // Question text (rendered)
            $options = new \question_display_options();
            $options->hide_all_hints();
            $options->flags = \question_display_options::HIDDEN;
            $options->marks = \question_display_options::MARK_AND_MAX;
            
            // We want to show the student's response clearly
            $qitem->html = $attemptobj->render_question($slot, false); // This might return a huge HTML
            
            // Extract just the response for simplicity in a custom UI? 
            // Or use the full rendered question which Moodle provides.
            // Using full rendered question is safer for complex types.
            
            $result->questions[] = $qitem;
        }

        return $result;
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
