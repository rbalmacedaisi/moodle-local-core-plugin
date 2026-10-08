<?php
namespace local_grupomakro_core\external\student;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;

class activity_group_leave extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new \external_function_parameters([
            'cmid' => new \external_value(PARAM_INT, 'course_modules.id', VALUE_REQUIRED),
        ]);
    }

    public static function execute(int $cmid): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid,
        ]);
        $cmid = (int)$params['cmid'];

        return gmk_leave_activity_group($cmid, (int)$USER->id);
    }

    public static function execute_returns(): external_single_structure {
        return new \external_single_structure([
            'status'  => new \external_value(PARAM_TEXT, 'ok|fixed|invalid|not_member|error'),
            'message' => new \external_value(PARAM_TEXT, 'Mensaje', VALUE_OPTIONAL),
        ]);
    }
}
