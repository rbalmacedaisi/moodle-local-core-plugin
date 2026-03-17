<?php
/**
 * Academic Demand Gaps & Failed Subjects
 *
 * Tab 1 (Brechas): Active students with 0 or 1 subjects in-progress (status=2)
 *   who still have subjects in status Disponible (1) or No disponible (0).
 *   Excludes PRÁCTICA PROFESIONAL / PROYECTO DE GRADO.
 *   Skips students with no pending subjects (cycle complete).
 *
 * Tab 2 (Reprobados): Active students with failed subjects (status=5).
 *   Shows which failed subjects have an open/upcoming class so the student
 *   can re-enrol after completing the payment process.
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

// ── Parameters ────────────────────────────────────────────────────────────────
$active_tab    = optional_param('tab',    'gaps', PARAM_ALPHA);
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
        'fail' => 'background:#6f42c1;color:#fff',
    );
    $style = isset($styles[$type]) ? $styles[$type] : 'background:#444;color:#fff';
    return '<span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;' . $style . '">' . adg_h($text) . '</span>';
}

/** Returns true if course fullname is excluded (PRÁCTICA / PROYECTO) */
function adg_is_excluded_course($fullname) {
    $fn = mb_strtoupper((string)$fullname, 'UTF-8');
    if (strpos($fn, 'PRACTICA PROFESIONAL') !== false) { return true; }
    if (strpos($fn, 'PRÁCTICA PROFESIONAL') !== false) { return true; }
    if (strpos($fn, 'PROYECTO DE GRADO')    !== false) { return true; }
    return false;
}

// ── 1. Jornada custom field ───────────────────────────────────────────────────
$jornadaFieldId = 0;
try {
    $jornadaField = $DB->get_record('user_info_field', array('shortname' => 'gmkjourney'), 'id', IGNORE_MISSING);
    if ($jornadaField) { $jornadaFieldId = (int)$jornadaField->id; }
} catch (Exception $e) { }

// ── 2. Learning plans dropdown ────────────────────────────────────────────────
$allPlans = array();
try {
    $allPlans = $DB->get_records('local_learning_plans', null, 'name ASC', 'id, name');
} catch (Exception $e) { }

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
    } catch (Exception $e) { }
}

// ── 4. Jornada SQL fragments ──────────────────────────────────────────────────
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

$allFiltersParams = array_merge($extraParams, $searchParams);

// ── 6. Plan curriculum structure (for semester numbers) ───────────────────────
// structure[planid][courseid] = object(sem_num, fullname)
$structure     = array();
$planPeriodIdx = array();
try {
    $structureRows = $DB->get_records_sql(
        "SELECT lpc.id AS linkid,
                p.learningplanid,
                p.id AS period_id,
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
        if (!isset($planPeriodIdx[$pid]))       { $planPeriodIdx[$pid] = array(); }
        if (!isset($planPeriodIdx[$pid][$perid])) {
            $planPeriodIdx[$pid][$perid] = count($planPeriodIdx[$pid]) + 1;
        }
        $structure[$pid][$cid] = (object)array(
            'sem_num'  => (int)$planPeriodIdx[$pid][$perid],
            'fullname' => (string)$r->fullname,
            'courseid' => $cid,
        );
    }
} catch (Exception $e) { }

// ── 7. Open classes by courseid (oldest/prev-period first) ───────────────────
$openClassByCourseid = array();
try {
    $openRows = $DB->get_records_sql(
        "SELECT gc.id, gc.name, gc.corecourseid, gc.shift, gc.type, gc.initdate, gc.enddate
           FROM {gmk_class} gc
          WHERE gc.approved = 1
            AND gc.closed   = 0
            AND gc.corecourseid > 0
            AND gc.enddate > :now
          ORDER BY gc.initdate ASC",
        array('now' => time() - 86400 * 14)
    );
    foreach ($openRows as $r) {
        $cid = (int)$r->corecourseid;
        if (!isset($openClassByCourseid[$cid])) { $openClassByCourseid[$cid] = array(); }
        $openClassByCourseid[$cid][] = $r;
    }
} catch (Exception $e) { }

// ══════════════════════════════════════════════════════════════════════════════
// TAB 1: BRECHAS DE CARGA
// Pending subjects = gmk_course_progre.status IN (0,1), excluding PRACTICA
// ══════════════════════════════════════════════════════════════════════════════

// pendingByUser[userid][courseid] = array('fullname'=>..., 'cpstatus'=>0|1)
$pendingByUser = array();
try {
    $pendingRows = $DB->get_records_sql(
        "SELECT cp.id, cp.userid, cp.courseid, c.fullname, cp.status AS cpstatus
           FROM {gmk_course_progre} cp
           JOIN {course} c ON c.id = cp.courseid
          WHERE cp.status IN (0, 1)"
    );
    foreach ($pendingRows as $r) {
        if (adg_is_excluded_course($r->fullname)) { continue; }
        $pendingByUser[(int)$r->userid][(int)$r->courseid] = array(
            'fullname' => (string)$r->fullname,
            'cpstatus' => (int)$r->cpstatus,
        );
    }
} catch (Exception $e) { }

// HAVING clause
if ($filter_min === 'zero') {
    $havingClause = 'HAVING COUNT(cp_active.id) = 0';
} else if ($filter_min === 'one') {
    $havingClause = 'HAVING COUNT(cp_active.id) = 1';
} else {
    $havingClause = 'HAVING COUNT(cp_active.id) <= 1';
}

$gapStudentsSql = "
    SELECT llu.id AS subid,
           u.id AS userid,
           u.firstname, u.lastname, u.email, u.idnumber,
           lp.id AS planid, lp.name AS planname,
           lp_per.id AS periodid, lp_per.name AS periodname,
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
  GROUP BY llu.id, u.id, u.firstname, u.lastname, u.email, u.idnumber,
           lp.id, lp.name, lp_per.id, lp_per.name
           {$jornadaGroup}
  {$havingClause}
  ORDER BY lp.name ASC, u.lastname ASC, u.firstname ASC
     LIMIT 600";

$gapRawStudents = array();
try {
    $gapRawStudents = array_values($DB->get_records_sql($gapStudentsSql, $allFiltersParams));
} catch (Exception $e) { }

$gapResults    = array();
$statsZero     = 0;
$statsOne      = 0;
$statsWithOpen = 0;
$statsNeedNew  = 0;

foreach ($gapRawStudents as $stu) {
    $uid    = (int)$stu->userid;
    $planid = (int)$stu->planid;
    $inprog = (int)$stu->inprogress_count;

    $pending = isset($pendingByUser[$uid]) ? $pendingByUser[$uid] : array();
    if (empty($pending)) {
        // No pending subjects = cycle complete, skip
        continue;
    }

    if ($inprog === 0) { $statsZero++; } else { $statsOne++; }

    $suggestOpen = array();
    $suggestNew  = array();

    foreach ($pending as $cid => $info) {
        $semNum = isset($structure[$planid][$cid]) ? (int)$structure[$planid][$cid]->sem_num : 0;
        if (!empty($openClassByCourseid[$cid])) {
            $suggestOpen[$cid] = array(
                'fullname' => $info['fullname'],
                'sem_num'  => $semNum,
                'cpstatus' => $info['cpstatus'],
                'classes'  => $openClassByCourseid[$cid],
            );
        } else {
            if (count($suggestNew) < 3) {
                $suggestNew[$cid] = array(
                    'fullname' => $info['fullname'],
                    'sem_num'  => $semNum,
                    'cpstatus' => $info['cpstatus'],
                );
            }
        }
    }

    if (!empty($suggestOpen)) { $statsWithOpen++; }
    if (!empty($suggestNew))  { $statsNeedNew++;  }

    $gapResults[] = array(
        'stu'          => $stu,
        'inprogress'   => $inprog,
        'pending_count'=> count($pending),
        'suggestOpen'  => $suggestOpen,
        'suggestNew'   => $suggestNew,
    );
}

// ══════════════════════════════════════════════════════════════════════════════
// TAB 2: REPROBADOS
// Failed subjects = gmk_course_progre.status = 5, excluding PRACTICA
// ══════════════════════════════════════════════════════════════════════════════

// failedByUser[userid][courseid] = fullname
$failedByUser = array();
try {
    $failedRows = $DB->get_records_sql(
        "SELECT cp.id, cp.userid, cp.courseid, c.fullname
           FROM {gmk_course_progre} cp
           JOIN {course} c ON c.id = cp.courseid
          WHERE cp.status = 5"
    );
    foreach ($failedRows as $r) {
        if (adg_is_excluded_course($r->fullname)) { continue; }
        $failedByUser[(int)$r->userid][(int)$r->courseid] = (string)$r->fullname;
    }
} catch (Exception $e) { }

$failedStudentsSql = "
    SELECT llu.id AS subid,
           u.id AS userid,
           u.firstname, u.lastname, u.email, u.idnumber,
           lp.id AS planid, lp.name AS planname,
           lp_per.id AS periodid, lp_per.name AS periodname
           {$jornadaSelect}
      FROM {user} u
      JOIN {local_learning_users} llu
           ON llu.userid = u.id AND llu.userrolename = 'student'
      JOIN {local_learning_plans} lp ON lp.id = llu.learningplanid
      LEFT JOIN {local_learning_periods} lp_per ON lp_per.id = llu.currentperiodid
      {$jornadaJoin}
     WHERE u.deleted = 0
       AND u.suspended = 0
       AND llu.status = 'activo'
       {$extraWhere}
       {$searchWhere}
  GROUP BY llu.id, u.id, u.firstname, u.lastname, u.email, u.idnumber,
           lp.id, lp.name, lp_per.id, lp_per.name
           {$jornadaGroup}
  ORDER BY lp.name ASC, u.lastname ASC, u.firstname ASC
     LIMIT 1000";

$failedRawStudents = array();
try {
    $failedRawStudents = array_values($DB->get_records_sql($failedStudentsSql, $allFiltersParams));
} catch (Exception $e) { }

$failedResults       = array();
$statsFailedStudents = 0;
$statsFailedWithOpen = 0;
$statsFailedNoOpen   = 0;

foreach ($failedRawStudents as $stu) {
    $uid = (int)$stu->userid;
    if (empty($failedByUser[$uid])) { continue; }

    $planid = (int)$stu->planid;
    $statsFailedStudents++;

    $subjectsWithOpen = array();
    $subjectsNoOpen   = array();

    foreach ($failedByUser[$uid] as $cid => $fullname) {
        $semNum = isset($structure[$planid][$cid]) ? (int)$structure[$planid][$cid]->sem_num : 0;
        if (!empty($openClassByCourseid[$cid])) {
            $subjectsWithOpen[$cid] = array(
                'fullname' => $fullname,
                'sem_num'  => $semNum,
                'classes'  => $openClassByCourseid[$cid],
            );
        } else {
            $subjectsNoOpen[$cid] = array(
                'fullname' => $fullname,
                'sem_num'  => $semNum,
            );
        }
    }

    if (!empty($subjectsWithOpen)) { $statsFailedWithOpen++; }
    if (!empty($subjectsNoOpen))   { $statsFailedNoOpen++;   }

    $failedResults[] = array(
        'stu'              => $stu,
        'subjectsWithOpen' => $subjectsWithOpen,
        'subjectsNoOpen'   => $subjectsNoOpen,
    );
}

// ── OUTPUT ────────────────────────────────────────────────────────────────────
echo $OUTPUT->header();
?>
<style>
.adg-wrap{max-width:1700px;margin:0 auto;padding:16px;font-family:system-ui,sans-serif}
.adg-tabs{display:flex;gap:0;border-bottom:2px solid #dee2e6;margin-bottom:16px}
.adg-tab{padding:10px 24px;font-size:14px;font-weight:600;cursor:pointer;border:1px solid transparent;border-bottom:none;border-radius:6px 6px 0 0;color:#6c757d;text-decoration:none;background:#f8f9fa}
.adg-tab:hover{background:#e9ecef;color:#212529;text-decoration:none}
.adg-tab.active{background:#fff;color:#0d6efd;border-color:#dee2e6 #dee2e6 #fff;margin-bottom:-2px}
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
.fail-open{background:#e8d5f5;border-radius:4px;padding:4px 8px;font-size:11px;margin-bottom:4px}
.fail-open .cn{font-weight:600;color:#3d1a6e}
.fail-open .cm{color:#4a2080;font-size:10px}
.fail-none{background:#f0f0f0;border-radius:4px;padding:4px 8px;font-size:11px;color:#6c757d}
.muted{color:#6c757d;font-size:11px}
.sec-title{font-size:14px;font-weight:700;margin:20px 0 6px;padding-bottom:4px;border-bottom:2px solid #dee2e6}
.adg-legend{display:flex;gap:16px;flex-wrap:wrap;font-size:12px;margin:8px 0}
.adg-legend-dot{width:12px;height:12px;border-radius:3px;flex-shrink:0;display:inline-block;margin-right:6px;vertical-align:middle}
.adg-alert-info{background:#cff4fc;border:1px solid #9eeaf9;border-radius:6px;padding:10px 16px;margin:10px 0;font-size:13px}
</style>

<div class="adg-wrap">
<h2 style="margin-bottom:4px">Brechas de Demanda Academica</h2>

<?php
// ── Tabs ───────────────────────────────────────────────────────────────────
$baseUrl = new moodle_url('/local/grupomakro_core/pages/academic_demand_gaps.php',
    array('planid' => $filter_planid, 'shift' => $filter_shift, 'search' => $filter_search));
$tabGapsUrl   = clone $baseUrl; $tabGapsUrl->param('tab', 'gaps');
$tabFailedUrl = clone $baseUrl; $tabFailedUrl->param('tab', 'failed');
?>
<div class="adg-tabs">
    <a class="adg-tab <?php echo ($active_tab !== 'failed' ? 'active' : ''); ?>"
       href="<?php echo $tabGapsUrl->out(false); ?>">
        Brechas de Carga
        <span style="background:#dc3545;color:#fff;border-radius:10px;padding:1px 7px;font-size:11px;margin-left:6px">
            <?php echo count($gapResults); ?>
        </span>
    </a>
    <a class="adg-tab <?php echo ($active_tab === 'failed' ? 'active' : ''); ?>"
       href="<?php echo $tabFailedUrl->out(false); ?>">
        Reprobados con Modulo Disponible
        <span style="background:#6f42c1;color:#fff;border-radius:10px;padding:1px 7px;font-size:11px;margin-left:6px">
            <?php echo $statsFailedStudents; ?>
        </span>
    </a>
</div>

<!-- ── Shared filters ─────────────────────────────────────────────────────── -->
<form method="get" class="adg-filters">
    <input type="hidden" name="tab" value="<?php echo adg_h($active_tab); ?>">
    <div>
        <label>Buscar estudiante</label>
        <input type="text" name="search" value="<?php echo adg_h($filter_search); ?>"
               placeholder="Nombre o email" style="width:220px">
    </div>
    <div>
        <label>Plan de estudios</label>
        <select name="planid">
            <option value="0">- Todos -</option>
            <?php foreach ($allPlans as $pl): ?>
            <option value="<?php echo (int)$pl->id; ?>"
                    <?php echo ((int)$pl->id === $filter_planid ? 'selected' : ''); ?>>
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
            <option value="<?php echo adg_h($sh); ?>"
                    <?php echo ($sh === $filter_shift ? 'selected' : ''); ?>>
                <?php echo adg_h($sh); ?>
            </option>
            <?php endforeach; ?>
        </select>
        <?php else: ?>
        <input type="text" name="shift" value="<?php echo adg_h($filter_shift); ?>"
               placeholder="Matutina, Vespertina..." style="width:160px">
        <?php endif; ?>
    </div>
    <?php if ($active_tab !== 'failed'): ?>
    <div>
        <label>Mostrar</label>
        <select name="min">
            <option value="both" <?php echo ($filter_min === 'both' ? 'selected' : ''); ?>>0 y 1 asignatura</option>
            <option value="zero" <?php echo ($filter_min === 'zero' ? 'selected' : ''); ?>>Solo 0 asignaturas</option>
            <option value="one"  <?php echo ($filter_min === 'one'  ? 'selected' : ''); ?>>Solo 1 asignatura</option>
        </select>
    </div>
    <?php endif; ?>
    <div>
        <button type="submit" class="btn btn-primary" style="font-size:13px">Aplicar</button>
        <a href="<?php echo (new moodle_url('/local/grupomakro_core/pages/academic_demand_gaps.php',
            array('tab' => $active_tab)))->out(false); ?>"
           class="btn btn-secondary" style="margin-left:6px;font-size:13px">Limpiar</a>
    </div>
</form>

<?php if ($active_tab !== 'failed'): ?>
<!-- ══════════════════════════════════════════════════════════════════════════
     TAB 1: BRECHAS DE CARGA
     ══════════════════════════════════════════════════════════════════════════ -->

<div class="adg-alert-info">
    Muestra estudiantes activos con 0 o 1 asignatura en estado <strong>Cursando</strong>
    que aun tienen asignaturas en estado <strong>Disponible</strong> o <strong>No disponible</strong>
    en su plan de estudios. Excluye <em>Práctica Profesional / Proyecto de Grado</em>.
    Estudiantes sin asignaturas pendientes (ciclo completo) no aparecen.
</div>

<!-- Stats -->
<div class="adg-grid-4">
    <div class="adg-stat">
        <div class="num" style="color:#dc3545"><?php echo count($gapResults); ?></div>
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

<div class="adg-legend">
    <div><span class="adg-legend-dot" style="background:#198754"></span>Modulo abierto — estudiante puede inscribirse</div>
    <div><span class="adg-legend-dot" style="background:#ffc107"></span>Sin modulo — se recomienda apertura (max. 3)</div>
</div>

<?php if (empty($gapResults)): ?>
<div style="background:#f0faf4;border-left:4px solid #198754;border-radius:4px;padding:14px 18px;margin:14px 0">
    <strong>No se encontraron estudiantes con brechas</strong> con los filtros actuales.
</div>
<?php else: ?>

<div class="sec-title">
    Resultados (<?php echo count($gapResults); ?> estudiante<?php echo count($gapResults) !== 1 ? 's' : ''; ?>)
    <?php if (count($gapResults) >= 600): ?>
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
    <th>Cursando</th>
    <th>Pendientes</th>
    <th style="min-width:380px">Sugerencias</th>
</tr>
</thead>
<tbody>
<?php foreach ($gapResults as $idx => $row):
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
            <span class="muted">—</span>
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
                $cls    = $sug['classes'][0];
                $cdate  = date('d/m/Y', (int)$cls->initdate);
                $cedate = date('d/m/Y', (int)$cls->enddate);
                $cshift = isset($cls->shift) ? trim((string)$cls->shift) : '';
                $ctype  = ((int)$cls->type === 1) ? 'Virtual' : 'Presencial';
                $total  = count($sug['classes']);
                $semLbl = $sug['sem_num'] > 0 ? ' <span style="font-weight:400;color:#6c757d">Sem. ' . (int)$sug['sem_num'] . '</span>' : '';
            ?>
            <li>
                <div class="sug-open">
                    <div class="cn">&#128217; <?php echo adg_h($sug['fullname']); ?><?php echo $semLbl; ?></div>
                    <div class="cm">
                        <strong><?php echo adg_h($cls->name); ?></strong>
                        &middot; <?php echo adg_h($ctype); ?>
                        <?php if ($cshift !== ''): ?>&middot; <?php echo adg_h($cshift); ?><?php endif; ?>
                        &middot; <?php echo adg_h($cdate); ?> &ndash; <?php echo adg_h($cedate); ?>
                        <?php if ($total > 1): ?>&middot; <em>(+<?php echo $total - 1; ?> mas)</em><?php endif; ?>
                    </div>
                </div>
            </li>
            <?php endforeach; ?>
            <?php foreach ($sugNew as $cid => $sug):
                $semLbl = $sug['sem_num'] > 0 ? ' Sem. ' . (int)$sug['sem_num'] : '';
            ?>
            <li>
                <div class="sug-new">
                    <div class="cn">&#128217; <?php echo adg_h($sug['fullname']); ?>
                        <span style="font-weight:400;color:#856404"><?php echo adg_h($semLbl); ?></span>
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
foreach ($gapResults as $row) {
    $pname = (string)$row['stu']->planname;
    if (!isset($byPlan[$pname])) {
        $byPlan[$pname] = array('zero' => 0, 'one' => 0, 'with_open' => 0, 'need_new' => 0);
    }
    if ($row['inprogress'] === 0) { $byPlan[$pname]['zero']++; } else { $byPlan[$pname]['one']++; }
    if (!empty($row['suggestOpen'])) { $byPlan[$pname]['with_open']++; }
    if (!empty($row['suggestNew']))  { $byPlan[$pname]['need_new']++;  }
}
?>
<div class="sec-title" style="margin-top:30px">Resumen por Plan de Estudios</div>
<table class="adg-table" style="max-width:900px">
<thead>
<tr>
    <th>Plan</th><th>Sin asignaturas (0)</th><th>Con 1 asignatura</th>
    <th>Con modulo abierto</th><th>Necesitan apertura</th>
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

<?php else: ?>
<!-- ══════════════════════════════════════════════════════════════════════════
     TAB 2: REPROBADOS
     ══════════════════════════════════════════════════════════════════════════ -->

<div class="adg-alert-info">
    Estudiantes activos con asignaturas en estado <strong>Reprobado</strong> que tienen un
    <strong>modulo abierto disponible</strong> para el proximo bloque. El estudiante debe
    completar el <strong>proceso de pago</strong> para inscribirse en la asignatura reprobada.
    Excluye <em>Práctica Profesional / Proyecto de Grado</em>.
</div>

<!-- Stats -->
<div class="adg-grid-4">
    <div class="adg-stat">
        <div class="num" style="color:#6f42c1"><?php echo $statsFailedStudents; ?></div>
        <div class="lbl">Estudiantes con reprobadas</div>
    </div>
    <div class="adg-stat">
        <div class="num" style="color:#198754"><?php echo $statsFailedWithOpen; ?></div>
        <div class="lbl">Con modulo abierto disponible</div>
    </div>
    <div class="adg-stat">
        <div class="num" style="color:#6c757d"><?php echo $statsFailedNoOpen; ?></div>
        <div class="lbl">Sin modulo abierto aun</div>
    </div>
    <div class="adg-stat">
        <div class="num" style="color:#fd7e14"><?php echo $statsFailedStudents > 0 ? count($failedResults) : 0; ?></div>
        <div class="lbl">Total registros mostrados</div>
    </div>
</div>

<div class="adg-legend">
    <div><span class="adg-legend-dot" style="background:#e8d5f5;border:1px solid #6f42c1"></span>Asignatura reprobada con modulo abierto — puede inscribirse previo pago</div>
    <div><span class="adg-legend-dot" style="background:#f0f0f0;border:1px solid #ccc"></span>Reprobada sin modulo abierto aun</div>
</div>

<?php if (empty($failedResults)): ?>
<div style="background:#f0faf4;border-left:4px solid #198754;border-radius:4px;padding:14px 18px;margin:14px 0">
    <strong>No se encontraron estudiantes con asignaturas reprobadas</strong> con los filtros actuales.
</div>
<?php else: ?>

<div class="sec-title">
    Informe de Reprobados (<?php echo count($failedResults); ?> estudiante<?php echo count($failedResults) !== 1 ? 's' : ''; ?>)
    <?php if (count($failedResults) >= 1000): ?>
    <span class="muted"> — Mostrando los primeros 1000. Usa los filtros para acotar.</span>
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
    <th style="min-width:380px">Asignaturas reprobadas con modulo abierto</th>
    <th style="min-width:200px">Reprobadas sin modulo abierto</th>
</tr>
</thead>
<tbody>
<?php foreach ($failedResults as $idx => $row):
    $stu      = $row['stu'];
    $swOpen   = $row['subjectsWithOpen'];
    $swNone   = $row['subjectsNoOpen'];
    $fullname = trim($stu->firstname . ' ' . $stu->lastname);
    $shift    = isset($stu->shift) ? trim((string)$stu->shift) : '';
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
        <?php if (empty($swOpen)): ?>
            <span class="muted">—</span>
        <?php else: ?>
        <ul class="sug-list">
            <?php foreach ($swOpen as $cid => $sug):
                $cls    = $sug['classes'][0];
                $cdate  = date('d/m/Y', (int)$cls->initdate);
                $cedate = date('d/m/Y', (int)$cls->enddate);
                $cshift = isset($cls->shift) ? trim((string)$cls->shift) : '';
                $ctype  = ((int)$cls->type === 1) ? 'Virtual' : 'Presencial';
                $total  = count($sug['classes']);
                $semLbl = $sug['sem_num'] > 0 ? ' Sem. ' . (int)$sug['sem_num'] : '';
            ?>
            <li>
                <div class="fail-open">
                    <div class="cn">
                        &#128683; <?php echo adg_h($sug['fullname']); ?>
                        <?php if ($semLbl): ?>
                        <span style="font-weight:400;color:#6c757d"><?php echo adg_h($semLbl); ?></span>
                        <?php endif; ?>
                        <span style="background:#198754;color:#fff;border-radius:8px;padding:1px 6px;font-size:10px;margin-left:4px">Pago requerido</span>
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
        </ul>
        <?php endif; ?>
    </td>
    <td>
        <?php if (empty($swNone)): ?>
            <span class="muted">—</span>
        <?php else: ?>
        <ul class="sug-list">
            <?php foreach ($swNone as $cid => $sug):
                $semLbl = $sug['sem_num'] > 0 ? ' Sem. ' . (int)$sug['sem_num'] : '';
            ?>
            <li>
                <div class="fail-none">
                    &#128683; <?php echo adg_h($sug['fullname']); ?>
                    <?php if ($semLbl): ?><span class="muted"><?php echo adg_h($semLbl); ?></span><?php endif; ?>
                    <br><span style="font-size:10px;color:#999">Sin modulo abierto</span>
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

<!-- Summary by plan for failed tab -->
<?php
$byPlanFailed = array();
foreach ($failedResults as $row) {
    $pname = (string)$row['stu']->planname;
    if (!isset($byPlanFailed[$pname])) {
        $byPlanFailed[$pname] = array('students' => 0, 'with_open' => 0, 'no_open' => 0);
    }
    $byPlanFailed[$pname]['students']++;
    if (!empty($row['subjectsWithOpen'])) { $byPlanFailed[$pname]['with_open']++; }
    if (!empty($row['subjectsNoOpen']))   { $byPlanFailed[$pname]['no_open']++;   }
}
?>
<div class="sec-title" style="margin-top:30px">Resumen por Plan de Estudios</div>
<table class="adg-table" style="max-width:700px">
<thead>
<tr>
    <th>Plan</th>
    <th>Estudiantes con reprobadas</th>
    <th>Con modulo abierto</th>
    <th>Sin modulo abierto</th>
</tr>
</thead>
<tbody>
<?php foreach ($byPlanFailed as $planName => $counts): ?>
<tr>
    <td><strong><?php echo adg_h($planName); ?></strong></td>
    <td><?php echo (int)$counts['students']; ?></td>
    <td style="color:#198754;font-weight:700"><?php echo (int)$counts['with_open']; ?></td>
    <td style="color:#6c757d;font-weight:700"><?php echo (int)$counts['no_open']; ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<?php endif; ?>
<?php endif; ?>

</div>
<?php echo $OUTPUT->footer(); ?>
