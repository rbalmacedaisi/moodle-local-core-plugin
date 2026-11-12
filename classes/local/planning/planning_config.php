<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Configuracion de la planificacion y generacion de horarios (pestana Configuracion de la linea de tiempo).
 *
 * Usa SOLO tablas existentes:
 *  - Periodos: gmk_academic_periods (inicio/fin) + gmk_academic_calendar (periodstart/periodend/block1start/block1end)
 *    + gmk_academic_periods.configsettings (JSON, clave 'jornada_starts': inicio de clases por jornada).
 *  - Reglas de agrupacion: config del plugin (planning_min_group, planning_max_group, planning_min_load).
 *  - Jornadas y horarios: scheduler_settings (la misma configuracion global del planificador).
 *  - Aulas: gmk_classrooms. Festivos: gmk_holidays (globales). Cargas: gmk_subject_loads (solo lectura aqui).
 *
 * Reglas de integridad en cada escritura: validacion previa, solo columnas que cambian, transaccion,
 * respaldo JSON con valores anteriores en moodledata/local_grupomakro_core/logs y relectura de lo guardado.
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Grupo Makro
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\planning;

use local_grupomakro_core\local\scheduler_settings;

defined('MOODLE_INTERNAL') || die();

class planning_config {

    const JORNADAS = ['Diurna', 'Nocturna', 'Sabatina'];
    const DEFAULT_RULES = ['min_group' => 12, 'max_group' => 30, 'min_load' => 3];

    /** Reglas de agrupacion vigentes. */
    public static function rules() {
        $out = [];
        foreach (self::DEFAULT_RULES as $k => $def) {
            $v = (int)get_config('local_grupomakro_core', 'planning_' . $k);
            $out[$k] = $v > 0 ? $v : $def;
        }
        return $out;
    }

    /**
     * Regla de mezcla de carreras (guardada en la configuracion del generador de horarios):
     *  - isolatedCareers: nombres de carreras cuyas asignaturas no se mezclan con otras carreras (ya existente).
     *  - isolatedSubjects: [{planid, courseid}] asignaturas puntuales de una carrera que no se mezclan.
     *
     * @return array ['plans' => [planid => true], 'subjects' => ['planid|courseid' => true],
     *                'careers' => [planid,...], 'subject_list' => [{planid, courseid}]]
     */
    public static function isolation() {
        global $DB;
        $cfg = scheduler_settings::get_config();
        $names = array_map('trim', (array)($cfg->isolatedCareers ?? []));
        $plans = [];
        if ($names) {
            foreach ($DB->get_records('local_learning_plans', null, '', 'id, name') as $p) {
                if (in_array(trim($p->name), $names, true)) {
                    $plans[(int)$p->id] = true;
                }
            }
        }
        $subjects = [];
        $list = [];
        foreach ((array)($cfg->isolatedSubjects ?? []) as $s) {
            $s = (array)$s;
            $pid = (int)($s['planid'] ?? 0);
            $cid = (int)($s['courseid'] ?? 0);
            if ($pid && $cid) {
                $subjects[$pid . '|' . $cid] = true;
                $list[] = ['planid' => $pid, 'courseid' => $cid];
            }
        }
        return ['plans' => $plans, 'subjects' => $subjects, 'careers' => array_keys($plans), 'subject_list' => $list];
    }

    /**
     * Excepciones de apertura del periodo (gmk_academic_periods.configsettings, clave 'quorum_exceptions'):
     * asignaturas que abren aunque no alcancen el minimo ('grupo') o que se dictan como modulo a estudiantes
     * puntuales ('modulo'). Clave: courseid|jornada|carrera aislada (0 = mezcla).
     *
     * @return array [key => ['key','courseid','course','jornada','isolated_plan','mode','userids','note','by','byname','time']]
     */
    public static function exceptions($periodid) {
        global $DB;
        $raw = $DB->get_field('gmk_academic_periods', 'configsettings', ['id' => $periodid]);
        $cfg = json_decode((string)$raw, true);
        $out = [];
        foreach ((is_array($cfg) ? ($cfg['quorum_exceptions'] ?? []) : []) as $e) {
            if (!empty($e['key'])) {
                $out[$e['key']] = $e;
            }
        }
        return $out;
    }

    public static function exception_key($courseid, $jornada, $isoplan) {
        return (int)$courseid . '|' . trim((string)$jornada) . '|' . (int)$isoplan;
    }

    /** Agrega/reemplaza o quita excepciones de un periodo (solo la columna configsettings). */
    protected static function save_exceptions(array $d, $remove) {
        global $DB, $USER;
        $pid = (int)($d['periodid'] ?? 0);
        $p = $DB->get_record('gmk_academic_periods', ['id' => $pid]);
        if (!$p) {
            return self::fail('El periodo no existe.');
        }
        $current = self::exceptions($pid);
        $before = $current;
        if ($remove) {
            foreach ((array)($d['keys'] ?? []) as $k) {
                unset($current[(string)$k]);
            }
        } else {
            $items = (array)($d['items'] ?? []);
            if (!$items) {
                return self::fail('No se eligió ninguna asignatura.');
            }
            $note = trim((string)($d['note'] ?? ''));
            if ($note === '') {
                return self::fail('Indique el motivo de la excepción: queda registrado para la revisión.');
            }
            // grupo: abre aunque no alcance el minimo; modulo: modulo sin horario ni pago para los elegidos;
            // cerrar: no abre aunque alcance el minimo (sus estudiantes quedan como alerta).
            $mode = in_array(($d['mode'] ?? ''), ['modulo', 'cerrar'], true) ? $d['mode'] : 'grupo';
            foreach ($items as $it) {
                $cid = (int)($it['courseid'] ?? 0);
                $course = $DB->get_field('course', 'fullname', ['id' => $cid]);
                if (!$course) {
                    return self::fail('Una de las asignaturas no existe.');
                }
                $jornada = trim((string)($it['jornada'] ?? ''));
                if ($jornada !== '' && $jornada !== 'Sin jornada' && !in_array($jornada, self::JORNADAS, true)) {
                    return self::fail('Jornada no válida: ' . $jornada);
                }
                $userids = array_values(array_unique(array_map('intval', (array)($it['userids'] ?? []))));
                if ($mode === 'modulo') {
                    if (!$userids) {
                        return self::fail('Para abrir como módulo elija al menos un estudiante de ' . $course . '.');
                    }
                    list($insql, $inparams) = $DB->get_in_or_equal($userids);
                    if ($DB->count_records_select('user', "id $insql AND deleted = 0", $inparams) !== count($userids)) {
                        return self::fail('Uno de los estudiantes elegidos no existe.');
                    }
                }
                // Juntar cohortes: estudiantes que la adelantan (clave usuario-plan; deben ser estudiantes activos de un
                // plan que tenga la asignatura) y marca de que la decision viene de juntar cohortes.
                $addkeys = [];
                foreach ((array)($it['add_keys'] ?? []) as $ak) {
                    if (!preg_match('/^(\d+)-(\d+)$/', (string)$ak, $mm)) {
                        return self::fail('Estudiante no válido en ' . $course . '.');
                    }
                    $ok = $DB->record_exists_sql("SELECT 1 FROM {local_learning_users} u
                        JOIN {local_learning_courses} c ON c.learningplanid = u.learningplanid AND c.courseid = ?
                        WHERE u.userid = ? AND u.learningplanid = ? AND u.userrolename = 'student' AND u.status = 'activo'",
                        [$cid, (int)$mm[1], (int)$mm[2]]);
                    if (!$ok) {
                        return self::fail('Uno de los estudiantes que adelantan ' . $course . ' no es estudiante activo de una carrera con esa asignatura.');
                    }
                    $addkeys[] = $mm[1] . '-' . $mm[2];
                }
                if ($addkeys && $mode !== 'grupo') {
                    return self::fail('Solo se pueden sumar estudiantes que adelantan cuando la asignatura abre como grupo.');
                }
                $key = self::exception_key($cid, $jornada, (int)($it['isolated_plan'] ?? 0));
                $current[$key] = ['key' => $key, 'courseid' => $cid, 'course' => $course, 'jornada' => $jornada,
                    'isolated_plan' => (int)($it['isolated_plan'] ?? 0), 'mode' => $mode, 'userids' => $userids,
                    'add_keys' => array_values(array_unique($addkeys)), 'pool' => !empty($d['pool']),
                    'note' => $note, 'by' => (int)$USER->id, 'byname' => fullname($USER), 'time' => time()];
            }
        }
        $cfg = json_decode((string)$p->configsettings, true);
        if (!is_array($cfg)) {
            $cfg = [];
        }
        if ($current) {
            $cfg['quorum_exceptions'] = array_values($current);
        } else {
            unset($cfg['quorum_exceptions']);
        }
        $tx = $DB->start_delegated_transaction();
        $DB->update_record('gmk_academic_periods', (object)['id' => $pid,
            'configsettings' => $cfg ? json_encode($cfg, JSON_UNESCAPED_UNICODE) : null,
            'usermodified' => $USER->id, 'timemodified' => time()]);
        $tx->allow_commit();
        self::backup('excepciones_periodo_' . $pid, ['antes' => array_values($before), 'despues' => array_values($current)]);
        return self::ok($remove ? 'Excepción quitada.' : 'Excepción registrada.', array_values(self::exceptions($pid)));
    }

    /**
     * Asignaturas no obligatorias en un periodo concreto: no se cargan por defecto, solo si se activan en el periodo.
     *  - auto: las de credito 0 (campo de curso 'credits').
     *  - add / remove: ajustes manuales por carrera y asignatura (config planning_flexible_subjects).
     *
     * @return array ['auto' => [courseid => true], 'add' => ['planid|courseid' => true], 'remove' => ['planid|courseid' => true]]
     */
    public static function flexible() {
        global $DB;
        $auto = [];
        $fid = (int)$DB->get_field('customfield_field', 'id', ['shortname' => 'credits']);
        if ($fid) {
            foreach ($DB->get_records_select('customfield_data', "fieldid = ? AND value = '0'", [$fid], '', 'id, instanceid') as $r) {
                $auto[(int)$r->instanceid] = true;
            }
        }
        $cfg = json_decode((string)get_config('local_grupomakro_core', 'planning_flexible_subjects'), true) ?: [];
        $add = [];
        $remove = [];
        foreach ((array)($cfg['add'] ?? []) as $k) {
            $add[(string)$k] = true;
        }
        foreach ((array)($cfg['remove'] ?? []) as $k) {
            $remove[(string)$k] = true;
        }
        return ['auto' => $auto, 'add' => $add, 'remove' => $remove];
    }

    public static function is_flexible(array $flex, $planid, $courseid) {
        $k = (int)$planid . '|' . (int)$courseid;
        return isset($flex['add'][$k]) || (isset($flex['auto'][(int)$courseid]) && !isset($flex['remove'][$k]));
    }

    /** Asignaturas no obligatorias activadas para un periodo: [courseid => true]. */
    public static function flexible_enabled($periodid) {
        global $DB;
        $cfg = json_decode((string)$DB->get_field('gmk_academic_periods', 'configsettings', ['id' => $periodid]), true);
        $out = [];
        foreach ((array)(is_array($cfg) ? ($cfg['flexible_enabled'] ?? []) : []) as $cid) {
            $out[(int)$cid] = true;
        }
        return $out;
    }

    /** Activa o desactiva una asignatura no obligatoria en un periodo (solo la columna configsettings). */
    protected static function toggle_flexible(array $d) {
        global $DB, $USER;
        $pid = (int)($d['periodid'] ?? 0);
        $cid = (int)($d['courseid'] ?? 0);
        $p = $DB->get_record('gmk_academic_periods', ['id' => $pid]);
        if (!$p || !$DB->record_exists('course', ['id' => $cid])) {
            return self::fail('Periodo o asignatura no válidos.');
        }
        $cfg = json_decode((string)$p->configsettings, true);
        if (!is_array($cfg)) {
            $cfg = [];
        }
        $list = array_map('intval', (array)($cfg['flexible_enabled'] ?? []));
        $before = $list;
        $list = array_values(array_diff($list, [$cid]));
        if (!empty($d['enabled'])) {
            $list[] = $cid;
        }
        if ($list) {
            $cfg['flexible_enabled'] = $list;
        } else {
            unset($cfg['flexible_enabled']);
        }
        $DB->update_record('gmk_academic_periods', (object)['id' => $pid,
            'configsettings' => $cfg ? json_encode($cfg, JSON_UNESCAPED_UNICODE) : null,
            'usermodified' => $USER->id, 'timemodified' => time()]);
        self::backup('no_obligatorias_periodo_' . $pid, ['antes' => $before, 'despues' => $list]);
        return self::ok(!empty($d['enabled']) ? 'Asignatura activada para este periodo.' : 'Asignatura desactivada para este periodo.', $list);
    }

    /** Asignaturas con docente de planta (costo fijo): no se incluyen en el gasto proyectado. [courseid => true] */
    public static function fixed_cost_subjects() {
        $out = [];
        foreach ((array)json_decode((string)get_config('local_grupomakro_core', 'planning_fixed_cost_subjects'), true) as $cid) {
            $out[(int)$cid] = true;
        }
        return $out;
    }

    /**
     * Asignaturas que se cargan sin horario (practica profesional, proyecto de grado): no tienen docente, aula ni
     * sesiones; abren siempre que haya estudiantes (sin minimo), en un solo grupo por asignatura que junta todas las
     * jornadas, no van al tablero de horarios ni al gasto. Si no se configuraron, se sugieren por el nombre.
     *
     * @return array ['configured' => bool, 'ids' => [courseid => true]]
     */
    public static function unscheduled_subjects() {
        global $DB;
        $raw = get_config('local_grupomakro_core', 'planning_unscheduled_subjects');
        $ids = [];
        if ($raw !== false && $raw !== null && $raw !== '') {
            foreach ((array)json_decode($raw, true) as $cid) {
                $ids[(int)$cid] = true;
            }
            return ['configured' => true, 'ids' => $ids];
        }
        foreach ($DB->get_records_sql("SELECT DISTINCT c.id, c.fullname FROM {course} c
                JOIN {local_learning_courses} l ON l.courseid = c.id") as $c) {
            if (preg_match('/PR[AÁ]CTICA\s+(PROFESIONAL|EMPRESARIAL)/iu', $c->fullname)) {
                $ids[(int)$c->id] = true;
            }
        }
        return ['configured' => false, 'ids' => $ids];
    }

    /** Minutos de una hora academica (config planning_hour_minutes; 45 por defecto): las cargas horarias estan en horas academicas. */
    public static function hour_minutes() {
        $v = (int)get_config('local_grupomakro_core', 'planning_hour_minutes');
        return $v > 0 ? $v : 45;
    }

    /** Semanas academicas de clase por bloque (sin festivos); la semana siguiente del bloque es de revalidas. */
    public static function class_weeks() {
        $v = (int)get_config('local_grupomakro_core', 'planning_class_weeks');
        return $v > 0 ? $v : 7;
    }

    /** Tarifa por hora docente para proyectar el gasto (config planning_hour_rate; 18 por defecto, igual que financial_planning). */
    public static function hour_rate() {
        $v = (float)get_config('local_grupomakro_core', 'planning_hour_rate');
        return $v > 0 ? $v : 18.0;
    }

    /**
     * Asignaturas de cuatrimestre completo (duran dos periodos, ~16 semanas).
     * Si no se configuraron, se toman del historial: dictadas al menos 2 veces en clases de mas de 75 dias y
     * mas veces largas que cortas.
     *
     * @return array ['configured' => bool, 'ids' => [courseid => true], 'suggested' => [['courseid','name','largas','cortas']]]
     */
    public static function long_subjects() {
        global $DB;
        $suggested = [];
        foreach ($DB->get_records_sql("SELECT c.corecourseid AS courseid, co.fullname AS name,
                SUM(CASE WHEN (c.enddate - c.initdate) > 75*86400 THEN 1 ELSE 0 END) AS largas,
                SUM(CASE WHEN (c.enddate - c.initdate) <= 75*86400 THEN 1 ELSE 0 END) AS cortas
              FROM {gmk_class} c JOIN {course} co ON co.id = c.corecourseid
             WHERE c.initdate > ? AND c.enddate > c.initdate AND c.enddate < c.initdate + 200*86400
          GROUP BY c.corecourseid, co.fullname", [time() - 400 * 86400]) as $r) {
            if ((int)$r->largas >= 2 && (int)$r->largas > (int)$r->cortas) {
                $suggested[] = ['courseid' => (int)$r->courseid, 'name' => $r->name, 'largas' => (int)$r->largas,
                    'cortas' => (int)$r->cortas];
            }
        }
        $raw = get_config('local_grupomakro_core', 'planning_long_subjects');
        $ids = [];
        if ($raw !== false && $raw !== null && $raw !== '') {
            foreach ((array)json_decode($raw, true) as $cid) {
                $ids[(int)$cid] = true;
            }
            return ['configured' => true, 'ids' => $ids, 'suggested' => $suggested];
        }
        foreach ($suggested as $s) {
            $ids[$s['courseid']] = true;
        }
        return ['configured' => false, 'ids' => $ids, 'suggested' => $suggested];
    }

    /**
     * Datos para proyectar el gasto mensual de un periodo: tarifa, horas por asignatura (cargas horarias), asignaturas
     * de cuatrimestre completo y clases aprobadas que siguen corriendo desde el periodo anterior.
     */
    public static function costing($periodid, $periodstart) {
        global $DB;
        $byname = [];
        foreach (scheduler_settings::get_loads() as $l) {
            $byname[self::norm($l->subjectname)] = (float)$l->total_hours;
        }
        $hours = [];
        foreach ($DB->get_records_sql("SELECT DISTINCT c.id, c.fullname FROM {course} c
                JOIN {local_learning_courses} l ON l.courseid = c.id") as $c) {
            $k = self::norm($c->fullname);
            if (isset($byname[$k]) && $byname[$k] > 0) {
                $hours[(int)$c->id] = $byname[$k];
            }
        }
        $long = self::long_subjects();
        // Clases aprobadas y abiertas de otros periodos que siguen corriendo cuando empieza este (cuatrimestrales).
        $continuing = [];
        foreach ($DB->get_records_select('gmk_class',
                'approved = 1 AND closed = 0 AND periodid <> ? AND enddate > ? AND initdate < ? AND (enddate - initdate) > ?',
                [$periodid, $periodstart + 7 * 86400, $periodstart, 75 * 86400],
                'id', 'id, name, corecourseid, shift, initdate, enddate') as $c) {
            $continuing[] = ['id' => (int)$c->id, 'name' => $c->name, 'courseid' => (int)$c->corecourseid,
                'shift' => (string)$c->shift, 'initdate' => (int)$c->initdate, 'enddate' => (int)$c->enddate,
                'hours' => $hours[(int)$c->corecourseid] ?? 0];
        }
        return ['rate' => self::hour_rate(), 'hours' => (object)$hours, 'long' => array_keys($long['ids']),
            'fixed' => array_keys(self::fixed_cost_subjects()), 'continuing' => $continuing,
            'unscheduled' => array_keys(self::unscheduled_subjects()['ids'])];
    }

    protected static function norm($s) {
        $s = \core_text::strtoupper(trim((string)$s));
        return preg_replace('/\s+/', ' ', rtrim($s, '. '));
    }

    /**
     * Propuesta de grupos guardada aparte en el periodo (configsettings.auto_proposal), para compararla con el
     * borrador del tablero de horarios sin tocarlo. El servidor la recalcula al guardar (no confia en el cliente).
     */
    public static function saved_proposal($periodid) {
        global $DB;
        $cfg = json_decode((string)$DB->get_field('gmk_academic_periods', 'configsettings', ['id' => $periodid]), true);
        return (is_array($cfg) && !empty($cfg['auto_proposal'])) ? $cfg['auto_proposal'] : null;
    }

    protected static function save_proposal(array $d, $remove) {
        global $DB, $USER;
        $pid = (int)($d['periodid'] ?? 0);
        $p = $DB->get_record('gmk_academic_periods', ['id' => $pid]);
        if (!$p) {
            return self::fail('El periodo no existe.');
        }
        $cfg = json_decode((string)$p->configsettings, true);
        if (!is_array($cfg)) {
            $cfg = [];
        }
        $before = $cfg['auto_proposal'] ?? null;
        if ($remove) {
            unset($cfg['auto_proposal']);
        } else {
            $res = (new demand_engine($pid))->compute();
            if (!empty($res['error'])) {
                return self::fail($res['error']);
            }
            $res['exceptions'] = array_values(self::exceptions($pid));
            $res['costing'] = self::costing($pid, (int)$res['period']['start']);
            $g = (new group_builder($res, self::rules()))->build();
            $groups = [];
            foreach ($g['groups'] as $gr) {
                $groups[] = ['courseid' => $gr['courseid'], 'name' => $gr['name'], 'jornada' => $gr['jornada'],
                    'isolated_plan' => $gr['isolated_plan'], 'type' => $gr['type'], 'number' => $gr['number'], 'of' => $gr['of'],
                    'userids' => array_map(function ($k) use ($res) {
                        return (int)$res['students'][$k]['userid'];
                    }, $gr['students']),
                    'adelantos' => array_map(function ($k) use ($res) {
                        return (int)$res['students'][$k]['userid'];
                    }, $gr['adel'])];
            }
            $cfg['auto_proposal'] = ['saved_at' => time(), 'by' => (int)$USER->id, 'byname' => fullname($USER),
                'rules' => self::rules(), 'summary' => $g['summary'], 'groups' => $groups];
        }
        $DB->update_record('gmk_academic_periods', (object)['id' => $pid,
            'configsettings' => $cfg ? json_encode($cfg, JSON_UNESCAPED_UNICODE) : null,
            'usermodified' => $USER->id, 'timemodified' => time()]);
        self::backup('propuesta_periodo_' . $pid, ['antes' => $before ? ['saved_at' => $before['saved_at'],
            'grupos' => count($before['groups'])] : null, 'accion' => $remove ? 'quitar' : 'guardar']);
        $saved = self::saved_proposal($pid);
        return self::ok($remove ? 'Propuesta guardada eliminada.' : 'Propuesta guardada: ' . count($saved['groups'])
            . ' grupos. El tablero de horarios se arma sobre ella.', $saved ? ['saved_at' => $saved['saved_at'],
            'groups' => count($saved['groups'])] : null);
    }

    /** Inicio de clases por jornada guardado en el periodo (o []). */
    public static function jornada_starts($period) {
        $cfg = json_decode((string)($period->configsettings ?? ''), true);
        $out = [];
        if (is_array($cfg) && !empty($cfg['jornada_starts']) && is_array($cfg['jornada_starts'])) {
            foreach (self::JORNADAS as $j) {
                if (!empty($cfg['jornada_starts'][$j])) {
                    $out[$j] = (int)$cfg['jornada_starts'][$j];
                }
            }
        }
        return $out;
    }

    /** Todo lo que muestra la pestana de configuracion. */
    public static function get() {
        global $DB;
        $periods = [];
        $cals = [];
        foreach ($DB->get_records('gmk_academic_calendar') as $c) {
            $cals[(int)$c->academicperiodid] = $c;
        }
        foreach ($DB->get_records('gmk_academic_periods', null, 'startdate DESC') as $p) {
            $c = $cals[(int)$p->id] ?? null;
            $periods[] = [
                'id' => (int)$p->id, 'name' => $p->name, 'status' => (int)$p->status,
                'startdate' => (int)$p->startdate, 'enddate' => (int)$p->enddate,
                'block1start' => $c ? (int)$c->block1start : 0, 'block1end' => $c ? (int)$c->block1end : 0,
                'has_calendar' => (bool)$c,
                'jornada_starts' => (object)self::jornada_starts($p),
            ];
        }
        $inuse = [];
        foreach ($DB->get_records_sql("SELECT DISTINCT classroomid FROM {gmk_class} WHERE classroomid > 0") as $r) {
            $inuse[(int)$r->classroomid] = true;
        }
        foreach ($DB->get_records_sql("SELECT DISTINCT classroomid FROM {gmk_class_schedules} WHERE classroomid > 0") as $r) {
            $inuse[(int)$r->classroomid] = true;
        }
        $classrooms = [];
        foreach ($DB->get_records('gmk_classrooms', null, 'name ASC') as $r) {
            $classrooms[] = ['id' => (int)$r->id, 'name' => $r->name, 'capacity' => (int)$r->capacity,
                'type' => (string)$r->type, 'active' => (int)$r->active, 'in_use' => isset($inuse[(int)$r->id])];
        }
        $holidays = [];
        foreach (scheduler_settings::get_holidays() as $h) {
            $holidays[] = ['id' => (int)$h->id, 'date' => (int)$h->date, 'name' => (string)$h->name, 'type' => (string)$h->type];
        }
        $loads = [];
        foreach (scheduler_settings::get_loads() as $l) {
            $loads[] = ['subject' => $l->subjectname, 'total_hours' => (float)$l->total_hours, 'intensity' => (float)$l->intensity];
        }
        // Carreras activas y sus asignaturas, para la regla de mezcla.
        $careers = [];
        $excluded = \local_grupomakro_core\local\academic_progress::EXCLUDED_PLANS;
        foreach ($DB->get_records_sql(
                "SELECT lp.id, lp.name FROM {local_learning_plans} lp
                  WHERE EXISTS (SELECT 1 FROM {local_learning_users} u WHERE u.learningplanid = lp.id
                                   AND u.userrolename = 'student' AND u.status = 'activo')
               ORDER BY lp.name") as $lp) {
            if (in_array((int)$lp->id, $excluded, true)) {
                continue;
            }
            $courses = [];
            foreach ($DB->get_records_sql(
                    "SELECT llc.courseid, c.fullname FROM {local_learning_courses} llc JOIN {course} c ON c.id = llc.courseid
                      WHERE llc.learningplanid = ? ORDER BY c.fullname", [$lp->id]) as $c) {
                $courses[] = ['courseid' => (int)$c->courseid, 'name' => $c->fullname];
            }
            $careers[] = ['id' => (int)$lp->id, 'name' => $lp->name, 'courses' => $courses];
        }
        $iso = self::isolation();
        $flex = self::flexible();
        $autolist = [];
        if ($flex['auto']) {
            list($insql, $inparams) = $DB->get_in_or_equal(array_keys($flex['auto']));
            foreach ($DB->get_records_select('course', "id $insql", $inparams, 'fullname', 'id, fullname') as $c) {
                $autolist[] = ['courseid' => (int)$c->id, 'name' => $c->fullname];
            }
        }
        $long = self::long_subjects();
        $longlist = [];
        if ($long['ids']) {
            list($insql, $inparams) = $DB->get_in_or_equal(array_keys($long['ids']));
            foreach ($DB->get_records_select('course', "id $insql", $inparams, 'fullname', 'id, fullname') as $c) {
                $longlist[] = ['courseid' => (int)$c->id, 'name' => $c->fullname];
            }
        }
        $fixedlist = [];
        if ($fx = self::fixed_cost_subjects()) {
            list($insql, $inparams) = $DB->get_in_or_equal(array_keys($fx));
            foreach ($DB->get_records_select('course', "id $insql", $inparams, 'fullname', 'id, fullname') as $c) {
                $fixedlist[] = ['courseid' => (int)$c->id, 'name' => $c->fullname];
            }
        }
        $unsched = self::unscheduled_subjects();
        $unschedlist = [];
        if ($unsched['ids']) {
            list($insql, $inparams) = $DB->get_in_or_equal(array_keys($unsched['ids']));
            foreach ($DB->get_records_select('course', "id $insql", $inparams, 'fullname', 'id, fullname') as $c) {
                $unschedlist[] = ['courseid' => (int)$c->id, 'name' => $c->fullname];
            }
        }
        $allcourses = [];
        foreach ($DB->get_records_sql("SELECT DISTINCT c.id, c.fullname FROM {course} c
                JOIN {local_learning_courses} l ON l.courseid = c.id ORDER BY c.fullname") as $c) {
            $allcourses[] = ['courseid' => (int)$c->id, 'name' => $c->fullname];
        }
        // Docentes para la regla de "docente fijo" de una asignatura.
        global $CFG;
        require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
        $teachers = [];
        foreach (list_instructors() as $i) {
            if (empty($i->suspended)) {
                $teachers[] = ['id' => (int)$i->id, 'name' => trim(preg_replace('/\s+/', ' ', $i->fullname))];
            }
        }
        usort($teachers, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
        return [
            'teachers' => $teachers,
            'costs' => ['hour_rate' => self::hour_rate(), 'long' => $longlist, 'fixed' => $fixedlist, 'long_configured' => $long['configured'],
                'long_suggested' => $long['suggested'], 'all_courses' => $allcourses,
                'unscheduled' => $unschedlist, 'unscheduled_configured' => $unsched['configured']],
            'flexible' => ['auto' => $autolist, 'add' => array_keys($flex['add']), 'remove' => array_keys($flex['remove'])],
            'periods' => $periods,
            'rules' => self::rules(),
            'careers' => $careers,
            'isolation' => ['careers' => $iso['careers'], 'subjects' => $iso['subject_list']],
            'scheduler' => scheduler_settings::get_config(),
            'classrooms' => $classrooms,
            'holidays' => $holidays,
            'loads' => $loads,
        ];
    }

    /**
     * Guarda una seccion. Devuelve ['ok' => bool, 'message' => string, 'saved' => lo releido].
     *
     * @param string $section periods | rules | scheduler | classroom | classroom_delete | holiday | holiday_delete
     * @param array $data
     */
    public static function save($section, array $data) {
        global $DB, $USER;
        $now = time();
        switch ($section) {
            case 'period':
                return self::save_period($data);

            case 'exception_add':
                return self::save_exceptions($data, false);

            case 'exception_remove':
                return self::save_exceptions($data, true);

            case 'flex_toggle':
                return self::toggle_flexible($data);

            case 'proposal_save':
                return self::save_proposal($data, false);

            case 'proposal_remove':
                return self::save_proposal($data, true);

            case 'board_save':
                return schedule_board::save($data);

            case 'publish_plan':
                $plan = publisher::plan((int)($data['periodid'] ?? 0));
                return $plan['ok'] ? self::ok('Revisión de la publicación (no se escribió nada).', $plan) : self::fail($plan['message']);

            case 'publish':
                return publisher::publish($data);

            case 'board_clear':
                return schedule_board::save(['clear' => true] + $data);

            case 'teacher_skill_add':
                return teacher_admin::skill($data, true);

            case 'teacher_skill_remove':
                return teacher_admin::skill($data, false);

            case 'teacher_availability':
                return teacher_admin::availability($data);

            case 'costs':
                // Tarifa por hora y asignaturas de cuatrimestre completo.
                $rate = (float)($data['hour_rate'] ?? 0);
                if ($rate <= 0 || $rate > 500) {
                    return self::fail('La tarifa por hora debe estar entre 0 y 500.');
                }
                $ids = [];
                foreach ((array)($data['long'] ?? []) as $cid) {
                    $cid = (int)$cid;
                    if (!$DB->record_exists('course', ['id' => $cid])) {
                        return self::fail('Una de las asignaturas de cuatrimestre completo no existe.');
                    }
                    $ids[] = $cid;
                }
                $fixed = [];
                foreach ((array)($data['fixed'] ?? []) as $cid) {
                    $cid = (int)$cid;
                    if (!$DB->record_exists('course', ['id' => $cid])) {
                        return self::fail('Una de las asignaturas con docente de planta no existe.');
                    }
                    $fixed[] = $cid;
                }
                // Sin horario: solo si viene en los datos (una pantalla vieja no la borra).
                $unsched = null;
                if (array_key_exists('unscheduled', $data)) {
                    $unsched = [];
                    foreach ((array)$data['unscheduled'] as $cid) {
                        $cid = (int)$cid;
                        if (!$DB->record_exists('course', ['id' => $cid])) {
                            return self::fail('Una de las asignaturas sin horario no existe.');
                        }
                        $unsched[] = $cid;
                    }
                    if (array_intersect($unsched, $ids)) {
                        return self::fail('Una asignatura no puede ser a la vez sin horario y de cuatrimestre completo.');
                    }
                }
                $before = ['rate' => self::hour_rate(), 'long' => array_keys(self::long_subjects()['ids']),
                    'fixed' => array_keys(self::fixed_cost_subjects()), 'unscheduled' => array_keys(self::unscheduled_subjects()['ids'])];
                set_config('planning_hour_rate', $rate, 'local_grupomakro_core');
                set_config('planning_long_subjects', json_encode(array_values(array_unique($ids))), 'local_grupomakro_core');
                set_config('planning_fixed_cost_subjects', json_encode(array_values(array_unique($fixed))), 'local_grupomakro_core');
                if ($unsched !== null) {
                    set_config('planning_unscheduled_subjects', json_encode(array_values(array_unique($unsched))), 'local_grupomakro_core');
                }
                self::backup('costos_duracion', ['antes' => $before, 'despues' => ['rate' => $rate, 'long' => $ids, 'fixed' => $fixed,
                    'unscheduled' => $unsched]]);
                return self::ok('Costos y duración guardados.', ['rate' => self::hour_rate(), 'long' => array_keys(self::long_subjects()['ids']),
                    'fixed' => array_keys(self::fixed_cost_subjects()), 'unscheduled' => array_keys(self::unscheduled_subjects()['ids'])]);

            case 'flexible':
                // Ajustes manuales de no obligatorias: 'add' marca, 'remove' desmarca una de credito 0.
                $clean = ['add' => [], 'remove' => []];
                foreach (['add', 'remove'] as $kind) {
                    foreach ((array)($data[$kind] ?? []) as $k) {
                        list($pid, $cid) = array_map('intval', explode('|', (string)$k) + [0, 0]);
                        if (!$pid || !$cid || !$DB->record_exists('local_learning_courses', ['learningplanid' => $pid, 'courseid' => $cid])) {
                            return self::fail('Una de las asignaturas elegidas no pertenece a la carrera indicada.');
                        }
                        $clean[$kind][] = $pid . '|' . $cid;
                    }
                    $clean[$kind] = array_values(array_unique($clean[$kind]));
                }
                $before = get_config('local_grupomakro_core', 'planning_flexible_subjects');
                set_config('planning_flexible_subjects', json_encode($clean), 'local_grupomakro_core');
                self::backup('no_obligatorias', ['antes' => json_decode((string)$before, true), 'despues' => $clean]);
                return self::ok('Asignaturas no obligatorias guardadas.', $clean);

            case 'rules':
                $min = (int)($data['min_group'] ?? 0);
                $max = (int)($data['max_group'] ?? 0);
                $load = (int)($data['min_load'] ?? 0);
                if ($min < 1 || $max < $min || $max > 200 || $load < 1 || $load > 10) {
                    return self::fail('Revise los valores: el mínimo debe ser al menos 1, el máximo no puede ser menor que el mínimo '
                        . 'y la carga mínima debe estar entre 1 y 10.');
                }
                $before = self::rules();
                set_config('planning_min_group', $min, 'local_grupomakro_core');
                set_config('planning_max_group', $max, 'local_grupomakro_core');
                set_config('planning_min_load', $load, 'local_grupomakro_core');
                self::backup('reglas', ['antes' => $before, 'despues' => self::rules()]);
                return self::ok('Reglas de agrupación guardadas.', self::rules());

            case 'scheduler':
                $cfg = $data['config'] ?? null;
                if (!is_array($cfg) || empty($cfg['shiftWindows'])) {
                    return self::fail('Configuración de jornadas incompleta.');
                }
                foreach ($cfg['shiftWindows'] as $j => $w) {
                    if (!self::is_time($w['start'] ?? '') || !self::is_time($w['end'] ?? '') || $w['start'] >= $w['end']) {
                        return self::fail("La franja de la jornada $j no es válida (la hora de inicio debe ser menor que la de fin).");
                    }
                    // Dias preferidos de la jornada (preferencia, no obligatorio): 1 = lunes ... 6 = sabado.
                    if (isset($w['preferredDays'])) {
                        $pd = array_values(array_unique(array_map('intval', (array)$w['preferredDays'])));
                        if (array_diff($pd, [1, 2, 3, 4, 5, 6])) {
                            return self::fail("Días preferidos no válidos en la jornada $j.");
                        }
                        sort($pd);
                        $cfg['shiftWindows'][$j]['preferredDays'] = $pd;
                    }
                }
                foreach (['startTime', 'endTime', 'lunchStart', 'lunchEnd'] as $k) {
                    if (isset($cfg[$k]) && $cfg[$k] !== '' && !self::is_time($cfg[$k])) {
                        return self::fail("Hora no válida en $k.");
                    }
                }
                $before = scheduler_settings::get_config();
                // Conserva las claves que esta pantalla no edita y que se guardan por su cuenta (mezcla de carreras y
                // preferencias por asignatura): lo que venga del navegador para esas claves se ignora, para no pisar
                // cambios hechos despues de abrir la pantalla.
                unset($cfg['isolatedCareers'], $cfg['isolatedSubjects'], $cfg['subjectPreferences']);
                $merged = array_merge(json_decode(json_encode($before), true) ?: [], $cfg);
                if (!scheduler_settings::save_config($merged)) {
                    return self::fail('No se pudo guardar la configuración de jornadas.');
                }
                self::backup('jornadas', ['antes' => $before, 'despues' => scheduler_settings::get_config()]);
                return self::ok('Jornadas y horarios guardados.', scheduler_settings::get_config());

            case 'subject_pref_set':
            case 'subject_pref_remove':
                return self::save_subject_pref($data, $section === 'subject_pref_remove');

            case 'isolation':
                $careerids = array_values(array_unique(array_map('intval', (array)($data['careers'] ?? []))));
                $subjects = [];
                foreach ((array)($data['subjects'] ?? []) as $s) {
                    $pid = (int)($s['planid'] ?? 0);
                    $cid = (int)($s['courseid'] ?? 0);
                    if (!$pid || !$cid) {
                        continue;
                    }
                    if (!$DB->record_exists('local_learning_courses', ['learningplanid' => $pid, 'courseid' => $cid])) {
                        return self::fail('Una de las asignaturas elegidas no pertenece a la carrera indicada.');
                    }
                    // 'plan' (nombre) es como el tablero de horarios identifica la carrera.
                    $subjects[$pid . '|' . $cid] = ['planid' => $pid, 'courseid' => $cid,
                        'plan' => (string)$DB->get_field('local_learning_plans', 'name', ['id' => $pid])];
                }
                $names = [];
                foreach ($careerids as $pid) {
                    $n = $DB->get_field('local_learning_plans', 'name', ['id' => $pid]);
                    if ($n === false) {
                        return self::fail('Una de las carreras elegidas no existe.');
                    }
                    $names[] = $n;
                }
                $before = scheduler_settings::get_config();
                $merged = json_decode(json_encode($before), true) ?: [];
                $merged['isolatedCareers'] = $names;
                $merged['isolatedSubjects'] = array_values($subjects);
                if (!scheduler_settings::save_config($merged)) {
                    return self::fail('No se pudo guardar la regla de mezcla.');
                }
                self::backup('mezcla_carreras', ['antes' => ['isolatedCareers' => $before->isolatedCareers ?? [],
                    'isolatedSubjects' => $before->isolatedSubjects ?? []], 'despues' => ['isolatedCareers' => $names,
                    'isolatedSubjects' => array_values($subjects)]]);
                $iso = self::isolation();
                return self::ok('Regla de mezcla de carreras guardada.', ['careers' => $iso['careers'], 'subjects' => $iso['subject_list']]);

            case 'classroom':
                $name = trim((string)($data['name'] ?? ''));
                $cap = (int)($data['capacity'] ?? 0);
                if ($name === '' || $cap < 1 || $cap > 500) {
                    return self::fail('El aula necesita nombre y una capacidad entre 1 y 500.');
                }
                $rec = ['name' => $name, 'capacity' => $cap, 'type' => trim((string)($data['type'] ?? 'general')) ?: 'general',
                    'active' => empty($data['active']) ? 0 : 1];
                $id = (int)($data['id'] ?? 0);
                $dup = $DB->get_record_select('gmk_classrooms', 'LOWER(name) = LOWER(?) AND id <> ?', [$name, $id], 'id', IGNORE_MULTIPLE);
                if ($dup) {
                    return self::fail('Ya existe un aula con ese nombre.');
                }
                if ($id) {
                    $old = $DB->get_record('gmk_classrooms', ['id' => $id], '*', MUST_EXIST);
                    $upd = self::changed($old, $rec);
                    if (!$upd) {
                        return self::ok('Sin cambios.', (array)$old);
                    }
                    $upd['id'] = $id;
                    $upd['usermodified'] = $USER->id;
                    $upd['timemodified'] = $now;
                    $DB->update_record('gmk_classrooms', (object)$upd);
                    self::backup('aula', ['antes' => $old, 'cambios' => $upd]);
                } else {
                    $id = $DB->insert_record('gmk_classrooms', (object)($rec + ['usermodified' => $USER->id,
                        'timecreated' => $now, 'timemodified' => $now]));
                    self::backup('aula', ['nueva' => $id, 'datos' => $rec]);
                }
                return self::ok('Aula guardada.', (array)$DB->get_record('gmk_classrooms', ['id' => $id]));

            case 'classroom_delete':
                $id = (int)($data['id'] ?? 0);
                $old = $DB->get_record('gmk_classrooms', ['id' => $id]);
                if (!$old) {
                    return self::fail('El aula no existe.');
                }
                if ($DB->record_exists_select('gmk_class', 'classroomid = ?', [$id])
                        || $DB->record_exists_select('gmk_class_schedules', 'classroomid = ?', [$id])) {
                    return self::fail('Esta aula tiene clases u horarios asignados y no se puede borrar. Desactívela para que no se use en nuevos horarios.');
                }
                $DB->delete_records('gmk_classrooms', ['id' => $id]);
                self::backup('aula_borrada', ['antes' => $old]);
                return self::ok('Aula eliminada.', null);

            case 'holiday':
                $date = (int)($data['date'] ?? 0);
                $name = trim((string)($data['name'] ?? ''));
                if ($date <= 0 || $name === '') {
                    return self::fail('El festivo necesita fecha y nombre.');
                }
                $day = date('Y-m-d', $date);
                foreach (scheduler_settings::get_holidays() as $h) {
                    if ($h->formatted_date === $day && (int)$h->id !== (int)($data['id'] ?? 0)) {
                        return self::fail("Ya hay un festivo registrado el $day ({$h->name}).");
                    }
                }
                $rec = ['academicperiodid' => scheduler_settings::GLOBAL_SCOPE, 'date' => $date, 'name' => $name,
                    'type' => trim((string)($data['type'] ?? 'feriado')) ?: 'feriado'];
                $id = (int)($data['id'] ?? 0);
                if ($id) {
                    $old = $DB->get_record('gmk_holidays', ['id' => $id], '*', MUST_EXIST);
                    $upd = self::changed($old, $rec);
                    if ($upd) {
                        $upd += ['id' => $id, 'usermodified' => $USER->id, 'timemodified' => $now];
                        $DB->update_record('gmk_holidays', (object)$upd);
                        self::backup('festivo', ['antes' => $old, 'cambios' => $upd]);
                    }
                } else {
                    $id = $DB->insert_record('gmk_holidays', (object)($rec + ['usermodified' => $USER->id,
                        'timecreated' => $now, 'timemodified' => $now]));
                    self::backup('festivo', ['nuevo' => $id, 'datos' => $rec]);
                }
                return self::ok('Festivo guardado.', (array)$DB->get_record('gmk_holidays', ['id' => $id]));

            case 'holiday_delete':
                $id = (int)($data['id'] ?? 0);
                $old = $DB->get_record('gmk_holidays', ['id' => $id]);
                if (!$old) {
                    return self::fail('El festivo no existe.');
                }
                $DB->delete_records('gmk_holidays', ['id' => $id]);
                self::backup('festivo_borrado', ['antes' => $old]);
                return self::ok('Festivo eliminado.', null);
        }
        return self::fail('Sección desconocida.');
    }

    /**
     * Fechas de un periodo: inicio/fin del periodo y del bloque de clases, e inicio por jornada.
     * Escribe solo columnas que cambian, en transaccion, con respaldo.
     */
    protected static function save_period(array $d) {
        global $DB, $USER;
        $id = (int)($d['id'] ?? 0);
        $p = $DB->get_record('gmk_academic_periods', ['id' => $id]);
        if (!$p) {
            return self::fail('El periodo no existe.');
        }
        $start = (int)($d['startdate'] ?? 0);
        $end = (int)($d['enddate'] ?? 0);
        if ($start <= 0 || $end <= $start) {
            return self::fail('La fecha de fin del periodo debe ser posterior a la de inicio.');
        }
        $js = [];
        foreach (self::JORNADAS as $j) {
            $v = (int)($d['jornada_starts'][$j] ?? 0);
            if ($v) {
                if (abs($v - $start) > 21 * 86400) {
                    return self::fail("El inicio de la jornada $j debe estar a menos de 3 semanas del inicio del periodo.");
                }
                $js[$j] = $v;
            }
        }
        $now = time();
        $tx = $DB->start_delegated_transaction();
        $before = ['periodo' => $p, 'calendario' => $DB->get_record('gmk_academic_calendar', ['academicperiodid' => $id])];

        $cfg = json_decode((string)$p->configsettings, true);
        if (!is_array($cfg)) {
            $cfg = [];
        }
        if ($js) {
            $cfg['jornada_starts'] = $js;
        } else {
            unset($cfg['jornada_starts']);
        }
        $newcfg = $cfg ? json_encode($cfg, JSON_UNESCAPED_UNICODE) : null;
        $upd = self::changed($p, ['startdate' => $start, 'enddate' => $end]);
        if ((string)$p->configsettings !== (string)$newcfg) {
            $upd['configsettings'] = $newcfg;
        }
        if ($upd) {
            $upd += ['id' => $id, 'usermodified' => $USER->id, 'timemodified' => $now];
            $DB->update_record('gmk_academic_periods', (object)$upd);
        }
        // Calendario: el bloque de clases arranca con la jornada que empieza primero.
        $classstart = $js ? min(array_merge([$start], array_values($js))) : $start;
        $cal = $before['calendario'];
        if ($cal) {
            $cupd = self::changed($cal, ['periodstart' => $start, 'periodend' => $end, 'block1start' => $classstart]);
            if ($cupd) {
                $cupd += ['id' => $cal->id, 'usermodified' => $USER->id, 'timemodified' => $now];
                $DB->update_record('gmk_academic_calendar', (object)$cupd);
            }
        }
        $tx->allow_commit();
        self::backup('periodo_' . $id, ['antes' => $before, 'cambios_periodo' => $upd, 'cambios_calendario' => $cupd ?? null]);
        $saved = null;
        foreach (self::get()['periods'] as $row) {
            if ($row['id'] === $id) {
                $saved = $row;
            }
        }
        return self::ok($upd || !empty($cupd) ? 'Periodo guardado.' : 'Sin cambios.', $saved);
    }

    /**
     * Preferencias de dia y hora por asignatura (y opcionalmente por carrera), en la configuracion del generador de
     * horarios (clave subjectPreferences). Son preferencias: si no se pueden cumplir se usa otro dia u hora con aviso.
     *
     * @return array [['courseid', 'planid' (0 = todas), 'days' => [1..6], 'start' => 'HH:MM'|'', 'end' => 'HH:MM'|'']]
     */
    public static function subject_prefs() {
        $cfg = scheduler_settings::get_config();
        $out = [];
        foreach ((array)($cfg->subjectPreferences ?? []) as $p) {
            $p = (array)$p;
            $out[] = ['courseid' => (int)($p['courseid'] ?? 0), 'planid' => (int)($p['planid'] ?? 0),
                'jornada' => (string)($p['jornada'] ?? ''),
                'days' => array_values(array_map('intval', (array)($p['days'] ?? []))),
                'start' => (string)($p['start'] ?? ''), 'end' => (string)($p['end'] ?? ''),
                // Regla (no solo preferencia): obligatoria, su franja vale para todas las jornadas, docente fijo,
                // veces por semana, y asignaturas que el mismo docente puede dictar en paralelo (hasta un maximo).
                'strict' => !empty($p['strict']), 'override_window' => !empty($p['override_window']),
                'first_slot' => !empty($p['first_slot']),
                'teacherid' => (int)($p['teacherid'] ?? 0), 'times_per_week' => (int)($p['times_per_week'] ?? 0),
                'parallel' => array_values(array_map('intval', (array)($p['parallel'] ?? []))),
                'parallel_max' => (int)($p['parallel_max'] ?? 0) ?: 4];
        }
        return $out;
    }

    protected static function save_subject_pref(array $d, $remove) {
        global $DB;
        $cid = (int)($d['courseid'] ?? 0);
        $pid = (int)($d['planid'] ?? 0);
        $jor = trim((string)($d['jornada'] ?? ''));
        $course = $DB->get_field('course', 'fullname', ['id' => $cid]);
        if (!$course) {
            return self::fail('La asignatura no existe.');
        }
        if ($jor !== '' && !in_array($jor, self::JORNADAS, true)) {
            return self::fail('Jornada no válida: ' . $jor);
        }
        if ($pid && !$DB->record_exists('local_learning_courses', ['learningplanid' => $pid, 'courseid' => $cid])) {
            return self::fail('La asignatura no pertenece a esa carrera.');
        }
        $list = array_values(array_filter(self::subject_prefs(), function ($p) use ($cid, $pid, $jor) {
            return !($p['courseid'] === $cid && $p['planid'] === $pid && $p['jornada'] === $jor);
        }));
        $item = null;
        // Lo que no venga en los datos conserva su valor (p. ej. guardar dia y franja desde el tablero no borra el
        // docente fijo ni el paralelo definidos en Configuracion).
        $prev = null;
        foreach (self::subject_prefs() as $p) {
            if ($p['courseid'] === $cid && $p['planid'] === $pid && $p['jornada'] === $jor) {
                $prev = $p;
            }
        }
        foreach (['strict', 'override_window', 'first_slot', 'teacherid', 'times_per_week', 'parallel', 'parallel_max'] as $f) {
            if (!array_key_exists($f, $d) && $prev) {
                $d[$f] = $prev[$f];
            }
        }
        if (!$remove) {
            $days = array_values(array_unique(array_map('intval', (array)($d['days'] ?? []))));
            sort($days);
            if (array_diff($days, [1, 2, 3, 4, 5, 6])) {
                return self::fail('Días no válidos.');
            }
            $start = trim((string)($d['start'] ?? ''));
            $end = trim((string)($d['end'] ?? ''));
            if (($start !== '' && !self::is_time($start)) || ($end !== '' && !self::is_time($end))
                    || ($start !== '' && $end !== '' && $start >= $end) || (($start === '') !== ($end === ''))) {
                return self::fail('Indique la franja con hora de inicio y de fin (la de fin mayor), o déjela vacía.');
            }
            if (!$days && $start === '' && empty($d['first_slot'])) {
                return self::fail('Elija al menos un día, una franja horaria o "empieza a primera hora".');
            }
            $tid = (int)($d['teacherid'] ?? 0);
            if ($tid && !$DB->record_exists('user', ['id' => $tid, 'deleted' => 0])) {
                return self::fail('El docente fijo no existe.');
            }
            $times = (int)($d['times_per_week'] ?? 0);
            if ($times < 0 || $times > 5) {
                return self::fail('Las veces por semana deben estar entre 0 (automático) y 5.');
            }
            $parallel = array_values(array_unique(array_map('intval', (array)($d['parallel'] ?? []))));
            foreach ($parallel as $pc) {
                if (!$DB->record_exists('course', ['id' => $pc])) {
                    return self::fail('Una de las asignaturas en paralelo no existe.');
                }
            }
            $pmax = (int)($d['parallel_max'] ?? 4) ?: 4;
            if ($pmax < 1 || $pmax > 10) {
                return self::fail('El máximo de grupos en paralelo debe estar entre 1 y 10.');
            }
            if (!empty($d['override_window']) && $start === '') {
                return self::fail('Para que la franja de la asignatura valga en todas las jornadas, indique la franja.');
            }
            $item = ['courseid' => $cid, 'planid' => $pid, 'jornada' => $jor, 'days' => $days, 'start' => $start, 'end' => $end,
                'strict' => !empty($d['strict']), 'override_window' => !empty($d['override_window']),
                'first_slot' => !empty($d['first_slot']), 'teacherid' => $tid,
                'times_per_week' => $times, 'parallel' => array_values(array_diff($parallel, [$cid])), 'parallel_max' => $pmax];
            $list[] = $item;
        }
        $before = scheduler_settings::get_config();
        $merged = json_decode(json_encode($before), true) ?: [];
        if ($list) {
            $merged['subjectPreferences'] = $list;
        } else {
            unset($merged['subjectPreferences']); // sin reglas: la configuracion queda como estaba
        }
        if (!scheduler_settings::save_config($merged)) {
            return self::fail('No se pudo guardar la preferencia.');
        }
        self::backup('preferencia_asignatura', ['antes' => $before->subjectPreferences ?? [], 'despues' => $list]);
        return self::ok($remove ? 'Preferencia de ' . $course . ' quitada.' : 'Preferencia de ' . $course . ' guardada.', self::subject_prefs());
    }

    /** Columnas de $new que difieren de $old. */
    protected static function changed($old, array $new) {
        $out = [];
        foreach ($new as $k => $v) {
            if ((string)($old->$k ?? '') !== (string)$v) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    protected static function is_time($t) {
        return is_string($t) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t);
    }

    /** Respaldo JSON para otras clases de la planificacion. */
    public static function log_backup($what, $payload) {
        self::backup($what, $payload);
    }

    protected static function backup($what, $payload) {
        global $CFG, $USER;
        $dir = $CFG->dataroot . '/local_grupomakro_core/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        @file_put_contents($dir . '/config_planificacion_' . date('Ymd_His') . '_' . $what . '.json',
            json_encode(['usuario' => (int)$USER->id, 'hora' => date('c'), 'datos' => $payload], JSON_UNESCAPED_UNICODE));
    }

    protected static function ok($msg, $saved) {
        return ['ok' => true, 'message' => $msg, 'saved' => $saved];
    }

    protected static function fail($msg) {
        return ['ok' => false, 'message' => $msg, 'saved' => null];
    }
}
