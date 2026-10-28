<?php
namespace local_grupomakro_core\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Avance automatico del nivel curricular (cuatrimestre / bimestre) de los estudiantes.
 *
 * Regla institucional:
 *  - Hay 6 periodos al ano (I..VI) y cada uno es una franja de 2 meses (bimestre).
 *  - 2 franjas activas = 1 cuatrimestre. Cuatrimestre = ceil(n/2), bimestre = ((n-1) % 2) + 1,
 *    con n = franjas activas desde el periodo de ingreso hasta la franja vigente.
 *  - Solo avanzan los estudiantes con status 'activo'. Un estudiante inactivo, suspendido, etc.
 *    no suma franjas mientras lo esta.
 *  - Antes de FIRST_EVIDENCE_SLOT se asume avance continuo (no hay clases cargadas de forma fiable).
 *    Desde esa franja una franja pasada solo cuenta si el estudiante tuvo clase en ella
 *    (gmk_course_progre, grupo de la clase o cola de matricula). La franja vigente cuenta siempre.
 *  - Tope: el numero de cuatrimestres del plan. Nunca se retrocede a nadie.
 *  - Soldadura Subacuatica (3) y Buceo Comercial (14) se mantienen en el mismo nivel.
 *
 * Es idempotente: se puede ejecutar todos los dias; solo escribe cuando hay que subir de nivel.
 * Las franjas salen de gmk_academic_calendar (block1start de cada periodo y block2start del periodo
 * anterior). Si el calendario no tiene la franja siguiente, no se avanza y se deja constancia.
 */
class academic_progress {

    /** Planes de cursos complementarios: no tienen progresion por cuatrimestres. */
    const EXCLUDED_PLANS = [26, 28, 29, 30];

    /** Planes que deben quedar siempre en el mismo nivel. */
    const PAIRED_PLANS = [3, 14];

    /** Primera franja (2026-II) con clases cargadas de forma fiable: indice = anio * 6 + (numero - 1). */
    const FIRST_EVIDENCE_SLOT = 2026 * 6 + 1;

    /** Usuario al que se atribuyen los cambios (admin). */
    const ACTOR_USERID = 2;

    /** Duracion asumida de la ultima franja conocida si el calendario no dice cuando termina. */
    const DEFAULT_SLOT_SECONDS = 62 * 86400;

    /**
     * Indice de franja a partir de un nombre como "2026-V" o "2025-II-BI" (anio * 6 + numero - 1).
     */
    public static function slot_index($name) {
        static $roman = ['I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4, 'V' => 5, 'VI' => 6];
        $name = strtoupper(trim((string)$name));
        if (preg_match('/(20\d{2})\s*-?\s*(VI|IV|V|III|II|I)\b/', $name, $m)) {
            return ((int)$m[1]) * 6 + ($roman[$m[2]] - 1);
        }
        return null;
    }

    public static function slot_label($index) {
        static $roman = ['I', 'II', 'III', 'IV', 'V', 'VI'];
        return intdiv($index, 6) . '-' . $roman[$index % 6];
    }

    /**
     * Franjas conocidas segun el calendario academico.
     *
     * @return array [slot index => start timestamp], ordenado por indice
     */
    public static function load_slot_starts() {
        global $DB;
        $periods = $DB->get_records('gmk_academic_periods');
        $starts = [];
        foreach ($DB->get_records('gmk_academic_calendar', null, 'id ASC') as $c) {
            if (empty($c->hassubperiods) || !isset($periods[$c->academicperiodid])) {
                continue;
            }
            $i = self::slot_index($periods[$c->academicperiodid]->name);
            if ($i === null) {
                continue;
            }
            if ($c->block1start > 0) {
                $starts[$i] = (int)$c->block1start;
            }
            if ($c->block2start > 0 && !isset($starts[$i + 1])) {
                $starts[$i + 1] = (int)$c->block2start;
            }
        }
        ksort($starts);
        return $starts;
    }

    /**
     * Evidencia de clases: userid => [slot index => true], solo para franjas con historia fiable.
     */
    protected static function load_evidence(array $starts) {
        global $DB;
        $win = [];
        foreach ($starts as $i => $st) {
            if ($i < self::FIRST_EVIDENCE_SLOT) {
                continue;
            }
            $win[$i] = [$st, isset($starts[$i + 1]) ? $starts[$i + 1] : $st + self::DEFAULT_SLOT_SECONDS];
        }
        $ev = [];
        $queries = [
            "SELECT cp.userid, c.initdate, c.enddate
               FROM {gmk_course_progre} cp
               JOIN {gmk_class} c ON c.id = cp.classid
              WHERE cp.classid > 0 AND cp.status IN (2,3,4,5,6) AND c.initdate > 0",
            "SELECT gm.userid, c.initdate, c.enddate
               FROM {gmk_class} c
               JOIN {groups_members} gm ON gm.groupid = c.groupid
              WHERE c.initdate > 0 AND c.groupid > 0",
            "SELECT q.userid, c.initdate, c.enddate
               FROM {gmk_class_queue} q
               JOIN {gmk_class} c ON c.id = q.classid
              WHERE c.initdate > 0",
        ];
        foreach ($queries as $sql) {
            $rs = $DB->get_recordset_sql($sql);
            foreach ($rs as $r) {
                $end = $r->enddate ?: $r->initdate;
                foreach ($win as $i => $w) {
                    if ($r->initdate < $w[1] && $end >= $w[0]) {
                        $ev[$r->userid][$i] = true;
                    }
                }
            }
            $rs->close();
        }
        return $ev;
    }

    /**
     * Calcula (y opcionalmente aplica) el avance.
     *
     * @param bool $apply false = simulacion, no escribe nada
     * @param callable|null $log function(string $line)
     * @param int|null $now para pruebas
     * @return array resumen: contadores y lista 'changes'
     */
    public static function run($apply = true, $log = null, $now = null) {
        global $DB, $CFG;

        $now = $now ?: time();
        $log = $log ?: function ($l) {
        };

        $starts = self::load_slot_starts();
        $sum = ['evaluados' => 0, 'actualizados' => 0, 'sin_cambio' => 0, 'adelantados' => 0,
                'sin_ingreso' => 0, 'sin_estructura' => 0, 'calendario_incompleto' => 0, 'changes' => []];
        if (!$starts) {
            $log('AVISO: el calendario academico no tiene franjas con bimestres; no se hace nada.');
            return $sum;
        }

        $current = null;
        foreach ($starts as $i => $st) {
            if ($st <= $now) {
                $current = $i;
            }
        }
        if ($current === null) {
            $log('AVISO: ninguna franja del calendario ha iniciado todavia.');
            return $sum;
        }
        // Si ya paso el fin del calendario cargado, la franja vigente real es posterior y no se conoce.
        $lastend = 0;
        foreach ($DB->get_records('gmk_academic_calendar') as $c) {
            $lastend = max($lastend, (int)$c->block2end, (int)$c->block1end);
        }
        if ($lastend > 0 && $now > $lastend + 86400) {
            $sum['calendario_incompleto'] = 1;
            $log('AVISO: el calendario academico termina el ' . date('Y-m-d', $lastend)
                . ' y no tiene las franjas siguientes; cargue los periodos nuevos para que sigan avanzando.');
        }
        $log('Franja vigente: ' . self::slot_label($current));

        $evidence = self::load_evidence($starts);

        // Estructura de los planes: orden de cuatrimestres y bimestres por id.
        $planperiods = [];
        foreach ($DB->get_records('local_learning_periods', null, 'id ASC') as $x) {
            $planperiods[$x->learningplanid][] = (int)$x->id;
        }
        $subperiods = [];
        foreach ($DB->get_records('local_learning_subperiods', null, 'id ASC') as $x) {
            $subperiods[$x->periodid][] = (int)$x->id;
        }

        $ifield = $DB->get_record('user_info_field', ['shortname' => 'periodo_ingreso']);
        $ingresojoin = $ifield ? "LEFT JOIN {user_info_data} d ON d.userid = llu.userid AND d.fieldid = " . (int)$ifield->id
            : '';
        $ingresosel = $ifield ? 'd.data' : "''";
        list($insql, $inparams) = $DB->get_in_or_equal(self::EXCLUDED_PLANS, SQL_PARAMS_NAMED, 'ex', false);
        $rows = $DB->get_records_sql(
            "SELECT llu.id, llu.userid, llu.learningplanid, llu.currentperiodid, llu.currentsubperiodid,
                    u.idnumber, $ingresosel AS ingreso
               FROM {local_learning_users} llu
               JOIN {user} u ON u.id = llu.userid AND u.deleted = 0
               $ingresojoin
              WHERE llu.userrolename = 'student' AND llu.status = 'activo' AND llu.learningplanid $insql
           ORDER BY llu.learningplanid, llu.id", $inparams);

        // Paso 1: nivel esperado por fila.
        $items = [];
        foreach ($rows as $r) {
            $sum['evaluados']++;
            $n = count($planperiods[$r->learningplanid] ?? []);
            if (!$n) {
                $sum['sin_estructura']++;
                continue;
            }
            $idx = self::slot_index($r->ingreso);
            if ($idx === null && preg_match('/^(20\d{2}-(?:VI|IV|V|III|II|I))-\d+/i', (string)$r->idnumber, $m)) {
                $idx = self::slot_index($m[1]);
            }
            if ($idx === null) {
                $sum['sin_ingreso']++;
                continue;
            }
            $active = 0;
            for ($j = $idx; $j <= $current; $j++) {
                if ($j < self::FIRST_EVIDENCE_SLOT || $j == $current || !empty($evidence[$r->userid][$j])) {
                    $active++;
                }
            }
            $ord = min(max(1, $active), 2 * $n);

            $pos = array_search((int)$r->currentperiodid, $planperiods[$r->learningplanid]);
            $sp = array_search((int)$r->currentsubperiodid, $subperiods[$r->currentperiodid] ?? []);
            $curord = ($pos === false) ? 0 : $pos * 2 + ($sp === false ? 1 : $sp + 1);

            $items[] = ['row' => $r, 'n' => $n, 'ord' => $ord, 'curord' => $curord];
        }

        // Paso 2: Soldadura y Buceo siempre iguales (el mayor nivel de los dos, dentro del tope de cada plan).
        $byuser = [];
        foreach ($items as $k => $it) {
            if (in_array((int)$it['row']->learningplanid, self::PAIRED_PLANS, true)) {
                $byuser[$it['row']->userid][] = $k;
            }
        }
        foreach ($byuser as $keys) {
            if (count($keys) < 2) {
                continue;
            }
            $max = 0;
            foreach ($keys as $k) {
                $max = max($max, $items[$k]['ord'], $items[$k]['curord']);
            }
            foreach ($keys as $k) {
                $items[$k]['ord'] = min($max, 2 * $items[$k]['n']);
            }
        }

        // Paso 3: escribir solo lo que sube de nivel.
        $transaction = $apply ? $DB->start_delegated_transaction() : null;
        foreach ($items as $it) {
            $r = $it['row'];
            if ($it['ord'] <= $it['curord']) {
                $sum[$it['curord'] > $it['ord'] ? 'adelantados' : 'sin_cambio']++;
                continue;
            }
            $c = (int)ceil($it['ord'] / 2);
            $b = (($it['ord'] - 1) % 2) + 1;
            $newperiod = $planperiods[$r->learningplanid][$c - 1] ?? null;
            $newsub = $newperiod ? ($subperiods[$newperiod][$b - 1] ?? null) : null;
            if (!$newperiod || !$newsub) {
                $sum['sin_estructura']++;
                $log("AVISO: plan {$r->learningplanid} no tiene el bimestre C$c.B$b definido (usuario {$r->userid}).");
                continue;
            }
            $change = ['id' => (int)$r->id, 'userid' => (int)$r->userid, 'planid' => (int)$r->learningplanid,
                'from' => [(int)$r->currentperiodid, (int)$r->currentsubperiodid],
                'to' => [$newperiod, $newsub], 'level' => "C$c.B$b"];
            if ($apply) {
                $DB->update_record('local_learning_users', (object)[
                    'id' => $r->id,
                    'currentperiodid' => $newperiod,
                    'currentsubperiodid' => $newsub,
                    'timemodified' => $now,
                    'usermodified' => self::ACTOR_USERID,
                ]);
            }
            $sum['actualizados']++;
            $sum['changes'][] = $change;
        }
        if ($transaction) {
            $transaction->allow_commit();
        }

        // Registro de lo cambiado, con valores anteriores para poder revertir.
        if ($apply && $sum['changes']) {
            $dir = $CFG->dataroot . '/local_grupomakro_core/logs';
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            @file_put_contents($dir . '/avance_progreso_' . date('Ymd_His', $now) . '.json',
                json_encode(['franja' => self::slot_label($current), 'changes' => $sum['changes']]));
        }
        return $sum;
    }
}
