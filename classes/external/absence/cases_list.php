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
 * Seguimiento de inasistencias (20261001131): listado de casos (sincroniza antes con los bloqueos activos).
 * Respuesta en JSON en un solo campo (solo para js/components/AbsenceCases.js).
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

class cases_list extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'status' => new external_value(PARAM_ALPHA, 'open|due|unmanaged|overdue|closed|all', VALUE_DEFAULT, 'open'),
            'search' => new external_value(PARAM_TEXT, 'Nombre, cedula o clase', VALUE_DEFAULT, ''),
            'ids' => new external_value(PARAM_SEQUENCE, 'Ids de casos (detalle de un indicador)', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(string $status = 'open', string $search = '', string $ids = ''): array {
        global $USER;
        $p = self::validate_parameters(self::execute_parameters(), ['status' => $status, 'search' => $search, 'ids' => $ids]);
        self::validate_context(context_system::instance());
        if (!cases::can_view((int)$USER->id)) {
            throw new \required_capability_exception(context_system::instance(), 'local/grupomakro_core:viewabsencedashboard', 'nopermissions', '');
        }
        cases::sync();
        $filters = ['status' => $p['status'], 'search' => $p['search']];
        if ($p['ids'] !== '') {
            $filters['ids'] = array_filter(array_map('intval', explode(',', $p['ids'])));
            $filters['status'] = 'all';
        }
        return ['json' => json_encode([
            'cases' => cases::list($filters),
            'canmanage' => cases::can_manage((int)$USER->id),
            'cansupervise' => cases::can_supervise((int)$USER->id),
            'statuses' => cases::STATUSES,
            'channels' => cases::CHANNELS,
            'results' => cases::RESULTS,
            'reasons' => array_diff_key(cases::CLOSE_REASONS, ['auto_desbloqueado' => 1]),
            'now' => time(),
        ])];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure(['json' => new external_value(PARAM_RAW, 'Data as JSON')]);
    }
}
