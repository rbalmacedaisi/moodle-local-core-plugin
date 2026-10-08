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
 * Devuelve la configuracion del lado Moodle para la evaluacion docente (RF-08).
 *
 * El LXP necesita saber si el popup esta habilitado y cuantos minutos de
 * espera aplicar antes de mostrarlo. Mantenemos este WS minimo en lugar de
 * exponer get_config() entero.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\wellness;

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;

defined('MOODLE_INTERNAL') || die();

class get_wellness_eval_enabled extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([]);
    }

    public static function execute() {
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:view_wellness', $context);

        $enabled = (bool)get_config('local_grupomakro_core', 'wellness_eval_enabled');
        $delay = (int)get_config('local_grupomakro_core', 'wellness_eval_delay_minutes');
        if ($delay < 0) {
            $delay = 0;
        }
        return [
            'enabled'       => $enabled,
            'delay_minutes' => $delay,
        ];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'enabled'       => new external_value(PARAM_BOOL, 'Popup habilitado'),
            'delay_minutes' => new external_value(PARAM_INT, 'Minutos de espera antes de mostrar el popup'),
        ]);
    }
}
