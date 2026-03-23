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
 * Create letter request.
 */
class create_request extends external_api {
    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'lettertypeid' => new external_value(PARAM_INT, 'Letter type id'),
            'observation' => new external_value(PARAM_RAW, 'Student observation', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * @param int $lettertypeid
     * @param string $observation
     * @return array
     */
    public static function execute(int $lettertypeid, string $observation = ''): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), [
            'lettertypeid' => $lettertypeid,
            'observation' => $observation,
        ]);
        self::validate_context(context_system::instance());
        return manager::create_request((int)$USER->id, (int)$params['lettertypeid'], (string)$params['observation']);
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Request id'),
            'userid' => new external_value(PARAM_INT, 'User id'),
            'lettertypeid' => new external_value(PARAM_INT, 'Letter type id'),
            'lettertypename' => new external_value(PARAM_TEXT, 'Letter type name'),
            'lettertypecode' => new external_value(PARAM_TEXT, 'Letter type code'),
            'status' => new external_value(PARAM_TEXT, 'Status code'),
            'statuslabel' => new external_value(PARAM_TEXT, 'Status label'),
            'observation' => new external_value(PARAM_RAW, 'Observation'),
            'warning_snapshot' => new external_value(PARAM_RAW, 'Warning text'),
            'cost_snapshot' => new external_value(PARAM_FLOAT, 'Cost'),
            'deliverymode_snapshot' => new external_value(PARAM_TEXT, 'Delivery mode'),
            'generationmode_snapshot' => new external_value(PARAM_TEXT, 'Generation mode'),
            'invoice_id' => new external_value(PARAM_TEXT, 'Invoice id'),
            'invoice_number' => new external_value(PARAM_TEXT, 'Invoice number'),
            'payment_link' => new external_value(PARAM_RAW, 'Payment link'),
            'document_available' => new external_value(PARAM_INT, 'Document available'),
            'document_version' => new external_value(PARAM_INT, 'Document version'),
            'document_filename' => new external_value(PARAM_TEXT, 'Document filename'),
            'timecreated' => new external_value(PARAM_INT, 'Creation timestamp'),
            'timemodified' => new external_value(PARAM_INT, 'Modification timestamp'),
        ]);
    }
}

