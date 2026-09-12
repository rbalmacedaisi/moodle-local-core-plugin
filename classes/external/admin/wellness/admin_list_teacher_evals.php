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
 * Resultados de evaluacion docente para Coordinacion Academica (RF-08): detalle SIN ANONIMATO y promedios por docente.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\admin\wellness;

use context_system;
use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/wellness_teacher_eval_manager.php');

use local_grupomakro_core\local\wellness_teacher_eval_manager as MGR;

class admin_list_teacher_evals extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'instructorid' => new external_value(PARAM_INT, 'Filtrar por docente (0 = todos)', VALUE_DEFAULT, 0),
            'classid'      => new external_value(PARAM_INT, 'Filtrar por clase (0 = todas)', VALUE_DEFAULT, 0),
            'from'         => new external_value(PARAM_INT, 'Desde (unix ts, 0 = sin limite)', VALUE_DEFAULT, 0),
            'to'           => new external_value(PARAM_INT, 'Hasta (unix ts, 0 = sin limite)', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute($instructorid = 0, $classid = 0, $from = 0, $to = 0) {
        $p = self::validate_parameters(self::execute_parameters(), [
            'instructorid' => $instructorid, 'classid' => $classid, 'from' => $from, 'to' => $to,
        ]);
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:manage_wellness', $context);

        $items = [];
        foreach (MGR::list_results((int)$p['instructorid'], (int)$p['classid'], (int)$p['from'], (int)$p['to']) as $r) {
            $items[] = [
                'id'                 => (int)$r->id,
                'classid'            => (int)$r->classid,
                'classname'          => (string)($r->classname ?? ''),
                'sessiondate'        => (int)$r->sessiondate,
                'instructorid'       => (int)$r->instructorid,
                'teacher_name'       => (string)$r->teacher_name,
                'userid'             => (int)$r->userid,
                'student_name'       => (string)$r->student_name,
                'rating_overall'     => (int)$r->rating_overall,
                'rating_clarity'     => (int)$r->rating_clarity,
                'rating_punctuality' => (int)$r->rating_punctuality,
                'comment'            => (string)($r->comment ?? ''),
            ];
        }

        $aggs = [];
        foreach (MGR::aggregates((int)$p['from'], (int)$p['to']) as $a) {
            $aggs[] = [
                'instructorid'    => (int)$a->instructorid,
                'teacher_name'    => (string)$a->teacher_name,
                'total'           => (int)$a->total,
                'avg_overall'     => (float)$a->avg_overall,
                'avg_clarity'     => (float)$a->avg_clarity,
                'avg_punctuality' => (float)$a->avg_punctuality,
                'min_overall'     => (int)$a->min_overall,
                'max_overall'     => (int)$a->max_overall,
                'last_eval'       => (int)$a->last_eval,
                'classes_count'   => (int)$a->classes_count,
                'with_comments'   => (int)$a->with_comments,
                'dist'            => implode(',', $a->dist),
                'low_sample'      => (bool)$a->low_sample,
                'needs_attention' => (bool)$a->needs_attention,
            ];
        }

        $k = MGR::global_kpis((int)$p['from'], (int)$p['to']);
        $kpis = [
            'sent'            => $k->sent,
            'dismissed'       => $k->dismissed,
            'eligible'        => $k->eligible,
            'response_rate'   => $k->response_rate,
            'coverage_rate'   => $k->coverage_rate,
            'teachers'        => $k->teachers,
            'students'        => $k->students,
            'classes'         => $k->classes,
            'avg_overall'     => $k->avg_overall,
            'avg_clarity'     => $k->avg_clarity,
            'avg_punctuality' => $k->avg_punctuality,
            'with_comments'   => $k->with_comments,
            'dist'            => implode(',', $k->dist),
            'min_sample'      => MGR::MIN_SAMPLE,
            'attention_threshold' => MGR::ATTENTION_THRESHOLD,
        ];

        $trend = [];
        foreach (MGR::trend((int)$p['from'], (int)$p['to']) as $t) {
            $trend[] = ['period' => $t->period, 'total' => $t->total, 'avg' => $t->avg];
        }

        return ['evaluations' => $items, 'aggregates' => $aggs, 'kpis' => $kpis, 'trend' => $trend];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'evaluations' => new external_multiple_structure(new external_single_structure([
                'id'                 => new external_value(PARAM_INT,  'Id'),
                'classid'            => new external_value(PARAM_INT,  'Clase'),
                'classname'          => new external_value(PARAM_TEXT, 'Nombre de la clase'),
                'sessiondate'        => new external_value(PARAM_INT,  'Fecha de la sesion'),
                'instructorid'       => new external_value(PARAM_INT,  'Docente'),
                'teacher_name'       => new external_value(PARAM_TEXT, 'Nombre del docente'),
                'userid'             => new external_value(PARAM_INT,  'Estudiante (sin anonimato)'),
                'student_name'       => new external_value(PARAM_TEXT, 'Nombre del estudiante'),
                'rating_overall'     => new external_value(PARAM_INT,  '1-5'),
                'rating_clarity'     => new external_value(PARAM_INT,  '1-5, 0 = sin responder'),
                'rating_punctuality' => new external_value(PARAM_INT,  '1-5, 0 = sin responder'),
                'comment'            => new external_value(PARAM_RAW,  'Comentario'),
            ])),
            'aggregates' => new external_multiple_structure(new external_single_structure([
                'instructorid'    => new external_value(PARAM_INT,   'Docente'),
                'teacher_name'    => new external_value(PARAM_TEXT,  'Nombre'),
                'total'           => new external_value(PARAM_INT,   'Evaluaciones recibidas'),
                'avg_overall'     => new external_value(PARAM_FLOAT, 'Promedio general'),
                'avg_clarity'     => new external_value(PARAM_FLOAT, 'Promedio claridad'),
                'avg_punctuality' => new external_value(PARAM_FLOAT, 'Promedio puntualidad'),
                'min_overall'     => new external_value(PARAM_INT,   'Peor nota recibida'),
                'max_overall'     => new external_value(PARAM_INT,   'Mejor nota recibida'),
                'last_eval'       => new external_value(PARAM_INT,   'Fecha de la ultima evaluacion'),
                'classes_count'   => new external_value(PARAM_INT,   'Clases distintas evaluadas'),
                'with_comments'   => new external_value(PARAM_INT,   'Cuantas traen comentario'),
                'dist'            => new external_value(PARAM_TEXT,  'Distribucion 1-5 separada por comas'),
                'low_sample'      => new external_value(PARAM_BOOL,  'Muestra insuficiente para comparar'),
                'needs_attention' => new external_value(PARAM_BOOL,  'Media baja con muestra suficiente'),
            ])),
            'kpis' => new external_single_structure([
                'sent'            => new external_value(PARAM_INT,   'Evaluaciones enviadas'),
                'dismissed'       => new external_value(PARAM_INT,   'Popups descartados'),
                'eligible'        => new external_value(PARAM_INT,   'Oportunidades elegibles del periodo'),
                'response_rate'   => new external_value(PARAM_FLOAT, '% respondido sobre lo atendido'),
                'coverage_rate'   => new external_value(PARAM_FLOAT, '% evaluado sobre lo elegible'),
                'teachers'        => new external_value(PARAM_INT,   'Docentes con evaluaciones'),
                'students'        => new external_value(PARAM_INT,   'Estudiantes que evaluaron'),
                'classes'         => new external_value(PARAM_INT,   'Clases con evaluaciones'),
                'avg_overall'     => new external_value(PARAM_FLOAT, 'Promedio institucional'),
                'avg_clarity'     => new external_value(PARAM_FLOAT, 'Promedio claridad'),
                'avg_punctuality' => new external_value(PARAM_FLOAT, 'Promedio puntualidad'),
                'with_comments'   => new external_value(PARAM_INT,   'Evaluaciones con comentario'),
                'dist'            => new external_value(PARAM_TEXT,  'Distribucion 1-5 separada por comas'),
                'min_sample'      => new external_value(PARAM_INT,   'Umbral de muestra representativa'),
                'attention_threshold' => new external_value(PARAM_FLOAT, 'Umbral de media baja'),
            ]),
            'trend' => new external_multiple_structure(new external_single_structure([
                'period' => new external_value(PARAM_TEXT,  'Mes AAAA-MM'),
                'total'  => new external_value(PARAM_INT,   'Evaluaciones del mes'),
                'avg'    => new external_value(PARAM_FLOAT, 'Promedio del mes'),
            ])),
        ]);
    }
}
