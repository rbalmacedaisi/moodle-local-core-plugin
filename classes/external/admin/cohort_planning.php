<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Servicios del tablero de planificacion por cohortes.
 *
 * Fase 1: solo lectura (demanda por cohorte de un periodo academico).
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Grupo Makro
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\admin;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;
use local_grupomakro_core\local\planning\demand_engine;

class cohort_planning extends external_api {

    public static function get_demand_parameters() {
        return new external_function_parameters([
            'periodid' => new external_value(PARAM_INT, 'Periodo academico (0 = el proximo por iniciar)', VALUE_DEFAULT, 0),
            'planids' => new external_value(PARAM_SEQUENCE, 'Planes separados por coma (vacio = todos)', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Demanda por cohorte. Solo lectura.
     *
     * @return array ['data' => JSON con periods, selected, y el resultado de demand_engine::compute()]
     */
    public static function get_demand($periodid = 0, $planids = '') {
        global $DB;
        $params = self::validate_parameters(self::get_demand_parameters(),
            ['periodid' => $periodid, 'planids' => $planids]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:manage_academic_planning', $context);

        // Periodos que se pueden planificar: abiertos y con calendario por bloques.
        $periods = [];
        $rows = $DB->get_records_sql(
            "SELECT p.id, p.name, p.startdate, p.enddate, p.status, c.block1start, c.block2start, c.hassubperiods
               FROM {gmk_academic_periods} p
          LEFT JOIN {gmk_academic_calendar} c ON c.academicperiodid = p.id
              WHERE p.status = 1
           ORDER BY p.startdate ASC");
        $now = time();
        $default = 0;
        foreach ($rows as $r) {
            $ok = !empty($r->hassubperiods) && !empty($r->block1start) && !empty($r->block2start);
            $periods[] = ['id' => (int)$r->id, 'name' => $r->name, 'startdate' => (int)$r->startdate,
                'enddate' => (int)$r->enddate, 'has_calendar' => $ok,
                'block1start' => (int)$r->block1start, 'block2start' => (int)$r->block2start];
            if (!$default && $ok && (int)$r->block1start > $now) {
                $default = (int)$r->id;
            }
        }
        if (!$default && $periods) {
            $default = $periods[count($periods) - 1]['id'];
        }
        $pid = $params['periodid'] ?: $default;
        $plans = $params['planids'] !== '' ? array_map('intval', explode(',', $params['planids'])) : [];

        $result = $pid ? (new demand_engine($pid, $plans))->compute() : ['error' => 'No hay periodos academicos abiertos.'];
        $careers = array_values(array_filter($DB->get_records_sql(
            "SELECT DISTINCT lp.id, lp.name
               FROM {local_learning_plans} lp
               JOIN {local_learning_users} llu ON llu.learningplanid = lp.id
                    AND llu.userrolename = 'student' AND llu.status = 'activo'
           ORDER BY lp.name"), function ($c) {
            return !in_array((int)$c->id, \local_grupomakro_core\local\academic_progress::EXCLUDED_PLANS, true);
        }));

        return ['data' => json_encode([
            'periods' => $periods,
            'selected_period' => $pid,
            'careers' => $careers,
            'result' => $result,
        ], JSON_UNESCAPED_UNICODE)];
    }

    public static function get_demand_returns() {
        return new external_single_structure([
            'data' => new external_value(PARAM_RAW, 'JSON con la demanda'),
        ]);
    }
}
