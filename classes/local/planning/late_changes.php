<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Cambios despues de planificar (o de publicar) un periodo, sin tablas nuevas:
 *
 *  - Grupos adicionales (configsettings.board_extra): un grupo mas de una asignatura + jornada que se manda al tablero de
 *    horarios aunque tenga 0 o pocos estudiantes. Con el periodo publicado se suma a lo publicado sin tocar lo demas.
 *  - Sugerencias de matricula: estudiantes a los que les toca una asignatura en el periodo y no estan cargados en ninguna
 *    clase o modulo (p. ej. terminaron la matricula despues de publicar), con la clase del periodo que les corresponde.
 *    Matricular usa el camino oficial (cola de la clase y, si la clase esta aprobada, enrol_student_in_class).
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Grupo Makro
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\planning;

defined('MOODLE_INTERNAL') || die();

class late_changes {

    /** Grupos adicionales del periodo, por clave. */
    public static function extras($periodid) {
        global $DB;
        $cfg = json_decode((string)$DB->get_field('gmk_academic_periods', 'configsettings', ['id' => $periodid]), true);
        $out = [];
        foreach ((is_array($cfg) ? ($cfg['board_extra'] ?? []) : []) as $x) {
            if (!empty($x['key']) && !empty($x['courseid'])) {
                $x['extra'] = true;
                $x['type'] = 'grupo';
                $x['userids'] = array_values(array_map('intval', (array)($x['userids'] ?? [])));
                $x['adelantos'] = [];
                $out[(string)$x['key']] = $x;
            }
        }
        return $out;
    }

    protected static function ok($msg, $saved) {
        return ['ok' => true, 'message' => $msg, 'saved' => $saved];
    }

    protected static function fail($msg) {
        return ['ok' => false, 'message' => $msg, 'saved' => null];
    }

    protected static function write_extras($p, array $list) {
        global $DB, $USER;
        $cfg = json_decode((string)$DB->get_field('gmk_academic_periods', 'configsettings', ['id' => $p->id]), true);
        if (!is_array($cfg)) {
            $cfg = [];
        }
        if ($list) {
            $cfg['board_extra'] = array_values($list);
        } else {
            unset($cfg['board_extra']);
        }
        $DB->update_record('gmk_academic_periods', (object)['id' => (int)$p->id,
            'configsettings' => $cfg ? json_encode($cfg, JSON_UNESCAPED_UNICODE) : null,
            'usermodified' => $USER->id, 'timemodified' => time()]);
    }

    /** "userid|courseid" cargados en una clase (cola o grupo) o modulo del periodo. */
    protected static function loaded_in_period($periodid, $courseid) {
        global $DB;
        $out = [];
        foreach ($DB->get_records_sql("SELECT q.id, q.userid FROM {gmk_class_queue} q JOIN {gmk_class} c ON c.id = q.classid
                WHERE c.periodid = ? AND c.closed = 0 AND c.corecourseid = ?", [$periodid, $courseid]) as $r) {
            $out[(int)$r->userid] = true;
        }
        foreach ($DB->get_records_sql("SELECT gm.id, gm.userid FROM {groups_members} gm JOIN {gmk_class} c ON c.groupid = gm.groupid
                WHERE c.periodid = ? AND c.closed = 0 AND c.corecourseid = ? AND gm.userid <> c.instructorid",
                [$periodid, $courseid]) as $r) {
            $out[(int)$r->userid] = true;
        }
        return $out;
    }

    /**
     * Agrega grupos adicionales. $d = ['periodid', 'note', 'items' => [['courseid', 'jornada', 'isolated_plan', 'userids']]]
     * Quita: $d = ['periodid', 'key'] (solo si no se publico; uno publicado se revierte desde el tablero).
     */
    public static function save_extra(array $d, $remove) {
        global $DB, $USER;
        $pid = (int)($d['periodid'] ?? 0);
        $p = $DB->get_record('gmk_academic_periods', ['id' => $pid]);
        if (!$p) {
            return self::fail('El periodo no existe.');
        }
        $list = self::extras($pid);
        $before = array_values($list);
        $pub = publisher::published($pid);
        if ($remove) {
            $key = (string)($d['key'] ?? '');
            if (!isset($list[$key])) {
                return self::fail('Ese grupo adicional no existe.');
            }
            if (!empty($pub['groups'][$key]) || !empty($pub['pending'][$key])) {
                return self::fail('Ese grupo ya se publicó: para quitarlo use "Revertir publicación" en el tablero.');
            }
            unset($list[$key]);
            $tx = $DB->start_delegated_transaction();
            self::write_extras($p, $list);
            $tx->allow_commit();
            planning_config::log_backup('grupo_adicional_quitar_' . $pid, ['antes' => $before, 'quitado' => $key]);
            return self::ok('Grupo adicional quitado del tablero.', array_values($list));
        }
        if (!planning_config::saved_proposal($pid)) {
            return self::fail('Guarde primero la propuesta de grupos: el tablero se arma sobre ella.');
        }
        $note = trim((string)($d['note'] ?? ''));
        if ($note === '') {
            return self::fail('Indique el motivo del grupo adicional.');
        }
        $items = (array)($d['items'] ?? []);
        if (!$items || count($items) > 40) {
            return self::fail('Elija entre 1 y 40 asignaturas.');
        }
        $board = (array)schedule_board::board_groups($pid);
        $added = [];
        foreach ($items as $it) {
            $cid = (int)($it['courseid'] ?? 0);
            $jornada = trim((string)($it['jornada'] ?? ''));
            $iso = (int)($it['isolated_plan'] ?? 0);
            $course = $DB->get_field('course', 'fullname', ['id' => $cid]);
            if (!$course || !$DB->record_exists('local_learning_courses', ['courseid' => $cid])) {
                return self::fail('La asignatura no existe o no está en ningún plan.');
            }
            if (!in_array($jornada, planning_config::JORNADAS, true)) {
                return self::fail('Elija la jornada (Diurna, Nocturna o Sabatina) de ' . $course . '.');
            }
            if ($iso && !$DB->record_exists('local_learning_courses', ['courseid' => $cid, 'learningplanid' => $iso])) {
                return self::fail($course . ' no está en la carrera elegida.');
            }
            $userids = array_values(array_unique(array_map('intval', (array)($it['userids'] ?? []))));
            if (count($userids) > 80) {
                return self::fail('Demasiados estudiantes en ' . $course . '.');
            }
            // Estudiantes activos de una carrera que tiene la asignatura.
            $planof = [];
            if ($userids) {
                list($usql, $uparams) = $DB->get_in_or_equal($userids);
                foreach ($DB->get_records_sql("SELECT u.id, u.userid, u.learningplanid FROM {local_learning_users} u
                        JOIN {local_learning_courses} c ON c.learningplanid = u.learningplanid AND c.courseid = ?
                        WHERE u.userid $usql AND u.userrolename = 'student' AND u.status = 'activo'", array_merge([$cid], $uparams)) as $r) {
                    $planof[(int)$r->userid][] = (int)$r->learningplanid;
                }
                $bad = array_diff($userids, array_keys($planof));
                if ($bad) {
                    return self::fail(count($bad) . ' estudiante(s) de ' . $course . ' no son estudiantes activos de una carrera con esa asignatura.');
                }
            }
            // Ni cargados ya en el periodo ni en otro grupo sin publicar de esa asignatura (se cargarian dos veces).
            $loaded = self::loaded_in_period($pid, $cid);
            $dup = array_filter($userids, function ($u) use ($loaded) {
                return isset($loaded[$u]);
            });
            if ($dup) {
                return self::fail(count($dup) . ' estudiante(s) ya están cargados en una clase de ' . $course . ' en este periodo.');
            }
            $n = 0;
            foreach (array_merge($board, $added) as $k => $g) {
                if ((int)$g['courseid'] !== $cid) {
                    continue;
                }
                if (trim((string)$g['jornada']) === $jornada && (int)($g['isolated_plan'] ?? 0) === $iso) {
                    $n = max($n, (int)($g['number'] ?? 0));
                }
                if (empty($pub['groups'][$k]) && array_intersect($userids, array_map('intval', $g['userids'] ?? []))) {
                    return self::fail('Hay estudiantes de ' . $course . ' que ya están en otro grupo sin publicar de esa asignatura ('
                        . $g['jornada'] . ' G' . (int)($g['number'] ?? 1) . '). Quítelos de allí o elija otros.');
                }
            }
            // Carrera de la clase: la aislada o la de la mayoria de los estudiantes.
            $planid = $iso;
            if (!$planid) {
                $cnt = [];
                foreach ($planof as $pls) {
                    foreach (array_unique($pls) as $pl) {
                        $cnt[$pl] = ($cnt[$pl] ?? 0) + 1;
                    }
                }
                arsort($cnt);
                $planid = $cnt ? (int)key($cnt) : 0;
            }
            $num = $n + 1;
            $key = 'x' . time() . count($added) . ':' . $cid . '|' . $jornada . '|' . $iso . '|' . $num;
            $added[$key] = ['key' => $key, 'courseid' => $cid, 'name' => $course, 'jornada' => $jornada, 'isolated_plan' => $iso,
                'planid' => $planid, 'number' => $num, 'of' => $num, 'userids' => $userids, 'note' => $note,
                'by' => (int)$USER->id, 'byname' => fullname($USER), 'time' => time()];
        }
        foreach ($added as $k => $x) {
            $list[$k] = $x;
        }
        $tx = $DB->start_delegated_transaction();
        self::write_extras($p, array_map(function ($x) {
            unset($x['extra'], $x['type'], $x['adelantos']);
            return $x;
        }, $list));
        $tx->allow_commit();
        planning_config::log_backup('grupo_adicional_' . $pid, ['antes' => $before, 'agregados' => array_values($added)]);
        $names = array_map(function ($x) {
            return $x['name'] . ' (' . $x['jornada'] . ' G' . $x['number'] . ', ' . count($x['userids']) . ' est.)';
        }, array_values($added));
        return self::ok('Grupo(s) adicional(es) enviado(s) al tablero de horarios: ' . implode('; ', $names)
            . '. Ubíquelos en el tablero y publíquelos; lo ya publicado no cambia.', array_values($added));
    }

    /** Sesiones semanales (dia, inicio, fin en minutos, desde, hasta) de las clases dadas. */
    protected static function class_slots(array $classes) {
        global $DB;
        $out = [];
        if (!$classes) {
            return $out;
        }
        list($insql, $inparams) = $DB->get_in_or_equal(array_keys($classes));
        foreach ($DB->get_records_select('gmk_class_schedules', "classid $insql", $inparams) as $s) {
            $dn = schedule_board::day_num($s->day);
            $a = schedule_board::to_min($s->start_time);
            $b = schedule_board::to_min($s->end_time);
            if ($dn && $a >= 0 && $b > $a) {
                $c = $classes[(int)$s->classid];
                $out[(int)$s->classid][] = ['day' => $dn, 'start' => $a, 'end' => $b, 'from' => (int)$c->initdate, 'to' => (int)$c->enddate];
            }
        }
        return $out;
    }

    protected static function slot_text(array $slots) {
        return implode(', ', array_map(function ($x) {
            return schedule_board::DAYNAMES[$x['day']] . ' ' . schedule_board::to_hhmm($x['start']) . '–' . schedule_board::to_hhmm($x['end']);
        }, $slots));
    }

    /**
     * Sugerencias de matricula (solo lectura): estudiantes a los que les toca la asignatura en el periodo (nivel o atrasada,
     * con prelaciones) que no estan cargados, y las clases del periodo de esa asignatura que les sirven. Los adelantos no se
     * sugieren (son opcionales).
     */
    public static function suggestions($periodid) {
        global $DB;
        $res = (new demand_engine($periodid))->compute();
        if (!empty($res['error'])) {
            return self::fail($res['error']);
        }
        $classes = $DB->get_records_select('gmk_class', 'periodid = ? AND closed = 0 AND is_module = 0', [$periodid], 'id',
            'id, name, corecourseid, shift, groupid, instructorid, classroomcapacity, approved, initdate, enddate');
        $slots = self::class_slots($classes);
        // Quien esta en cada clase (cola o grupo) y las clases de cada estudiante.
        $inclass = [];
        $userclasses = [];
        if ($classes) {
            list($insql, $inparams) = $DB->get_in_or_equal(array_keys($classes));
            foreach ($DB->get_records_select('gmk_class_queue', "classid $insql", $inparams, '', 'id, classid, userid') as $q) {
                $inclass[(int)$q->classid][(int)$q->userid] = true;
            }
            $gid = [];
            foreach ($classes as $c) {
                if ($c->groupid) {
                    $gid[(int)$c->groupid] = $c;
                }
            }
            if ($gid) {
                list($gsql, $gparams) = $DB->get_in_or_equal(array_keys($gid));
                foreach ($DB->get_records_select('groups_members', "groupid $gsql", $gparams, '', 'id, groupid, userid') as $m) {
                    $c = $gid[(int)$m->groupid];
                    if ((int)$m->userid !== (int)$c->instructorid) {
                        $inclass[(int)$c->id][(int)$m->userid] = true;
                    }
                }
            }
            foreach ($inclass as $cid => $us) {
                foreach ($us as $u => $t) {
                    $userclasses[$u][] = $cid;
                }
            }
        }
        $bycourse = [];
        foreach ($classes as $c) {
            $bycourse[(int)$c->corecourseid][] = $c;
        }
        // Quien estaba en la propuesta guardada (para distinguir a los que llegaron despues) y los excluidos por choques.
        $inproposal = [];
        $saved = planning_config::saved_proposal($periodid);
        $savedat = (int)($saved['saved_at'] ?? 0);
        // Matricula en la carrera (estudiante activo) por usuario y plan: para saber quien llego despues de planificar.
        $since = [];
        foreach ($DB->get_records_sql("SELECT id, userid, learningplanid, timecreated FROM {local_learning_users}
                WHERE userrolename = 'student' AND status = 'activo'") as $lu) {
            $since[(int)$lu->userid . '|' . (int)$lu->learningplanid] = (int)$lu->timecreated;
        }
        foreach ((array)($saved['groups'] ?? []) as $g) {
            foreach (array_merge((array)($g['userids'] ?? []), (array)($g['adelantos'] ?? [])) as $u) {
                $inproposal[(int)$g['courseid'] . '|' . (int)$u] = true;
            }
        }
        $excl = planning_config::board_exclusions($periodid);
        $rows = [];
        foreach ($res['demand'] as $d) {
            $cid = (int)$d['courseid'];
            if (empty($bycourse[$cid])) {
                continue; // la asignatura no tiene clase en el periodo
            }
            foreach ($d['students'] as $x) {
                if (!empty($x['loaded']) || $x['prereq'] === 'no' || !empty($x['flex'])
                        || !in_array($x['category'], ['nivel', 'atrasada'], true)) {
                    continue;
                }
                $s = $res['students'][$x['key']] ?? null;
                if (!$s) {
                    continue;
                }
                $uid = (int)$s['userid'];
                $opts = [];
                foreach ($bycourse[$cid] as $c) {
                    if (isset($inclass[(int)$c->id][$uid])) {
                        continue 2; // ya esta en una clase de la asignatura (el motor no lo vio)
                    }
                    $taken = count($inclass[(int)$c->id] ?? []);
                    $cap = (int)$c->classroomcapacity;
                    $clash = [];
                    foreach ($slots[(int)$c->id] ?? [] as $a) {
                        foreach ($userclasses[$uid] ?? [] as $oc) {
                            foreach ($slots[$oc] ?? [] as $b) {
                                if ($a['day'] === $b['day'] && $a['start'] < $b['end'] && $b['start'] < $a['end']
                                        && $a['from'] <= $b['to'] && $b['from'] <= $a['to']) {
                                    $clash[$oc] = $classes[$oc]->name;
                                }
                            }
                        }
                    }
                    $opts[] = ['classid' => (int)$c->id, 'name' => $c->name, 'jornada' => (string)$c->shift,
                        'same_jornada' => trim((string)$c->shift) === trim((string)$s['jornada']),
                        'schedule' => self::slot_text($slots[(int)$c->id] ?? []), 'taken' => $taken, 'capacity' => $cap,
                        'full' => $cap > 0 && $taken >= $cap, 'approved' => (int)$c->approved === 1,
                        'clash' => array_values($clash)];
                }
                if (!$opts) {
                    continue;
                }
                usort($opts, function ($a, $b) {
                    return [!$a['same_jornada'], (bool)$a['clash'], $a['full'], $a['taken']] <=> [!$b['same_jornada'], (bool)$b['clash'], $b['full'], $b['taken']];
                });
                $best = $opts[0]['same_jornada'] && !$opts[0]['clash'] && !$opts[0]['full'] ? $opts[0]['classid'] : 0;
                $isnew = $savedat && ($since[$uid . '|' . (int)$s['planid']] ?? 0) > $savedat;
                $why = isset($excl[$cid . '|' . $uid]) ? 'excluido por choque al publicar'
                    : ($isnew ? 'nuevo: se matriculó después de planificar'
                    : (isset($inproposal[$cid . '|' . $uid]) ? 'estaba en un grupo propuesto y no quedó cargado' : 'sin grupo en la propuesta'));
                $rows[] = ['userid' => $uid, 'name' => (string)$s['name'], 'documento' => (string)($s['documento'] ?? ''),
                    'plan' => (string)$s['plan'], 'level' => (string)$s['level'], 'jornada' => (string)$s['jornada'],
                    'courseid' => $cid, 'course' => (string)$d['name'], 'category' => $x['category'], 'why' => $why,
                    'is_new' => $isnew,
                    'suggested' => $best, 'options' => $opts];
            }
        }
        usort($rows, function ($a, $b) {
            return [!$a['is_new'], $a['course'], $a['name']] <=> [!$b['is_new'], $b['course'], $b['name']];
        });
        return self::ok(count($rows) . ' sugerencia(s) de matrícula (no se escribió nada).', ['rows' => $rows,
            'published' => !empty($res['published'])]);
    }

    /**
     * Matricula estudiantes en clases del periodo. $d = ['periodid', 'items' => [['userid', 'classid']]]
     * Cola de la clase (como al publicar) y, si la clase ya esta aprobada, matricula oficial (grupo, curso y avance).
     */
    public static function enrol(array $d) {
        global $DB, $USER, $CFG;
        require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
        require_once($CFG->dirroot . '/group/lib.php');
        $pid = (int)($d['periodid'] ?? 0);
        $items = (array)($d['items'] ?? []);
        if (!$DB->record_exists('gmk_academic_periods', ['id' => $pid])) {
            return self::fail('El periodo no existe.');
        }
        if (!$items || count($items) > 300) {
            return self::fail('Elija entre 1 y 300 matrículas.');
        }
        $out = [];
        $t0 = time();
        foreach ($items as $it) {
            $uid = (int)($it['userid'] ?? 0);
            $cid = (int)($it['classid'] ?? 0);
            $u = $DB->get_record('user', ['id' => $uid, 'deleted' => 0], 'id, firstname, lastname');
            $c = $DB->get_record('gmk_class', ['id' => $cid]);
            $row = ['userid' => $uid, 'classid' => $cid, 'name' => $u ? fullname($u) : ('#' . $uid), 'class' => $c ? $c->name : ('#' . $cid)];
            if (time() - $t0 > 70) {
                $out[] = $row + ['status' => 'pending', 'message' => 'Sin tiempo en esta tanda: vuelva a aplicar las que faltan.'];
                continue;
            }
            if (!$u || !$c || (int)$c->periodid !== $pid || $c->closed || $c->is_module) {
                $out[] = $row + ['status' => 'error', 'message' => 'La clase no es de este periodo o está cerrada.'];
                continue;
            }
            $llc = $DB->get_record_sql("SELECT c.id FROM {local_learning_courses} c JOIN {local_learning_users} u
                    ON u.learningplanid = c.learningplanid AND u.userid = ? AND u.userrolename = 'student' AND u.status = 'activo'
                    WHERE c.courseid = ?", [$uid, (int)$c->corecourseid], IGNORE_MULTIPLE);
            if (!$llc) {
                $out[] = $row + ['status' => 'error', 'message' => 'No es estudiante activo de una carrera con esa asignatura.'];
                continue;
            }
            if (isset(self::loaded_in_period($pid, (int)$c->corecourseid)[$uid])) {
                $out[] = $row + ['status' => 'error', 'message' => 'Ya está cargado en una clase de esa asignatura en el periodo.'];
                continue;
            }
            $qid = 0;
            try {
                $now = time();
                $qid = $DB->insert_record('gmk_class_queue', (object)['classid' => $cid, 'userid' => $uid, 'courseid' => (int)$c->courseid,
                    'timecreated' => $now, 'timemodified' => $now, 'usermodified' => (int)$USER->id]);
                if ((int)$c->approved === 1) {
                    $r = \local_grupomakro_core\local\failed_subjects_manager::enrol_student_in_class($uid, $cid, false);
                    if (($r['status'] ?? '') !== 'ok') {
                        $DB->delete_records('gmk_class_queue', ['id' => $qid]);
                        $out[] = $row + ['status' => 'error', 'message' => strip_tags((string)($r['message'] ?? 'No se pudo matricular.'))];
                        continue;
                    }
                    $out[] = $row + ['status' => 'ok', 'message' => 'Matriculado (clase aprobada: queda en el grupo y en su avance).'];
                } else {
                    $out[] = $row + ['status' => 'ok', 'message' => 'En la cola de la clase: se matricula al aprobarla.'];
                }
            } catch (\Throwable $e) {
                if ($qid && !$DB->record_exists('groups_members', ['groupid' => (int)$c->groupid, 'userid' => $uid])) {
                    $DB->delete_records('gmk_class_queue', ['id' => $qid]);
                }
                $out[] = $row + ['status' => 'error', 'message' => $e->getMessage()];
            }
        }
        planning_config::log_backup('matricula_sugerida_' . $pid, $out);
        $ok = count(array_filter($out, function ($x) {
            return $x['status'] === 'ok';
        }));
        $more = count(array_filter($out, function ($x) {
            return $x['status'] === 'pending';
        }));
        return self::ok($ok . ' de ' . count($out) . ' matrícula(s) hecha(s)' . ($more ? '; ' . $more . ' quedan para otra tanda' : '') . '.',
            ['rows' => $out, 'more' => $more > 0]);
    }
}
