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

    private static function log_error($message, $context = []) {
        $logfile = '/var/www/html/moodle/local/grupomakro_core/save_grade_error.log';
        $timestamp = date('Y-m-d H:i:s');
        $context_str = !empty($context) ? json_encode($context) : '';
        $log_entry = "[{$timestamp}] {$message} {$context_str}\n";
        @file_put_contents($logfile, $log_entry, FILE_APPEND);
    }

    public static function execute($assignmentid, $studentid, $grade, $feedback = '') {
        global $DB, $USER;

        self::log_error("INCOMING REQUEST", [
            'assignmentid' => $assignmentid,
            'studentid' => $studentid,
            'grade' => $grade,
            'feedback_length' => strlen($feedback ?? '')
        ]);

        try {
            $params = self::validate_parameters(self::execute_parameters(), array(
                'assignmentid' => $assignmentid,
                'studentid' => $studentid,
                'grade' => $grade,
                'feedback' => $feedback ?? ''
            ));
            
            self::log_error("params validated", $params);

            $context = \context_system::instance();
            $cm = get_coursemodule_from_instance('assign', $params['assignmentid']);
            if (!$cm) {
                throw new \moodle_exception('invalidcoursemodule');
            }
            
            self::log_error("cm loaded", ['cm_id' => $cm->id, 'cm_instance' => $cm->instance]);

            $context_module = \context_module::instance($cm->id);
            self::validate_context($context_module);
            
            require_capability('mod/assign:grade', $context_module);

            $assignment_record = $DB->get_record('assign', array('id' => $params['assignmentid']), '*', MUST_EXIST);
            $course = $DB->get_record('course', array('id' => $assignment_record->course), '*', MUST_EXIST);

            self::log_error("assignment loaded", [
                'assignment_id' => $assignment_record->id,
                'course' => $assignment_record->course,
                'markingworkflow' => $assignment_record->markingworkflow ?? 'none'
            ]);

            $assign = new \assign($context_module, $cm, $course);

            $data = new stdClass();
            $data->grade            = $params['grade'];
            $data->attemptnumber    = -1;
            $data->applytoall       = 0;
            $data->addattempt       = 0;
            $data->sendstudentnotifications = false;

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

            self::log_error("about to call save_grade", [
                'studentid' => $params['studentid'],
                'grade' => $params['grade']
            ]);

            // 3. Save via Moodle assign API.
            $assign->save_grade($params['studentid'], $data);

            self::log_error("save_grade completed successfully");

            // 4. Explicitly push the grade to the Moodle gradebook.
            require_once($CFG->dirroot . '/mod/assign/lib.php');
            assign_update_grades($assignment_record, $params['studentid']);

            self::log_error("assign_update_grades completed");

            // 5. Verify the feedback was saved and sync if needed.
            // The assign API saves feedback to assignfeedback_comments plugin table.
            // We need to ensure grade_grades.feedback is also synced for the gradebook UI.
            if ($params['feedback'] !== '') {
                // Get the gradeitem for this assignment
                $gradeitem = $DB->get_record_sql(
                    "SELECT id FROM {grade_items}
                      WHERE itemtype = 'mod' AND itemmodule = 'assign'
                        AND iteminstance = :assignid AND courseid = :courseid",
                    ['assignid' => $params['assignmentid'], 'courseid' => $assignment_record->course]
                );
                
                self::log_error("gradeitem query result", ['found' => !empty($gradeitem), 'gradeitem_id' => $gradeitem->id ?? null]);
                
                if ($gradeitem) {
                    // Check current feedback in grade_grades
                    $current = $DB->get_record('grade_grades', [
                        'itemid' => $gradeitem->id,
                        'userid' => $params['studentid']
                    ]);
                    
                    self::log_error("current grade_grades record", [
                        'exists' => !empty($current),
                        'current_feedback_length' => $current ? strlen($current->feedback ?? '') : 0
                    ]);
                    
                    // Update grade_grades with the feedback for UI display
                    if ($current) {
                        $DB->set_field('grade_grades', 'feedback', $params['feedback'], ['id' => $current->id]);
                        $DB->set_field('grade_grades', 'feedbackformat', FORMAT_HTML, ['id' => $current->id]);
                        self::log_error("grade_grades feedback updated via set_field");
                    }
                }
            }

            self::log_error("SUCCESS - All operations completed");

            return array(
                'status'  => 'success',
                'message' => 'Calificación guardada correctamente',
            );

        } catch (\Exception $e) {
            self::log_error("EXCEPTION CAUGHT", [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            throw $e;
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