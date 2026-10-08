<?php
namespace local_grupomakro_core\external\wdr;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/wdr_manager.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;

class get_scanned extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new \external_function_parameters([
            'id' => new \external_value(PARAM_INT, 'Request id'),
        ]);
    }

    public static function execute(int $id): array {
        $params = self::validate_parameters(self::execute_parameters(), ['id' => $id]);

        $context = \context_system::instance();
        require_capability('local/grupomakro_core:view_wdr_requests', $context);

        $file = \local_grupomakro_core\local\wdr_manager::get_scanned((int)$params['id']);
        $row = \local_grupomakro_core\local\wdr_manager::get((int)$params['id']);

        if (!$file) {
            return [
                'id'            => (int)$params['id'],
                'request_number'=> $row->request_number,
                'available'     => false,
                'mimetype'      => '',
                'filename'      => '',
                'contentbase64' => '',
            ];
        }
        return [
            'id'             => (int)$params['id'],
            'request_number' => $row->request_number,
            'available'      => true,
            'mimetype'       => $file->get_mimetype(),
            'filename'       => $file->get_filename(),
            'contentbase64'  => base64_encode($file->get_content()),
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new \external_single_structure([
            'id'             => new \external_value(PARAM_INT, ''),
            'request_number' => new \external_value(PARAM_TEXT, ''),
            'available'      => new \external_value(PARAM_BOOL, ''),
            'mimetype'       => new \external_value(PARAM_TEXT, ''),
            'filename'       => new \external_value(PARAM_TEXT, ''),
            'contentbase64'  => new \external_value(PARAM_RAW, ''),
        ]);
    }
}
