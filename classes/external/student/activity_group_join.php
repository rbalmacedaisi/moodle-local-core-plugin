<?php
namespace local_grupomakro_core\external\student;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;

class activity_group_join extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'groupid' => new external_value(PARAM_INT, 'gmk_activity_group.id', VALUE_REQUIRED),
        ]);
    }

    public static function execute(int $groupid): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'groupid' => $groupid,
        ]);
        $groupid = (int)$params['groupid'];

        return gmk_join_activity_group($groupid, (int)$USER->id);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status'  => new external_value(PARAM_TEXT, 'ok|full|fixed|invalid|duplicate|error'),
            'message' => new external_value(PARAM_TEXT, 'Mensaje', VALUE_OPTIONAL),
            'groupid' => new external_value(PARAM_INT, 'groupid', VALUE_OPTIONAL),
        ]);
    }
}
