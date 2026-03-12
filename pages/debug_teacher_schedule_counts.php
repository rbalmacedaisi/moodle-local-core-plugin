<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

global $DB, $PAGE, $OUTPUT, $USER;

require_login();

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/local/grupomakro_core/pages/debug_teacher_schedule_counts.php');
$PAGE->set_title('Debug: Teacher vs Schedule counts');
$PAGE->set_heading('Debug: Teacher vs Schedule counts');
$PAGE->set_pagelayout('admin');

$teacherid = optional_param('teacherid', $USER->id, PARAM_INT);
$classid = optional_param('classid', 0, PARAM_INT);
$classname = optional_param(
    'classname',
    '2026-I (S) INGLÉS I (PRESENCIAL) AULA L',
    PARAM_RAW_TRIMMED
);

echo $OUTPUT->header();

$teacher = $DB->get_record('user', ['id' => $teacherid], 'id,firstname,lastname,email', IGNORE_MISSING);
$isadmin = is_siteadmin($teacherid);
$now = time();
$bufferdays = 7;
$nowwithbuffer = $now - ($bufferdays * DAYSECS);

function gmk_dbg_h($value) {
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

function gmk_dbg_badge($ok, $oklabel = 'OK', $badlabel = 'NO') {
    if ($ok) {
        return '<span class="badge ok">' . gmk_dbg_h($oklabel) . '</span>';
    }
    return '<span class="badge bad">' . gmk_dbg_h($badlabel) . '</span>';
}

echo '<style>
    .dbg-wrap { max-width: 1700px; margin: 18px auto; }
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
    .reason { margin: 4px 0; }
    .okline { color: #198754; font-weight: 600; }
    .badline { color: #dc3545; font-weight: 600; }
</style>';

echo '<div class="dbg-wrap">';
echo '<h2>Debug de diferencias: Teacher Dashboard vs Panel de aprobacion</h2>';

echo '<form method="get" class="dbg-card">';
echo '<div class="dbg-grid">';
echo '<div>';
echo '<label><strong>Teacher ID</strong></label><br>';
echo '<input type="number" name="teacherid" value="' . gmk_dbg_h($teacherid) . '" style="width:100%;max-width:280px;">';
echo '</div>';
echo '<div>';
echo '<label><strong>Class ID (opcional)</strong></label><br>';
echo '<input type="number" name="classid" value="' . gmk_dbg_h($classid) . '" style="width:100%;max-width:280px;">';
echo '</div>';
echo '</div>';
echo '<div style="margin-top:10px;">';
echo '<label><strong>Filtro por nombre de clase</strong></label><br>';
echo '<input type="text" name="classname" value="' . gmk_dbg_h($classname) . '" style="width:100%;">';
echo '</div>';
echo '<div style="margin-top:12px;">';
echo '<button type="submit" class="btn btn-primary">Ejecutar diagnostico</button>';
echo '</div>';
echo '</form>';

echo '<div class="dbg-card">';
echo '<strong>Contexto:</strong><br>';
echo 'Teacher: ' . ($teacher ? gmk_dbg_h($teacher->firstname . ' ' . $teacher->lastname . ' (' . $teacher->email . ')') : 'No encontrado') . '<br>';
echo 'Es admin: ' . gmk_dbg_badge($isadmin, 'SI', 'NO') . '<br>';
echo 'Now: ' . userdate($now) . '<br>';
echo 'Buffer teacher dashboard: ' . $bufferdays . ' dias (enddate >= ' . userdate($nowwithbuffer) . ')';
echo '</div>';

$classes = [];
if ($classid > 0) {
    $class = $DB->get_record('gmk_class', ['id' => $classid], '*', IGNORE_MISSING);
    if ($class) {
        $classes[$class->id] = $class;
    }
} else if (trim($classname) !== '') {
    $needle = '%' . trim($classname) . '%';
    $likesql = $DB->sql_like('c.name', ':needle', false, false);
    $classes = $DB->get_records_sql(
        "SELECT c.* FROM {gmk_class} c WHERE $likesql ORDER BY c.id DESC",
        ['needle' => $needle]
    );
}

if (empty($classes)) {
    echo '<div class="dbg-card"><span class="badline">No se encontraron clases con ese filtro.</span></div>';
    echo '</div>';
    echo $OUTPUT->footer();
    exit;
}

echo '<div class="dbg-card">';
echo 'Clases encontradas: <strong>' . count($classes) . '</strong>';
echo '</div>';

foreach ($classes as $class) {
    $instructor = null;
    if (!empty($class->instructorid)) {
        $instructor = $DB->get_record('user', ['id' => $class->instructorid], 'id,firstname,lastname,email', IGNORE_MISSING);
    }

    $hasrelation = $DB->record_exists('gmk_bbb_attendance_relation', ['classid' => $class->id]);
    $relationcount = $DB->count_records('gmk_bbb_attendance_relation', ['classid' => $class->id]);
    $passesinstructor = $isadmin || ((int)$class->instructorid === (int)$teacherid);
    $passesclosed = ((int)$class->closed === 0);
    $passesdate = ((int)$class->enddate >= (int)$nowwithbuffer);
    $wouldshowindashboard = ($passesinstructor && $passesclosed && $passesdate && $hasrelation);

    $dashcountlegacy = 0;
    if (!empty($class->groupid)) {
        $dashcountsql = "SELECT COUNT(DISTINCT gm.userid)
                           FROM {groups_members} gm
                           JOIN {local_learning_users} llu ON llu.userid = gm.userid
                          WHERE gm.groupid = :gid
                            AND gm.userid != :iid";
        $dashcountlegacy = (int)$DB->count_records_sql($dashcountsql, [
            'gid' => (int)$class->groupid,
            'iid' => (int)$class->instructorid
        ]);
    }

    $groupcount = 0;
    if (!empty($class->groupid)) {
        $groupcount = (int)$DB->count_records_select(
            'groups_members',
            'groupid = :gid AND userid != :iid',
            ['gid' => (int)$class->groupid, 'iid' => (int)$class->instructorid]
        );
    }

    $preregraw = (int)$DB->count_records_select(
        'gmk_class_pre_registration',
        'classid = :cid AND userid != :iid',
        ['cid' => (int)$class->id, 'iid' => (int)$class->instructorid]
    );
    $queueraw = (int)$DB->count_records_select(
        'gmk_class_queue',
        'classid = :cid AND userid != :iid',
        ['cid' => (int)$class->id, 'iid' => (int)$class->instructorid]
    );
    $prograw = (int)$DB->count_records_select(
        'gmk_course_progre',
        'classid = :cid AND userid != :iid',
        ['cid' => (int)$class->id, 'iid' => (int)$class->instructorid]
    );

    $parts = get_class_participants($class);
    $enrolleddedup = count((array)$parts->enroledStudents);
    $preregdedup = count((array)$parts->preRegisteredStudents);
    $queuededup = count((array)$parts->queuedStudents);
    $progrededup = count((array)$parts->progreStudents);
    // Current dashboard logic should use this same enrolled count.
    $dashcount = $enrolleddedup;

    $schedulepanelusers = ((int)$class->approved > 0) ? $enrolleddedup : $preregdedup;
    $schedulepanelwaiting = ((int)$class->approved > 0) ? 0 : $queuededup;

    $groupstudentsql = "SELECT gm.userid,
                               u.idnumber,
                               u.firstname,
                               u.lastname,
                               CASE WHEN llu.id IS NULL THEN 0 ELSE 1 END AS in_llu,
                               CASE WHEN pr.id IS NULL THEN 0 ELSE 1 END AS in_prereg,
                               CASE WHEN q.id IS NULL THEN 0 ELSE 1 END AS in_queue,
                               CASE WHEN p.id IS NULL THEN 0 ELSE 1 END AS in_progre
                          FROM {groups_members} gm
                          JOIN {user} u ON u.id = gm.userid
                     LEFT JOIN {local_learning_users} llu ON llu.userid = gm.userid
                     LEFT JOIN {gmk_class_pre_registration} pr ON pr.classid = :cid AND pr.userid = gm.userid
                     LEFT JOIN {gmk_class_queue} q ON q.classid = :cid2 AND q.userid = gm.userid
                     LEFT JOIN {gmk_course_progre} p ON p.classid = :cid3 AND p.userid = gm.userid
                         WHERE gm.groupid = :gid
                           AND gm.userid != :iid
                      ORDER BY u.lastname, u.firstname";
    $groupstudents = [];
    if (!empty($class->groupid)) {
        $groupstudents = $DB->get_records_sql($groupstudentsql, [
            'cid' => (int)$class->id,
            'cid2' => (int)$class->id,
            'cid3' => (int)$class->id,
            'gid' => (int)$class->groupid,
            'iid' => (int)$class->instructorid
        ]);
    }

    $reasons = [];
    if (!$passesclosed) {
        $reasons[] = 'La clase tiene closed=1.';
    }
    if (!$passesdate) {
        $reasons[] = 'La clase no cumple enddate >= now-7dias del dashboard.';
    }
    if (!$passesinstructor) {
        $reasons[] = 'La clase no pertenece al teacherid usado en el debug.';
    }
    if (!$hasrelation) {
        $reasons[] = 'No existe relacion en gmk_bbb_attendance_relation para esta clase.';
    }
    if (empty($class->groupid)) {
        $reasons[] = 'groupid vacio; dashboard usa group members para student_count.';
    }
    if ($dashcountlegacy === 0 && $groupcount > 0) {
        $reasons[] = 'Hay usuarios en groups_members pero ninguno en local_learning_users (filtro del dashboard).';
    }
    if ((int)$class->approved === 0 && ($preregdedup + $queuededup) > 0 && $dashcount === 0) {
        $reasons[] = 'La aprobacion muestra pendientes (pre/queue), pero dashboard muestra inscritos reales en grupo.';
    }

    // Diagnose the exact source used by TeacherStudentTable -> local_grupomakro_get_student_info.
    $groupuids = [];
    if (!empty($class->groupid)) {
        $groupuids = $DB->get_fieldset_select(
            'groups_members',
            'userid',
            'groupid = :gid AND userid != :iid',
            ['gid' => (int)$class->groupid, 'iid' => (int)$class->instructorid]
        );
    }
    $groupuids = array_values(array_unique(array_map('intval', $groupuids)));

    $progreuids = $DB->get_fieldset_select(
        'gmk_course_progre',
        'userid',
        'classid = :cid AND userid != :iid',
        ['cid' => (int)$class->id, 'iid' => (int)$class->instructorid]
    );
    $progreuids = array_values(array_unique(array_map('intval', $progreuids)));

    $candidateuids = array_values(array_unique(array_merge($groupuids, $progreuids)));
    $groupuidset = array_fill_keys($groupuids, true);
    $progreuidset = array_fill_keys($progreuids, true);

    $usersbyid = [];
    $llubyuser = [];
    $studentstatusbyuid = [];
    if (!empty($candidateuids)) {
        list($inusersql, $inuserparams) = $DB->get_in_or_equal($candidateuids, SQL_PARAMS_NAMED, 'u');
        $usersbyid = $DB->get_records_sql(
            "SELECT id, firstname, lastname, idnumber, email
               FROM {user}
              WHERE id $inusersql",
            $inuserparams
        );

        $llurecs = $DB->get_records_sql(
            "SELECT id, userid, learningplanid, userrolename, currentperiodid, status
               FROM {local_learning_users}
              WHERE userid $inusersql",
            $inuserparams
        );
        foreach ($llurecs as $lr) {
            if (!isset($llubyuser[$lr->userid])) {
                $llubyuser[$lr->userid] = [];
            }
            $llubyuser[$lr->userid][] = $lr;
        }

        $statusfield = $DB->get_record('user_info_field', ['shortname' => 'studentstatus'], 'id', IGNORE_MISSING);
        if ($statusfield) {
            $statusrows = $DB->get_records_sql(
                "SELECT userid, data
                   FROM {user_info_data}
                  WHERE fieldid = :fieldid
                    AND userid $inusersql",
                array_merge(['fieldid' => (int)$statusfield->id], $inuserparams)
            );
            foreach ($statusrows as $sr) {
                $studentstatusbyuid[(int)$sr->userid] = trim((string)$sr->data);
            }
        }
    }

    $studenttabrows = [];
    $currentqueryuids = [];
    $fallbackqueryuids = [];
    $fallbackactiveuids = [];
    foreach ($candidateuids as $uid) {
        $lurows = $llubyuser[$uid] ?? [];
        $hasstudentllu = false;
        $planids = [];
        $roles = [];
        foreach ($lurows as $lur) {
            $roles[] = (string)$lur->userrolename;
            $planids[] = (int)$lur->learningplanid;
            if ($lur->userrolename === 'student') {
                $hasstudentllu = true;
            }
        }
        $roles = array_values(array_unique($roles));
        $planids = array_values(array_unique($planids));

        $ingroup = isset($groupuidset[$uid]);
        $inprogre = isset($progreuidset[$uid]);
        $isinstructor = ((int)$uid === (int)$class->instructorid);

        // Current query in get_student_info before fix.
        $currentpassesclassfilter = ((int)$class->groupid > 0) ? $ingroup : false;
        $currentincluded = (!$isinstructor && $hasstudentllu && $currentpassesclassfilter);

        // Expected/fixed behavior for classes without group.
        $fallbackpassesclassfilter = ((int)$class->groupid > 0) ? $ingroup : $inprogre;
        $fallbackincluded = (!$isinstructor && $hasstudentllu && $fallbackpassesclassfilter);

        if ($currentincluded) {
            $currentqueryuids[] = $uid;
        }
        if ($fallbackincluded) {
            $fallbackqueryuids[] = $uid;
        }

        $studentstatus = $studentstatusbyuid[$uid] ?? 'Activo';
        $isactivestatus = (strcasecmp(trim($studentstatus), 'Activo') === 0);
        if ($fallbackincluded && $isactivestatus) {
            $fallbackactiveuids[] = $uid;
        }

        $u = $usersbyid[$uid] ?? null;
        $studenttabrows[] = [
            'userid' => $uid,
            'idnumber' => $u ? (string)$u->idnumber : '',
            'name' => $u ? trim($u->firstname . ' ' . $u->lastname) : '',
            'ingroup' => $ingroup,
            'inprogre' => $inprogre,
            'hasstudentllu' => $hasstudentllu,
            'llu_roles' => implode(',', $roles),
            'llu_plans' => implode(',', $planids),
            'studentstatus' => $studentstatus,
            'is_active_status' => $isactivestatus,
            'current_included' => $currentincluded,
            'fallback_included' => $fallbackincluded,
        ];
    }

    if ((int)$class->groupid === 0 && !empty($progreuids) && empty($currentqueryuids)) {
        $reasons[] = 'La pestaña Estudiantes queda vacia porque get_student_info filtraba solo por groups_members y esta clase no tiene groupid.';
    }

    $diag = [
        'class' => [
            'id' => (int)$class->id,
            'name' => (string)$class->name,
            'approved' => (int)$class->approved,
            'closed' => (int)$class->closed,
            'instructorid' => (int)$class->instructorid,
            'groupid' => (int)$class->groupid,
            'corecourseid' => (int)$class->corecourseid,
            'courseid' => (int)$class->courseid,
            'periodid' => (int)$class->periodid,
            'enddate' => (int)$class->enddate,
        ],
        'teacher_dashboard_filters' => [
            'instructor_match' => $passesinstructor,
            'closed_ok' => $passesclosed,
            'date_ok_with_7day_buffer' => $passesdate,
            'has_bbb_attendance_relation' => $hasrelation,
            'would_show_in_dashboard' => $wouldshowindashboard,
        ],
        'counts' => [
            'teacher_dashboard_student_count' => $dashcount,
            'teacher_dashboard_student_count_legacy_group_llu' => $dashcountlegacy,
            'group_members_excluding_instructor' => $groupcount,
            'prereg_raw' => $preregraw,
            'queue_raw' => $queueraw,
            'progre_raw' => $prograw,
            'participants_enrolled_dedup' => $enrolleddedup,
            'participants_prereg_dedup' => $preregdedup,
            'participants_queue_dedup' => $queuededup,
            'participants_progre' => $progrededup,
            'scheduleapproval_users_shown' => $schedulepanelusers,
            'scheduleapproval_waiting_shown' => $schedulepanelwaiting,
        ],
        'student_tab_debug' => [
            'group_source_count' => count($groupuids),
            'progre_source_count' => count($progreuids),
            'candidate_union_count' => count($candidateuids),
            'current_query_included_count' => count($currentqueryuids),
            'fallback_query_included_count' => count($fallbackqueryuids),
            'fallback_query_active_status_count' => count($fallbackactiveuids),
            'current_query_userids' => array_values($currentqueryuids),
            'fallback_query_userids' => array_values($fallbackqueryuids),
            'fallback_query_active_userids' => array_values($fallbackactiveuids),
        ],
        'reasons' => $reasons,
    ];

    echo '<div class="dbg-card">';
    echo '<h3>Clase: ' . gmk_dbg_h($class->name) . ' (ID ' . (int)$class->id . ')</h3>';
    echo '<div class="muted">Instructor: ' . ($instructor ? gmk_dbg_h($instructor->firstname . ' ' . $instructor->lastname . ' [' . $instructor->id . ']') : 'N/A') . '</div>';

    echo '<table class="dbg-table">';
    echo '<thead><tr><th>Campo</th><th>Valor</th><th>Comentario</th></tr></thead><tbody>';
    echo '<tr><td>approved</td><td>' . (int)$class->approved . '</td><td>0=pendiente, 1=aprobada</td></tr>';
    echo '<tr><td>groupid</td><td>' . gmk_dbg_h($class->groupid) . '</td><td>Teacher dashboard cuenta desde groups_members</td></tr>';
    echo '<tr><td>teacher_dashboard student_count (actual esperado)</td><td><strong>' . $dashcount . '</strong></td><td>Misma logica de get_class_participants()->enroledStudents</td></tr>';
    echo '<tr><td>teacher_dashboard student_count (legacy group+llu)</td><td><strong>' . $dashcountlegacy . '</strong></td><td>COUNT(DISTINCT groups_members) + filtro local_learning_users</td></tr>';
    echo '<tr><td>groups_members (sin filtro local_learning_users)</td><td><strong>' . $groupcount . '</strong></td><td>Conteo crudo del grupo sin instructor</td></tr>';
    echo '<tr><td>scheduleapproval users mostrados</td><td><strong>' . $schedulepanelusers . '</strong></td><td>Si approved=0 usa preRegistered; si approved=1 usa enroledStudents</td></tr>';
    echo '<tr><td>scheduleapproval waiting mostrado</td><td><strong>' . $schedulepanelwaiting . '</strong></td><td>Si approved=1 siempre muestra 0</td></tr>';
    echo '<tr><td>pre_registration (raw)</td><td>' . $preregraw . '</td><td>Sin deduplicar</td></tr>';
    echo '<tr><td>queue (raw)</td><td>' . $queueraw . '</td><td>Sin deduplicar</td></tr>';
    echo '<tr><td>BBB-attendance relation records</td><td>' . $relationcount . '</td><td>Requisito para que aparezca en teacher dashboard</td></tr>';
    echo '<tr><td>Aparece en teacher dashboard</td><td>' . gmk_dbg_badge($wouldshowindashboard, 'SI', 'NO') . '</td><td>Evaluado con la misma logica del webservice</td></tr>';
    echo '</tbody></table>';

    if (empty($reasons)) {
        echo '<div class="okline">Sin discrepancias obvias detectadas con las reglas actuales.</div>';
    } else {
        echo '<div class="badline">Posibles causas de diferencia:</div>';
        foreach ($reasons as $reason) {
            echo '<div class="reason">- ' . gmk_dbg_h($reason) . '</div>';
        }
    }

    echo '<h4>Detalle de usuarios en groups_members de esta clase</h4>';
    if (empty($groupstudents)) {
        echo '<div class="muted">No hay registros en groups_members para este groupid.</div>';
    } else {
        echo '<table class="dbg-table">';
        echo '<thead><tr><th>userid</th><th>idnumber</th><th>nombre</th><th>en local_learning_users</th><th>en pre_registration</th><th>en queue</th><th>en progre</th></tr></thead><tbody>';
        foreach ($groupstudents as $row) {
            echo '<tr>';
            echo '<td>' . (int)$row->userid . '</td>';
            echo '<td>' . gmk_dbg_h($row->idnumber) . '</td>';
            echo '<td>' . gmk_dbg_h(trim($row->firstname . ' ' . $row->lastname)) . '</td>';
            echo '<td>' . gmk_dbg_badge((int)$row->in_llu === 1, 'SI', 'NO') . '</td>';
            echo '<td>' . gmk_dbg_badge((int)$row->in_prereg === 1, 'SI', 'NO') . '</td>';
            echo '<td>' . gmk_dbg_badge((int)$row->in_queue === 1, 'SI', 'NO') . '</td>';
            echo '<td>' . gmk_dbg_badge((int)$row->in_progre === 1, 'SI', 'NO') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    echo '<h4>Detalle de inclusion en pestaña Estudiantes (TeacherStudentTable)</h4>';
    echo '<div class="muted">Fuente actual del endpoint local_grupomakro_get_student_info:</div>';
    echo '<div class="muted">- Si class.groupid > 0: incluye solo usuarios en groups_members de ese groupid.</div>';
    echo '<div class="muted">- Si class.groupid = 0: antes no incluia nadie (filtraba por groups_members inexistente).</div>';

    echo '<table class="dbg-table">';
    echo '<thead><tr><th>Metric</th><th>Count</th><th>Comentario</th></tr></thead><tbody>';
    echo '<tr><td>group source users</td><td>' . count($groupuids) . '</td><td>groups_members sin instructor</td></tr>';
    echo '<tr><td>progre source users</td><td>' . count($progreuids) . '</td><td>gmk_course_progre sin instructor</td></tr>';
    echo '<tr><td>candidate union users</td><td>' . count($candidateuids) . '</td><td>union(group, progre)</td></tr>';
    echo '<tr><td>included by current query logic</td><td><strong>' . count($currentqueryuids) . '</strong></td><td>logica antigua get_student_info</td></tr>';
    echo '<tr><td>included by fallback logic</td><td><strong>' . count($fallbackqueryuids) . '</strong></td><td>logica esperada para groupid=0</td></tr>';
    echo '<tr><td>included + status Activo</td><td><strong>' . count($fallbackactiveuids) . '</strong></td><td>Coincide con tarjeta "Estudiantes Activos" de la tabla</td></tr>';
    echo '</tbody></table>';

    if (empty($studenttabrows)) {
        echo '<div class="muted">No hay candidatos para listar en detalle.</div>';
    } else {
        echo '<table class="dbg-table">';
        echo '<thead><tr><th>userid</th><th>idnumber</th><th>nombre</th><th>in_group</th><th>in_progre</th><th>has_student_llu</th><th>llu_roles</th><th>llu_plans</th><th>student_status</th><th>status_activo</th><th>current_included</th><th>fallback_included</th></tr></thead><tbody>';
        foreach ($studenttabrows as $r) {
            echo '<tr>';
            echo '<td>' . (int)$r['userid'] . '</td>';
            echo '<td>' . gmk_dbg_h($r['idnumber']) . '</td>';
            echo '<td>' . gmk_dbg_h($r['name']) . '</td>';
            echo '<td>' . gmk_dbg_badge((bool)$r['ingroup'], 'SI', 'NO') . '</td>';
            echo '<td>' . gmk_dbg_badge((bool)$r['inprogre'], 'SI', 'NO') . '</td>';
            echo '<td>' . gmk_dbg_badge((bool)$r['hasstudentllu'], 'SI', 'NO') . '</td>';
            echo '<td>' . gmk_dbg_h($r['llu_roles']) . '</td>';
            echo '<td>' . gmk_dbg_h($r['llu_plans']) . '</td>';
            echo '<td>' . gmk_dbg_h($r['studentstatus']) . '</td>';
            echo '<td>' . gmk_dbg_badge((bool)$r['is_active_status'], 'SI', 'NO') . '</td>';
            echo '<td>' . gmk_dbg_badge((bool)$r['current_included'], 'SI', 'NO') . '</td>';
            echo '<td>' . gmk_dbg_badge((bool)$r['fallback_included'], 'SI', 'NO') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    echo '<h4>JSON del diagnostico</h4>';
    echo '<pre class="dbg-pre">' . gmk_dbg_h(json_encode($diag, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</pre>';
    echo '</div>';
}

echo '</div>';
echo $OUTPUT->footer();
