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

    /**
     * Parameters for execute.
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'classId' => new external_value(PARAM_INT, 'The class ID'),
            'open' => new external_value(PARAM_BOOL, 'True to open, False to close', VALUE_REQUIRED),
            'initDate' => new external_value(PARAM_TEXT, 'New Init Date (YYYY-MM-DD)', VALUE_DEFAULT, null),
            'endDate' => new external_value(PARAM_TEXT, 'New End Date (YYYY-MM-DD)', VALUE_DEFAULT, null),
        ]);
    }

    /**
     * Execute the function.
     */
    public static function execute($classId, $open, $initDate = null, $endDate = null) {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'classId' => $classId,
            'open' => $open,
            'initDate' => $initDate,
            'endDate' => $endDate
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context); // Only admins for now

        $class = $DB->get_record('gmk_class', ['id' => $params['classId']], '*', MUST_EXIST);

        $update = new \stdClass();
        $update->id = $class->id;
        $update->closed = $params['open'] ? 0 : 1;
        $update->timemodified = time();
        $update->usermodified = $USER->id;

        if ($params['initDate']) {
            $update->initdate = strtotime($params['initDate']);
        }
        if ($params['endDate']) {
            $update->enddate = strtotime($params['endDate']);
        }

        $DB->update_record('gmk_class', $update);

        // Toggle grade lock
        // If opening (open=true), we UNLOCK (locked=false).
        // If closing (open=false), we LOCK (locked=true).
        toggle_class_grade_lock($class->id, !$params['open']);

        return ['status' => 'ok', 'message' => 'Class status updated.'];
    }

    /**
     * Return structure.
     */
    public static function execute_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_TEXT, 'Status code'),
            'message' => new external_value(PARAM_TEXT, 'Message')
        ]);
    }
}
