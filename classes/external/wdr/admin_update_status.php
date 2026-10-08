<?php
namespace local_grupomakro_core\external\wdr;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/wdr_manager.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;

class admin_update_status extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new \external_function_parameters([
            'id'             => new \external_value(PARAM_INT, 'Request id'),
            'action'         => new \external_value(PARAM_ALPHANUMEXT,
                'record_da|record_admin|reject|process|mark_pendiente_firma'),
            'reject_reason'  => new \external_value(PARAM_TEXT, 'Reason for rejection',
                VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(int $id, string $action, string $reject_reason): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'id'             => $id,
            'action'         => $action,
            'reject_reason'  => $reject_reason,
        ]);

        $context = \context_system::instance();
        require_capability('local/grupomakro_core:manage_wdr_requests', $context);

        $row = \local_grupomakro_core\local\wdr_manager::admin_update(
            (int)$params['id'],
            (string)$params['action'],
            (int)$USER->id,
            $params['action'] === 'reject' ? (string)$params['reject_reason'] : null
        );

        return [
            'id'     => (int)$row->id,
            'status' => $row->status,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new \external_single_structure([
            'id'     => new \external_value(PARAM_INT, ''),
            'status' => new \external_value(PARAM_TEXT, ''),
        ]);
    }
}
