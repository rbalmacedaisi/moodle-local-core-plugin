<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * local_grupomakro_support_get_catalog: Areas y temas disponibles para abrir una solicitud (incluye enlaces externos como Finanzas).
 *
 * Mesa de ayuda (20261001120). Identidad = $USER siempre; el acceso lo decide
 * local\support\manager.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\support;

use context_system;
use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;
use local_grupomakro_core\local\support\manager;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class get_catalog extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([

        ]);
    }

    public static function execute(): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), []);
        self::validate_context(context_system::instance());
        $userid = (int)$USER->id;
        require_capability('local/grupomakro_core:support_create', context_system::instance());
        return [
            'areas' => manager::catalog($userid),
            'max_open' => \local_grupomakro_core\local\support\config::get_int('tkt_max_open'),
            'max_body' => manager::MAX_BODY,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'areas' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Area id'),
                'code' => new external_value(PARAM_ALPHANUMEXT, 'Code'),
                'name' => new external_value(PARAM_TEXT, 'Name'),
                'description' => new external_value(PARAM_RAW, 'Description'),
                'icon' => new external_value(PARAM_TEXT, 'mdi icon'),
                'hours' => new external_value(PARAM_TEXT, 'Business hours, readable'),
                'isopen' => new external_value(PARAM_BOOL, 'Within business hours now'),
                'external' => new external_value(PARAM_BOOL, 'Does not open tickets: shows a link'),
                'external_url' => new external_value(PARAM_RAW, 'Link with the initial message'),
                'external_label' => new external_value(PARAM_TEXT, 'Button text'),
                'topics' => new external_multiple_structure(new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Topic id'),
                    'name' => new external_value(PARAM_TEXT, 'Name'),
                    'description' => new external_value(PARAM_RAW, 'Description'),
                    'confidential' => new external_value(PARAM_BOOL, 'Confidential'),
                    'requires_attachment' => new external_value(PARAM_BOOL, 'Attachment required (phase 3)'),
                ])),
            ])),
            'max_open' => new external_value(PARAM_INT, 'Max open requests per student'),
            'max_body' => new external_value(PARAM_INT, 'Max characters per message'),
        ]);
    }
}
