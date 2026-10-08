<?php
namespace local_grupomakro_core\external\wdr;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/wdr_manager.php');

use external_api;
use external_function_parameters;
use external_value;
use external_multiple_structure;
use external_single_structure;

class get_my_requests extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new \external_function_parameters([]);
    }

    public static function execute(): array {
        global $USER;

        $context = \context_system::instance();
        require_capability('local/grupomakro_core:create_wdr_request', $context);

        $rows = \local_grupomakro_core\local\wdr_manager::list_for_user((int)$USER->id);

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'                  => (int)$r->id,
                'request_number'      => $r->request_number,
                'status'              => $r->status,
                'reason'              => $r->reason,
                'timecreated'         => (int)$r->timecreated,
                'received_da_at'      => (int)($r->received_da_at ?? 0),
                'received_admin_at'   => (int)($r->received_admin_at ?? 0),
                'has_scanned'         => !empty($r->scanned_pdf_path),
            ];
        }
        return $out;
    }

    public static function execute_returns(): external_multiple_structure {
        return new \external_multiple_structure(new \external_single_structure([
            'id'                => new \external_value(PARAM_INT, 'Row id'),
            'request_number'    => new \external_value(PARAM_TEXT, 'RET-{YYYY}-{NNNN}'),
            'status'            => new \external_value(PARAM_TEXT, 'Status code'),
            'reason'            => new \external_value(PARAM_TEXT, 'A|B|C|D|E|F'),
            'timecreated'       => new \external_value(PARAM_INT, 'Unix ts'),
            'received_da_at'    => new \external_value(PARAM_INT, 'Unix ts DA receipt'),
            'received_admin_at' => new \external_value(PARAM_INT, 'Unix ts Admin receipt'),
            'has_scanned'       => new \external_value(PARAM_BOOL, 'A scanned signed copy is on file'),
        ]));
    }
}
