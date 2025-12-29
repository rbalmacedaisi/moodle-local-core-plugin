<?php

namespace local_grupomakro_core\external\student;

use external_api;
use external_description;
use external_function_parameters;
use external_single_structure;
use external_value;
use Exception;
use local_grupomakro_progress_manager;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/progress_manager.php');

/**
 * External function to synchronize progress for all students.
 */
class sync_progress extends external_api {

    /**
     * Describes parameters.
     */
    public static function execute_parameters() {
        return new external_function_parameters([]);
    }

    /**
     * Synchronize progress for all students.
     */
    public static function execute() {
        global $DB;

        // Ensure user is logged in and has appropriate permissions.
        self::validate_context(\context_system::instance());
        require_capability('local/grupomakro_core:seeallorders', \context_system::instance());

        $result = [
            'status' => 'success',
            'message' => 'Sincronización completada.',
            'count' => 0
        ];

        try {
            // Get all students enrolled in learning plans.
            $sql = "SELECT lpu.userid, lpu.learningplanid, lpc.courseid
                    FROM {local_learning_users} lpu
                    JOIN {local_learning_courses} lpc ON lpc.learningplanid = lpu.learningplanid
                    JOIN {gmk_course_progre} gcp ON (gcp.userid = lpu.userid AND gcp.courseid = lpc.courseid)
                    WHERE lpu.userrolename = :rolename";
            
            $records = $DB->get_records_sql($sql, ['rolename' => 'student']);

            foreach ($records as $record) {
                try {
                    // Force progress and grade update.
                    local_grupomakro_progress_manager::update_course_progress($record->courseid, $record->userid);
                    $result['count']++;
                } catch (Exception $e) {
                    // Log or handle individual record errors if needed.
                    continue;
                }
            }

        } catch (Exception $e) {
            $result['status'] = 'error';
            $result['message'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Describes return value.
     */
    public static function execute_returns() {
        return new external_single_structure([
            'status'  => new external_value(PARAM_ALPHANUM, 'Status of the operation.'),
            'message' => new external_value(PARAM_TEXT, 'Result message.'),
            'count'   => new external_value(PARAM_INT, 'Number of records synchronized.')
        ]);
    }
}
