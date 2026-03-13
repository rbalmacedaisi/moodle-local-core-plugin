<?php
// Diagnóstico: sesiones futuras en Mi Horario (LXP)
$config_path = __DIR__ . '/../../config.php';
if (!file_exists($config_path)) $config_path = __DIR__ . '/../../../config.php';
if (!file_exists($config_path)) $config_path = __DIR__ . '/../../../../config.php';
require_once($config_path);
require_once($CFG->dirroot . '/calendar/lib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url('/local/grupomakro_core/pages/debug_calendar_events.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Debug: Horario LXP');
$PAGE->set_heading('Debug: Sesiones Futuras en Mi Horario');
echo $OUTPUT->header();

$userId = optional_param('userid', 0, PARAM_INT);
$search = optional_param('search', '', PARAM_TEXT);

echo '<style>
  body { font-family: sans-serif; }
  table { border-collapse: collapse; width: 100%; font-size: 13px; margin-bottom: 12px; }
  th, td { border: 1px solid #ccc; padding: 6px 10px; }
  th { background: #1a73e8; color: white; }
  tr:nth-child(even) { background: #f9f9f9; }
  .ok   { color: green; font-weight: bold; }
  .err  { color: red; font-weight: bold; }
  .warn { color: orange; font-weight: bold; }
  .section { margin: 22px 0 8px; font-size: 15px; font-weight: bold;
             border-bottom: 2px solid #1a73e8; padding-bottom: 3px; }
  .box { padding: 10px 14px; border-radius: 4px; margin: 8px 0; border: 1px solid; }
  .box.ok  { background:#dfd; border-color:green; }
  .box.err { background:#fde; border-color:red; }
  .box.warn { background:#fff3cd; border-color:#ffc107; }
  input[type=text], input[type=number] { padding: 6px 10px; font-size:14px; border:1px solid #ccc; border-radius:3px; }
  button { padding: 7px 18px; background:#1a73e8; color:white; border:none; border-radius:3px; cursor:pointer; font-size:14px; }
  button:hover { background:#1558b0; }
  .student-card { background:#e8f0fe; border:1px solid #1a73e8; border-radius:4px; padding:8px 14px; margin-bottom:12px; }
</style>';

// ── Buscador de estudiantes ───────────────────────────────────────────────
echo '<form method="get" style="margin-bottom:16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
  <input type="text" name="search" value="' . s($search) . '" placeholder="Buscar estudiante por nombre o email..." style="width:320px;">
  <button type="submit">Buscar</button>';
if ($userId) {
    echo '<input type="hidden" name="userid" value="' . $userId . '">';
}
echo '</form>';

// Resultados de búsqueda
if ($search && !$userId) {
    $like = '%' . $DB->sql_like_escape($search) . '%';
    $students = $DB->get_records_sql(
        "SELECT u.id, u.firstname, u.lastname, u.email
         FROM {user} u
         WHERE u.deleted = 0 AND u.suspended = 0
           AND (" . $DB->sql_like('u.firstname', ':s1', false) . "
            OR " . $DB->sql_like('u.lastname',  ':s2', false) . "
            OR " . $DB->sql_like('u.email',     ':s3', false) . ")
         ORDER BY u.lastname, u.firstname
         LIMIT 30",
        ['s1' => $like, 's2' => $like, 's3' => $like]
    );

    if (empty($students)) {
        echo "<p class='warn'>No se encontraron usuarios con \"" . s($search) . "\"</p>";
    } else {
        echo "<div class='section'>Resultados (" . count($students) . ")</div>";
        echo "<table><tr><th>ID</th><th>Nombre</th><th>Email</th><th>Acción</th></tr>";
        foreach ($students as $s) {
            $url = new moodle_url('/local/grupomakro_core/pages/debug_calendar_events.php',
                                  ['userid' => $s->id, 'search' => $search]);
            echo "<tr>
                <td>{$s->id}</td>
                <td>{$s->firstname} {$s->lastname}</td>
                <td>{$s->email}</td>
                <td><a href='$url'><button type='button'>Diagnosticar</button></a></td>
            </tr>";
        }
        echo "</table>";
    }
    echo $OUTPUT->footer();
    exit;
}

if (!$userId) {
    echo "<div class='box warn'>Busca un estudiante arriba para comenzar el diagnóstico.</div>";
    echo $OUTPUT->footer();
    exit;
}

// ── Datos del estudiante seleccionado ────────────────────────────────────
$user = $DB->get_record('user', ['id' => $userId, 'deleted' => 0], '*', MUST_EXIST);
echo "<div class='student-card'>
  <b>Estudiante:</b> {$user->firstname} {$user->lastname} &nbsp;|&nbsp;
  <b>Email:</b> {$user->email} &nbsp;|&nbsp;
  <b>ID:</b> {$user->id} &nbsp;|&nbsp;
  <a href='?search=" . urlencode($search) . "'>← Cambiar estudiante</a>
</div>";

$now      = time();
$initDate = date('Y-01-01');
$endDate  = date('Y-12-31', strtotime('+1 year'));

// ── 1. Grupos del usuario ─────────────────────────────────────────────────
echo "<div class='section'>1. Grupos y cursos del estudiante</div>";
$userGroups = $DB->get_records_sql(
    'SELECT gm.groupid, g.courseid, g.name AS groupname
     FROM {groups_members} gm
     JOIN {groups} g ON g.id = gm.groupid
     WHERE gm.userid = :userid',
    ['userid' => $userId]
);

if (empty($userGroups)) {
    echo "<div class='box err'>El estudiante no pertenece a ningún grupo. Sin grupos no hay sesiones.</div>";
    echo $OUTPUT->footer();
    exit;
}

$userGroupIds  = array_values(array_unique(array_column($userGroups, 'groupid')));
$userCourseIds = array_values(array_unique(array_column($userGroups, 'courseid')));

echo "<table><tr><th>GroupId</th><th>Nombre grupo</th><th>CourseId</th></tr>";
foreach ($userGroups as $g) {
    echo "<tr><td>{$g->groupid}</td><td>{$g->groupname}</td><td>{$g->courseid}</td></tr>";
}
echo "</table>";

// ── 2. attendance_sessions ────────────────────────────────────────────────
echo "<div class='section'>2. Sesiones en attendance_sessions</div>";
list($inSql, $inParams) = $DB->get_in_or_equal($userGroupIds);
$sessions = $DB->get_records_sql(
    "SELECT asess.id, asess.sessdate, asess.caleventid, asess.groupid
     FROM {attendance_sessions} asess
     WHERE asess.groupid $inSql
     ORDER BY asess.sessdate ASC",
    $inParams
);

$cntFut = $cntPast = $cntNoCal = 0;
echo "<table><tr><th>Session ID</th><th>Fecha</th><th>¿Futura?</th><th>caleventid</th><th>GroupId</th></tr>";
foreach ($sessions as $s) {
    $fut = $s->sessdate > $now;
    $fut ? $cntFut++ : $cntPast++;
    if (!$s->caleventid) $cntNoCal++;
    echo "<tr>
        <td>{$s->id}</td>
        <td>" . date('Y-m-d H:i', $s->sessdate) . "</td>
        <td " . ($fut ? "class='ok'" : "") . ">" . ($fut ? 'FUTURA' : 'pasada') . "</td>
        <td " . (!$s->caleventid ? "class='err'" : "") . ">" . ($s->caleventid ?: 'NULL ⚠') . "</td>
        <td>{$s->groupid}</td>
    </tr>";
}
echo "</table>";
echo "<p>Total: " . count($sessions) . " | Pasadas: $cntPast | <b>Futuras: $cntFut</b> | Sin caleventid: <span class='" . ($cntNoCal ? 'err' : 'ok') . "'>$cntNoCal</span></p>";
if ($cntNoCal) {
    echo "<div class='box err'>⚠ $cntNoCal sesiones sin caleventid — get_class_events las descarta automáticamente.</div>";
}

// ── 3. Eventos en {event} ─────────────────────────────────────────────────
echo "<div class='section'>3. Eventos en tabla {event} (modulename=attendance)</div>";
$calEvts = $DB->get_records_sql(
    "SELECT e.id, e.timestart, e.groupid, e.courseid, e.visible
     FROM {event} e
     WHERE e.groupid $inSql AND e.modulename = 'attendance'
     ORDER BY e.timestart ASC",
    $inParams
);

$cntEvtFut = $cntEvtPast = $cntHidden = 0;
echo "<table><tr><th>Event ID</th><th>Fecha</th><th>¿Futura?</th><th>Visible</th><th>GroupId</th></tr>";
foreach ($calEvts as $e) {
    $fut = $e->timestart > $now;
    $fut ? $cntEvtFut++ : $cntEvtPast++;
    if (!$e->visible) $cntHidden++;
    echo "<tr>
        <td>{$e->id}</td>
        <td>" . date('Y-m-d H:i', $e->timestart) . "</td>
        <td " . ($fut ? "class='ok'" : "") . ">" . ($fut ? 'FUTURA' : 'pasada') . "</td>
        <td " . (!$e->visible ? "class='err'" : "") . ">" . ($e->visible ? 'sí' : 'OCULTO ⚠') . "</td>
        <td>{$e->groupid}</td>
    </tr>";
}
echo "</table>";
echo "<p>Total: " . count($calEvts) . " | Pasados: $cntEvtPast | <b>Futuros: $cntEvtFut</b> | Ocultos: <span class='" . ($cntHidden ? 'err' : 'ok') . "'>$cntHidden</span></p>";

// ── 4. calendar_get_events ────────────────────────────────────────────────
echo "<div class='section'>4. Resultado de calendar_get_events</div>";
$rawEvents  = calendar_get_events(strtotime($initDate), strtotime($endDate), false, $userGroupIds, $userCourseIds, true);
$rawAtt     = array_filter($rawEvents, fn($e) => $e->modulename === 'attendance');
$cntRawFut  = count(array_filter($rawAtt, fn($e) => $e->timestart > $now));
$cntRawPast = count($rawAtt) - $cntRawFut;

echo "<p>Eventos totales: " . count($rawEvents) . " | Attendance: " . count($rawAtt) . " | Pasados: $cntRawPast | <b>Futuros: $cntRawFut</b></p>";

if ($cntEvtFut > 0 && $cntRawFut === 0) {
    echo "<div class='box err'>⚠ Hay $cntEvtFut eventos futuros en la tabla {event} pero calendar_get_events devuelve 0 — problema en cómo se pasan groupIds o courseIds.</div>";
} elseif ($cntRawFut > 0) {
    echo "<div class='box ok'>calendar_get_events devuelve $cntRawFut eventos futuros correctamente.</div>";
}

// ── 5. gmk_bbb_attendance_relation para sesiones futuras ─────────────────
echo "<div class='section'>5. Relación gmk_bbb_attendance_relation (sesiones futuras)</div>";
$futureSess = $DB->get_records_sql(
    "SELECT asess.id, asess.sessdate, asess.caleventid
     FROM {attendance_sessions} asess
     WHERE asess.groupid $inSql AND asess.sessdate > " . $now . "
     ORDER BY asess.sessdate ASC LIMIT 15",
    $inParams
);

echo "<table><tr><th>Session ID</th><th>Fecha</th><th>caleventid</th><th>En bbb_attendance_relation</th><th>classid</th></tr>";
foreach ($futureSess as $s) {
    $rel = $DB->get_record('gmk_bbb_attendance_relation', ['attendancesessionid' => $s->id]);
    echo "<tr>
        <td>{$s->id}</td>
        <td>" . date('Y-m-d H:i', $s->sessdate) . "</td>
        <td " . (!$s->caleventid ? "class='err'" : "") . ">" . ($s->caleventid ?: 'NULL ⚠') . "</td>
        <td " . (!$rel ? "class='err'" : "") . ">" . ($rel ? 'sí' : 'NO ⚠') . "</td>
        <td>" . ($rel ? $rel->classid : '-') . "</td>
    </tr>";
}
echo "</table><p><i>Máx. 15 sesiones futuras</i></p>";

// ── 6. Filtro gmk_course_progre (causa más común de 0 resultados) ─────────
echo "<div class='section'>6. Filtro gmk_course_progre (status=2 + periodo activo)</div>";

// 6a. currentperiodid del estudiante por learning plan
$learningplanusers = $DB->get_records(
    'local_learning_users',
    ['userid' => (int)$userId],
    '',
    'id, learningplanid, currentperiodid'
);
$studentperiodmap = [];
foreach ($learningplanusers as $lpu) {
    if (!empty($lpu->learningplanid) && !empty($lpu->currentperiodid)) {
        $studentperiodmap[(int)$lpu->learningplanid] = (int)$lpu->currentperiodid;
    }
}

if (empty($learningplanusers)) {
    echo "<div class='box warn'>⚠ El estudiante no tiene registros en local_learning_users. El filtro de periodo no aplica.</div>";
} else {
    echo "<p><b>local_learning_users (periodo activo por plan):</b></p>";
    echo "<table><tr><th>learningplanid</th><th>currentperiodid</th></tr>";
    foreach ($learningplanusers as $lpu) {
        echo "<tr><td>{$lpu->learningplanid}</td><td>{$lpu->currentperiodid}</td></tr>";
    }
    echo "</table>";
}

// 6b. Registros en gmk_course_progre
$progrerows = $DB->get_records_select(
    'gmk_course_progre',
    'userid = :userid',
    ['userid' => (int)$userId],
    '',
    'id, learningplanid, periodid, courseid, classid, status'
);

$statusLabels = [0 => 'pendiente', 1 => 'aprobado', 2 => 'en curso', 3 => 'reprobado', 4 => 'retirado'];

if (empty($progrerows)) {
    echo "<div class='box warn'>⚠ El estudiante NO tiene registros en gmk_course_progre. <br>El filtro estricto <b>no se activa</b> y los eventos deberían mostrarse sin restricción de status.</div>";
} else {
    echo "<p><b>gmk_course_progre</b> (" . count($progrerows) . " registros):</p>";
    echo "<table><tr><th>id</th><th>learningplanid</th><th>periodid</th><th>courseid</th><th>classid</th><th>status</th><th>¿Pasa filtro?</th><th>Razón descarte</th></tr>";

    $activeCourseIds = [];
    $activeClassIds  = [];

    foreach ($progrerows as $prow) {
        $statusNum  = (int)$prow->status;
        $statusText = $statusLabels[$statusNum] ?? $statusNum;
        $passes     = false;
        $reason     = '';

        if ($statusNum !== 2) {
            $reason = "status=" . $statusNum . " (" . $statusText . "), se requiere 2";
        } else {
            $rowlpid  = !empty($prow->learningplanid) ? (int)$prow->learningplanid : 0;
            $rowperid = !empty($prow->periodid)       ? (int)$prow->periodid       : 0;
            if ($rowlpid > 0 && isset($studentperiodmap[$rowlpid])) {
                $currentperid = (int)$studentperiodmap[$rowlpid];
                if ($rowperid > 0 && $rowperid !== $currentperid) {
                    $reason = "periodid={$rowperid} ≠ currentperiodid={$currentperid}";
                } else {
                    $passes = true;
                }
            } else {
                $passes = true; // sin plan o sin periodo → no filtra por periodo
            }
        }

        if ($passes) {
            if (!empty($prow->courseid)) $activeCourseIds[(int)$prow->courseid] = true;
            if (!empty($prow->classid))  $activeClassIds[(int)$prow->classid]   = true;
        }

        $rowClass = $passes ? 'ok' : 'err';
        echo "<tr>
            <td>{$prow->id}</td>
            <td>" . ($prow->learningplanid ?: '-') . "</td>
            <td>" . ($prow->periodid ?: '-') . "</td>
            <td>{$prow->courseid}</td>
            <td>" . ($prow->classid ?: '-') . "</td>
            <td class='{$rowClass}'>{$statusNum} ({$statusText})</td>
            <td class='{$rowClass}'>" . ($passes ? '✔ sí' : '✘ no') . "</td>
            <td class='" . ($passes ? '' : 'err') . "'>" . ($reason ?: '') . "</td>
        </tr>";
    }
    echo "</table>";

    // Resumen
    if (empty($activeCourseIds)) {
        echo "<div class='box err'>⚠ <b>NINGÚN registro pasa el filtro</b> → <code>\$studentActiveCourseIdSet</code> queda vacío → <b>todos los eventos son descartados</b>.<br>
        El filtro estricto se activa porque el estudiante SÍ tiene filas en gmk_course_progre, pero ninguna con status=2 en el periodo actual.</div>";
    } else {
        $courseList = implode(', ', array_keys($activeCourseIds));
        $classList  = implode(', ', array_keys($activeClassIds));
        echo "<div class='box ok'>✔ Cursos activos para el filtro: [{$courseList}]<br>Clases activas: [" . ($classList ?: 'ninguna') . "]</div>";

        // Verificar si los cursos del grupo están en el set activo
        $missing = [];
        foreach ($userCourseIds as $cid) {
            if (!isset($activeCourseIds[$cid])) {
                $missing[] = $cid;
            }
        }
        if (!empty($missing)) {
            echo "<div class='box warn'>⚠ Los cursos [" . implode(', ', $missing) . "] tienen grupos asignados al estudiante pero NO están en el set activo de gmk_course_progre. Sus eventos serán descartados.</div>";
        }
    }
}

// ── 7. Resultado final: lo que ve la LXP ─────────────────────────────────
echo "<div class='section'>7. Lo que recibe la LXP (get_class_events)</div>";
$lxp     = get_class_events($userId);
$lxpFut  = count(array_filter($lxp, fn($e) => strtotime($e->start) > $now));
$lxpPast = count($lxp) - $lxpFut;

echo "<table><tr><th>Curso</th><th>Inicio</th><th>¿Futura?</th><th>Módulo</th><th>classId</th></tr>";
foreach ($lxp as $e) {
    $fut = strtotime($e->start) > $now;
    echo "<tr>
        <td>{$e->coursename}</td>
        <td>{$e->start}</td>
        <td " . ($fut ? "class='ok'" : "") . ">" . ($fut ? 'FUTURA' : 'pasada') . "</td>
        <td>{$e->modulename}</td>
        <td>" . ($e->classId ?? '-') . "</td>
    </tr>";
}
echo "</table>";
echo "<p>Total: " . count($lxp) . " | Pasadas: $lxpPast | <b>Futuras: $lxpFut</b></p>";

if ($lxpFut === 0) {
    echo "<div class='box err'><b>CONFIRMADO: La LXP recibe 0 sesiones futuras para este estudiante.</b><br>Revisa los ⚠ en las secciones anteriores, especialmente la sección 6.</div>";
} else {
    echo "<div class='box ok'><b>La LXP recibe $lxpFut sesiones futuras.</b> Si aún no aparecen en pantalla, el problema está en el frontend (horario.vue).</div>";
}

echo $OUTPUT->footer();
