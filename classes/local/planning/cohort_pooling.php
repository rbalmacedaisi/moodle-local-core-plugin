<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Juntar cohortes pequenas de periodos seguidos (SOLO LECTURA).
 *
 * Cuando una asignatura llega periodo a periodo con pocos estudiantes por jornada (una cohorte chica por ingreso) y
 * nunca alcanza el minimo, se busca el periodo de apertura que lo alcanza moviendo la menor cantidad de estudiantes
 * el menor tiempo posible:
 *  - Esperar: quien la tenia antes la ve despues. No se permite si alguna asignatura que tiene antes de esa apertura
 *    la necesita como prelacion, ni si egresa antes.
 *  - Adelantar: quien la tenia despues la ve antes. Solo si para entonces ya habra aprobado todas sus prelaciones.
 *  - Tambien puede sumarse a un grupo que ya abre solo en un periodo cercano.
 * No hay espera maxima: se juntan los periodos que hagan falta, siempre respetando las prelaciones. No se juntan
 * jornadas ni carreras que no se mezclan (la fila es asignatura + jornada + carrera aislada).
 *
 * Periodos: k = 0 es el periodo que se planifica; k >= 1, los siguientes de la proyeccion.
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Grupo Makro
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\planning;

defined('MOODLE_INTERNAL') || die();

class cohort_pooling {

    /** @var array resultado de demand_engine con horizonte (plan_courses, done) */
    protected $res;
    protected $min;
    protected $periodnames = [];
    protected $maxk = 0;
    /** @var array asignaturas que quedaron fuera por ser de cuatrimestre completo: courseid => nombre */
    protected $excluded = [];

    /** @var array grupos propuestos del periodo que se planifica: fila (asignatura|jornada|carrera) => [clave => true] */
    protected $planned = [];

    /**
     * @param array $res resultado de demand_engine con horizonte
     * @param array $rules reglas (min_group)
     * @param array $planned estudiantes de los grupos ya propuestos en el periodo que se planifica (incluye los que adelantan),
     *              por fila asignatura|jornada|carrera: se cuentan como el grupo que ya abre y no se vuelven a sugerir despues.
     */
    public function __construct(array $res, array $rules, array $planned = []) {
        $this->res = $res;
        $this->planned = $planned;
        $this->min = max(1, (int)$rules['min_group']);
        $this->periodnames[0] = $res['period']['name'];
        foreach ((array)($res['horizon'] ?? []) as $h) {
            $this->periodnames[(int)$h['k']] = $h['name'];
            $this->maxk = max($this->maxk, (int)$h['k']);
        }
    }

    /** Periodo (k) en que un estudiante ve una asignatura segun su plan; null si no esta en su plan. */
    protected function due($s, $cid) {
        $pc = $this->res['plan_courses'][$s['planid']][$cid] ?? null;
        return $pc ? max(0, (int)$pc[0] - (int)$s['ord']) : null;
    }

    protected function done($key, $cid) {
        return isset($this->res['done'][$key][$cid]);
    }

    /** Ultimo periodo (k) en que el estudiante sigue estudiando. */
    protected function lastk($s) {
        $max = 0;
        foreach ($this->res['plan_courses'][$s['planid']] ?? [] as $pc) {
            $max = max($max, (int)$pc[0]);
        }
        return max(0, $max - (int)$s['ord']);
    }

    /**
     * Puede el estudiante ver la asignatura en el periodo t (la tenia en k)? Devuelve '' si puede o el motivo.
     */
    public function movable($key, $cid, $k, $t) {
        $s = $this->res['students'][$key] ?? null;
        if (!$s) {
            return 'no es estudiante activo';
        }
        if ($t === $k) {
            return '';
        }
        if ($t > $this->lastk($s)) {
            return 'egresa antes de ' . $this->periodnames[$t];
        }
        $plan = $this->res['plan_courses'][$s['planid']] ?? [];
        if ($t < $k) {
            // Adelantar: todas las prelaciones aprobadas antes de t.
            foreach ($plan[$cid][1] ?? [] as $pre) {
                if (isset($this->res['failed'][$key][$pre]) && !$this->done($key, $pre)) {
                    return 'no podría adelantarla: reprobó su prelación ' . ($this->res['course_names'][$pre] ?? $pre);
                }
                if ($this->done($key, $pre) || !isset($plan[$pre])) {
                    continue;
                }
                $p = $this->due($s, $pre);
                if ($p >= $t) {
                    return 'no podría adelantarla: su prelación ' . ($this->res['course_names'][$pre] ?? $pre) . ' la ve en '
                        . ($this->periodnames[$p] ?? 'un periodo posterior');
                }
            }
            return '';
        }
        // Esperar: ninguna asignatura que la necesite como prelacion puede caer en t o antes.
        foreach ($plan as $d => $pc) {
            if ((int)$d === (int)$cid || !in_array((int)$cid, $pc[1], true) || $this->done($key, $d)) {
                continue;
            }
            $p = $this->due($s, $d);
            if ($p <= $t) {
                return 'no puede esperar: es prelación de ' . ($this->res['course_names'][$d] ?? $d) . ', que ve en '
                    . ($this->periodnames[$p] ?? 'ese periodo');
            }
        }
        return '';
    }

    /** Series por asignatura + jornada + carrera aislada: periodo k => claves de estudiantes. */
    protected function series() {
        $flex = planning_config::flexible();
        $out = [];
        foreach ($this->res['demand'] as $d) {
            $rk = $d['courseid'] . '|' . $d['jornada'] . '|' . (int)$d['isolated_plan'];
            foreach ($d['students'] as $x) {
                if ($x['prereq'] === 'no' || !empty($x['flex']) || !in_array($x['category'], ['nivel', 'atrasada'], true)) {
                    continue;
                }
                $out[$rk]['cells'][0][] = $x['key'];
            }
            if (isset($out[$rk])) {
                $out[$rk] += ['courseid' => (int)$d['courseid'], 'name' => $d['name'], 'jornada' => $d['jornada'],
                    'isolated_plan' => (int)$d['isolated_plan']];
            }
        }
        foreach ((array)($this->res['horizon'] ?? []) as $h) {
            foreach ($h['rows'] as $r) {
                $rk = $r['courseid'] . '|' . $r['jornada'] . '|' . (int)$r['isolated_plan'];
                foreach ($r['students'] as $key) {
                    $s = $this->res['students'][$key] ?? null;
                    if (!$s || planning_config::is_flexible($flex, $s['planid'], $r['courseid'])) {
                        continue;
                    }
                    $out[$rk]['cells'][(int)$h['k']][] = $key;
                }
                if (isset($out[$rk])) {
                    $out[$rk] += ['courseid' => (int)$r['courseid'], 'name' => $r['name'], 'jornada' => $r['jornada'],
                        'isolated_plan' => (int)$r['isolated_plan']];
                }
            }
        }
        // Grupos ya propuestos para el periodo que se planifica: la celda del periodo es el grupo real (con los que ya
        // adelantan) y esos estudiantes salen de los periodos siguientes (no se sugiere sumarlos otra vez).
        foreach ($this->planned as $rk => $keys) {
            if (!isset($out[$rk])) {
                continue;
            }
            $out[$rk]['cells'][0] = array_values(array_unique(array_merge($out[$rk]['cells'][0] ?? [], array_keys($keys))));
            foreach ($out[$rk]['cells'] as $k => $list) {
                if ($k > 0) {
                    $out[$rk]['cells'][$k] = array_values(array_filter($list, function ($key) use ($keys) {
                        return !isset($keys[$key]);
                    }));
                    if (!$out[$rk]['cells'][$k]) {
                        unset($out[$rk]['cells'][$k]);
                    }
                }
            }
        }
        // No se juntan: sin horario (practica profesional) ni las de cuatrimestre completo (Ingles, Acondicionamiento...):
        // se dictan el cuatrimestre entero con su propia secuencia y no se mueven de periodo.
        $unsched = planning_config::unscheduled_subjects()['ids'];
        $long = planning_config::long_subjects()['ids'];
        $keep = [];
        foreach ($out as $rk => $x) {
            if (isset($long[$x['courseid']])) {
                $this->excluded[$x['courseid']] = $x['name'];
                continue;
            }
            if (!isset($unsched[$x['courseid']]) && !empty($x['cells'])) {
                $keep[$rk] = $x;
            }
        }
        return $keep;
    }

    /**
     * @return array ['pools' => [...], 'unsolved' => [...], 'min' => int, 'periods' => [k => nombre],
     *               'excluded_long' => [nombres de las de cuatrimestre completo, que no se juntan]]
     */
    public function build() {
        $pools = [];
        $unsolved = [];
        foreach ($this->series() as $rk => $ser) {
            $cells = $ser['cells'];
            ksort($cells);
            $cid = $ser['courseid'];
            // Celdas que ya abren solas: no se tocan, pero los grupos chicos pueden sumarse a ellas.
            $open = [];
            $remaining = [];
            foreach ($cells as $k => $keys) {
                $keys = array_values(array_unique($keys));
                if (count($keys) >= $this->min) {
                    $open[$k] = $keys;
                } else {
                    foreach ($keys as $key) {
                        $remaining[$key] = $k;
                    }
                }
            }
            if (!$remaining) {
                continue;
            }
            $guard = 0;
            while ($remaining && $guard++ < 50) {
                $k0 = min($remaining);
                $best = null;
                for ($t = 0; $t <= $this->maxk; $t++) {
                    $base = $open[$t] ?? [];
                    // Candidatos ordenados por distancia (cohorte por cohorte), con su motivo si no pueden moverse.
                    $byk = [];
                    foreach ($remaining as $key => $k) {
                        $byk[$k][] = $key;
                    }
                    uksort($byk, function ($a, $b) use ($t) {
                        return [abs($a - $t), $a > $t] <=> [abs($b - $t), $b > $t];
                    });
                    $members = [];
                    $blocked = [];
                    $cost = 0;
                    $total = count($base);
                    $haskey0 = false;
                    foreach ($byk as $k => $keys) {
                        // Se suman cohortes de la mas cercana a la mas lejana hasta alcanzar el minimo incluyendo la
                        // cohorte mas temprana pendiente (si no se incluye, esta apertura no la resuelve).
                        if ($total >= $this->min && $haskey0) {
                            break;
                        }
                        foreach ($keys as $key) {
                            $why = $this->movable($key, $cid, $k, $t);
                            if ($why === '') {
                                $members[$k][] = $key;
                                $total++;
                                $cost += abs($k - $t);
                                if ($k === $k0) {
                                    $haskey0 = true;
                                }
                            } else {
                                $blocked[] = ['key' => $key, 'k' => $k, 'reason' => $why];
                            }
                        }
                    }
                    if ($total < $this->min || !$haskey0) {
                        continue;
                    }
                    $score = $cost + $t * 0.01;
                    if (!$best || $score < $best['score']) {
                        $best = ['t' => $t, 'members' => $members, 'blocked' => $blocked, 'base' => $base, 'score' => $score,
                            'cost' => $cost, 'total' => $total];
                    }
                }
                if (!$best) {
                    // Ni juntando todo lo que se puede mover alcanza: la cohorte mas temprana queda sin solucion.
                    $keys = array_keys(array_filter($remaining, function ($k) use ($k0) {
                        return $k === $k0;
                    }));
                    $unsolved[] = ['key' => $rk . '|' . $k0, 'courseid' => $cid, 'name' => $ser['name'], 'jornada' => $ser['jornada'],
                        'isolated_plan' => $ser['isolated_plan'], 'k' => $k0, 'period' => $this->periodnames[$k0], 'students' => $keys,
                        'reason' => 'Aun juntando todos los periodos en que se puede mover sin romper prelaciones no se llega a '
                            . $this->min . '. Opciones: abrir por excepción o como módulo.'];
                    foreach ($keys as $key) {
                        unset($remaining[$key]);
                    }
                    continue;
                }
                $cohorts = [];
                foreach ($best['members'] as $k => $keys) {
                    $cohorts[] = ['k' => $k, 'period' => $this->periodnames[$k], 'action' => $k === $best['t'] ? 'a_tiempo'
                        : ($k < $best['t'] ? 'espera' : 'adelanta'), 'shift' => abs($k - $best['t']), 'students' => $keys];
                    foreach ($keys as $key) {
                        unset($remaining[$key]);
                    }
                }
                usort($cohorts, function ($a, $b) {
                    return $a['k'] <=> $b['k'];
                });
                // Los bloqueados de cohortes que si se juntaron siguen en su periodo (sin quorum, con su motivo).
                $blockedk = array_column($cohorts, 'k');
                $blocked = array_values(array_filter($best['blocked'], function ($b) use ($blockedk) {
                    return in_array($b['k'], $blockedk, true);
                }));
                foreach ($blocked as $b) {
                    unset($remaining[$b['key']]);
                }
                $pools[] = ['key' => $rk . '|' . $best['t'], 'rowkey' => $rk, 'courseid' => $cid, 'name' => $ser['name'],
                    'jornada' => $ser['jornada'], 'isolated_plan' => $ser['isolated_plan'], 'open_k' => $best['t'],
                    'open_period' => $this->periodnames[$best['t']], 'total' => $best['total'], 'base' => count($best['base']),
                    'moved' => array_sum(array_map(function ($c) {
                        return $c['action'] === 'a_tiempo' ? 0 : count($c['students']);
                    }, $cohorts)), 'cohorts' => $cohorts, 'blocked' => $blocked,
                    'affects_next' => $best['t'] === 0 || in_array(0, array_column($cohorts, 'k'), true)];
            }
        }
        usort($pools, function ($a, $b) {
            return [$a['open_k'], $a['name']] <=> [$b['open_k'], $b['name']];
        });
        asort($this->excluded);
        return ['pools' => $pools, 'unsolved' => $unsolved, 'min' => $this->min, 'periods' => $this->periodnames,
            'excluded_long' => array_values($this->excluded)];
    }
}
