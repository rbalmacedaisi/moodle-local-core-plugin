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
 * Get active letter types.
 */
class get_types extends external_api {
    /**
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * @return array
     */
    public static function execute(): array {
        self::validate_parameters(self::execute_parameters(), []);
        self::validate_context(context_system::instance());
        return ['types' => manager::get_active_letter_types()];
    }

    /**
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'types' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Letter type id'),
                    'code' => new external_value(PARAM_TEXT, 'Type code'),
                    'name' => new external_value(PARAM_TEXT, 'Name'),
                    'warningtext' => new external_value(PARAM_RAW, 'Warning message'),
                    'cost' => new external_value(PARAM_FLOAT, 'Configured cost'),
                    'active' => new external_value(PARAM_INT, 'Active flag'),
                    'deliverymode' => new external_value(PARAM_TEXT, 'digital/fisica'),
                    'generationmode' => new external_value(PARAM_TEXT, 'auto/manual'),
                    'autostamp' => new external_value(PARAM_INT, 'Auto stamp enabled'),
                    'autosignature' => new external_value(PARAM_INT, 'Auto signature enabled'),
                    'odoo_product_id' => new external_value(PARAM_INT, 'Odoo product id'),
                    'datasetcodes' => new external_multiple_structure(
                        new external_value(PARAM_TEXT, 'Dataset code')
                    ),
                ])
            ),
        ]);
    }
}

