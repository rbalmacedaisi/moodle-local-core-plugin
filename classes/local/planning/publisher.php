<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Publicacion del tablero de horarios de la linea de tiempo (fase D). SOLO AGREGA:
 *
 *  - Grupos del tablero: crea cada clase como la crea el Planificador Academico al publicar (gmk_class sin aprobar,
 *    estudiantes en la cola gmk_class_queue, sesion en gmk_class_schedules con sus fechas, y luego grupo, seccion y
 *    actividades de Moodle). La aprobacion (que inscribe a los estudiantes) sigue siendo del area academica.
 *    Nunca modifica ni borra clases existentes. Si falla la creacion de las estructuras de Moodle de una clase, esa
 *    clase se borra completa (como create_class) y se informa.
 *  - Modulos decididos en la proyeccion y asignaturas sin horario (practica profesional): por el mismo flujo de
 *    modulos (enroll_module) sin pago, en el periodo planificado y con plazo al fin del bloque de clases.
 *
 * Lo publicado queda registrado en gmk_academic_periods.configsettings['board_published'] para no repetirlo.
 * Antes de escribir se recalcula todo en el servidor (choques duros incluidos) y se exige que el borrador del tablero
 * sea exactamente la version que el usuario reviso.
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Grupo Makro
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\planning;

use local_grupomakro_core\local\scheduler_settings;

defined('MOODLE_INTERNAL') || die();

class publisher {

    /** Nombres de dia como los guarda gmk_class_schedules (sin tilde). */
    const DAYKEY = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miercoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sabado', 7 => 'Domingo'];

    /** Lo ya publicado: ['groups' => [key => classid], 'modules' => [courseid => [userid => classid]]]. */
    public static function published($periodid) {
        global $DB;
        $cfg = json_decode((string)$DB->get_field('gmk_academic_periods', 'configsettings', ['id' => $periodid]), true);
        $p = is_array($cfg) && !empty($cfg['board_published']) ? $cfg['board_published'] : [];
        return ['groups' => (array)($p['groups'] ?? []), 'modules' => (array)($p['modules'] ?? [])];
    }

    protected static function save_published($periodid, array $pub) {
        global $DB, $USER;
        $cfg = json_decode((string)$DB->get_field('gmk_academic_periods', 'configsettings', ['id' => $periodid]), true);
        if (!is_array($cfg)) {
            $cfg = [];
        }
        $cfg['board_published'] = ['groups' => (object)$pub['groups'], 'modules' => (object)$pub['modules'], 'updated' => time()];
        $DB->update_record('gmk_academic_periods', (object)['id' => $periodid,
            'configsettings' => json_encode($cfg, JSON_UNESCAPED_UNICODE), 'usermodified' => $USER->id, 'timemodified' => time()]);
    }

    /** Fechas de clase por jornada: inicio de la jornada y fin del bloque (o de los dos bloques si es cuatrimestral). */
    public static function dates($period, $calendar, $jornada, $long) {
        $js = planning_config::jornada_starts($period);
        $start = (int)($js[$jornada] ?? 0);
        if (!$start) {
            $start = $calendar && $calendar->block1start ? (int)$calendar->block1start : (int)$period->startdate;
        }
        if ($long) {
            $end = $calendar && $calendar->block2end ? (int)$calendar->block2end : $start + 16 * 7 * 86400 - 1;
        } else {
            $end = $calendar && $calendar->block1end ? (int)$calendar->block1end : $start + 8 * 7 * 86400 - 1;
        }
        return [usergetmidnight($start), usergetmidnight($end) + 86399];
    }

    /**
     * Calendario de clases de una jornada: por dia de la semana, las primeras N semanas academicas (planning_config::
     * class_weeks, 7 por defecto) sin contar festivos, desde el inicio de la jornada y antes de la ultima semana del
     * bloque, que es de revalidas. Las de cuatrimestre completo suman las del segundo bloque.
     *
     * @return array [desde, hasta, [dia => [Y-m-d, ...]]]
     */
    public static function class_calendar($period, $calendar, $jornada, $long, array $holidays) {
        $weeks = planning_config::class_weeks();
        list($from) = self::dates($period, $calendar, $jornada, false);
        $b1end = $calendar && $calendar->block1end ? (int)$calendar->block1end : $from + 8 * 7 * 86400 - 1;
        $blocks = [[$from, $b1end - 7 * 86400]];
        if ($long) {
            $b2start = $calendar && $calendar->block2start ? (int)$calendar->block2start : $b1end + 1;
            $b2end = $calendar && $calendar->block2end ? (int)$calendar->block2end : $b2start + 8 * 7 * 86400 - 1;
            $blocks[] = [$b2start, $b2end - 7 * 86400];
        }
        $days = [];
        foreach ([1, 2, 3, 4, 5, 6] as $dn) {
            $days[$dn] = [];
            foreach ($blocks as $b) {
                $days[$dn] = array_merge($days[$dn], array_slice(self::session_dates($dn, $b[0], $b[1], 0, 0, $holidays), 0, $weeks));
            }
        }
        $all = array_merge(...array_values($days));
        sort($all);
        $to = $all ? strtotime(end($all) . ' 23:59:59') : $b1end;
        return [$from, $to, $days];
    }

    /** Fechas (Y-m-d) del dia de la semana entre inicio y fin, sin festivos, hasta cubrir las horas de la carga. */
    public static function session_dates($day, $from, $to, $minutes, $hours, array $holidays) {
        $out = [];
        $d = usergetmidnight($from);
        while ((int)date('N', $d) !== (int)$day) {
            $d = strtotime('+1 day', $d);
        }
        for (; $d <= $to; $d = strtotime('+7 days', $d)) {
            $ymd = date('Y-m-d', $d);
            if (!isset($holidays[$ymd])) {
                $out[] = $ymd;
            }
        }
        if ($hours > 0 && $minutes > 0) {
            $need = (int)ceil($hours * 60 / $minutes);
            if ($need > 0 && count($out) > $need) {
                $out = array_slice($out, 0, $need);
            }
        }
        return $out;
    }

    /**
     * Plan de publicacion (SOLO LECTURA): que se crearia, que esta bloqueado y por que.
     *
     * @return array ['ok', 'message', 'version', 'groups' => [...], 'modules' => [...], 'unscheduled' => [...], 'totals' => [...]]
     */
    public static function plan($periodid) {
        global $DB;
        $period = $DB->get_record('gmk_academic_periods', ['id' => $periodid]);
        if (!$period) {
            return ['ok' => false, 'message' => 'El periodo no existe.'];
        }
        $saved = planning_config::saved_proposal($periodid);
        if (!$saved) {
            return ['ok' => false, 'message' => 'No hay propuesta de grupos guardada.'];
        }
        $draft = schedule_board::draft($periodid);
        if (!$draft['version']) {
            return ['ok' => false, 'message' => 'El tablero de horarios no tiene un borrador guardado.'];
        }
        $calendar = $DB->get_record('gmk_academic_calendar', ['academicperiodid' => $periodid]);
        $res = (new demand_engine($periodid))->compute();
        if (!empty($res['error'])) {
            return ['ok' => false, 'message' => $res['error']];
        }
        $res['exceptions'] = array_values(planning_config::exceptions($periodid));
        $res['costing'] = planning_config::costing($periodid, (int)$res['period']['start']);
        $board = schedule_board::load($periodid, $res);
        $pub = self::published($periodid);
        $cfg = $board['config'];
        $pad = max(0, (int)$cfg['interval']);
        $holidays = [];
        foreach (scheduler_settings::get_holidays() as $h) {
            $holidays[date('Y-m-d', (int)$h->date)] = $h->name;
        }
        $teachers = [];
        foreach ($board['teachers'] as $t) {
            $teachers[$t['id']] = $t;
        }
        $rooms = [];
        foreach ($board['rooms'] as $r) {
            $rooms[$r['id']] = $r;
        }
        $students = [];
        foreach ($res['students'] as $s) {
            $students[(int)$s['userid']][] = $s;
        }
        // Ocupacion: clases existentes (menos las que publico este tablero, que se cuentan como grupos) + grupos.
        $pubclass = array_flip(array_map('intval', array_values($pub['groups'])));
        $occ = [];
        foreach ($board['fixed'] as $f) {
            if (isset($pubclass[(int)$f['id']])) {
                continue;
            }
            foreach ($f['sessions'] as $x) {
                $occ[] = ['id' => 'c' . $f['id'], 'name' => $f['name'], 'day' => $x['day'], 'start' => $x['start'], 'end' => $x['end'],
                    'teacherid' => (int)$f['teacherid'], 'roomid' => (int)$x['roomid'], 'users' => array_flip($f['userids']),
                    'from' => (int)$f['initdate'], 'to' => (int)$f['enddate']];
            }
        }
        // Cada grupo tiene una o varias fichas (partes semanales); cada ficha, sus fechas exactas (semana de inicio + sesiones).
        $rows = [];
        $cards = [];
        foreach ($board['groups'] as $g) {
            list($from, $to) = self::dates($period, $calendar, $g['jornada'], $g['long']);
            $cal = $board['calendars'][$g['cal']]['days'] ?? [];
            $row = ['key' => $g['key'], 'courseid' => $g['courseid'], 'name' => $g['name'], 'jornada' => $g['jornada'],
                'number' => $g['number'], 'of' => $g['of'], 'size' => $g['size'], 'userids' => $g['userids'], 'levels' => $g['levels'],
                'plans' => $g['plans'], 'long' => $g['long'], 'hours' => $g['hours'], 'from' => $from, 'to' => $to,
                'cards' => [], 'day' => 0, 'start' => 0, 'end' => 0, 'teacherid' => 0, 'roomid' => 0, 'teacher' => '', 'room' => '',
                'dates' => [], 'classid' => (int)($pub['groups'][$g['key']] ?? 0), 'blocks' => [], 'warnings' => []];
            if ($row['classid'] && !$DB->record_exists('gmk_class', ['id' => $row['classid']])) {
                $row['warnings'][] = 'Se había publicado como clase ' . $row['classid'] . ' pero esa clase ya no existe (fue borrada); no se vuelve a crear sola.';
            }
            $tids = [];
            foreach ($g['parts'] as $pi => $pt) {
                $it = $draft['items'][$pt['key']] ?? null;
                $day = (int)($it['day'] ?? 0);
                $nses = (int)($it['sessions'] ?? 0) ?: (int)$pt['sessions'];
                $week = (int)($it['week'] ?? 0);
                $all = $day ? array_values((array)($cal[$day] ?? ($cal[(string)$day] ?? []))) : [];
                $dates = $nses > 0 ? array_slice($all, $week, $nses) : $all;
                $card = ['key' => $pt['key'], 'part' => $pi + 1, 'parts' => count($g['parts']), 'day' => $day,
                    'start' => (int)($it['start'] ?? 0), 'end' => (int)($it['end'] ?? 0), 'week' => $week, 'sessions' => $nses,
                    'teacherid' => (int)($it['teacherid'] ?? 0), 'roomid' => (int)($it['roomid'] ?? 0), 'dates' => $dates];
                if (!$day) {
                    $row['blocks'][] = (count($g['parts']) > 1 ? 'La parte ' . ($pi + 1) . ' no tiene' : 'No tiene') . ' horario en el tablero.';
                } else if ($nses > 0 && count($dates) < $nses) {
                    $row['blocks'][] = 'Desde la semana ' . ($week + 1) . ' solo caben ' . count($dates) . ' de ' . $nses . ' sesiones.';
                }
                if ($card['teacherid']) {
                    $tids[$card['teacherid']] = true;
                }
                $row['cards'][] = $card;
                $cards[] = ['row' => $g['key'], 'card' => $card];
            }
            if (count($tids) > 1) {
                $row['blocks'][] = 'Las partes de la asignatura tienen docentes distintos: debe ser el mismo docente.';
            }
            $first = $row['cards'][0];
            $row['day'] = $first['day'];
            $row['start'] = $first['start'];
            $row['end'] = $first['end'];
            $row['teacherid'] = $tids ? (int)array_key_first($tids) : 0;
            $row['roomid'] = $first['roomid'];
            $row['teacher'] = $row['teacherid'] && isset($teachers[$row['teacherid']]) ? $teachers[$row['teacherid']]['name'] : '';
            $row['room'] = $row['roomid'] && isset($rooms[$row['roomid']]) ? $rooms[$row['roomid']]['name'] : '';
            $alldates = [];
            foreach ($row['cards'] as $c) {
                $alldates = array_merge($alldates, $c['dates']);
            }
            sort($alldates);
            $row['dates'] = $alldates;
            $rows[$g['key']] = $row;
        }
        // Choques duros (servidor, independiente de la pantalla), ficha por ficha con sus fechas exactas.
        $ymd = function ($d) {
            return (int)strtotime($d . ' 00:00:00');
        };
        $entries = $occ;
        foreach ($cards as $x) {
            $c = $x['card'];
            if ($c['day'] && $c['dates']) {
                $r = $rows[$x['row']];
                $entries[] = ['id' => $c['key'], 'row' => $x['row'], 'card' => true, 'name' => $r['name'] . ' G' . $r['number']
                    . ($c['parts'] > 1 ? ' (' . $c['part'] . '/' . $c['parts'] . ')' : ''), 'day' => $c['day'], 'start' => $c['start'], 'end' => $c['end'],
                    'teacherid' => $c['teacherid'], 'roomid' => $c['roomid'], 'users' => array_flip($r['userids']),
                    'from' => $ymd($c['dates'][0]), 'to' => $ymd(end($c['dates'])) + 86400];
            }
        }
        $sw = json_decode(json_encode($cfg['shiftWindows']), true) ?: [];
        foreach ($cards as $x) {
            $k = $x['row'];
            $r = $rows[$k];
            $c = $x['card'];
            if (!$c['day'] || !$c['dates']) {
                continue;
            }
            $w = isset($sw[$r['jornada']]) ? (array)$sw[$r['jornada']] : null;
            $days = stripos($r['jornada'], 'sab') !== false ? [6] : [1, 2, 3, 4, 5];
            if (!in_array($c['day'], $days, true)) {
                $rows[$k]['blocks'][] = 'El día no corresponde a la jornada ' . $r['jornada'] . '.';
            } else if ($w && ($c['start'] < schedule_board::to_min($w['start']) || $c['end'] > schedule_board::to_min($w['end']))) {
                $rows[$k]['blocks'][] = 'Fuera de la franja de la jornada ' . $r['jornada'] . '.';
            }
            $cfrom = $ymd($c['dates'][0]);
            $cto = $ymd(end($c['dates'])) + 86400;
            foreach ($entries as $o) {
                if ($o['id'] === $c['key'] || $o['day'] !== $c['day'] || !($c['start'] < $o['end'] + $pad && $o['start'] < $c['end'] + $pad)) {
                    continue;
                }
                if (!empty($o['card'])) {
                    if (!($cfrom < $o['to'] && $o['from'] < $cto)) {
                        continue; // fichas en semanas distintas de la misma franja: no chocan
                    }
                } else if ($o['from'] && $o['to'] && !($cfrom < $o['to'] - 7 * 86400 && $o['from'] < $cto - 7 * 86400)) {
                    continue;
                }
                $n = count(array_intersect_key(array_flip($r['userids']), $o['users']));
                if ($n) {
                    $rows[$k]['blocks'][] = $n . ' estudiante(s) también tienen ' . $o['name'] . ' a esa hora.';
                }
                if ($c['teacherid'] && $o['teacherid'] === $c['teacherid']) {
                    $rows[$k]['blocks'][] = 'El docente ya dicta ' . $o['name'] . ' a esa hora.';
                }
                if ($c['roomid'] && $o['roomid'] === $c['roomid']) {
                    $rows[$k]['blocks'][] = 'El aula está ocupada por ' . $o['name'] . ' a esa hora.';
                }
            }
        }
        foreach ($rows as $k => $r) {
            if (!$r['day']) {
                continue;
            }
            if (!$r['teacherid']) {
                $rows[$k]['warnings'][] = 'Sin docente: se asigna después en la clase.';
            } else {
                $t = $teachers[$r['teacherid']] ?? null;
                if ($t && !in_array($r['courseid'], $t['skills'], true)) {
                    $rows[$k]['warnings'][] = $r['teacher'] . ' no tiene registrada la habilidad.';
                }
            }
            $mins = 0;
            foreach ($r['cards'] as $c) {
                if (!$c['roomid']) {
                    $rows[$k]['warnings'][] = 'Sin aula.';
                } else if (isset($rooms[$c['roomid']]) && $rooms[$c['roomid']]['capacity'] < $r['size']) {
                    $rows[$k]['warnings'][] = 'El aula es chica para el grupo.';
                }
                $mins += count($c['dates']) * ($c['end'] - $c['start']);
            }
            if (!$r['dates']) {
                $rows[$k]['blocks'][] = 'No hay fechas de clase para ese día en el periodo.';
            } else if ($r['hours'] > 0 && $mins < $r['hours'] * 60) {
                $rows[$k]['warnings'][] = 'Las sesiones suman ' . round($mins / 60, 1) . ' h y la carga es de ' . $r['hours'] . ' h.';
            }
        }
        foreach ($rows as $k => $r) {
            $rows[$k]['blocks'] = array_values(array_unique($r['blocks']));
            $rows[$k]['warnings'] = array_values(array_unique($r['warnings']));
            $rows[$k]['status'] = $r['classid'] ? 'publicado' : ($rows[$k]['blocks'] ? 'bloqueado' : 'listo');
        }

        // Modulos (decididos en la proyeccion) y asignaturas sin horario.
        $modules = [];
        list(, $modend) = self::dates($period, $calendar, '', false);
        foreach (planning_config::exceptions($periodid) as $e) {
            if ($e['mode'] !== 'modulo') {
                continue;
            }
            $modules[] = self::module_row('modulo', (int)$e['courseid'], $e['course'], $e['jornada'], $e['userids'], $pub, $modend, $e['note']);
        }
        $unsched = [];
        foreach ($saved['groups'] as $g) {
            if (($g['type'] ?? '') === 'sin_horario') {
                $unsched[] = self::module_row('sin_horario', (int)$g['courseid'], $g['name'], '', $g['userids'], $pub, $modend, '');
            }
        }
        $tot = ['listo' => 0, 'bloqueado' => 0, 'publicado' => 0, 'queue' => 0];
        foreach ($rows as $r) {
            $tot[$r['status']]++;
            if ($r['status'] === 'listo') {
                $tot['queue'] += $r['size'];
            }
        }
        return ['ok' => true, 'message' => '', 'version' => $draft['version'], 'groups' => array_values($rows),
            'modules' => $modules, 'unscheduled' => $unsched, 'totals' => $tot, 'module_due' => $modend];
    }

    /** Filas de avance (gmk_course_progre) de una asignatura, para comparar antes/despues. */
    protected static function progress_rows($courseid) {
        global $DB;
        $out = [];
        foreach ($DB->get_records('gmk_course_progre', ['courseid' => (int)$courseid], 'id') as $r) {
            $out[(int)$r->id] = ['userid' => (int)$r->userid, 'status' => (int)$r->status, 'grade' => (string)$r->grade,
                'progress' => (string)$r->progress, 'classid' => (int)$r->classid, 'groupid' => (int)$r->groupid,
                'grade_at' => (int)($r->grade_at ?? 0), 'grade_source' => (string)($r->grade_source ?? '')];
        }
        return $out;
    }

    protected static function module_row($kind, $courseid, $name, $jornada, array $userids, array $pub, $due, $note) {
        $done = (array)($pub['modules'][$courseid] ?? []);
        $pending = array_values(array_filter(array_map('intval', $userids), function ($u) use ($done) {
            return !isset($done[$u]) && !isset($done[(string)$u]);
        }));
        return ['kind' => $kind, 'courseid' => $courseid, 'name' => $name, 'jornada' => $jornada, 'userids' => array_map('intval', $userids),
            'pending' => $pending, 'done' => count($userids) - count($pending), 'due' => $due, 'note' => $note];
    }

    /**
     * Publica. $d = ['periodid', 'version', 'keys' => [claves de grupos], 'modules' => [courseid,...],
     * 'unscheduled' => [courseid,...]]. Se llama por tandas desde la pantalla.
     */
    public static function publish(array $d) {
        global $DB, $USER, $CFG;
        require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
        require_once($CFG->dirroot . '/group/lib.php');
        require_once($CFG->dirroot . '/course/lib.php');
        $pid = (int)($d['periodid'] ?? 0);
        $plan = self::plan($pid);
        if (!$plan['ok']) {
            return ['ok' => false, 'message' => $plan['message'], 'saved' => null];
        }
        if ((int)($d['version'] ?? -1) !== (int)$plan['version']) {
            return ['ok' => false, 'message' => 'El tablero cambió después de revisar la publicación. Revise de nuevo antes de publicar.', 'saved' => null];
        }
        $keys = array_values(array_unique(array_map('strval', (array)($d['keys'] ?? []))));
        $byKey = [];
        foreach ($plan['groups'] as $g) {
            $byKey[$g['key']] = $g;
        }
        // Validacion completa antes de escribir nada.
        foreach ($keys as $k) {
            if (!isset($byKey[$k])) {
                return ['ok' => false, 'message' => 'Un grupo pedido no está en el tablero (' . $k . ').', 'saved' => null];
            }
            if ($byKey[$k]['status'] !== 'listo') {
                return ['ok' => false, 'message' => $byKey[$k]['name'] . ' G' . $byKey[$k]['number'] . ' no está listo para publicar: '
                    . implode(' ', $byKey[$k]['blocks'] ?: ['ya publicado']), 'saved' => null];
            }
        }
        $modcourses = array_map('intval', (array)($d['modules'] ?? []));
        $unscourses = array_map('intval', (array)($d['unscheduled'] ?? []));
        if (($modcourses || $unscourses) && !has_capability('local/grupomakro_core:manage_modules', \context_system::instance())) {
            return ['ok' => false, 'message' => 'Para publicar módulos se necesita el permiso de gestión de módulos.', 'saved' => null];
        }
        $period = $DB->get_record('gmk_academic_periods', ['id' => $pid], '*', MUST_EXIST);
        $pub = self::published($pid);
        $report = ['created' => [], 'failed' => [], 'modules' => [], 'progress_changed' => []];
        $studentsall = [];
        foreach ((new demand_engine($pid))->compute()['students'] as $s) {
            $studentsall[(int)$s['userid']][] = $s;
        }
        foreach ($keys as $k) {
            $g = $byKey[$k];
            try {
                // Control: crear la clase no inscribe a nadie, pero Moodle recalcula las notas del curso al crear sus
                // actividades y eso dispara la sincronizacion de "ultima nota". Se compara el avance de TODO el curso
                // antes y despues; si algo cambia se informa con detalle y queda en el respaldo.
                $before = self::progress_rows((int)$g['courseid']);
                $classid = self::create_group_class($period, $g, $studentsall);
                $after = self::progress_rows((int)$g['courseid']);
                $changed = [];
                foreach ($after as $id => $row) {
                    if (!isset($before[$id]) || $before[$id] !== $row) {
                        $changed[$id] = ['antes' => $before[$id] ?? null, 'despues' => $row];
                    }
                }
                foreach (array_diff_key($before, $after) as $id => $row) {
                    $changed[$id] = ['antes' => $row, 'despues' => null];
                }
                if ($changed) {
                    $report['progress_changed'][] = ['key' => $k, 'classid' => $classid, 'course' => $g['name'], 'rows' => $changed];
                }
                $pub['groups'][$k] = $classid;
                self::save_published($pid, $pub);
                $report['created'][] = ['key' => $k, 'classid' => $classid, 'name' => $DB->get_field('gmk_class', 'name', ['id' => $classid]),
                    'queue' => count($g['userids'])];
            } catch (\Throwable $e) {
                $report['failed'][] = ['key' => $k, 'name' => $g['name'] . ' G' . $g['number'], 'error' => $e->getMessage()];
            }
        }
        // Modulos y sin horario: flujo de modulos sin pago, en el periodo planificado.
        $rowsets = array_merge(
            array_filter($plan['modules'], function ($m) use ($modcourses) {
                return in_array((int)$m['courseid'], $modcourses, true);
            }),
            array_filter($plan['unscheduled'], function ($m) use ($unscourses) {
                return in_array((int)$m['courseid'], $unscourses, true);
            }));
        foreach ($rowsets as $m) {
            foreach ($m['pending'] as $uid) {
                $planid = 0;
                foreach ($studentsall[$uid] ?? [] as $s) {
                    foreach ($s['subjects'] as $x) {
                        if ((int)$x['courseid'] === (int)$m['courseid']) {
                            $planid = (int)$s['planid'];
                        }
                    }
                }
                try {
                    $r = \local_grupomakro_core\external\schedule\enroll_module::execute($uid, (int)$m['courseid'], $planid, true, $pid, (int)$m['due']);
                } catch (\Throwable $e) {
                    $r = ['status' => 'error', 'message' => $e->getMessage()];
                }
                if (($r['status'] ?? '') === 'ok') {
                    $cid = (int)$DB->get_field('gmk_class', 'id', ['is_module' => 1, 'corecourseid' => $m['courseid'], 'periodid' => $pid], IGNORE_MULTIPLE);
                    $pub['modules'][$m['courseid']][$uid] = $cid;
                    self::save_published($pid, $pub);
                }
                $u = $DB->get_record('user', ['id' => $uid], 'id, firstname, lastname');
                $report['modules'][] = ['courseid' => $m['courseid'], 'course' => $m['name'], 'userid' => $uid,
                    'name' => $u ? fullname($u) : ('#' . $uid), 'status' => $r['status'] ?? 'error', 'message' => strip_tags((string)($r['message'] ?? ''))];
            }
        }
        planning_config::log_backup('publicacion_periodo_' . $pid, $report);
        $okmods = count(array_filter($report['modules'], function ($x) {
            return $x['status'] === 'ok';
        }));
        $msg = count($report['created']) . ' clase(s) creada(s) pendientes de aprobación';
        if ($report['failed']) {
            $msg .= '; ' . count($report['failed']) . ' no se pudieron crear (no quedó nada a medias)';
        }
        if ($report['progress_changed']) {
            $n = array_sum(array_map(function ($x) {
                return count($x['rows']);
            }, $report['progress_changed']));
            $msg .= '; ATENCIÓN: al crear las actividades Moodle recalculó notas y cambió ' . $n
                . ' registro(s) de avance de esas asignaturas (detalle abajo y en el respaldo)';
        }
        if ($report['modules']) {
            $msg .= '; ' . $okmods . ' de ' . count($report['modules']) . ' inscripciones en módulos';
        }
        return ['ok' => true, 'message' => $msg . '.', 'saved' => $report];
    }

    /** Crea una clase del tablero como el Planificador Academico (sin aprobar, con cola), y sus estructuras de Moodle. */
    protected static function create_group_class($period, array $g, array $studentsall) {
        global $DB, $USER;
        $pid = (int)$period->id;
        // Plan (local_learning_courses) de la mayoria del grupo que tiene la asignatura.
        $count = [];
        foreach ($g['userids'] as $u) {
            foreach ($studentsall[(int)$u] ?? [] as $s) {
                foreach ($s['subjects'] as $x) {
                    if ((int)$x['courseid'] === (int)$g['courseid'] && !empty($x['llcid'])) {
                        $count[(int)$x['llcid']] = ($count[(int)$x['llcid']] ?? 0) + 1;
                    }
                }
            }
        }
        arsort($count);
        $llcid = (int)key($count);
        $llc = $llcid ? $DB->get_record('local_learning_courses', ['id' => $llcid]) : null;
        if (!$llc) {
            $llc = $DB->get_record('local_learning_courses', ['courseid' => $g['courseid']], '*', IGNORE_MULTIPLE);
        }
        if (!$llc) {
            throw new \moodle_exception('La asignatura no está en ningún plan.');
        }
        $course = $DB->get_record('course', ['id' => $g['courseid']], 'id, fullname', MUST_EXIST);
        $planname = (string)$DB->get_field('local_learning_plans', 'name', ['id' => $llc->learningplanid]);
        $now = time();
        $a = schedule_board::to_hhmm($g['start']);
        $b = schedule_board::to_hhmm($g['end']);
        $mask = [0, 0, 0, 0, 0, 0, 0];
        foreach ($g['cards'] as $card) {
            $mask[$card['day'] - 1] = 1;
        }
        // La clase va de su primera a su ultima sesion (una asignatura corta no ocupa todo el bloque).
        $initdate = usergetmidnight(strtotime($g['dates'][0] . ' 00:00:00'));
        $enddate = usergetmidnight(strtotime(end($g['dates']) . ' 00:00:00')) + 86399;
        $levels = [];
        foreach ($g['levels'] as $l => $n) {
            $levels[] = $l . ' (' . $n . ')';
        }
        $c = new \stdClass();
        $c->name = $course->fullname;
        $c->type = 0;
        $c->typelabel = 'Presencial';
        $c->learningplanid = (int)$llc->learningplanid;
        $c->periodid = $pid;
        $c->courseid = (int)$llc->id;
        $c->corecourseid = (int)$course->id;
        $c->instructorid = (int)$g['teacherid'];
        $c->instructorlpid = $g['teacherid'] ? (int)$DB->get_field('local_learning_users', 'id',
            ['userid' => $g['teacherid'], 'learningplanid' => $llc->learningplanid], IGNORE_MULTIPLE) : 0;
        $c->inittime = $a;
        $c->endtime = $b;
        $c->inithourformatted = date('h:i A', strtotime($a));
        $c->endhourformatted = date('h:i A', strtotime($b));
        $c->inittimets = $g['start'] * 60;
        $c->endtimets = $g['end'] * 60;
        $c->classduration = ($g['end'] - $g['start']) * 60;
        $c->classdays = implode('/', $mask);
        $c->classroomid = $g['roomid'] ?: null;
        $c->classroomcapacity = $g['roomid'] ? (int)$DB->get_field('gmk_classrooms', 'capacity', ['id' => $g['roomid']]) : 0;
        $c->initdate = $initdate;
        $c->enddate = $enddate;
        $c->subperiodid = 0;
        $c->shift = $g['jornada'];
        $c->level_label = implode(', ', $levels);
        $c->career_label = count($g['plans']) > 1 ? $planname . ' y otras ' . (count($g['plans']) - 1) . ' carrera(s)' : $planname;
        $c->approved = 0;
        $c->closed = 0;
        $c->is_module = 0;
        $c->groupid = 0;
        $c->coursesectionid = 0;
        $c->usermodified = (int)$USER->id;
        $c->timecreated = $now;
        $c->timemodified = $now;
        $c->name = build_class_group_name($c);

        // Fase 1 (transaccion): clase, cola de estudiantes y sesion con sus fechas.
        $tx = $DB->start_delegated_transaction();
        $c->id = $DB->insert_record('gmk_class', $c);
        foreach ($g['userids'] as $u) {
            $DB->insert_record('gmk_class_queue', (object)['classid' => $c->id, 'userid' => (int)$u, 'courseid' => (int)$llc->id,
                'timecreated' => $now, 'timemodified' => $now, 'usermodified' => (int)$USER->id]);
        }
        // Una sesion semanal por ficha, con sus propias fechas.
        foreach ($g['cards'] as $card) {
            $DB->insert_record('gmk_class_schedules', (object)['classid' => $c->id, 'day' => self::DAYKEY[$card['day']],
                'start_time' => schedule_board::to_hhmm($card['start']), 'end_time' => schedule_board::to_hhmm($card['end']),
                'classroomid' => $card['roomid'] ?: null, 'excluded_dates' => null,
                'assigned_dates' => json_encode(array_values($card['dates'])), 'usermodified' => (int)$USER->id, 'timecreated' => $now,
                'timemodified' => $now]);
        }
        $tx->allow_commit();

        // Fase 2: estructuras de Moodle (grupo, seccion, actividades). Si algo falla, la clase se borra completa.
        try {
            $c->groupid = create_class_group($c);
            $DB->set_field('gmk_class', 'groupid', $c->groupid, ['id' => $c->id]);
            $c->coursesectionid = create_class_section($c);
            $DB->set_field('gmk_class', 'coursesectionid', $c->coursesectionid, ['id' => $c->id]);
            $fresh = $DB->get_record('gmk_class', ['id' => $c->id], '*', MUST_EXIST);
            create_class_activities($fresh, false);
            $reason = '';
            if (!gmk_is_valid_class_attendance_module($DB->get_record('gmk_class', ['id' => $c->id]), $reason)) {
                throw new \moodle_exception('No se creó la asistencia de la clase: ' . $reason);
            }
        } catch (\Throwable $e) {
            try {
                delete_class($c->id, 'Publicación del tablero: falló la creación de estructuras');
            } catch (\Throwable $e2) {
                $DB->delete_records('gmk_class_queue', ['classid' => $c->id]);
                $DB->delete_records('gmk_class_schedules', ['classid' => $c->id]);
                $DB->delete_records('gmk_class', ['id' => $c->id]);
            }
            throw $e;
        }
        return (int)$c->id;
    }
}
