<?php
namespace local_grupomakro_core\external\wdr;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/wdr_manager.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;

class download_pdf extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Request id'),
        ]);
    }

    public static function execute(int $id): array {
        global $USER;

        $context = \context_system::instance();
        require_capability('local/grupomakro_core:create_wdr_request', $context);

        // Owner OR admin.
        $isadmin = has_capability('local/grupomakro_core:manage_wdr_requests', $context);
        $row = \local_grupomakro_core\local\wdr_manager::get_for_user($id, (int)$USER->id, $isadmin);

        $bytes = \local_grupomakro_core\local\wdr_manager::render_pdf($id);

        return [
            'id'             => (int)$row->id,
            'request_number' => $row->request_number,
            'mimetype'       => 'application/pdf',
            'filename'       => 'RET-01-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $row->request_number) . '.pdf',
            'contentbase64'  => base64_encode($bytes),
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'id'             => new external_value(PARAM_INT, ''),
            'request_number' => new external_value(PARAM_TEXT, ''),
            'mimetype'       => new external_value(PARAM_TEXT, ''),
            'filename'       => new external_value(PARAM_TEXT, ''),
            'contentbase64'  => new external_value(PARAM_RAW, 'base64-encoded PDF bytes'),
        ]);
    }
}
