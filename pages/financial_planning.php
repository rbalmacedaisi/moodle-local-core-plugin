<?php
/**
 * Análisis Financiero Docente
 *
 * Proyección de costos mensuales de docentes para un período académico.
 *
 * @package local_grupomakro_core
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/financial_planning.php'));
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Análisis Financiero Docente');
$PAGE->set_heading('Análisis Financiero Docente');

// ── Helpers ────────────────────────────────────────────────────────────────────
function fp_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function fp_normalize_day($day) {
    return ucfirst(mb_strtolower(str_replace(
        ['é','á','ó','ú','í','ñ','É','Á','Ó','Ú','Í','Ñ'],
        ['e','a','o','u','i','n','e','a','o','u','i','n'],
        trim((string)$day)
    ), 'UTF-8'));
}

function fp_day_to_dow($d) {
    $map = ['Lunes'=>1,'Martes'=>2,'Miercoles'=>3,'Jueves'=>4,'Viernes'=>5,'Sabado'=>6,'Domingo'=>7];
    return $map[$d] ?? -1;
}

function fp_duration_hours($s, $e) {
    $ps = array_pad(explode(':', (string)$s), 2, 0);
    $pe = array_pad(explode(':', (string)$e), 2, 0);
    $sm = (int)$ps[0] * 60 + (int)$ps[1];
    $em = (int)$pe[0] * 60 + (int)$pe[1];
    return max(0.0, ($em - $sm) / 60.0);
}

function fp_sessions_in_month($year, $month, $dow, $init, $end, $excl) {
    if ($dow < 1) return 0;
    $ms = mktime(0, 0, 0, $month, 1, $year);
    $me = mktime(23, 59, 59, $month, (int)date('t', $ms), $year);
    $from = max((int)$init, $ms);
    $to   = min((int)$end,  $me);
    if ($from > $to) return 0;
    $n = 0;
    for ($ts = $from; $ts <= $to; $ts += 86400) {
        if ((int)date('N', $ts) === $dow && !in_array(date('Y-m-d', $ts), $excl)) {
            $n++;
        }
    }
    return $n;
}

// ── Filters ────────────────────────────────────────────────────────────────────
$filter_periodid  = optional_param('periodid',      0, PARAM_INT);
$filter_planid    = optional_param('planid',         0, PARAM_INT);
$include_draft    = optional_param('include_draft',  0, PARAM_INT);

// ── Dropdown data ──────────────────────────────────────────────────────────────
$allPeriods = [];
try { $allPeriods = $DB->get_records('gmk_academic_periods', null, 'name DESC', 'id, name'); } catch (Exception $e) {}
$allPlans = [];
try { $allPlans = $DB->get_records('local_learning_plans', null, 'name ASC', 'id, name'); } catch (Exception $e) {}

// ── Data computation ───────────────────────────────────────────────────────────
$teacherHours = [];  // [iid][month_key] = float hours
$teacherMeta  = [];  // [iid] = {name, classes[]}
$allMonths    = [];
$periodLabel  = '';

if ($filter_periodid > 0) {
    try {
        $per = $DB->get_record('gmk_academic_periods', ['id' => $filter_periodid]);
        if ($per) { $periodLabel = $per->name; }
    } catch (Exception $e) {}

    if ($include_draft) {
        // ── MODO BORRADOR ─────────────────────────────────────────────────────
        // Los borradores se almacenan como JSON en gmk_academic_periods.draft_schedules
        $draftJson  = '';
        try {
            $draftJson = $DB->get_field('gmk_academic_periods', 'draft_schedules', ['id' => $filter_periodid]) ?: '';
        } catch (Exception $e) {}

        $draftItems = [];
        if ($draftJson) {
            $decoded = json_decode($draftJson, true);
            if (is_array($decoded)) { $draftItems = $decoded; }
        }

        // Rango de meses desde las fechas del período
        if (isset($per) && $per && !empty($per->startdate) && !empty($per->enddate)) {
            $cur = mktime(0, 0, 0, (int)date('n', $per->startdate), 1, (int)date('Y', $per->startdate));
            $lim = mktime(0, 0, 0, (int)date('n', $per->enddate),   1, (int)date('Y', $per->enddate));
            while ($cur <= $lim) {
                $allMonths[] = date('Y-m', $cur);
                $cur = strtotime('+1 month', $cur);
            }
        }

        $periodInit = (isset($per) && $per) ? (int)$per->startdate : 0;
        $periodEnd  = (isset($per) && $per) ? (int)$per->enddate   : 0;

        foreach ($draftItems as $item) {
            // Filtro por carrera (learningplanid)
            if ($filter_planid > 0 && (int)($item['learningplanid'] ?? 0) !== $filter_planid) {
                continue;
            }

            $iid  = (int)($item['instructorId'] ?? 0);
            $name = ($iid > 0)
                ? trim(($item['teacherName'] ?? '') ?: 'Docente #' . $iid)
                : 'Sin docente asignado';
            $courseName = (string)($item['subjectName'] ?? 'Curso sin nombre');

            if (!isset($teacherMeta[$iid])) {
                $teacherMeta[$iid] = [
                    'name'       => $name,
                    'no_teacher' => ($iid === 0),
                    'classes'    => [],
                ];
                foreach ($allMonths as $mk) { $teacherHours[$iid][$mk] = 0.0; }
            }

            // Clave única por ítem: usar corecourseid + subperiodo para distinguir secciones
            $classKey = ($item['corecourseid'] ?? 0) . '_' . ($item['subperiod'] ?? 0) . '_' . ($item['id'] ?? '');
            $teacherMeta[$iid]['classes'][$classKey] = $courseName;

            // Construir array de sesiones
            $sessions = [];
            if (!empty($item['sessions']) && is_array($item['sessions'])) {
                $sessions = $item['sessions'];
            } elseif (!empty($item['day'])) {
                // Si no hay sessions[], construir desde campos top-level
                $sessions = [[
                    'day'            => $item['day'],
                    'start'          => $item['start'] ?? '',
                    'end'            => $item['end']   ?? '',
                    'excluded_dates' => [],
                    'assignedDates'  => $item['assignedDates'] ?? null,
                ]];
            }

            foreach ($sessions as $sess) {
                $excl = is_array($sess['excluded_dates'] ?? null) ? array_values($sess['excluded_dates']) : [];

                if (!empty($sess['assignedDates']) && is_array($sess['assignedDates'])) {
                    // Fechas específicas asignadas: contar horas por fecha
                    $dur = fp_duration_hours((string)($sess['start'] ?? ''), (string)($sess['end'] ?? ''));
                    if ($dur > 0) {
                        foreach ($sess['assignedDates'] as $dateStr) {
                            $ts = is_string($dateStr) ? strtotime($dateStr) : 0;
                            if ($ts > 0) {
                                $mk = date('Y-m', $ts);
                                if (isset($teacherHours[$iid][$mk])) {
                                    $teacherHours[$iid][$mk] += $dur;
                                }
                            }
                        }
                    }
                } else {
                    // Cálculo regular por día de semana
                    $dow = fp_day_to_dow(fp_normalize_day((string)($sess['day'] ?? '')));
                    $dur = fp_duration_hours((string)($sess['start'] ?? ''), (string)($sess['end'] ?? ''));
                    if ($dow < 0 || $dur <= 0) { continue; }
                    foreach ($allMonths as $mk) {
                        [$y, $m] = explode('-', $mk);
                        $cnt = fp_sessions_in_month((int)$y, (int)$m, $dow, $periodInit, $periodEnd, $excl);
                        $teacherHours[$iid][$mk] += $cnt * $dur;
                    }
                }
            }
        }

    } else {
        // ── MODO ACTIVO (comportamiento original) ─────────────────────────────
        $wp = ['periodid' => $filter_periodid];
        $we = '';
        if ($filter_planid > 0) {
            $we = ' AND gc.learningplanid = :planid';
            $wp['planid'] = $filter_planid;
        }

        try {
            $rows = $DB->get_records_sql("
                SELECT s.id AS schedid,
                       gc.id AS classid, gc.name AS classname,
                       gc.instructorid, gc.initdate, gc.enddate, gc.approved,
                       u.firstname, u.lastname,
                       c.fullname AS coursefullname,
                       s.day, s.start_time, s.end_time,
                       COALESCE(s.excluded_dates, '') AS excluded_dates
                  FROM {gmk_class} gc
                  JOIN {course} c ON c.id = gc.corecourseid
                  JOIN {user}   u ON u.id = gc.instructorid
                  JOIN {gmk_class_schedules} s ON s.classid = gc.id
                 WHERE gc.periodid = :periodid
                   AND gc.closed = 0
                   $we
                 ORDER BY u.lastname ASC, u.firstname ASC, gc.name ASC",
                $wp
            );
        } catch (Exception $e) { $rows = []; }

        if (!empty($rows)) {
            $minTs = PHP_INT_MAX; $maxTs = 0;
            foreach ($rows as $r) {
                if ((int)$r->initdate > 0) { $minTs = min($minTs, (int)$r->initdate); }
                if ((int)$r->enddate  > 0) { $maxTs = max($maxTs, (int)$r->enddate); }
            }
            if ($maxTs > 0 && $minTs < PHP_INT_MAX) {
                $cur = mktime(0, 0, 0, (int)date('n', $minTs), 1, (int)date('Y', $minTs));
                $lim = mktime(0, 0, 0, (int)date('n', $maxTs), 1, (int)date('Y', $maxTs));
                while ($cur <= $lim) {
                    $allMonths[] = date('Y-m', $cur);
                    $cur = strtotime('+1 month', $cur);
                }
            }

            foreach ($rows as $r) {
                $iid = (int)$r->instructorid;
                if (!isset($teacherMeta[$iid])) {
                    $teacherMeta[$iid] = [
                        'name'       => trim($r->firstname . ' ' . $r->lastname),
                        'no_teacher' => false,
                        'classes'    => [],
                    ];
                    foreach ($allMonths as $mk) { $teacherHours[$iid][$mk] = 0.0; }
                }
                $teacherMeta[$iid]['classes'][(int)$r->classid] = $r->coursefullname;

                $excl = [];
                if ($r->excluded_dates) {
                    $dec = json_decode($r->excluded_dates, true);
                    if (is_array($dec)) { $excl = array_values($dec); }
                }
                $dow = fp_day_to_dow(fp_normalize_day($r->day));
                $dur = fp_duration_hours($r->start_time, $r->end_time);
                if ($dow < 0 || $dur <= 0) { continue; }
                foreach ($allMonths as $mk) {
                    [$y, $m] = explode('-', $mk);
                    $sess = fp_sessions_in_month((int)$y, (int)$m, $dow, (int)$r->initdate, (int)$r->enddate, $excl);
                    $teacherHours[$iid][$mk] += $sess * $dur;
                }
            }
        }
    }

    foreach ($teacherMeta as &$tm) {
        $tm['classes'] = array_unique(array_values($tm['classes']));
    }
    unset($tm);
}

echo $OUTPUT->header();
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js"></script>
<style>
/* ── Layout ─────────────────────────────────────────────────────────────────── */
.fp-wrap{max-width:1500px;margin:0 auto;padding:16px;font-family:system-ui,sans-serif}
.fp-filters{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;background:#fff;border:1px solid #dee2e6;border-radius:8px;padding:14px;margin:14px 0}
.fp-filters label{font-size:11px;font-weight:700;color:#495057;display:block;margin-bottom:3px;text-transform:uppercase;letter-spacing:.4px}
.fp-filters select,.fp-filters input{padding:6px 10px;border:1px solid #ced4da;border-radius:4px;font-size:13px;background:#fff;min-width:160px}
/* ── Section cards ──────────────────────────────────────────────────────────── */
.fp-section{background:#fff;border:1px solid #dee2e6;border-radius:10px;margin-bottom:20px;overflow:hidden}
.fp-section-header{background:#1a237e;color:#fff;padding:12px 16px;font-weight:700;font-size:14px;display:flex;align-items:center;gap:8px}
.fp-section-body{padding:16px}
/* ── Teacher config table ────────────────────────────────────────────────────── */
.fp-table{width:100%;border-collapse:collapse;font-size:13px}
.fp-table th{background:#f0f4f8;color:#37474f;padding:8px 12px;text-align:left;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.4px;border-bottom:2px solid #dee2e6;white-space:nowrap}
.fp-table td{padding:8px 12px;border-bottom:1px solid #f0f4f8;vertical-align:middle}
.fp-table tr:last-child td{border-bottom:none}
.fp-table tr:hover td{background:#fafbfc}
.fp-rate-input{width:90px;padding:4px 8px;border:1px solid #ced4da;border-radius:4px;font-size:13px;text-align:right}
.fp-rate-input:focus{outline:none;border-color:#1976D2;box-shadow:0 0 0 2px rgba(25,118,210,.15)}
.fp-exclude-cb{width:16px;height:16px;cursor:pointer;accent-color:#c62828}
.fp-teacher-excluded td{opacity:.45;text-decoration:line-through}
.fp-teacher-excluded td:last-child{text-decoration:none;opacity:1}
.fp-class-list{font-size:11px;color:#546e7a;max-width:280px}
/* ── Monthly summary table ───────────────────────────────────────────────────── */
.fp-summary-table{width:100%;border-collapse:collapse;font-size:12px;min-width:700px}
.fp-summary-table th{background:#37474f;color:#fff;padding:7px 10px;text-align:right;font-size:11px;font-weight:700;white-space:nowrap}
.fp-summary-table th:first-child{text-align:left}
.fp-summary-table td{padding:6px 10px;border-bottom:1px solid #f0f4f8;text-align:right;white-space:nowrap}
.fp-summary-table td:first-child{text-align:left;font-weight:600;color:#37474f}
.fp-summary-table tr.fp-total-row td{background:#e8f5e9;font-weight:800;font-size:13px;border-top:2px solid #4caf50;color:#2e7d32}
.fp-summary-table tr.fp-extra-row td{background:#fff3e0;color:#e65100}
.fp-summary-scroll{overflow-x:auto}
/* ── Chart ───────────────────────────────────────────────────────────────────── */
.fp-chart-wrap{position:relative;height:360px;width:100%}
/* ── Additional costs ────────────────────────────────────────────────────────── */
.fp-extra-list{display:flex;flex-direction:column;gap:8px;margin-bottom:12px}
.fp-extra-row-form{display:flex;flex-wrap:wrap;gap:8px;align-items:center;background:#f8f9fa;border:1px solid #dee2e6;border-radius:6px;padding:10px 12px}
.fp-extra-row-form select,.fp-extra-row-form input{padding:5px 8px;border:1px solid #ced4da;border-radius:4px;font-size:12px}
.fp-extra-row-form input[type=text]{min-width:180px}
.fp-extra-row-form input[type=number]{width:100px;text-align:right}
.fp-extra-row-form .fp-badge-type{padding:3px 10px;border-radius:12px;font-size:11px;font-weight:700;text-transform:uppercase}
.fp-badge-teacher{background:#e3f2fd;color:#1565C0}
.fp-badge-event{background:#fff3e0;color:#e65100}
.fp-rm-btn{background:none;border:none;cursor:pointer;color:#c62828;font-size:16px;padding:0 4px;line-height:1}
/* ── Add buttons ─────────────────────────────────────────────────────────────── */
.fp-add-btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border:none;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;transition:opacity .15s}
.fp-add-btn:hover{opacity:.85}
.fp-btn-teacher{background:#e3f2fd;color:#1565C0}
.fp-btn-event{background:#fff3e0;color:#e65100}
/* ── Default rate banner ─────────────────────────────────────────────────────── */
.fp-rate-banner{display:flex;align-items:center;gap:16px;flex-wrap:wrap;background:#f0f4f8;border:1px solid #cfd8dc;border-radius:8px;padding:12px 16px;margin-bottom:16px}
.fp-rate-banner label{font-size:12px;font-weight:700;color:#37474f;margin:0}
.fp-rate-banner input{width:100px;padding:5px 10px;border:1px solid #90a4ae;border-radius:4px;font-size:14px;font-weight:700;text-align:right}
.fp-rate-note{font-size:11px;color:#6c757d}
/* ── No data ─────────────────────────────────────────────────────────────────── */
.fp-nodata{background:#fff8e1;border-left:4px solid #ffc107;border-radius:4px;padding:16px 20px;margin:20px 0;color:#6d4c00;font-weight:600}
.fp-noperiod{background:#e8f5e9;border-left:4px solid #4caf50;border-radius:4px;padding:16px 20px;margin:20px 0;color:#2e7d32;font-weight:600}
</style>

<div class="fp-wrap">
<h2 style="margin-bottom:4px;color:#0d1b4b">💰 Análisis Financiero Docente</h2>
<p style="color:#6c757d;font-size:13px;margin-bottom:12px">
    Proyección mensual de costos docentes basada en horarios de clases del período seleccionado.
</p>

<!-- Filters -->
<form method="get" class="fp-filters">
    <div>
        <label>Período Académico</label>
        <select name="periodid">
            <option value="0">— Seleccionar período —</option>
            <?php foreach ($allPeriods as $p): ?>
            <option value="<?php echo (int)$p->id; ?>" <?php echo ((int)$p->id === $filter_periodid ? 'selected' : ''); ?>>
                <?php echo fp_h($p->name); ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label>Carrera (opcional)</label>
        <select name="planid">
            <option value="0">— Todas —</option>
            <?php foreach ($allPlans as $pl): ?>
            <option value="<?php echo (int)$pl->id; ?>" <?php echo ((int)$pl->id === $filter_planid ? 'selected' : ''); ?>>
                <?php echo fp_h($pl->name); ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label>Origen de datos</label>
        <select name="include_draft" style="min-width:220px">
            <option value="0" <?php echo ($include_draft === 0 ? 'selected' : ''); ?>>Cursos activos (en ejecución)</option>
            <option value="1" <?php echo ($include_draft === 1 ? 'selected' : ''); ?>>Borradores — planificación académica</option>
        </select>
    </div>
    <div>
        <button type="submit" class="btn btn-primary" style="font-size:13px">Cargar Datos</button>
        <a href="<?php echo (new moodle_url('/local/grupomakro_core/pages/financial_planning.php'))->out(false); ?>"
           class="btn btn-secondary" style="margin-left:6px;font-size:13px">Limpiar</a>
    </div>
</form>

<?php if ($filter_periodid === 0): ?>
<div class="fp-noperiod">Selecciona un período académico para ver la proyección financiera.</div>
<?php elseif (empty($teacherMeta)): ?>
<div class="fp-nodata">
    <?php if ($include_draft): ?>
    No se encontraron clases en borrador con horarios asignados para el período seleccionado.
    <?php else: ?>
    No se encontraron clases activas para el período seleccionado.
    <?php endif; ?>
</div>
<?php else: ?>

<?php if ($include_draft): ?>
<div style="background:#fff3cd;border-left:4px solid #ffc107;border-radius:4px;padding:12px 16px;margin-bottom:12px;font-size:13px;color:#856404">
    <strong>⚠ Modo borrador:</strong> Visualizando cursos planificados (no publicados aún) del período <strong><?php echo fp_h($periodLabel); ?></strong>.
    Los cursos sin docente asignado aparecen agrupados como <em>"Sin docente asignado"</em> con tarifa predeterminada de $18/hora.
</div>
<?php endif; ?>

<!-- ── Config: default rate + teacher table ─────────────────────────────────── -->
<div class="fp-section">
    <div class="fp-section-header">⚙️ Configuración de Tarifas Docentes
        <?php if ($periodLabel): ?>
        <span style="font-size:12px;font-weight:400;opacity:.85">— <?php echo fp_h($periodLabel); ?></span>
        <?php endif; ?>
    </div>
    <div class="fp-section-body">
        <div class="fp-rate-banner">
            <label for="fp-default-rate">💵 Tarifa hora docente (USD):</label>
            <input type="number" id="fp-default-rate" min="0" step="0.5" value="18">
            <span class="fp-rate-note">Valor por defecto aplicado a todos los docentes. Puedes personalizar por docente abajo.</span>
        </div>
        <div style="overflow-x:auto">
        <table class="fp-table" id="fp-teacher-table">
            <thead>
                <tr>
                    <th>Docente</th>
                    <th>Clases asignadas</th>
                    <th style="text-align:right">Horas totales estimadas</th>
                    <th style="text-align:right">Tarifa (USD/h)</th>
                    <th style="text-align:center">Excluir</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($teacherMeta as $iid => $tm):
                $totalHours   = array_sum($teacherHours[$iid] ?? []);
                $isNoTeacher  = !empty($tm['no_teacher']);
                $rowStyle     = $isNoTeacher ? ' style="background:#fff3e0"' : '';
                $nameStyle    = 'font-weight:600' . ($isNoTeacher ? ';color:#e65100' : '');
            ?>
            <tr id="fp-trow-<?php echo $iid; ?>" data-iid="<?php echo $iid; ?>"<?php echo $rowStyle; ?>>
                <td style="<?php echo $nameStyle; ?>">
                    <?php if ($isNoTeacher): ?>⚠ <?php endif; ?>
                    <?php echo fp_h($tm['name']); ?>
                </td>
                <td class="fp-class-list"><?php echo fp_h(implode(', ', array_unique($tm['classes']))); ?></td>
                <td style="text-align:right"><?php echo number_format($totalHours, 1); ?> h</td>
                <td style="text-align:right">
                    <input type="number" class="fp-rate-input fp-teacher-rate"
                           data-iid="<?php echo $iid; ?>"
                           min="0" step="0.5" value="18"
                           title="Tarifa para <?php echo fp_h($tm['name']); ?>">
                </td>
                <td style="text-align:center">
                    <input type="checkbox" class="fp-exclude-cb fp-teacher-exclude"
                           data-iid="<?php echo $iid; ?>"
                           title="Excluir a <?php echo fp_h($tm['name']); ?> del análisis">
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<!-- ── Additional costs ─────────────────────────────────────────────────────── -->
<div class="fp-section">
    <div class="fp-section-header">➕ Costos Adicionales</div>
    <div class="fp-section-body">
        <p style="font-size:12px;color:#6c757d;margin:0 0 12px">
            Agrega docentes externos o eventos con costo en meses específicos.
        </p>
        <div id="fp-extra-list" class="fp-extra-list"></div>
        <button class="fp-add-btn fp-btn-teacher" id="fp-add-teacher">👤 Agregar docente externo</button>
        <button class="fp-add-btn fp-btn-event" id="fp-add-event" style="margin-left:8px">📅 Agregar evento / costo</button>
    </div>
</div>

<!-- ── Chart ─────────────────────────────────────────────────────────────────── -->
<div class="fp-section">
    <div class="fp-section-header">📊 Proyección de Costos Mensuales</div>
    <div class="fp-section-body">
        <div class="fp-chart-wrap">
            <canvas id="fp-chart"></canvas>
        </div>
    </div>
</div>

<!-- ── Monthly summary table ─────────────────────────────────────────────────── -->
<div class="fp-section">
    <div class="fp-section-header">📋 Desglose Mensual por Docente</div>
    <div class="fp-section-body fp-summary-scroll">
        <div id="fp-summary-wrap"></div>
    </div>
</div>

<?php endif; ?>
</div>

<?php if (!empty($teacherMeta)): ?>
<script>
(function() {
    // ── Raw data from PHP ────────────────────────────────────────────────────
    const TEACHER_HOURS = <?php echo json_encode($teacherHours, JSON_PRETTY_PRINT); ?>;
    const TEACHER_META  = <?php echo json_encode($teacherMeta,  JSON_PRETTY_PRINT); ?>;
    const ALL_MONTHS    = <?php echo json_encode($allMonths,    JSON_PRETTY_PRINT); ?>;

    // ── State ─────────────────────────────────────────────────────────────────
    const teacherRates    = {};   // iid → rate
    const teacherExcluded = {};   // iid → bool
    let   additionalCosts = [];   // [{id, type, ...}]
    let   extraIdCounter  = 0;
    let   chart           = null;

    const MONTH_NAMES = {
        '01':'Enero','02':'Febrero','03':'Marzo','04':'Abril',
        '05':'Mayo','06':'Junio','07':'Julio','08':'Agosto',
        '09':'Septiembre','10':'Octubre','11':'Noviembre','12':'Diciembre'
    };
    const COLORS = [
        '#1976D2','#388E3C','#7B1FA2','#F57C00','#C62828',
        '#00838F','#5D4037','#AD1457','#558B2F','#1565C0',
        '#6A1B9A','#2E7D32','#E65100','#0277BD','#4527A0',
        '#00695C','#F9A825','#6D4C41','#37474F','#880E4F',
    ];

    function monthLabel(mk) {
        const [y, m] = mk.split('-');
        return (MONTH_NAMES[m] || m) + ' ' + y;
    }
    function fmt(v) { return '$' + v.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }
    function fmtH(v) { return v.toFixed(1) + 'h'; }

    // ── Init teacher rates from DOM ───────────────────────────────────────────
    function initState() {
        const defaultRate = parseFloat(document.getElementById('fp-default-rate').value) || 18;
        document.querySelectorAll('.fp-teacher-rate').forEach(inp => {
            const iid = inp.dataset.iid;
            teacherRates[iid] = parseFloat(inp.value) || defaultRate;
        });
        document.querySelectorAll('.fp-teacher-exclude').forEach(cb => {
            const iid = cb.dataset.iid;
            teacherExcluded[iid] = cb.checked;
        });
    }

    // ── Compute monthly costs ─────────────────────────────────────────────────
    function computeCosts() {
        // Returns: { [month]: { teachers: {[iid]: cost}, extra: [{label, cost}], total } }
        const result = {};
        ALL_MONTHS.forEach(mk => { result[mk] = { teachers: {}, extra: [], total: 0 }; });

        // Teacher costs
        for (const iid in TEACHER_HOURS) {
            if (teacherExcluded[iid]) { continue; }
            const rate = teacherRates[iid] || 0;
            ALL_MONTHS.forEach(mk => {
                const hrs  = (TEACHER_HOURS[iid] && TEACHER_HOURS[iid][mk]) ? TEACHER_HOURS[iid][mk] : 0;
                const cost = hrs * rate;
                result[mk].teachers[iid] = cost;
                result[mk].total += cost;
            });
        }

        // Additional costs
        additionalCosts.forEach(ac => {
            if (ac.type === 'teacher') {
                const rate  = parseFloat(ac.rate)  || 0;
                const hours = parseFloat(ac.hours) || 0;
                const cost  = rate * hours;
                ALL_MONTHS.forEach(mk => {
                    result[mk].extra.push({ label: ac.name + ' (ext.)', cost });
                    result[mk].total += cost;
                });
            } else if (ac.type === 'event') {
                if (result[ac.month]) {
                    const cost = parseFloat(ac.amount) || 0;
                    result[ac.month].extra.push({ label: ac.description, cost });
                    result[ac.month].total += cost;
                }
            }
        });

        return result;
    }

    // ── Render chart ──────────────────────────────────────────────────────────
    function renderChart(costs) {
        const labels   = ALL_MONTHS.map(monthLabel);
        const datasets = [];
        let colorIdx   = 0;

        // One dataset per non-excluded teacher
        for (const iid in TEACHER_META) {
            if (teacherExcluded[iid]) { continue; }
            const data  = ALL_MONTHS.map(mk => parseFloat(((costs[mk] || {}).teachers || {})[iid] || 0).toFixed(2));
            datasets.push({
                label       : TEACHER_META[iid].name,
                data,
                backgroundColor: COLORS[colorIdx % COLORS.length],
                stack       : 'stack',
            });
            colorIdx++;
        }

        // Extra costs dataset
        const hasExtra = additionalCosts.length > 0;
        if (hasExtra) {
            const extraData = ALL_MONTHS.map(mk => {
                return ((costs[mk] || {}).extra || []).reduce((s, e) => s + e.cost, 0).toFixed(2);
            });
            datasets.push({
                label           : 'Costos adicionales',
                data            : extraData,
                backgroundColor : '#FF8F00',
                stack           : 'stack',
            });
        }

        // Total line
        datasets.push({
            label         : 'Total mensual',
            data          : ALL_MONTHS.map(mk => parseFloat(((costs[mk] || {}).total || 0)).toFixed(2)),
            type          : 'line',
            borderColor   : '#212121',
            backgroundColor: 'transparent',
            borderWidth   : 2,
            pointRadius   : 4,
            pointBackgroundColor: '#212121',
            tension       : 0.3,
            stack         : undefined,
        });

        const ctx = document.getElementById('fp-chart').getContext('2d');
        if (chart) { chart.destroy(); }
        chart = new Chart(ctx, {
            type: 'bar',
            data: { labels, datasets },
            options: {
                responsive        : true,
                maintainAspectRatio: false,
                plugins: {
                    legend : { position: 'bottom', labels: { font: { size: 11 }, boxWidth: 14 } },
                    tooltip: {
                        callbacks: {
                            label: ctx => ' ' + ctx.dataset.label + ': ' + fmt(parseFloat(ctx.parsed.y))
                        }
                    }
                },
                scales: {
                    x: { stacked: true, ticks: { font: { size: 11 } } },
                    y: {
                        stacked: true,
                        ticks  : { callback: v => '$' + Number(v).toLocaleString(), font: { size: 11 } },
                        title  : { display: true, text: 'USD', font: { size: 11 } }
                    }
                }
            }
        });
    }

    // ── Render summary table ──────────────────────────────────────────────────
    function renderSummary(costs) {
        // Columns: teacher names (not excluded)
        const activeTids = Object.keys(TEACHER_META).filter(iid => !teacherExcluded[iid]);
        const hasExtra   = additionalCosts.length > 0;

        let html = '<table class="fp-summary-table"><thead><tr>';
        html += '<th>Mes</th>';
        activeTids.forEach(iid => {
            html += '<th>' + escH(TEACHER_META[iid].name) + '</th>';
        });
        if (hasExtra) { html += '<th>Costos adicionales</th>'; }
        html += '<th>TOTAL</th>';
        html += '</tr></thead><tbody>';

        let grandTotal = 0;
        const colTotals = {};
        activeTids.forEach(iid => { colTotals[iid] = 0; });
        let extraColTotal = 0;

        ALL_MONTHS.forEach(mk => {
            const mc = costs[mk] || {};
            const extraSum = (mc.extra || []).reduce((s, e) => s + e.cost, 0);
            html += '<tr>';
            html += '<td>' + monthLabel(mk) + '</td>';
            activeTids.forEach(iid => {
                const v = (mc.teachers || {})[iid] || 0;
                colTotals[iid] += v;
                html += '<td>' + fmt(v) + '</td>';
            });
            if (hasExtra) {
                extraColTotal += extraSum;
                html += '<td class="" style="color:#e65100">' + fmt(extraSum) + '</td>';
            }
            const rowTotal = mc.total || 0;
            grandTotal += rowTotal;
            html += '<td style="font-weight:700;color:#1565C0">' + fmt(rowTotal) + '</td>';
            html += '</tr>';
        });

        // Totals row
        html += '<tr class="fp-total-row"><td>TOTAL PERÍODO</td>';
        activeTids.forEach(iid => { html += '<td>' + fmt(colTotals[iid]) + '</td>'; });
        if (hasExtra) { html += '<td>' + fmt(extraColTotal) + '</td>'; }
        html += '<td>' + fmt(grandTotal) + '</td>';
        html += '</tr></tbody></table>';

        document.getElementById('fp-summary-wrap').innerHTML = html;
    }

    function escH(v) { return String(v).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

    // ── updateAll ─────────────────────────────────────────────────────────────
    function updateAll() {
        const costs = computeCosts();
        renderChart(costs);
        renderSummary(costs);
    }

    // ── Event: default rate change → propagate to non-customized teachers ─────
    document.getElementById('fp-default-rate').addEventListener('input', function() {
        const rate = parseFloat(this.value) || 18;
        document.querySelectorAll('.fp-teacher-rate').forEach(inp => {
            const iid = inp.dataset.iid;
            // Only update if user hasn't customized this teacher's rate
            if (!inp.dataset.customized) {
                inp.value = rate;
                teacherRates[iid] = rate;
            }
        });
        updateAll();
    });

    // ── Event: per-teacher rate change ────────────────────────────────────────
    document.addEventListener('input', function(e) {
        if (e.target.classList.contains('fp-teacher-rate')) {
            e.target.dataset.customized = '1';
            teacherRates[e.target.dataset.iid] = parseFloat(e.target.value) || 0;
            updateAll();
        }
    });

    // ── Event: exclude checkbox ───────────────────────────────────────────────
    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('fp-teacher-exclude')) {
            const iid = e.target.dataset.iid;
            teacherExcluded[iid] = e.target.checked;
            const row = document.getElementById('fp-trow-' + iid);
            if (row) { row.classList.toggle('fp-teacher-excluded', e.target.checked); }
            updateAll();
        }
    });

    // ── Additional costs UI ───────────────────────────────────────────────────
    function renderExtraList() {
        const container = document.getElementById('fp-extra-list');
        container.innerHTML = '';
        additionalCosts.forEach(ac => {
            const div = document.createElement('div');
            div.className = 'fp-extra-row-form';
            div.id = 'fp-extra-' + ac.id;
            if (ac.type === 'teacher') {
                div.innerHTML =
                    '<span class="fp-badge-type fp-badge-teacher">👤 Docente ext.</span>' +
                    '<input type="text" placeholder="Nombre docente" value="' + escH(ac.name) + '" ' +
                        'data-xid="' + ac.id + '" data-xfield="name" style="min-width:180px">' +
                    '<label style="font-size:11px;margin:0">Horas/mes:</label>' +
                    '<input type="number" min="0" step="0.5" value="' + ac.hours + '" ' +
                        'data-xid="' + ac.id + '" data-xfield="hours" style="width:80px">' +
                    '<label style="font-size:11px;margin:0">USD/hora:</label>' +
                    '<input type="number" min="0" step="0.5" value="' + ac.rate + '" ' +
                        'data-xid="' + ac.id + '" data-xfield="rate" style="width:80px">' +
                    '<span style="font-size:12px;color:#6c757d">= ' + fmt((parseFloat(ac.hours)||0)*(parseFloat(ac.rate)||0)) + '/mes (todos los meses)</span>' +
                    '<button class="fp-rm-btn" data-rmid="' + ac.id + '" title="Eliminar">✕</button>';
            } else {
                const opts = ALL_MONTHS.map(mk =>
                    '<option value="' + mk + '"' + (mk === ac.month ? ' selected' : '') + '>' + monthLabel(mk) + '</option>'
                ).join('');
                div.innerHTML =
                    '<span class="fp-badge-type fp-badge-event">📅 Evento</span>' +
                    '<select data-xid="' + ac.id + '" data-xfield="month">' + opts + '</select>' +
                    '<input type="text" placeholder="Descripción del evento" value="' + escH(ac.description) + '" ' +
                        'data-xid="' + ac.id + '" data-xfield="description">' +
                    '<label style="font-size:11px;margin:0">Monto USD:</label>' +
                    '<input type="number" min="0" step="1" value="' + ac.amount + '" ' +
                        'data-xid="' + ac.id + '" data-xfield="amount">' +
                    '<button class="fp-rm-btn" data-rmid="' + ac.id + '" title="Eliminar">✕</button>';
            }
            container.appendChild(div);
        });
    }

    document.getElementById('fp-add-teacher').addEventListener('click', function() {
        additionalCosts.push({ id: ++extraIdCounter, type: 'teacher', name: '', hours: 0, rate: 18 });
        renderExtraList();
        updateAll();
    });

    document.getElementById('fp-add-event').addEventListener('click', function() {
        additionalCosts.push({ id: ++extraIdCounter, type: 'event', month: ALL_MONTHS[0] || '', description: '', amount: 0 });
        renderExtraList();
        updateAll();
    });

    document.getElementById('fp-extra-list').addEventListener('click', function(e) {
        const rmId = e.target.dataset.rmid;
        if (rmId) {
            additionalCosts = additionalCosts.filter(ac => String(ac.id) !== String(rmId));
            renderExtraList();
            updateAll();
        }
    });

    document.getElementById('fp-extra-list').addEventListener('input', function(e) {
        const xid   = e.target.dataset.xid;
        const field = e.target.dataset.xfield;
        if (!xid || !field) { return; }
        const ac = additionalCosts.find(a => String(a.id) === String(xid));
        if (!ac) { return; }
        ac[field] = e.target.value;
        renderExtraList();
        updateAll();
    });

    document.getElementById('fp-extra-list').addEventListener('change', function(e) {
        const xid   = e.target.dataset.xid;
        const field = e.target.dataset.xfield;
        if (!xid || !field) { return; }
        const ac = additionalCosts.find(a => String(a.id) === String(xid));
        if (!ac) { return; }
        ac[field] = e.target.value;
        renderExtraList();
        updateAll();
    });

    // ── Init ──────────────────────────────────────────────────────────────────
    initState();
    updateAll();
})();
</script>
<?php endif; ?>

<?php echo $OUTPUT->footer(); ?>
