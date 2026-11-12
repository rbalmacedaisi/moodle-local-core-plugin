<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Agrupacion automatica (fase 2). SOLO LECTURA: arma la propuesta de grupos del periodo a partir de la demanda
 * (demand_engine) y la configuracion, sin escribir nada.
 *
 * Reglas:
 *  - Por asignatura + jornada (+ carrera si no se mezcla). Abre si los que se cargan (nivel + atrasada, con prelacion,
 *    sin repitentes ni no obligatorias sin activar) alcanzan el minimo, o si tiene excepcion.
 *  - Divide en grupos parejos de hasta el maximo (31 -> 16 + 15), manteniendo juntas a las cohortes
 *    (carrera + ingreso + nivel).
 *  - Adelantos con sentido comun, en una segunda pasada: solo para quien queda con menos carga que la minima y
 *    tiene asignaturas que puede adelantar; primero llenan cupos libres de grupos que ya abren y luego completan
 *    grupos que con ellos alcanzan el minimo. Nunca se le carga un adelanto a quien ya tiene su carga.
 *  - Modulo por excepcion: un grupo con los estudiantes elegidos (sin horario ni pago, se crea al publicar).
 *  - No abrir por decision: no abre aunque alcance el minimo; sus estudiantes quedan como alerta.
 *  - Sin horario (practica profesional): un solo grupo por asignatura con todas las jornadas, sin minimo ni maximo.
 *  - Alertas: sin quorum, egresa sin quorum, carga baja, prelacion pendiente (repitentes se gestionan aparte).
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Grupo Makro
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\planning;

defined('MOODLE_INTERNAL') || die();

class group_builder {

    /** @var array resultado de demand_engine::compute() */
    protected $res;
    protected $min;
    protected $max;
    protected $minload;

    public function __construct(array $demandresult, array $rules) {
        $this->res = $demandresult;
        $this->min = max(1, (int)$rules['min_group']);
        $this->max = max($this->min, (int)$rules['max_group']);
        $this->minload = max(1, (int)$rules['min_load']);
    }

    /**
     * @return array ['groups' => [...], 'alerts' => [...], 'summary' => [...], 'students' => [key => carga asignada]]
     */
    public function build() {
        $students = $this->res['students'];
        $exceptions = [];
        foreach ((array)($this->res['exceptions'] ?? []) as $e) {
            $exceptions[$e['key']] = $e;
        }
        $long = array_flip((array)($this->res['costing']['long'] ?? []));
        $unsched = array_flip((array)($this->res['costing']['unscheduled'] ?? []));
        $assigned = [];      // studentkey => [courseid => groupid]
        $groups = [];
        $alerts = [];
        $pending = [];       // filas que no abrieron: rowkey => fila con listas
        $protected = [];     // studentkey => [courseid => true]: adelantos decididos para juntar cohortes

        // 1. Filas: separar quien se carga, quien puede adelantar y quien queda con prelacion pendiente.
        $rows = [];
        foreach ($this->res['demand'] as $d) {
            $nosched = isset($unsched[(int)$d['courseid']]);
            // Sin horario: todas las jornadas en una sola fila.
            $jornada = $nosched ? 'Sin horario' : $d['jornada'];
            $rk = $d['courseid'] . '|' . $jornada . '|' . (int)$d['isolated_plan'];
            $row = $rows[$rk] ?? ['key' => $rk, 'courseid' => (int)$d['courseid'], 'name' => $d['name'], 'jornada' => $jornada,
                'isolated_plan' => (int)$d['isolated_plan'], 'ready' => [], 'adelanto' => [], 'blocked' => [],
                'exception' => $nosched ? null : ($exceptions[$rk] ?? null), 'long' => isset($long[(int)$d['courseid']]),
                'unscheduled' => $nosched];
            foreach ($d['students'] as $x) {
                if ($x['category'] === 'repite') {
                    continue; // gestion aparte
                }
                if ($x['prereq'] === 'no') {
                    $row['blocked'][] = $x['key'];
                } else if (!empty($x['flex'])) {
                    continue; // no obligatoria sin activar
                } else if ($x['category'] === 'adelanto') {
                    $row['adelanto'][] = $x['key'];
                } else {
                    $row['ready'][] = $x['key'];
                }
            }
            $rows[$rk] = $row;
        }

        // 2. Primera pasada: abrir lo que alcanza el minimo o tiene excepcion.
        foreach ($rows as $rk => $row) {
            $exc = $row['exception'];
            if ($row['unscheduled']) {
                // Practica profesional y similares: se carga a todos los que les toca, sin minimo ni division.
                if ($row['ready']) {
                    $this->add_group($groups, $assigned, $row, $row['ready'], 1, 'sin_horario');
                }
                continue;
            }
            if ($exc && $exc['mode'] === 'cerrar') {
                if ($row['ready']) {
                    $pending[$rk] = $row;
                }
                continue;
            }
            if ($exc && $exc['mode'] === 'modulo') {
                $members = [];
                foreach (array_merge($row['ready'], $row['adelanto']) as $k) {
                    if (in_array((int)$students[$k]['userid'], array_map('intval', $exc['userids']), true)) {
                        $members[] = $k;
                    }
                }
                if ($members) {
                    $this->add_group($groups, $assigned, $row, $members, 1, 'modulo');
                }
                $left = array_values(array_diff($row['ready'], $members));
                if ($left) {
                    $pending[$rk] = $row;
                    $pending[$rk]['ready'] = $left;
                }
                continue;
            }
            $n = count($row['ready']);
            // Juntar cohortes: estudiantes de periodos siguientes que la adelantan (ya validados con sus prelaciones).
            $extra = [];
            if ($exc && $exc['mode'] === 'grupo') {
                foreach ((array)($exc['add_keys'] ?? []) as $ak) {
                    if (isset($students[$ak]) && !in_array($ak, $row['ready'], true) && !isset($assigned[$ak][$row['courseid']])) {
                        $extra[] = $ak;
                        // Se adelanta para juntar cohortes: la limpieza de adelantos no lo quita.
                        $protected[$ak][$row['courseid']] = true;
                    }
                }
            }
            if ($n >= $this->min || ($exc && $n + count($extra) > 0)) {
                $this->split($groups, $assigned, $row, array_merge($row['ready'], $extra), $exc ? 'excepcion' : 'grupo', count($extra));
            } else if ($n > 0) {
                $pending[$rk] = $row;
            }
        }

        // 3. Segunda pasada: adelantos para quien tiene poca carga.
        $load = function ($k) use (&$assigned) {
            return count($assigned[$k] ?? []);
        };
        $wants = function ($k) use ($load) {
            return $load($k) < $this->minload;
        };
        // 3a. Llenar cupos libres de grupos que ya abren.
        foreach ($groups as &$g) {
            if ($g['type'] === 'modulo' || $g['type'] === 'sin_horario') {
                continue;
            }
            $row = $rows[$g['rowkey']];
            foreach ($this->prioritize($row['adelanto'], $students, $load) as $k) {
                if (count($g['students']) >= $this->max) {
                    break;
                }
                if ($wants($k) && !isset($assigned[$k][$row['courseid']])) {
                    $g['students'][] = $k;
                    $g['adel'][] = $k;
                    $g['adelantos']++;
                    $assigned[$k][$row['courseid']] = $g['id'];
                }
            }
        }
        unset($g);
        // 3b. Completar con adelantos lo que no alcanzo el minimo.
        foreach ($pending as $rk => $row) {
            if ($row['exception']) {
                continue;
            }
            $extra = [];
            foreach ($this->prioritize($row['adelanto'], $students, $load) as $k) {
                if ($wants($k) && !isset($assigned[$k][$row['courseid']])) {
                    $extra[] = $k;
                }
            }
            if (count($row['ready']) + count($extra) >= $this->min) {
                $need = max(0, $this->min - count($row['ready']));
                $take = array_slice($extra, 0, max($need, min(count($extra), $this->max - count($row['ready']))));
                $this->split($groups, $assigned, $row, array_merge($row['ready'], $take), 'grupo', count($take));
                unset($pending[$rk]);
            }
        }

        // 3c. Limpieza: quien al final ya tiene su carga sin el adelanto lo pierde (si el grupo sigue con el minimo).
        $base = function ($k) use (&$groups, &$assigned) {
            $n = 0;
            foreach ($assigned[$k] ?? [] as $gid) {
                if (!in_array($k, $groups[$gid]['adel'], true)) {
                    $n++;
                }
            }
            return $n;
        };
        foreach ($groups as $gid => &$g) {
            foreach ($g['adel'] as $k) {
                if (isset($protected[$k][$g['courseid']])) {
                    continue;
                }
                if ($base($k) >= $this->minload && count($g['students']) - 1 >= $this->min) {
                    $g['students'] = array_values(array_diff($g['students'], [$k]));
                    $g['adel'] = array_values(array_diff($g['adel'], [$k]));
                    $g['adelantos'] = count($g['adel']);
                    unset($assigned[$k][$g['courseid']]);
                }
            }
        }
        unset($g);

        // 4. Alertas.
        foreach ($pending as $row) {
            $closed = $row['exception'] && $row['exception']['mode'] === 'cerrar';
            foreach ($row['ready'] as $k) {
                $s = $students[$k];
                if (!empty($row['exception']['pool'])) {
                    // Espera para juntarse con la cohorte de un periodo siguiente (decidido en Proximos periodos).
                    $alerts[] = $this->alert('espera', $k, $s, $row, $row['exception']['note']);
                    continue;
                }
                if ($closed) {
                    $alerts[] = $this->alert('no_abre', $k, $s, $row, 'Se decidió no abrir: ' . $row['exception']['note']);
                    continue;
                }
                $alerts[] = $this->alert($s['finishing'] ? 'egresa_sin_quorum' : 'sin_quorum', $k, $s, $row,
                    count($row['ready']) . ' estudiante(s) en ' . ($row['jornada'] ?: 'sin jornada') . '; mínimo ' . $this->min . '.');
            }
        }
        foreach ($rows as $row) {
            foreach ($row['blocked'] as $k) {
                $alerts[] = $this->alert('prelacion', $k, $students[$k], $row, 'Falta aprobar la prelación.');
            }
        }
        foreach ($students as $k => $s) {
            $l = $load($k);
            $possible = 0;
            foreach ($s['subjects'] as $x) {
                if ($x['prereq'] !== 'no' && $x['category'] !== 'repite' && (empty($x['flexible']) || !empty($x['flex_on']))) {
                    $possible++;
                }
            }
            if ($l < $this->minload && $possible > $l) {
                $alerts[] = ['type' => 'carga_baja', 'key' => $k, 'userid' => (int)$s['userid'], 'name' => $s['name'],
                    'plan' => $s['plan'], 'jornada' => $s['jornada'], 'level' => $s['level'], 'courseid' => 0, 'course' => '',
                    'finishing' => (bool)$s['finishing'], 'detail' => 'Queda con ' . $l . ' asignatura(s) y podría ver ' . $possible . '.'];
            }
        }

        // 5. Numerar grupos y describir su composicion.
        $count = [];
        foreach ($groups as &$g) {
            $ck = $g['rowkey'];
            $count[$ck] = ($count[$ck] ?? 0) + 1;
            $g['number'] = $count[$ck];
            $g['size'] = count($g['students']);
            $levels = [];
            $cohorts = [];
            $plans = [];
            foreach ($g['students'] as $k) {
                $s = $students[$k];
                $levels[$s['level']] = ($levels[$s['level']] ?? 0) + 1;
                $ck2 = $s['plan'] . ' · ' . ($s['ingreso'] ?: 'sin ingreso');
                $cohorts[$ck2] = ($cohorts[$ck2] ?? 0) + 1;
                $plans[$s['planid']] = true;
            }
            arsort($levels);
            arsort($cohorts);
            $g['levels'] = $levels;
            $g['cohorts'] = $cohorts;
            $g['plans'] = array_keys($plans);
        }
        unset($g);
        foreach ($groups as &$g) {
            $g['of'] = $count[$g['rowkey']];
        }
        unset($g);

        $summary = ['groups' => count($groups), 'modules' => 0, 'exceptions' => 0, 'unscheduled' => 0, 'seats' => 0, 'adelantos' => 0,
            'students_assigned' => 0, 'alerts' => []];
        foreach ($groups as $g) {
            $summary['seats'] += $g['size'];
            $summary['adelantos'] += $g['adelantos'];
            $summary['modules'] += $g['type'] === 'modulo' ? 1 : 0;
            $summary['exceptions'] += $g['type'] === 'excepcion' ? 1 : 0;
            $summary['unscheduled'] += $g['type'] === 'sin_horario' ? 1 : 0;
        }
        $summary['students_assigned'] = count(array_filter($assigned));
        foreach ($alerts as $a) {
            $summary['alerts'][$a['type']] = ($summary['alerts'][$a['type']] ?? 0) + 1;
        }
        $loads = [];
        foreach ($students as $k => $s) {
            $loads[$k] = $load($k);
        }
        return ['groups' => array_values($groups), 'alerts' => $alerts, 'summary' => $summary, 'loads' => $loads];
    }

    /** Divide en grupos parejos de hasta el maximo, manteniendo juntas las cohortes. */
    protected function split(array &$groups, array &$assigned, array $row, array $members, $type, $adelantos = 0) {
        $students = $this->res['students'];
        usort($members, function ($a, $b) use ($students) {
            $sa = $students[$a];
            $sb = $students[$b];
            return [$sa['planid'], $sa['ingreso'], $sa['ord'], $sa['name']] <=> [$sb['planid'], $sb['ingreso'], $sb['ord'], $sb['name']];
        });
        $n = count($members);
        $k = max(1, (int)ceil($n / $this->max));
        $base = intdiv($n, $k);
        $rest = $n % $k;
        $pos = 0;
        $adelset = $adelantos ? array_flip(array_diff($members, $row['ready'])) : [];
        for ($i = 0; $i < $k; $i++) {
            $size = $base + ($i < $rest ? 1 : 0);
            $chunk = array_slice($members, $pos, $size);
            $pos += $size;
            $id = $this->add_group($groups, $assigned, $row, $chunk, $i + 1, $type);
            $groups[$id]['adel'] = array_values(array_intersect($chunk, array_keys($adelset)));
            $groups[$id]['adelantos'] = count($groups[$id]['adel']);
        }
    }

    protected function add_group(array &$groups, array &$assigned, array $row, array $members, $num, $type) {
        $id = count($groups);
        $groups[$id] = ['id' => $id, 'rowkey' => $row['key'], 'courseid' => $row['courseid'], 'name' => $row['name'],
            'jornada' => $row['jornada'], 'isolated_plan' => $row['isolated_plan'], 'type' => $type, 'long' => $row['long'],
            'decision' => $row['exception']['mode'] ?? '',
            'students' => array_values($members), 'adelantos' => 0, 'adel' => [], 'note' => $row['exception']['note'] ?? ''];
        foreach ($members as $k) {
            $assigned[$k][$row['courseid']] = $id;
        }
        return $id;
    }

    /** Adelantos primero para quien egresa o tiene menos carga, y del nivel mas alto. */
    protected function prioritize(array $keys, array $students, callable $load) {
        usort($keys, function ($a, $b) use ($students, $load) {
            return [$load($a), -(int)$students[$a]['finishing'], -$students[$a]['ord']]
                <=> [$load($b), -(int)$students[$b]['finishing'], -$students[$b]['ord']];
        });
        return $keys;
    }

    protected function alert($type, $k, array $s, array $row, $detail) {
        return ['type' => $type, 'key' => $k, 'userid' => (int)$s['userid'], 'name' => $s['name'], 'plan' => $s['plan'],
            'jornada' => $row['jornada'], 'level' => $s['level'], 'courseid' => $row['courseid'], 'course' => $row['name'],
            'finishing' => (bool)$s['finishing'], 'detail' => $detail];
    }
}
