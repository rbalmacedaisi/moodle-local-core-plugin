<?php
// This file is part of Moodle - https://moodle.org/

namespace local_grupomakro_core\external\letter;

defined('MOODLE_INTERNAL') || die();

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use local_grupomakro_core\local\letters\manager;

require_once($CFG->libdir . '/externallib.php');

/**
 * Download request document.
 */
class download extends external_api {
    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'requestid' => new external_value(PARAM_INT, 'Request id'),
        ]);
    }

    /**
     * @param int $requestid
     * @return array
     */
    public static function execute(int $requestid): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['requestid' => $requestid]);
        self::validate_context(context_system::instance());
        $canviewall = has_capability('local/grupomakro_core:viewallletterrequests', context_system::instance());
        return manager::download_document_payload((int)$params['requestid'], (int)$USER->id, $canviewall);
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'filename' => new external_value(PARAM_FILE, 'Filename'),
            'mimetype' => new external_value(PARAM_TEXT, 'Mimetype'),
            'contentbase64' => new external_value(PARAM_RAW, 'Base64 content'),
        ]);
    }
}

