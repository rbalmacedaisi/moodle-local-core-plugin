<?php
namespace local_grupomakro_core\external\wdr;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/wdr_manager.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;

class admin_upload_scanned extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new \external_function_parameters([
            'id'             => new \external_value(PARAM_INT, 'Request id'),
            'filename'       => new \external_value(PARAM_FILE, 'Original filename'),
            'contentbase64'  => new \external_value(PARAM_RAW, 'base64-encoded PDF bytes'),
        ]);
    }

    public static function execute(int $id, string $filename, string $contentbase64): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'id'            => $id,
            'filename'      => $filename,
            'contentbase64' => $contentbase64,
        ]);

        $context = \context_system::instance();
        require_capability('local/grupomakro_core:manage_wdr_requests', $context);

        \local_grupomakro_core\local\wdr_manager::get((int)$params['id']);
        $content = base64_decode((string)$params['contentbase64'], true);
        if ($content === false) {
            throw new \moodle_exception('invalidparameter', 'error');
        }
        $path = \local_grupomakro_core\local\wdr_manager::store_scanned(
            (int)$params['id'],
            (string)$params['filename'],
            $content
        );

        global $DB;
        $DB->set_field('gmk_wdr', 'scanned_pdf_path', $path, ['id' => (int)$params['id']]);
        $DB->set_field('gmk_wdr', 'status', 'firmada_digital', ['id' => (int)$params['id']]);

        return [
            'id'   => (int)$params['id'],
            'path' => $path,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new \external_single_structure([
            'id'   => new \external_value(PARAM_INT, ''),
            'path' => new \external_value(PARAM_TEXT, ''),
        ]);
    }
}
