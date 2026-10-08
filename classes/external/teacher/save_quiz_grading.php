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

            // Perform manual grading via the question engine API.
            $quba = \question_engine::load_questions_usage_by_activity($attemptobj->get_uniqueid());
            $qa = $quba->get_question_attempt($slot);

            // Parameters: comment, mark, commentformat, timestamp, userid
            $qa->manual_grade($params['comment'], (float)$params['mark'], FORMAT_HTML, time(), $USER->id);

            // Persist the changes to the question engine database tables.
            \question_engine::save_questions_usage_by_activity($quba, $DB);

            // Recalculate sumgrades from the freshly-persisted quba so we get the
            // updated total (the attemptobj was loaded before the grade was saved
            // and may hold a stale in-memory quba).
            $fresh_quba = \question_engine::load_questions_usage_by_activity($attemptobj->get_uniqueid());
            $newsum = 0;
            foreach ($attemptobj->get_slots() as $s) {
                $mark = $fresh_quba->get_question_attempt($s)->get_mark();
                $newsum += ($mark !== null) ? (float)$mark : 0.0;
            }
            $DB->set_field('quiz_attempts', 'sumgrades', $newsum, ['id' => $params['attemptid']]);

            // Push the updated grade to the Moodle gradebook.
            // Pass no $attempts so quiz_save_best_grade fetches fresh records from DB
            // (avoids using the stale attempt object that still has old sumgrades).
            quiz_save_best_grade($attemptobj->get_quiz(), $attemptobj->get_userid());

            // Write comment to grade_grades.feedback so the student gradebook shows it.
            // quiz_save_best_grade updates finalgrade but does not propagate per-question
            // manual comments to grade_grades.feedback, so we set it explicitly.
            if ($params['comment'] !== '') {
                $quiz = $attemptobj->get_quiz();
                $gradeitem = $DB->get_record_sql(
                    "SELECT id FROM {grade_items}
                      WHERE itemtype = 'mod' AND itemmodule = 'quiz'
                        AND iteminstance = :quizid AND courseid = :courseid",
                    ['quizid' => $quiz->id, 'courseid' => $quiz->course]
                );
                if ($gradeitem) {
                    $DB->execute(
                        "UPDATE {grade_grades}
                            SET feedback = :fb, feedbackformat = :fmt
                          WHERE itemid = :itemid AND userid = :userid",
                        ['fb' => $params['comment'], 'fmt' => FORMAT_HTML,
                         'itemid' => $gradeitem->id, 'userid' => $attemptobj->get_userid()]
                    );
                }
            }

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
