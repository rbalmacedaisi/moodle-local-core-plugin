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
 * Seguimiento de inasistencias (20261001131): registra una gestion en un caso
 * (solo agregar; no hay edicion ni borrado). Exige manage_absence_cases.
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

class cases_log extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'caseid' => new external_value(PARAM_INT, 'Caso'),
            'channel' => new external_value(PARAM_ALPHA, 'Canal'),
            'result' => new external_value(PARAM_ALPHAEXT, 'Resultado'),
            'note' => new external_value(PARAM_TEXT, 'Nota', VALUE_DEFAULT, ''),
            'next_followup' => new external_value(PARAM_INT, 'Proximo seguimiento (timestamp)', VALUE_DEFAULT, 0),
            'status' => new external_value(PARAM_ALPHAEXT, 'Nuevo estado', VALUE_DEFAULT, ''),
            'closed_reason' => new external_value(PARAM_ALPHAEXT, 'Motivo de cierre', VALUE_DEFAULT, ''),
            'correctsid' => new external_value(PARAM_INT, 'Gestion que corrige', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(int $caseid, string $channel, string $result, string $note = '', int $next_followup = 0,
            string $status = '', string $closed_reason = '', int $correctsid = 0): array {
        global $USER;
        $p = self::validate_parameters(self::execute_parameters(), compact('caseid', 'channel', 'result', 'note',
            'next_followup', 'status', 'closed_reason', 'correctsid'));
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:manage_absence_cases', $context);
        $case = cases::add_log((int)$p['caseid'], (int)$USER->id, $p);
        return ['json' => json_encode($case)];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure(['json' => new external_value(PARAM_RAW, 'Data as JSON')]);
    }
}
