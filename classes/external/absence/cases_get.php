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
 * Seguimiento de inasistencias (20261001131): un caso con su historial de
 * gestiones. Respuesta en JSON (solo para js/components/AbsenceCases.js).
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\absence;

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use local_grupomakro_core\local\absence\cases;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class cases_get extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'caseid' => new external_value(PARAM_INT, 'Caso'),
        ]);
    }

    public static function execute(int $caseid): array {
        global $USER;
        $p = self::validate_parameters(self::execute_parameters(), ['caseid' => $caseid]);
        self::validate_context(context_system::instance());
        if (!cases::can_view((int)$USER->id)) {
            throw new \required_capability_exception(context_system::instance(),
                'local/grupomakro_core:viewabsencedashboard', 'nopermissions', '');
        }
        return ['json' => json_encode(cases::get((int)$p['caseid']))];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure(['json' => new external_value(PARAM_RAW, 'Data as JSON')]);
    }
}
