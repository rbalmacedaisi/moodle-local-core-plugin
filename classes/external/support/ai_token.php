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
 * Mesa de ayuda (20261001126): genera o revoca el token del agente en la
 * nube desde la configuracion. El token solo se devuelve al generarlo; despues
 * la pantalla muestra solo sus ultimos 4 caracteres.
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

class ai_token extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'op' => new external_value(PARAM_ALPHA, 'generate | revoke'),
        ]);
    }

    public static function execute($op): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['op' => $op]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:support_manage', $context);
        require_capability('local/grupomakro_core:support_assistant', $context);
        $token = '';
        if ($params['op'] === 'generate') {
            $token = assistant::generate_token((int)$USER->id);
        } else if ($params['op'] === 'revoke') {
            assistant::revoke_token((int)$USER->id);
        } else {
            throw new \invalid_parameter_exception('op');
        }
        return ['token' => $token, 'json' => json_encode(assistant::export_ui())];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'token' => new external_value(PARAM_RAW, 'Token nuevo (solo al generar; no se vuelve a mostrar)'),
            'json' => new external_value(PARAM_RAW, 'Assistant JSON after the change'),
        ]);
    }
}
