<?php
namespace local_grupomakro_core\external\gmkclass;

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use context_system;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

class toggle_class_status extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'classId'       => new external_value(PARAM_INT,  'The class ID'),
            'open'          => new external_value(PARAM_BOOL, 'True to open, False to close (deprecated — use local_grupomakro_close_class_period)', VALUE_REQUIRED),
            'initDate'      => new external_value(PARAM_TEXT, 'New Init Date (YYYY-MM-DD)', VALUE_DEFAULT, null),
            'endDate'       => new external_value(PARAM_TEXT, 'New End Date (YYYY-MM-DD)', VALUE_DEFAULT, null),
            'revert_states' => new external_value(PARAM_BOOL, 'When reopening: also revert student statuses (APPROVED/FAILED/REVALID) back to CURSANDO', VALUE_DEFAULT, false),
        ]);
    }

    public static function execute($classId, $open, $initDate = null, $endDate = null, $revert_states = false) {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'classId'       => $classId,
            'open'          => $open,
            'initDate'      => $initDate,
            'endDate'       => $endDate,
            'revert_states' => $revert_states,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        // Closing must go through gmk_close_class_with_grade_recalc to ensure
        // grade recalculation, validation, transaction safety and audit logging.
        if (!$params['open']) {
            throw new moodle_exception('closenotsupported', 'local_grupomakro_core', '',
                'Use local_grupomakro_close_class_period to close a class. ' .
                'Direct close is disabled to enforce grade recalculation and audit logging.');
        }

        $class = $DB->get_record('gmk_class', ['id' => $params['classId']], '*', MUST_EXIST);
        $now   = time();

        $update               = new \stdClass();
        $update->id           = $class->id;
        $update->closed       = 0;
        $update->timemodified = $now;
        $update->usermodified = $USER->id;

        if ($params['initDate']) {
            $update->initdate = strtotime($params['initDate']);
        }
        if ($params['endDate']) {
            $update->enddate = strtotime($params['endDate']);
        }

        $DB->update_record('gmk_class', $update);

        // Unlock the grade category so professors can edit grades again.
        toggle_class_grade_lock($class->id, false);

        // Optionally revert academic statuses so students appear as CURSANDO again.
        if ($params['revert_states']) {
            if (!defined('COURSE_IN_PROGRESS')) {
                require_once($GLOBALS['CFG']->dirroot . '/local/grupomakro_core/classes/local/progress_manager.php');
            }
            $DB->execute(
                "UPDATE {gmk_course_progre}
                    SET status = :inprogress, timemodified = :t
                  WHERE classid = :cid
                    AND status IN (4, 5, 6)",
                ['inprogress' => COURSE_IN_PROGRESS, 't' => $now, 'cid' => $class->id]
            );
        }

        return [
            'status'  => 'ok',
            'message' => 'Class reopened.' . ($params['revert_states'] ? ' Student statuses reverted to Cursando.' : ''),
        ];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'status'  => new external_value(PARAM_TEXT, 'Status code'),
            'message' => new external_value(PARAM_TEXT, 'Message'),
        ]);
    }
}
