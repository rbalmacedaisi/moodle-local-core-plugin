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
 * Seguimiento de inasistencias (20261001131): indicadores de supervision
 * (gerencia y administradores). Cada indicador trae los ids de sus casos.
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

class cases_stats extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'weeks' => new external_value(PARAM_INT, 'Semanas de la serie', VALUE_DEFAULT, 8),
        ]);
    }

    public static function execute(int $weeks = 8): array {
        $p = self::validate_parameters(self::execute_parameters(), ['weeks' => $weeks]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:supervise_absence_cases', $context);
        cases::sync();
        return ['json' => json_encode(cases::stats(0, max(4, min(26, (int)$p['weeks']))))];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure(['json' => new external_value(PARAM_RAW, 'Data as JSON')]);
    }
}
