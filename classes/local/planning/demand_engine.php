<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Motor de demanda por cohorte para un periodo academico. SOLO LECTURA.
 *
 * Un periodo academico (2026-V, 2026-VI...) es un bimestre de clases: los periodos se suceden cada
 * dos meses y todos los estudiantes activos cursan en cada uno. Para cada estudiante calcula que
 * asignaturas le corresponden en el periodo que se planifica:
 *
 *  - Nivel: el nivel (ordinal de bimestre) al inicio del periodo sale de la regla oficial de avance
 *    (academic_progress::expected_levels a esa fecha) y nunca baja del registrado.
 *  - Cohorte: plan + periodo de ingreso + jornada. La cohorte avanza unida; quien no esta en el
 *    nivel de su cohorte queda marcado como desfasado.
 *  - Categorias de cada asignatura pendiente:
 *      nivel     la que su plan pone en ese bimestre
 *      atrasada  de un bimestre anterior y nunca cursada (se ve con otra cohorte que este en ese nivel)
 *      repite    reprobada (gmk_course_progre.status = 5) y no aprobada despues. NO se carga sola: no cuenta para
 *                el quorum ni la carga; se le avisa al aprobar el horario (repeat_offers)
 *      adelanto  de los dos bimestres siguientes, con prelaciones cumplidas (solo para completar carga)
 *  - Prelaciones (campo de curso 'pre'): cumplida si esta aprobada; "condicionada" si se esta
 *    cursando o en revalida; reprobada o sin cursar = no cumplida. La nota oficial (gmk_course_progre)
 *    manda sobre la finalizacion de Moodle.
 *
 * El resultado alimenta la pantalla de demanda y, mas adelante, el agrupador.
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Grupo Makro
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\planning;

use local_grupomakro_core\local\academic_progress;

defined('MOODLE_INTERNAL') || die();

class demand_engine {

    /** Estados de gmk_course_progre (ver progress_manager). */
    const ST_NOT_AVAILABLE = 0;
    const ST_AVAILABLE = 1;
    const ST_IN_PROGRESS = 2;
    const ST_COMPLETED = 3;
    const ST_APPROVED = 4;
    const ST_FAILED = 5;
    const ST_REVALIDA = [6, 7];
    const ST_MIGRATION = 99;

    const CAT_NIVEL = 'nivel';
    const CAT_ATRASADA = 'atrasada';
    const CAT_REPITE = 'repite';
    const CAT_ADELANTO = 'adelanto';

    /** Cuantos bimestres hacia adelante se consideran para adelantar. */
    const ADELANTO_WINDOW = 2;

    /** @var \stdClass periodo academico (gmk_academic_periods) */
    protected $period;
    /** @var \stdClass fila de gmk_academic_calendar */
    protected $calendar;
    /** @var int[] planes a incluir (vacio = todos menos los excluidos) */
    protected $planfilter;

    public function __construct($academicperiodid, array $planids = []) {
        global $DB;
        $this->period = $DB->get_record('gmk_academic_periods', ['id' => $academicperiodid], '*', MUST_EXIST);
        $this->calendar = $DB->get_record('gmk_academic_calendar', ['academicperiodid' => $academicperiodid]);
        $this->planfilter = array_map('intval', $planids);
    }

    /** Carga minima razonable por bloque (configurable). */
    public static function min_load() {
        $v = (int)get_config('local_grupomakro_core', 'planning_min_load');
        return $v > 0 ? $v : 3;
    }

    public static function normalize_jornada($j) {
        $j = trim((string)$j);
        foreach (['Diurna', 'Nocturna', 'Sabatina', 'Dominical'] as $valid) {
            if (strcasecmp($j, $valid) === 0 || ($j !== '' && stripos($j, substr($valid, 0, 5)) === 0)) {
                return $valid;
            }
        }
        return '';
    }

    /** Ordinal de bimestre -> etiqueta "C2.B1". */
    public static function ord_label($ord) {
        if ($ord <= 0) {
            return '-';
        }
        return 'C' . (int)ceil($ord / 2) . '.B' . ((($ord - 1) % 2) + 1);
    }

    /**
     * Calcula la demanda.
     *
     * @return array period, students, cohorts, demand, totals, issues (o error)
     */
    public function compute() {
        global $DB;

        $issues = [];
        // Un periodo academico = un bimestre de clases (las clases del periodo se crean en su primer bloque).
        // Inicio: bloque 1 del calendario academico; si no esta cargado, la fecha de inicio del periodo.
        $cal = $this->calendar;
        $start = ($cal && !empty($cal->block1start)) ? (int)$cal->block1start : (int)$this->period->startdate;
        $end = ($cal && !empty($cal->block1end)) ? (int)$cal->block1end : 0;
        if (!$start) {
            return ['error' => 'El periodo ' . $this->period->name . ' no tiene fecha de inicio. '
                . 'Cargue el calendario academico del periodo antes de planificar.'];
        }
        $period = ['id' => (int)$this->period->id, 'name' => $this->period->name, 'start' => $start, 'end' => $end];

        // 1. Nivel de cada estudiante al inicio del periodo, con la regla oficial de avance.
        $lev = academic_progress::expected_levels($start + 3600);
        $levelbyllu = [];
        foreach ($lev['items'] as $it) {
            $levelbyllu[(int)$it['row']->id] = ['ord' => (int)$it['ord'], 'curord' => (int)$it['curord'], 'n' => (int)$it['n']];
        }

        // 2. Estructura de planes: niveles, bimestres, asignaturas y prelaciones.
        $planperiods = [];
        foreach ($DB->get_records('local_learning_periods', null, 'id ASC') as $x) {
            $planperiods[(int)$x->learningplanid][] = (int)$x->id;
        }
        $subpos = [];
        foreach ($DB->get_records('local_learning_subperiods', null, 'id ASC') as $x) {
            $subpos[(int)$x->periodid][] = (int)$x->id;
        }
        $plans = $DB->get_records('local_learning_plans', null, '', 'id, name, shortname');
        $prefield = (int)$DB->get_field('customfield_field', 'id', ['shortname' => 'pre']);
        $prejoin = $prefield ? "LEFT JOIN {customfield_data} cfd ON cfd.instanceid = c.id AND cfd.fieldid = $prefield" : '';
        $presel = $prefield ? ', cfd.value AS pre' : ", '' AS pre";
        $courses = $DB->get_records_sql(
            "SELECT llc.id, llc.learningplanid, llc.courseid, llc.periodid, llc.subperiodid, llc.isrequired,
                    c.fullname, c.shortname $presel
               FROM {local_learning_courses} llc
               JOIN {course} c ON c.id = llc.courseid
               $prejoin
           ORDER BY llc.learningplanid, llc.periodid, llc.subperiodid, llc.position, llc.id");
        $shortnametoid = $DB->get_records_menu('course', null, '', 'shortname, id');
        $coursename = [];
        $plancourses = []; // plan => courseid => {llcid, ord, name, prereqs[]}
        foreach ($courses as $c) {
            $pid = (int)$c->learningplanid;
            $lpos = array_search((int)$c->periodid, $planperiods[$pid] ?? []);
            $spos = array_search((int)$c->subperiodid, $subpos[(int)$c->periodid] ?? []);
            $ord = ($lpos === false) ? 0 : $lpos * 2 + ($spos === false ? 1 : $spos + 1);
            $prereqs = [];
            foreach (array_filter(array_map('trim', explode(',', (string)$c->pre))) as $sn) {
                if (isset($shortnametoid[$sn])) {
                    $prereqs[] = (int)$shortnametoid[$sn];
                }
            }
            if ($lpos === false || ($spos === false && (int)$c->subperiodid > 0)) {
                $issues[] = ['type' => 'estructura_invalida', 'planid' => $pid, 'llcid' => (int)$c->id,
                    'courseid' => (int)$c->courseid, 'name' => $c->fullname,
                    'detail' => $lpos === false ? 'cuatrimestre no pertenece al plan'
                        : 'bimestre ' . (int)$c->subperiodid . ' no pertenece al cuatrimestre ' . (int)$c->periodid];
            }
            $coursename[(int)$c->courseid] = $c->fullname;
            $plancourses[$pid][(int)$c->courseid] = (object)[
                'llcid' => (int)$c->id, 'courseid' => (int)$c->courseid, 'ord' => $ord,
                'nosub' => ($spos === false), 'name' => $c->fullname, 'prereqs' => $prereqs,
                'required' => (int)$c->isrequired,
            ];
        }

        // 3. Estudiantes activos con contacto, ingreso y jornada.
        $fields = $DB->get_records_menu('user_info_field', null, '', 'shortname, id');
        $fj = (int)($fields['gmkjourney'] ?? 0);
        $fi = (int)($fields['periodo_ingreso'] ?? 0);
        $fd = (int)($fields['documentnumber'] ?? 0);
        list($exsql, $exparams) = $DB->get_in_or_equal(academic_progress::EXCLUDED_PLANS, SQL_PARAMS_NAMED, 'ex', false);
        $plansql = '';
        $planparams = [];
        if ($this->planfilter) {
            list($plansql, $planparams) = $DB->get_in_or_equal($this->planfilter, SQL_PARAMS_NAMED, 'pf');
            $plansql = "AND llu.learningplanid $plansql";
        }
        $rows = $DB->get_records_sql(
            "SELECT llu.id, llu.userid, llu.learningplanid, llu.currentperiodid, llu.currentsubperiodid,
                    llu.academicperiodid, u.firstname, u.lastname, u.idnumber, u.username, u.email, u.phone1, u.phone2,
                    u.suspended, dj.data AS jornada, di.data AS ingreso, dd.data AS documento
               FROM {local_learning_users} llu
               JOIN {user} u ON u.id = llu.userid AND u.deleted = 0
          LEFT JOIN {user_info_data} dj ON dj.userid = u.id AND dj.fieldid = $fj
          LEFT JOIN {user_info_data} di ON di.userid = u.id AND di.fieldid = $fi
          LEFT JOIN {user_info_data} dd ON dd.userid = u.id AND dd.fieldid = $fd
              WHERE llu.userrolename = 'student' AND llu.status = 'activo'
                AND llu.learningplanid $exsql $plansql
           ORDER BY llu.learningplanid, u.lastname, u.firstname", array_merge($exparams, $planparams));

        // 4. Historial academico (una pasada por tabla).
        $userids = [];
        foreach ($rows as $r) {
            $userids[(int)$r->userid] = true;
        }
        $hist = []; // userid => courseid => status (el "mejor" estado si hay varias filas)
        $rank = [self::ST_APPROVED => 9, self::ST_COMPLETED => 8, self::ST_IN_PROGRESS => 7, 6 => 6, 7 => 6,
                 self::ST_MIGRATION => 5, self::ST_FAILED => 4, self::ST_AVAILABLE => 2, self::ST_NOT_AVAILABLE => 1];
        $rs = $DB->get_recordset_sql("SELECT id, userid, courseid, status FROM {gmk_course_progre}");
        foreach ($rs as $r) {
            $u = (int)$r->userid;
            if (!isset($userids[$u])) {
                continue;
            }
            $c = (int)$r->courseid;
            $s = (int)$r->status;
            if (!isset($hist[$u][$c]) || ($rank[$s] ?? 0) > ($rank[$hist[$u][$c]] ?? 0)) {
                $hist[$u][$c] = $s;
            }
        }
        $rs->close();
        // La finalizacion de Moodle solo cuenta cuando no hay nota oficial: hay cursos "finalizados"
        // en Moodle que en gmk_course_progre estan reprobados (la nota oficial manda).
        $rs = $DB->get_recordset_sql("SELECT id, userid, course FROM {course_completions} WHERE timecompleted > 0");
        foreach ($rs as $r) {
            if (isset($userids[(int)$r->userid]) && !isset($hist[(int)$r->userid][(int)$r->course])) {
                $hist[(int)$r->userid][(int)$r->course] = self::ST_APPROVED;
            }
        }
        $rs->close();

        // 5. Por estudiante: nivel, cohorte y asignaturas del periodo.
        $students = [];
        $cohorts = [];
        foreach ($rows as $r) {
            $pid = (int)$r->learningplanid;
            $uid = (int)$r->userid;
            $key = $uid . '-' . $pid;
            $n = count($planperiods[$pid] ?? []);
            $flags = [];
            if (empty($plancourses[$pid])) {
                $issues[] = ['type' => 'plan_sin_asignaturas', 'userid' => $uid, 'planid' => $pid];
                continue;
            }
            $lv = $levelbyllu[(int)$r->id] ?? null;
            if (!$lv) {
                $flags[] = 'sin_ingreso';
                // Sin periodo de ingreso valido no hay regla de avance: se usa su nivel registrado + 1.
                $pos = array_search((int)$r->currentperiodid, $planperiods[$pid] ?? []);
                $sp = array_search((int)$r->currentsubperiodid, $subpos[(int)$r->currentperiodid] ?? []);
                $cur = ($pos === false) ? 0 : $pos * 2 + ($sp === false ? 1 : $sp + 1);
                $lv = ['ord' => min(max(1, $cur + 1), 2 * $n), 'curord' => $cur, 'n' => $n];
            }
            // Igual que el avance diario: el nivel nunca baja del registrado.
            $ord = max((int)$lv['ord'], (int)$lv['curord']);
            $last = ($ord >= 2 * $n);

            $jornada = self::normalize_jornada($r->jornada);
            if ($jornada === '') {
                $flags[] = 'sin_jornada';
            }
            if (!empty($r->suspended)) {
                $flags[] = 'suspendido';
            }
            $h = $hist[$uid] ?? [];
            $approved = function ($cid) use ($h) {
                return isset($h[$cid]) && in_array($h[$cid], [self::ST_APPROVED, self::ST_COMPLETED], true);
            };

            $subjects = [];
            $repite = [];
            $blocked = [];
            $other = []; // cursando, revalida, migracion
            foreach ($plancourses[$pid] as $cid => $pc) {
                $st = $h[$cid] ?? null;
                if ($approved($cid)) {
                    continue;
                }
                if ($st === self::ST_IN_PROGRESS || in_array($st, self::ST_REVALIDA, true) || $st === self::ST_MIGRATION) {
                    $other[] = ['courseid' => $cid, 'name' => $pc->name, 'status' => $st];
                    continue;
                }
                $isfail = ($st === self::ST_FAILED);
                if ($isfail) {
                    $cat = self::CAT_REPITE;            // reprobada: cuenta en cualquier nivel
                } else if ($pc->ord === $ord) {
                    $cat = self::CAT_NIVEL;
                } else if ($pc->ord < $ord) {
                    $cat = self::CAT_ATRASADA;
                } else if ($pc->ord <= $ord + self::ADELANTO_WINDOW) {
                    $cat = self::CAT_ADELANTO;
                } else {
                    continue; // asignatura de niveles futuros
                }
                $subjects[$cid] = (object)[
                    'courseid' => $cid, 'llcid' => $pc->llcid, 'name' => $pc->name, 'ord' => $pc->ord,
                    'level' => self::ord_label($pc->ord), 'category' => $cat,
                    'prereq' => 'ok', 'missing' => [], 'conditional' => [],
                ];
            }
            // Prelaciones: cumplida si esta aprobada; condicionada si se esta cursando o en reválida.
            foreach ($subjects as $cid => $s) {
                foreach ($plancourses[$pid][$cid]->prereqs as $pre) {
                    if ($approved($pre)) {
                        continue;
                    }
                    $pst = $h[$pre] ?? null;
                    $prename = $coursename[$pre] ?? ('curso ' . $pre);
                    if ($pst === self::ST_IN_PROGRESS || in_array($pst, self::ST_REVALIDA, true)) {
                        $s->conditional[] = $prename;
                    } else {
                        $s->missing[] = $prename;
                    }
                }
                if ($s->missing) {
                    $s->prereq = 'no';
                } else if ($s->conditional) {
                    $s->prereq = 'condicionada';
                }
            }
            // Adelantos solo con prelaciones cumplidas (no condicionadas).
            foreach ($subjects as $cid => $s) {
                if ($s->category === self::CAT_ADELANTO && $s->prereq !== 'ok') {
                    unset($subjects[$cid]);
                } else if ($s->category === self::CAT_REPITE) {
                    $repite[] = $cid;
                } else if ($s->prereq === 'no') {
                    $blocked[] = $cid;
                }
            }

            // Carga: lo que le toca (sin adelantos ni bloqueadas) vs la carga normal de su bimestre en el plan.
            $normal = 0;
            foreach ($plancourses[$pid] as $pc) {
                if ($pc->ord === $ord) {
                    $normal++;
                }
            }
            // Carga: las reprobadas NO se cargan automaticamente (el repitente las solicita y paga en el area
            // academica cuando hay una clase aprobada), asi que no cuentan para la carga ni para la demanda.
            $load = 0;
            $available = 0;
            foreach ($subjects as $s) {
                if ($s->prereq === 'no' || $s->category === self::CAT_REPITE) {
                    continue;
                }
                $available++;
                if ($s->category !== self::CAT_ADELANTO) {
                    $load++;
                }
            }
            if ($load < self::min_load() && $available > $load) {
                $flags[] = 'carga_baja';
            }
            $pending = 0;
            $beyond = 0; // pendientes que NO se le proyectan en este periodo (de niveles posteriores)
            $otherids = array_column($other, 'courseid');
            foreach ($plancourses[$pid] as $cid => $pc) {
                if (!$approved($cid)) {
                    $pending++;
                    $isadelanto = isset($subjects[$cid]) && $subjects[$cid]->category === self::CAT_ADELANTO;
                    if ((!isset($subjects[$cid]) && !in_array($cid, $otherids, true)) || $isadelanto) {
                        $beyond++;
                    }
                }
            }
            // Egresa con este periodo: no le queda nada de niveles posteriores y todo lo que le falta (sin contar lo que
            // ya esta cursando) cabe en la carga de un periodo. Si alguna de esas asignaturas no alcanza el quorum, no
            // termina a tiempo. Quien debe muchas atrasadas no egresa en un solo periodo aunque este en el ultimo nivel.
            $nowcount = 0;
            foreach ($subjects as $sx) {
                if ($sx->category !== self::CAT_ADELANTO) {
                    $nowcount++;
                }
            }
            $finishing = $pending > 0 && $beyond === 0 && $nowcount <= max($normal, 4);

            $cohortkey = $pid . '|' . trim((string)$r->ingreso) . '|' . $jornada;
            $students[$key] = [
                'userid' => $uid, 'lluid' => (int)$r->id, 'planid' => $pid,
                'plan' => $plans[$pid]->name ?? '', 'name' => trim($r->firstname . ' ' . $r->lastname),
                'documento' => (string)($r->documento ?: $r->idnumber ?: $r->username),
                'email' => (string)$r->email, 'phone' => trim((string)$r->phone1), 'phone2' => trim((string)$r->phone2),
                'jornada' => $jornada, 'ingreso' => trim((string)$r->ingreso), 'cohort' => $cohortkey,
                'ord' => $ord, 'level' => self::ord_label($ord), 'registered' => self::ord_label((int)$lv['curord']),
                'last' => $last, 'finishing' => $finishing, 'flags' => $flags,
                'subjects' => array_values(array_map(function ($s) {
                    return (array)$s;
                }, $subjects)),
                'other' => $other, 'repite' => $repite, 'blocked' => $blocked,
                'load' => $load, 'normal_load' => $normal, 'pending_total' => $pending,
            ];
            $cohorts[$cohortkey]['members'][] = $key;
        }

        // 6. Cohortes: nivel de referencia (moda) y desfasados.
        $cohortout = [];
        foreach ($cohorts as $ck => $c) {
            $cnt = [];
            foreach ($c['members'] as $k) {
                $cnt[$students[$k]['ord']] = ($cnt[$students[$k]['ord']] ?? 0) + 1;
            }
            arsort($cnt);
            $mode = (int)key($cnt);
            $desf = 0;
            foreach ($c['members'] as $k) {
                if ($students[$k]['ord'] !== $mode) {
                    $students[$k]['flags'][] = $students[$k]['ord'] < $mode ? 'atrasado_vs_cohorte' : 'adelantado_vs_cohorte';
                    $desf++;
                }
            }
            list($pid, $ing, $jor) = explode('|', $ck);
            $cohortout[$ck] = ['key' => $ck, 'planid' => (int)$pid, 'plan' => $plans[$pid]->name ?? '', 'ingreso' => $ing,
                'jornada' => $jor, 'size' => count($c['members']), 'level' => self::ord_label($mode),
                'ord' => $mode, 'desfasados' => $desf];
        }

        // 7. Demanda por asignatura (curso Moodle) + jornada, con desglose por categoria.
        // Mezcla de carreras: la misma asignatura junta estudiantes de varias carreras, salvo que la carrera
        // completa o esa asignatura de esa carrera este marcada como "no se mezcla" (planning_config::isolation).
        $iso = planning_config::isolation();
        $demand = [];
        foreach ($students as $k => $s) {
            foreach ($s['subjects'] as $sub) {
                $isoplan = (isset($iso['plans'][$s['planid']]) || isset($iso['subjects'][$s['planid'] . '|' . $sub['courseid']]))
                    ? (int)$s['planid'] : 0;
                $dk = $sub['courseid'] . '|' . $s['jornada'] . '|' . $isoplan;
                if (!isset($demand[$dk])) {
                    $demand[$dk] = ['courseid' => $sub['courseid'], 'name' => $sub['name'],
                        'jornada' => $s['jornada'], 'isolated_plan' => $isoplan, 'total' => 0, 'ready' => 0,
                        'by_category' => [self::CAT_NIVEL => 0, self::CAT_ATRASADA => 0, self::CAT_REPITE => 0,
                            self::CAT_ADELANTO => 0],
                        'blocked' => 0, 'conditional' => 0, 'plans' => [], 'levels' => [], 'students' => []];
                }
                $d =& $demand[$dk];
                $d['students'][] = ['key' => $k, 'category' => $sub['category'], 'prereq' => $sub['prereq']];
                if ($sub['prereq'] === 'no') {
                    $d['blocked']++;
                    unset($d);
                    continue;
                }
                $d['total']++;
                $d['by_category'][$sub['category']]++;
                if ($sub['prereq'] === 'condicionada') {
                    $d['conditional']++;
                }
                // 'ready' = lo que se carga automaticamente (nivel + atrasada). Repitentes y adelantos se informan aparte.
                if ($sub['category'] === self::CAT_NIVEL || $sub['category'] === self::CAT_ATRASADA) {
                    $d['ready']++;
                }
                $d['plans'][$s['planid']] = ($d['plans'][$s['planid']] ?? 0) + 1;
                $d['levels'][$sub['level']] = ($d['levels'][$sub['level']] ?? 0) + 1;
                unset($d);
            }
        }
        uasort($demand, function ($a, $b) {
            return [$b['ready'], $a['name']] <=> [$a['ready'], $b['name']];
        });

        // 8. Totales.
        $totals = ['estudiantes' => count($students), 'cohortes' => count($cohortout), 'repitentes' => 0,
            'con_bloqueos' => 0, 'carga_baja' => 0, 'ultimo_bimestre' => 0, 'egresan' => 0, 'sin_jornada' => 0,
            'sin_ingreso' => 0, 'suspendidos' => 0];
        foreach ($students as $s) {
            $totals['repitentes'] += $s['repite'] ? 1 : 0;
            $totals['con_bloqueos'] += $s['blocked'] ? 1 : 0;
            $totals['ultimo_bimestre'] += $s['last'] ? 1 : 0;
            $totals['egresan'] += $s['finishing'] ? 1 : 0;
            foreach (['sin_jornada', 'sin_ingreso', 'carga_baja'] as $f) {
                $totals[$f] += in_array($f, $s['flags'], true) ? 1 : 0;
            }
            $totals['suspendidos'] += in_array('suspendido', $s['flags'], true) ? 1 : 0;
        }

        return [
            'period' => $period,
            'students' => $students,
            'cohorts' => array_values($cohortout),
            'demand' => array_values($demand),
            'totals' => $totals,
            'issues' => $issues,
            'computed_at' => time(),
        ];
    }
}
