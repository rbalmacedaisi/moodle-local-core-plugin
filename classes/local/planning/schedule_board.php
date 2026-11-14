<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Tablero de planificacion de horarios (linea de tiempo, pestana Proyeccion).
 *
 * Parte de la propuesta de grupos guardada del periodo (configsettings.auto_proposal) y guarda su borrador APARTE en
 * gmk_academic_periods.configsettings (clave 'board_draft'). No lee ni escribe el borrador del Planificador Academico
 * (draft_schedules) ni crea clases: publicar es una fase posterior con su propia aprobacion.
 *
 * Ocupacion fija (solo lectura): clases ya creadas del periodo y clases de otros periodos que siguen corriendo en
 * sus fechas (cuatrimestrales), con sus estudiantes, docente, aula y sesiones.
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Grupo Makro
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\planning;

use local_grupomakro_core\local\scheduler_settings;

defined('MOODLE_INTERNAL') || die();

class schedule_board {

    /** Columnas de disponibilidad por dia (1 = lunes ... 7 = domingo). */
    const DAYCOLS = [1 => 'disp_monday', 2 => 'disp_tuesday', 3 => 'disp_wednesday', 4 => 'disp_thursday',
        5 => 'disp_friday', 6 => 'disp_saturday', 7 => 'disp_sunday'];
    const DAYNAMES = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];

    /** Numero de dia (1-7) a partir del nombre, con o sin tilde, en espanol o ingles. 0 si no se reconoce. */
    public static function day_num($name) {
        $n = \core_text::strtolower(trim((string)$name));
        // Algunos horarios guardan el dia como numero (1 = lunes ... 6 = sabado; 0 o 7 = domingo).
        if (preg_match('/^[0-7]$/', $n)) {
            return (int)$n === 0 ? 7 : (int)$n;
        }
        $n = strtr($n, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
        $map = ['lunes' => 1, 'martes' => 2, 'miercoles' => 3, 'jueves' => 4, 'viernes' => 5, 'sabado' => 6, 'domingo' => 7,
            'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6, 'sunday' => 7];
        return $map[$n] ?? 0;
    }

    public static function to_min($hhmm) {
        if (!preg_match('/^(\d{1,2}):(\d{2})/', (string)$hhmm, $m)) {
            return -1;
        }
        return (int)$m[1] * 60 + (int)$m[2];
    }

    public static function to_hhmm($min) {
        return sprintf('%02d:%02d', intdiv((int)$min, 60), (int)$min % 60);
    }

    protected static function norm($s) {
        $s = \core_text::strtoupper(trim((string)$s));
        return preg_replace('/\s+/', ' ', rtrim($s, '. '));
    }

    /** Borrador del tablero guardado en el periodo (o estructura vacia). */
    public static function draft($periodid) {
        global $DB;
        $cfg = json_decode((string)$DB->get_field('gmk_academic_periods', 'configsettings', ['id' => $periodid]), true);
        $d = is_array($cfg) && !empty($cfg['board_draft']) && is_array($cfg['board_draft']) ? $cfg['board_draft'] : [];
        return ['version' => (int)($d['version'] ?? 0), 'saved_at' => (int)($d['saved_at'] ?? 0), 'byname' => (string)($d['byname'] ?? ''),
            'items' => (array)($d['items'] ?? [])];
    }

    /**
     * Diferencias entre la propuesta guardada (la que usa el tablero) y la que sale hoy con las decisiones y la
     * configuracion vigentes, por asignatura + jornada: las que ya no tienen grupo, las nuevas y las que cambian de numero
     * de grupos o de tamano. Solo lectura. Devuelve null si no hay diferencias.
     */
    protected static function proposal_changes($periodid, array $saved, array $demand, array $dropped) {
        $info = planning_config::saved_proposal($periodid);
        $rk = function ($g) {
            return (int)$g['courseid'] . '|' . trim((string)$g['jornada']) . '|' . (int)($g['isolated_plan'] ?? 0);
        };
        $old = [];
        foreach ($saved as $g) {
            $k = $rk($g);
            $old[$k] = ['name' => $g['name'], 'jornada' => $g['jornada'], 'n' => ($old[$k]['n'] ?? 0) + 1,
                'size' => ($old[$k]['size'] ?? 0) + count($g['userids'] ?? [])];
        }
        $gone = [];
        $added = [];
        $changed = [];
        if (isset($demand['grouping'])) {
            $new = [];
            foreach ((array)$demand['grouping']['groups'] as $g) {
                if (in_array($g['type'] ?? 'grupo', ['modulo', 'sin_horario'], true)) {
                    continue;
                }
                $k = $rk($g);
                $new[$k] = ['name' => $g['name'], 'jornada' => $g['jornada'], 'n' => ($new[$k]['n'] ?? 0) + 1,
                    'size' => ($new[$k]['size'] ?? 0) + count($g['students'] ?? [])];
            }
            foreach ($old as $k => $o) {
                if (!isset($new[$k])) {
                    continue; // ya salio del tablero (va en $dropped)
                } else if ($new[$k]['n'] !== $o['n'] || abs($new[$k]['size'] - $o['size']) >= 3) {
                    $changed[] = ['name' => $o['name'], 'jornada' => $o['jornada'], 'was' => $o['n'] . ' grupo(s), ' . $o['size'] . ' est.',
                        'now' => $new[$k]['n'] . ' grupo(s), ' . $new[$k]['size'] . ' est.'];
                }
            }
            foreach ($new as $k => $n) {
                if (!isset($old[$k])) {
                    $added[] = ['name' => $n['name'], 'jornada' => $n['jornada'], 'now' => $n['n'] . ' grupo(s), ' . $n['size'] . ' est.'];
                }
            }
        }
        if (!$dropped && !$gone && !$added && !$changed) {
            return null;
        }
        return ['saved_at' => (int)($info['saved_at'] ?? 0), 'byname' => (string)($info['byname'] ?? ''),
            'dropped' => $dropped, 'gone' => $gone, 'added' => $added, 'changed' => $changed];
    }

    /**
     * Dia y hora de un seminario de un dia segun su configuracion y la jornada del grupo (minutos desde las 00:00).
     * Diurna: jueves 08:00-12:00 (si asi se configuro); el resto, el horario sabatino: sabado desde las 15:00, domingo
     * 08:00-17:00 o sabado de la semana de revalidas desde las 08:00.
     */
    protected static function event_slot(array $sp, $jornada, $mins) {
        $kind = ($jornada === 'Diurna' && $sp['diu_slot'] === 'jueves') ? 'jueves' : $sp['sab_slot'];
        switch ($kind) {
            case 'jueves':
                // Hasta 4 h: 08:00-12:00; mas largo: desde las 08:00 con una hora de almuerzo, hasta las 17:00.
                return ['kind' => 'jueves', 'day' => 4, 'start' => 480, 'end' => $mins <= 240 ? 480 + $mins : min(1020, 480 + $mins + 60),
                    'base' => 'Diurna'];
            case 'domingo':
                return ['kind' => 'domingo', 'day' => 7, 'start' => 480, 'end' => 1020, 'base' => 'Sabatina'];
            case 'revalida':
                return ['kind' => 'revalida', 'day' => 6, 'start' => 480, 'end' => 480 + $mins, 'base' => 'Sabatina'];
            default:
                return ['kind' => 'sabado_tarde', 'day' => 6, 'start' => 900, 'end' => 900 + $mins, 'base' => 'Sabatina'];
        }
    }

    /** Fechas posibles de un seminario de un dia: las semanas de clase de su dia, o el sabado de la semana de revalidas. */
    protected static function event_calendar($p, $calendar, array $slot, array $holidays) {
        if ($slot['kind'] === 'revalida') {
            $dates = publisher::revalida_dates($p, $calendar, $slot['base'], $slot['day'], $holidays);
        } else {
            list(, , $cal) = publisher::class_calendar($p, $calendar, $slot['base'], false, $holidays);
            $dates = array_values($cal[$slot['day']] ?? []);
        }
        $from = $dates ? strtotime($dates[0] . ' 00:00:00') : (int)$p->startdate;
        $to = $dates ? strtotime(end($dates) . ' 23:59:59') : (int)$p->enddate;
        return ['from' => $from, 'to' => $to, 'days' => [$slot['day'] => array_values($dates)]];
    }

    /** Clave estable de un grupo de la propuesta guardada. */
    public static function group_key(array $g) {
        return (int)$g['courseid'] . '|' . trim((string)$g['jornada']) . '|' . (int)($g['isolated_plan'] ?? 0) . '|' . (int)($g['number'] ?? 1);
    }

    /** Grupos de la propuesta guardada que van al tablero (sin modulos ni asignaturas sin horario). */
    public static function board_groups($periodid) {
        $saved = planning_config::saved_proposal($periodid);
        if (!$saved) {
            return null;
        }
        $out = [];
        foreach ($saved['groups'] as $g) {
            if (in_array($g['type'] ?? 'grupo', ['modulo', 'sin_horario'], true)) {
                continue;
            }
            $out[self::group_key($g)] = $g;
        }
        // Grupos adicionales (Proximo periodo -> "Agregar grupo al tablero"), aunque tengan 0 estudiantes.
        foreach (late_changes::extras($periodid) as $k => $x) {
            $out[$k] = $x;
        }
        return $out;
    }

    /**
     * Todo lo que necesita el tablero. Solo lectura.
     *
     * @param int $periodid
     * @param array $demand resultado de demand_engine con 'grouping' (para niveles y cohortes de los grupos)
     */
    public static function load($periodid, array $demand) {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
        $p = $DB->get_record('gmk_academic_periods', ['id' => $periodid], '*', MUST_EXIST);
        $start = (int)$demand['period']['start'];
        $end = (int)$p->enddate;
        $long = planning_config::long_subjects()['ids'];
        // Horas por asignatura (cargas horarias, por nombre normalizado).
        $hours = [];
        foreach (scheduler_settings::get_loads() as $l) {
            $hours[self::norm($l->subjectname)] = ['total' => (float)$l->total_hours, 'intensity' => (float)$l->intensity];
        }
        $cfg = scheduler_settings::get_config();
        $defdur = (int)($cfg->sessionDuration ?? 120) ?: 120;
        $hm = planning_config::hour_minutes();   // minutos por hora academica (45)
        $cfga = json_decode(json_encode($cfg), true);
        $special = planning_config::special_subjects();
        $evprefs = [];   // reglas de dia y hora de los seminarios de un dia (no se guardan: salen de su configuracion)

        // Grupos (de la propuesta guardada). Los ya publicados quedan marcados con su clase.
        $published = publisher::published($periodid)['groups'];
        $groups = [];
        $saved = static::board_groups($periodid);
        // Decisiones de apertura vigentes: lo decidido "no abrir" o "modulo" despues de guardar la propuesta no va al
        // tablero (salvo que ya se haya publicado como clase).
        $decided = [];
        foreach (planning_config::exceptions($periodid) as $e) {
            if (in_array($e['mode'], ['cerrar', 'modulo'], true)) {
                $decided[$e['key']] = $e['mode'];
            }
        }
        // Igual con lo que ya no tiene grupo en la propuesta de hoy (paso a no obligatoria, ya no alcanza el minimo, etc.).
        $today = null;
        if (isset($demand['grouping'])) {
            $today = [];
            foreach ((array)$demand['grouping']['groups'] as $tg) {
                $today[(int)$tg['courseid'] . '|' . trim((string)$tg['jornada']) . '|' . (int)($tg['isolated_plan'] ?? 0)] = true;
            }
        }
        $dropped = [];
        if ($saved) {
            foreach ($saved as $key => $g) {
                $rk = (int)$g['courseid'] . '|' . trim((string)$g['jornada']) . '|' . (int)($g['isolated_plan'] ?? 0);
                if (!empty($published[$key]) || !empty($g['extra'])) {
                    continue; // publicado o adicional (decidido a mano): no sale del tablero
                }
                $why = isset($decided[$rk]) ? ($decided[$rk] === 'cerrar' ? 'se decidió no abrirla' : 'se decidió como módulo (sin horario)')
                    : ($today !== null && !isset($today[$rk]) ? 'ya no abre: no obligatoria, sin quórum u otro cambio' : '');
                if ($why !== '') {
                    $dropped[] = ['key' => $key, 'name' => $g['name'], 'jornada' => $g['jornada'], 'reason' => $why];
                    unset($saved[$key]);
                }
            }
        }
        $stale = $saved !== null ? self::proposal_changes($periodid, array_filter((array)$saved, function ($g) {
            return empty($g['extra']);
        }), $demand, $dropped) : null;
        // Estudiantes excluidos por choques: salen de los grupos de esa asignatura. Tambien de los ya publicados: la clase
        // se creo sin ellos (solo se puede excluir antes de publicar), y si no se quitan el tablero ve choques que no existen.
        $exclusions = planning_config::board_exclusions($periodid);
        if ($saved && $exclusions) {
            foreach ($saved as $key => $g) {
                $keep = function ($u) use ($g, $exclusions) {
                    return !isset($exclusions[(int)$g['courseid'] . '|' . (int)$u]);
                };
                $saved[$key]['userids'] = array_values(array_filter($g['userids'], $keep));
                $saved[$key]['adelantos'] = array_values(array_filter($g['adelantos'] ?? [], $keep));
                if (!$saved[$key]['userids'] && empty($published[$key]) && empty($g['extra'])) {
                    $dropped[] = ['key' => $key, 'name' => $g['name'], 'jornada' => $g['jornada'],
                        'reason' => 'todos sus estudiantes fueron excluidos por choques'];
                    unset($saved[$key]);
                }
            }
        }
        $byuser = [];
        foreach ($demand['students'] ?? [] as $k => $s) {
            $byuser[(int)$s['userid']][] = $s;
        }
        $names = [];
        $prefs = planning_config::subject_prefs();
        // Calendario de cada jornada (y cuatrimestral): fechas de clase por dia de la semana, sin festivos.
        $calendar = $DB->get_record('gmk_academic_calendar', ['academicperiodid' => $periodid]);
        $holidaymap = [];
        foreach (scheduler_settings::get_holidays() as $hd) {
            $holidaymap[date('Y-m-d', (int)$hd->date)] = $hd->name;
        }
        $calendars = [];
        $calweeks = [];
        foreach ((array)$saved as $key => $g) {
            $cid = (int)$g['courseid'];
            if (!isset($names[$cid])) {
                $names[$cid] = (string)$DB->get_field('course', 'fullname', ['id' => $cid]);
            }
            $h = $hours[self::norm($names[$cid] ?: $g['name'])] ?? null;
            $islong = isset($long[$cid]);
            $gplans = [];
            foreach ($g['userids'] as $u) {
                foreach ($byuser[(int)$u] ?? [] as $s) {
                    $gplans[(int)$s['planid']] = (int)$s['planid'];
                }
            }
            $gplans = array_values($gplans);
            $calkey = $g['jornada'] . '|' . ($islong ? 1 : 0);
            if (!isset($calendars[$calkey])) {
                list($cfrom, $cto, $cal) = publisher::class_calendar($p, $calendar, $g['jornada'], $islong, $holidaymap);
                $calendars[$calkey] = ['from' => $cfrom, 'to' => $cto, 'days' => $cal];
                // Semanas utiles: el dia de la jornada con menos fechas.
                $jd = stripos($g['jornada'], 'sab') !== false ? [6] : [1, 2, 3, 4, 5];
                $calweeks[$calkey] = max(1, min(array_map(function ($dn) use ($cal) {
                    return count($cal[$dn]);
                }, $jd)));
            }
            $weeks = $calweeks[$calkey];
            // Sesion: la intensidad de la carga (horas reloj por sesion) o la duracion estandar (120 min). El total de la carga
            // esta en horas academicas de 45 min. La ultima semana de clase de cada bloque es de evaluacion (y la siguiente, de
            // revalidas): el contenido se dicta en las semanas anteriores y se suma una sesion de evaluacion por bloque.
            // P. ej. 16 h = 720 min = 6 sesiones de 120 + evaluacion = 7; cuatrimestral de 32 h = 12 + 2 evaluaciones = 14.
            // Las asignaturas cortas usan solo las sesiones que necesitan (se pueden poner seguidas en la misma franja); las
            // que no caben se parten en varias fichas semanales.
            $fixeddur = $h && $h['intensity'] > 0;
            $dur = $fixeddur ? (int)round($h['intensity'] * 60) : $defdur;
            $dur = max(30, min(480, $dur));
            $evals = max(1, (int)round($weeks / max(1, planning_config::class_weeks())));   // una por bloque
            $cweeks = max(1, $weeks - $evals);   // semanas de contenido (sin las de evaluacion)
            $content = $h && $h['total'] > 0 ? (int)ceil($h['total'] * $hm / $dur) : $cweeks;
            // Si no cabe en las semanas de contenido y la carga no fija la intensidad, se alarga la sesion (hasta 4 h).
            if ($content > $cweeks && !$fixeddur && $h && $h['total'] > 0) {
                $longer = (int)(ceil($h['total'] * $hm / $cweeks / 5) * 5);
                if ($longer <= 240) {
                    $dur = max($dur, $longer);
                    $content = (int)ceil($h['total'] * $hm / $dur);
                }
            }
            $sessions = $content >= $cweeks ? $content + $evals : $content;
            $nparts = max(1, (int)ceil($sessions / $weeks));
            // Regla de la asignatura con veces por semana: una ficha por vez y sesiones del largo necesario para cubrir
            // la carga en las semanas del bloque (sin pasar de su franja). P. ej. 64 h, 1 vez, 14 semanas = 275 min.
            $rule = null;
            foreach ($prefs as $rp) {
                if ($rp['courseid'] === $cid && $rp['times_per_week'] > 0 && (!$rp['planid'] || in_array($rp['planid'], $gplans, true))
                        && ($rp['jornada'] === '' || $rp['jornada'] === $g['jornada'])) {
                    if (!$rule || $rp['planid'] || $rp['jornada'] !== '') {
                        $rule = $rp;
                    }
                }
            }
            if ($rule) {
                $nparts = $rule['times_per_week'];
                if ($h && $h['total'] > 0) {
                    $dur = (int)(ceil($h['total'] * $hm / ($weeks * $nparts) / 5) * 5);
                    $span = ($rule['start'] !== '' && $rule['end'] !== '') ? self::to_min($rule['end']) - self::to_min($rule['start']) : 480;
                    $dur = max(30, min($dur, $span, 480));
                }
                $sessions = $weeks * $nparts;
            }
            // Duracion propia de la jornada (p. ej. Sabatina 90 min): una sesion por semana hasta la ultima semana de clase,
            // sin alargar ni partir (la semana siguiente es de revalidas).
            $sp = $special[$cid] ?? null;
            $isevent = $sp && $sp['event_hours'] > 0;
            $jw = $cfga['shiftWindows'][$g['jornada']] ?? [];
            if (!$isevent && !$rule && !$fixeddur && !empty($jw['sessionMinutes'])) {
                $dur = (int)$jw['sessionMinutes'];
                $content = $h && $h['total'] > 0 ? (int)ceil($h['total'] * $hm / $dur) : $cweeks;
                $sessions = min($weeks, $content >= $cweeks ? $content + $evals : $content);
                $nparts = 1;
            }
            // Seminario de un dia: una sola sesion en su dia y hora (sabado desde las 15:00, domingo, sabado de revalidas o
            // jueves en la manana para Diurna), con su propio calendario de fechas posibles.
            if ($isevent) {
                $slot = self::event_slot($sp, $g['jornada'], (int)round($sp['event_hours'] * 60));
                $dur = $slot['end'] - $slot['start'];
                $sessions = 1;
                $nparts = 1;
                $calkey = $g['jornada'] . '|ev:' . $slot['kind'];
                if (!isset($calendars[$calkey])) {
                    $calendars[$calkey] = self::event_calendar($p, $calendar, $slot, $holidaymap);
                }
                $weeks = count($calendars[$calkey]['days'][$slot['day']] ?? []);
                $evprefs[] = ['courseid' => $cid, 'planid' => 0, 'jornada' => $g['jornada'], 'days' => [$slot['day']],
                    'start' => self::to_hhmm($slot['start']), 'end' => self::to_hhmm($slot['end']), 'strict' => true,
                    'override_window' => true, 'first_slot' => true, 'teacherid' => 0, 'times_per_week' => 0,
                    'parallel' => [], 'parallel_max' => 4, 'event' => $slot['kind']];
            }
            $parts = [];
            for ($i = 1; $i <= $nparts; $i++) {
                $parts[] = ['key' => $nparts === 1 ? $key : $key . '#' . $i,
                    'sessions' => intdiv($sessions, $nparts) + ($i <= $sessions % $nparts ? 1 : 0)];
            }
            $levels = [];
            $plans = [];
            foreach ($g['userids'] as $u) {
                foreach ($byuser[(int)$u] ?? [] as $s) {
                    $levels[$s['level']] = ($levels[$s['level']] ?? 0) + 1;
                    $plans[(int)$s['planid']] = true;
                }
            }
            arsort($levels);
            $groups[] = ['key' => $key, 'courseid' => $cid, 'name' => $g['name'], 'jornada' => $g['jornada'],
                'isolated_plan' => (int)$g['isolated_plan'], 'number' => (int)$g['number'], 'of' => (int)$g['of'],
                'type' => $g['type'], 'userids' => array_map('intval', $g['userids']), 'size' => count($g['userids']),
                'adelantos' => count($g['adelantos'] ?? []), 'adelanto_ids' => array_map('intval', $g['adelantos'] ?? []), 'levels' => $levels, 'plans' => array_keys($plans),
                'duration' => $dur, 'hours' => $h ? $h['total'] : 0, 'long' => $islong,
                'cal' => $calkey, 'weeks' => $weeks, 'sessions' => $sessions, 'parts' => $parts,
                'published' => (int)($published[$key] ?? 0),
                'event' => $isevent ? $slot['kind'] : '', 'yields' => $sp ? $sp['yields'] : false,
                'extra' => !empty($g['extra']), 'note' => (string)($g['note'] ?? ''), 'planid' => (int)($g['planid'] ?? 0),
                'byname' => (string)($g['byname'] ?? '')];
        }

        // Docentes: instructores de la plataforma + quien tiene disponibilidad registrada.
        $teachers = [];
        foreach (list_instructors() as $i) {
            if (empty($i->suspended)) {
                $teachers[(int)$i->id] = ['id' => (int)$i->id, 'name' => trim(preg_replace('/\s+/', ' ', $i->fullname)),
                    'skills' => [], 'avail' => (object)[], 'has_avail' => false];
            }
        }
        foreach ($DB->get_records('gmk_teacher_disponibility') as $r) {
            $uid = (int)$r->userid;
            if (!isset($teachers[$uid])) {
                $u = $DB->get_record('user', ['id' => $uid, 'deleted' => 0], 'id, firstname, lastname, suspended');
                if (!$u || $u->suspended) {
                    continue;
                }
                $teachers[$uid] = ['id' => $uid, 'name' => trim(preg_replace('/\s+/', ' ', fullname($u))), 'skills' => [],
                    'avail' => (object)[], 'has_avail' => false];
            }
            $av = [];
            foreach (self::DAYCOLS as $dn => $col) {
                $ranges = [];
                foreach ((array)json_decode((string)$r->$col, true) as $x) {
                    if (isset($x['st'], $x['et']) && $x['et'] > $x['st']) {
                        $ranges[] = [(int)round($x['st'] / 60), (int)round($x['et'] / 60)];
                    }
                }
                if ($ranges) {
                    $av[$dn] = $ranges;
                }
            }
            $teachers[$uid]['avail'] = (object)$av;
            $teachers[$uid]['has_avail'] = true;
        }
        foreach ($DB->get_records_sql("SELECT r.id, r.userid, s.courseid FROM {gmk_teacher_skill_relation} r
                JOIN {gmk_teacher_skill} s ON s.id = r.skillid WHERE s.courseid > 0") as $r) {
            if (isset($teachers[(int)$r->userid])) {
                $teachers[(int)$r->userid]['skills'][] = (int)$r->courseid;
            }
        }
        usort($teachers, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        // Ocupacion fija: clases del periodo y clases de otros periodos que siguen corriendo en sus fechas. Una clase de otro
        // periodo solo ocupa si le quedan sesiones desde el inicio de este (una fecha de fin mal puesta no la vuelve ocupada;
        // las cuatrimestrales que siguen en curso si).
        $fixed = [];
        $horizonend = $end + 70 * 86400;
        $classes = $DB->get_records_select('gmk_class',
            'is_module = 0 AND closed = 0 AND (periodid = ? OR (approved = 1 AND enddate > ? AND initdate < ?))',
            [$periodid, $start, $horizonend], 'id',
            'id, name, corecourseid, periodid, instructorid, classroomid, groupid, shift, initdate, enddate, approved');
        if ($classes) {
            list($insql, $inparams) = $DB->get_in_or_equal(array_keys($classes));
            $sess = [];
            $lastdate = [];   // classid => ultima fecha agendada (Y-m-d), si el horario tiene fechas
            foreach ($DB->get_records_select('gmk_class_schedules', "classid $insql", $inparams) as $s) {
                $dates = array_diff((array)json_decode((string)($s->assigned_dates ?? ''), true),
                    (array)json_decode((string)($s->excluded_dates ?? ''), true));
                if ($dates) {
                    $lastdate[(int)$s->classid] = max($lastdate[(int)$s->classid] ?? '', max($dates));
                }
                $dn = self::day_num($s->day);
                $a = self::to_min($s->start_time);
                $b = self::to_min($s->end_time);
                if ($dn && $a >= 0 && $b > $a) {
                    $sess[(int)$s->classid][] = ['day' => $dn, 'start' => $a, 'end' => $b, 'roomid' => (int)$s->classroomid];
                }
            }
            $members = [];
            $gids = array_filter(array_map(function ($c) {
                return (int)$c->groupid;
            }, $classes));
            if ($gids) {
                list($gsql, $gparams) = $DB->get_in_or_equal(array_values(array_unique($gids)));
                foreach ($DB->get_records_select('groups_members', "groupid $gsql", $gparams, '', 'id, groupid, userid') as $m) {
                    $members[(int)$m->groupid][] = (int)$m->userid;
                }
            }
            $pubids = array_flip(array_map('intval', array_values($published)));
            foreach ($classes as $c) {
                if (empty($sess[(int)$c->id]) || isset($pubids[(int)$c->id])) {
                    continue;
                }
                if ((int)$c->periodid !== (int)$periodid && isset($lastdate[(int)$c->id]) && $lastdate[(int)$c->id] < date('Y-m-d', $start)) {
                    continue; // ya no tiene sesiones en este periodo
                }
                $fixed[] = ['id' => (int)$c->id, 'name' => $c->name, 'courseid' => (int)$c->corecourseid,
                    'teacherid' => (int)$c->instructorid, 'jornada' => (string)$c->shift, 'samePeriod' => (int)$c->periodid === (int)$periodid,
                    'initdate' => (int)$c->initdate, 'enddate' => (int)$c->enddate, 'sessions' => $sess[(int)$c->id],
                    'userids' => $members[(int)$c->groupid] ?? []];
            }
        }

        $rooms = [];
        foreach ($DB->get_records('gmk_classrooms', ['active' => 1], 'name ASC') as $r) {
            $rooms[] = ['id' => (int)$r->id, 'name' => $r->name, 'capacity' => (int)$r->capacity, 'type' => (string)$r->type];
        }
        $holidays = [];
        foreach (scheduler_settings::get_holidays() as $h) {
            if ((int)$h->date >= $start - 86400 && (int)$h->date <= $horizonend) {
                $holidays[] = ['date' => (int)$h->date, 'day' => (int)date('N', (int)$h->date), 'name' => (string)$h->name];
            }
        }
        // Catalogo de habilidades (una por asignatura) para agregar habilidades desde el tablero.
        $catalog = [];
        foreach ($DB->get_records_sql("SELECT s.courseid, c.fullname FROM {gmk_teacher_skill} s JOIN {course} c ON c.id = s.courseid
                WHERE s.courseid > 0 ORDER BY c.fullname") as $r) {
            $catalog[] = ['courseid' => (int)$r->courseid, 'name' => $r->fullname];
        }
        return [
            'careers' => array_values(array_map(function ($r) {
                return ['id' => (int)$r->id, 'name' => $r->name];
            }, $DB->get_records('local_learning_plans', null, 'name', 'id, name'))),
            'needs_proposal' => $saved === null,
            'proposal_changes' => $stale,
            'calendars' => $calendars,
            'skills_catalog' => $catalog,
            'groups' => $groups,
            'teachers' => array_values($teachers),
            'rooms' => $rooms,
            'fixed' => $fixed,
            // Nombres de los estudiantes de las fichas (para decir quien choca).
            'students' => (object)array_map(function ($list) {
                return $list[0]['name'];
            }, $byuser),
            // Detalle de los estudiantes de las fichas (solo lectura), para imprimir y exportar.
            'student_info' => (object)array_map(function ($list) {
                return ['doc' => (string)$list[0]['documento'], 'email' => (string)$list[0]['email'],
                    'phone' => (string)$list[0]['phone'], 'jornada' => (string)$list[0]['jornada'], 'ingreso' => (string)$list[0]['ingreso'],
                    'plans' => array_values(array_map(function ($s) {
                        return ['plan' => (string)$s['plan'], 'level' => (string)$s['level'], 'finishing' => !empty($s['finishing'])];
                    }, $list))];
            }, array_intersect_key($byuser, array_flip(array_merge([], ...array_map(function ($g) {
                return $g['userids'];
            }, $groups))))),
            // Informe de excluidos por choques (con los datos del estudiante para cargarle la asignatura despues).
            'exclusions' => array_values(array_map(function ($e) use ($byuser, $DB, $published) {
                $s = $byuser[(int)$e['userid']][0] ?? null;
                if (!$s) {
                    $u = $DB->get_record('user', ['id' => $e['userid']], 'id, firstname, lastname, idnumber, email');
                    $s = ['name' => $u ? fullname($u) : ('Usuario ' . $e['userid']), 'documento' => $u ? (string)$u->idnumber : '',
                        'email' => $u ? (string)$u->email : '', 'plan' => '', 'level' => ''];
                }
                return $e + ['name' => (string)$s['name'], 'documento' => (string)$s['documento'], 'email' => (string)$s['email'],
                    'plans' => implode('; ', array_map(function ($x) {
                        return trim(($x['plan'] ?? '') . ' · ' . ($x['level'] ?? ''), ' ·');
                    }, $byuser[(int)$e['userid']] ?? [$s])),
                    'locked' => !empty($published[$e['group']])];
            }, $exclusions)),
            'holidays' => $holidays,
            'config' => [
                'shiftWindows' => $cfg->shiftWindows ?? (object)[],
                'startTime' => $cfg->startTime ?? '07:00', 'endTime' => $cfg->endTime ?? '22:00',
                'lunchStart' => $cfg->lunchStart ?? '', 'lunchEnd' => $cfg->lunchEnd ?? '',
                'interval' => (int)($cfg->intervalMinutes ?? 30), 'sessionDuration' => $defdur,
                'classWeeks' => planning_config::class_weeks(),
            ],
            // Preferencias de dia y hora (no obligatorias): por jornada (shiftWindows[j].preferredDays) y por asignatura.
            'preferences' => ['subjects' => array_merge(planning_config::subject_prefs(), $evprefs)],
            'period' => ['start' => $start, 'end' => $end, 'jornada_starts' => (object)planning_config::jornada_starts($p)],
            'draft' => self::draft($periodid),
        ];
    }

    /**
     * Guarda el borrador del tablero (solo configsettings.board_draft del periodo).
     * Control de version: si otro usuario guardo despues de que se cargo el tablero, no se sobrescribe.
     *
     * @param array $d ['periodid', 'version', 'items' => {key: {day, start, end, teacherid, roomid, locked}}, 'clear' => bool]
     */
    public static function save(array $d) {
        global $DB, $USER;
        $pid = (int)($d['periodid'] ?? 0);
        $p = $DB->get_record('gmk_academic_periods', ['id' => $pid]);
        if (!$p) {
            return ['ok' => false, 'message' => 'El periodo no existe.', 'saved' => null];
        }
        $cur = self::draft($pid);
        if ((int)($d['version'] ?? -1) !== $cur['version']) {
            return ['ok' => false, 'message' => 'Otra persona guardó el tablero (' . ($cur['byname'] ?: 'otro usuario') . ', '
                . userdate($cur['saved_at'], '%d/%m %H:%M') . ') después de que usted lo abrió. Recargue el tablero para no perder sus cambios '
                . 'ni los de la otra persona.', 'saved' => ['conflict' => true, 'version' => $cur['version']]];
        }
        $items = [];
        if (!empty($d['clear']) && publisher::published($pid)['groups']) {
            return ['ok' => false, 'message' => 'Hay grupos ya publicados desde este tablero: el borrador no se puede vaciar.', 'saved' => null];
        }
        if (empty($d['clear'])) {
            $groups = self::board_groups($pid);
            if ($groups === null) {
                return ['ok' => false, 'message' => 'Guarde primero la propuesta de grupos: el tablero se arma sobre ella.', 'saved' => null];
            }
            $rooms = $DB->get_records_menu('gmk_classrooms', null, '', 'id, name');
            $published = publisher::published($pid)['groups'];
            $tchecked = [];
            foreach ((array)($d['items'] ?? []) as $key => $it) {
                $key = (string)$key;
                // Ficha: clave del grupo, o grupo#parte cuando la asignatura se dicta varios dias por semana.
                $gkey = $key;
                if (preg_match('/^(.*)#(\d{1,2})$/', $key, $mm)) {
                    $gkey = $mm[1];
                    if ((int)$mm[2] < 1) {
                        return ['ok' => false, 'message' => 'Ficha no válida (' . $key . ').', 'saved' => null];
                    }
                }
                if (!empty($published[$gkey])) {
                    // Ya publicado: se conserva lo que estaba (no se edita desde el tablero).
                    if (isset($cur['items'][$key])) {
                        $items[$key] = $cur['items'][$key];
                    }
                    continue;
                }
                if (!isset($groups[$gkey])) {
                    return ['ok' => false, 'message' => 'Un grupo del tablero ya no está en la propuesta guardada (' . $key . '). Recargue el tablero.', 'saved' => null];
                }
                $week = (int)($it['week'] ?? 0);
                $nses = (int)($it['sessions'] ?? 0);
                if ($week < 0 || $week > 40 || $nses < 0 || $nses > 60) {
                    return ['ok' => false, 'message' => 'Semanas o sesiones no válidas en ' . $groups[$gkey]['name'] . '.', 'saved' => null];
                }
                $day = (int)($it['day'] ?? 0);
                $a = (int)($it['start'] ?? -1);
                $b = (int)($it['end'] ?? -1);
                $placed = $day > 0;
                if ($placed && ($day < 1 || $day > 7 || $a < 0 || $b <= $a || $b > 24 * 60)) {
                    return ['ok' => false, 'message' => 'Horario no válido en ' . $groups[$gkey]['name'] . '.', 'saved' => null];
                }
                $tid = (int)($it['teacherid'] ?? 0);
                if ($tid && !isset($tchecked[$tid])) {
                    if (!$DB->record_exists('user', ['id' => $tid, 'deleted' => 0])) {
                        return ['ok' => false, 'message' => 'El docente asignado a ' . $groups[$gkey]['name'] . ' no existe.', 'saved' => null];
                    }
                    $tchecked[$tid] = true;
                }
                $rid = (int)($it['roomid'] ?? 0);
                if ($rid && !isset($rooms[$rid])) {
                    return ['ok' => false, 'message' => 'El aula asignada a ' . $groups[$gkey]['name'] . ' no existe.', 'saved' => null];
                }
                if (!$placed && !$tid && !$rid) {
                    continue; // nada que guardar
                }
                $items[$key] = ['day' => $placed ? $day : 0, 'start' => $placed ? $a : 0, 'end' => $placed ? $b : 0,
                    'teacherid' => $tid, 'roomid' => $rid, 'locked' => !empty($it['locked']), 'week' => $placed ? $week : 0,
                    'sessions' => $nses];
            }
        }
        $cfg = json_decode((string)$p->configsettings, true);
        if (!is_array($cfg)) {
            $cfg = [];
        }
        $before = $cfg['board_draft'] ?? null;
        if (!empty($d['clear'])) {
            unset($cfg['board_draft']);
        } else {
            $cfg['board_draft'] = ['version' => $cur['version'] + 1, 'saved_at' => time(), 'by' => (int)$USER->id,
                'byname' => fullname($USER), 'items' => (object)$items];
        }
        $tx = $DB->start_delegated_transaction();
        $DB->update_record('gmk_academic_periods', (object)['id' => $pid,
            'configsettings' => $cfg ? json_encode($cfg, JSON_UNESCAPED_UNICODE) : null,
            'usermodified' => $USER->id, 'timemodified' => time()]);
        $tx->allow_commit();
        planning_config::log_backup('tablero_periodo_' . $pid, ['antes' => $before, 'accion' => !empty($d['clear']) ? 'vaciar' : 'guardar',
            'grupos' => count($items)]);
        $now = self::draft($pid);
        $placed = count(array_filter($now['items'], function ($x) {
            return !empty($x['day']);
        }));
        return ['ok' => true, 'message' => !empty($d['clear']) ? 'Borrador del tablero vaciado.'
            : 'Tablero guardado: ' . $placed . ' grupo(s) con horario. No se creó ninguna clase.', 'saved' => $now];
    }
}
