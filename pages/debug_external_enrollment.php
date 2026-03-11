<?php
/**
 * Debug y reparación de enrollments para clases externas (Buceo/Soldadura)
 * Diagnostica estudiantes desvinculados y permite restaurarlos.
 *
 * @package    local_grupomakro_core
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

// ── AJAX handlers ─────────────────────────────────────────────────────────────
$ajax = optional_param('ajax', '', PARAM_ALPHA);

if ($ajax === 'restore') {
    header('Content-Type: application/json');
    try {
        $classid  = required_param('classid',  PARAM_INT);
        $userid   = required_param('userid',   PARAM_INT);
        $sesskey  = required_param('sesskey',  PARAM_RAW);
        if (!confirm_sesskey($sesskey)) {
            throw new Exception('Sesskey inválido');
        }

        $class = $DB->get_record('gmk_class', ['id' => $classid], '*', MUST_EXIST);
        $user  = $DB->get_record('user',      ['id' => $userid],  'id, firstname, lastname', MUST_EXIST);

        $studentRoleId = $DB->get_field('role', 'id', ['shortname' => 'student']);
        $enrolplugin   = enrol_get_plugin('manual');
        $courseInstance = get_manual_enroll($class->corecourseid);

        $msgs = [];

        // 1. Enrolar en el curso Moodle
        if ($courseInstance && $enrolplugin && $studentRoleId) {
            $enrolplugin->enrol_user($courseInstance, $userid, $studentRoleId);
            $msgs[] = 'Enrolado en curso Moodle ' . $class->corecourseid;
        }

        // 2. Agregar al grupo si existe
        if ($class->groupid) {
            $added = groups_add_member($class->groupid, $userid);
            $msgs[] = $added ? 'Agregado al grupo ' . $class->groupid : 'Ya estaba en el grupo ' . $class->groupid;
        }

        // 3. Asignar clase al progreso del curso
        local_grupomakro_progress_manager::assign_class_to_course_progress($userid, $class);
        $msgs[] = 'Progreso de curso asignado';

        echo json_encode([
            'status'  => 'success',
            'message' => "{$user->firstname} {$user->lastname}: " . implode('; ', $msgs),
        ]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

if ($ajax === 'restore_bulk') {
    header('Content-Type: application/json');
    try {
        $classid = required_param('classid', PARAM_INT);
        $sesskey = required_param('sesskey', PARAM_RAW);
        if (!confirm_sesskey($sesskey)) throw new Exception('Sesskey inválido');

        $class = $DB->get_record('gmk_class', ['id' => $classid], '*', MUST_EXIST);
        $studentRoleId = $DB->get_field('role', 'id', ['shortname' => 'student']);
        $enrolplugin   = enrol_get_plugin('manual');
        $courseInstance = get_manual_enroll($class->corecourseid);

        // Obtener todos los que deberían estar: local_learning_users del learning plan de este curso
        $lpid = $class->learningplanid;
        if (!$lpid) {
            // Fallback: buscar por corecourseid → local_learning_courses → learningplanid
            $lpid = $DB->get_field('local_learning_courses', 'learningplanid',
                                    ['courseid' => $class->corecourseid], IGNORE_MULTIPLE);
        }
        if (!$lpid) throw new Exception("No se encontró learningplanid para la clase $classid");

        $expected = $DB->get_records('local_learning_users', [
            'learningplanid' => $lpid,
            'userroleid'     => $studentRoleId,
        ]);

        // Ya enrolados en el curso Moodle
        $enrolled = get_enrolled_users(context_course::instance($class->corecourseid));
        $enrolledIds = array_column((array)$enrolled, 'id');

        $restored = 0; $skipped = 0; $errors = [];
        foreach ($expected as $llu) {
            try {
                if (in_array($llu->userid, $enrolledIds)) { $skipped++; continue; }
                if ($courseInstance && $enrolplugin) {
                    $enrolplugin->enrol_user($courseInstance, $llu->userid, $studentRoleId);
                }
                if ($class->groupid) {
                    groups_add_member($class->groupid, $llu->userid);
                }
                local_grupomakro_progress_manager::assign_class_to_course_progress($llu->userid, $class);
                $restored++;
            } catch (Throwable $ue) {
                $errors[] = "userid={$llu->userid}: " . $ue->getMessage();
            }
        }

        echo json_encode([
            'status'   => 'success',
            'restored' => $restored,
            'skipped'  => $skipped,
            'errors'   => $errors,
        ]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// ── Parámetros de filtro ───────────────────────────────────────────────────────
$filterPlan  = optional_param('planid',   0, PARAM_INT);
$filterClass = optional_param('classid',  0, PARAM_INT);

// ── Cargar datos ──────────────────────────────────────────────────────────────
// Planes "externos": Buceo Comercial y Soldadura Subacuática
$externalPlans = $DB->get_records_sql(
    "SELECT id, name FROM {local_learning_plans}
      WHERE " . $DB->sql_like('name', ':kw1') . " OR " . $DB->sql_like('name', ':kw2') . "
      ORDER BY name",
    ['kw1' => '%BUCEO%', 'kw2' => '%SOLDADURA%']
);

if ($filterPlan && !isset($externalPlans[$filterPlan])) {
    // Permitir cualquier plan si se especifica manualmente
    $extraPlan = $DB->get_record('local_learning_plans', ['id' => $filterPlan]);
    if ($extraPlan) $externalPlans[$filterPlan] = $extraPlan;
}

// ── Render ─────────────────────────────────────────────────────────────────────
$PAGE->set_url('/local/grupomakro_core/pages/debug_external_enrollment.php');
$PAGE->set_context($context);
$PAGE->set_title('Debug: Enrollments Externos');
echo $OUTPUT->header();

echo '<style>
body { font-size:13px; }
h2 { margin-top:24px; }
table { border-collapse:collapse; width:100%; margin:10px 0; font-size:12px; }
th,td { border:1px solid #ddd; padding:6px 8px; }
th { background:#f2f2f2; position:sticky; top:0; }
.ok   { background:#d4edda; }
.warn { background:#fff3cd; }
.err  { background:#f8d7da; }
.info { background:#d1ecf1; padding:8px; border-radius:4px; margin:6px 0; }
.section { border:2px solid #ccc; border-radius:6px; padding:16px; margin:20px 0; }
.btn { padding:4px 12px; border:none; border-radius:4px; cursor:pointer; color:#fff; font-size:12px; }
.btn-primary { background:#007bff; }
.btn-success { background:#28a745; }
.btn-danger  { background:#dc3545; }
.spinner { display:inline-block; width:14px; height:14px; border:2px solid #eee; border-top-color:#007bff; border-radius:50%; animation:spin 1s linear infinite; }
@keyframes spin { to { transform:rotate(360deg); } }
pre { background:#f4f4f4; padding:8px; font-size:11px; border-radius:4px; overflow-x:auto; }
</style>';

echo '<h1>Debug: Enrollments de Clases Externas (Buceo / Soldadura)</h1>';
echo '<p style="color:#666">Diagnostica estudiantes desvinculados en planes de aprendizaje externos y permite restaurar su matrícula.</p>';

if (empty($externalPlans)) {
    echo '<div class="info" style="background:#f8d7da">No se encontraron planes con nombre BUCEO o SOLDADURA. Use el filtro manual.</div>';
}

// Filtro manual de plan
echo '<form method="get" style="margin:10px 0; display:flex; gap:8px; align-items:center">';
echo 'Plan ID: <input type="number" name="planid" value="' . (int)$filterPlan . '" style="width:80px;padding:4px">';
echo ' Clase ID: <input type="number" name="classid" value="' . (int)$filterClass . '" style="width:80px;padding:4px">';
echo ' <button type="submit" class="btn btn-primary">Filtrar</button>';
echo ' <a href="?" class="btn btn-primary">Resetear</a>';
echo '</form>';

$studentRoleId = $DB->get_field('role', 'id', ['shortname' => 'student']);

foreach ($externalPlans as $plan) {
    if ($filterPlan && $plan->id != $filterPlan) continue;

    echo '<div class="section">';
    echo '<h2>Plan: ' . htmlspecialchars($plan->name) . ' (ID: ' . $plan->id . ')</h2>';

    // Estudiantes matriculados en el plan
    $planStudents = $DB->get_records('local_learning_users', [
        'learningplanid' => $plan->id,
        'userroleid'     => $studentRoleId,
    ]);
    echo '<div class="info">Estudiantes en local_learning_users: <strong>' . count($planStudents) . '</strong></div>';

    // Clases del plan
    $classes = $DB->get_records_sql(
        "SELECT c.*, co.fullname as coursename
           FROM {gmk_class} c
           LEFT JOIN {course} co ON co.id = c.corecourseid
          WHERE c.learningplanid = :lpid
          ORDER BY c.id DESC",
        ['lpid' => $plan->id]
    );

    if (empty($classes)) {
        echo '<div class="info" style="background:#fff3cd">No hay clases (gmk_class) registradas para este plan.</div>';
        echo '</div>';
        continue;
    }

    foreach ($classes as $class) {
        if ($filterClass && $class->id != $filterClass) continue;

        echo '<h3 style="margin-top:16px">Clase ID ' . $class->id . ': ' . htmlspecialchars($class->name)
           . ' <small style="color:#888">(corecourseid=' . $class->corecourseid
           . ', groupid=' . $class->groupid
           . ', approved=' . $class->approved
           . ', periodid=' . $class->periodid . ')</small></h3>';

        // ── Estado del grupo ──────────────────────────────────────────────
        if ($class->groupid) {
            $group = $DB->get_record('groups', ['id' => $class->groupid]);
            if ($group) {
                echo '<div class="info">Grupo Moodle: <strong>' . htmlspecialchars($group->name) . '</strong> (id=' . $group->id . ')</div>';
                $groupMembers = $DB->get_records('groups_members', ['groupid' => $class->groupid]);
                $groupMemberIds = array_column((array)$groupMembers, 'userid');
            } else {
                echo '<div class="info" style="background:#f8d7da">groupid=' . $class->groupid . ' pero el grupo NO existe en la tabla groups.</div>';
                $groupMemberIds = [];
            }
        } else {
            echo '<div class="info" style="background:#fff3cd">Sin grupo Moodle asignado (groupid=0).</div>';
            $groupMemberIds = [];
        }

        // ── Enrolados en el curso Moodle ──────────────────────────────────
        $courseCtx = context_course::instance($class->corecourseid, IGNORE_MISSING);
        $enrolledMoodleIds = [];
        if ($courseCtx) {
            $enrolled = get_enrolled_users($courseCtx, '', 0, 'u.id');
            $enrolledMoodleIds = array_keys((array)$enrolled);
        }

        // ── Comparar con local_learning_users (fuente de verdad) ──────────
        $expectedIds = array_column((array)$planStudents, 'userid');

        $missing_course  = array_diff($expectedIds, $enrolledMoodleIds);  // en LP pero no en curso
        $missing_group   = $class->groupid ? array_diff($expectedIds, $groupMemberIds) : [];
        $extra_course    = array_diff($enrolledMoodleIds, $expectedIds);   // en curso pero no en LP

        echo '<table>';
        echo '<tr>';
        echo '<th>Indicador</th><th>Cantidad</th><th>Detalle</th>';
        echo '</tr>';

        $rowClass = count($missing_course) === 0 ? 'ok' : 'err';
        echo "<tr class='$rowClass'>";
        echo '<td>Esperados (local_learning_users)</td><td>' . count($expectedIds) . '</td>';
        echo '<td>IDs: ' . implode(', ', array_slice($expectedIds, 0, 10)) . (count($expectedIds) > 10 ? '…' : '') . '</td>';
        echo '</tr>';

        $rowClass = count($enrolledMoodleIds) > 0 ? 'ok' : 'err';
        echo "<tr class='$rowClass'>";
        echo '<td>Enrolados en curso Moodle</td><td>' . count($enrolledMoodleIds) . '</td>';
        echo '<td>IDs: ' . implode(', ', array_slice($enrolledMoodleIds, 0, 10)) . (count($enrolledMoodleIds) > 10 ? '…' : '') . '</td>';
        echo '</tr>';

        $rowClass = count($missing_course) === 0 ? 'ok' : 'err';
        echo "<tr class='$rowClass'>";
        echo '<td>FALTANTES en curso Moodle</td><td>' . count($missing_course) . '</td>';
        echo '<td>' . implode(', ', array_slice($missing_course, 0, 20)) . (count($missing_course) > 20 ? '…' : '') . '</td>';
        echo '</tr>';

        if ($class->groupid) {
            $rowClass = count($missing_group) === 0 ? 'ok' : 'warn';
            echo "<tr class='$rowClass'>";
            echo '<td>FALTANTES en grupo Moodle</td><td>' . count($missing_group) . '</td>';
            echo '<td>' . implode(', ', array_slice($missing_group, 0, 20)) . (count($missing_group) > 20 ? '…' : '') . '</td>';
            echo '</tr>';
        }

        if (!empty($extra_course)) {
            echo "<tr class='warn'>";
            echo '<td>Extras en curso (no en LP)</td><td>' . count($extra_course) . '</td>';
            echo '<td>' . implode(', ', array_slice($extra_course, 0, 10)) . '</td>';
            echo '</tr>';
        }
        echo '</table>';

        // ── Tabla detalle de faltantes ────────────────────────────────────
        if (!empty($missing_course)) {
            $missingUsers = $DB->get_records_list('user', 'id', $missing_course, 'lastname, firstname', 'id, firstname, lastname, email, username, suspended');
            echo '<h4>Detalle de faltantes en curso Moodle</h4>';
            echo '<div id="progress-' . $class->id . '" style="display:none;margin:6px 0;padding:8px;background:#f8f9fa;border-radius:4px">';
            echo '<div style="height:8px;background:#e9ecef;border-radius:4px;margin:4px 0">';
            echo '<div id="pbar-' . $class->id . '" style="height:100%;background:#28a745;border-radius:4px;width:0;transition:width .3s"></div>';
            echo '</div>';
            echo '<div id="plog-' . $class->id . '" style="max-height:150px;overflow-y:auto;font-size:11px"></div>';
            echo '</div>';

            echo '<table id="tbl-' . $class->id . '">';
            echo '<tr><th><input type="checkbox" onclick="toggleChk(this,' . $class->id . ')"></th>';
            echo '<th>ID</th><th>Nombre</th><th>Email</th><th>Username</th><th>Suspendido</th><th>Estado</th></tr>';
            foreach ($missingUsers as $u) {
                $susp = $u->suspended ? '<span style="color:red">Sí</span>' : 'No';
                echo '<tr class="err" data-uid="' . $u->id . '">';
                echo '<td><input type="checkbox" class="chk-' . $class->id . '" value="' . $u->id . '"></td>';
                echo '<td>' . $u->id . '</td>';
                echo '<td>' . htmlspecialchars($u->firstname . ' ' . $u->lastname) . '</td>';
                echo '<td>' . htmlspecialchars($u->email) . '</td>';
                echo '<td>' . htmlspecialchars($u->username) . '</td>';
                echo '<td>' . $susp . '</td>';
                echo '<td class="st">Pendiente</td>';
                echo '</tr>';
            }
            echo '</table>';

            echo '<div style="margin-top:8px;display:flex;gap:8px">';
            echo '<button class="btn btn-success" onclick="restoreSelected(' . $class->id . ')">Restaurar seleccionados</button>';
            echo '<button class="btn btn-danger"  onclick="restoreAll(' . $class->id . ')">Restaurar TODOS (' . count($missing_course) . ')</button>';
            echo '</div>';
        } else {
            echo '<div class="info" style="background:#d4edda">Todos los estudiantes del plan están enrolados en el curso Moodle.</div>';
        }
    }

    echo '</div>'; // section
}

// ── JavaScript ─────────────────────────────────────────────────────────────────
?>
<script>
const sesskey = <?php echo json_encode(sesskey()); ?>;
const ajaxUrl = <?php echo json_encode(qualified_me()); ?>;

function toggleChk(src, classid) {
    document.querySelectorAll('.chk-' + classid).forEach(c => c.checked = src.checked);
}

async function restoreSelected(classid) {
    const ids = Array.from(document.querySelectorAll('.chk-' + classid + ':checked')).map(c => +c.value);
    if (!ids.length) { alert('Selecciona al menos un estudiante'); return; }
    await runRestore(classid, ids);
}

async function restoreAll(classid) {
    const ids = Array.from(document.querySelectorAll('.chk-' + classid)).map(c => +c.value);
    if (!confirm('¿Restaurar ' + ids.length + ' estudiantes para la clase ' + classid + '?')) return;
    await runRestore(classid, ids);
}

async function runRestore(classid, ids) {
    const prog  = document.getElementById('progress-' + classid);
    const pbar  = document.getElementById('pbar-' + classid);
    const plog  = document.getElementById('plog-' + classid);
    const table = document.getElementById('tbl-' + classid);
    prog.style.display = 'block';
    plog.innerHTML = '';
    let done = 0;

    for (const uid of ids) {
        const row = table ? table.querySelector('tr[data-uid="' + uid + '"]') : null;
        if (row) row.querySelector('.st').innerHTML = '<span class="spinner"></span>';

        try {
            const fd = new FormData();
            fd.append('ajax', 'restore');
            fd.append('classid', classid);
            fd.append('userid', uid);
            fd.append('sesskey', sesskey);
            const res = await fetch(ajaxUrl, { method: 'POST', body: fd });
            const d   = await res.json();
            done++;
            pbar.style.width = Math.round(done / ids.length * 100) + '%';

            const logLine = document.createElement('div');
            logLine.style.cssText = 'padding:2px 0;border-bottom:1px solid #eee';
            if (d.status === 'success') {
                logLine.style.color = '#155724';
                logLine.textContent = '✓ ' + d.message;
                if (row) { row.className = 'ok'; row.querySelector('.st').textContent = 'Restaurado'; }
            } else {
                logLine.style.color = '#721c24';
                logLine.textContent = '✗ uid=' + uid + ': ' + d.message;
                if (row) { row.className = 'err'; row.querySelector('.st').textContent = 'Error'; }
            }
            plog.appendChild(logLine);
            plog.scrollTop = plog.scrollHeight;
        } catch (e) {
            done++;
            const logLine = document.createElement('div');
            logLine.style.color = '#721c24';
            logLine.textContent = '✗ uid=' + uid + ' excepción: ' + e.message;
            plog.appendChild(logLine);
        }
    }

    const summary = document.createElement('div');
    summary.style.cssText = 'font-weight:bold;margin-top:6px';
    summary.textContent = 'Completado: ' + done + '/' + ids.length;
    plog.appendChild(summary);

    if (done === ids.length && confirm('Proceso completado. ¿Recargar para ver resultados actualizados?')) {
        location.reload();
    }
}
</script>
<?php
echo $OUTPUT->footer();
