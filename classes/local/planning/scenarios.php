<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Escenarios de horario sobre un periodo ya publicado (sin tablas nuevas).
 *
 *  - Un escenario es una copia con nombre de las ubicaciones del tablero donde las clases publicadas se pueden mover. Se
 *    guarda aparte en el periodo (configsettings.board_scenarios) y NO toca ninguna clase.
 *  - "Aplicar desde una fecha": cambia dia, hora y aula de las clases publicadas que el escenario movio, solo en sus
 *    sesiones desde esa fecha. Primero se revisa (solo lectura: sesion por sesion, choques con TODAS las clases abiertas,
 *    feriados, sesiones ya dadas) y luego se aplica con respaldo JSON, en una transaccion y con verificacion.
 *    No borra ni crea nada: mueve en su lugar las sesiones de asistencia, sus eventos, las salas BBB (nombre incluido) y
 *    actualiza el horario de la clase. Nada antes de la fecha cambia. El docente y los estudiantes no cambian.
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Grupo Makro
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\planning;

defined('MOODLE_INTERNAL') || die();

class scenarios {

    /** Nombre del dia como lo guardan los horarios de las clases. */
    const DAYKEY = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miercoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sabado', 7 => 'Domingo'];

    protected static function ok($msg, $saved) {
        return ['ok' => true, 'message' => $msg, 'saved' => $saved];
    }

    protected static function fail($msg) {
        return ['ok' => false, 'message' => $msg, 'saved' => null];
    }

    protected static function cfg($pid) {
        global $DB;
        $cfg = json_decode((string)$DB->get_field('gmk_academic_periods', 'configsettings', ['id' => $pid]), true);
        return is_array($cfg) ? $cfg : [];
    }

    /** Escenarios guardados del periodo: [id => {id, name, effective, items, saved_at, byname, applied}]. */
    public static function all($pid) {
        $cfg = self::cfg($pid);
        return is_array($cfg['board_scenarios'] ?? null) ? $cfg['board_scenarios'] : [];
    }

    protected static function write($pid, array $list) {
        global $DB;
        $cfg = self::cfg($pid);
        if ($list) {
            $cfg['board_scenarios'] = $list;
        } else {
            unset($cfg['board_scenarios']);
        }
        $DB->set_field('gmk_academic_periods', 'configsettings', json_encode($cfg, JSON_UNESCAPED_UNICODE), ['id' => $pid]);
    }

    /** Guarda (o borra con $remove) un escenario. Solo escribe en la configuracion del periodo. */
    public static function save(array $d, $remove) {
        global $DB, $USER;
        $pid = (int)($d['periodid'] ?? 0);
        if (!$DB->record_exists('gmk_academic_periods', ['id' => $pid])) {
            return self::fail('El periodo no existe.');
        }
        $list = self::all($pid);
        $id = preg_replace('/[^a-z0-9]/', '', (string)($d['id'] ?? ''));
        if ($remove) {
            if (!isset($list[$id])) {
                return self::fail('El escenario ya no existe.');
            }
            unset($list[$id]);
            self::write($pid, $list);
            return self::ok('Escenario borrado. No se cambió ninguna clase.', array_values($list));
        }
        $name = trim((string)($d['name'] ?? ''));
        if ($name === '' || \core_text::strlen($name) > 80) {
            return self::fail('Indique un nombre para el escenario (hasta 80 caracteres).');
        }
        if ($id === '') {
            $id = 's' . time() . random_int(100, 999);
        } else if (isset($list[$id]) && (int)($d['saved_at'] ?? 0) !== (int)$list[$id]['saved_at']) {
            return self::fail('Otra persona guardó este escenario (' . $list[$id]['byname'] . ') después de que usted lo abrió. '
                . 'Recárguelo para no perder cambios.');
        }
        $items = [];
        foreach ((array)($d['items'] ?? []) as $k => $it) {
            if (!is_array($it)) {
                continue;
            }
            $items[(string)$k] = ['day' => (int)($it['day'] ?? 0), 'start' => (int)($it['start'] ?? 0), 'end' => (int)($it['end'] ?? 0),
                'teacherid' => (int)($it['teacherid'] ?? 0), 'roomid' => (int)($it['roomid'] ?? 0), 'week' => (int)($it['week'] ?? 0),
                'locked' => !empty($it['locked'])];
        }
        $eff = (string)($d['effective'] ?? '');
        $list[$id] = ['id' => $id, 'name' => $name, 'note' => \core_text::substr(trim((string)($d['note'] ?? '')), 0, 500),
            'effective' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $eff) ? $eff : '', 'items' => $items,
            'saved_at' => time(), 'byname' => fullname($USER), 'applied' => $list[$id]['applied'] ?? []];
        self::write($pid, $list);
        return self::ok('Escenario "' . $name . '" guardado. No se cambió ninguna clase.', $list[$id]);
    }

    /**
     * Revision (SOLO LECTURA) de aplicar cambios de clases publicadas desde una fecha.
     *
     * @param array $d periodid, effective (Y-m-d), changes: [{classid, sid, day (1-7), start, end (minutos), roomid}]
     * @return array ok, message, saved => {effective, classes: [...], blocks, warnings, token}
     */
    public static function plan(array $d) {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
        $pid = (int)($d['periodid'] ?? 0);
        $eff = (string)($d['effective'] ?? '');
        if (!$DB->record_exists('gmk_academic_periods', ['id' => $pid])) {
            return self::fail('El periodo no existe.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $eff) || strtotime($eff) === false) {
            return self::fail('Indique la fecha desde la que rige el cambio.');
        }
        $now = time();
        $tomorrow = date('Y-m-d', $now + 86400);
        if ($eff < $tomorrow) {
            return self::fail('El cambio solo puede regir desde mañana (' . $tomorrow . ') en adelante: las sesiones de hoy o anteriores no se mueven.');
        }
        $effts = strtotime($eff . ' 00:00');
        $changes = [];
        foreach ((array)($d['changes'] ?? []) as $c) {
            $sid = (int)($c['sid'] ?? 0);
            $changes[$sid] = ['classid' => (int)($c['classid'] ?? 0), 'sid' => $sid, 'day' => (int)($c['day'] ?? 0),
                'start' => (int)($c['start'] ?? -1), 'end' => (int)($c['end'] ?? -1), 'roomid' => (int)($c['roomid'] ?? 0)];
        }
        if (!$changes) {
            return self::fail('El escenario no cambia ninguna clase publicada.');
        }
        $holidays = [];
        foreach (\local_grupomakro_core\local\scheduler_settings::get_holidays() as $h) {
            $holidays[date('Y-m-d', (int)$h->date)] = $h->name;
        }
        $rooms = $DB->get_records('gmk_classrooms', null, '', 'id, name, capacity');
        $out = [];
        $blocks = [];
        $warnings = [];
        $moving = [];     // classid => [sid => cambio] (para no contarlas como choque entre si con su horario viejo)
        foreach ($changes as $sid => $c) {
            $moving[$c['classid']][$sid] = $c;
        }
        foreach ($moving as $cid => $list) {
            $k = $DB->get_record('gmk_class', ['id' => $cid]);
            $label = '#' . $cid . ($k ? ' ' . $k->name : '');
            if (!$k || (int)$k->closed || (int)$k->is_module || (int)$k->periodid !== $pid) {
                $blocks[] = $label . ': no es una clase abierta de este periodo.';
                continue;
            }
            $scheds = $DB->get_records('gmk_class_schedules', ['classid' => $cid], 'id');
            $aid = $k->attendancemoduleid ? (int)$DB->get_field('course_modules', 'instance', ['id' => $k->attendancemoduleid]) : 0;
            $sessions = $aid ? $DB->get_records('attendance_sessions', ['attendanceid' => $aid], 'sessdate') : [];
            $members = $k->groupid ? $DB->get_fieldset_select('groups_members', 'userid', 'groupid = ?', [$k->groupid]) : [];
            $members = array_values(array_diff(array_map('intval', $members), [(int)$k->instructorid]));
            $cls = ['classid' => $cid, 'name' => $k->name, 'teacher' => $k->instructorid ? fullname($DB->get_record('user', ['id' => $k->instructorid])) : '',
                'students' => count($members), 'schedules' => []];
            $taken = []; // sesiones ya usadas por otro horario de la misma clase
            foreach ($list as $sid => $c) {
                $s = $scheds[$sid] ?? null;
                if (!$s) {
                    $blocks[] = $label . ': su horario cambió (recargue el tablero).';
                    continue;
                }
                $oday = schedule_board::day_num($s->day);
                $oa = schedule_board::to_min($s->start_time);
                $ob = schedule_board::to_min($s->end_time);
                if ($c['day'] < 1 || $c['day'] > 7 || $c['start'] < 0 || $c['end'] <= $c['start'] || $c['end'] > 24 * 60) {
                    $blocks[] = $label . ': día u hora no válidos.';
                    continue;
                }
                $roomid = $c['roomid'] ?: (int)($s->classroomid ?: $k->classroomid);
                if ($roomid && !isset($rooms[$roomid])) {
                    $blocks[] = $label . ': el aula elegida no existe.';
                    continue;
                }
                $dates = array_values(array_diff((array)json_decode((string)$s->assigned_dates, true), (array)json_decode((string)$s->excluded_dates, true)));
                if (!$dates) {
                    // Horario sin fechas guardadas (se calculan por dia): las de sus sesiones de asistencia de ese dia y hora.
                    foreach ($sessions as $as) {
                        if ((int)date('N', $as->sessdate) === $oday && abs(((int)date('G', $as->sessdate) * 60 + (int)date('i', $as->sessdate)) - $oa) <= 30) {
                            $dates[] = date('Y-m-d', $as->sessdate);
                        }
                    }
                    $dates = array_values(array_unique($dates));
                }
                sort($dates);
                $keep = array_values(array_filter($dates, function ($x) use ($eff) {
                    return $x < $eff;
                }));
                $move = array_values(array_filter($dates, function ($x) use ($eff) {
                    return $x >= $eff;
                }));
                if (!$move) {
                    $warnings[] = $label . ': no tiene sesiones desde ' . $eff . '; no cambia nada.';
                    continue;
                }
                // Fechas nuevas: el mismo dia conserva las fechas; otro dia toma las siguientes de ese dia desde la fecha,
                // sin feriados, tantas como las que se mueven y sin pasar del fin de la clase.
                $new = [];
                if ($c['day'] === $oday) {
                    $new = $move;
                } else {
                    $t = $effts;
                    // Hasta una semana despues del fin de la clase (un feriado puede correr la ultima sesion); si termina mas
                    // tarde que hoy se avisa y al aplicar se corre la fecha de fin.
                    $limit = ((int)$k->enddate ?: strtotime(end($move) . ' 23:59')) + 14 * 86400;
                    $used = array_flip($dates);
                    while (count($new) < count($move) && $t <= $limit) {
                        $ymd = date('Y-m-d', $t);
                        if ((int)date('N', $t) === $c['day'] && !isset($holidays[$ymd]) && !isset($used[$ymd])) {
                            $new[] = $ymd;
                        }
                        $t = strtotime('+1 day', $t);
                    }
                    if ($new && $k->enddate && end($new) > date('Y-m-d', (int)$k->enddate)) {
                        $warnings[] = $label . ': con el nuevo día termina el ' . date('d/m/Y', strtotime(end($new))) . ' en vez del '
                            . userdate((int)$k->enddate, '%d/%m/%Y') . '.';
                    }
                    if (count($new) < count($move)) {
                        $blocks[] = $label . ': el ' . schedule_board::DAYNAMES[$c['day']] . ' solo tiene ' . count($new) . ' fecha(s) libres de feriados desde '
                            . $eff . ' hasta el fin de la clase (' . userdate($limit, '%d/%m/%Y') . ') y hacen falta ' . count($move) . '.';
                        continue;
                    }
                }
                // Sesiones de asistencia de esas fechas (las de este horario: fecha + hora de inicio).
                $pairs = [];
                foreach ($move as $i => $ymd) {
                    $found = null;
                    foreach ($sessions as $as) {
                        if (isset($taken[$as->id]) || date('Y-m-d', $as->sessdate) !== $ymd) {
                            continue;
                        }
                        if (abs(((int)date('G', $as->sessdate) * 60 + (int)date('i', $as->sessdate)) - $oa) <= 30) {
                            $found = $as;
                            break;
                        }
                    }
                    if (!$found) {
                        $blocks[] = $label . ': no se encontró la sesión de asistencia del ' . $ymd . ' (' . $s->start_time . '). Revise la clase antes de moverla.';
                        continue 2;
                    }
                    $taken[$found->id] = true;
                    if ($found->sessdate <= $now) {
                        $blocks[] = $label . ': la sesión del ' . $ymd . ' ya empezó o pasó.';
                        continue 2;
                    }
                    if ($DB->record_exists('attendance_log', ['sessionid' => $found->id]) || (int)$found->lasttaken) {
                        $blocks[] = $label . ': la sesión del ' . $ymd . ' ya tiene asistencia tomada.';
                        continue 2;
                    }
                    $rel = $DB->get_record('gmk_bbb_attendance_relation', ['attendancesessionid' => $found->id], '*', IGNORE_MULTIPLE);
                    $bbb = $rel && $rel->bbbid ? $DB->get_record('bigbluebuttonbn', ['id' => $rel->bbbid]) : null;
                    if ($bbb && ($DB->record_exists_select('bigbluebuttonbn_logs', "bigbluebuttonbnid = ? AND log NOT IN ('Add', 'Edit')", [$bbb->id])
                            || $DB->record_exists('bigbluebuttonbn_recordings', ['bigbluebuttonbnid' => $bbb->id]))) {
                        $blocks[] = $label . ': la sala BBB del ' . $ymd . ' ya tiene uso o grabación.';
                        continue 2;
                    }
                    $ns = strtotime($new[$i] . ' ' . schedule_board::to_hhmm($c['start']));
                    $pairs[] = ['sessionid' => (int)$found->id, 'old' => date('Y-m-d H:i', $found->sessdate), 'new' => date('Y-m-d H:i', $ns),
                        'newts' => $ns, 'bbbid' => $bbb ? (int)$bbb->id : 0];
                }
                $cls['schedules'][] = ['sid' => $sid, 'from' => schedule_board::DAYNAMES[$oday] . ' ' . $s->start_time . '–' . $s->end_time
                    . ($s->classroomid || $k->classroomid ? ' · ' . ($rooms[(int)($s->classroomid ?: $k->classroomid)]->name ?? '') : ''),
                    'to' => schedule_board::DAYNAMES[$c['day']] . ' ' . schedule_board::to_hhmm($c['start']) . '–' . schedule_board::to_hhmm($c['end'])
                        . ($roomid ? ' · ' . ($rooms[$roomid]->name ?? '') : ''),
                    'day' => $c['day'], 'start' => $c['start'], 'end' => $c['end'], 'roomid' => $roomid,
                    'keep' => $keep, 'dates' => array_merge($keep, $new), 'sessions' => $pairs, 'dur' => $c['end'] - $c['start'],
                    'same' => $c['day'] === $oday && $c['start'] === $oa && $c['end'] === $ob && $roomid === (int)($s->classroomid ?: $k->classroomid)];
                if ($roomid && $rooms[$roomid]->capacity && $rooms[$roomid]->capacity < count($members)) {
                    $warnings[] = $label . ': el aula ' . $rooms[$roomid]->name . ' tiene capacidad ' . $rooms[$roomid]->capacity . ' y la clase ' . count($members) . ' estudiantes.';
                }
            }
            $cls['members'] = $members;
            $cls['class'] = $k;
            $out[$cid] = $cls;
        }
        // Choques con TODAS las clases abiertas (las que se mueven, con su horario nuevo).
        $occ = self::occupancy($out);
        foreach ($out as $cid => $cls) {
            foreach ($cls['schedules'] as $si => $sc) {
                $mine = array_flip(array_map(function ($p) {
                    return substr($p['new'], 0, 10);
                }, $sc['sessions']));
                $hits = [];
                foreach ($occ as $o) {
                    if ((int)$o['classid'] === (int)$cid || $o['day'] !== $sc['day']) {
                        continue;
                    }
                    if (!($o['a'] < $sc['end'] && $sc['start'] < $o['b'])) {
                        continue;
                    }
                    $common = array_intersect_key($o['dates'], $mine);
                    if (!$common) {
                        continue;
                    }
                    $why = [];
                    if ($o['teacher'] && $o['teacher'] === (int)$cls['class']->instructorid) {
                        $why[] = 'mismo docente';
                    }
                    if ($o['room'] && $o['room'] === (int)$sc['roomid']) {
                        $why[] = 'misma aula';
                    }
                    $sh = array_intersect($cls['members'], $o['members']);
                    if ($sh) {
                        $why[] = count($sh) . ' estudiante(s) (' . implode(', ', array_slice(array_map(function ($u) use ($DB) {
                            return fullname($DB->get_record('user', ['id' => $u]));
                        }, $sh), 0, 5)) . (count($sh) > 5 ? '…' : '') . ')';
                    }
                    if ($why) {
                        $hits[] = '#' . $o['classid'] . ' ' . $o['name'] . ' ' . schedule_board::to_hhmm($o['a']) . '–' . schedule_board::to_hhmm($o['b'])
                            . ' (' . count($common) . ' fecha(s)): ' . implode(', ', $why);
                    }
                }
                $out[$cid]['schedules'][$si]['clashes'] = $hits;
                foreach ($hits as $h) {
                    $blocks[] = '#' . $cid . ' ' . $cls['name'] . ' choca con ' . $h;
                }
                // Disponibilidad del docente (aviso).
                $tid = (int)$cls['class']->instructorid;
                if ($tid && ($r = $DB->get_record('gmk_teacher_disponibility', ['userid' => $tid]))) {
                    $col = schedule_board::DAYCOLS[$sc['day']];
                    $okav = false;
                    foreach ((array)json_decode((string)$r->$col, true) as $x) {
                        if (isset($x['st'], $x['et']) && $sc['start'] * 60 >= $x['st'] && $sc['end'] * 60 <= $x['et']) {
                            $okav = true;
                        }
                    }
                    if (!$okav) {
                        $warnings[] = '#' . $cid . ' ' . $cls['name'] . ': ' . $cls['teacher'] . ' no tiene registrada disponibilidad el '
                            . schedule_board::DAYNAMES[$sc['day']] . ' ' . schedule_board::to_hhmm($sc['start']) . '–' . schedule_board::to_hhmm($sc['end']) . '.';
                    }
                }
            }
        }
        $classes = [];
        foreach ($out as $cid => $cls) {
            $sch = array_values(array_filter($cls['schedules'], function ($x) {
                return !$x['same'];
            }));
            if (!$sch) {
                continue;
            }
            // Para el informe de notificacion: correo del docente y lista de estudiantes de la clase.
            $tu = (int)$cls['class']->instructorid ? $DB->get_record('user', ['id' => (int)$cls['class']->instructorid], 'id, email') : null;
            $roster = [];
            if ($cls['members']) {
                list($in, $pp) = $DB->get_in_or_equal($cls['members']);
                foreach ($DB->get_records_select('user', "id $in AND deleted = 0", $pp, 'lastname, firstname', 'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename, email, idnumber') as $u) {
                    $roster[] = ['name' => fullname($u), 'email' => (string)$u->email, 'doc' => (string)$u->idnumber];
                }
            }
            $classes[] = ['classid' => $cid, 'name' => $cls['name'], 'teacher' => $cls['teacher'], 'teacher_email' => $tu ? (string)$tu->email : '',
                'students' => $cls['students'], 'roster' => $roster, 'schedules' => $sch];
        }
        if (!$classes && !$blocks) {
            return self::fail('Con esa fecha no cambia ninguna sesión.');
        }
        $token = sha1(json_encode([$pid, $eff, $classes]));
        return self::ok('Revisión (no se escribió nada).', ['effective' => $eff, 'classes' => $classes, 'blocks' => array_values(array_unique($blocks)),
            'warnings' => array_values(array_unique($warnings)), 'token' => $token,
            'sessions' => array_sum(array_map(function ($c) {
                return array_sum(array_map(function ($s) {
                    return count($s['sessions']);
                }, $c['schedules']));
            }, $classes))]);
    }

    /** Ocupacion de todas las clases abiertas por dia y fecha (las que se mueven, con su horario nuevo). */
    protected static function occupancy(array $moving) {
        global $DB;
        $rows = $DB->get_records_sql("SELECT s.id, s.classid, s.day, s.start_time, s.end_time, s.classroomid sroom, s.assigned_dates, s.excluded_dates,
                c.name, c.groupid, c.instructorid, c.classroomid, c.initdate, c.enddate
            FROM {gmk_class_schedules} s JOIN {gmk_class} c ON c.id = s.classid WHERE c.closed = 0");
        $members = [];
        $mem = function ($gid) use ($DB, &$members) {
            if (!$gid) {
                return [];
            }
            if (!isset($members[$gid])) {
                $members[$gid] = array_map('intval', $DB->get_fieldset_select('groups_members', 'userid', 'groupid = ?', [$gid]));
            }
            return $members[$gid];
        };
        $occ = [];
        foreach ($rows as $o) {
            $cid = (int)$o->classid;
            $chg = null;
            if (isset($moving[$cid])) {
                foreach ($moving[$cid]['schedules'] as $sc) {
                    if ((int)$sc['sid'] === (int)$o->id) {
                        $chg = $sc;
                    }
                }
            }
            $dn = schedule_board::day_num($o->day);
            $d = array_values(array_diff((array)json_decode((string)$o->assigned_dates, true), (array)json_decode((string)$o->excluded_dates, true)));
            if (!$d && $o->initdate && $o->enddate) {
                for ($t = (int)$o->initdate; $t <= (int)$o->enddate; $t += 86400) {
                    if ((int)date('N', $t) === $dn) {
                        $d[] = date('Y-m-d', $t);
                    }
                }
            }
            $teacher = (int)$o->instructorid;
            $us = array_values(array_diff($mem((int)$o->groupid), [$teacher]));
            if ($chg) {
                // Lo que queda antes de la fecha, con el horario viejo; lo de despues, con el nuevo.
                $occ[] = ['classid' => $cid, 'name' => $o->name, 'day' => $dn, 'a' => schedule_board::to_min($o->start_time), 'b' => schedule_board::to_min($o->end_time),
                    'dates' => array_flip($chg['keep']), 'teacher' => $teacher, 'room' => (int)($o->sroom ?: $o->classroomid), 'members' => $us];
                $occ[] = ['classid' => $cid, 'name' => $o->name, 'day' => $chg['day'], 'a' => $chg['start'], 'b' => $chg['end'],
                    'dates' => array_flip(array_map(function ($p) {
                        return substr($p['new'], 0, 10);
                    }, $chg['sessions'])), 'teacher' => $teacher, 'room' => (int)$chg['roomid'], 'members' => $us];
                continue;
            }
            $occ[] = ['classid' => $cid, 'name' => $o->name, 'day' => $dn, 'a' => schedule_board::to_min($o->start_time), 'b' => schedule_board::to_min($o->end_time),
                'dates' => array_flip($d), 'teacher' => $teacher, 'room' => (int)($o->sroom ?: $o->classroomid), 'members' => $us];
        }
        return $occ;
    }

    /**
     * Aplica la revision: respaldo, cambios en una transaccion y verificacion. Rechaza si la revision cambio desde que el
     * usuario la vio (token) o si tiene bloqueos.
     */
    public static function apply(array $d) {
        global $DB, $USER;
        $chk = self::plan($d);
        if (!$chk['ok']) {
            return $chk;
        }
        $plan = $chk['saved'];
        if ($plan['blocks']) {
            return self::fail('No se aplica: ' . implode(' ', $plan['blocks']));
        }
        if ((string)($d['token'] ?? '') !== $plan['token']) {
            return self::fail('Las clases cambiaron desde la revisión. Revise de nuevo antes de aplicar.');
        }
        $note = trim((string)($d['note'] ?? ''));
        if ($note === '') {
            return self::fail('Indique el motivo del cambio (queda en el respaldo).');
        }
        $now = time();
        // Respaldo de todo lo que se va a tocar.
        $backup = ['motivo' => $note, 'escenario' => (string)($d['scenario'] ?? ''), 'desde' => $plan['effective'], 'revision' => $plan, 'clases' => []];
        foreach ($plan['classes'] as $c) {
            $cid = (int)$c['classid'];
            $b = ['gmk_class' => $DB->get_record('gmk_class', ['id' => $cid]), 'gmk_class_schedules' => array_values($DB->get_records('gmk_class_schedules', ['classid' => $cid])),
                'attendance_sessions' => [], 'events' => [], 'bigbluebuttonbn' => [], 'course_modules' => []];
            foreach ($c['schedules'] as $sc) {
                foreach ($sc['sessions'] as $p) {
                    $as = $DB->get_record('attendance_sessions', ['id' => $p['sessionid']]);
                    $b['attendance_sessions'][] = $as;
                    if ($as->caleventid && ($e = $DB->get_record('event', ['id' => $as->caleventid]))) {
                        $b['events'][] = $e;
                    }
                    if ($p['bbbid'] && ($r = $DB->get_record('bigbluebuttonbn', ['id' => $p['bbbid']]))) {
                        $b['bigbluebuttonbn'][] = $r;
                        foreach ($DB->get_records('event', ['modulename' => 'bigbluebuttonbn', 'instance' => $r->id]) as $e) {
                            $b['events'][] = $e;
                        }
                        $cm = get_coursemodule_from_instance('bigbluebuttonbn', $r->id, 0, false, IGNORE_MISSING);
                        if ($cm) {
                            $b['course_modules'][] = $DB->get_record('course_modules', ['id' => $cm->id]);
                        }
                    }
                }
            }
            $backup['clases'][$cid] = $b;
        }
        planning_config::log_backup('escenario_aplicar_' . (int)$d['periodid'], $backup);

        $tx = $DB->start_delegated_transaction();
        foreach ($plan['classes'] as $c) {
            $cid = (int)$c['classid'];
            $k = $DB->get_record('gmk_class', ['id' => $cid], '*', MUST_EXIST);
            foreach ($c['schedules'] as $sc) {
                $start = schedule_board::to_hhmm($sc['start']);
                $end = schedule_board::to_hhmm($sc['end']);
                $dur = ($sc['end'] - $sc['start']) * 60;
                $s = $DB->get_record('gmk_class_schedules', ['id' => $sc['sid']], '*', MUST_EXIST);
                $upd = (object)['id' => $s->id, 'day' => self::DAYKEY[$sc['day']], 'start_time' => $start, 'end_time' => $end,
                    'classroomid' => $sc['roomid'] ?: $s->classroomid, 'assigned_dates' => json_encode(array_values($sc['dates'])), 'timemodified' => $now];
                $DB->update_record('gmk_class_schedules', $upd);
                foreach ($sc['sessions'] as $p) {
                    $as = $DB->get_record('attendance_sessions', ['id' => $p['sessionid']], '*', MUST_EXIST);
                    $ns = (int)$p['newts'];
                    $DB->update_record('attendance_sessions', (object)['id' => $as->id, 'sessdate' => $ns, 'duration' => $dur, 'timemodified' => $now]);
                    if ($as->caleventid && $DB->record_exists('event', ['id' => $as->caleventid])) {
                        $DB->update_record('event', (object)['id' => $as->caleventid, 'timestart' => $ns, 'timesort' => $ns, 'timeduration' => $dur, 'timemodified' => $now]);
                    }
                    if ($p['bbbid'] && ($r = $DB->get_record('bigbluebuttonbn', ['id' => $p['bbbid']]))) {
                        $before = max(0, (int)$as->sessdate - (int)$r->openingtime);   // la sala abre antes (hoy 10 min)
                        $oldname = (string)$r->name;
                        $newname = preg_replace('/-' . (int)$as->sessdate . '$/', '-' . $ns, $oldname);
                        $DB->update_record('bigbluebuttonbn', (object)['id' => $r->id, 'name' => $newname, 'openingtime' => $ns - $before,
                            'closingtime' => $ns + $dur, 'timemodified' => $now]);
                        foreach ($DB->get_records('event', ['modulename' => 'bigbluebuttonbn', 'instance' => $r->id]) as $e) {
                            $eu = (object)['id' => $e->id, 'name' => str_replace($oldname, $newname, (string)$e->name), 'timemodified' => $now];
                            if ($e->eventtype === 'meeting_start') {
                                $eu->timestart = $ns - $before;
                                $eu->timesort = $ns - $before;
                            }
                            $DB->update_record('event', $eu);
                        }
                        $cm = get_coursemodule_from_instance('bigbluebuttonbn', $r->id, 0, false, IGNORE_MISSING);
                        if ($cm && (string)$cm->idnumber !== '') {
                            $DB->set_field('course_modules', 'idnumber', \core_text::substr($newname, 0, 100), ['id' => $cm->id]);
                        }
                        if (strpos((string)$as->description, 'bbbModule:' . $oldname) !== false) {
                            $DB->set_field('attendance_sessions', 'description', str_replace('bbbModule:' . $oldname, 'bbbModule:' . $newname, $as->description), ['id' => $as->id]);
                        }
                    }
                }
            }
            // Datos de la clase: dias (mascara), y hora/aula si tiene un solo horario.
            $all = $DB->get_records('gmk_class_schedules', ['classid' => $cid], 'id');
            $mask = array_fill(0, 7, '0');
            foreach ($all as $s) {
                $dn = schedule_board::day_num($s->day);
                if ($dn) {
                    $mask[$dn - 1] = '1';
                }
            }
            $cu = (object)['id' => $cid, 'classdays' => implode('/', $mask), 'timemodified' => $now];
            $last = '';
            foreach ($c['schedules'] as $sc) {
                $last = max($last, (string)end($sc['dates']));
            }
            if ($last && strtotime($last . ' 23:59:59') > (int)$k->enddate) {
                $cu->enddate = strtotime($last . ' 23:59:59');
            }
            if (count($all) === 1) {
                $s = reset($all);
                $a = schedule_board::to_min($s->start_time);
                $b = schedule_board::to_min($s->end_time);
                $cu->inittime = $s->start_time;
                $cu->endtime = $s->end_time;
                $cu->inithourformatted = date('h:i A', strtotime('2000-01-01 ' . $s->start_time));
                $cu->endhourformatted = date('h:i A', strtotime('2000-01-01 ' . $s->end_time));
                $cu->inittimets = $a * 60;
                $cu->endtimets = $b * 60;
                $cu->classduration = ($b - $a) * 60;
                if ((int)$s->classroomid) {
                    $cu->classroomid = (int)$s->classroomid;
                }
            }
            $DB->update_record('gmk_class', $cu);
        }
        $tx->allow_commit();
        foreach ($plan['classes'] as $c) {
            $k = $DB->get_record('gmk_class', ['id' => (int)$c['classid']]);
            if ($k && $k->corecourseid) {
                rebuild_course_cache((int)$k->corecourseid, true);
            }
        }
        \cache_helper::purge_by_event('changesincalendar');

        // Verificacion.
        $bad = [];
        foreach ($plan['classes'] as $c) {
            $cid = (int)$c['classid'];
            $old = $backup['clases'][$cid]['gmk_class'];
            $k = $DB->get_record('gmk_class', ['id' => $cid]);
            if ((int)$k->instructorid !== (int)$old->instructorid || (int)$k->groupid !== (int)$old->groupid || (int)$k->closed !== (int)$old->closed
                    || (int)$k->approved !== (int)$old->approved || (int)$k->periodid !== (int)$old->periodid) {
                $bad[] = '#' . $cid . ': cambió algo que no debía (docente, grupo, estado o periodo).';
            }
            foreach ($c['schedules'] as $sc) {
                $s = $DB->get_record('gmk_class_schedules', ['id' => $sc['sid']]);
                if (schedule_board::day_num($s->day) !== (int)$sc['day'] || schedule_board::to_min($s->start_time) !== (int)$sc['start']
                        || schedule_board::to_min($s->end_time) !== (int)$sc['end'] || json_decode($s->assigned_dates, true) !== array_values($sc['dates'])) {
                    $bad[] = '#' . $cid . ': el horario no quedó como se esperaba.';
                }
                foreach ($sc['sessions'] as $p) {
                    $as = $DB->get_record('attendance_sessions', ['id' => $p['sessionid']]);
                    if (!$as || (int)$as->sessdate !== (int)$p['newts']) {
                        $bad[] = '#' . $cid . ': la sesión ' . $p['sessionid'] . ' no quedó en ' . $p['new'] . '.';
                        continue;
                    }
                    if ($as->caleventid && ($e = $DB->get_record('event', ['id' => $as->caleventid])) && (int)$e->timestart !== (int)$p['newts']) {
                        $bad[] = '#' . $cid . ': el evento de la sesión ' . $p['sessionid'] . ' no se movió.';
                    }
                    if ($p['bbbid']) {
                        $r = $DB->get_record('bigbluebuttonbn', ['id' => $p['bbbid']]);
                        if (!$r || date('Y-m-d', $r->openingtime) !== substr($p['new'], 0, 10) || (int)$r->closingtime !== (int)$p['newts'] + ($sc['end'] - $sc['start']) * 60) {
                            $bad[] = '#' . $cid . ': la sala BBB de la sesión ' . $p['sessionid'] . ' no quedó en ' . $p['new'] . '.';
                        }
                    }
                }
            }
            $oldn = count(array_filter($backup['clases'][$cid]['attendance_sessions']));
            $aid = $k->attendancemoduleid ? (int)$DB->get_field('course_modules', 'instance', ['id' => $k->attendancemoduleid]) : 0;
            $total = $aid ? $DB->count_records('attendance_sessions', ['attendanceid' => $aid]) : 0;
            if ($oldn && !$total) {
                $bad[] = '#' . $cid . ': la clase quedó sin sesiones.';
            }
        }
        // Registro en el escenario.
        $sid = preg_replace('/[^a-z0-9]/', '', (string)($d['scenario'] ?? ''));
        $list = self::all((int)$d['periodid']);
        if ($sid && isset($list[$sid])) {
            $list[$sid]['applied'][] = ['at' => $now, 'by' => fullname($USER), 'effective' => $plan['effective'],
                'classes' => array_map(function ($c) {
                    return (int)$c['classid'];
                }, $plan['classes']), 'ok' => !$bad];
            self::write((int)$d['periodid'], $list);
        }
        $msg = 'Cambio aplicado desde el ' . userdate(strtotime($plan['effective']), '%d/%m/%Y') . ': ' . count($plan['classes']) . ' clase(s), '
            . $plan['sessions'] . ' sesión(es) movidas. Se guardó un respaldo de todo lo modificado.';
        if ($bad) {
            return ['ok' => false, 'message' => 'Se aplicó, pero la verificación encontró diferencias: ' . implode(' ', $bad)
                . ' Avise a soporte; hay respaldo de lo anterior.', 'saved' => ['bad' => $bad]];
        }
        return self::ok($msg . ' Verificación correcta.', ['classes' => count($plan['classes']), 'sessions' => $plan['sessions']]);
    }
}
