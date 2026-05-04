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

function fp_compute_comparison_period($DB, $periodid, $use_draft, $planid_filter) {
    $result = ['label' => '', 'months' => [], 'hours_by_month' => []];
    try {
        $per = $DB->get_record('gmk_academic_periods', ['id' => $periodid]);
        if (!$per) { return null; }
        $result['label'] = $per->name;
    } catch (Exception $e) { return null; }

    if (!empty($per->startdate) && !empty($per->enddate)) {
        $cur = mktime(0, 0, 0, (int)date('n', $per->startdate), 1, (int)date('Y', $per->startdate));
        $lim = mktime(0, 0, 0, (int)date('n', $per->enddate),   1, (int)date('Y', $per->enddate));
        while ($cur <= $lim) {
            $mk = date('Y-m', $cur);
            $result['months'][] = $mk;
            $result['hours_by_month'][$mk] = 0.0;
            $cur = strtotime('+1 month', $cur);
        }
    }
    if (empty($result['months'])) { return $result; }
    $periodInit = (int)$per->startdate;
    $periodEnd  = (int)$per->enddate;

    if ($use_draft) {
        $draftJson = '';
        try { $draftJson = $DB->get_field('gmk_academic_periods', 'draft_schedules', ['id' => $periodid]) ?: ''; } catch (Exception $e) {}
        $items = $draftJson ? (json_decode($draftJson, true) ?: []) : [];
        foreach ($items as $item) {
            if ($planid_filter > 0 && (int)($item['learningplanid'] ?? 0) !== $planid_filter) { continue; }
            $sessions = !empty($item['sessions']) && is_array($item['sessions']) ? $item['sessions'] : [];
            if (empty($sessions) && !empty($item['day'])) {
                $sessions = [['day' => $item['day'], 'start' => $item['start'] ?? '',
                              'end' => $item['end'] ?? '', 'excluded_dates' => [],
                              'assignedDates' => $item['assignedDates'] ?? null]];
            }
            foreach ($sessions as $sess) {
                $excl = is_array($sess['excluded_dates'] ?? null) ? array_values($sess['excluded_dates']) : [];
                $dur  = fp_duration_hours((string)($sess['start'] ?? ''), (string)($sess['end'] ?? ''));
                if ($dur <= 0) { continue; }
                if (!empty($sess['assignedDates']) && is_array($sess['assignedDates'])) {
                    foreach ($sess['assignedDates'] as $dateStr) {
                        $ts = is_string($dateStr) ? strtotime($dateStr) : 0;
                        if ($ts > 0) {
                            $mk = date('Y-m', $ts);
                            if (isset($result['hours_by_month'][$mk])) { $result['hours_by_month'][$mk] += $dur; }
                        }
                    }
                } else {
                    $dow = fp_day_to_dow(fp_normalize_day((string)($sess['day'] ?? '')));
                    if ($dow < 0) { continue; }
                    foreach ($result['months'] as $mk) {
                        [$y, $m] = explode('-', $mk);
                        $cnt = fp_sessions_in_month((int)$y, (int)$m, $dow, $periodInit, $periodEnd, $excl);
                        $result['hours_by_month'][$mk] += $cnt * $dur;
                    }
                }
            }
        }
    } else {
        $wp = ['periodid' => $periodid];
        $we = '';
        if ($planid_filter > 0) { $we = ' AND gc.learningplanid = :planid'; $wp['planid'] = $planid_filter; }
        $rows = [];
        try {
            $rows = $DB->get_records_sql(
                "SELECT s.id, gc.initdate, gc.enddate, s.day, s.start_time, s.end_time,
                        COALESCE(s.excluded_dates,'') AS excluded_dates
                   FROM {gmk_class} gc
                   JOIN {gmk_class_schedules} s ON s.classid = gc.id
                  WHERE gc.periodid = :periodid AND gc.closed = 0 $we", $wp);
        } catch (Exception $e) {}
        foreach ($rows as $r) {
            $excl = [];
            if ($r->excluded_dates) { $dec = json_decode($r->excluded_dates, true); if (is_array($dec)) { $excl = array_values($dec); } }
            $dow = fp_day_to_dow(fp_normalize_day($r->day));
            $dur = fp_duration_hours($r->start_time, $r->end_time);
            if ($dow < 0 || $dur <= 0) { continue; }
            foreach ($result['months'] as $mk) {
                [$y, $m] = explode('-', $mk);
                $cnt = fp_sessions_in_month((int)$y, (int)$m, $dow, (int)$r->initdate, (int)$r->enddate, $excl);
                $result['hours_by_month'][$mk] += $cnt * $dur;
            }
        }
    }
    return $result;
}

// ── Filters ────────────────────────────────────────────────────────────────────
$filter_periodid  = optional_param('periodid',      0, PARAM_INT);
$filter_planid    = optional_param('planid',         0, PARAM_INT);
$include_draft    = optional_param('include_draft',  0, PARAM_INT);

// ── Comparison parameters ──────────────────────────────────────────────────────
$cmp_rate = max(0.0, (float)(optional_param('cmp_rate', '18', PARAM_TEXT) ?: 18));

// ── Dropdown data ──────────────────────────────────────────────────────────────
$allPeriods = [];
try { $allPeriods = $DB->get_records('gmk_academic_periods', null, 'name DESC', 'id, name'); } catch (Exception $e) {}
$allPlans = [];
try { $allPlans = $DB->get_records('local_learning_plans', null, 'name ASC', 'id, name'); } catch (Exception $e) {}

// ── Data computation ───────────────────────────────────────────────────────────
$teacherHours  = [];  // [iid][month_key] = float hours
$teacherMeta   = [];  // [iid] = {name, classes[]}
$allMonths     = [];
$periodLabel   = '';
$subjectDetail = [];  // [subjKey] = {name, career, learningplanid, iid, months:[mk=>hrs]}

// Load comparison data
$cmpData = [];
for ($ci = 1; $ci <= 3; $ci++) {
    $cpid = optional_param('cmp_p' . $ci, 0, PARAM_INT);
    $csrc = optional_param('cmp_s' . $ci, 0, PARAM_INT);
    if ($cpid > 0) {
        $d = fp_compute_comparison_period($DB, $cpid, (bool)$csrc, 0);
        if ($d) { $d['slot'] = $ci; $d['src'] = $csrc; $cmpData[$ci] = $d; }
    }
}

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

        $periodInit      = (isset($per) && $per) ? (int)$per->startdate : 0;
        $periodEnd       = (isset($per) && $per) ? (int)$per->enddate   : 0;
        $noTeacherIdMap  = [];   // career_name => negative int id
        $noTeacherCtr    = -1;

        foreach ($draftItems as $item) {
            // Filtro por carrera (learningplanid)
            if ($filter_planid > 0 && (int)($item['learningplanid'] ?? 0) !== $filter_planid) {
                continue;
            }

            $iid = (int)($item['instructorId'] ?? 0);
            if ($iid > 0) {
                $name = trim(($item['teacherName'] ?? '') ?: 'Docente #' . $iid);
            } else {
                // Sin docente: crear un ID virtual negativo único por carrera
                $career = (string)($item['career'] ?? 'Sin carrera asignada');
                if (!isset($noTeacherIdMap[$career])) {
                    $noTeacherIdMap[$career] = $noTeacherCtr--;
                }
                $iid  = $noTeacherIdMap[$career];
                $name = 'Sin docente — ' . $career;
            }
            $courseName = (string)($item['subjectName'] ?? 'Curso sin nombre');
            $subjKey = 'c' . (int)($item['corecourseid'] ?? 0) . '_lp' . (int)($item['learningplanid'] ?? 0);
            if (!isset($subjectDetail[$subjKey])) {
                $subjectDetail[$subjKey] = [
                    'name'          => $courseName,
                    'career'        => (string)($item['career'] ?? 'Sin carrera'),
                    'learningplanid'=> (int)($item['learningplanid'] ?? 0),
                    'iid'           => $iid,
                    'months'        => array_fill_keys($allMonths, 0.0),
                ];
            }

            if (!isset($teacherMeta[$iid])) {
                $teacherMeta[$iid] = [
                    'name'       => $name,
                    'no_teacher' => ($iid <= 0),   // IDs negativos = sin docente por carrera
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
                                    if (isset($subjectDetail[$subjKey]['months'][$mk])) {
                                        $subjectDetail[$subjKey]['months'][$mk] += $dur;
                                    }
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
                        $subjectDetail[$subjKey]['months'][$mk] += $cnt * $dur;
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
                       gc.corecourseid, gc.learningplanid,
                       COALESCE(gc.career_label, '') AS career_label,
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

                $subjKey = 'c' . (int)$r->corecourseid . '_lp' . (int)$r->learningplanid;
                if (!isset($subjectDetail[$subjKey])) {
                    $subjectDetail[$subjKey] = [
                        'name'          => $r->coursefullname,
                        'career'        => (string)$r->career_label,
                        'learningplanid'=> (int)$r->learningplanid,
                        'iid'           => $iid,
                        'months'        => array_fill_keys($allMonths, 0.0),
                    ];
                }

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
                    $subjectDetail[$subjKey]['months'][$mk] += $sess * $dur;
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
/* ── Analytics: subject table ────────────────────────────────────────────────── */
.fp-subj-table{width:100%;border-collapse:collapse;font-size:12px;min-width:600px}
.fp-subj-table th{background:#37474f;color:#fff;padding:7px 10px;text-align:right;font-size:11px;font-weight:700;white-space:nowrap}
.fp-subj-table th.fp-th-l{text-align:left}
.fp-subj-table td{padding:6px 10px;border-bottom:1px solid #f0f4f8;text-align:right;white-space:nowrap}
.fp-subj-table td.fp-td-l{text-align:left;font-weight:600;color:#37474f}
.fp-subj-table tr:hover td{background:#f5f8ff}
.fp-subj-total-row td{background:#e8f5e9!important;font-weight:800;border-top:2px solid #4caf50;color:#2e7d32}
.fp-career-tag{display:inline-block;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;background:#e3f2fd;color:#1565c0;white-space:nowrap;vertical-align:middle}
.fp-analytics-filter{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin-bottom:14px}
.fp-analytics-filter label{font-size:11px;font-weight:700;color:#495057;display:block;margin-bottom:3px;text-transform:uppercase}
.fp-analytics-filter select{padding:5px 8px;border:1px solid #ced4da;border-radius:4px;font-size:13px;background:#fff}
.fp-toggle-btn{background:none;border:1px solid #90a4ae;color:#546e7a;border-radius:4px;padding:2px 10px;font-size:10px;cursor:pointer;font-weight:700;transition:all .15s}
.fp-toggle-btn:hover{background:#eceff1;border-color:#546e7a}
/* ── Analytics: career accordion ────────────────────────────────────────────── */
.fp-career-list{display:flex;flex-direction:column;gap:8px}
.fp-career-item{border:1px solid #dee2e6;border-radius:8px;overflow:hidden}
.fp-career-header{display:flex;align-items:center;gap:12px;padding:10px 16px;background:#f0f4f8;cursor:pointer;user-select:none}
.fp-career-header:hover{background:#e3eaf2}
.fp-career-name{font-weight:700;font-size:13px;color:#1a237e;flex:1}
.fp-career-meta{font-size:12px;color:#546e7a}
.fp-career-cost-badge{font-size:14px;font-weight:800;color:#2e7d32}
.fp-career-chevron{font-size:12px;transition:transform .2s;color:#90a4ae}
.fp-career-header.is-open .fp-career-chevron{transform:rotate(180deg)}
.fp-career-body{display:none;padding:14px 16px;overflow-x:auto;border-top:1px solid #dee2e6}
.fp-career-body.is-open{display:block}
.fp-career-chart-wrap{height:160px;position:relative;margin-bottom:14px}
/* ── Comparison section ──────────────────────────────────────────────────────── */
.fp-cmp-slots{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;margin-bottom:14px}
.fp-cmp-slot{background:#f8f9fa;border:1px solid #dee2e6;border-radius:8px;padding:12px}
.fp-cmp-slot-title{font-size:11px;font-weight:700;color:#37474f;text-transform:uppercase;margin-bottom:8px}
.fp-cmp-slot select,.fp-cmp-slot input[type=text]{width:100%;padding:5px 8px;border:1px solid #ced4da;border-radius:4px;font-size:12px;box-sizing:border-box;margin-top:4px}
.fp-cmp-slot label{font-size:11px;color:#6c757d;display:block;margin-top:6px}
.fp-cmp-rate-row{display:flex;align-items:center;gap:10px;margin-bottom:14px;background:#e8f5e9;border-radius:6px;padding:10px 14px;flex-wrap:wrap}
.fp-cmp-rate-row label{font-size:12px;font-weight:700;color:#2e7d32;margin:0}
.fp-cmp-rate-row input{width:90px;padding:4px 8px;border:1px solid #a5d6a7;border-radius:4px;font-size:13px;font-weight:700;text-align:right}
.fp-cmp-chart-wrap{height:300px;position:relative;margin-bottom:16px}
.fp-cmp-table{width:100%;border-collapse:collapse;font-size:12px;min-width:500px}
.fp-cmp-table th{background:#1a237e;color:#fff;padding:7px 10px;font-size:11px;font-weight:700;text-align:right}
.fp-cmp-table th:first-child{text-align:left}
.fp-cmp-table td{padding:6px 10px;border-bottom:1px solid #f0f4f8;text-align:right;white-space:nowrap}
.fp-cmp-table td:first-child{text-align:left;font-weight:600;color:#37474f}
.fp-cmp-total-row td{background:#e8f5e9;font-weight:800;color:#2e7d32;border-top:2px solid #4caf50}
.fp-src-badge{display:inline-block;padding:1px 6px;border-radius:8px;font-size:10px;font-weight:700}
.fp-src-active{background:#e8f5e9;color:#2e7d32}
.fp-src-draft{background:#fff3cd;color:#856404}
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

<!-- ── Subject analytics ──────────────────────────────────────────────────────── -->
<div class="fp-section">
    <div class="fp-section-header">📚 Costos por Asignatura
        <span style="font-size:11px;font-weight:400;opacity:.8;margin-left:8px">— calculado con las tarifas configuradas arriba</span>
    </div>
    <div class="fp-section-body">
        <div class="fp-analytics-filter">
            <div>
                <label>Filtrar por carrera</label>
                <select id="fp-subj-career-filter">
                    <option value="">— Todas las carreras —</option>
                    <?php
                    $careerOptionsMap = [];
                    foreach ($subjectDetail as $sd) {
                        $lpid = $sd['learningplanid'];
                        if (!isset($careerOptionsMap[$lpid])) {
                            $careerOptionsMap[$lpid] = $sd['career'];
                        }
                    }
                    foreach ($careerOptionsMap as $lpid => $cname): ?>
                    <option value="<?php echo (int)$lpid; ?>"><?php echo fp_h($cname); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="align-self:flex-end">
                <button class="fp-toggle-btn" id="fp-subj-toggle-months">Ver meses</button>
            </div>
        </div>
        <div style="overflow-x:auto">
            <div id="fp-subj-wrap"></div>
        </div>
    </div>
</div>

<!-- ── Career analytics ───────────────────────────────────────────────────────── -->
<div class="fp-section">
    <div class="fp-section-header">🎓 Costos por Carrera
        <span style="font-size:11px;font-weight:400;opacity:.8;margin-left:8px">— desglose por mes, expandible</span>
    </div>
    <div class="fp-section-body">
        <div id="fp-career-wrap" class="fp-career-list"></div>
    </div>
</div>

<?php endif; ?>

<!-- ── Comparison section (always visible to select periods) ──────────────────── -->
<div class="fp-section" style="margin-top:20px">
    <div class="fp-section-header">🔄 Comparativa Multi-Período
        <span style="font-size:11px;font-weight:400;opacity:.8;margin-left:8px">— compara hasta 3 períodos independientemente</span>
    </div>
    <div class="fp-section-body">
        <form method="get" id="fp-cmp-form">
            <?php if ($filter_periodid > 0): ?>
            <input type="hidden" name="periodid" value="<?php echo (int)$filter_periodid; ?>">
            <input type="hidden" name="include_draft" value="<?php echo (int)$include_draft; ?>">
            <input type="hidden" name="planid" value="<?php echo (int)$filter_planid; ?>">
            <?php endif; ?>
            <div class="fp-cmp-rate-row">
                <label>💵 Tarifa hora (USD) para comparación:</label>
                <input type="number" name="cmp_rate" id="fp-cmp-rate-input"
                       min="0" step="0.5" value="<?php echo number_format($cmp_rate, 2, '.', ''); ?>">
                <span style="font-size:11px;color:#388e3c">Se aplica a todos los docentes de los períodos comparados.</span>
            </div>
            <div class="fp-cmp-slots">
            <?php for ($ci = 1; $ci <= 3; $ci++):
                $selPid = (int)(optional_param('cmp_p' . $ci, 0, PARAM_INT));
                $selSrc = (int)(optional_param('cmp_s' . $ci, 0, PARAM_INT));
            ?>
            <div class="fp-cmp-slot">
                <div class="fp-cmp-slot-title">Período <?php echo $ci; ?></div>
                <select name="cmp_p<?php echo $ci; ?>">
                    <option value="0">— No comparar —</option>
                    <?php foreach ($allPeriods as $p): ?>
                    <option value="<?php echo (int)$p->id; ?>" <?php echo ((int)$p->id === $selPid ? 'selected' : ''); ?>>
                        <?php echo fp_h($p->name); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <label>Origen de datos</label>
                <select name="cmp_s<?php echo $ci; ?>">
                    <option value="0" <?php echo ($selSrc === 0 ? 'selected' : ''); ?>>Cursos activos</option>
                    <option value="1" <?php echo ($selSrc === 1 ? 'selected' : ''); ?>>Borradores — planificación</option>
                </select>
            </div>
            <?php endfor; ?>
            </div>
            <button type="submit" class="btn btn-primary" style="font-size:13px">Generar Comparativa</button>
            <a href="<?php echo (new moodle_url('/local/grupomakro_core/pages/financial_planning.php',
                array_filter(['periodid'=>$filter_periodid,'include_draft'=>$include_draft,'planid'=>$filter_planid])))->out(false); ?>"
               class="btn btn-secondary" style="margin-left:8px;font-size:13px">Limpiar comparativa</a>
        </form>

        <?php if (!empty($cmpData)): ?>
        <hr style="margin:16px 0;border-color:#dee2e6">
        <div style="overflow-x:auto">
            <div class="fp-cmp-chart-wrap"><canvas id="fp-cmp-chart"></canvas></div>
        </div>
        <div style="overflow-x:auto;margin-top:10px">
            <?php
            // Compute all unique months across all comparison periods
            $cmpAllMonths = [];
            foreach ($cmpData as $cd) {
                foreach ($cd['months'] as $mk) {
                    if (!in_array($mk, $cmpAllMonths)) { $cmpAllMonths[] = $mk; }
                }
            }
            sort($cmpAllMonths);
            $cmpMonthNames = ['01'=>'Ene','02'=>'Feb','03'=>'Mar','04'=>'Abr','05'=>'May','06'=>'Jun',
                              '07'=>'Jul','08'=>'Ago','09'=>'Sep','10'=>'Oct','11'=>'Nov','12'=>'Dic'];
            function fp_cmp_month_label($mk) {
                global $cmpMonthNames;
                [$y, $m] = explode('-', $mk);
                return ($cmpMonthNames[$m] ?? $m) . ' ' . substr($y, 2);
            }
            ?>
            <table class="fp-cmp-table">
                <thead>
                    <tr>
                        <th style="text-align:left">Período</th>
                        <th>Fuente</th>
                        <?php foreach ($cmpAllMonths as $mk): ?>
                        <th><?php echo fp_h(fp_cmp_month_label($mk)); ?></th>
                        <?php endforeach; ?>
                        <th>TOTAL HORAS</th>
                        <th>COSTO ESTIMADO</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($cmpData as $cd):
                    $totalHrs = array_sum($cd['hours_by_month']);
                    $srcLabel = $cd['src'] ? 'Borrador' : 'Activo';
                    $srcClass = $cd['src'] ? 'fp-src-draft' : 'fp-src-active';
                ?>
                <tr>
                    <td><?php echo fp_h($cd['label']); ?></td>
                    <td style="text-align:left"><span class="fp-src-badge <?php echo $srcClass; ?>"><?php echo $srcLabel; ?></span></td>
                    <?php foreach ($cmpAllMonths as $mk):
                        $h = $cd['hours_by_month'][$mk] ?? 0;
                    ?>
                    <td><?php echo number_format($h, 1); ?>h</td>
                    <?php endforeach; ?>
                    <td style="font-weight:700"><?php echo number_format($totalHrs, 1); ?>h</td>
                    <td style="font-weight:700;color:#1565C0">$<?php echo number_format($totalHrs * $cmp_rate, 2); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

</div>

<?php if (!empty($teacherMeta)): ?>
<script>
(function() {
    // ── Raw data from PHP ────────────────────────────────────────────────────
    const TEACHER_HOURS  = <?php echo json_encode($teacherHours,  JSON_PRETTY_PRINT); ?>;
    const TEACHER_META   = <?php echo json_encode($teacherMeta,   JSON_PRETTY_PRINT); ?>;
    const ALL_MONTHS     = <?php echo json_encode($allMonths,     JSON_PRETTY_PRINT); ?>;
    const SUBJECT_DETAIL = <?php echo json_encode($subjectDetail, JSON_PRETTY_PRINT); ?>;

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

    // ── Compute subject costs (rates may change at runtime) ───────────────────
    function computeSubjectCosts() {
        const result = {};
        let subjectCareerFilter = '';
        const filterEl = document.getElementById('fp-subj-career-filter');
        if (filterEl) { subjectCareerFilter = filterEl.value; }

        for (const key in SUBJECT_DETAIL) {
            const subj = SUBJECT_DETAIL[key];
            if (subjectCareerFilter && String(subj.learningplanid) !== String(subjectCareerFilter)) { continue; }
            const iid  = String(subj.iid);
            if (teacherExcluded[iid]) { continue; }
            const rate = teacherRates[iid] || 0;
            const monthCosts = {};
            let total = 0;
            ALL_MONTHS.forEach(mk => {
                const hrs  = (subj.months || {})[mk] || 0;
                const cost = hrs * rate;
                monthCosts[mk] = cost;
                total += cost;
            });
            result[key] = { name: subj.name, career: subj.career, learningplanid: subj.learningplanid, iid, monthCosts, total };
        }
        return result;
    }

    let subjShowMonths = false;

    function renderSubjectTable() {
        const subjectCosts = computeSubjectCosts();
        const wrap = document.getElementById('fp-subj-wrap');
        if (!wrap) { return; }
        const keys = Object.keys(subjectCosts).sort((a, b) => {
            const ca = subjectCosts[a].career, cb = subjectCosts[b].career;
            return ca !== cb ? ca.localeCompare(cb) : subjectCosts[a].name.localeCompare(subjectCosts[b].name);
        });
        if (!keys.length) { wrap.innerHTML = '<p style="color:#6c757d;font-size:13px">Sin datos para mostrar.</p>'; return; }

        let html = '<table class="fp-subj-table"><thead><tr>';
        html += '<th class="fp-th-l">Asignatura</th><th class="fp-th-l">Carrera</th>';
        if (subjShowMonths) { ALL_MONTHS.forEach(mk => { html += '<th>' + escH(shortMonthLabel(mk)) + '</th>'; }); }
        html += '<th>Total</th></tr></thead><tbody>';

        let grandTotal = 0;
        const monthTotals = {};
        if (subjShowMonths) { ALL_MONTHS.forEach(mk => { monthTotals[mk] = 0; }); }

        keys.forEach(key => {
            const s = subjectCosts[key];
            html += '<tr><td class="fp-td-l">' + escH(s.name) + '</td>';
            html += '<td class="fp-td-l"><span class="fp-career-tag">' + escH(s.career) + '</span></td>';
            if (subjShowMonths) {
                ALL_MONTHS.forEach(mk => {
                    monthTotals[mk] = (monthTotals[mk] || 0) + (s.monthCosts[mk] || 0);
                    html += '<td>' + fmt(s.monthCosts[mk] || 0) + '</td>';
                });
            }
            html += '<td style="font-weight:700;color:#1565C0">' + fmt(s.total) + '</td></tr>';
            grandTotal += s.total;
        });

        html += '<tr class="fp-subj-total-row"><td colspan="2">TOTAL PERÍODO</td>';
        if (subjShowMonths) { ALL_MONTHS.forEach(mk => { html += '<td>' + fmt(monthTotals[mk] || 0) + '</td>'; }); }
        html += '<td>' + fmt(grandTotal) + '</td></tr>';
        html += '</tbody></table>';
        wrap.innerHTML = html;
    }

    // ── Career accordion ──────────────────────────────────────────────────────
    const careerCharts = {};

    function computeCareerCosts() {
        const subjectCosts = computeSubjectCosts();
        const careers = {};
        for (const key in subjectCosts) {
            const s = subjectCosts[key];
            const ckey = 'lp' + s.learningplanid;
            if (!careers[ckey]) {
                careers[ckey] = { name: s.career, learningplanid: s.learningplanid, subjects: [], monthCosts: {}, total: 0 };
                ALL_MONTHS.forEach(mk => { careers[ckey].monthCosts[mk] = 0; });
            }
            careers[ckey].subjects.push(s);
            ALL_MONTHS.forEach(mk => {
                careers[ckey].monthCosts[mk] += s.monthCosts[mk] || 0;
                careers[ckey].total += s.monthCosts[mk] || 0;
            });
        }
        return careers;
    }

    function renderCareerAccordion() {
        const wrap = document.getElementById('fp-career-wrap');
        if (!wrap) { return; }
        const careers = computeCareerCosts();
        const ckeys = Object.keys(careers).sort((a, b) => careers[a].name.localeCompare(careers[b].name));
        if (!ckeys.length) { wrap.innerHTML = '<p style="color:#6c757d;font-size:13px">Sin datos para mostrar.</p>'; return; }

        wrap.innerHTML = '';
        ckeys.forEach(ckey => {
            const career = careers[ckey];
            const item = document.createElement('div');
            item.className = 'fp-career-item';
            item.dataset.ckey = ckey;

                item.innerHTML =
                '<div class="fp-career-header" onclick="fpToggleCareer(this)">' +
                  '<span class="fp-career-name">' + escH(career.name || 'Sin carrera') + '</span>' +
                  '<span class="fp-career-meta">' + career.subjects.length + ' asignaturas</span>' +
                  '<span class="fp-career-cost-badge">' + fmt(career.total) + '</span>' +
                  '<span class="fp-career-chevron">▼</span>' +
                '</div>' +
                '<div class="fp-career-body" id="fp-cb-' + ckey + '">' +
                  '<div class="fp-career-chart-wrap"><canvas id="fp-cc-' + ckey + '"></canvas></div>' +
                  buildCareerSubjTable(career) +
                '</div>';
            wrap.appendChild(item);
        });
    }

    function buildCareerSubjTable(career) {
        let html = '<table class="fp-subj-table" style="margin-top:10px"><thead><tr>';
        html += '<th class="fp-th-l">Asignatura</th>';
        ALL_MONTHS.forEach(mk => { html += '<th>' + escH(shortMonthLabel(mk)) + '</th>'; });
        html += '<th>Total</th></tr></thead><tbody>';
        career.subjects.forEach(s => {
            html += '<tr><td class="fp-td-l">' + escH(s.name) + '</td>';
            ALL_MONTHS.forEach(mk => { html += '<td>' + fmt(s.monthCosts[mk] || 0) + '</td>'; });
            html += '<td style="font-weight:700;color:#1565C0">' + fmt(s.total) + '</td></tr>';
        });
        html += '<tr class="fp-subj-total-row"><td>TOTAL</td>';
        ALL_MONTHS.forEach(mk => { html += '<td>' + fmt(career.monthCosts[mk] || 0) + '</td>'; });
        html += '<td>' + fmt(career.total) + '</td></tr>';
        html += '</tbody></table>';
        return html;
    }

    window.fpToggleCareer = function(header) {
        const isOpen = header.classList.toggle('is-open');
        const body   = header.nextElementSibling;
        body.classList.toggle('is-open', isOpen);
        if (isOpen) {
            const ckey    = header.closest('.fp-career-item').dataset.ckey;
            const careers = computeCareerCosts();
            const career  = careers[ckey];
            if (!career) { return; }
            const canvasId = 'fp-cc-' + ckey;
            if (careerCharts[ckey]) { careerCharts[ckey].destroy(); }
            const ctx = document.getElementById(canvasId);
            if (!ctx) { return; }
            const COLORS = ['#1976D2','#388E3C','#7B1FA2','#F57C00','#C62828','#00838F','#5D4037','#AD1457'];
            const datasets = career.subjects.map((s, i) => ({
                label: s.name,
                data: ALL_MONTHS.map(mk => parseFloat((s.monthCosts[mk] || 0).toFixed(2))),
                backgroundColor: COLORS[i % COLORS.length],
                stack: 'stack',
            }));
            careerCharts[ckey] = new Chart(ctx, {
                type: 'bar',
                data: { labels: ALL_MONTHS.map(shortMonthLabel), datasets },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom', labels: { font: { size: 10 }, boxWidth: 12 } } },
                    scales: {
                        x: { stacked: true, ticks: { font: { size: 10 } } },
                        y: { stacked: true, ticks: { callback: v => '$' + Number(v).toLocaleString(), font: { size: 10 } } }
                    }
                }
            });
        }
    };

    function shortMonthLabel(mk) {
        const mn = { '01':'Ene','02':'Feb','03':'Mar','04':'Abr','05':'May','06':'Jun',
                     '07':'Jul','08':'Ago','09':'Sep','10':'Oct','11':'Nov','12':'Dic' };
        const [y, m] = mk.split('-');
        return (mn[m] || m) + ' ' + y.slice(2);
    }

    // ── updateAll ─────────────────────────────────────────────────────────────
    function updateAll() {
        const costs = computeCosts();
        renderChart(costs);
        renderSummary(costs);
        renderSubjectTable();
        renderCareerAccordion();
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

    // ── Subject analytics events ──────────────────────────────────────────────
    const subjCareerFilter = document.getElementById('fp-subj-career-filter');
    if (subjCareerFilter) {
        subjCareerFilter.addEventListener('change', function() {
            renderSubjectTable();
            renderCareerAccordion();
        });
    }
    const subjToggleBtn = document.getElementById('fp-subj-toggle-months');
    if (subjToggleBtn) {
        subjToggleBtn.addEventListener('click', function() {
            subjShowMonths = !subjShowMonths;
            this.textContent = subjShowMonths ? 'Ocultar meses' : 'Ver meses';
            renderSubjectTable();
        });
    }

    // ── Init ──────────────────────────────────────────────────────────────────
    initState();
    updateAll();
})();
</script>
<?php endif; ?>

<?php if (!empty($cmpData)): ?>
<script>
(function() {
    const CMP_DATA   = <?php echo json_encode(array_values($cmpData), JSON_PRETTY_PRINT); ?>;
    const CMP_RATE   = <?php echo (float)$cmp_rate; ?>;
    const CMP_MONTHS = <?php
        $cmpAllMks = [];
        foreach ($cmpData as $cd) {
            foreach ($cd['months'] as $mk) {
                if (!in_array($mk, $cmpAllMks)) { $cmpAllMks[] = $mk; }
            }
        }
        sort($cmpAllMks);
        echo json_encode($cmpAllMks);
    ?>;
    const MN = {'01':'Ene','02':'Feb','03':'Mar','04':'Abr','05':'May','06':'Jun',
                '07':'Jul','08':'Ago','09':'Sep','10':'Oct','11':'Nov','12':'Dic'};
    function ml(mk) { const [y,m]=mk.split('-'); return (MN[m]||m)+' '+y.slice(2); }

    const COLORS = ['#1976D2','#388E3C','#F57C00'];
    const datasets = CMP_DATA.map((cd, i) => ({
        label: cd.label + (cd.src ? ' (borrador)' : ' (activo)'),
        data: CMP_MONTHS.map(mk => parseFloat(((cd.hours_by_month[mk]||0)*CMP_RATE).toFixed(2))),
        backgroundColor: COLORS[i % COLORS.length],
        borderColor: COLORS[i % COLORS.length],
        borderWidth: 2,
    }));

    const ctx = document.getElementById('fp-cmp-chart');
    if (ctx) {
        new Chart(ctx, {
            type: 'bar',
            data: { labels: CMP_MONTHS.map(ml), datasets },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { font: { size: 11 }, boxWidth: 14 } },
                    tooltip: {
                        callbacks: {
                            label: c => ' ' + c.dataset.label + ': $' + parseFloat(c.parsed.y).toLocaleString('en-US',{minimumFractionDigits:2})
                        }
                    }
                },
                scales: {
                    x: { ticks: { font: { size: 11 } } },
                    y: { ticks: { callback: v => '$'+Number(v).toLocaleString(), font: { size: 11 } },
                         title: { display: true, text: 'USD', font: { size: 11 } } }
                }
            }
        });
    }
})();
</script>
<?php endif; ?>

<?php echo $OUTPUT->footer(); ?>
