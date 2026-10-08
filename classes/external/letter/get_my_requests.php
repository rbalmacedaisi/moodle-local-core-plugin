<?php
// This file is part of Moodle - https://moodle.org/

namespace local_grupomakro_core\external\letter;

defined('MOODLE_INTERNAL') || die();

use context_system;
use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use local_grupomakro_core\local\letters\manager;

require_once($CFG->libdir . '/externallib.php');

/**
 * Get current user letter requests.
 */
class get_my_requests extends external_api {
    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'status' => new external_value(PARAM_TEXT, 'Optional status filter', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * @param string $status
     * @return array
     */
    public static function execute(string $status = ''): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['status' => $status]);
        self::validate_context(context_system::instance());
        return ['requests' => manager::get_requests((int)$USER->id, false, (string)$params['status'])];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'requests' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Request id'),
                    'userid' => new external_value(PARAM_INT, 'User id'),
                    'lettertypeid' => new external_value(PARAM_INT, 'Letter type id'),
                    'lettertypename' => new external_value(PARAM_TEXT, 'Letter type name'),
                    'lettertypecode' => new external_value(PARAM_TEXT, 'Letter type code'),
                    'status' => new external_value(PARAM_TEXT, 'Status'),
                    'statuslabel' => new external_value(PARAM_TEXT, 'Status label'),
                    'cost_snapshot' => new external_value(PARAM_FLOAT, 'Cost'),
                    'invoice_id' => new external_value(PARAM_TEXT, 'Invoice id'),
                    'invoice_number' => new external_value(PARAM_TEXT, 'Invoice number'),
                    'payment_link' => new external_value(PARAM_RAW, 'Payment link'),
                    'document_available' => new external_value(PARAM_INT, 'Document available'),
                    'document_filename' => new external_value(PARAM_TEXT, 'Document filename'),
                    'document_verification_url' => new external_value(PARAM_RAW, 'Public verification URL'),
                    'timecreated' => new external_value(PARAM_INT, 'Creation timestamp'),
                    'timemodified' => new external_value(PARAM_INT, 'Modification timestamp'),
                ])
            ),
        ]);
    }
}
