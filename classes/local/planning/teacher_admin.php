<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Habilidades y disponibilidad de docentes desde el tablero de planificacion.
 *
 * Usa las tablas existentes (gmk_teacher_skill_relation, gmk_teacher_disponibility) con cambios PUNTUALES:
 *  - Habilidad: agrega o quita UNA relacion docente-asignatura. No borra ni recrea las demas (el servicio
 *    update_teacher_disponibility borra y recrea todas; aqui no se usa).
 *  - Disponibilidad: cambia SOLO la columna del dia indicado. Si el docente no tiene fila, se crea con los
 *    demas dias vacios. No se permite dejar fuera de la disponibilidad una clase abierta que ya dicta ese dia.
 * La disponibilidad y las habilidades son globales (valen para todos los periodos), igual que en el panel de
 * disponibilidad y el Planificador Academico.
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Grupo Makro
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\planning;

defined('MOODLE_INTERNAL') || die();

class teacher_admin {

    protected static function ok($msg, $saved) {
        return ['ok' => true, 'message' => $msg, 'saved' => $saved];
    }

    protected static function fail($msg) {
        return ['ok' => false, 'message' => $msg, 'saved' => null];
    }

    protected static function teacher($uid) {
        global $DB;
        return $DB->get_record('user', ['id' => (int)$uid, 'deleted' => 0], 'id, firstname, lastname, suspended');
    }

    /** Estado actual de un docente (para releer despues de guardar). */
    public static function state($uid) {
        global $DB;
        $skills = array_map('intval', $DB->get_fieldset_sql("SELECT s.courseid FROM {gmk_teacher_skill_relation} r
            JOIN {gmk_teacher_skill} s ON s.id = r.skillid WHERE r.userid = ? AND s.courseid > 0", [(int)$uid]));
        $avail = [];
        if ($row = $DB->get_record('gmk_teacher_disponibility', ['userid' => (int)$uid], '*', IGNORE_MULTIPLE)) {
            foreach (schedule_board::DAYCOLS as $dn => $col) {
                $r = [];
                foreach ((array)json_decode((string)$row->$col, true) as $x) {
                    if (isset($x['st'], $x['et'])) {
                        $r[] = [(int)round($x['st'] / 60), (int)round($x['et'] / 60)];
                    }
                }
                if ($r) {
                    $avail[$dn] = $r;
                }
            }
        }
        return ['id' => (int)$uid, 'skills' => $skills, 'avail' => (object)$avail, 'has_avail' => !empty($row)];
    }

    /** Agrega (add = true) o quita una habilidad (asignatura) a un docente. */
    public static function skill(array $d, $add) {
        global $DB, $USER;
        $uid = (int)($d['userid'] ?? 0);
        $cid = (int)($d['courseid'] ?? 0);
        $u = self::teacher($uid);
        if (!$u) {
            return self::fail('El docente no existe.');
        }
        $skill = $DB->get_record('gmk_teacher_skill', ['courseid' => $cid], 'id, name', IGNORE_MULTIPLE);
        if (!$skill) {
            return self::fail('La asignatura no tiene habilidad registrada (se crean solas con cada curso). Avise a soporte.');
        }
        $existing = $DB->get_records('gmk_teacher_skill_relation', ['userid' => $uid, 'skillid' => $skill->id]);
        $now = time();
        if ($add) {
            if ($existing) {
                return self::ok(fullname($u) . ' ya tenía la habilidad ' . $skill->name . '.', self::state($uid));
            }
            $id = $DB->insert_record('gmk_teacher_skill_relation', (object)['userid' => $uid, 'skillid' => $skill->id,
                'academicperiodid' => 0, 'usermodified' => $USER->id, 'timecreated' => $now, 'timemodified' => $now]);
            planning_config::log_backup('docente_habilidad', ['accion' => 'agregar', 'userid' => $uid, 'skillid' => (int)$skill->id, 'nuevo' => $id]);
            return self::ok('Habilidad agregada: ' . fullname($u) . ' puede dictar ' . $skill->name . '.', self::state($uid));
        }
        if (!$existing) {
            return self::ok('El docente no tenía esa habilidad.', self::state($uid));
        }
        $DB->delete_records('gmk_teacher_skill_relation', ['userid' => $uid, 'skillid' => $skill->id]);
        planning_config::log_backup('docente_habilidad', ['accion' => 'quitar', 'antes' => array_values($existing)]);
        return self::ok('Habilidad quitada: ' . $skill->name . '.', self::state($uid));
    }

    /**
     * Fija la disponibilidad de UN dia de un docente.
     *
     * @param array $d ['userid', 'day' (1-7), 'ranges' => [['start' => 'HH:MM', 'end' => 'HH:MM'], ...]]
     */
    public static function availability(array $d) {
        global $DB, $USER;
        $uid = (int)($d['userid'] ?? 0);
        $day = (int)($d['day'] ?? 0);
        $u = self::teacher($uid);
        if (!$u) {
            return self::fail('El docente no existe.');
        }
        if (!isset(schedule_board::DAYCOLS[$day])) {
            return self::fail('Día no válido.');
        }
        $ranges = [];
        foreach ((array)($d['ranges'] ?? []) as $r) {
            $a = schedule_board::to_min($r['start'] ?? '');
            $b = schedule_board::to_min($r['end'] ?? '');
            if ($a < 0 || $b <= $a || $b > 24 * 60) {
                return self::fail('Revise las franjas: la hora de fin debe ser mayor que la de inicio.');
            }
            $ranges[] = [$a, $b];
        }
        usort($ranges, function ($x, $y) {
            return $x[0] <=> $y[0];
        });
        // Une franjas que se tocan o se solapan.
        $merged = [];
        foreach ($ranges as $r) {
            $n = count($merged);
            if ($n && $r[0] <= $merged[$n - 1][1]) {
                $merged[$n - 1][1] = max($merged[$n - 1][1], $r[1]);
            } else {
                $merged[] = $r;
            }
        }
        // Clases abiertas que el docente ya dicta ese dia deben quedar dentro.
        $out = [];
        foreach ($DB->get_records_sql("SELECT s.id, c.name, s.day, s.start_time, s.end_time
                FROM {gmk_class_schedules} s JOIN {gmk_class} c ON c.id = s.classid
               WHERE c.instructorid = ? AND c.closed = 0 AND c.enddate > ?", [$uid, time()]) as $s) {
            if (schedule_board::day_num($s->day) !== $day) {
                continue;
            }
            $a = schedule_board::to_min($s->start_time);
            $b = schedule_board::to_min($s->end_time);
            $inside = false;
            foreach ($merged as $r) {
                if ($a >= $r[0] && $b <= $r[1]) {
                    $inside = true;
                }
            }
            if (!$inside) {
                $out[] = $s->name . ' (' . $s->start_time . '-' . $s->end_time . ')';
            }
        }
        if ($out) {
            return self::fail('No se puede: el docente ya dicta clases ese día fuera de esas franjas: ' . implode('; ', $out) . '.');
        }
        $json = json_encode(array_map(function ($r) {
            return ['st' => $r[0] * 60, 'et' => $r[1] * 60];
        }, $merged));
        $col = schedule_board::DAYCOLS[$day];
        $rows = $DB->get_records('gmk_teacher_disponibility', ['userid' => $uid], 'id ASC');
        $now = time();
        $tx = $DB->start_delegated_transaction();
        if ($rows) {
            $row = reset($rows);
            if ((string)$row->$col === $json) {
                $tx->allow_commit();
                return self::ok('Sin cambios.', self::state($uid));
            }
            $DB->update_record('gmk_teacher_disponibility', (object)['id' => $row->id, $col => $json,
                'usermodified' => $USER->id, 'timemodified' => $now]);
            $before = ['id' => (int)$row->id, $col => $row->$col];
        } else {
            $rec = ['userid' => $uid, 'academicperiodid' => 0, 'usermodified' => $USER->id, 'timecreated' => $now, 'timemodified' => $now];
            foreach (schedule_board::DAYCOLS as $c) {
                $rec[$c] = '[]';
            }
            $rec[$col] = $json;
            $before = ['nueva_fila' => $DB->insert_record('gmk_teacher_disponibility', (object)$rec)];
        }
        $tx->allow_commit();
        planning_config::log_backup('docente_disponibilidad', ['userid' => $uid, 'dia' => $day, 'antes' => $before, 'despues' => $json]);
        return self::ok('Disponibilidad del ' . schedule_board::DAYNAMES[$day] . ' guardada para ' . fullname($u) . '.', self::state($uid));
    }
}
