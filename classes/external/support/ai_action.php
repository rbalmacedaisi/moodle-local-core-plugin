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
 * Mesa de ayuda (20261001126): Anadir / Editar y anadir / Descartar /
 * Recuperar una sugerencia del asistente semanal. Anadir pasa por
 * settings::apply (misma validacion y auditoria que la configuracion manual).
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\support;

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use local_grupomakro_core\local\support\assistant;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class ai_action extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Suggestion id'),
            'op' => new external_value(PARAM_ALPHA, 'add | dismiss | restore'),
            'payload' => new external_value(PARAM_RAW, 'JSON object with the edited fields ("Editar y añadir"), or empty',
                VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute($id, $op, $payload = ''): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['id' => $id, 'op' => $op, 'payload' => $payload]);
        self::validate_context(context_system::instance());
        require_capability('local/grupomakro_core:support_manage', context_system::instance());
        require_capability('local/grupomakro_core:support_assistant', context_system::instance());
        $edited = null;
        if (trim((string)$params['payload']) !== '') {
            $edited = json_decode((string)$params['payload'], true);
            if (!is_array($edited)) {
                throw new \invalid_parameter_exception('payload must be a JSON object');
            }
        }
        $resultid = assistant::action((int)$params['id'], (string)$params['op'], $edited, (int)$USER->id);
        return ['resultid' => $resultid, 'json' => json_encode(assistant::export_ui())];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'resultid' => new external_value(PARAM_INT, 'Created or updated faq/canned id'),
            'json' => new external_value(PARAM_RAW, 'Assistant JSON after the change'),
        ]);
    }
}
