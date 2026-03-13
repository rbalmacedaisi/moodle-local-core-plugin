<?php
/**
 * Debug de filtros de horario del estudiante (LXP).
 *
 * Objetivo:
 * - Mostrar por que un evento aparece/no aparece en "Mi Horario".
 * - Comparar el scope esperado (gmk_course_progre status=2) vs lo que entrega get_class_events().
 *
 * @package local_grupomakro_core
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/calendar/lib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url('/local/grupomakro_core/pages/debug_student_schedule_filters.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Debug filtros horario estudiante');
$PAGE->set_heading('Debug filtros horario estudiante');

$userid = optional_param('userid', 0, PARAM_INT);
$search = optional_param('search', '', PARAM_TEXT);
$initdate = optional_param('initdate', date('Y-01-01'), PARAM_TEXT);
$enddate = optional_param('enddate', date('Y-12-31', strtotime('+1 year')), PARAM_TEXT);
$page = optional_param('page', 0, PARAM_INT);
$perpage = optional_param('perpage', 50, PARAM_INT);
$maxrows = optional_param('maxrows', 100, PARAM_INT);
$runmass = optional_param('runmass', 0, PARAM_BOOL);
$applymass = optional_param('applymass', 0, PARAM_BOOL);

$fixdeduplicate = optional_param('fixdeduplicate', 0, PARAM_BOOL);
$fixsyncstatus = optional_param('fixsyncstatus', 0, PARAM_BOOL);
$fixsyncperiod = optional_param('fixsyncperiod', 0, PARAM_BOOL);
$fixgroupmembers = optional_param('fixgroupmembers', 0, PARAM_BOOL);
if (!$applymass) {
    if (!array_key_exists('fixdeduplicate', $_REQUEST)) {
        $fixdeduplicate = 1;
    }
    if (!array_key_exists('fixsyncstatus', $_REQUEST)) {
        $fixsyncstatus = 1;
    }
    if (!array_key_exists('fixsyncperiod', $_REQUEST)) {
        $fixsyncperiod = 1;
    }
    if (!array_key_exists('fixgroupmembers', $_REQUEST)) {
        $fixgroupmembers = 1;
    }
}
if ($page < 0) {
    $page = 0;
}
if ($perpage < 20) {
    $perpage = 20;
}
if ($perpage > 200) {
    $perpage = 200;
}
if ($maxrows < 20) {
    $maxrows = 20;
}
if ($maxrows > 1000) {
    $maxrows = 1000;
}

function gmk_dbg_h($value): string {
    if ($value === null) {
        return 'NULL';
    }
    if ($value === true) {
        return '1';
    }
    if ($value === false) {
        return '0';
    }
    return s((string)$value);
}

function gmk_dbg_status_label($status): string {
    static $map = [
        0 => 'No disponible',
        1 => 'Disponible',
        2 => 'Cursando',
        3 => 'Completado',
        4 => 'Aprobada',
        5 => 'Reprobada',
        6 => 'Pendiente Revalida',
        7 => 'Revalidando curso',
    ];
    $s = (int)$status;
    return isset($map[$s]) ? $map[$s] : ('Estado ' . $s);
}

function gmk_dbg_status_list(array $codes): string {
    if (empty($codes)) {
        return '-';
    }
    $codes = array_values(array_unique(array_map('intval', $codes)));
    sort($codes);
    $parts = [];
    foreach ($codes as $c) {
        $parts[] = $c . ':' . gmk_dbg_status_label($c);
    }
    return implode(' | ', $parts);
}

function gmk_dbg_print_table(array $headers, array $rows): void {
    echo '<table class="dbg-table"><thead><tr>';
    foreach ($headers as $h) {
        echo '<th>' . s($h) . '</th>';
    }
    echo '</tr></thead><tbody>';
    if (empty($rows)) {
        echo '<tr><td colspan="' . count($headers) . '" class="muted">Sin registros</td></tr>';
    } else {
        foreach ($rows as $r) {
            echo '<tr>';
            foreach ($headers as $h) {
                echo '<td>' . (isset($r[$h]) ? (string)$r[$h] : '') . '</td>';
            }
            echo '</tr>';
        }
    }
    echo '</tbody></table>';
}

function gmk_dbg_mass_collect(int $maxrows): array {
    global $DB;

    $passedsubsql = "
        SELECT gg.userid, gi.courseid, MAX(COALESCE(gg.finalgrade, gg.rawgrade)) AS gradeval
          FROM {grade_items} gi
          JOIN {grade_grades} gg ON gg.itemid = gi.id
          JOIN (
                SELECT DISTINCT userid, courseid
                  FROM {gmk_course_progre}
                 WHERE status = 2
               ) sc
            ON sc.userid = gg.userid
           AND sc.courseid = gi.courseid
         WHERE gi.itemtype = 'course'
      GROUP BY gg.userid, gi.courseid
    ";

    $periodsubsql = "
        SELECT userid, learningplanid, MAX(currentperiodid) AS currentperiodid
          FROM {local_learning_users}
      GROUP BY userid, learningplanid
    ";

    $checks = [];

    $checks[] = [
        'id' => 'duplicate_progress_records',
        'title' => 'Duplicados gmk_course_progre (userid+courseid+learningplanid)',
        'countsql' => "SELECT COUNT(*)
                         FROM (
                               SELECT 1
                                 FROM {gmk_course_progre}
                             GROUP BY userid, courseid, learningplanid
                               HAVING COUNT(*) > 1
                              ) q",
        'countparams' => [],
        'rowsql' => "SELECT CONCAT(cp.userid, '-', cp.courseid, '-', cp.learningplanid) AS rowid,
                            cp.userid, cp.courseid, cp.learningplanid,
                            COUNT(*) AS dupcount, MIN(cp.id) AS minid, MAX(cp.id) AS maxid
                       FROM {gmk_course_progre} cp
                   GROUP BY cp.userid, cp.courseid, cp.learningplanid
                     HAVING COUNT(*) > 1
                   ORDER BY dupcount DESC, maxid DESC",
        'rowparams' => [],
        'headers' => ['rowid', 'userid', 'courseid', 'learningplanid', 'dupcount', 'minid', 'maxid'],
    ];

    $checks[] = [
        'id' => 'inprogress_wrong_period',
        'title' => 'Cursando (status=2) fuera del periodo actual del plan',
        'countsql' => "SELECT COUNT(*)
                         FROM {gmk_course_progre} cp
                         JOIN ($periodsubsql) lpu
                           ON lpu.userid = cp.userid
                          AND lpu.learningplanid = cp.learningplanid
                        WHERE cp.status = 2
                          AND cp.periodid > 0
                          AND lpu.currentperiodid > 0
                          AND cp.periodid <> lpu.currentperiodid",
        'countparams' => [],
        'rowsql' => "SELECT cp.id AS rowid,
                            cp.userid, cp.learningplanid, cp.periodid, lpu.currentperiodid,
                            cp.courseid, cp.classid, cp.groupid
                       FROM {gmk_course_progre} cp
                       JOIN ($periodsubsql) lpu
                         ON lpu.userid = cp.userid
                        AND lpu.learningplanid = cp.learningplanid
                      WHERE cp.status = 2
                        AND cp.periodid > 0
                        AND lpu.currentperiodid > 0
                        AND cp.periodid <> lpu.currentperiodid
                   ORDER BY cp.userid ASC, cp.learningplanid ASC, cp.courseid ASC, cp.id ASC",
        'rowparams' => [],
        'headers' => ['rowid', 'userid', 'learningplanid', 'periodid', 'currentperiodid', 'courseid', 'classid', 'groupid'],
    ];

    $checks[] = [
        'id' => 'inprogress_invalid_class',
        'title' => 'Cursando (status=2) con classid invalido (no existe en gmk_class)',
        'countsql' => "SELECT COUNT(*)
                         FROM {gmk_course_progre} cp
                    LEFT JOIN {gmk_class} gc ON gc.id = cp.classid
                        WHERE cp.status = 2
                          AND cp.classid > 0
                          AND gc.id IS NULL",
        'countparams' => [],
        'rowsql' => "SELECT cp.id AS rowid,
                            cp.userid, cp.learningplanid, cp.courseid, cp.classid, cp.groupid, cp.periodid
                       FROM {gmk_course_progre} cp
                  LEFT JOIN {gmk_class} gc ON gc.id = cp.classid
                      WHERE cp.status = 2
                        AND cp.classid > 0
                        AND gc.id IS NULL
                   ORDER BY cp.userid ASC, cp.learningplanid ASC, cp.courseid ASC, cp.id ASC",
        'rowparams' => [],
        'headers' => ['rowid', 'userid', 'learningplanid', 'courseid', 'classid', 'groupid', 'periodid'],
    ];

    $checks[] = [
        'id' => 'inprogress_group_mismatch',
        'title' => 'Cursando (status=2) con groupid distinto al groupid de su clase',
        'countsql' => "SELECT COUNT(*)
                         FROM {gmk_course_progre} cp
                         JOIN {gmk_class} gc ON gc.id = cp.classid
                        WHERE cp.status = 2
                          AND cp.classid > 0
                          AND gc.groupid > 0
                          AND cp.groupid > 0
                          AND cp.groupid <> gc.groupid",
        'countparams' => [],
        'rowsql' => "SELECT cp.id AS rowid,
                            cp.userid, cp.learningplanid, cp.courseid, cp.classid,
                            cp.groupid AS cp_groupid, gc.groupid AS class_groupid
                       FROM {gmk_course_progre} cp
                       JOIN {gmk_class} gc ON gc.id = cp.classid
                      WHERE cp.status = 2
                        AND cp.classid > 0
                        AND gc.groupid > 0
                        AND cp.groupid > 0
                        AND cp.groupid <> gc.groupid
                   ORDER BY cp.userid ASC, cp.learningplanid ASC, cp.courseid ASC, cp.id ASC",
        'rowparams' => [],
        'headers' => ['rowid', 'userid', 'learningplanid', 'courseid', 'classid', 'cp_groupid', 'class_groupid'],
    ];

    $checks[] = [
        'id' => 'inprogress_missing_group_member',
        'title' => 'Cursando (status=2) sin membership en groups_members para el grupo de la clase',
        'countsql' => "SELECT COUNT(*)
                         FROM {gmk_course_progre} cp
                         JOIN {gmk_class} gc ON gc.id = cp.classid
                    LEFT JOIN {groups_members} gm
                           ON gm.userid = cp.userid
                          AND gm.groupid = gc.groupid
                        WHERE cp.status = 2
                          AND cp.classid > 0
                          AND gc.groupid > 0
                          AND gm.id IS NULL",
        'countparams' => [],
        'rowsql' => "SELECT cp.id AS rowid,
                            cp.userid, cp.learningplanid, cp.courseid, cp.classid,
                            gc.groupid AS class_groupid
                       FROM {gmk_course_progre} cp
                       JOIN {gmk_class} gc ON gc.id = cp.classid
                  LEFT JOIN {groups_members} gm
                         ON gm.userid = cp.userid
                        AND gm.groupid = gc.groupid
                      WHERE cp.status = 2
                        AND cp.classid > 0
                        AND gc.groupid > 0
                        AND gm.id IS NULL
                   ORDER BY cp.userid ASC, cp.learningplanid ASC, cp.courseid ASC, cp.id ASC",
        'rowparams' => [],
        'headers' => ['rowid', 'userid', 'learningplanid', 'courseid', 'classid', 'class_groupid'],
    ];

    $checks[] = [
        'id' => 'inprogress_passed_by_gradebook',
        'title' => 'Cursando (status=2) pero con nota final >= 70 en gradebook (candidato a aprobar)',
        'countsql' => "SELECT COUNT(*)
                         FROM {gmk_course_progre} cp
                         JOIN ($passedsubsql) pg
                           ON pg.userid = cp.userid
                          AND pg.courseid = cp.courseid
                        WHERE cp.status = 2
                          AND pg.gradeval >= :passgrade",
        'countparams' => ['passgrade' => 70.0],
        'rowsql' => "SELECT cp.id AS rowid,
                            cp.userid, cp.learningplanid, cp.courseid, cp.classid, cp.groupid,
                            cp.status, cp.progress, cp.grade, pg.gradeval AS gradebook_grade
                       FROM {gmk_course_progre} cp
                       JOIN ($passedsubsql) pg
                         ON pg.userid = cp.userid
                        AND pg.courseid = cp.courseid
                      WHERE cp.status = 2
                        AND pg.gradeval >= :passgrade
                   ORDER BY cp.userid ASC, cp.learningplanid ASC, cp.courseid ASC, cp.id ASC",
        'rowparams' => ['passgrade' => 70.0],
        'headers' => ['rowid', 'userid', 'learningplanid', 'courseid', 'classid', 'groupid', 'status', 'progress', 'grade', 'gradebook_grade'],
    ];

    $checks[] = [
        'id' => 'finished_with_class_link',
        'title' => 'Completadas/Aprobadas/Reprobadas con classid/groupid enlazado (informativo)',
        'countsql' => "SELECT COUNT(*)
                         FROM {gmk_course_progre} cp
                        WHERE cp.status IN (3,4,5)
                          AND (cp.classid > 0 OR cp.groupid > 0)",
        'countparams' => [],
        'rowsql' => "SELECT cp.id AS rowid,
                            cp.userid, cp.learningplanid, cp.courseid, cp.status, cp.classid, cp.groupid
                       FROM {gmk_course_progre} cp
                      WHERE cp.status IN (3,4,5)
                        AND (cp.classid > 0 OR cp.groupid > 0)
                   ORDER BY cp.userid ASC, cp.learningplanid ASC, cp.courseid ASC, cp.id ASC",
        'rowparams' => [],
        'headers' => ['rowid', 'userid', 'learningplanid', 'courseid', 'status', 'classid', 'groupid'],
    ];

    $result = [
        'generatedat' => date('Y-m-d H:i:s'),
        'summaryrows' => [],
        'details' => [],
        'totalissues' => 0,
    ];

    foreach ($checks as $chk) {
        $count = (int)$DB->count_records_sql($chk['countsql'], $chk['countparams']);
        $rows = [];
        if ($count > 0) {
            $rows = array_values($DB->get_records_sql($chk['rowsql'], $chk['rowparams'], 0, $maxrows));
        }
        $status = ($count > 0) ? 'ISSUE' : 'OK';
        if ($count > 0) {
            $result['totalissues']++;
        }
        $result['summaryrows'][] = [
            'Check' => $chk['title'],
            'Issues' => $count,
            'Estado' => ($count > 0 ? '<span class="badge bad">ISSUE</span>' : '<span class="badge ok">OK</span>'),
        ];
        $result['details'][] = [
            'id' => $chk['id'],
            'title' => $chk['title'],
            'status' => $status,
            'count' => $count,
            'headers' => $chk['headers'],
            'rows' => array_map(static function($r) {
                return (array)$r;
            }, $rows),
            'truncated' => ($count > count($rows)),
        ];
    }

    return $result;
}

function gmk_dbg_pick_progress_record_to_keep(array $records): int {
    $statuspriority = [
        4 => 700, // aprobada
        3 => 650, // completada
        7 => 600, // revalidando
        6 => 500, // pendiente revalida
        2 => 400, // cursando
        5 => 300, // reprobada
        1 => 200, // disponible
        0 => 100, // no disponible
        99 => 550, // migracion
    ];

    $bestid = 0;
    $bestscore = -1.0e18;
    foreach ($records as $r) {
        $status = isset($r->status) ? (int)$r->status : -1;
        $score = ($statuspriority[$status] ?? 0) * 1000000;
        $score += (!empty($r->classid) ? 100000 : 0);
        $score += (!empty($r->groupid) ? 50000 : 0);
        $score += (int)round((float)($r->progress ?? 0) * 100);
        $score += (int)round((float)($r->grade ?? 0) * 100);
        $score += (int)($r->timemodified ?? 0);
        $score += (int)($r->id ?? 0);

        if ($score > $bestscore) {
            $bestscore = $score;
            $bestid = (int)$r->id;
        }
    }
    return $bestid;
}

function gmk_dbg_mass_repair(array $opts): array {
    global $DB;
    $report = [];

    @set_time_limit(0);

    if (!empty($opts['fixdeduplicate'])) {
        $dupkeys = $DB->get_records_sql("
            SELECT CONCAT(cp.userid, '-', cp.courseid, '-', cp.learningplanid) AS rowid,
                   cp.userid, cp.courseid, cp.learningplanid, COUNT(*) AS dupcount
              FROM {gmk_course_progre} cp
          GROUP BY cp.userid, cp.courseid, cp.learningplanid
            HAVING COUNT(*) > 1
        ");
        $deleted = 0;
        $groups = 0;
        foreach ($dupkeys as $k) {
            $groups++;
            $records = $DB->get_records('gmk_course_progre', [
                'userid' => (int)$k->userid,
                'courseid' => (int)$k->courseid,
                'learningplanid' => (int)$k->learningplanid
            ], 'timemodified DESC, id DESC', 'id,status,progress,grade,classid,groupid,timemodified');
            if (count($records) <= 1) {
                continue;
            }
            $keepid = gmk_dbg_pick_progress_record_to_keep(array_values($records));
            foreach ($records as $r) {
                if ((int)$r->id === $keepid) {
                    continue;
                }
                $DB->delete_records('gmk_course_progre', ['id' => (int)$r->id]);
                $deleted++;
            }
        }
        $report[] = "Deduplicación: grupos=$groups, eliminados=$deleted.";
    }

    if (!empty($opts['fixgroupmembers'])) {
        $missingmembers = $DB->get_records_sql("
            SELECT cp.id AS rowid, cp.userid, gc.groupid AS class_groupid
              FROM {gmk_course_progre} cp
              JOIN {gmk_class} gc ON gc.id = cp.classid
         LEFT JOIN {groups_members} gm
                ON gm.userid = cp.userid
               AND gm.groupid = gc.groupid
             WHERE cp.status = 2
               AND cp.classid > 0
               AND gc.groupid > 0
               AND gm.id IS NULL
        ");
        $ok = 0;
        $fail = 0;
        foreach ($missingmembers as $row) {
            $added = groups_add_member((int)$row->class_groupid, (int)$row->userid);
            if ($added) {
                $ok++;
            } else {
                $fail++;
            }
        }
        $report[] = "Sincronización de grupos: agregados=$ok, fallidos=$fail.";
    }

    if (!empty($opts['fixsyncstatus'])) {
        $targets = $DB->get_records_sql("
            SELECT DISTINCT CONCAT(cp.userid, '-', cp.courseid, '-', cp.learningplanid) AS rowid,
                            cp.userid, cp.courseid, cp.learningplanid
              FROM {gmk_course_progre} cp
              JOIN (
                    SELECT gg.userid, gi.courseid, MAX(COALESCE(gg.finalgrade, gg.rawgrade)) AS gradeval
                      FROM {grade_items} gi
                      JOIN {grade_grades} gg ON gg.itemid = gi.id
                      JOIN (
                            SELECT DISTINCT userid, courseid
                              FROM {gmk_course_progre}
                             WHERE status = 2
                           ) sc
                        ON sc.userid = gg.userid
                       AND sc.courseid = gi.courseid
                     WHERE gi.itemtype = 'course'
                  GROUP BY gg.userid, gi.courseid
                   ) pg
                ON pg.userid = cp.userid
               AND pg.courseid = cp.courseid
             WHERE cp.status = 2
               AND pg.gradeval >= :passgrade
        ", ['passgrade' => 70.0]);
        $ok = 0;
        $fail = 0;
        foreach ($targets as $t) {
            $updated = local_grupomakro_progress_manager::update_course_progress(
                (int)$t->courseid,
                (int)$t->userid,
                (int)$t->learningplanid
            );
            if ($updated) {
                $ok++;
            } else {
                $fail++;
            }
        }
        $report[] = "Sincronización de estado por nota: objetivo=" . count($targets) . ", ok=$ok, fail=$fail.";
    }

    if (!empty($opts['fixsyncperiod'])) {
        $lpusers = $DB->get_records_sql("
            SELECT CONCAT(llu.userid, '-', llu.learningplanid) AS rowid,
                   llu.userid, llu.learningplanid
              FROM {local_learning_users} llu
             WHERE llu.learningplanid > 0
          GROUP BY llu.userid, llu.learningplanid
        ");
        $ok = 0;
        $nooporfail = 0;
        foreach ($lpusers as $lpu) {
            $synced = local_grupomakro_progress_manager::sync_student_period(
                (int)$lpu->userid,
                (int)$lpu->learningplanid
            );
            if ($synced) {
                $ok++;
            } else {
                $nooporfail++;
            }
        }
        $report[] = "Sincronización de periodos: procesados=" . count($lpusers) . ", sincronizados=$ok, sin cambio/fail=$nooporfail.";
    }

    return $report;
}

echo $OUTPUT->header();

echo '<style>
    .dbg-wrap { max-width: 1800px; margin: 18px auto; }
    .dbg-card { background: #f8f9fa; border-left: 4px solid #2c7be5; padding: 14px; margin: 14px 0; }
    .dbg-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .dbg-table { width: 100%; border-collapse: collapse; font-size: 12px; margin: 10px 0; background: #fff; }
    .dbg-table th { background: #212529; color: #fff; text-align: left; padding: 8px; border: 1px solid #495057; }
    .dbg-table td { padding: 7px; border: 1px solid #dee2e6; vertical-align: top; }
    .dbg-pre { background: #0f172a; color: #e2e8f0; padding: 12px; font-size: 12px; overflow-x: auto; }
    .badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 700; }
    .badge.ok { background: #198754; color: #fff; }
    .badge.bad { background: #dc3545; color: #fff; }
    .badge.warn { background: #ffc107; color: #111; }
    .muted { color: #6c757d; }
    .okline { color: #198754; font-weight: 600; }
    .badline { color: #dc3545; font-weight: 600; }
</style>';

echo '<div class="dbg-wrap">';
echo '<h2>Debug de filtros: Mi Horario del Estudiante</h2>';

echo '<form method="get" class="dbg-card">';
echo '<div class="dbg-grid">';
echo '<div>';
echo '<label><strong>Buscar estudiante (nombre/email)</strong></label><br>';
echo '<input type="text" name="search" value="' . gmk_dbg_h($search) . '" style="width:100%;" />';
echo '</div>';
echo '<div>';
echo '<label><strong>Student ID</strong></label><br>';
echo '<input type="number" name="userid" value="' . (int)$userid . '" style="width:100%;max-width:280px;" />';
echo '</div>';
echo '</div>';
echo '<div class="dbg-grid" style="margin-top:10px;">';
echo '<div>';
echo '<label><strong>Fecha inicio (YYYY-MM-DD)</strong></label><br>';
echo '<input type="text" name="initdate" value="' . gmk_dbg_h($initdate) . '" style="width:100%;max-width:280px;" />';
echo '</div>';
echo '<div>';
echo '<label><strong>Fecha fin (YYYY-MM-DD)</strong></label><br>';
echo '<input type="text" name="enddate" value="' . gmk_dbg_h($enddate) . '" style="width:100%;max-width:280px;" />';
echo '</div>';
echo '</div>';
echo '<div class="dbg-grid" style="margin-top:10px;">';
echo '<div>';
echo '<label><strong>Filas por pagina</strong></label><br>';
echo '<input type="number" name="perpage" value="' . (int)$perpage . '" min="20" max="200" style="width:100%;max-width:280px;" />';
echo '</div>';
echo '<div>';
echo '<label><strong>Pagina</strong></label><br>';
echo '<input type="number" name="page" value="' . (int)$page . '" min="0" style="width:100%;max-width:280px;" />';
echo '</div>';
echo '</div>';
echo '<div style="margin-top:12px;"><button type="submit" class="btn btn-primary">Diagnosticar</button></div>';
echo '</form>';

echo '<div class="dbg-card">';
echo '<strong>Diagnóstico masivo de integridad (todos los estudiantes)</strong><br>';
echo '<span class="muted">Identifica inconsistencia de progreso/periodo/clase/grupo que afecta "Mi Horario".</span>';
echo '<form method="get" style="margin-top:10px;">';
echo '<input type="hidden" name="search" value="' . gmk_dbg_h($search) . '">';
echo '<input type="hidden" name="initdate" value="' . gmk_dbg_h($initdate) . '">';
echo '<input type="hidden" name="enddate" value="' . gmk_dbg_h($enddate) . '">';
echo '<input type="hidden" name="perpage" value="' . (int)$perpage . '">';
echo '<input type="hidden" name="page" value="' . (int)$page . '">';
echo '<label style="margin-right:8px;"><strong>Max filas detalle por check:</strong></label>';
echo '<input type="number" name="maxrows" value="' . (int)$maxrows . '" min="20" max="1000" style="max-width:120px;">';
echo '<button type="submit" name="runmass" value="1" class="btn btn-secondary" style="margin-left:8px;">Ejecutar diagnóstico masivo</button>';
echo '</form>';
echo '</div>';

$massrepairlog = [];
if ($applymass) {
    require_sesskey();
    $massrepairlog = gmk_dbg_mass_repair([
        'fixdeduplicate' => !empty($fixdeduplicate),
        'fixsyncstatus' => !empty($fixsyncstatus),
        'fixsyncperiod' => !empty($fixsyncperiod),
        'fixgroupmembers' => !empty($fixgroupmembers),
    ]);
    $runmass = 1;
}

$massdiag = null;
if ($runmass) {
    $massdiag = gmk_dbg_mass_collect((int)$maxrows);

    if (!empty($massrepairlog)) {
        echo '<div class="dbg-card"><strong>Resultado de reparación masiva</strong><ul>';
        foreach ($massrepairlog as $line) {
            echo '<li>' . s($line) . '</li>';
        }
        echo '</ul></div>';
    }

    echo '<div class="dbg-card"><strong>Resumen masivo</strong><br>';
    echo '<span class="muted">Generado: ' . s($massdiag['generatedat']) . '</span><br>';
    echo '<span class="muted">Checks con issues: ' . (int)$massdiag['totalissues'] . '</span>';
    echo '</div>';
    gmk_dbg_print_table(['Check', 'Issues', 'Estado'], $massdiag['summaryrows']);

    echo '<div class="dbg-card">';
    echo '<strong>Aplicar corrección masiva</strong><br>';
    echo '<span class="muted">Usa checks para ejecutar correcciones seguras en bloque.</span>';
    echo '<form method="post" style="margin-top:10px;" onsubmit="return confirm(\'Se aplicarán correcciones masivas de integridad. ¿Deseas continuar?\');">';
    echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
    echo '<input type="hidden" name="search" value="' . gmk_dbg_h($search) . '">';
    echo '<input type="hidden" name="initdate" value="' . gmk_dbg_h($initdate) . '">';
    echo '<input type="hidden" name="enddate" value="' . gmk_dbg_h($enddate) . '">';
    echo '<input type="hidden" name="perpage" value="' . (int)$perpage . '">';
    echo '<input type="hidden" name="page" value="' . (int)$page . '">';
    echo '<input type="hidden" name="maxrows" value="' . (int)$maxrows . '">';
    echo '<input type="hidden" name="runmass" value="1">';
    echo '<input type="hidden" name="applymass" value="1">';

    echo '<label style="display:block;"><input type="checkbox" name="fixdeduplicate" value="1" ' . (!empty($fixdeduplicate) ? 'checked' : '') . '> Deduplicar gmk_course_progre (conservar mejor registro por usuario/curso/plan)</label>';
    echo '<label style="display:block;"><input type="checkbox" name="fixsyncstatus" value="1" ' . (!empty($fixsyncstatus) ? 'checked' : '') . '> Sincronizar estado por nota (status=2 con nota final >= 70 pasa a estado final)</label>';
    echo '<label style="display:block;"><input type="checkbox" name="fixsyncperiod" value="1" ' . (!empty($fixsyncperiod) ? 'checked' : '') . '> Sincronizar currentperiodid por estudiante/plan</label>';
    echo '<label style="display:block;"><input type="checkbox" name="fixgroupmembers" value="1" ' . (!empty($fixgroupmembers) ? 'checked' : '') . '> Corregir groups_members faltantes para clases en curso</label>';
    echo '<button type="submit" class="btn btn-primary" style="margin-top:10px;">Aplicar corrección masiva</button>';
    echo '</form>';
    echo '</div>';

    foreach ($massdiag['details'] as $check) {
        echo '<div class="dbg-card">';
        echo '<strong>' . s($check['title']) . '</strong> ';
        if ($check['status'] === 'ISSUE') {
            echo '<span class="badge bad">ISSUE</span>';
        } else {
            echo '<span class="badge ok">OK</span>';
        }
        echo ' <span class="muted">rows=' . (int)$check['count'];
        if (!empty($check['truncated'])) {
            echo ' (mostrando primeras ' . count($check['rows']) . ')';
        }
        echo '</span>';
        echo '</div>';
        gmk_dbg_print_table($check['headers'], $check['rows']);
    }
}

// Listado dinamico de estudiantes (sin necesidad de busqueda manual).
$searchsql = '';
$searchparams = [];
if (trim($search) !== '') {
    $like = '%' . $DB->sql_like_escape(trim($search)) . '%';
    $searchsql = " AND (
        " . $DB->sql_like('u.firstname', ':s1', false, false) . "
        OR " . $DB->sql_like('u.lastname', ':s2', false, false) . "
        OR " . $DB->sql_like('u.email', ':s3', false, false) . "
    )";
    $searchparams = ['s1' => $like, 's2' => $like, 's3' => $like];
}

$totalstudents = (int)$DB->count_records_sql(
    "SELECT COUNT(DISTINCT llu.userid)
       FROM {local_learning_users} llu
       JOIN {user} u ON u.id = llu.userid
      WHERE u.deleted = 0 {$searchsql}",
    $searchparams
);
$offset = $page * $perpage;
$studentlist = $DB->get_records_sql(
    "SELECT u.id,
            u.firstname,
            u.lastname,
            u.email,
            COUNT(DISTINCT CASE WHEN cp.status = 2 THEN cp.courseid END) AS inprogress_courses,
            COUNT(DISTINCT CASE WHEN cp.status = 2 THEN cp.classid END) AS inprogress_classes,
            COUNT(DISTINCT CASE WHEN cp.status = 4 THEN cp.courseid END) AS approved_courses
       FROM {local_learning_users} llu
       JOIN {user} u ON u.id = llu.userid
  LEFT JOIN {gmk_course_progre} cp ON cp.userid = u.id
      WHERE u.deleted = 0 {$searchsql}
   GROUP BY u.id, u.firstname, u.lastname, u.email
   ORDER BY u.lastname ASC, u.firstname ASC, u.id ASC",
    $searchparams,
    $offset,
    $perpage
);

$listrows = [];
foreach ($studentlist as $st) {
    $url = new moodle_url('/local/grupomakro_core/pages/debug_student_schedule_filters.php', [
        'userid' => (int)$st->id,
        'search' => $search,
        'initdate' => $initdate,
        'enddate' => $enddate,
        'page' => $page,
        'perpage' => $perpage
    ]);
    $listrows[] = [
        'ID' => (int)$st->id,
        'Nombre' => s(trim($st->firstname . ' ' . $st->lastname)),
        'Email' => s($st->email),
        'Cursando (materias)' => (int)$st->inprogress_courses,
        'Cursando (clases)' => (int)$st->inprogress_classes,
        'Aprobadas' => (int)$st->approved_courses,
        'Accion' => '<a href="' . $url->out(false) . '">Diagnosticar</a>',
    ];
}

echo '<div class="dbg-card"><strong>Lista de estudiantes</strong><br>';
echo '<span class="muted">Mostrando ' . count($studentlist) . ' de ' . $totalstudents . ' | pagina ' . ($page + 1) . ' | ' . $perpage . ' por pagina</span>';
echo '</div>';
gmk_dbg_print_table(
    ['ID', 'Nombre', 'Email', 'Cursando (materias)', 'Cursando (clases)', 'Aprobadas', 'Accion'],
    $listrows
);

$hasprev = $page > 0;
$hasnext = ($offset + count($studentlist)) < $totalstudents;
if ($hasprev || $hasnext) {
    echo '<div class="dbg-card">';
    if ($hasprev) {
        $prevurl = new moodle_url('/local/grupomakro_core/pages/debug_student_schedule_filters.php', [
            'search' => $search,
            'initdate' => $initdate,
            'enddate' => $enddate,
            'page' => $page - 1,
            'perpage' => $perpage
        ]);
        echo '<a href="' . $prevurl->out(false) . '" class="btn btn-secondary" style="margin-right:10px;">Anterior</a>';
    }
    if ($hasnext) {
        $nexturl = new moodle_url('/local/grupomakro_core/pages/debug_student_schedule_filters.php', [
            'search' => $search,
            'initdate' => $initdate,
            'enddate' => $enddate,
            'page' => $page + 1,
            'perpage' => $perpage
        ]);
        echo '<a href="' . $nexturl->out(false) . '" class="btn btn-secondary">Siguiente</a>';
    }
    echo '</div>';
}

if ($userid <= 0) {
    if ($runmass) {
        echo '<div class="dbg-card"><span class="muted">Puedes seleccionar un estudiante de la lista para ver también el diagnóstico individual.</span></div>';
        echo '</div>';
        echo $OUTPUT->footer();
        exit;
    }
    echo '<div class="dbg-card"><span class="muted">Selecciona un estudiante de la lista para ejecutar el diagnostico completo.</span></div>';
    echo '</div>';
    echo $OUTPUT->footer();
    exit;
}

$user = $DB->get_record('user', ['id' => $userid], 'id,firstname,lastname,email', IGNORE_MISSING);
if (!$user) {
    echo '<div class="dbg-card"><span class="badline">Usuario no encontrado.</span></div>';
    echo '</div>';
    echo $OUTPUT->footer();
    exit;
}

echo '<div class="dbg-card">';
echo '<strong>Estudiante:</strong> ' . s($user->firstname . ' ' . $user->lastname) . ' (' . s($user->email) . ')';
echo ' | <strong>ID:</strong> ' . (int)$user->id;
echo '<br><strong>Rango:</strong> ' . s($initdate) . ' a ' . s($enddate);
echo '</div>';

$learningplanusers = $DB->get_records('local_learning_users', ['userid' => (int)$userid], '', 'id,learningplanid,currentperiodid');
$studentperiodmap = [];
foreach ($learningplanusers as $lpu) {
    if (!empty($lpu->learningplanid) && !empty($lpu->currentperiodid)) {
        $studentperiodmap[(int)$lpu->learningplanid] = (int)$lpu->currentperiodid;
    }
}

$progre = $DB->get_records_sql(
    "SELECT cp.id,
            cp.learningplanid,
            cp.periodid,
            cp.courseid,
            cp.classid,
            cp.status,
            cp.grade,
            cp.timemodified,
            c.fullname AS coursename,
            gc.name AS classname
       FROM {gmk_course_progre} cp
  LEFT JOIN {course} c ON c.id = cp.courseid
  LEFT JOIN {gmk_class} gc ON gc.id = cp.classid
      WHERE cp.userid = :userid
   ORDER BY cp.learningplanid ASC, cp.periodid ASC, cp.courseid ASC, cp.id ASC",
    ['userid' => (int)$userid]
);

$studentHasProgreData = !empty($progre);
$activeCourseSet = [];
$activeClassSet = [];
$courseStatusMap = [];
$classStatusMap = [];
$progrerows = [];

foreach ($progre as $p) {
    $status = (int)$p->status;
    $courseid = (int)$p->courseid;
    $classid = (int)$p->classid;
    $lpid = (int)$p->learningplanid;
    $periodid = (int)$p->periodid;

    if (!isset($courseStatusMap[$courseid])) {
        $courseStatusMap[$courseid] = [];
    }
    $courseStatusMap[$courseid][] = $status;

    if ($classid > 0) {
        if (!isset($classStatusMap[$classid])) {
            $classStatusMap[$classid] = [];
        }
        $classStatusMap[$classid][] = $status;
    }

    $periodmatch = true;
    if ($lpid > 0 && isset($studentperiodmap[$lpid]) && $periodid > 0) {
        $periodmatch = ((int)$studentperiodmap[$lpid] === $periodid);
    }
    $isinprogress = ($status === 2);
    $isactive = ($isinprogress && $periodmatch);

    if ($isactive) {
        if ($courseid > 0) {
            $activeCourseSet[$courseid] = true;
        }
        if ($classid > 0) {
            $activeClassSet[$classid] = true;
        }
    }

    $progrerows[] = [
        'cp.id' => (int)$p->id,
        'learningplanid' => $lpid,
        'periodid' => $periodid,
        'currentperiodid' => isset($studentperiodmap[$lpid]) ? (int)$studentperiodmap[$lpid] : '-',
        'courseid' => $courseid,
        'coursename' => s((string)$p->coursename),
        'classid' => $classid,
        'classname' => s((string)$p->classname),
        'status' => $status . ' (' . s(gmk_dbg_status_label($status)) . ')',
        'grade' => is_null($p->grade) ? '-' : s((string)$p->grade),
        'period_match' => $periodmatch ? '<span class="badge ok">SI</span>' : '<span class="badge bad">NO</span>',
        'active_scope' => $isactive ? '<span class="badge ok">SI</span>' : '<span class="badge bad">NO</span>',
    ];
}

$usergroups = $DB->get_records_sql(
    "SELECT gm.groupid, g.courseid, g.name AS groupname
       FROM {groups_members} gm
       JOIN {groups} g ON g.id = gm.groupid
      WHERE gm.userid = :userid",
    ['userid' => (int)$userid]
);
$userGroupIds = array_values(array_unique(array_map(static function($r) { return (int)$r->groupid; }, $usergroups)));
$userCourseIds = array_values(array_unique(array_map(static function($r) { return (int)$r->courseid; }, $usergroups)));
$userGroupIdSet = array_fill_keys($userGroupIds, true);

$rawEvents = calendar_get_events(strtotime($initdate), strtotime($enddate), false, $userGroupIds, $userCourseIds, true);
$fetchedClasses = [];

$decisionRows = [];
$prefinal = [];
$stats = [
    'raw_total' => 0,
    'raw_supported' => 0,
    'raw_unsupported' => 0,
    'excluded_mapping' => 0,
    'excluded_no_active_course_scope' => 0,
    'excluded_course_not_active' => 0,
    'excluded_class_not_active' => 0,
    'excluded_group_not_member' => 0,
    'included_prefinal' => 0,
];

foreach ($rawEvents as $event) {
    $stats['raw_total']++;
    $supported = false;
    if ($event->modulename === 'attendance' || $event->modulename === 'bigbluebuttonbn') {
        $supported = true;
    } else if (in_array($event->eventtype, ['due', 'gradingdue', 'close', 'open'])) {
        $supported = true;
    }

    if (!$supported) {
        $stats['raw_unsupported']++;
        $decisionRows[] = [
            'eventid' => (int)$event->id,
            'modulename' => s((string)$event->modulename),
            'eventtype' => s((string)$event->eventtype),
            'name' => s((string)$event->name),
            'courseid' => (int)$event->courseid,
            'groupid' => (int)$event->groupid,
            'classid' => '-',
            'start' => date('Y-m-d H:i:s', (int)$event->timestart),
            'course_statuses' => gmk_dbg_status_list($courseStatusMap[(int)$event->courseid] ?? []),
            'class_statuses' => '-',
            'decision' => '<span class="badge warn">SKIP</span> modulo/eventtype no soportado',
        ];
        continue;
    }

    $stats['raw_supported']++;
    if ($event->modulename === 'attendance') {
        $eventComplete = complete_class_event_information($event, $fetchedClasses);
    } else if ($event->modulename === 'bigbluebuttonbn') {
        $eventComplete = complete_class_event_information_bbb($event, $fetchedClasses);
    } else {
        $eventComplete = complete_generic_module_event_information($event, $fetchedClasses);
    }

    if (!$eventComplete) {
        $stats['excluded_mapping']++;
        $decisionRows[] = [
            'eventid' => (int)$event->id,
            'modulename' => s((string)$event->modulename),
            'eventtype' => s((string)$event->eventtype),
            'name' => s((string)$event->name),
            'courseid' => (int)$event->courseid,
            'groupid' => (int)$event->groupid,
            'classid' => '-',
            'start' => date('Y-m-d H:i:s', (int)$event->timestart),
            'course_statuses' => gmk_dbg_status_list($courseStatusMap[(int)$event->courseid] ?? []),
            'class_statuses' => '-',
            'decision' => '<span class="badge bad">EXCLUDE</span> complete_* devolvio false',
        ];
        continue;
    }

    $eventcourseid = !empty($eventComplete->courseid) ? (int)$eventComplete->courseid : 0;
    $eventgroupid = !empty($eventComplete->groupid) ? (int)$eventComplete->groupid : 0;
    $eventclassid = !empty($eventComplete->classId) ? (int)$eventComplete->classId : 0;

    $excludedreason = '';
    if ($studentHasProgreData) {
        if (empty($activeCourseSet)) {
            $stats['excluded_no_active_course_scope']++;
            $excludedreason = 'sin cursos activos (status=2) en gmk_course_progre';
        } else if (!isset($activeCourseSet[$eventcourseid])) {
            $stats['excluded_course_not_active']++;
            $excludedreason = 'courseid fuera de scope activo';
        }
    }

    if ($excludedreason === '' && $studentHasProgreData && !empty($activeClassSet)) {
        if ($eventclassid <= 0 || !isset($activeClassSet[$eventclassid])) {
            $stats['excluded_class_not_active']++;
            $excludedreason = 'classid fuera de scope activo';
        }
    }

    if ($excludedreason === '' && $eventgroupid > 0 && !isset($userGroupIdSet[$eventgroupid])) {
        $stats['excluded_group_not_member']++;
        $excludedreason = 'groupid no pertenece al estudiante';
    }

    if ($excludedreason !== '') {
        $decisionRows[] = [
            'eventid' => (int)$event->id,
            'modulename' => s((string)$eventComplete->modulename),
            'eventtype' => s((string)$eventComplete->eventtype),
            'name' => s((string)$eventComplete->name),
            'courseid' => $eventcourseid,
            'groupid' => $eventgroupid,
            'classid' => $eventclassid > 0 ? $eventclassid : '-',
            'start' => s((string)$eventComplete->start),
            'course_statuses' => gmk_dbg_status_list($courseStatusMap[$eventcourseid] ?? []),
            'class_statuses' => gmk_dbg_status_list($classStatusMap[$eventclassid] ?? []),
            'decision' => '<span class="badge bad">EXCLUDE</span> ' . s($excludedreason),
        ];
        continue;
    }

    $stats['included_prefinal']++;
    $prefinal[] = $eventComplete;
    $decisionRows[] = [
        'eventid' => (int)$event->id,
        'modulename' => s((string)$eventComplete->modulename),
        'eventtype' => s((string)$eventComplete->eventtype),
        'name' => s((string)$eventComplete->name),
        'courseid' => $eventcourseid,
        'groupid' => $eventgroupid,
        'classid' => $eventclassid > 0 ? $eventclassid : '-',
        'start' => s((string)$eventComplete->start),
        'course_statuses' => gmk_dbg_status_list($courseStatusMap[$eventcourseid] ?? []),
        'class_statuses' => gmk_dbg_status_list($classStatusMap[$eventclassid] ?? []),
        'decision' => '<span class="badge ok">INCLUDE</span>',
    ];
}

$attendanceEvents = [];
foreach ($prefinal as $event) {
    if ($event->modulename === 'attendance' && !empty($event->classId)) {
        if (!isset($attendanceEvents[(int)$event->classId])) {
            $attendanceEvents[(int)$event->classId] = [];
        }
        $attendanceEvents[(int)$event->classId][] = (int)$event->timestart;
    }
}

$simulatedFinal = [];
foreach ($prefinal as $event) {
    if ($event->modulename === 'bigbluebuttonbn' && !empty($event->classId)) {
        $isduplicate = false;
        if (isset($attendanceEvents[(int)$event->classId])) {
            foreach ($attendanceEvents[(int)$event->classId] as $attTime) {
                if (abs($attTime - (int)$event->timestart) <= 601) {
                    $isduplicate = true;
                    break;
                }
            }
        }
        if ($isduplicate) {
            continue;
        }
    }
    $simulatedFinal[] = $event;
}

$actualFinal = get_class_events($userid, $initdate, $enddate);
$actualRows = [];
$actualLeaks = 0;
foreach ($actualFinal as $e) {
    $courseid = !empty($e->courseid) ? (int)$e->courseid : 0;
    $classid = !empty($e->classId) ? (int)$e->classId : 0;
    $courseactive = isset($activeCourseSet[$courseid]);
    $classactive = empty($activeClassSet) ? true : isset($activeClassSet[$classid]);
    $isleak = false;
    if ($studentHasProgreData) {
        if (empty($activeCourseSet) || !$courseactive || !$classactive) {
            $isleak = true;
        }
    }
    if ($isleak) {
        $actualLeaks++;
    }
    $actualRows[] = [
        'eventid' => (int)$e->id,
        'modulename' => s((string)$e->modulename),
        'name' => s((string)$e->name),
        'courseid' => $courseid,
        'classid' => $classid > 0 ? $classid : '-',
        'groupid' => !empty($e->groupid) ? (int)$e->groupid : '-',
        'start' => s((string)$e->start),
        'course_statuses' => gmk_dbg_status_list($courseStatusMap[$courseid] ?? []),
        'class_statuses' => gmk_dbg_status_list($classStatusMap[$classid] ?? []),
        'in_active_scope' => $isleak ? '<span class="badge bad">NO</span>' : '<span class="badge ok">SI</span>',
    ];
}

$summaryRows = [
    ['Metrica' => 'local_learning_users', 'Valor' => count($learningplanusers)],
    ['Metrica' => 'gmk_course_progre (rows)', 'Valor' => count($progre)],
    ['Metrica' => 'studentHasProgreData', 'Valor' => $studentHasProgreData ? 'SI' : 'NO'],
    ['Metrica' => 'Cursos activos scope (status=2 + periodo actual)', 'Valor' => count($activeCourseSet)],
    ['Metrica' => 'Clases activas scope (status=2 + periodo actual)', 'Valor' => count($activeClassSet)],
    ['Metrica' => 'Grupos del estudiante', 'Valor' => count($userGroupIds)],
    ['Metrica' => 'Cursos por grupos del estudiante', 'Valor' => count($userCourseIds)],
    ['Metrica' => 'Raw events calendar_get_events', 'Valor' => $stats['raw_total']],
    ['Metrica' => 'Raw soportados', 'Valor' => $stats['raw_supported']],
    ['Metrica' => 'Excluidos: complete_* false', 'Valor' => $stats['excluded_mapping']],
    ['Metrica' => 'Excluidos: sin cursos activos', 'Valor' => $stats['excluded_no_active_course_scope']],
    ['Metrica' => 'Excluidos: course fuera de scope', 'Valor' => $stats['excluded_course_not_active']],
    ['Metrica' => 'Excluidos: class fuera de scope', 'Valor' => $stats['excluded_class_not_active']],
    ['Metrica' => 'Excluidos: group no miembro', 'Valor' => $stats['excluded_group_not_member']],
    ['Metrica' => 'Incluidos pre-dedupe', 'Valor' => $stats['included_prefinal']],
    ['Metrica' => 'Final simulado (dedupe BBB)', 'Valor' => count($simulatedFinal)],
    ['Metrica' => 'Final real get_class_events()', 'Valor' => count($actualFinal)],
    ['Metrica' => 'Eventos potencialmente fuera de scope en final real', 'Valor' => $actualLeaks],
];

echo '<div class="dbg-card"><strong>Resumen del diagnostico</strong></div>';
gmk_dbg_print_table(['Metrica', 'Valor'], $summaryRows);

echo '<div class="dbg-card"><strong>Periodos activos del estudiante (local_learning_users)</strong></div>';
$periodrows = [];
foreach ($learningplanusers as $lpu) {
    $periodrows[] = [
        'learningplanid' => (int)$lpu->learningplanid,
        'currentperiodid' => (int)$lpu->currentperiodid,
    ];
}
gmk_dbg_print_table(['learningplanid', 'currentperiodid'], $periodrows);

echo '<div class="dbg-card"><strong>Progreso del estudiante (gmk_course_progre)</strong></div>';
gmk_dbg_print_table(
    ['cp.id', 'learningplanid', 'periodid', 'currentperiodid', 'courseid', 'coursename', 'classid', 'classname', 'status', 'grade', 'period_match', 'active_scope'],
    $progrerows
);

echo '<div class="dbg-card"><strong>Decisiones por evento (pipeline de filtros)</strong></div>';
gmk_dbg_print_table(
    ['eventid', 'modulename', 'eventtype', 'name', 'courseid', 'groupid', 'classid', 'start', 'course_statuses', 'class_statuses', 'decision'],
    $decisionRows
);

echo '<div class="dbg-card"><strong>Eventos finales reales (get_class_events)</strong></div>';
gmk_dbg_print_table(
    ['eventid', 'modulename', 'name', 'courseid', 'classid', 'groupid', 'start', 'course_statuses', 'class_statuses', 'in_active_scope'],
    $actualRows
);

echo '<div class="dbg-card"><strong>JSON tecnico</strong><pre class="dbg-pre">' .
    s(json_encode([
        'userid' => (int)$userid,
        'initdate' => (string)$initdate,
        'enddate' => (string)$enddate,
        'learningplan_period_map' => $studentperiodmap,
        'active_course_ids' => array_values(array_map('intval', array_keys($activeCourseSet))),
        'active_class_ids' => array_values(array_map('intval', array_keys($activeClassSet))),
        'stats' => $stats,
        'simulated_final_count' => count($simulatedFinal),
        'actual_final_count' => count($actualFinal),
        'actual_potential_leaks' => $actualLeaks,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre></div>';

echo '</div>';
echo $OUTPUT->footer();
