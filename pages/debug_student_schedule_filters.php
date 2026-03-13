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
echo '<div style="margin-top:12px;"><button type="submit" class="btn btn-primary">Diagnosticar</button></div>';
echo '</form>';

if ($search !== '' && $userid <= 0) {
    $like = '%' . $DB->sql_like_escape($search) . '%';
    $candidates = $DB->get_records_sql(
        "SELECT u.id, u.firstname, u.lastname, u.email
           FROM {user} u
          WHERE u.deleted = 0
            AND (
                " . $DB->sql_like('u.firstname', ':s1', false, false) . "
                OR " . $DB->sql_like('u.lastname', ':s2', false, false) . "
                OR " . $DB->sql_like('u.email', ':s3', false, false) . "
            )
       ORDER BY u.lastname, u.firstname",
        ['s1' => $like, 's2' => $like, 's3' => $like],
        0,
        50
    );

    $rows = [];
    foreach ($candidates as $c) {
        $url = new moodle_url('/local/grupomakro_core/pages/debug_student_schedule_filters.php', [
            'userid' => (int)$c->id,
            'initdate' => $initdate,
            'enddate' => $enddate
        ]);
        $rows[] = [
            'ID' => (int)$c->id,
            'Nombre' => s(trim($c->firstname . ' ' . $c->lastname)),
            'Email' => s($c->email),
            'Accion' => '<a href="' . $url->out(false) . '">Diagnosticar</a>',
        ];
    }
    echo '<div class="dbg-card"><strong>Resultados busqueda:</strong></div>';
    gmk_dbg_print_table(['ID', 'Nombre', 'Email', 'Accion'], $rows);

    echo '</div>';
    echo $OUTPUT->footer();
    exit;
}

if ($userid <= 0) {
    echo '<div class="dbg-card"><span class="muted">Ingresa un Student ID o busca un estudiante para iniciar el diagnostico.</span></div>';
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

