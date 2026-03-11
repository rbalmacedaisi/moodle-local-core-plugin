<?php
/**
 * Debug: Clases externas en el tablero de planificación
 *
 * Muestra las clases de OTROS periodos que aparecen en el tablero del periodo seleccionado
 * (porque sus fechas solapan), lista sus estudiantes (gmk_class_queue + gmk_course_progre)
 * y compara con lo que realmente está mostrando el planificador.
 *
 * @package    local_grupomakro_core
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

// ── AJAX: restaurar enrollment individual ────────────────────────────────────
$ajax = optional_param('ajax', '', PARAM_ALPHA);

if ($ajax === 'restore') {
    header('Content-Type: application/json');
    try {
        $classid = required_param('classid', PARAM_INT);
        $userid  = required_param('userid',  PARAM_INT);
        require_sesskey();

        $class = $DB->get_record('gmk_class', ['id' => $classid], '*', MUST_EXIST);
        $user  = $DB->get_record('user',      ['id' => $userid],  'id,firstname,lastname', MUST_EXIST);

        $studentRoleId = $DB->get_field('role', 'id', ['shortname' => 'student']);
        $enrolplugin   = enrol_get_plugin('manual');
        $courseInstance = get_manual_enroll($class->corecourseid);
        $msgs = [];

        if ($courseInstance && $enrolplugin && $studentRoleId) {
            $enrolplugin->enrol_user($courseInstance, $userid, $studentRoleId);
            $msgs[] = 'enrolado en curso ' . $class->corecourseid;
        }
        if ($class->groupid) {
            groups_add_member($class->groupid, $userid);
            $msgs[] = 'agregado al grupo ' . $class->groupid;
        }
        // Restaurar en gmk_class_queue si no existe
        if (!$DB->record_exists('gmk_class_queue', ['classid' => $classid, 'userid' => $userid])) {
            $q = new stdClass();
            $q->classid      = $classid;
            $q->userid       = $userid;
            $q->timecreated  = time();
            $q->timemodified = time();
            $DB->insert_record('gmk_class_queue', $q);
            $msgs[] = 'reinsertado en gmk_class_queue';
        }

        echo json_encode(['status' => 'success', 'message' => implode('; ', $msgs)]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// ── Parámetros de página ─────────────────────────────────────────────────────
$activePeriodId = optional_param('periodid', 0, PARAM_INT);

// Cargar todos los periodos para el selector
$allPeriods = $DB->get_records('gmk_academic_periods', null, 'id DESC', 'id, name, startdate, enddate');

// Si no se seleccionó periodo, usar el primero (más reciente)
if (!$activePeriodId && !empty($allPeriods)) {
    $first = reset($allPeriods);
    $activePeriodId = $first->id;
}

$activePeriod = $activePeriodId ? ($allPeriods[$activePeriodId] ?? null) : null;

// ── Render ───────────────────────────────────────────────────────────────────
$PAGE->set_url('/local/grupomakro_core/pages/debug_external_enrollment.php');
$PAGE->set_context($context);
$PAGE->set_title('Debug: Clases Externas en Tablero');
echo $OUTPUT->header();

?>
<style>
body { font-size: 13px; }
h2 { margin-top: 20px; border-bottom: 2px solid #ddd; padding-bottom: 6px; }
h3 { margin-top: 14px; color: #333; }
table { border-collapse: collapse; width: 100%; margin: 8px 0; font-size: 12px; }
th, td { border: 1px solid #ddd; padding: 5px 8px; vertical-align: top; }
th { background: #f2f2f2; font-weight: bold; position: sticky; top: 0; z-index: 1; }
.ok   { background: #d4edda; }
.warn { background: #fff3cd; }
.err  { background: #f8d7da; }
.info-box { background: #d1ecf1; border: 1px solid #bee5eb; border-radius: 4px; padding: 8px 12px; margin: 6px 0; font-size: 12px; }
.warn-box { background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; padding: 8px 12px; margin: 6px 0; }
.err-box  { background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; padding: 8px 12px; margin: 6px 0; }
.ok-box   { background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; padding: 8px 12px; margin: 6px 0; }
.section  { border: 2px solid #ccc; border-radius: 6px; padding: 14px 18px; margin: 16px 0; }
.btn { padding: 4px 12px; border: none; border-radius: 4px; cursor: pointer; color: #fff; font-size: 12px; }
.btn-primary { background: #007bff; }
.btn-success { background: #28a745; }
.btn-danger  { background: #dc3545; }
.btn:disabled { opacity: .5; cursor: not-allowed; }
.spinner { display:inline-block; width:12px; height:12px; border:2px solid #eee; border-top-color:#007bff; border-radius:50%; animation:spin 1s linear infinite; vertical-align:middle; }
@keyframes spin { to { transform:rotate(360deg); } }
.badge { display:inline-block; padding:2px 7px; border-radius:10px; font-size:11px; font-weight:bold; color:#fff; }
.badge-danger  { background:#dc3545; }
.badge-success { background:#28a745; }
.badge-warning { background:#ffc107; color:#333; }
.prog-bar { height:6px; background:#e9ecef; border-radius:3px; margin:4px 0; }
.prog-fill { height:100%; background:#28a745; border-radius:3px; transition:width .2s; }
.plog { max-height:120px; overflow-y:auto; font-size:11px; margin-top:4px; }
.tag-ext { background:#6c757d; color:#fff; padding:1px 6px; border-radius:3px; font-size:11px; }
</style>

<h1>Debug: Clases Externas en el Tablero de Planificación</h1>
<p style="color:#666;margin:0 0 12px">
  Muestra las clases de <strong>otros periodos</strong> que aparecen en el tablero porque sus fechas solapan con el periodo activo.
  Compara los estudiantes en BD con los que el tablero está mostrando.
</p>

<?php
// ── Selector de periodo ──────────────────────────────────────────────────────
echo '<form method="get" style="display:flex;gap:8px;align-items:center;margin-bottom:16px;flex-wrap:wrap">';
echo '<label><strong>Periodo activo (tablero):</strong></label>';
echo '<select name="periodid" style="padding:4px 8px;border-radius:4px;border:1px solid #ccc">';
foreach ($allPeriods as $p) {
    $sel = ($p->id == $activePeriodId) ? ' selected' : '';
    echo '<option value="' . $p->id . '"' . $sel . '>' . htmlspecialchars($p->name) . ' (ID:' . $p->id . ')</option>';
}
echo '</select>';
echo '<button type="submit" class="btn btn-primary">Ver clases externas</button>';
echo '</form>';

if (!$activePeriod) {
    echo '<div class="err-box">No se encontró el periodo seleccionado.</div>';
    echo $OUTPUT->footer();
    exit;
}

echo '<div class="info-box">Periodo activo: <strong>' . htmlspecialchars($activePeriod->name) . '</strong> &nbsp;|&nbsp; ';
echo 'Inicio: ' . date('d/m/Y', $activePeriod->startdate) . ' &nbsp;|&nbsp; ';
echo 'Fin: '    . date('d/m/Y', $activePeriod->enddate) . '</div>';

// ── Diagnóstico: clases de Buceo y Soldadura en gmk_class ───────────────────
echo '<div class="section">';
echo '<h2>Diagnóstico: clases de Buceo y Soldadura en gmk_class</h2>';

$buceoSoldClasses = $DB->get_records_sql(
    "SELECT c.id, c.name, c.periodid, ap.name as periodname,
            c.groupid, c.approved, c.initdate, c.enddate,
            c.corecourseid, co.fullname as coursename,
            lp.id as lpid, lp.name as planname
       FROM {gmk_class} c
       LEFT JOIN {gmk_academic_periods} ap ON ap.id = c.periodid
       LEFT JOIN {course}               co ON co.id = c.corecourseid
       LEFT JOIN {local_learning_plans} lp ON lp.id = c.learningplanid
      WHERE c.learningplanid IN (
          SELECT id FROM {local_learning_plans}
           WHERE " . $DB->sql_like('name', ':kw1') . "
              OR " . $DB->sql_like('name', ':kw2') . "
      )
      ORDER BY c.id DESC",
    ['kw1' => '%BUCEO%', 'kw2' => '%SOLDADURA%']
);

if (empty($buceoSoldClasses)) {
    echo '<div class="err-box"><strong>No se encontraron clases en gmk_class para los planes BUCEO ni SOLDADURA.</strong><br>';
    echo 'Esto confirma que fueron eliminadas durante los intentos anteriores de publicación.</div>';

    // Mostrar qué planes existen con esos nombres
    $plans = $DB->get_records_sql(
        "SELECT id, name FROM {local_learning_plans}
          WHERE " . $DB->sql_like('name', ':kw1') . " OR " . $DB->sql_like('name', ':kw2'),
        ['kw1' => '%BUCEO%', 'kw2' => '%SOLDADURA%']
    );
    if ($plans) {
        echo '<div class="warn-box">Planes encontrados en local_learning_plans:<ul>';
        foreach ($plans as $pl) {
            $studentCount = $DB->count_records('local_learning_users', ['learningplanid' => $pl->id]);
            echo '<li>ID=' . $pl->id . ': <strong>' . htmlspecialchars($pl->name) . '</strong> — ' . $studentCount . ' estudiante(s) en local_learning_users</li>';
        }
        echo '</ul></div>';
    }
} else {
    echo '<div class="ok-box">Se encontraron <strong>' . count($buceoSoldClasses) . '</strong> clases.</div>';
    echo '<table>';
    echo '<tr><th>ID</th><th>Nombre clase</th><th>Periodo</th><th>Plan</th><th>Curso Moodle</th><th>groupid</th><th>approved</th><th>initdate</th><th>enddate</th></tr>';
    foreach ($buceoSoldClasses as $cls) {
        $overlap = ($cls->initdate <= $activePeriod->enddate && $cls->enddate >= $activePeriod->startdate);
        $rowCls  = $overlap ? 'warn' : '';
        echo '<tr class="' . $rowCls . '">';
        echo '<td>' . $cls->id . '</td>';
        echo '<td>' . htmlspecialchars($cls->name) . '</td>';
        echo '<td>' . htmlspecialchars($cls->periodname ?? 'ID:'.$cls->periodid) . '</td>';
        echo '<td>' . htmlspecialchars($cls->planname ?? 'ID:'.$cls->lpid) . '</td>';
        echo '<td>' . htmlspecialchars($cls->coursename ?? 'ID:'.$cls->corecourseid) . '</td>';
        echo '<td>' . $cls->groupid . '</td>';
        echo '<td>' . $cls->approved . '</td>';
        echo '<td>' . ($cls->initdate ? date('d/m/Y', $cls->initdate) : '—') . '</td>';
        echo '<td>' . ($cls->enddate  ? date('d/m/Y', $cls->enddate)  : '—') . '</td>';
        echo '</tr>';
    }
    echo '</table>';
    echo '<small style="color:#666">Filas amarillas = solapan con el periodo activo (aparecen en el tablero como externas)</small>';
}
echo '</div>'; // section diagnóstico

// ── Obtener clases externas (misma lógica que get_generated_schedules) ───────
// Clases de OTRO periodo cuyas fechas solapan con el periodo activo
$externalClasses = $DB->get_records_sql(
    "SELECT c.id, c.name, c.periodid, c.corecourseid, c.groupid, c.learningplanid,
            c.instructorid, c.approved,
            c.initdate, c.enddate, c.inittime, c.endtime,
            u.firstname, u.lastname,
            co.fullname as coursename,
            lp.name as planname,
            ap.name as periodname
       FROM {gmk_class} c
       LEFT JOIN {user}                u  ON u.id = c.instructorid
       LEFT JOIN {course}              co ON co.id = c.corecourseid
       LEFT JOIN {local_learning_plans} lp ON lp.id = c.learningplanid
       LEFT JOIN {gmk_academic_periods} ap ON ap.id = c.periodid
      WHERE c.periodid != :pid
        AND c.initdate <= :enddate
        AND c.enddate  >= :startdate
      ORDER BY lp.name, c.name",
    [
        'pid'       => $activePeriodId,
        'startdate' => $activePeriod->startdate,
        'enddate'   => $activePeriod->enddate,
    ]
);

if (empty($externalClasses)) {
    echo '<div class="ok-box">No hay clases externas que solapen con el periodo <strong>' . htmlspecialchars($activePeriod->name) . '</strong>.</div>';
    echo $OUTPUT->footer();
    exit;
}

echo '<div class="info-box">Se encontraron <strong>' . count($externalClasses) . '</strong> clases externas que solapan con este periodo.</div>';

// ── Agrupar por plan de aprendizaje ─────────────────────────────────────────
$byPlan = [];
foreach ($externalClasses as $cls) {
    $planKey = $cls->planname ?: ('Plan ID ' . $cls->learningplanid);
    $byPlan[$planKey][] = $cls;
}

foreach ($byPlan as $planName => $classes):
?>
<div class="section">
  <h2><?php echo htmlspecialchars($planName); ?> <span class="tag-ext">EXTERNO</span></h2>

  <?php foreach ($classes as $cls):
      // ── Datos en BD para esta clase ──────────────────────────────────────
      $queueStudents = $DB->get_records_sql(
          "SELECT q.userid, u.firstname, u.lastname, u.email, u.idnumber, u.username, u.suspended
             FROM {gmk_class_queue} q
             JOIN {user} u ON u.id = q.userid AND u.deleted = 0
            WHERE q.classid = :cid
            ORDER BY u.lastname, u.firstname",
          ['cid' => $cls->id]
      );
      $progreStudents = $DB->get_records_sql(
          "SELECT DISTINCT p.userid, u.firstname, u.lastname, u.email, u.idnumber, u.username, u.suspended
             FROM {gmk_course_progre} p
             JOIN {user} u ON u.id = p.userid AND u.deleted = 0
            WHERE p.classid = :cid
            ORDER BY u.lastname, u.firstname",
          ['cid' => $cls->id]
      );

      // Todos los estudiantes únicos (idnumber como clave)
      $allStudentsById = []; // userid → user obj
      foreach ($queueStudents  as $s) $allStudentsById[$s->userid] = $s;
      foreach ($progreStudents as $s) $allStudentsById[$s->userid] = $s;

      // ── Lo que el tablero muestra (mismo cálculo que get_generated_schedules) ──
      $boardIdnumbers = array_values(array_unique(array_filter(array_merge(
          $DB->get_fieldset_sql("SELECT u.idnumber FROM {user} u JOIN {gmk_class_queue} q ON u.id = q.userid WHERE q.classid = ? AND u.deleted = 0", [$cls->id]),
          $DB->get_fieldset_sql("SELECT u.idnumber FROM {user} u JOIN {gmk_course_progre} p ON u.id = p.userid WHERE p.classid = ? AND u.deleted = 0", [$cls->id])
      ))));
      $boardCount = $DB->count_records('gmk_class_queue', ['classid' => $cls->id])
                  + $DB->count_records('gmk_course_progre', ['classid' => $cls->id]);

      // ── Estado del grupo Moodle ──────────────────────────────────────────
      $groupExists = $cls->groupid ? $DB->record_exists('groups', ['id' => $cls->groupid]) : false;
      $groupMemberIds = [];
      if ($cls->groupid && $groupExists) {
          $groupMemberIds = $DB->get_fieldset_select('groups_members', 'userid', 'groupid = :gid', ['gid' => $cls->groupid]);
      }

      // ── Enrolados en el curso Moodle ────────────────────────────────────
      $enrolledMoodleIds = [];
      $courseCtx = context_course::instance($cls->corecourseid, IGNORE_MISSING);
      if ($courseCtx) {
          $enrolled = get_enrolled_users($courseCtx, '', 0, 'u.id');
          $enrolledMoodleIds = array_keys((array)$enrolled);
      }

      // ── Calcular discrepancias ───────────────────────────────────────────
      $bdStudentIds     = array_keys($allStudentsById);
      $missingFromGroup = array_diff($bdStudentIds, $groupMemberIds);
      $missingFromMoodle= array_diff($bdStudentIds, $enrolledMoodleIds);
  ?>
  <h3>
    Clase ID <?php echo $cls->id; ?>:
    <?php echo htmlspecialchars($cls->name); ?>
    <small style="color:#888;font-weight:normal">
      &nbsp;| Periodo: <?php echo htmlspecialchars($cls->periodname ?? ('ID:'.$cls->periodid)); ?>
      &nbsp;| groupid=<?php echo $cls->groupid; ?>
      &nbsp;| approved=<?php echo $cls->approved; ?>
    </small>
  </h3>

  <?php if ($cls->instructorid): ?>
  <div class="info-box" style="font-size:11px">
    Docente: <strong><?php echo htmlspecialchars($cls->firstname . ' ' . $cls->lastname); ?></strong>
    (id=<?php echo $cls->instructorid; ?>) &nbsp;|&nbsp;
    Curso Moodle: <?php echo htmlspecialchars($cls->coursename ?? 'ID:'.$cls->corecourseid); ?>
    (id=<?php echo $cls->corecourseid; ?>) &nbsp;|&nbsp;
    Fechas: <?php echo date('d/m/Y', $cls->initdate) . ' – ' . date('d/m/Y', $cls->enddate); ?>
  </div>
  <?php endif; ?>

  <!-- Resumen de estado -->
  <table style="width:auto;min-width:500px;margin-bottom:10px">
    <tr>
      <th>Indicador</th><th>Valor</th><th>Estado</th>
    </tr>
    <tr class="<?php echo $boardCount > 0 ? 'ok' : 'warn'; ?>">
      <td>Tablero: studentCount</td>
      <td><strong><?php echo $boardCount; ?></strong></td>
      <td><?php echo $boardCount > 0 ? 'OK' : 'Sin estudiantes en tablero'; ?></td>
    </tr>
    <tr class="<?php echo count($boardIdnumbers) > 0 ? 'ok' : 'warn'; ?>">
      <td>Tablero: studentIds (idnumbers únicos)</td>
      <td><strong><?php echo count($boardIdnumbers); ?></strong></td>
      <td><?php echo implode(', ', array_slice($boardIdnumbers, 0, 8)) . (count($boardIdnumbers) > 8 ? '…' : ''); ?></td>
    </tr>
    <tr class="<?php echo count($queueStudents) > 0 ? 'ok' : 'warn'; ?>">
      <td>gmk_class_queue</td>
      <td><strong><?php echo count($queueStudents); ?></strong></td>
      <td>Registros en cola</td>
    </tr>
    <tr class="<?php echo count($progreStudents) > 0 ? 'ok' : 'warn'; ?>">
      <td>gmk_course_progre</td>
      <td><strong><?php echo count($progreStudents); ?></strong></td>
      <td>Registros de progreso</td>
    </tr>
    <tr class="<?php echo ($cls->groupid && $groupExists) ? 'ok' : 'err'; ?>">
      <td>Grupo Moodle</td>
      <td><?php echo $cls->groupid; ?></td>
      <td><?php echo $cls->groupid ? ($groupExists ? 'Existe (' . count($groupMemberIds) . ' miembros)' : '<span style="color:red">ID guardado pero grupo NO existe</span>') : 'Sin grupo'; ?></td>
    </tr>
    <tr class="<?php echo count($enrolledMoodleIds) > 0 ? 'ok' : 'warn'; ?>">
      <td>Enrolados en curso Moodle</td>
      <td><strong><?php echo count($enrolledMoodleIds); ?></strong></td>
      <td>&nbsp;</td>
    </tr>
    <?php if (!empty($missingFromMoodle)): ?>
    <tr class="err">
      <td>FALTANTES en curso Moodle</td>
      <td><strong><?php echo count($missingFromMoodle); ?></strong></td>
      <td>UIDs: <?php echo implode(', ', array_slice($missingFromMoodle, 0, 10)); ?></td>
    </tr>
    <?php endif; ?>
    <?php if (!empty($missingFromGroup) && $cls->groupid && $groupExists): ?>
    <tr class="warn">
      <td>FALTANTES en grupo Moodle</td>
      <td><strong><?php echo count($missingFromGroup); ?></strong></td>
      <td>UIDs: <?php echo implode(', ', array_slice($missingFromGroup, 0, 10)); ?></td>
    </tr>
    <?php endif; ?>
  </table>

  <!-- Tabla detalle de estudiantes -->
  <?php if (!empty($allStudentsById)): ?>
  <details style="margin-top:8px">
    <summary style="cursor:pointer;font-weight:bold">
      Ver <?php echo count($allStudentsById); ?> estudiantes
      <?php if (!empty($missingFromMoodle)): ?>
        <span class="badge badge-danger"><?php echo count($missingFromMoodle); ?> desvinculados del curso</span>
      <?php endif; ?>
    </summary>

    <div id="prog-<?php echo $cls->id; ?>" style="display:none;margin:6px 0">
      <div class="prog-bar"><div class="prog-fill" id="pbar-<?php echo $cls->id; ?>" style="width:0"></div></div>
      <div class="plog" id="plog-<?php echo $cls->id; ?>"></div>
    </div>

    <table id="tbl-<?php echo $cls->id; ?>">
      <tr>
        <th><input type="checkbox" onclick="toggleChk(this,<?php echo $cls->id; ?>)"></th>
        <th>UID</th><th>Documento (idnumber)</th><th>Nombre</th><th>Username</th>
        <th>queue</th><th>progre</th>
        <th>Grupo Moodle</th><th>Enrolado</th><th>Suspendido</th>
        <th>Acción</th>
      </tr>
      <?php foreach ($allStudentsById as $uid => $s):
          $inQueue  = isset($queueStudents[$uid]);
          $inProgre = isset($progreStudents[$uid]);
          $inGroup  = in_array($uid, $groupMemberIds);
          $inMoodle = in_array($uid, $enrolledMoodleIds);
          $rowCls   = (!$inMoodle) ? 'err' : (!$inGroup && $cls->groupid ? 'warn' : 'ok');
      ?>
      <tr class="<?php echo $rowCls; ?>" data-uid="<?php echo $uid; ?>">
        <td><input type="checkbox" class="chk-<?php echo $cls->id; ?>" value="<?php echo $uid; ?>"></td>
        <td><?php echo $uid; ?></td>
        <td><?php echo htmlspecialchars($s->idnumber ?? ''); ?></td>
        <td><?php echo htmlspecialchars($s->firstname . ' ' . $s->lastname); ?></td>
        <td><?php echo htmlspecialchars($s->username); ?></td>
        <td><?php echo $inQueue  ? '✓' : '—'; ?></td>
        <td><?php echo $inProgre ? '✓' : '—'; ?></td>
        <td><?php echo $inGroup  ? '✓' : '<span style="color:orange">✗</span>'; ?></td>
        <td><?php echo $inMoodle ? '✓' : '<span style="color:red;font-weight:bold">✗ FALTA</span>'; ?></td>
        <td><?php echo $s->suspended ? '<span style="color:red">Sí</span>' : 'No'; ?></td>
        <td class="st-<?php echo $cls->id; ?>">
          <?php if (!$inMoodle || (!$inGroup && $cls->groupid)): ?>
          <button class="btn btn-success" style="padding:2px 8px;font-size:11px"
            onclick="restoreOne(<?php echo $cls->id; ?>, <?php echo $uid; ?>, this)">Restaurar</button>
          <?php else: ?>
          <span style="color:green;font-size:11px">OK</span>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>

    <?php
    $missingCount = count($missingFromMoodle);
    if ($missingCount > 0):
    ?>
    <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap">
      <button class="btn btn-success" onclick="restoreSelected(<?php echo $cls->id; ?>)">
        Restaurar seleccionados
      </button>
      <button class="btn btn-danger" onclick="restoreAll(<?php echo $cls->id; ?>, <?php echo json_encode(array_keys($allStudentsById)); ?>)">
        Restaurar TODOS los desvinculados (<?php echo $missingCount; ?>)
      </button>
    </div>
    <?php endif; ?>
  </details>
  <?php else: ?>
  <div class="warn-box">Esta clase no tiene estudiantes en gmk_class_queue ni gmk_course_progre.</div>
  <?php endif; ?>

  <?php endforeach; ?>
</div>
<?php endforeach; ?>

<script>
const SESSKEY = <?php echo json_encode(sesskey()); ?>;
const AJAX_URL = <?php echo json_encode(
    (new moodle_url('/local/grupomakro_core/pages/debug_external_enrollment.php'))->out(false)
); ?>;

function toggleChk(src, classid) {
    document.querySelectorAll('.chk-' + classid).forEach(c => c.checked = src.checked);
}

function restoreOne(classid, userid, btn) {
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span>';
    doRestore(classid, [userid], null, null, null).then(() => { btn.disabled = false; });
}

function restoreSelected(classid) {
    const ids = [...document.querySelectorAll('.chk-' + classid + ':checked')].map(c => +c.value);
    if (!ids.length) { alert('Selecciona al menos un estudiante'); return; }
    doRestore(classid, ids, 'prog-'+classid, 'pbar-'+classid, 'plog-'+classid);
}

function restoreAll(classid, allIds) {
    if (!confirm('¿Restaurar ' + allIds.length + ' estudiantes para la clase ' + classid + '?')) return;
    doRestore(classid, allIds, 'prog-'+classid, 'pbar-'+classid, 'plog-'+classid);
}

async function doRestore(classid, ids, progId, pbarId, plogId) {
    const prog = progId ? document.getElementById(progId) : null;
    const pbar = pbarId ? document.getElementById(pbarId) : null;
    const plog = plogId ? document.getElementById(plogId) : null;
    if (prog) { prog.style.display = 'block'; if(plog) plog.innerHTML = ''; }

    let done = 0;
    for (const uid of ids) {
        try {
            const fd = new FormData();
            fd.append('ajax', 'restore');
            fd.append('classid', classid);
            fd.append('userid', uid);
            fd.append('sesskey', SESSKEY);
            const res = await fetch(AJAX_URL, { method: 'POST', body: fd });
            const d = await res.json();
            done++;
            if (pbar) pbar.style.width = Math.round(done / ids.length * 100) + '%';
            if (plog) {
                const ln = document.createElement('div');
                ln.style.cssText = 'padding:1px 0;border-bottom:1px solid #eee';
                ln.style.color = d.status === 'success' ? '#155724' : '#721c24';
                ln.textContent = (d.status === 'success' ? '✓' : '✗') + ' uid=' + uid + ': ' + d.message;
                plog.appendChild(ln);
                plog.scrollTop = plog.scrollHeight;
            }
            // Actualizar fila
            const row = document.querySelector('#tbl-' + classid + ' tr[data-uid="' + uid + '"]');
            if (row && d.status === 'success') {
                row.className = 'ok';
                const btn = row.querySelector('button');
                if (btn) { btn.disabled = true; btn.textContent = 'Restaurado'; }
            }
        } catch(e) {
            if (plog) {
                const ln = document.createElement('div');
                ln.style.color = '#721c24';
                ln.textContent = '✗ uid=' + uid + ': ' + e.message;
                plog.appendChild(ln);
            }
            done++;
        }
    }
    if (done === ids.length) {
        if (plog) {
            const s = document.createElement('div');
            s.style.cssText = 'font-weight:bold;margin-top:4px';
            s.textContent = 'Completado: ' + done + '/' + ids.length;
            plog.appendChild(s);
        }
        if (done > 0 && confirm('Proceso completado. ¿Recargar para ver resultados actualizados?')) {
            location.reload();
        }
    }
}
</script>
<?php
echo $OUTPUT->footer();
