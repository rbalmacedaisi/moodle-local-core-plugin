<?php
namespace local_grupomakro_core\external\teacher;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use stdClass;

class create_express_activity extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters(
            array(
                'classid' => new external_value(PARAM_INT, 'The ID of the class', VALUE_REQUIRED),
                'type' => new external_value(PARAM_ALPHA, 'The type of activity (bbb, assign, etc.)', VALUE_REQUIRED),
                'name' => new external_value(PARAM_TEXT, 'The name of the activity', VALUE_REQUIRED),
                'intro' => new external_value(PARAM_RAW, 'The description of the activity', VALUE_DEFAULT, ''),
                'duedate' => new external_value(PARAM_INT, 'The due date for assignments', VALUE_DEFAULT, 0),
                'save_as_template' => new external_value(PARAM_BOOL, 'Whether to save as a template', VALUE_DEFAULT, false)
            )
        );
    }

    public static function execute($classid, $type, $name, $intro, $duedate, $save_as_template) {
        $params = self::validate_parameters(self::execute_parameters(), array(
            'classid' => $classid,
            'type' => $type,
            'name' => $name,
            'intro' => $intro,
            'duedate' => $duedate,
            'save_as_template' => $save_as_template
        ));

        $context = \context_system::instance();
        self::validate_context($context);

        // Convert alpha type to Moodle module name if necessary
        $modmap = ['bbb' => 'bigbluebuttonbn', 'assignment' => 'assign', 'task' => 'assign', 'resource' => 'resource'];
        $modname = isset($modmap[$params['type']]) ? $modmap[$params['type']] : $params['type'];

        $extra = [
            'duedate' => $params['duedate'],
            'save_as_template' => $params['save_as_template']
        ];

        try {
            $result = local_grupomakro_create_express_activity($params['classid'], $modname, $params['name'], $params['intro'], $extra);
            return array(
                'status' => 'success',
                'message' => 'Activity created successfully',
                'cmid' => $result->coursemodule
            );
        } catch (\Exception $e) {
            return array(
                'status' => 'error',
                'message' => $e->getMessage(),
                'cmid' => 0
            );
        }
    }

    public static function execute_returns() {
        return new external_single_structure(
            array(
                'status' => new external_value(PARAM_ALPHA, 'success or error'),
                'message' => new external_value(PARAM_TEXT, 'Error or success message'),
                'cmid' => new external_value(PARAM_INT, 'Course module ID')
            )
        );
    }
}
