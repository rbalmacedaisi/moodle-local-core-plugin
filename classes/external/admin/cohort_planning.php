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
use local_grupomakro_core\local\planning\planning_config;

class cohort_planning extends external_api {

    public static function get_demand_parameters() {
        return new external_function_parameters([
            'periodid' => new external_value(PARAM_INT, 'Periodo academico (0 = el proximo por iniciar)', VALUE_DEFAULT, 0),
            'planids' => new external_value(PARAM_SEQUENCE, 'Planes separados por coma (vacio = todos)', VALUE_DEFAULT, ''),
            'horizon' => new external_value(PARAM_INT, 'Periodos siguientes a proyectar (0 = solo el periodo)', VALUE_DEFAULT, 0),
            'groups' => new external_value(PARAM_INT, '1 = incluir la propuesta de grupos (agrupacion automatica)', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Demanda por cohorte. Solo lectura.
     *
     * @return array ['data' => JSON con periods, selected, y el resultado de demand_engine::compute()]
     */
    public static function get_demand($periodid = 0, $planids = '', $horizon = 0, $groups = 0) {
        global $DB;
        $params = self::validate_parameters(self::get_demand_parameters(),
            ['periodid' => $periodid, 'planids' => $planids, 'horizon' => $horizon, 'groups' => $groups]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:manage_academic_planning', $context);

        // Periodos que se pueden planificar: abiertos y con fecha de inicio (bloque 1 del calendario
        // o fecha de inicio del periodo). Por defecto, el proximo en iniciar.
        $periods = [];
        $rows = $DB->get_records_sql(
            "SELECT p.id, p.name, p.startdate, p.enddate, p.status, c.block1start
               FROM {gmk_academic_periods} p
          LEFT JOIN {gmk_academic_calendar} c ON c.academicperiodid = p.id
              WHERE p.status = 1
           ORDER BY p.startdate ASC");
        $now = time();
        $default = 0;
        foreach ($rows as $r) {
            $start = !empty($r->block1start) ? (int)$r->block1start : (int)$r->startdate;
            $periods[] = ['id' => (int)$r->id, 'name' => $r->name, 'start' => $start,
                'enddate' => (int)$r->enddate, 'has_calendar' => $start > 0];
            if (!$default && $start > $now) {
                $default = (int)$r->id;
            }
        }
        if (!$default && $periods) {
            $default = $periods[count($periods) - 1]['id'];
        }
        $pid = $params['periodid'] ?: $default;
        $plans = $params['planids'] !== '' ? array_map('intval', explode(',', $params['planids'])) : [];

        $result = $pid ? (new demand_engine($pid, $plans, $params['horizon']))->compute() : ['error' => 'No hay periodos academicos abiertos.'];
        $careers = array_values(array_filter($DB->get_records_sql(
            "SELECT DISTINCT lp.id, lp.name
               FROM {local_learning_plans} lp
               JOIN {local_learning_users} llu ON llu.learningplanid = lp.id
                    AND llu.userrolename = 'student' AND llu.status = 'activo'
           ORDER BY lp.name"), function ($c) {
            return !in_array((int)$c->id, \local_grupomakro_core\local\academic_progress::EXCLUDED_PLANS, true);
        }));

        if ($pid && empty($result['error'])) {
            $prow = $DB->get_record('gmk_academic_periods', ['id' => $pid]);
            $result['period']['jornada_starts'] = (object)planning_config::jornada_starts($prow);
            $result['exceptions'] = array_values(planning_config::exceptions($pid));
            $result['costing'] = planning_config::costing($pid, (int)$result['period']['start']);
            if ($params['groups']) {
                $result['grouping'] = (new \local_grupomakro_core\local\planning\group_builder($result, planning_config::rules()))->build();
            }
        }
        return ['data' => json_encode([
            'periods' => $periods,
            'selected_period' => $pid,
            'careers' => $careers,
            'rules' => planning_config::rules(),
            'result' => $result,
        ], JSON_UNESCAPED_UNICODE)];
    }

    public static function get_demand_returns() {
        return new external_single_structure([
            'data' => new external_value(PARAM_RAW, 'JSON con la demanda'),
        ]);
    }

    // ---------- Configuracion (pestana Configuracion de la linea de tiempo) ----------

    public static function get_config_parameters() {
        return new external_function_parameters([]);
    }

    public static function get_config() {
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:manage_academic_planning', $context);
        return ['data' => json_encode(planning_config::get(), JSON_UNESCAPED_UNICODE)];
    }

    public static function get_config_returns() {
        return new external_single_structure([
            'data' => new external_value(PARAM_RAW, 'JSON con la configuracion'),
        ]);
    }

    public static function save_config_parameters() {
        return new external_function_parameters([
            'section' => new external_value(PARAM_ALPHANUMEXT, 'period | exception_add | exception_remove | flex_toggle | flexible | costs | rules | scheduler | isolation | classroom | classroom_delete | holiday | holiday_delete'),
            'data' => new external_value(PARAM_RAW, 'JSON con los datos de la seccion'),
        ]);
    }

    public static function save_config($section, $data) {
        $params = self::validate_parameters(self::save_config_parameters(), ['section' => $section, 'data' => $data]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:manage_academic_planning', $context);
        $payload = json_decode($params['data'], true);
        if (!is_array($payload)) {
            return ['ok' => false, 'message' => 'Datos no válidos.', 'saved' => 'null'];
        }
        $r = planning_config::save($params['section'], $payload);
        return ['ok' => (bool)$r['ok'], 'message' => (string)$r['message'], 'saved' => json_encode($r['saved'], JSON_UNESCAPED_UNICODE)];
    }

    public static function save_config_returns() {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Guardado'),
            'message' => new external_value(PARAM_TEXT, 'Mensaje para el usuario'),
            'saved' => new external_value(PARAM_RAW, 'JSON con lo guardado, releido de la base'),
        ]);
    }
}
