<?php
/**
 * Vista Semanal de Horarios Activos
 *
 * Muestra en una cuadrícula semanal (Lunes–Sábado) todas las clases activas
 * (approved=1, closed=0) agrupadas por Carrera → Nivel Académico → Jornada.
 *
 * @package local_grupomakro_core
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/schedule_weekly_view.php'));
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Vista Semanal de Horarios');
$PAGE->set_heading('Vista Semanal de Horarios');

// ── Filters ───────────────────────────────────────────────────────────────────
$filter_planid    = optional_param('planid',  0,  PARAM_INT);
$filter_periodid  = optional_param('periodid', 0, PARAM_INT);
$filter_shift     = optional_param('shift',   '', PARAM_TEXT);
$filter_type      = optional_param('type',    -1, PARAM_INT); // -1 = all, 1 = virtual, 0 = presencial

// ── Constants ─────────────────────────────────────────────────────────────────
$DAYS = array('Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes', 'Sabado');
$DAY_LABELS = array('Lunes' => 'Lunes', 'Martes' => 'Martes', 'Miercoles' => 'Miérc.', 'Jueves' => 'Jueves', 'Viernes' => 'Viernes', 'Sabado' => 'Sábado');

// ── Helpers ───────────────────────────────────────────────────────────────────
function swv_h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function swv_fmt_time($t) {
    // "HH:MM" or "HH:MM:SS" → "HH:MM"
    $parts = explode(':', (string)$t);
    if (count($parts) >= 2) {
        return str_pad((int)$parts[0], 2, '0', STR_PAD_LEFT) . ':' . str_pad((int)$parts[1], 2, '0', STR_PAD_LEFT);
    }
    return (string)$t;
}

function swv_color_for($str) {
    // Deterministic soft color based on string hash
    $colors = array(
        '#1976D2','#388E3C','#7B1FA2','#F57C00','#C62828',
        '#00838F','#5D4037','#AD1457','#558B2F','#1565C0',
        '#6A1B9A','#2E7D32','#E65100','#0277BD','#4527A0',
    );
    $idx = abs(crc32($str)) % count($colors);
    return $colors[$idx];
}

// ── Load dropdown data ────────────────────────────────────────────────────────
$allPlans = array();
try { $allPlans = $DB->get_records('local_learning_plans', null, 'name ASC', 'id, name'); } catch (Exception $e) {}

$allPeriods = array();
try { $allPeriods = $DB->get_records('local_learning_periods', null, 'name ASC', 'id, name'); } catch (Exception $e) {}

$allShifts = array();
try {
    $shiftRows = $DB->get_fieldset_sql(
        "SELECT DISTINCT shift FROM {gmk_class}
          WHERE shift IS NOT NULL AND shift <> '' AND approved = 1 AND closed = 0
          ORDER BY shift ASC"
    );
    $allShifts = array_values(array_filter((array)$shiftRows));
} catch (Exception $e) {}

// ── Build WHERE ───────────────────────────────────────────────────────────────
$whereExtra  = '';
$whereParams = array();

if ($filter_planid > 0) {
    $whereExtra .= ' AND gc.learningplanid = :planid';
    $whereParams['planid'] = $filter_planid;
}
if ($filter_periodid > 0) {
    $whereExtra .= ' AND EXISTS (
        SELECT 1 FROM {local_learning_courses} llcf
         JOIN {local_learning_periods} pf ON pf.id = llcf.periodid
        WHERE llcf.courseid = gc.corecourseid
          AND pf.learningplanid = gc.learningplanid
          AND pf.id = :periodid)';
    $whereParams['periodid'] = $filter_periodid;
}
if ($filter_shift !== '') {
    $whereExtra .= ' AND gc.shift = :shift';
    $whereParams['shift'] = $filter_shift;
}
if ($filter_type >= 0) {
    $whereExtra .= ' AND gc.type = :type';
    $whereParams['type'] = $filter_type;
}

// ── Load active classes with their schedules ──────────────────────────────────
$scheduleRows = array();
try {
    $scheduleRows = $DB->get_records_sql(
        "SELECT s.id AS schedid,
                gc.id AS classid,
                gc.name AS classname,
                gc.shift,
                gc.type,
                gc.learningplanid,
                gc.periodid,
                gc.initdate,
                gc.enddate,
                gc.instructorid,
                gc.corecourseid,
                lp.name AS planname,
                (SELECT p.name FROM {local_learning_courses} llc2
                  JOIN {local_learning_periods} p ON p.id = llc2.periodid
                 WHERE llc2.courseid = gc.corecourseid
                   AND p.learningplanid = gc.learningplanid
                 LIMIT 1) AS periodname,
                (SELECT p.id FROM {local_learning_courses} llc2
                  JOIN {local_learning_periods} p ON p.id = llc2.periodid
                 WHERE llc2.courseid = gc.corecourseid
                   AND p.learningplanid = gc.learningplanid
                 LIMIT 1) AS nivel_periodid,
                c.fullname AS coursefullname,
                u.firstname AS instr_first,
                u.lastname  AS instr_last,
                s.day,
                s.start_time,
                s.end_time,
                COALESCE(cr.name, '') AS classroomname,
                gc.groupid,
                (SELECT COUNT(*) FROM {groups_members} WHERE groupid = gc.groupid AND gc.groupid > 0 AND userid <> gc.instructorid) AS student_count
           FROM {gmk_class} gc
           JOIN {local_learning_plans} lp ON lp.id = gc.learningplanid
           JOIN {course} c ON c.id = gc.corecourseid
           JOIN {user} u ON u.id = gc.instructorid
           JOIN {gmk_class_schedules} s ON s.classid = gc.id
           LEFT JOIN {gmk_classrooms} cr ON cr.id = s.classroomid
          WHERE gc.approved = 1
            AND gc.closed   = 0
            AND gc.enddate  > :now
            {$whereExtra}
          ORDER BY lp.name ASC, periodname ASC, gc.shift ASC, s.start_time ASC",
        array_merge(array('now' => time() - 86400), $whereParams)
    );
} catch (Exception $e) {
    $scheduleRows = array();
}

// ── Precompute context totals: unique students per (plan + nivel + shift) ─────
// Strategy: one extra query for groups_members of all visible classes.
// Context key = planname . '||' . periodname . '||' . shift
$contextTotals  = array(); // groupKey → unique student count
$classGroupKeys = array(); // classid  → groupKey
$groupidMap     = array(); // groupid  → classid (first seen, for lookup)

foreach ($scheduleRows as $r) {
    $gk = (string)$r->planname . '||' . (string)$r->periodname . '||' . (trim((string)$r->shift) ?: '—');
    $classGroupKeys[(int)$r->classid] = $gk;
    $gid = (int)$r->groupid;
    if ($gid > 0 && !isset($groupidMap[$gid])) {
        $groupidMap[$gid] = (int)$r->classid;
    }
}

$allGroupids = array_keys($groupidMap);
if (!empty($allGroupids)) {
    try {
        list($insql, $inparams) = $DB->get_in_or_equal($allGroupids);
        $memberRows = $DB->get_records_sql(
            "SELECT gm.id, gm.groupid, gm.userid FROM {groups_members} gm WHERE gm.groupid $insql",
            $inparams
        );
        // Accumulate unique userids per groupKey
        $usersByKey = array();
        foreach ($memberRows as $mr) {
            $classid = $groupidMap[(int)$mr->groupid] ?? null;
            if ($classid === null) { continue; }
            $gk = $classGroupKeys[$classid] ?? null;
            if ($gk === null) { continue; }
            $usersByKey[$gk][(string)$mr->userid] = true;
        }
        foreach ($usersByKey as $gk => $users) {
            $contextTotals[$gk] = count($users);
        }
    } catch (Exception $e) {
        // leave $contextTotals empty; display will degrade gracefully
    }
}

// ── Group data: groups[planname][periodname][shift][day][] = entry ────────────
// Also: classCache[classid] = first entry (for class-level info)
$groups      = array();
$classDayMap = array(); // [planname][periodname][shift][classid][day] = entry (de-duped)

foreach ($scheduleRows as $r) {
    $plan   = (string)$r->planname;
    $period = (string)$r->periodname;
    $shift  = trim((string)$r->shift) ?: '—';
    $day    = (string)$r->day;

    // Normalize day key (handle accent issues: Miércoles vs Miercoles)
    $dayClean = mb_strtolower(str_replace(
        array('é','á','ó','ú','í','ñ','É','Á','Ó','Ú','Í','Ñ'),
        array('e','a','o','u','i','n','E','A','O','U','I','N'),
        $day
    ), 'UTF-8');
    $dayKey = ucfirst($dayClean); // e.g. "Miercoles"

    if (!in_array($dayKey, $DAYS)) { continue; } // skip Domingo or unknowns

    if (!isset($groups[$plan])) { $groups[$plan] = array(); }
    if (!isset($groups[$plan][$period])) { $groups[$plan][$period] = array(); }
    if (!isset($groups[$plan][$period][$shift])) { $groups[$plan][$period][$shift] = array(); }

    // De-duplicate per class per day (one card per class-day)
    if (!isset($classDayMap[$plan][$period][$shift][(int)$r->classid][$dayKey])) {
        $classDayMap[$plan][$period][$shift][(int)$r->classid][$dayKey] = true;
        if (!isset($groups[$plan][$period][$shift][$dayKey])) {
            $groups[$plan][$period][$shift][$dayKey] = array();
        }
        $groups[$plan][$period][$shift][$dayKey][] = $r;
    }
}

// ── Count totals ──────────────────────────────────────────────────────────────
$totalGroups  = 0;
$totalClasses = 0;
$seenClassIds = array();
foreach ($scheduleRows as $r) {
    if (!isset($seenClassIds[(int)$r->classid])) {
        $seenClassIds[(int)$r->classid] = true;
        $totalClasses++;
    }
}
$totalGroups = 0;
foreach ($groups as $plan => $periods) {
    foreach ($periods as $period => $shifts) {
        $totalGroups += count($shifts);
    }
}

// ── OUTPUT ────────────────────────────────────────────────────────────────────
echo $OUTPUT->header();
?>
<style>
/* ── Layout ─────────────────────────────────────────────────────────────────── */
.swv-wrap{max-width:1700px;margin:0 auto;padding:16px;font-family:system-ui,sans-serif}
.swv-filters{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;background:#fff;border:1px solid #dee2e6;border-radius:8px;padding:14px;margin:14px 0}
.swv-filters label{font-size:11px;font-weight:700;color:#495057;display:block;margin-bottom:3px;text-transform:uppercase;letter-spacing:.4px}
.swv-filters select{padding:6px 10px;border:1px solid #ced4da;border-radius:4px;font-size:13px;background:#fff;min-width:160px}
/* ── Stats bar ───────────────────────────────────────────────────────────────── */
.swv-stats{display:flex;gap:12px;margin:10px 0}
.swv-stat{background:#fff;border:1px solid #dee2e6;border-radius:8px;padding:12px 20px;text-align:center;min-width:120px}
.swv-stat .n{font-size:1.8rem;font-weight:800;line-height:1;color:#1976D2}
.swv-stat .l{font-size:11px;color:#6c757d;margin-top:2px}
/* ── Group header ────────────────────────────────────────────────────────────── */
.swv-group{margin:24px 0 0;border-radius:10px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.10)}
.swv-group-header{padding:10px 16px;color:#fff;font-weight:700;font-size:13px;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.swv-group-header .plan{font-size:15px}
.swv-group-header .badges{display:flex;gap:6px;flex-wrap:wrap}
.swv-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 10px;border-radius:12px;font-size:11px;font-weight:700;background:rgba(255,255,255,.22);white-space:nowrap}
/* ── Week grid ───────────────────────────────────────────────────────────────── */
.swv-week{display:grid;grid-template-columns:repeat(6,1fr);background:#f0f4f8}
.swv-day-header{background:#37474f;color:#fff;text-align:center;padding:8px 4px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;border-right:1px solid #546e7a}
.swv-day-header:last-child{border-right:none}
.swv-day-col{padding:6px;border-right:1px solid #cfd8dc;min-height:80px;display:flex;flex-direction:column;gap:5px;background:#fafbfc}
.swv-day-col:last-child{border-right:none}
.swv-day-col.has-cards{background:#fff}
/* ── Class card ──────────────────────────────────────────────────────────────── */
.swv-card{border-radius:6px;padding:7px 9px;color:#fff;font-size:11px;line-height:1.35;box-shadow:0 1px 3px rgba(0,0,0,.18);position:relative;overflow:hidden}
.swv-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:rgba(255,255,255,.35)}
.swv-card .time{font-size:12px;font-weight:800;letter-spacing:.3px}
.swv-card .subject{font-weight:700;font-size:11px;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.swv-card .instructor{font-size:10px;opacity:.88;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.swv-card .room{font-size:10px;opacity:.78;margin-top:1px}
.swv-card .students{font-size:10px;opacity:.88;margin-top:2px}
.swv-card .type-chip{display:inline-block;background:rgba(255,255,255,.25);border-radius:8px;padding:0 5px;font-size:9px;font-weight:700;margin-top:3px;text-transform:uppercase;letter-spacing:.5px}
/* ── Empty day ───────────────────────────────────────────────────────────────── */
.swv-empty-day{color:#cdd;font-size:20px;text-align:center;padding:10px 0;flex:1;display:flex;align-items:center;justify-content:center}
/* ── Plan section title ─────────────────────────────────────────────────────── */
.swv-plan-title{font-size:17px;font-weight:800;color:#1a237e;margin:30px 0 4px;padding-bottom:6px;border-bottom:3px solid #1976D2;display:flex;align-items:center;gap:8px}
/* ── Period sub-title ──────────────────────────────────────────────────────── */
.swv-period-title{font-size:13px;font-weight:700;color:#37474f;margin:16px 0 4px;display:flex;align-items:center;gap:6px}
.swv-period-dot{width:10px;height:10px;border-radius:50%;background:#1976D2;flex-shrink:0}
/* ── No data ─────────────────────────────────────────────────────────────────── */
.swv-nodata{background:#f0faf4;border-left:4px solid #4caf50;border-radius:4px;padding:16px 20px;margin:20px 0;color:#2e7d32;font-weight:600}
/* ── Color legend ────────────────────────────────────────────────────────────── */
.swv-legend{display:flex;flex-wrap:wrap;gap:8px;padding:10px 12px;background:#f8f9fa;border-top:1px solid #dee2e6;border-radius:0 0 10px 10px}
.swv-legend-item{display:flex;align-items:center;gap:6px;font-size:11px;color:#37474f;background:#fff;border:1px solid #e0e0e0;border-radius:20px;padding:3px 10px 3px 5px;white-space:nowrap;max-width:260px}
.swv-legend-dot{width:12px;height:12px;border-radius:3px;flex-shrink:0}
.swv-legend-text{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
/* ── PDF button ──────────────────────────────────────────────────────────────── */
.swv-pdf-btn{display:inline-flex;align-items:center;gap:7px;background:#c62828;color:#fff;border:none;border-radius:6px;padding:8px 16px;font-size:13px;font-weight:700;cursor:pointer;box-shadow:0 2px 6px rgba(198,40,40,.28);transition:opacity .15s;margin-top:8px}
.swv-pdf-btn:hover{opacity:.88}
.swv-pdf-btn:disabled{opacity:.5;cursor:not-allowed}
</style>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

<div class="swv-wrap">
<h2 style="margin-bottom:4px;color:#0d1b4b">📅 Vista Semanal de Horarios Activos</h2>
<p style="color:#6c757d;font-size:13px;margin-bottom:12px">
    Clases aprobadas y activas agrupadas por Carrera, Nivel Académico y Jornada.
</p>

<!-- Filters -->
<form method="get" class="swv-filters">
    <div>
        <label>Carrera</label>
        <select name="planid">
            <option value="0">— Todas —</option>
            <?php foreach ($allPlans as $pl): ?>
            <option value="<?php echo (int)$pl->id; ?>" <?php echo ((int)$pl->id === $filter_planid ? 'selected' : ''); ?>>
                <?php echo swv_h($pl->name); ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label>Nivel Académico</label>
        <select name="periodid">
            <option value="0">— Todos —</option>
            <?php foreach ($allPeriods as $ap): ?>
            <option value="<?php echo (int)$ap->id; ?>" <?php echo ((int)$ap->id === $filter_periodid ? 'selected' : ''); ?>>
                <?php echo swv_h($ap->name); ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label>Jornada</label>
        <select name="shift">
            <option value="">— Todas —</option>
            <?php foreach ($allShifts as $sh): ?>
            <option value="<?php echo swv_h($sh); ?>" <?php echo ($sh === $filter_shift ? 'selected' : ''); ?>>
                <?php echo swv_h($sh); ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label>Modalidad</label>
        <select name="type">
            <option value="-1" <?php echo ($filter_type === -1 ? 'selected' : ''); ?>>— Todas —</option>
            <option value="0"  <?php echo ($filter_type === 0  ? 'selected' : ''); ?>>Presencial</option>
            <option value="1"  <?php echo ($filter_type === 1  ? 'selected' : ''); ?>>Virtual</option>
            <option value="2"  <?php echo ($filter_type === 2  ? 'selected' : ''); ?>>Mixta</option>
        </select>
    </div>
    <div>
        <button type="submit" class="btn btn-primary" style="font-size:13px">Aplicar</button>
        <a href="<?php echo (new moodle_url('/local/grupomakro_core/pages/schedule_weekly_view.php'))->out(false); ?>"
           class="btn btn-secondary" style="margin-left:6px;font-size:13px">Limpiar</a>
    </div>
</form>

<!-- Stats -->
<div class="swv-stats">
    <div class="swv-stat">
        <div class="n"><?php echo $totalClasses; ?></div>
        <div class="l">Clases activas</div>
    </div>
    <div class="swv-stat">
        <div class="n"><?php echo count($groups); ?></div>
        <div class="l">Carreras</div>
    </div>
    <div class="swv-stat">
        <div class="n"><?php echo $totalGroups; ?></div>
        <div class="l">Grupos (Carrera + Período + Jornada)</div>
    </div>
</div>

<?php if (empty($groups)): ?>
<div class="swv-nodata">No se encontraron horarios activos con los filtros seleccionados.</div>
<?php else: ?>
<div>
    <button class="swv-pdf-btn" id="swv-pdf-btn" onclick="swvDownloadPdf()">📄 Descargar PDF</button>
</div>

<?php foreach ($groups as $planName => $periods): ?>
<div class="swv-plan-title">
    <span>🎓</span> <?php echo swv_h($planName); ?>
</div>

<?php foreach ($periods as $periodName => $shifts): ?>
<div class="swv-period-title">
    <span class="swv-period-dot"></span>
    Nivel Académico: <strong><?php echo swv_h($periodName); ?></strong>
</div>

<?php foreach ($shifts as $shiftName => $dayData): ?>
<?php
    // Count unique classes in this group
    $groupClassIds = array();
    foreach ($dayData as $dayCards) {
        foreach ($dayCards as $card) {
            $groupClassIds[(int)$card->classid] = true;
        }
    }
    $groupCount = count($groupClassIds);
    $headerColor   = swv_color_for($planName . $periodName . $shiftName);
    $ctxGroupKey   = $planName . '||' . $periodName . '||' . $shiftName;
    $ctxTotal      = isset($contextTotals[$ctxGroupKey]) ? (int)$contextTotals[$ctxGroupKey] : 0;
?>
<div class="swv-group" style="margin-bottom:16px">
    <!-- Group header -->
    <div class="swv-group-header" style="background:<?php echo swv_h($headerColor); ?>">
        <span class="plan">🗓 <?php echo swv_h($shiftName); ?></span>
        <div class="badges">
            <span class="swv-badge">📚 <?php echo swv_h($planName); ?></span>
            <span class="swv-badge">📋 <?php echo swv_h($periodName); ?></span>
            <span class="swv-badge">📦 <?php echo $groupCount; ?> clase<?php echo $groupCount !== 1 ? 's' : ''; ?></span>
        </div>
    </div>

    <!-- Day headers -->
    <div class="swv-week">
        <?php foreach ($DAYS as $day): ?>
        <div class="swv-day-header"><?php echo swv_h($DAY_LABELS[$day]); ?></div>
        <?php endforeach; ?>
    </div>

    <!-- Day columns -->
    <?php $legendCourses = array(); ?>
    <div class="swv-week">
        <?php foreach ($DAYS as $day): ?>
        <?php
            $cards = isset($dayData[$day]) ? $dayData[$day] : array();
            usort($cards, function($a, $b) {
                return strcmp((string)$a->start_time, (string)$b->start_time);
            });
        ?>
        <div class="swv-day-col <?php echo !empty($cards) ? 'has-cards' : ''; ?>">
            <?php if (empty($cards)): ?>
            <div class="swv-empty-day">·</div>
            <?php else: ?>
            <?php foreach ($cards as $card):
                $cardColor = swv_color_for((string)$card->classid);
                $startFmt  = swv_fmt_time($card->start_time);
                $endFmt    = swv_fmt_time($card->end_time);
                $instrName = trim($card->instr_first . ' ' . $card->instr_last);
                $typeLabel = (int)$card->type === 1 ? 'Virtual' : ((int)$card->type === 2 ? 'Mixta' : 'Presencial');
                $roomStr   = $card->classroomname !== '' ? $card->classroomname : '';
                $legendCourses[(int)$card->classid] = array('name' => $card->classname . ' — ' . $card->coursefullname, 'color' => $cardColor);
            ?>
            <div class="swv-card" style="background:<?php echo swv_h($cardColor); ?>" title="<?php echo swv_h($card->coursefullname . ' — ' . $instrName); ?>">
                <div class="time">⏰ <?php echo swv_h($startFmt); ?> – <?php echo swv_h($endFmt); ?></div>
                <div class="subject"><?php echo swv_h($card->coursefullname); ?></div>
                <div class="instructor">👤 <?php echo swv_h($instrName); ?></div>
                <?php if ($roomStr !== ''): ?>
                <div class="room">📍 <?php echo swv_h($roomStr); ?></div>
                <?php endif; ?>
                <div class="students">👥 <?php echo (int)$card->student_count; ?></div>
                <div><span class="type-chip"><?php echo swv_h($typeLabel); ?></span></div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php if (!empty($legendCourses)): ?>
    <div class="swv-legend">
        <?php
        uasort($legendCourses, function($a, $b) { return strcmp($a['name'], $b['name']); });
        foreach ($legendCourses as $lc):
        ?>
        <div class="swv-legend-item">
            <span class="swv-legend-dot" style="background:<?php echo swv_h($lc['color']); ?>"></span>
            <span class="swv-legend-text" title="<?php echo swv_h($lc['name']); ?>"><?php echo swv_h($lc['name']); ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; // shifts ?>
<?php endforeach; // periods ?>
<?php endforeach; // plans ?>

<?php endif; ?>
</div>

<script>
async function swvDownloadPdf() {
    const btn = document.getElementById('swv-pdf-btn');
    const groups = Array.from(document.querySelectorAll('.swv-group'));
    if (!groups.length) { return; }

    btn.disabled = true;
    btn.innerHTML = '⏳ Generando PDF… (0/' + groups.length + ')';

    try {
        const { jsPDF } = window.jspdf;
        const pdf    = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });
        const pageW  = pdf.internal.pageSize.getWidth();
        const pageH  = pdf.internal.pageSize.getHeight();
        const margin = 8;
        const availW = pageW - 2 * margin;
        const availH = pageH - 2 * margin;
        const PX2MM  = 25.4 / 96;

        for (let i = 0; i < groups.length; i++) {
            btn.innerHTML = '⏳ Generando PDF… (' + (i + 1) + '/' + groups.length + ')';

            // Walk backwards from this .swv-group to find plan + period titles
            let planText = '', periodText = '';
            let sibling = groups[i].previousElementSibling;
            while (sibling) {
                if (!periodText && sibling.classList.contains('swv-period-title')) {
                    periodText = sibling.innerText.trim();
                }
                if (sibling.classList.contains('swv-plan-title')) {
                    planText = sibling.innerText.trim();
                    break;
                }
                sibling = sibling.previousElementSibling;
            }

            // Build an off-screen wrapper: plan header + group clone
            const wrap = document.createElement('div');
            wrap.style.cssText = 'position:absolute;left:-9999px;top:0;width:' +
                groups[i].offsetWidth + 'px;background:#fff;font-family:system-ui,sans-serif';

            if (planText) {
                const planHdr = document.createElement('div');
                planHdr.style.cssText =
                    'font-size:16px;font-weight:800;color:#1a237e;padding:10px 4px 6px;' +
                    'border-bottom:3px solid #1976D2;margin-bottom:8px;display:flex;align-items:center;gap:8px';
                planHdr.textContent = planText;
                wrap.appendChild(planHdr);
            }
            if (periodText) {
                const perHdr = document.createElement('div');
                perHdr.style.cssText =
                    'font-size:12px;font-weight:700;color:#37474f;padding:0 4px 8px;display:flex;align-items:center;gap:6px';
                perHdr.textContent = periodText;
                wrap.appendChild(perHdr);
            }

            wrap.appendChild(groups[i].cloneNode(true));
            document.body.appendChild(wrap);

            const canvas = await html2canvas(wrap, {
                scale           : 2,
                useCORS         : true,
                allowTaint      : true,
                backgroundColor : '#ffffff',
                logging         : false,
            });

            document.body.removeChild(wrap);

            const imgData  = canvas.toDataURL('image/jpeg', 0.93);
            const elemW_mm = (canvas.width  / 2) * PX2MM;
            const elemH_mm = (canvas.height / 2) * PX2MM;

            const scale  = Math.min(availW / elemW_mm, availH / elemH_mm);
            const finalW = elemW_mm * scale;
            const finalH = elemH_mm * scale;
            const x      = margin + (availW - finalW) / 2;
            const y      = margin + (availH - finalH) / 2;

            if (i > 0) { pdf.addPage(); }
            pdf.addImage(imgData, 'JPEG', x, y, finalW, finalH);
        }

        pdf.save('horarios_semanales.pdf');
    } catch (err) {
        alert('Error generando PDF: ' + err.message);
    }

    btn.disabled = false;
    btn.innerHTML = '📄 Descargar PDF';
}
</script>

<?php echo $OUTPUT->footer(); ?>
