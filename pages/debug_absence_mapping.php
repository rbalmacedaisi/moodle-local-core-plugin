<?php
/**
 * Debug page: Attendance mapping for absence_dashboard
 *
 * URL params:
 *   classid=<int>  — inspect one specific class (optional; shows picker if omitted)
 *   period=<str>   — filter class list by period code (optional)
 */

$config_path = __DIR__ . '/../../config.php';
if (!file_exists($config_path)) $config_path = __DIR__ . '/../../../config.php';
if (!file_exists($config_path)) $config_path = __DIR__ . '/../../../../config.php';
require_once($config_path);
require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_absence_mapping.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Debug: Mapeo de Asistencia');
$PAGE->set_heading('Debug: Mapeo de Asistencia');
$PAGE->set_pagelayout('admin');

echo $OUTPUT->header();

$classid    = optional_param('classid', 0, PARAM_INT);
$period_flt = optional_param('period', '', PARAM_TEXT);

// ── CSS ────────────────────────────────────────────────────────────────────────
echo '<style>
body { font-family: monospace; font-size: 13px; }
.dbg-section { background:#f8fafc; border:1px solid #cbd5e1; border-radius:6px;
               padding:16px; margin:16px 0; }
.dbg-section h3 { margin:0 0 10px; color:#1e40af; font-size:14px; }
.dbg-ok   { color:#16a34a; font-weight:bold; }
.dbg-warn { color:#d97706; font-weight:bold; }
.dbg-err  { color:#dc2626; font-weight:bold; }
table.dbg { border-collapse:collapse; width:100%; margin-top:8px; }
table.dbg th { background:#e2e8f0; padding:4px 8px; text-align:left;
               border:1px solid #cbd5e1; white-space:nowrap; }
table.dbg td { padding:4px 8px; border:1px solid #e2e8f0; vertical-align:top; }
table.dbg tr:hover td { background:#f0f9ff; }
.chip { display:inline-block; padding:1px 6px; border-radius:10px; font-size:11px; }
.chip-green { background:#dcfce7; color:#15803d; }
.chip-red   { background:#fee2e2; color:#b91c1c; }
.chip-gray  { background:#f1f5f9; color:#475569; }
.chip-blue  { background:#dbeafe; color:#1d4ed8; }
pre.sql { background:#1e293b; color:#e2e8f0; padding:10px 14px; border-radius:4px;
          white-space:pre-wrap; word-break:break-all; margin:8px 0; font-size:12px; }
.form-row { display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap;
            padding:12px; background:#fff; border:1px solid #cbd5e1; border-radius:6px; }
</style>';

$now = time();

// ── Class picker ───────────────────────────────────────────────────────────────
echo '<div class="dbg-section">';
echo '<h3>Seleccionar clase</h3>';
echo '<form method="get" class="form-row">';
echo '<div><label>Class ID<br><input type="number" name="classid" value="' . $classid . '" style="width:120px;padding:4px;"></label></div>';
echo '<div><label>Filtrar por período<br><input type="text" name="period" value="' . s($period_flt) . '" placeholder="ej: 2025-1" style="width:150px;padding:4px;"></label></div>';
echo '<div style="padding-bottom:4px"><button type="submit" style="padding:6px 16px;">Buscar</button></div>';
echo '</form>';

// List recent classes with attendancemoduleid > 0
$where_period = '';
$params_list  = [];
if ($period_flt !== '') {
    $where_period = " AND gp.code LIKE :pflt";
    $params_list['pflt'] = '%' . $period_flt . '%';
}
$class_list = $DB->get_records_sql(
    "SELECT gc.id, gc.coursename, gc.groupid, gc.attendancemoduleid,
            gc.approved, gp.code AS period_code
       FROM {gmk_class} gc
       LEFT JOIN {gmk_academic_period} gp ON gp.id = gc.academicperiodid
      WHERE gc.attendancemoduleid > 0 $where_period
      ORDER BY gc.id DESC
      LIMIT 80",
    $params_list
);
if ($class_list) {
    echo '<table class="dbg" style="margin-top:12px;">';
    echo '<tr><th>ID</th><th>Nombre</th><th>Período</th><th>groupid</th><th>attendancemoduleid</th><th></th></tr>';
    foreach ($class_list as $c) {
        $sel = ((int)$c->id === $classid) ? ' style="background:#dbeafe;"' : '';
        $url = (new moodle_url('/local/grupomakro_core/pages/debug_absence_mapping.php',
                               ['classid' => $c->id, 'period' => $period_flt]))->out(false);
        echo "<tr$sel><td>{$c->id}</td><td>" . s($c->coursename) . "</td><td>" . s($c->period_code) . "</td>"
            . "<td>{$c->groupid}</td><td>{$c->attendancemoduleid}</td>"
            . "<td><a href=\"$url\">Inspeccionar</a></td></tr>";
    }
    echo '</table>';
}
echo '</div>';

if (!$classid) {
    echo $OUTPUT->footer();
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════════
// DEEP INSPECTION FOR THE SELECTED CLASS
// ═══════════════════════════════════════════════════════════════════════════════

// ── 1. gmk_class record ───────────────────────────────────────────────────────
echo '<div class="dbg-section">';
echo '<h3>1. Registro gmk_class (id=' . $classid . ')</h3>';

$cls = $DB->get_record('gmk_class', ['id' => $classid]);
if (!$cls) {
    echo '<span class="dbg-err">Clase no encontrada.</span>';
    echo '</div>';
    echo $OUTPUT->footer();
    exit;
}

echo '<table class="dbg"><tr><th>Campo</th><th>Valor</th><th>Nota</th></tr>';
$fields_of_interest = [
    'id', 'coursename', 'groupid', 'attendancemoduleid', 'approved',
    'initdate', 'enddate', 'academicperiodid',
];
foreach ($fields_of_interest as $f) {
    $val  = $cls->$f ?? '(no existe)';
    $note = '';
    if ($f === 'attendancemoduleid') {
        $note = ((int)$val > 0)
            ? '<span class="dbg-ok">OK — tiene módulo de asistencia</span>'
            : '<span class="dbg-err">0 — SIN módulo, el dashboard lo omite</span>';
    }
    if ($f === 'groupid') {
        $note = ((int)$val === 0)
            ? '<span class="chip chip-gray">0 = sin grupo (todas las sesiones groupid=0)</span>'
            : '<span class="chip chip-blue">grupo específico — las sesiones deben tener groupid=' . (int)$val . ' o 0</span>';
    }
    if ($f === 'initdate' || $f === 'enddate') {
        $note = is_numeric($val) ? date('Y-m-d H:i', (int)$val) : '';
    }
    echo "<tr><td><b>$f</b></td><td>" . s((string)$val) . "</td><td>$note</td></tr>";
}
echo '</table>';
echo '</div>';

$cmid = (int)$cls->attendancemoduleid;
$grpid = (int)$cls->groupid;

// ── 2. course_modules → attendance instance ────────────────────────────────────
echo '<div class="dbg-section">';
echo '<h3>2. course_modules y attendance instance</h3>';

$cm = $DB->get_record('course_modules', ['id' => $cmid]);
if (!$cm) {
    echo '<span class="dbg-err">No existe course_modules con id=' . $cmid . ' — attendancemoduleid roto.</span>';
    echo '</div>';
    echo $OUTPUT->footer();
    exit;
}

$att_instance = $DB->get_record('attendance', ['id' => $cm->instance]);
echo '<table class="dbg">';
echo '<tr><th>course_modules.id</th><th>cm.module</th><th>cm.instance</th><th>cm.course</th><th>attendance.name</th></tr>';
echo '<tr><td>' . $cm->id . '</td><td>' . $cm->module . '</td><td>' . $cm->instance . '</td><td>' . $cm->course . '</td>'
    . '<td>' . s($att_instance->name ?? '<span class="dbg-err">NO ENCONTRADO</span>') . '</td></tr>';
echo '</table>';

$att_id = (int)$cm->instance;
echo '</div>';

// ── 3. attendance_statuses ─────────────────────────────────────────────────────
echo '<div class="dbg-section">';
echo '<h3>3. attendance_statuses para esta instancia de asistencia (attendanceid=' . $att_id . ')</h3>';

$statuses = $DB->get_records_sql(
    "SELECT id, attendanceid, acronym, description, grade, setnumber, deleted, visible
       FROM {attendance_statuses}
      WHERE attendanceid = :aid
      ORDER BY setnumber, grade DESC",
    ['aid' => $att_id]
);

if (!$statuses) {
    // Fall back to setnumber=0 (global statuses)
    echo '<span class="dbg-warn">Sin estatutos propios — buscando estatutos globales (setnumber=0)…</span><br>';
    $statuses = $DB->get_records_sql(
        "SELECT id, attendanceid, acronym, description, grade, setnumber, deleted, visible
           FROM {attendance_statuses}
          WHERE setnumber = 0
          ORDER BY grade DESC
          LIMIT 30",
        []
    );
}

if ($statuses) {
    echo '<table class="dbg">';
    echo '<tr><th>id</th><th>acronym</th><th>description</th><th>grade</th><th>setnumber</th><th>deleted</th><th>visible</th><th>¿Es ausencia?</th></tr>';
    foreach ($statuses as $st) {
        $is_abs = '';
        if ($st->deleted) {
            $is_abs = '<span class="chip chip-gray">eliminado</span>';
        } elseif ($st->grade === null) {
            $is_abs = '<span class="chip chip-red">grade=NULL — PROBLEMA: no pasa grade&lt;=0</span>';
        } elseif ((float)$st->grade <= 0) {
            $is_abs = '<span class="chip chip-red">SÍ ausencia (grade=' . $st->grade . ')</span>';
        } else {
            $is_abs = '<span class="chip chip-green">No ausencia (grade=' . $st->grade . ')</span>';
        }
        echo '<tr>'
            . '<td>' . $st->id . '</td>'
            . '<td><b>' . s($st->acronym) . '</b></td>'
            . '<td>' . s($st->description) . '</td>'
            . '<td>' . ($st->grade ?? '<span class="dbg-err">NULL</span>') . '</td>'
            . '<td>' . $st->setnumber . '</td>'
            . '<td>' . ($st->deleted ? 'SÍ' : 'no') . '</td>'
            . '<td>' . ($st->visible ? 'sí' : 'no') . '</td>'
            . "<td>$is_abs</td>"
            . '</tr>';
    }
    echo '</table>';
    echo '<p style="color:#64748b;font-size:12px;">El dashboard cuenta registros donde <code>grade IS NULL OR grade &lt;= 0</code>.</p>';
} else {
    echo '<span class="dbg-err">No hay attendance_statuses para esta instancia ni globales.</span>';
}
echo '</div>';

// ── 4. attendance_sessions ─────────────────────────────────────────────────────
echo '<div class="dbg-section">';
echo '<h3>4. Sesiones de asistencia (attendance_sessions) — últimas 20 y conteo por groupid</h3>';

// Count by groupid
$sess_counts = $DB->get_records_sql(
    "SELECT groupid, COUNT(*) AS total,
            SUM(CASE WHEN sessdate < :now THEN 1 ELSE 0 END) AS past
       FROM {attendance_sessions}
      WHERE attendanceid = :aid
      GROUP BY groupid
      ORDER BY groupid",
    ['aid' => $att_id, 'now' => $now]
);

if ($sess_counts) {
    echo '<table class="dbg">';
    echo '<tr><th>groupid en sesión</th><th>Total sesiones</th><th>Pasadas (&lt;now)</th><th>¿Coincide con gc.groupid=' . $grpid . '?</th></tr>';
    foreach ($sess_counts as $sc) {
        $match = ((int)$sc->groupid === $grpid)
            ? '<span class="dbg-ok">SÍ coincide exacto</span>'
            : ((int)$sc->groupid === 0
                ? '<span class="dbg-warn">groupid=0 (global) — el filtro s.groupid=gc.groupid lo OMITE si gc.groupid &gt; 0</span>'
                : '<span class="dbg-err">NO coincide</span>');
        echo '<tr><td>' . $sc->groupid . '</td><td>' . $sc->total . '</td><td>' . $sc->past . '</td><td>' . $match . '</td></tr>';
    }
    echo '</table>';
} else {
    echo '<span class="dbg-err">No hay sesiones para attendanceid=' . $att_id . '</span>';
}

// Sample sessions
$sample_sess = $DB->get_records_sql(
    "SELECT id, sessdate, groupid, description
       FROM {attendance_sessions}
      WHERE attendanceid = :aid
      ORDER BY sessdate DESC
      LIMIT 10",
    ['aid' => $att_id]
);

if ($sample_sess) {
    echo '<p style="margin-top:12px;font-weight:bold;color:#475569;">Últimas 10 sesiones:</p>';
    echo '<table class="dbg">';
    echo '<tr><th>id</th><th>sessdate</th><th>groupid</th><th>description</th><th>¿Pasada?</th></tr>';
    foreach ($sample_sess as $ss) {
        $past_chip = ($ss->sessdate < $now)
            ? '<span class="chip chip-green">sí</span>'
            : '<span class="chip chip-gray">no (futura)</span>';
        echo '<tr><td>' . $ss->id . '</td><td>' . date('Y-m-d H:i', (int)$ss->sessdate) . '</td>'
            . '<td>' . $ss->groupid . '</td><td>' . s($ss->description) . '</td><td>' . $past_chip . '</td></tr>';
    }
    echo '</table>';
}
echo '</div>';

// ── 5. attendance_log sample ───────────────────────────────────────────────────
echo '<div class="dbg-section">';
echo '<h3>5. Registros de attendance_log — muestra de 20 entradas para sesiones de esta instancia</h3>';

// Get session IDs for this attendance
$sess_ids = $DB->get_fieldset_sql(
    "SELECT id FROM {attendance_sessions} WHERE attendanceid = :aid ORDER BY sessdate DESC LIMIT 50",
    ['aid' => $att_id]
);

if ($sess_ids) {
    [$ssinsql, $ssinp] = $DB->get_in_or_equal($sess_ids, SQL_PARAMS_NAMED, 'ss');
    $logs = $DB->get_records_sql(
        "SELECT l.id, l.sessionid, l.studentid, l.statusid,
                ast.acronym, ast.description AS status_desc, ast.grade,
                s.sessdate, s.groupid AS sess_groupid
           FROM {attendance_log} l
           JOIN {attendance_sessions} s  ON s.id = l.sessionid
           LEFT JOIN {attendance_statuses} ast ON ast.id = l.statusid
          WHERE l.sessionid $ssinsql
          ORDER BY s.sessdate DESC, l.studentid
          LIMIT 30",
        $ssinp
    );

    if ($logs) {
        echo '<table class="dbg">';
        echo '<tr><th>log.id</th><th>sessionid</th><th>sessdate</th><th>sess.groupid</th>'
            . '<th>studentid</th><th>statusid</th><th>acronym</th><th>grade</th><th>¿Ausencia?</th></tr>';
        foreach ($logs as $lg) {
            $abs_chip = '';
            if ($lg->grade === null) {
                $abs_chip = '<span class="chip chip-red">grade NULL — no detectado por dashboard</span>';
            } elseif ((float)$lg->grade <= 0) {
                $abs_chip = '<span class="chip chip-red">SÍ ausencia</span>';
            } else {
                $abs_chip = '<span class="chip chip-green">Presente (grade=' . $lg->grade . ')</span>';
            }
            echo '<tr><td>' . $lg->id . '</td><td>' . $lg->sessionid . '</td>'
                . '<td>' . date('Y-m-d', (int)$lg->sessdate) . '</td>'
                . '<td>' . $lg->sess_groupid . '</td>'
                . '<td>' . $lg->studentid . '</td>'
                . '<td>' . $lg->statusid . '</td>'
                . '<td><b>' . s($lg->acronym ?? '?') . '</b></td>'
                . '<td>' . ($lg->grade ?? '<span class="dbg-err">NULL</span>') . '</td>'
                . "<td>$abs_chip</td>"
                . '</tr>';
        }
        echo '</table>';
    } else {
        echo '<span class="dbg-warn">No hay registros en attendance_log para las sesiones de esta instancia.<br>'
            . 'Esto significa que el docente aún no ha registrado asistencia.</span>';
    }
} else {
    echo '<span class="dbg-warn">No hay sesiones en attendance_sessions para esta instancia.</span>';
}
echo '</div>';

// ── 6. gmk_course_progre for this class ───────────────────────────────────────
echo '<div class="dbg-section">';
echo '<h3>6. gmk_course_progre — estudiantes matriculados en esta clase</h3>';

$progre_counts = $DB->get_records_sql(
    "SELECT status, COUNT(*) AS total
       FROM {gmk_course_progre}
      WHERE classid = :cid
      GROUP BY status
      ORDER BY status",
    ['cid' => $classid]
);

if ($progre_counts) {
    echo '<table class="dbg">';
    echo '<tr><th>status</th><th>total estudiantes</th><th>¿Incluido en IN(1,2,3)?</th></tr>';
    foreach ($progre_counts as $pc) {
        $included = in_array((int)$pc->status, [1, 2, 3])
            ? '<span class="dbg-ok">SÍ — se cuenta para ausencias</span>'
            : '<span class="dbg-err">NO — excluido del conteo</span>';
        echo '<tr><td>' . $pc->status . '</td><td>' . $pc->total . '</td><td>' . $included . '</td></tr>';
    }
    echo '</table>';
} else {
    echo '<span class="dbg-err">No hay registros en gmk_course_progre para classid=' . $classid . '</span>';
}
echo '</div>';

// ── 7. Simulate the exact absence query ───────────────────────────────────────
echo '<div class="dbg-section">';
echo '<h3>7. Simulación de la consulta de ausencias del dashboard</h3>';

// Query A: original (grade <= 0)
$sql_orig = "SELECT gc.id AS classid, COUNT(l.id) AS cnt
               FROM {gmk_class} gc
               JOIN {course_modules} cm ON cm.id = gc.attendancemoduleid
               JOIN {attendance_sessions} s ON s.attendanceid = cm.instance
                    AND s.groupid = gc.groupid AND s.sessdate < :nowts
               JOIN {attendance_log} l ON l.sessionid = s.id
               JOIN {attendance_statuses} ast ON ast.id = l.statusid AND ast.grade <= 0
               JOIN {gmk_course_progre} gcp ON gcp.classid = gc.id
                    AND gcp.userid = l.studentid AND gcp.status IN (1,2,3)
              WHERE gc.id = :cid AND gc.attendancemoduleid > 0
              GROUP BY gc.id";

// Query B: fixed (grade IS NULL OR grade <= 0)
$sql_fixed = "SELECT gc.id AS classid, COUNT(l.id) AS cnt
               FROM {gmk_class} gc
               JOIN {course_modules} cm ON cm.id = gc.attendancemoduleid
               JOIN {attendance_sessions} s ON s.attendanceid = cm.instance
                    AND s.groupid = gc.groupid AND s.sessdate < :nowts
               JOIN {attendance_log} l ON l.sessionid = s.id
               JOIN {attendance_statuses} ast ON ast.id = l.statusid AND (ast.grade IS NULL OR ast.grade <= 0)
               JOIN {gmk_course_progre} gcp ON gcp.classid = gc.id
                    AND gcp.userid = l.studentid AND gcp.status IN (1,2,3)
              WHERE gc.id = :cid AND gc.attendancemoduleid > 0
              GROUP BY gc.id";

// Query C: fixed + groupid OR 0
$sql_grp = "SELECT gc.id AS classid, COUNT(l.id) AS cnt
               FROM {gmk_class} gc
               JOIN {course_modules} cm ON cm.id = gc.attendancemoduleid
               JOIN {attendance_sessions} s ON s.attendanceid = cm.instance
                    AND (s.groupid = gc.groupid OR s.groupid = 0) AND s.sessdate < :nowts
               JOIN {attendance_log} l ON l.sessionid = s.id
               JOIN {attendance_statuses} ast ON ast.id = l.statusid AND (ast.grade IS NULL OR ast.grade <= 0)
               JOIN {gmk_course_progre} gcp ON gcp.classid = gc.id
                    AND gcp.userid = l.studentid AND gcp.status IN (1,2,3)
              WHERE gc.id = :cid AND gc.attendancemoduleid > 0
              GROUP BY gc.id";

// Query D: no progre filter, no grade filter — raw log count
$sql_raw = "SELECT gc.id AS classid, COUNT(l.id) AS cnt
               FROM {gmk_class} gc
               JOIN {course_modules} cm ON cm.id = gc.attendancemoduleid
               JOIN {attendance_sessions} s ON s.attendanceid = cm.instance
                    AND (s.groupid = gc.groupid OR s.groupid = 0) AND s.sessdate < :nowts
               JOIN {attendance_log} l ON l.sessionid = s.id
              WHERE gc.id = :cid AND gc.attendancemoduleid > 0
              GROUP BY gc.id";

// Sessions count (baseline)
$sql_sessions = "SELECT gc.id AS classid, COUNT(DISTINCT s.id) AS cnt
               FROM {gmk_class} gc
               JOIN {course_modules} cm ON cm.id = gc.attendancemoduleid
               JOIN {attendance_sessions} s ON s.attendanceid = cm.instance
                    AND s.groupid = gc.groupid AND s.sessdate < :nowts
              WHERE gc.id = :cid AND gc.attendancemoduleid > 0
              GROUP BY gc.id";

$p = ['cid' => $classid, 'nowts' => $now];

$run = function($label, $sql, $params) use ($DB) {
    try {
        $r = $DB->get_record_sql($sql, $params);
        $cnt = $r ? (int)$r->cnt : 0;
        $chip = $cnt > 0
            ? '<span class="chip chip-green">' . $cnt . '</span>'
            : '<span class="chip chip-red">0</span>';
        return "<tr><td>$label</td><td>$chip</td></tr>";
    } catch (Throwable $e) {
        return "<tr><td>$label</td><td><span class='dbg-err'>ERROR: " . s($e->getMessage()) . "</span></td></tr>";
    }
};

echo '<table class="dbg">';
echo '<tr><th>Variante de consulta</th><th>Resultado</th></tr>';
echo $run('Sesiones pasadas (s.groupid=gc.groupid exacto)', $sql_sessions, $p);
echo $run('Original: grade &lt;= 0, groupid exacto', $sql_orig, $p);
echo $run('Fix A: grade IS NULL OR grade &lt;= 0, groupid exacto', $sql_fixed, $p);
echo $run('Fix B: grade IS NULL OR grade &lt;= 0, groupid OR 0', $sql_grp, $p);
echo $run('Fix C: SIN filtro de grade, SIN filtro progre (conteo raw)', $sql_raw, $p);
echo '</table>';
echo '</div>';

// ── 8. Per-student breakdown ───────────────────────────────────────────────────
echo '<div class="dbg-section">';
echo '<h3>8. Desglose por estudiante (AJAX handler — get_students)</h3>';

// Get enrolled students
$students_raw = $DB->get_records_sql(
    "SELECT gcp.userid, u.firstname, u.lastname, gcp.status
       FROM {gmk_course_progre} gcp
       JOIN {user} u ON u.id = gcp.userid
      WHERE gcp.classid = :cid AND gcp.status IN (1,2,3)
      LIMIT 30",
    ['cid' => $classid]
);

if (!$students_raw) {
    echo '<span class="dbg-warn">No hay estudiantes con status IN(1,2,3) en gmk_course_progre para esta clase.</span>';
} else {
    $uids = array_keys($students_raw);
    [$uinsql, $uinp] = $DB->get_in_or_equal($uids, SQL_PARAMS_NAMED, 'suid');
    $uinp['cmid']    = $cmid;
    $uinp['groupid'] = $grpid;
    $uinp['nowts']   = $now;

    // Count absences per student (both variants)
    $abs_orig = [];
    $abs_fixed = [];
    $abs_grp   = [];

    try {
        $rs = $DB->get_recordset_sql(
            "SELECT l.studentid, COUNT(l.id) AS absences
               FROM {course_modules} cm
               JOIN {attendance_sessions} s ON s.attendanceid = cm.instance
                    AND s.groupid = :groupid AND s.sessdate < :nowts
               JOIN {attendance_log} l ON l.sessionid = s.id
               JOIN {attendance_statuses} ast ON ast.id = l.statusid AND ast.grade <= 0
              WHERE cm.id = :cmid AND l.studentid $uinsql
              GROUP BY l.studentid",
            $uinp
        );
        foreach ($rs as $row) { $abs_orig[(int)$row->studentid] = (int)$row->absences; }
        $rs->close();
    } catch (Throwable $e) { $abs_orig = ['ERROR' => $e->getMessage()]; }

    try {
        $rs = $DB->get_recordset_sql(
            "SELECT l.studentid, COUNT(l.id) AS absences
               FROM {course_modules} cm
               JOIN {attendance_sessions} s ON s.attendanceid = cm.instance
                    AND s.groupid = :groupid AND s.sessdate < :nowts
               JOIN {attendance_log} l ON l.sessionid = s.id
               JOIN {attendance_statuses} ast ON ast.id = l.statusid AND (ast.grade IS NULL OR ast.grade <= 0)
              WHERE cm.id = :cmid AND l.studentid $uinsql
              GROUP BY l.studentid",
            $uinp
        );
        foreach ($rs as $row) { $abs_fixed[(int)$row->studentid] = (int)$row->absences; }
        $rs->close();
    } catch (Throwable $e) { $abs_fixed = ['ERROR' => $e->getMessage()]; }

    $uinp2 = $uinp;
    $uinp2['groupid2'] = 0; // for OR groupid=0
    try {
        $rs = $DB->get_recordset_sql(
            "SELECT l.studentid, COUNT(l.id) AS absences
               FROM {course_modules} cm
               JOIN {attendance_sessions} s ON s.attendanceid = cm.instance
                    AND (s.groupid = :groupid OR s.groupid = :groupid2) AND s.sessdate < :nowts
               JOIN {attendance_log} l ON l.sessionid = s.id
               JOIN {attendance_statuses} ast ON ast.id = l.statusid AND (ast.grade IS NULL OR ast.grade <= 0)
              WHERE cm.id = :cmid AND l.studentid $uinsql
              GROUP BY l.studentid",
            $uinp2
        );
        foreach ($rs as $row) { $abs_grp[(int)$row->studentid] = (int)$row->absences; }
        $rs->close();
    } catch (Throwable $e) { $abs_grp = ['ERROR' => $e->getMessage()]; }

    echo '<table class="dbg">';
    echo '<tr><th>Estudiante</th><th>status progre</th><th>Orig (grade&lt;=0)</th>'
        . '<th>Fix A (NULL|0)</th><th>Fix B (NULL|0 + grp OR 0)</th></tr>';
    foreach ($students_raw as $uid => $st) {
        $name = s(trim($st->firstname . ' ' . $st->lastname));
        $o = $abs_orig[$uid] ?? 0;
        $a = $abs_fixed[$uid] ?? 0;
        $b = $abs_grp[$uid] ?? 0;

        $chip_o = $o > 0 ? '<span class="chip chip-red">'.$o.'</span>' : '<span class="chip chip-gray">0</span>';
        $chip_a = $a > 0 ? '<span class="chip chip-red">'.$a.'</span>' : '<span class="chip chip-gray">0</span>';
        $chip_b = $b > 0 ? '<span class="chip chip-red">'.$b.'</span>' : '<span class="chip chip-gray">0</span>';

        echo "<tr><td>$name (uid=$uid)</td><td>{$st->status}</td><td>$chip_o</td><td>$chip_a</td><td>$chip_b</td></tr>";
    }
    echo '</table>';
}
echo '</div>';

// ── 9. Summary / diagnosis ─────────────────────────────────────────────────────
echo '<div class="dbg-section" style="background:#fefce8;border-color:#fde047;">';
echo '<h3 style="color:#854d0e;">9. Resumen de diagnóstico</h3>';
echo '<ul style="line-height:1.9;">';
echo '<li>Si la columna <b>Fix A</b> sigue en 0 pero Fix C > 0 → el problema está en <b>attendance_statuses.grade</b> (todos los status tienen grade &gt; 0). Ver sección 3 para identificar cuáles son las ausencias.</li>';
echo '<li>Si Fix A = 0 pero Fix B > 0 → las sesiones usan <b>groupid = 0</b> (global) pero gc.groupid &gt; 0. El filtro <code>s.groupid = gc.groupid</code> los excluye. Hay que agregar <code>OR s.groupid = 0</code>.</li>';
echo '<li>Si Fix C = 0 → <b>no hay registros en attendance_log</b>. El docente no ha pasado asistencia todavía.</li>';
echo '<li>Si Fix B > 0 y Fix A = 0 → aplica el fix de groupid OR 0 en absence_dashboard.php.</li>';
echo '<li>Si todas las variantes dan 0 → revisar sección 5 (absence_log): puede que los log entries vinculen a otra instancia de attendance.</li>';
echo '</ul>';
echo '</div>';

echo $OUTPUT->footer();
