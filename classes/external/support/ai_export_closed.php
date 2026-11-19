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
 * Mesa de ayuda (20261001126): endpoint del agente semanal en la nube.
 * Devuelve los tickets resueltos/cerrados aun no analizados, YA anonimizados
 * en el servidor, sin notas internas ni Psicologia (assistant::export).
 * Solo por el servicio gmk_support_ai con el token del usuario dedicado.
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

class ai_export_closed extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'since' => new external_value(PARAM_INT, 'Solo tickets cerrados desde este unix time (0 = todos los no analizados)',
                VALUE_DEFAULT, 0),
            'limit' => new external_value(PARAM_INT, 'Maximo de tickets (1-500)', VALUE_DEFAULT, 200),
        ]);
    }

    public static function execute($since = 0, $limit = 200): array {
        $params = self::validate_parameters(self::execute_parameters(), ['since' => $since, 'limit' => $limit]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:support_ai_agent', $context);
        $data = assistant::export(['since' => (int)$params['since'], 'limit' => (int)$params['limit']]);
        return ['tickets' => count($data['tickets']),
            'json' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'tickets' => new external_value(PARAM_INT, 'Tickets exportados'),
            'json' => new external_value(PARAM_RAW, 'Exportacion anonimizada (format gmk-support-ai-export/1)'),
        ]);
    }
}
