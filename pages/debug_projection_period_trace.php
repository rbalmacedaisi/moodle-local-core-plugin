<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/planning_manager.php');

use local_grupomakro_core\local\planning_manager;

global $DB, $PAGE, $OUTPUT;

$context = context_system::instance();
require_login();
require_capability('moodle/site:config', $context);

$PAGE->set_url('/local/grupomakro_core/pages/debug_projection_period_trace.php');
$PAGE->set_context($context);
$PAGE->set_title('Debug Projection Trace');
$PAGE->set_heading('Debug Projection Trace');
$PAGE->set_pagelayout('admin');

function dbg_period_label(int $idx): string {
    $romans = [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V', 6 => 'VI'];
    $n = $idx + 1;
    return 'P-' . ($romans[$n] ?? (string)$n);
}

function dbg_norm(string $text): string {
    $text = core_text::strtolower(trim($text));
    $text = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
    $text = preg_replace('/[^a-z0-9 ]+/', ' ', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

function dbg_student_planning_state(array $stu): array {
    $level = planning_manager::parse_semester_number($stu['currentSemConfig'] ?? '');
    if ($level < 1) {
        $level = 1;
    }
    $isB2 = planning_manager::is_bimestre_two($stu['currentSubperiodConfig'] ?? '');
    if ($isB2) {
        return [$level + 1, 1];
    }
    return [$level, 2];
}

function dbg_natural_idx(array $stu, array $subj): int {
    if (!empty($subj['isPriority'])) {
        return 0;
    }

    list($planningLevel, $planningBim) = dbg_student_planning_state($stu);

    $subjectSemester = isset($subj['semester']) ? (int)$subj['semester'] : 0;
    $subjectBim = isset($subj['bimestre']) ? (int)$subj['bimestre'] : 1;
    if ($subjectSemester <= 0) {
        return 0;
    }
    if ($subjectBim !== 2) {
        $subjectBim = 1;
    }

    $cohortAbs = (($planningLevel - 1) * 2) + $planningBim;
    $subjectAbs = (($subjectSemester - 1) * 2) + $subjectBim;
    $idx = $subjectAbs - $cohortAbs;
    if ($idx < 0) {
        return 0;
    }
    if ($idx > 5) {
        return 5;
    }
    return $idx;
}

echo $OUTPUT->header();

$periods = $DB->get_records('gmk_academic_periods', null, 'startdate DESC, id DESC', 'id,name,startdate,enddate');

$periodid = optional_param('periodid', 0, PARAM_INT);
$email = optional_param('email', 'caballerobritney073@gmail.com', PARAM_RAW_TRIMMED);
$idnumber = optional_param('idnumber', '8-1060-2345', PARAM_RAW_TRIMMED);
$subjectname = optional_param('subject', 'SEGURIDAD Y PRIMEROS AUXILIOS', PARAM_RAW_TRIMMED);
$subjectid_param = optional_param('subjectid', 0, PARAM_INT);
$userid_param = optional_param('userid', 0, PARAM_INT);

if ($periodid === 0 && !empty($periods)) {
    $first = reset($periods);
    $periodid = (int)$first->id;
}

echo '<style>
    .dbg-wrap{max-width:1500px;margin:16px auto;font-family:Consolas,Monaco,monospace}
    .dbg-card{background:#fff;border:1px solid #d6dbe1;border-radius:10px;padding:14px 16px;margin-bottom:14px}
    .dbg-title{font-size:18px;font-weight:700;color:#1f2937;margin:0 0 10px}
    .dbg-sub{font-size:14px;font-weight:700;color:#374151;margin:12px 0 8px}
    .dbg-grid{display:grid;grid-template-columns:repeat(4,minmax(180px,1fr));gap:10px}
    .dbg-kv{background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:8px}
    .dbg-k{font-size:11px;color:#6b7280;text-transform:uppercase}
    .dbg-v{font-size:13px;color:#111827;word-break:break-word}
    .dbg-pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700}
    .ok{background:#dcfce7;color:#166534}.warn{background:#fef3c7;color:#92400e}.bad{background:#fee2e2;color:#991b1b}.info{background:#dbeafe;color:#1e40af}
    .dbg-table-wrap{overflow:auto;max-height:460px}
    table.dbg-table{width:100%;border-collapse:collapse;font-size:12px}
    .dbg-table th,.dbg-table td{border:1px solid #e5e7eb;padding:6px 8px;vertical-align:top}
    .dbg-table th{background:#f3f4f6;position:sticky;top:0;z-index:1}
    .dbg-code{background:#0b1020;color:#d1d5db;border-radius:8px;padding:10px;overflow:auto;font-size:12px}
    .dbg-form input,.dbg-form select{padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;width:100%}
    .dbg-form label{font-size:11px;color:#4b5563;text-transform:uppercase;font-weight:700}
    .dbg-btn{padding:9px 14px;border:none;background:#2563eb;color:#fff;border-radius:8px;font-weight:700;cursor:pointer}
</style>';

echo '<div class="dbg-wrap">';
echo '<div class="dbg-card">';
echo '<h2 class="dbg-title">Projection Trace Debug</h2>';
echo '<form method="get" class="dbg-form">';
echo '<div class="dbg-grid">';
echo '<div><label>Periodo Horarios</label><select name="periodid">';
foreach ($periods as $p) {
    $sel = ((int)$p->id === (int)$periodid) ? 'selected' : '';
    echo '<option value="' . (int)$p->id . '" ' . $sel . '>' . s($p->name) . ' (ID ' . (int)$p->id . ')</option>';
}
echo '</select></div>';
echo '<div><label>Email</label><input type="text" name="email" value="' . s($email) . '"></div>';
echo '<div><label>Cedula/ID Number</label><input type="text" name="idnumber" value="' . s($idnumber) . '"></div>';
echo '<div><label>User ID (opcional)</label><input type="number" name="userid" value="' . (int)$userid_param . '"></div>';
echo '<div style="grid-column: span 3"><label>Asignatura (nombre)</label><input type="text" name="subject" value="' . s($subjectname) . '"></div>';
echo '<div><label>Subject ID (opcional)</label><input type="number" name="subjectid" value="' . (int)$subjectid_param . '"></div>';
echo '</div>';
echo '<div style="margin-top:12px"><button class="dbg-btn" type="submit">Ejecutar Debug</button></div>';
echo '</form>';
echo '</div>';

$user = null;
$users = [];
if ($userid_param > 0) {
    $u = $DB->get_record('user', ['id' => $userid_param, 'deleted' => 0], 'id,firstname,lastname,email,idnumber');
    if ($u) {
        $users[] = $u;
    }
} else {
    $wheres = ['deleted = 0'];
    $params = [];
    $ors = [];
    if ($email !== '') {
        $ors[] = 'email = :email';
        $params['email'] = $email;
    }
    if ($idnumber !== '') {
        $ors[] = 'idnumber = :idnumber';
        $params['idnumber'] = $idnumber;
    }
    if (!empty($ors)) {
        $wheres[] = '(' . implode(' OR ', $ors) . ')';
    }
    $users = $DB->get_records_select('user', implode(' AND ', $wheres), $params, 'id ASC', 'id,firstname,lastname,email,idnumber');
}

if (empty($users)) {
    echo html_writer::div('No se encontro estudiante con los criterios dados.', 'dbg-card');
    echo '</div>';
    echo $OUTPUT->footer();
    exit;
}

$user = reset($users);
if (count($users) > 1) {
    echo '<div class="dbg-card"><h3 class="dbg-sub">Coincidencias de usuario</h3><ul>';
    foreach ($users as $u) {
        echo '<li>ID ' . (int)$u->id . ' - ' . s(trim($u->firstname . ' ' . $u->lastname)) . ' - ' . s($u->email) . ' - ' . s($u->idnumber) . '</li>';
    }
    echo '</ul><div class="dbg-kv"><div class="dbg-k">Usando usuario para el trace</div><div class="dbg-v">ID ' . (int)$user->id . ' (' . s($user->email) . ')</div></div></div>';
}

$enrollmentsql = "SELECT llu.id as lluid, llu.learningplanid, llu.status,
                         lp.name as planname,
                         p.id as currentperiodid, p.name as currentperiodname,
                         sp.id as currentsubperiodid, sp.name as currentsubperiodname
                  FROM {local_learning_users} llu
                  JOIN {local_learning_plans} lp ON lp.id = llu.learningplanid
                  LEFT JOIN {local_learning_periods} p ON p.id = llu.currentperiodid
                  LEFT JOIN {local_learning_subperiods} sp ON sp.id = llu.currentsubperiodid
                  WHERE llu.userid = :uid AND llu.userrolename = 'student'
                  ORDER BY llu.id ASC";
$enrollments = $DB->get_records_sql($enrollmentsql, ['uid' => $user->id]);

echo '<div class="dbg-card">';
echo '<h3 class="dbg-sub">Estudiante</h3>';
echo '<div class="dbg-grid">';
echo '<div class="dbg-kv"><div class="dbg-k">User ID</div><div class="dbg-v">' . (int)$user->id . '</div></div>';
echo '<div class="dbg-kv"><div class="dbg-k">Nombre</div><div class="dbg-v">' . s(trim($user->firstname . ' ' . $user->lastname)) . '</div></div>';
echo '<div class="dbg-kv"><div class="dbg-k">Email</div><div class="dbg-v">' . s($user->email) . '</div></div>';
echo '<div class="dbg-kv"><div class="dbg-k">ID Number</div><div class="dbg-v">' . s($user->idnumber) . '</div></div>';
echo '</div>';
echo '<h3 class="dbg-sub">Suscripciones activas/presentes en local_learning_users</h3>';
echo '<div class="dbg-table-wrap"><table class="dbg-table"><thead><tr><th>LLU ID</th><th>Plan ID</th><th>Plan</th><th>Status</th><th>Current Period</th><th>Current Subperiod</th></tr></thead><tbody>';
if (!empty($enrollments)) {
    foreach ($enrollments as $e) {
        echo '<tr>';
        echo '<td>' . (int)$e->lluid . '</td>';
        echo '<td>' . (int)$e->learningplanid . '</td>';
        echo '<td>' . s($e->planname) . '</td>';
        echo '<td>' . s($e->status) . '</td>';
        echo '<td>' . s($e->currentperiodname ?: '-') . ' (' . (int)$e->currentperiodid . ')</td>';
        echo '<td>' . s($e->currentsubperiodname ?: '-') . ' (' . (int)$e->currentsubperiodid . ')</td>';
        echo '</tr>';
    }
} else {
    echo '<tr><td colspan="6">Sin registros en local_learning_users.</td></tr>';
}
echo '</tbody></table></div>';
echo '</div>';

// Reverse maps and resolution (same strategy as planning_manager::get_demand_data).
$reverseMaps = $DB->get_records('gmk_planning_period_maps', ['target_period_id' => $periodid], 'timemodified DESC, id DESC');
$periodOrder = [];
$orderedPeriods = $DB->get_records('gmk_academic_periods', null, 'startdate ASC, id ASC', 'id,startdate,name');
$ord = 0;
foreach ($orderedPeriods as $ap) {
    $periodOrder[(int)$ap->id] = $ord++;
}

$chosenMap = null;
$bestScore = PHP_INT_MAX;
$reverseRows = [];
foreach ($reverseMaps as $rm) {
    $baseid = (int)$rm->base_period_id;
    $rel = (int)$rm->relative_index;
    $score = 500;
    $delta = null;
    if (isset($periodOrder[$baseid]) && isset($periodOrder[$periodid])) {
        $delta = $periodOrder[$periodid] - $periodOrder[$baseid];
        $expected = $rel + 1;
        if ($delta <= 0) {
            $score = 1000 + abs($delta - $expected);
        } else {
            $score = abs($delta - $expected);
        }
    }
    $reverseRows[] = [
        'id' => (int)$rm->id,
        'base_period_id' => $baseid,
        'relative_index' => $rel,
        'target_period_id' => (int)$rm->target_period_id,
        'delta' => $delta,
        'score' => $score,
        'timemodified' => (int)$rm->timemodified
    ];
    if ($chosenMap === null || $score < $bestScore) {
        $chosenMap = $rm;
        $bestScore = $score;
    }
}

$effectivePeriodId = $periodid;
$selectedRelativeIndex = -1;
if ($chosenMap) {
    $effectivePeriodId = (int)$chosenMap->base_period_id;
    $selectedRelativeIndex = (int)$chosenMap->relative_index;
}

echo '<div class="dbg-card">';
echo '<h3 class="dbg-sub">Resolucion de periodo en Horarios</h3>';
echo '<div class="dbg-grid">';
echo '<div class="dbg-kv"><div class="dbg-k">Periodo seleccionado (Horarios)</div><div class="dbg-v">ID ' . (int)$periodid . '</div></div>';
echo '<div class="dbg-kv"><div class="dbg-k">Base efectiva</div><div class="dbg-v">ID ' . (int)$effectivePeriodId . '</div></div>';
echo '<div class="dbg-kv"><div class="dbg-k">relative_index</div><div class="dbg-v">' . (int)$selectedRelativeIndex . ' (' . ($selectedRelativeIndex >= 0 ? dbg_period_label($selectedRelativeIndex) : 'base/directo') . ')</div></div>';
echo '<div class="dbg-kv"><div class="dbg-k">Criterio</div><div class="dbg-v">min(score) entre reverse maps de target</div></div>';
echo '</div>';

echo '<h3 class="dbg-sub">Reverse Maps candidatos (target_period_id = ' . (int)$periodid . ')</h3>';
echo '<div class="dbg-table-wrap"><table class="dbg-table"><thead><tr><th>map_id</th><th>base_period_id</th><th>relative_index</th><th>label</th><th>delta(base->target)</th><th>score</th><th>chosen</th></tr></thead><tbody>';
if (!empty($reverseRows)) {
    foreach ($reverseRows as $r) {
        $ischosen = ($chosenMap && (int)$chosenMap->id === (int)$r['id']);
        echo '<tr>';
        echo '<td>' . (int)$r['id'] . '</td>';
        echo '<td>' . (int)$r['base_period_id'] . '</td>';
        echo '<td>' . (int)$r['relative_index'] . '</td>';
        echo '<td>' . s(dbg_period_label((int)$r['relative_index'])) . '</td>';
        echo '<td>' . s($r['delta'] === null ? 'n/a' : (string)$r['delta']) . '</td>';
        echo '<td>' . (int)$r['score'] . '</td>';
        echo '<td>' . ($ischosen ? '<span class="dbg-pill ok">SI</span>' : '<span class="dbg-pill bad">NO</span>') . '</td>';
        echo '</tr>';
    }
} else {
    echo '<tr><td colspan="7">No hay reverse maps para este target_period_id.</td></tr>';
}
echo '</tbody></table></div>';
echo '</div>';

// Pull planning data from effective base period.
$planningData = planning_manager::get_planning_data($effectivePeriodId);
$students = $planningData['students'] ?? [];
$subjectCandidates = [];
$normWanted = dbg_norm($subjectname);

foreach (($planningData['all_subjects'] ?? []) as $s) {
    $normName = dbg_norm((string)$s['name']);
    if ($subjectid_param > 0 && (int)$s['id'] === $subjectid_param) {
        $subjectCandidates[] = $s;
        continue;
    }
    if ($normWanted !== '' && ($normName === $normWanted || strpos($normName, $normWanted) !== false || strpos($normWanted, $normName) !== false)) {
        $subjectCandidates[] = $s;
    }
}

$chosenSubject = null;
if (!empty($subjectCandidates)) {
    foreach ($subjectCandidates as $c) {
        if (dbg_norm((string)$c['name']) === $normWanted) {
            $chosenSubject = $c;
            break;
        }
    }
    if (!$chosenSubject) {
        $chosenSubject = reset($subjectCandidates);
    }
}

if (!$chosenSubject && $subjectid_param > 0) {
    // Final fallback by course table.
    $course = $DB->get_record('course', ['id' => $subjectid_param], 'id,fullname', IGNORE_MISSING);
    if ($course) {
        $chosenSubject = ['id' => (int)$course->id, 'name' => (string)$course->fullname];
    }
}

echo '<div class="dbg-card">';
echo '<h3 class="dbg-sub">Asignatura objetivo</h3>';
if ($chosenSubject) {
    echo '<div class="dbg-grid">';
    echo '<div class="dbg-kv"><div class="dbg-k">Subject ID</div><div class="dbg-v">' . (int)$chosenSubject['id'] . '</div></div>';
    echo '<div class="dbg-kv"><div class="dbg-k">Nombre</div><div class="dbg-v">' . s($chosenSubject['name']) . '</div></div>';
    echo '<div class="dbg-kv"><div class="dbg-k">Busqueda</div><div class="dbg-v">' . s($subjectname) . '</div></div>';
    echo '<div class="dbg-kv"><div class="dbg-k">Coincidencias</div><div class="dbg-v">' . count($subjectCandidates) . '</div></div>';
    echo '</div>';
} else {
    echo '<div class="dbg-pill bad">No se encontro la asignatura en all_subjects del periodo base efectivo.</div>';
}
echo '</div>';

if (!$chosenSubject) {
    echo '<div class="dbg-card"><div class="dbg-code">No se puede continuar el trace sin subject ID resuelto.</div></div>';
    echo '</div>';
    echo $OUTPUT->footer();
    exit;
}

$subjectid = (int)$chosenSubject['id'];
$omittedMap = [];
foreach (($planningData['planning_projections'] ?? []) as $pp) {
    if ((int)$pp->status === 2) {
        $omittedMap[(int)$pp->courseid] = true;
    }
}
$subjectOmitted = !empty($omittedMap[$subjectid]);

$subjectDeferrals = $DB->get_records('gmk_academic_deferrals', ['academicperiodid' => $effectivePeriodId, 'courseid' => $subjectid], 'timemodified DESC, id DESC');
$deferralByCohort = [];
foreach ($subjectDeferrals as $d) {
    $key = "{$d->career} - {$d->shift} - {$d->current_level}";
    $deferralByCohort[$key] = (int)$d->target_period_index;
}

$studentRows = [];
foreach ($students as $stu) {
    if ((int)($stu['dbId'] ?? 0) !== (int)$user->id) {
        continue;
    }
    $studentRows[] = $stu;
}

echo '<div class="dbg-card">';
echo '<h3 class="dbg-sub">Deferrals de la asignatura en periodo base efectivo</h3>';
echo '<div class="dbg-kv"><div class="dbg-k">Registros</div><div class="dbg-v">' . count($subjectDeferrals) . '</div></div>';
echo '<div class="dbg-table-wrap"><table class="dbg-table"><thead><tr><th>id</th><th>career</th><th>shift</th><th>current_level</th><th>target_period_index</th><th>label</th></tr></thead><tbody>';
if (!empty($subjectDeferrals)) {
    foreach ($subjectDeferrals as $d) {
        echo '<tr>';
        echo '<td>' . (int)$d->id . '</td>';
        echo '<td>' . s($d->career) . '</td>';
        echo '<td>' . s($d->shift) . '</td>';
        echo '<td>' . s($d->current_level) . '</td>';
        echo '<td>' . (int)$d->target_period_index . '</td>';
        echo '<td>' . s(dbg_period_label((int)$d->target_period_index)) . '</td>';
        echo '</tr>';
    }
} else {
    echo '<tr><td colspan="6">Sin deferrals para esta asignatura.</td></tr>';
}
echo '</tbody></table></div>';
echo '<div style="margin-top:10px">';
echo 'Omitida en gmk_academic_planning (status=2): ' . ($subjectOmitted ? '<span class="dbg-pill bad">SI</span>' : '<span class="dbg-pill ok">NO</span>');
echo '</div>';
echo '</div>';

echo '<div class="dbg-card">';
echo '<h3 class="dbg-sub">Trace por fila de estudiante en planning_data (dbId=' . (int)$user->id . ')</h3>';
echo '<div class="dbg-table-wrap"><table class="dbg-table"><thead><tr><th>plan/career</th><th>cohortKey</th><th>subject_in_pending</th><th>isPriority</th><th>prereq_ok</th><th>semester</th><th>bimestre</th><th>naturalIdx</th><th>deferralIdx</th><th>resolvedIdx</th><th>periodFilter</th><th>included</th></tr></thead><tbody>';

if (!empty($studentRows)) {
    foreach ($studentRows as $stu) {
        $career = (string)($stu['career'] ?? '');
        $shift = (string)($stu['shift'] ?? 'Sin Jornada');
        $cohortKey = planning_manager::build_cohort_key($career, $shift, $stu);

        $subjectPending = null;
        foreach (($stu['pendingSubjects'] ?? []) as $ps) {
            if ((int)($ps['id'] ?? 0) === $subjectid) {
                $subjectPending = $ps;
                break;
            }
            if (dbg_norm((string)($ps['name'] ?? '')) === dbg_norm((string)$chosenSubject['name'])) {
                $subjectPending = $ps;
                break;
            }
        }

        $naturalIdx = null;
        $defIdx = null;
        $resolvedIdx = null;
        $passesPeriodFilter = false;
        $included = false;

        if ($subjectPending) {
            $naturalIdx = dbg_natural_idx($stu, $subjectPending);
            $defIdx = $deferralByCohort[$cohortKey] ?? -1;
            $resolvedIdx = ($defIdx >= 0) ? $defIdx : $naturalIdx;
            if ($selectedRelativeIndex >= 0) {
                $passesPeriodFilter = ($resolvedIdx === $selectedRelativeIndex);
            } else {
                $passesPeriodFilter = ($resolvedIdx === 0);
            }
            $included = !empty($subjectPending['isPreRequisiteMet']) && !$subjectOmitted && $passesPeriodFilter;
        }

        echo '<tr>';
        echo '<td>' . s($career) . '</td>';
        echo '<td>' . s($cohortKey) . '</td>';
        echo '<td>' . ($subjectPending ? '<span class="dbg-pill ok">SI</span>' : '<span class="dbg-pill bad">NO</span>') . '</td>';
        echo '<td>' . ($subjectPending ? (!empty($subjectPending['isPriority']) ? '1' : '0') : '-') . '</td>';
        echo '<td>' . ($subjectPending ? (!empty($subjectPending['isPreRequisiteMet']) ? '1' : '0') : '-') . '</td>';
        echo '<td>' . ($subjectPending ? (int)($subjectPending['semester'] ?? 0) : '-') . '</td>';
        echo '<td>' . ($subjectPending ? (int)($subjectPending['bimestre'] ?? 0) : '-') . '</td>';
        echo '<td>' . ($subjectPending ? (int)$naturalIdx . ' (' . s(dbg_period_label((int)$naturalIdx)) . ')' : '-') . '</td>';
        echo '<td>' . ($subjectPending ? (int)$defIdx . (($defIdx >= 0) ? ' (' . s(dbg_period_label((int)$defIdx)) . ')' : '') : '-') . '</td>';
        echo '<td>' . ($subjectPending ? (int)$resolvedIdx . ' (' . s(dbg_period_label((int)$resolvedIdx)) . ')' : '-') . '</td>';
        echo '<td>' . ($subjectPending ? ($passesPeriodFilter ? '<span class="dbg-pill ok">PASS</span>' : '<span class="dbg-pill bad">FAIL</span>') : '-') . '</td>';
        echo '<td>' . ($subjectPending ? ($included ? '<span class="dbg-pill ok">SI</span>' : '<span class="dbg-pill bad">NO</span>') : '-') . '</td>';
        echo '</tr>';
    }
} else {
    echo '<tr><td colspan="12">No aparece en planning_data para el base efectivo. Revisar status en local_learning_users o filtros de get_planning_data.</td></tr>';
}

echo '</tbody></table></div>';
echo '</div>';

// Cross-check against actual demand output.
$demandData = planning_manager::get_demand_data($periodid);
$tree = $demandData['demand_tree'] ?? [];
$studenttoken1 = (string)$user->id;
$studenttoken2 = (string)$user->idnumber;
$occurrences = [];
foreach ($tree as $career => $shifts) {
    if (!is_array($shifts)) {
        continue;
    }
    foreach ($shifts as $shift => $levels) {
        if (!is_array($levels)) {
            continue;
        }
        foreach ($levels as $levelKey => $levelData) {
            if (empty($levelData['course_counts']) || !is_array($levelData['course_counts'])) {
                continue;
            }
            foreach ($levelData['course_counts'] as $cid => $cdata) {
                if ((int)$cid !== $subjectid) {
                    continue;
                }
                $studentslist = $cdata['students'] ?? [];
                $hasStudent = in_array($studenttoken2, $studentslist, true) || in_array($studenttoken1, $studentslist, true);
                $occurrences[] = [
                    'career' => $career,
                    'shift' => $shift,
                    'level' => $levelKey,
                    'count' => (int)($cdata['count'] ?? 0),
                    'hasstudent' => $hasStudent ? 1 : 0,
                    'students_raw' => $studentslist
                ];
            }
        }
    }
}

echo '<div class="dbg-card">';
echo '<h3 class="dbg-sub">Verificacion final en get_demand_data(periodo seleccionado)</h3>';
echo '<div class="dbg-kv"><div class="dbg-k">Apariciones de la asignatura en demand_tree</div><div class="dbg-v">' . count($occurrences) . '</div></div>';
echo '<div class="dbg-table-wrap"><table class="dbg-table"><thead><tr><th>career</th><th>shift</th><th>level bucket</th><th>course count</th><th>estudiante presente</th><th>students[]</th></tr></thead><tbody>';
if (!empty($occurrences)) {
    foreach ($occurrences as $o) {
        echo '<tr>';
        echo '<td>' . s($o['career']) . '</td>';
        echo '<td>' . s($o['shift']) . '</td>';
        echo '<td>' . s($o['level']) . '</td>';
        echo '<td>' . (int)$o['count'] . '</td>';
        echo '<td>' . ($o['hasstudent'] ? '<span class="dbg-pill ok">SI</span>' : '<span class="dbg-pill bad">NO</span>') . '</td>';
        echo '<td><pre style="margin:0;white-space:pre-wrap">' . s(json_encode($o['students_raw'], JSON_UNESCAPED_UNICODE)) . '</pre></td>';
        echo '</tr>';
    }
} else {
    echo '<tr><td colspan="6">La asignatura no existe en demand_tree para este periodo seleccionado.</td></tr>';
}
echo '</tbody></table></div>';
echo '</div>';

// Cross-check against what the scheduler board consumes (get_generated_schedules).
$boardRows = [];
$boardError = '';
try {
    $rawBoard = \local_grupomakro_core\external\admin\scheduler::get_generated_schedules($periodid, true);
    if (!is_array($rawBoard)) {
        $rawBoard = [];
    }

    $wantedNorm = dbg_norm((string)$chosenSubject['name']);
    foreach ($rawBoard as $row) {
        $coreId = (int)($row['corecourseid'] ?? 0);
        $linkId = (int)($row['courseid'] ?? 0);
        $nameNorm = dbg_norm((string)($row['subjectName'] ?? ''));
        $sameSubject = ($coreId === $subjectid) || ($linkId === $subjectid) || ($wantedNorm !== '' && $nameNorm === $wantedNorm);
        if (!$sameSubject) {
            continue;
        }

        $studentsRaw = isset($row['studentIds']) && is_array($row['studentIds']) ? $row['studentIds'] : [];
        $hasStudent = in_array((string)$user->id, $studentsRaw, true) || in_array((string)$user->idnumber, $studentsRaw, true);

        $boardRows[] = [
            'id' => (int)($row['id'] ?? 0),
            'subject' => (string)($row['subjectName'] ?? ''),
            'shift' => (string)($row['shift'] ?? ''),
            'day' => (string)($row['day'] ?? ''),
            'start' => (string)($row['start'] ?? ''),
            'end' => (string)($row['end'] ?? ''),
            'corecourseid' => $coreId,
            'courseid' => $linkId,
            'studentcount' => (int)($row['studentCount'] ?? 0),
            'prereg' => (int)($row['preRegisteredCount'] ?? 0),
            'queue' => (int)($row['queuedCount'] ?? 0),
            'enrolled' => (int)($row['enrolledCount'] ?? 0),
            'pendingenroll' => (int)($row['pendingEnrollmentCount'] ?? 0),
            'approved' => (int)($row['approved'] ?? 0),
            'isexternal' => !empty($row['isExternal']) ? 1 : 0,
            'hasstudent' => $hasStudent ? 1 : 0,
            'studentsraw' => $studentsRaw
        ];
    }
} catch (Throwable $t) {
    $boardError = $t->getMessage();
}

echo '<div class="dbg-card">';
echo '<h3 class="dbg-sub">Verificacion en fuente del Tablero (scheduler::get_generated_schedules)</h3>';
if ($boardError !== '') {
    echo '<div class="dbg-pill bad">Error al obtener datos del tablero: ' . s($boardError) . '</div>';
} else {
    echo '<div class="dbg-kv"><div class="dbg-k">Filas de esta asignatura en Tablero</div><div class="dbg-v">' . count($boardRows) . '</div></div>';
    echo '<div class="dbg-table-wrap"><table class="dbg-table"><thead><tr><th>classid</th><th>subject</th><th>shift</th><th>day</th><th>time</th><th>studentCount</th><th>preReg</th><th>queue</th><th>enrolled</th><th>approved</th><th>external</th><th>estudiante presente</th></tr></thead><tbody>';
    if (!empty($boardRows)) {
        foreach ($boardRows as $br) {
            echo '<tr>';
            echo '<td>' . (int)$br['id'] . '</td>';
            echo '<td>' . s($br['subject']) . '</td>';
            echo '<td>' . s($br['shift']) . '</td>';
            echo '<td>' . s($br['day']) . '</td>';
            echo '<td>' . s($br['start']) . ' - ' . s($br['end']) . '</td>';
            echo '<td>' . (int)$br['studentcount'] . '</td>';
            echo '<td>' . (int)$br['prereg'] . '</td>';
            echo '<td>' . (int)$br['queue'] . '</td>';
            echo '<td>' . (int)$br['enrolled'] . '</td>';
            echo '<td>' . (int)$br['approved'] . '</td>';
            echo '<td>' . ($br['isexternal'] ? '<span class="dbg-pill warn">SI</span>' : '<span class="dbg-pill ok">NO</span>') . '</td>';
            echo '<td>' . ($br['hasstudent'] ? '<span class="dbg-pill bad">SI</span>' : '<span class="dbg-pill ok">NO</span>') . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="12">No hay filas en el tablero para esta asignatura.</td></tr>';
    }
    echo '</tbody></table></div>';
}
echo '</div>';

// Source-level trace for this specific student in class-level tables.
$subjectClasses = $DB->get_records_select(
    'gmk_class',
    'periodid = :pid AND (corecourseid = :coreid OR courseid = :linkid)',
    ['pid' => (int)$periodid, 'coreid' => (int)$subjectid, 'linkid' => (int)$subjectid],
    'id DESC',
    'id,name,periodid,corecourseid,courseid,groupid,approved'
);

$sourceTrace = [];
foreach ($subjectClasses as $cls) {
    $classid = (int)$cls->id;
    $inPrereg = $DB->record_exists('gmk_class_pre_registration', ['classid' => $classid, 'userid' => (int)$user->id]);
    $inQueue = $DB->record_exists('gmk_class_queue', ['classid' => $classid, 'userid' => (int)$user->id]);
    $inProgre = $DB->record_exists('gmk_course_progre', ['classid' => $classid, 'userid' => (int)$user->id]);
    $inGroup = false;
    if (!empty($cls->groupid)) {
        $inGroup = $DB->record_exists('groups_members', ['groupid' => (int)$cls->groupid, 'userid' => (int)$user->id]);
    }
    if ($inPrereg || $inQueue || $inProgre || $inGroup) {
        $sourceTrace[] = [
            'classid' => $classid,
            'subject' => (string)$cls->name,
            'approved' => (int)$cls->approved,
            'inprereg' => $inPrereg ? 1 : 0,
            'inqueue' => $inQueue ? 1 : 0,
            'inprogre' => $inProgre ? 1 : 0,
            'ingroup' => $inGroup ? 1 : 0
        ];
    }
}

echo '<div class="dbg-card">';
echo '<h3 class="dbg-sub">Rastro de fuentes por clase (por que el Tablero lo muestra)</h3>';
echo '<div class="dbg-table-wrap"><table class="dbg-table"><thead><tr><th>classid</th><th>subject</th><th>approved</th><th>pre_registration</th><th>queue</th><th>course_progre</th><th>groups_members</th></tr></thead><tbody>';
if (!empty($sourceTrace)) {
    foreach ($sourceTrace as $sr) {
        echo '<tr>';
        echo '<td>' . (int)$sr['classid'] . '</td>';
        echo '<td>' . s($sr['subject']) . '</td>';
        echo '<td>' . (int)$sr['approved'] . '</td>';
        echo '<td>' . ($sr['inprereg'] ? '<span class="dbg-pill bad">SI</span>' : '<span class="dbg-pill ok">NO</span>') . '</td>';
        echo '<td>' . ($sr['inqueue'] ? '<span class="dbg-pill bad">SI</span>' : '<span class="dbg-pill ok">NO</span>') . '</td>';
        echo '<td>' . ($sr['inprogre'] ? '<span class="dbg-pill bad">SI</span>' : '<span class="dbg-pill ok">NO</span>') . '</td>';
        echo '<td>' . ($sr['ingroup'] ? '<span class="dbg-pill bad">SI</span>' : '<span class="dbg-pill ok">NO</span>') . '</td>';
        echo '</tr>';
    }
} else {
    echo '<tr><td colspan="7">El estudiante no aparece en pre-registro, cola, progreso ni grupo para clases de esta asignatura en el periodo.</td></tr>';
}
echo '</tbody></table></div>';
echo '</div>';

echo '<div class="dbg-card">';
echo '<h3 class="dbg-sub">Resumen rapido del caso</h3>';
echo '<div class="dbg-code">';
echo 'Periodo seleccionado (Horarios): ' . (int)$periodid . PHP_EOL;
echo 'Base efectiva resuelta: ' . (int)$effectivePeriodId . PHP_EOL;
echo 'relative_index seleccionado: ' . (int)$selectedRelativeIndex . ' (' . ($selectedRelativeIndex >= 0 ? dbg_period_label($selectedRelativeIndex) : 'base/directo') . ')' . PHP_EOL;
echo 'Asignatura: ' . s($chosenSubject['name']) . ' (ID ' . $subjectid . ')' . PHP_EOL;
echo 'Asignatura omitida status=2: ' . ($subjectOmitted ? 'SI' : 'NO') . PHP_EOL;
echo 'Filas de planning_data del estudiante: ' . count($studentRows) . PHP_EOL;
echo 'Apariciones de la asignatura en demand_tree: ' . count($occurrences) . PHP_EOL;
echo 'Filas de la asignatura en datos del Tablero: ' . count($boardRows) . PHP_EOL;
echo 'Clases donde el estudiante existe en tablas de clase (pre/queue/progre/group): ' . count($sourceTrace) . PHP_EOL;
echo '</div>';
echo '</div>';

echo '</div>';
echo $OUTPUT->footer();
