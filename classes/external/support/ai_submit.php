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
 * Recibe la ejecucion (resumen, tipificaciones y sugerencias por area), la
 * valida y sanea (assistant::import) y la guarda en gmk_tkt_ai_*. Nada llega a
 * estudiantes hasta que alguien pulsa "Anadir". dryrun=1 valida y revierte.
 * Idempotente por runkey.
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

class ai_submit extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'run' => new external_value(PARAM_RAW, 'JSON de la ejecucion (ver docs/agents/analisis_semanal_tickets.md)'),
            'dryrun' => new external_value(PARAM_BOOL, '1 = solo validar y contar, sin guardar', VALUE_DEFAULT, false),
        ]);
    }

    public static function execute($run, $dryrun = false): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['run' => $run, 'dryrun' => $dryrun]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:support_ai_agent', $context);
        if (strlen((string)$params['run']) > assistant::MAX_SUBMIT_BYTES) {
            throw new \invalid_parameter_exception('run supera ' . assistant::MAX_SUBMIT_BYTES . ' bytes');
        }
        $data = json_decode((string)$params['run'], true, 32);
        if (!is_array($data)) {
            throw new \invalid_parameter_exception('run debe ser un objeto JSON válido');
        }
        $report = assistant::import($data, (int)$USER->id, !empty($params['dryrun']));
        return ['status' => (string)$report['status'], 'runid' => (int)$report['runid'],
            'report' => json_encode($report, JSON_UNESCAPED_UNICODE)];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHAEXT, 'ok | dry_run | already_imported'),
            'runid' => new external_value(PARAM_INT, 'Id de la ejecucion (0 en dryrun)'),
            'report' => new external_value(PARAM_RAW, 'Detalle: classified, skipped[], suggestions_new/updated/skipped[]'),
        ]);
    }
}
