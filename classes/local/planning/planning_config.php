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
            $mode = ($d['mode'] ?? '') === 'modulo' ? 'modulo' : 'grupo';
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
                $key = self::exception_key($cid, $jornada, (int)($it['isolated_plan'] ?? 0));
                $current[$key] = ['key' => $key, 'courseid' => $cid, 'course' => $course, 'jornada' => $jornada,
                    'isolated_plan' => (int)($it['isolated_plan'] ?? 0), 'mode' => $mode, 'userids' => $userids,
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
        return [
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
                }
                foreach (['startTime', 'endTime', 'lunchStart', 'lunchEnd'] as $k) {
                    if (isset($cfg[$k]) && $cfg[$k] !== '' && !self::is_time($cfg[$k])) {
                        return self::fail("Hora no válida en $k.");
                    }
                }
                $before = scheduler_settings::get_config();
                // Conserva las claves que esta pantalla no edita (p. ej. isolatedCareers).
                $merged = array_merge(json_decode(json_encode($before), true) ?: [], $cfg);
                if (!scheduler_settings::save_config($merged)) {
                    return self::fail('No se pudo guardar la configuración de jornadas.');
                }
                self::backup('jornadas', ['antes' => $before, 'despues' => scheduler_settings::get_config()]);
                return self::ok('Jornadas y horarios guardados.', scheduler_settings::get_config());

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
