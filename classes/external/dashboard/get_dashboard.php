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
 * local_grupomakro_dashboard_get: datos de la pagina "Mis pendientes"
 * (tarjetas por fuente con conteos, semaforo y los mas urgentes). Cacheado
 * 60 s por persona y vista; refresh = 1 lo recalcula. Identidad = $USER.
 *
 * La respuesta va como JSON en un solo campo: es solo para el JS de la pagina.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\dashboard;

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use local_grupomakro_core\local\dashboard\dashboard;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class get_dashboard extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'scope' => new external_value(PARAM_ALPHA, 'mine | team', VALUE_DEFAULT, 'mine'),
            'refresh' => new external_value(PARAM_BOOL, 'Skip the 60 s cache', VALUE_DEFAULT, false),
        ]);
    }

    public static function execute(string $scope = 'mine', bool $refresh = false): array {
        global $USER;
        $p = self::validate_parameters(self::execute_parameters(), ['scope' => $scope, 'refresh' => $refresh]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:view_admin_dashboard', $context);
        return ['json' => json_encode(dashboard::get((int)$USER->id, (string)$p['scope'], (bool)$p['refresh']))];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure(['json' => new external_value(PARAM_RAW, 'Dashboard data as JSON')]);
    }
}
