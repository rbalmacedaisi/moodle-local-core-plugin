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
 * local_grupomakro_support_settings_save: Guarda o borra un elemento de la configuracion y deja evento de auditoria.
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

class settings_save extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'section' => new external_value(PARAM_ALPHA, 'area | category | agent | backup | person | template | config'),
            'op' => new external_value(PARAM_ALPHA, 'save | delete', VALUE_DEFAULT, 'save'),
            'payload' => new external_value(PARAM_RAW, 'JSON object'),
        ]);
    }

    public static function execute($section, $op, $payload): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['section' => $section, 'op' => $op, 'payload' => $payload]);
        self::validate_context(context_system::instance());
        $userid = (int)$USER->id;
        require_capability('local/grupomakro_core:support_manage', context_system::instance());
        $data = json_decode((string)$params['payload'], true);
        if (!is_array($data)) {
            throw new \invalid_parameter_exception('payload must be a JSON object');
        }
        \local_grupomakro_core\local\support\settings::apply((string)$params['section'], (string)$params['op'], $data, $userid);
        return ['json' => json_encode(\local_grupomakro_core\local\support\settings::export())];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure(['json' => new external_value(PARAM_RAW, 'Settings JSON after the change')]);
    }
}
