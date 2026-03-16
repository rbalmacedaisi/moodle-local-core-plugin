<?php
/**
 * Academic Demand Gaps
 *
 * Identifies active students with 0 or 1 subjects currently in progress (status=2)
 * and suggests subjects they could be loaded with, prioritising existing open classes
 * from previous periods. When no open class exists, suggests up to 3 subjects that
 * would need a new module opened.
 *
 * @package local_grupomakro_core
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/academic_demand_gaps.php'));
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Brechas de Demanda Academica');
$PAGE->set_heading('Brechas de Demanda Academica');

// ── Filters ───────────────────────────────────────────────────────────────────
$filter_planid = optional_param('planid',  0,      PARAM_INT);
$filter_shift  = optional_param('shift',   '',     PARAM_TEXT);
$filter_min    = optional_param('min',     'both', PARAM_ALPHA); // zero | one | both
$filter_search = optional_param('search',  '',     PARAM_TEXT);

// ── Helpers ───────────────────────────────────────────────────────────────────
function adg_h($v) {
    if ($v === null) { return ''; }
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function adg_badge($text, $type) {
    $styles = array(
        'open' => 'background:#198754;color:#fff',
        'new'  => 'background:#fd7e14;color:#fff',
        'info' => 'background:#0d6efd;color:#fff',
        'zero' => 'background:#dc3545;color:#fff',
        'one'  => 'background:#fd7e14;color:#fff',
        'warn' => 'background:#ffc107;color:#111',
    );
    $style = isset($styles[$type]) ? $styles[$type] : 'background:#444;color:#fff';
    return '<span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;' . $style . '">' . adg_h($text) . '</span>';
}

// ── 1. Jornada custom field ───────────────────────────────────────────────────
$jornadaFieldId = 0;
$jornadaField = $DB->get_record('user_info_field', array('shortname' => 'gmkjourney'), 'id', IGNORE_MISSING);
if ($jornadaField) {
    $jornadaFieldId = (int)$jornadaField->id;
}

// ── 2. Learning plans for dropdown ───────────────────────────────────────────
$allPlans = array();
try {
    $allPlans = $DB->get_records('local_learning_plans', null, 'name ASC', 'id, name');
} catch (Exception $e) {
    $allPlans = array();
}

// ── 3. Distinct shifts ────────────────────────────────────────────────────────
$allShifts = array();
if ($jornadaFieldId > 0) {
    try {
        $shiftRows = $DB->get_fieldset_sql(
            "SELECT DISTINCT data FROM {user_info_data}
              WHERE fieldid = :fid AND " . $DB->sql_isnotempty('user_info_data', 'data', false, false) . "
              ORDER BY data ASC",
            array('fid' => $jornadaFieldId)
        );
        $allShifts = array_values(array_filter((array)$shiftRows));
    } catch (Exception $e) {
        $allShifts = array();
    }
}

// ── 4. HAVING clause (PHP 7 compatible) ───────────────────────────────────────
if ($filter_min === 'zero') {
    $havingClause = 'HAVING COUNT(cp_active.id) = 0';
} else if ($filter_min === 'one') {
    $havingClause = 'HAVING COUNT(cp_active.id) = 1';
} else {
    $havingClause = 'HAVING COUNT(cp_active.id) <= 1';
}

// ── 5. Extra WHERE from filters ───────────────────────────────────────────────
$extraWhere  = '';
$extraParams = array();

if ($filter_planid > 0) {
    $extraWhere .= ' AND lp.id = :planid';
    $extraParams['planid'] = $filter_planid;
}
if ($filter_shift !== '' && $jornadaFieldId > 0) {
    $extraWhere .= ' AND uid_j.data = :shift';
    $extraParams['shift'] = $filter_shift;
}

$searchWhere  = '';
$searchParams = array();
if (trim($filter_search) !== '') {
    $like = '%' . $DB->sql_like_escape(trim($filter_search)) . '%';
    $searchWhere = " AND (" . $DB->sql_like('u.firstname', ':ss1', false) .
                   " OR "  . $DB->sql_like('u.lastname',  ':ss2', false) .
                   " OR "  . $DB->sql_like('u.email',     ':ss3', false) . ")";
    $searchParams = array('ss1' => $like, 'ss2' => $like, 'ss3' => $like);
}

// ── 6. Build main student query ───────────────────────────────────────────────
if ($jornadaFieldId > 0) {
    $jornadaJoin   = "LEFT JOIN {user_info_data} uid_j
                            ON uid_j.userid = u.id AND uid_j.fieldid = {$jornadaFieldId}";
    $jornadaSelect = ', uid_j.data AS shift';
    $jornadaGroup  = ', uid_j.data';
} else {
    $jornadaJoin   = '';
    $jornadaSelect = ", '' AS shift";
    $jornadaGroup  = '';
}

$studentsSql = "
    SELECT llu.id AS subid,
           u.id AS userid,
           u.firstname,
           u.lastname,
           u.email,
           u.idnumber,
           lp.id AS planid,
           lp.name AS planname,
           lp_per.id AS periodid,
           lp_per.name AS periodname,
           COUNT(cp_active.id) AS inprogress_count
           {$jornadaSelect}
      FROM {user} u
      JOIN {local_learning_users} llu
           ON llu.userid = u.id AND llu.userrolename = 'student'
      JOIN {local_learning_plans} lp ON lp.id = llu.learningplanid
      LEFT JOIN {local_learning_periods} lp_per ON lp_per.id = llu.currentperiodid
      {$jornadaJoin}
      LEFT JOIN {gmk_course_progre} cp_active
           ON cp_active.userid = u.id AND cp_active.status = 2
     WHERE u.deleted = 0
       AND u.suspended = 0
       AND llu.status = 'activo'
       {$extraWhere}
       {$searchWhere}
  GROUP BY llu.id,
           u.id,
           u.firstname,
           u.lastname,
           u.email,
           u.idnumber,
           lp.id,
           lp.name,
           lp_per.id,
           lp_per.name
           {$jornadaGroup}
  {$havingClause}
  ORDER BY lp.name ASC, u.lastname ASC, u.firstname ASC
     LIMIT 600";

$students = array();
try {
    $allParams = array_merge($extraParams, $searchParams);
    $students  = array_values($DB->get_records_sql($studentsSql, $allParams));
} catch (Exception $e) {
    $students = array();
    debugging('academic_demand_gaps student query failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
}

// ── 7. Plan curriculum structure ──────────────────────────────────────────────
// structure[planid][courseid] = object(period_id, period_name, sem_num, fullname, courseid)
$structure     = array();
$planPeriodIdx = array();

try {
    $structureRows = $DB->get_records_sql(
        "SELECT lpc.id AS linkid,
                p.learningplanid,
                p.id AS period_id,
                p.name AS period_name,
                c.id AS courseid,
                c.fullname
           FROM {local_learning_periods} p
           JOIN {local_learning_courses} lpc ON lpc.periodid = p.id
           JOIN {course} c ON c.id = lpc.courseid
          ORDER BY p.learningplanid ASC, p.id ASC"
    );

    foreach ($structureRows as $r) {
        $pid   = (int)$r->learningplanid;
        $cid   = (int)$r->courseid;
        $perid = (int)$r->period_id;

        if (!isset($planPeriodIdx[$pid])) {
            $planPeriodIdx[$pid] = array();
        }
        if (!isset($planPeriodIdx[$pid][$perid])) {
            $planPeriodIdx[$pid][$perid] = count($planPeriodIdx[$pid]) + 1;
        }

        $structure[$pid][$cid] = (object)array(
            'period_id'   => $perid,
            'period_name' => (string)$r->period_name,
            'sem_num'     => (int)$planPeriodIdx[$pid][$perid],
            'fullname'    => (string)$r->fullname,
            'courseid'    => $cid,
        );
    }
} catch (Exception $e) {
    debugging('academic_demand_gaps structure query failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
}

// ── 8. Approved / completed courses (bulk) ────────────────────────────────────
$approvedMap = array();
try {
    $rows = $DB->get_records_sql(
        "SELECT id, userid, courseid FROM {gmk_course_progre} WHERE status IN (3, 4)"
    );
    foreach ($rows as $r) {
        $approvedMap[(int)$r->userid][(int)$r->courseid] = true;
    }
} catch (Exception $e) { /* ignore */ }

try {
    $rows = $DB->get_records_sql(
        "SELECT id, userid, course FROM {course_completions} WHERE timecompleted > 0"
    );
    foreach ($rows as $r) {
        $approvedMap[(int)$r->userid][(int)$r->course] = true;
    }
} catch (Exception $e) { /* ignore */ }

// ── 9. In-progress courses per student (bulk) ─────────────────────────────────
$inProgressMap = array();
try {
    $rows = $DB->get_records_sql(
        "SELECT id, userid, courseid FROM {gmk_course_progre} WHERE status = 2"
    );
    foreach ($rows as $r) {
        $inProgressMap[(int)$r->userid][(int)$r->courseid] = true;
    }
} catch (Exception $e) { /* ignore */ }

// ── 10. Open classes indexed by corecourseid (oldest first = previous period) ─
$openClassByCourseid = array();
try {
    $openRows = $DB->get_records_sql(
        "SELECT gc.id,
                gc.name,
                gc.corecourseid,
                gc.shift,
                gc.type,
                gc.initdate,
                gc.enddate
           FROM {gmk_class} gc
          WHERE gc.approved = 1
            AND gc.closed = 0
            AND gc.corecourseid > 0
            AND gc.enddate > :now
          ORDER BY gc.initdate ASC",
        array('now' => time() - 86400 * 14)
    );
    foreach ($openRows as $r) {
        $cid = (int)$r->corecourseid;
        if (!isset($openClassByCourseid[$cid])) {
            $openClassByCourseid[$cid] = array();
        }
        $openClassByCourseid[$cid][] = $r;
    }
} catch (Exception $e) {
    debugging('academic_demand_gaps open classes query failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
}

// ── 11. Build per-student analysis ────────────────────────────────────────────
$results     = array();
$statsZero   = 0;
$statsOne    = 0;
$statsWithOpen = 0;
$statsNeedNew  = 0;

foreach ($students as $stu) {
    $uid         = (int)$stu->userid;
    $planid      = (int)$stu->planid;
    $perid       = (int)(isset($stu->periodid) ? $stu->periodid : 0);
    $inprogress  = (int)$stu->inprogress_count;

    if ($inprogress === 0) { $statsZero++; } else { $statsOne++; }

    $currentSemNum = 0;
    if (isset($planPeriodIdx[$planid][$perid])) {
        $currentSemNum = (int)$planPeriodIdx[$planid][$perid];
    }

    $planCourses = isset($structure[$planid]) ? $structure[$planid] : array();

    // Pending = in plan up to current sem, not approved, not in progress
    $pending = array();
    foreach ($planCourses as $cid => $courseInfo) {
        if ($currentSemNum > 0 && $courseInfo->sem_num > $currentSemNum) {
            continue;
        }
        if (!empty($approvedMap[$uid][$cid])) {
            continue;
        }
        if (isset($inProgressMap[$uid][$cid])) {
            continue;
        }
        $pending[$cid] = $courseInfo;
    }

    $suggestOpen = array();
    $suggestNew  = array();

    foreach ($pending as $cid => $courseInfo) {
        if (!empty($openClassByCourseid[$cid])) {
            $suggestOpen[$cid] = array(
                'course'  => $courseInfo,
                'classes' => $openClassByCourseid[$cid],
            );
        } else {
            $suggestNew[$cid] = $courseInfo;
        }
    }

    // Limit new-module suggestions to 3
    $suggestNew = array_slice($suggestNew, 0, 3, true);

    if (!empty($suggestOpen)) { $statsWithOpen++; }
    if (!empty($suggestNew))  { $statsNeedNew++;  }

    $results[] = array(
        'stu'          => $stu,
        'inprogress'   => $inprogress,
        'pending_count'=> count($pending),
        'suggestOpen'  => $suggestOpen,
        'suggestNew'   => $suggestNew,
    );
}

// ── OUTPUT ────────────────────────────────────────────────────────────────────
echo $OUTPUT->header();
?>
<style>
.adg-wrap{max-width:1600px;margin:0 auto;padding:16px;font-family:system-ui,sans-serif}
.adg-card{background:#f8f9fa;border-left:4px solid #2c7be5;border-radius:4px;padding:14px 18px;margin:14px 0}
.adg-grid-4{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:14px 0}
.adg-stat{background:#fff;border:1px solid #dee2e6;border-radius:8px;padding:16px;text-align:center}
.adg-stat .num{font-size:2rem;font-weight:800;line-height:1}
.adg-stat .lbl{font-size:12px;color:#6c757d;margin-top:4px}
.adg-filters{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;background:#fff;border:1px solid #dee2e6;border-radius:8px;padding:14px;margin:14px 0}
.adg-filters label{font-size:12px;font-weight:600;color:#495057;display:block;margin-bottom:3px}
.adg-filters select,.adg-filters input[type=text]{padding:6px 10px;border:1px solid #ced4da;border-radius:4px;font-size:13px;background:#fff}
.adg-table{width:100%;border-collapse:collapse;font-size:12px;margin:10px 0}
.adg-table th{background:#212529;color:#fff;text-align:left;padding:8px 10px;border:1px solid #495057;white-space:nowrap}
.adg-table td{padding:8px 10px;border:1px solid #dee2e6;vertical-align:top}
.adg-table tr:nth-child(even) td{background:#f8f9fa}
.adg-table tr:hover td{background:#e8f4ff}
.sug-list{list-style:none;margin:0;padding:0}
.sug-list li{margin-bottom:5px}
.sug-open{background:#d1e7dd;border-radius:4px;padding:4px 8px;font-size:11px}
.sug-open .cn{font-weight:600;color:#0f5132}
.sug-open .cm{color:#155724;font-size:10px}
.sug-new{background:#fff3cd;border-radius:4px;padding:4px 8px;font-size:11px}
.sug-new .cn{font-weight:600;color:#664d03}
.sug-new .ca{font-size:10px;color:#856404}
.muted{color:#6c757d;font-size:11px}
.sec-title{font-size:14px;font-weight:700;margin:20px 0 6px;padding-bottom:4px;border-bottom:2px solid #dee2e6}
.adg-legend{display:flex;gap:16px;flex-wrap:wrap;font-size:12px;margin:8px 0}
.adg-legend-dot{width:12px;height:12px;border-radius:3px;flex-shrink:0;display:inline-block;margin-right:6px;vertical-align:middle}
</style>

<div class="adg-wrap">
<h2 style="margin-bottom:4px">Brechas de Demanda Academica</h2>
<p class="muted" style="margin-bottom:12px">
    Estudiantes activos con 0 o 1 asignaturas en curso (Cursando) y sugerencias de carga.
    Se priorizan modulos abiertos de periodos anteriores. Si no hay modulo abierto, se sugiere apertura (max. 3).
</p>

<!-- Filters -->
<form method="get" class="adg-filters">
    <div>
        <label>Buscar estudiante</label>
        <input type="text" name="search" value="<?php echo adg_h($filter_search); ?>" placeholder="Nombre o email" style="width:220px">
    </div>
    <div>
        <label>Plan de estudios</label>
        <select name="planid">
            <option value="0">- Todos -</option>
            <?php foreach ($allPlans as $pl): ?>
            <option value="<?php echo (int)$pl->id; ?>" <?php echo ((int)$pl->id === $filter_planid ? 'selected' : ''); ?>>
                <?php echo adg_h($pl->name); ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label>Jornada</label>
        <?php if (!empty($allShifts)): ?>
        <select name="shift">
            <option value="">- Todas -</option>
            <?php foreach ($allShifts as $sh): ?>
            <option value="<?php echo adg_h($sh); ?>" <?php echo ($sh === $filter_shift ? 'selected' : ''); ?>>
                <?php echo adg_h($sh); ?>
            </option>
            <?php endforeach; ?>
        </select>
        <?php else: ?>
        <input type="text" name="shift" value="<?php echo adg_h($filter_shift); ?>" placeholder="Matutina, Vespertina..." style="width:160px">
        <?php endif; ?>
    </div>
    <div>
        <label>Mostrar</label>
        <select name="min">
            <option value="both" <?php echo ($filter_min === 'both' ? 'selected' : ''); ?>>0 y 1 asignatura</option>
            <option value="zero" <?php echo ($filter_min === 'zero' ? 'selected' : ''); ?>>Solo 0 asignaturas</option>
            <option value="one"  <?php echo ($filter_min === 'one'  ? 'selected' : ''); ?>>Solo 1 asignatura</option>
        </select>
    </div>
    <div>
        <button type="submit" class="btn btn-primary" style="font-size:13px">Aplicar</button>
        <a href="<?php echo (new moodle_url('/local/grupomakro_core/pages/academic_demand_gaps.php'))->out(false); ?>"
           class="btn btn-secondary" style="margin-left:6px;font-size:13px">Limpiar</a>
    </div>
</form>

<!-- Stats -->
<div class="adg-grid-4">
    <div class="adg-stat">
        <div class="num" style="color:#dc3545"><?php echo count($results); ?></div>
        <div class="lbl">Estudiantes con brechas</div>
    </div>
    <div class="adg-stat">
        <div class="num" style="color:#dc3545"><?php echo $statsZero; ?></div>
        <div class="lbl">Sin ninguna asignatura activa</div>
    </div>
    <div class="adg-stat">
        <div class="num" style="color:#fd7e14"><?php echo $statsOne; ?></div>
        <div class="lbl">Con solo 1 asignatura activa</div>
    </div>
    <div class="adg-stat">
        <div class="num" style="color:#198754"><?php echo $statsWithOpen; ?></div>
        <div class="lbl">Con modulo abierto disponible</div>
    </div>
</div>

<!-- Legend -->
<div class="adg-legend">
    <div><span class="adg-legend-dot" style="background:#198754"></span>Modulo abierto disponible — estudiante puede inscribirse</div>
    <div><span class="adg-legend-dot" style="background:#ffc107"></span>Sin modulo — se recomienda apertura (max. 3 asignaturas)</div>
</div>

<?php if (empty($results)): ?>
<div class="adg-card" style="border-color:#198754;background:#f0faf4;margin-top:20px">
    <strong>No se encontraron estudiantes con brechas</strong> con los filtros actuales.
</div>
<?php else: ?>

<div class="sec-title">
    Resultados (<?php echo count($results); ?> estudiante<?php echo count($results) !== 1 ? 's' : ''; ?>)
    <?php if (count($results) >= 600): ?>
    <span class="muted"> — Mostrando los primeros 600. Usa los filtros para acotar.</span>
    <?php endif; ?>
</div>

<table class="adg-table">
<thead>
<tr>
    <th>#</th>
    <th>Estudiante</th>
    <th>Jornada</th>
    <th>Plan</th>
    <th>Periodo actual</th>
    <th>En curso</th>
    <th>Pendientes</th>
    <th style="min-width:360px">Sugerencias</th>
</tr>
</thead>
<tbody>
<?php foreach ($results as $idx => $row):
    $stu       = $row['stu'];
    $inprog    = $row['inprogress'];
    $sugOpen   = $row['suggestOpen'];
    $sugNew    = $row['suggestNew'];
    $pendCount = $row['pending_count'];
    $fullname  = trim($stu->firstname . ' ' . $stu->lastname);
    $shift     = isset($stu->shift) ? trim((string)$stu->shift) : '';
?>
<tr>
    <td class="muted"><?php echo $idx + 1; ?></td>
    <td>
        <strong><?php echo adg_h($fullname); ?></strong><br>
        <span class="muted"><?php echo adg_h($stu->email); ?></span>
        <?php if (!empty($stu->idnumber)): ?>
        <br><span class="muted">ID: <?php echo adg_h($stu->idnumber); ?></span>
        <?php endif; ?>
    </td>
    <td><?php echo adg_h($shift !== '' ? $shift : '—'); ?></td>
    <td style="font-size:11px"><?php echo adg_h($stu->planname); ?></td>
    <td style="font-size:11px"><?php echo adg_h(isset($stu->periodname) && $stu->periodname ? $stu->periodname : '—'); ?></td>
    <td>
        <?php if ($inprog === 0): ?>
            <?php echo adg_badge('0 — Sin asignaturas', 'zero'); ?>
        <?php else: ?>
            <?php echo adg_badge('1 asignatura', 'one'); ?>
        <?php endif; ?>
    </td>
    <td>
        <?php if ($pendCount === 0): ?>
            <span class="muted">Sin pendientes en plan</span>
        <?php else: ?>
            <?php echo adg_badge($pendCount . ' pendiente' . ($pendCount !== 1 ? 's' : ''), 'info'); ?>
        <?php endif; ?>
    </td>
    <td>
        <?php if (empty($sugOpen) && empty($sugNew)): ?>
            <span class="muted">Sin asignaturas pendientes identificadas.</span>
        <?php else: ?>
        <ul class="sug-list">
            <?php foreach ($sugOpen as $cid => $sug):
                $ci      = $sug['course'];
                $cls     = $sug['classes'][0];
                $cdate   = date('d/m/Y', (int)$cls->initdate);
                $cedate  = date('d/m/Y', (int)$cls->enddate);
                $cshift  = isset($cls->shift) ? trim((string)$cls->shift) : '';
                $ctype   = ((int)$cls->type === 1) ? 'Virtual' : 'Presencial';
                $total   = count($sug['classes']);
            ?>
            <li>
                <div class="sug-open">
                    <div class="cn">
                        &#128217; <?php echo adg_h($ci->fullname); ?>
                        <span style="font-weight:400;color:#6c757d"> Sem. <?php echo (int)$ci->sem_num; ?></span>
                    </div>
                    <div class="cm">
                        Modulo: <strong><?php echo adg_h($cls->name); ?></strong>
                        &middot; <?php echo adg_h($ctype); ?>
                        <?php if ($cshift !== ''): ?>&middot; <?php echo adg_h($cshift); ?><?php endif; ?>
                        &middot; <?php echo adg_h($cdate); ?> &ndash; <?php echo adg_h($cedate); ?>
                        <?php if ($total > 1): ?>&middot; <em>(+<?php echo $total - 1; ?> mas)</em><?php endif; ?>
                    </div>
                </div>
            </li>
            <?php endforeach; ?>
            <?php foreach ($sugNew as $cid => $ci): ?>
            <li>
                <div class="sug-new">
                    <div class="cn">
                        &#128217; <?php echo adg_h($ci->fullname); ?>
                        <span style="font-weight:400;color:#856404"> Sem. <?php echo (int)$ci->sem_num; ?></span>
                    </div>
                    <div class="ca">&#9888; Sin modulo abierto &mdash; se recomienda apertura de clase</div>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<!-- Summary by plan -->
<?php
$byPlan = array();
foreach ($results as $row) {
    $pname = (string)$row['stu']->planname;
    if (!isset($byPlan[$pname])) {
        $byPlan[$pname] = array('zero' => 0, 'one' => 0, 'with_open' => 0, 'need_new' => 0);
    }
    if ($row['inprogress'] === 0) { $byPlan[$pname]['zero']++; }
    else                          { $byPlan[$pname]['one']++;  }
    if (!empty($row['suggestOpen'])) { $byPlan[$pname]['with_open']++; }
    if (!empty($row['suggestNew']))  { $byPlan[$pname]['need_new']++;  }
}
?>
<div class="sec-title" style="margin-top:30px">Resumen por Plan de Estudios</div>
<table class="adg-table" style="max-width:900px">
<thead>
<tr>
    <th>Plan</th>
    <th>Sin asignaturas (0)</th>
    <th>Con 1 asignatura</th>
    <th>Con modulo abierto</th>
    <th>Necesitan apertura</th>
</tr>
</thead>
<tbody>
<?php foreach ($byPlan as $planName => $counts): ?>
<tr>
    <td><strong><?php echo adg_h($planName); ?></strong></td>
    <td><?php echo (int)$counts['zero']; ?></td>
    <td><?php echo (int)$counts['one']; ?></td>
    <td style="color:#198754;font-weight:700"><?php echo (int)$counts['with_open']; ?></td>
    <td style="color:#fd7e14;font-weight:700"><?php echo (int)$counts['need_new']; ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<?php endif; ?>
</div>
<?php echo $OUTPUT->footer(); ?>
