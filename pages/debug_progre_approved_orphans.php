<?php
// =============================================================================
// DEBUG: Inconsistencias en gmk_course_progre — aprobado + huérfano pendiente
//
// Detecta estudiantes que tienen, para el mismo userid+courseid:
//   - Al menos 1 registro con status IN (3,4) → Completada / Aprobada
//   - Al menos 1 registro con status IN (0,1,5) → No disponible / Disponible / Reprobada
//
// Acción de limpieza: elimina los registros huérfanos (status 0/1/5) conservando
// siempre el registro aprobado (status 3/4).
// =============================================================================
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

global $DB, $PAGE, $OUTPUT;

$context = context_system::instance();
require_login();
require_capability('moodle/site:config', $context);

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_progre_approved_orphans.php'));
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Debug: Aprobados con huérfanos pendientes');
$PAGE->set_heading('Inconsistencias gmk_course_progre: aprobado + registro huérfano');

// ─── Filtro de búsqueda por estudiante ───────────────────────────────────────
$searchName = trim(optional_param('q', '', PARAM_TEXT));

// ─── Constantes de estado ────────────────────────────────────────────────────
$STATUS_LABELS = [
    0  => ['txt' => 'No disponible', 'cls' => 'secondary'],
    1  => ['txt' => 'Disponible',    'cls' => 'info'],
    2  => ['txt' => 'Cursando',      'cls' => 'primary'],
    3  => ['txt' => 'Completada',    'cls' => 'success'],
    4  => ['txt' => 'Aprobada',      'cls' => 'success'],
    5  => ['txt' => 'Reprobada',     'cls' => 'danger'],
    6  => ['txt' => 'Pend. Revalida','cls' => 'warning'],
    7  => ['txt' => 'Revalidando',   'cls' => 'warning'],
    99 => ['txt' => 'Migración',     'cls' => 'secondary'],
];

// ─── Acción: eliminar huérfanos ──────────────────────────────────────────────
$action   = optional_param('action', '', PARAM_ALPHA);
$actionLog = [];
$deleteCount = 0;

// Un registro es "huérfano" si:
//   a) tiene status no-terminal (0/1/2/5)
//   b) el estudiante NO está matriculado en ese learningplanid (no existe en local_learning_users con rol estudiante)
//   c) Y para ese mismo userid+courseid existe al menos un registro aprobado (status 3/4)
$ORPHAN_CONDITION = "
    cp.status IN (0, 1, 2, 5)
    AND NOT EXISTS (
        SELECT 1 FROM {local_learning_users} lu
         WHERE lu.userid        = cp.userid
           AND lu.learningplanid = cp.learningplanid
           AND lu.userroleid    = 5
    )
    AND EXISTS (
        SELECT 1 FROM {gmk_course_progre} cp_ok
         WHERE cp_ok.userid   = cp.userid
           AND cp_ok.courseid = cp.courseid
           AND cp_ok.status  IN (3, 4)
    )
";

if ($action === 'cleanselected') {
    require_sesskey();
    $selectedIds = optional_param_array('delids', [], PARAM_INT);
    $selectedIds = array_values(array_filter(array_map('intval', $selectedIds)));

    if (empty($selectedIds)) {
        $actionLog[] = ['error', 'No seleccionaste ningún registro.'];
    } else {
        // Allow deleting any selected record (admin has full control)
        list($in, $inParams) = $DB->get_in_or_equal($selectedIds, SQL_PARAMS_NAMED);
        $toDelete = $DB->get_fieldset_sql(
            "SELECT id FROM {gmk_course_progre} WHERE id $in",
            $inParams
        );
        if (!empty($toDelete)) {
            list($in2, $params2) = $DB->get_in_or_equal($toDelete, SQL_PARAMS_NAMED);
            $DB->execute("DELETE FROM {gmk_course_progre} WHERE id $in2", $params2);
            $deleteCount = count($toDelete);
            $actionLog[] = ['ok', "Se eliminaron $deleteCount registros seleccionados."];
        } else {
            $actionLog[] = ['info', 'No se encontraron los registros indicados.'];
        }
    }
}

if ($action === 'cleanall') {
    require_sesskey();

    $orphanIds = $DB->get_fieldset_sql(
        "SELECT cp.id FROM {gmk_course_progre} cp WHERE $ORPHAN_CONDITION",
        []
    );

    if (!empty($orphanIds)) {
        list($in, $params) = $DB->get_in_or_equal($orphanIds, SQL_PARAMS_NAMED);
        $DB->execute("DELETE FROM {gmk_course_progre} WHERE id $in", $params);
        $deleteCount = count($orphanIds);
        $actionLog[] = ['ok', "Se eliminaron $deleteCount registros huérfanos."];
    } else {
        $actionLog[] = ['info', 'No se encontraron registros huérfanos para eliminar.'];
    }
}

if ($action === 'cleanone') {
    require_sesskey();
    $uid = required_param('uid', PARAM_INT);
    $cid = required_param('cid', PARAM_INT);

    // Verify there IS an approved record before deleting
    $hasApproved = $DB->record_exists_select(
        'gmk_course_progre',
        'userid = :uid AND courseid = :cid AND status IN (3, 4)',
        ['uid' => $uid, 'cid' => $cid]
    );
    if (!$hasApproved) {
        $actionLog[] = ['error', "uid=$uid courseid=$cid no tiene registro aprobado — no se eliminó nada."];
    } else {
        $orphanIds = $DB->get_fieldset_sql(
            "SELECT cp.id FROM {gmk_course_progre} cp
              WHERE cp.userid   = :uid
                AND cp.courseid = :cid
                AND $ORPHAN_CONDITION",
            ['uid' => $uid, 'cid' => $cid]
        );
        if (!empty($orphanIds)) {
            list($in, $params) = $DB->get_in_or_equal($orphanIds, SQL_PARAMS_NAMED);
            $DB->execute("DELETE FROM {gmk_course_progre} WHERE id $in", $params);
            $deleteCount = count($orphanIds);
        }
        $actionLog[] = ['ok', "Eliminados $deleteCount registros huérfanos para uid=$uid courseid=$cid."];
    }
}

// ─── Consulta: pares (userid, courseid) inconsistentes ──────────────────────
// Un par es inconsistente si tiene al menos un registro huérfano (según $ORPHAN_CONDITION)
$pairsSql = "
    SELECT CONCAT(cp.userid, '_', cp.courseid) AS ukey,
           cp.userid, cp.courseid,
           u.firstname, u.lastname, u.username,
           c.fullname AS coursename, c.shortname AS courseshort
      FROM {gmk_course_progre} cp
      JOIN {user}   u ON u.id = cp.userid   AND u.deleted = 0
      JOIN {course} c ON c.id = cp.courseid
     WHERE $ORPHAN_CONDITION
     GROUP BY cp.userid, cp.courseid, u.firstname, u.lastname, u.username,
              c.fullname, c.shortname
     ORDER BY u.lastname, u.firstname, c.fullname
";
$pairs = $DB->get_records_sql($pairsSql, []);

// For each pair, load all records
$details = [];
foreach ($pairs as $pair) {
    $recs = $DB->get_records(
        'gmk_course_progre',
        ['userid' => $pair->userid, 'courseid' => $pair->courseid],
        'status DESC, timemodified DESC'
    );
    $details[] = [
        'pair'    => $pair,
        'records' => $recs,
    ];
}

$totalPairs   = count($details);
$totalOrphans = 0;
foreach ($details as $d) {
    foreach ($d['records'] as $r) {
        if (in_array((int)$r->status, [0, 1, 2, 5])) {
            $totalOrphans++;
        }
    }
}

// ─── SECCIÓN 2: Aprobaciones faltantes ──────────────────────────────────────
// Detecta registros con status 0/1 en el plan activo del estudiante,
// sin ningún status 3/4 para ese userid+courseid,
// pero con nota >= 70 en Moodle (grade_items/grade_grades).
// ────────────────────────────────────────────────────────────────────────────
$PASSING_GRADE = 70.0;

// Helper: get best Moodle grade for userid+courseid
function dpa_moodle_grade($DB, $userid, $courseid) {
    // 1) Course total
    $g = $DB->get_field_sql(
        "SELECT MAX(COALESCE(gg.finalgrade, gg.rawgrade))
           FROM {grade_items} gi
           JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :uid
          WHERE gi.itemtype = 'course' AND gi.courseid = :cid",
        ['uid' => $userid, 'cid' => $courseid]
    );
    if ($g !== false && $g !== null && (float)$g > 0) {
        return ['grade' => round((float)$g, 2), 'src' => 'Course total'];
    }
    // 2) Nota Final Integrada
    $g = $DB->get_field_sql(
        "SELECT MAX(COALESCE(gg.finalgrade, gg.rawgrade))
           FROM {grade_items} gi
           JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :uid
          WHERE gi.courseid = :cid
            AND (gi.itemname LIKE :n1 OR gi.itemname LIKE :n2)",
        ['uid' => $userid, 'cid' => $courseid,
         'n1' => '%Nota Final Integrada%', 'n2' => '%Final Integrada%']
    );
    if ($g !== false && $g !== null && (float)$g > 0) {
        return ['grade' => round((float)$g, 2), 'src' => 'Nota Final Integrada'];
    }
    return ['grade' => null, 'src' => 'no encontrada'];
}

// Action: fix missing approvals
if ($action === 'fixmissing') {
    require_sesskey();
    $fixIds = optional_param_array('fixids', [], PARAM_INT);
    $fixIds = array_values(array_filter(array_map('intval', $fixIds)));
    $fixed = 0; $errors = 0;
    foreach ($fixIds as $pid) {
        $rec = $DB->get_record('gmk_course_progre', ['id' => $pid]);
        if (!$rec || in_array((int)$rec->status, [3, 4])) { continue; }
        $result = dpa_moodle_grade($DB, $rec->userid, $rec->courseid);
        $grade  = $result['grade'];
        if ($grade === null || $grade < $PASSING_GRADE) { $errors++; continue; }
        try {
            $DB->execute(
                "UPDATE {gmk_course_progre}
                    SET status=4, grade=:g, progress=100, timemodified=:now WHERE id=:id",
                ['g' => $grade, 'now' => time(), 'id' => $pid]
            );
            $fixed++;
        } catch (Exception $e) { $errors++; }
    }
    $actionLog[] = ['ok', "Aprobaciones registradas: $fixed." . ($errors ? " Errores: $errors." : '')];
}

// Query: status 0/1 in active plan, no 3/4 exists, but Moodle grade >= 70
$missingSql = "
    SELECT CONCAT(cp.userid, '_', cp.courseid) AS ukey,
           cp.id AS progre_id,
           cp.userid, cp.courseid, cp.status AS cpstatus,
           cp.grade AS stored_grade, cp.learningplanid,
           u.firstname, u.lastname, u.username,
           c.fullname AS coursename, c.shortname AS courseshort
      FROM {gmk_course_progre} cp
      JOIN {user}   u ON u.id = cp.userid AND u.deleted = 0 AND u.suspended = 0
      JOIN {course} c ON c.id = cp.courseid
     WHERE cp.status IN (0, 1)
       AND EXISTS (
           SELECT 1 FROM {local_learning_users} lu
            WHERE lu.userid        = cp.userid
              AND lu.learningplanid = cp.learningplanid
              AND lu.userroleid    = 5
       )
       AND NOT EXISTS (
           SELECT 1 FROM {gmk_course_progre} cp_ok
            WHERE cp_ok.userid   = cp.userid
              AND cp_ok.courseid = cp.courseid
              AND cp_ok.status  IN (3, 4)
       )
       AND (
           EXISTS (
               SELECT 1 FROM {grade_items} gi
               JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = cp.userid
              WHERE gi.itemtype = 'course' AND gi.courseid = cp.courseid
                AND COALESCE(gg.finalgrade, gg.rawgrade) >= :p1
           )
           OR EXISTS (
               SELECT 1 FROM {grade_items} gi
               JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = cp.userid
              WHERE gi.courseid = cp.courseid
                AND (gi.itemname LIKE :nfi1 OR gi.itemname LIKE :nfi2)
                AND COALESCE(gg.finalgrade, gg.rawgrade) >= :p2
           )
       )
     ORDER BY u.lastname, u.firstname, c.fullname
";
$missingRows = [];
try {
    $missingRows = $DB->get_records_sql($missingSql, [
        'p1'   => $PASSING_GRADE,
        'p2'   => $PASSING_GRADE,
        'nfi1' => '%Nota Final Integrada%',
        'nfi2' => '%Final Integrada%',
    ]);
} catch (Exception $e) {}

// Pre-fetch Moodle grades for missing rows
$missingData = [];
foreach ($missingRows as $mr) {
    $result = dpa_moodle_grade($DB, $mr->userid, $mr->courseid);
    $missingData[] = [
        'row'   => $mr,
        'grade' => $result['grade'],
        'src'   => $result['src'],
    ];
}

// ─── SECCIÓN 3: Pendientes sin nota Moodle (búsqueda por nombre) ─────────────
// status 0/1 en plan activo + sin 3/4 + SIN nota en Moodle (o < 70)
// Solo se ejecuta si hay búsqueda o se solicita explícitamente
$showSec3   = $searchName !== '' || optional_param('sec3', 0, PARAM_INT) === 1;
$sec3Data   = [];
$sec3Search = [];

if ($showSec3) {
    $sec3NameWhere  = '';
    $sec3NameParams = [];
    if ($searchName !== '') {
        $like = '%' . $DB->sql_like_escape($searchName) . '%';
        $sec3NameWhere = " AND (" .
            $DB->sql_like('u.firstname', ':sq1', false) . " OR " .
            $DB->sql_like('u.lastname',  ':sq2', false) . " OR " .
            $DB->sql_like('u.username',  ':sq3', false) . ")";
        $sec3NameParams = ['sq1' => $like, 'sq2' => $like, 'sq3' => $like];
    }

    $sec3Sql = "
        SELECT CONCAT(cp.userid, '_', cp.courseid) AS ukey,
               cp.id AS progre_id,
               cp.userid, cp.courseid, cp.status AS cpstatus,
               cp.grade AS stored_grade, cp.learningplanid, cp.classid,
               u.firstname, u.lastname, u.username,
               c.fullname AS coursename, c.shortname AS courseshort
          FROM {gmk_course_progre} cp
          JOIN {user}   u ON u.id = cp.userid AND u.deleted = 0 AND u.suspended = 0
          JOIN {course} c ON c.id = cp.courseid
         WHERE cp.status IN (0, 1)
           AND EXISTS (
               SELECT 1 FROM {local_learning_users} lu
                WHERE lu.userid        = cp.userid
                  AND lu.learningplanid = cp.learningplanid
                  AND lu.userroleid    = 5
           )
           AND NOT EXISTS (
               SELECT 1 FROM {gmk_course_progre} cp_ok
                WHERE cp_ok.userid   = cp.userid
                  AND cp_ok.courseid = cp.courseid
                  AND cp_ok.status  IN (3, 4)
           )
           AND NOT EXISTS (
               SELECT 1 FROM {grade_items} gi
               JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = cp.userid
              WHERE gi.itemtype = 'course' AND gi.courseid = cp.courseid
                AND COALESCE(gg.finalgrade, gg.rawgrade) >= :p1
           )
           AND NOT EXISTS (
               SELECT 1 FROM {grade_items} gi
               JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = cp.userid
              WHERE gi.courseid = cp.courseid
                AND (gi.itemname LIKE :nfi1 OR gi.itemname LIKE :nfi2)
                AND COALESCE(gg.finalgrade, gg.rawgrade) >= :p2
           )
           $sec3NameWhere
         ORDER BY u.lastname, u.firstname, c.fullname
         LIMIT 300
    ";
    try {
        $sec3Rows = $DB->get_records_sql($sec3Sql, array_merge([
            'p1'   => $PASSING_GRADE,
            'p2'   => $PASSING_GRADE,
            'nfi1' => '%Nota Final Integrada%',
            'nfi2' => '%Final Integrada%',
        ], $sec3NameParams));
        foreach ($sec3Rows as $r) {
            $mgrade = dpa_moodle_grade($DB, $r->userid, $r->courseid);
            $sec3Data[] = ['row' => $r, 'grade' => $mgrade['grade'], 'src' => $mgrade['src']];
        }
    } catch (Exception $e) {}
}

echo $OUTPUT->header();
?>
<style>
.dpa-wrap  { max-width:1300px; }
.dpa-card  { background:#fff; border:1px solid #dee2e6; border-radius:8px; padding:18px 20px; margin-bottom:20px; }
.dpa-card h4 { margin:0 0 12px; font-size:15px; font-weight:700; }
.dpa-stat  { display:inline-block; text-align:center; padding:12px 20px; border-radius:8px; margin:0 8px 8px 0; border:1px solid #e5e7eb; }
.dpa-stat .num { font-size:26px; font-weight:700; }
.dpa-stat .lbl { font-size:11px; color:#6b7280; }
.dpa-tbl   { width:100%; border-collapse:collapse; font-size:13px; }
.dpa-tbl th { background:#1f2937; color:#fff; padding:7px 10px; text-align:left; white-space:nowrap; }
.dpa-tbl td { border-bottom:1px solid #e5e7eb; padding:6px 10px; vertical-align:middle; }
.dpa-tbl tr.row-keep td   { background:#f0fdf4; }
.dpa-tbl tr.row-orphan td { background:#fef2f2; }
.dpa-badge { display:inline-block; padding:2px 7px; border-radius:4px; font-size:11px; font-weight:600; }
.badge-success   { background:#dcfce7; color:#166534; }
.badge-danger    { background:#fee2e2; color:#991b1b; }
.badge-primary   { background:#dbeafe; color:#1e40af; }
.badge-info      { background:#e0f2fe; color:#0369a1; }
.badge-warning   { background:#fef9c3; color:#854d0e; }
.badge-secondary { background:#e5e7eb; color:#374151; }
.dpa-section   { border:1px solid #e5e7eb; border-radius:8px; margin-bottom:16px; overflow:hidden; }
.dpa-sec-head  { background:#f8fafc; padding:10px 14px; font-size:13px; font-weight:600; display:flex; justify-content:space-between; align-items:center; }
.dpa-btn       { display:inline-flex; align-items:center; gap:5px; padding:6px 12px; border:none; border-radius:5px; cursor:pointer; font-size:12px; font-weight:600; text-decoration:none; }
.dpa-btn-red   { background:#dc2626; color:#fff; }
.dpa-btn-red:hover  { background:#b91c1c; color:#fff; }
.dpa-btn-blue  { background:#2563eb; color:#fff; }
.dpa-btn-blue:hover { background:#1d4ed8; color:#fff; }
.dpa-alert-ok  { background:#dcfce7; border:1px solid #bbf7d0; color:#166534; padding:12px 16px; border-radius:6px; margin-bottom:14px; font-weight:500; }
.dpa-alert-err { background:#fee2e2; border:1px solid #fecaca; color:#991b1b; padding:12px 16px; border-radius:6px; margin-bottom:14px; font-weight:500; }
.dpa-alert-info{ background:#dbeafe; border:1px solid #bfdbfe; color:#1e40af; padding:12px 16px; border-radius:6px; margin-bottom:14px; font-weight:500; }
.dpa-explain   { background:#fffbeb; border:1px solid #fcd34d; border-radius:8px; padding:14px 16px; font-size:13px; line-height:1.7; margin-bottom:20px; }
</style>

<div class="dpa-wrap">
<h2 style="font-size:20px;font-weight:700;margin-bottom:6px;">&#128269; Inconsistencias: aprobado + huérfano en gmk_course_progre</h2>

<!-- Buscador global por nombre de estudiante -->
<form method="get" style="margin-bottom:18px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <input type="text" name="q" value="<?php echo s($searchName); ?>"
           placeholder="Buscar estudiante por nombre, apellido o usuario..."
           style="padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;width:320px">
    <button type="submit" class="dpa-btn dpa-btn-blue" style="padding:8px 14px">&#128269; Buscar</button>
    <?php if ($searchName !== ''): ?>
        <a href="?" class="dpa-btn" style="background:#6b7280;color:#fff;padding:8px 14px">&#10005; Limpiar</a>
        <span style="font-size:13px;color:#6b7280">Mostrando resultados para: <strong><?php echo s($searchName); ?></strong></span>
    <?php endif; ?>
    <a href="?sec3=1" class="dpa-btn" style="background:#0369a1;color:#fff;padding:8px 14px;margin-left:auto">
        &#128269; Ver todos sin nota Moodle (Sección 3)
    </a>
</form>

<?php foreach ($actionLog as [$cls, $msg]): ?>
    <div class="dpa-alert-<?php echo $cls === 'ok' ? 'ok' : ($cls === 'error' ? 'err' : 'info'); ?>">
        <?php echo $cls === 'ok' ? '&#10003;' : ($cls === 'error' ? '&#10005;' : 'ℹ'); ?> <?php echo s($msg); ?>
    </div>
<?php endforeach; ?>

<!-- Explicación -->
<div class="dpa-explain">
    <strong>&#9888;&#65039; ¿Qué detecta esta página?</strong><br>
    Estudiantes que tienen para el <strong>mismo curso</strong>:
    <ul style="margin:6px 0 0 18px; padding:0;">
        <li>&#10003; Un registro <strong>aprobado</strong> (status 3 = Completada o 4 = Aprobada)</li>
        <li>&#10005; Y además un registro <strong>huérfano</strong>: status 0/1/2/5 cuyo <code>learningplanid</code> <strong>no corresponde a ningún plan en que el estudiante esté actualmente matriculado</strong> (<code>local_learning_users</code>)</li>
    </ul>
    <br>
    <strong>&#128165; Causas habituales:</strong>
    <ul style="margin:4px 0 0 18px; padding:0;">
        <li><strong>Cambio de plan académico</strong>: el curso fue aprobado en el Plan A; al asignar al Plan B (otro <code>learningplanid</code>), <code>create_learningplan_user_progress</code> inserta un registro nuevo con status=0/1 porque solo verifica duplicados por <code>userid+courseid+learningplanid</code>, sin cruzar contra planes anteriores.</li>
        <li><strong>Re-matrícula con <code>forceInProgress</code></strong>: la función <code>assign_class_to_course_progress</code> puede seleccionar un registro no-aprobado como "canónico" y actualizarlo a Cursando, dejando el registro aprobado intacto en paralelo.</li>
        <li><strong>Importaciones manuales</strong> (<code>import_grades</code>, <code>debug_external_enrollment</code>): insertan filas sin verificar registros aprobados en otro plan.</li>
    </ul>
    <br>
    <strong>Acción segura:</strong> eliminar los registros huérfanos (status 0/1/5) conservando siempre el registro aprobado. Esto corrige la vista en <em>Demanda académica</em> y en el LXP.
</div>

<!-- Stats -->
<div class="dpa-card">
    <h4>&#128202; Resumen</h4>
    <div class="dpa-stat" style="border-color:#fca5a5">
        <div class="num" style="color:#dc2626"><?php echo $totalPairs; ?></div>
        <div class="lbl">Pares (estudiante + curso) inconsistentes</div>
    </div>
    <div class="dpa-stat" style="border-color:#fca5a5">
        <div class="num" style="color:#dc2626"><?php echo $totalOrphans; ?></div>
        <div class="lbl">Registros huérfanos a eliminar</div>
    </div>
</div>

<?php if ($totalPairs > 0): ?>
<!-- Botón limpiar todo -->
<div class="dpa-card">
    <h4>&#129529; Limpieza masiva</h4>
    <p style="font-size:13px;color:#6b7280;margin:0 0 12px">
        Elimina todos los registros huérfanos (status 0, 1, 5) de los <?php echo $totalPairs; ?> pares identificados.<br>
        Los registros aprobados (status 3/4) <strong>no serán modificados</strong>.
    </p>
    <form method="post" onsubmit="return confirm('¿Eliminar <?php echo $totalOrphans; ?> registros huérfanos en <?php echo $totalPairs; ?> pares? Los registros aprobados NO se tocan.');">
        <input type="hidden" name="action"  value="cleanall">
        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
        <button type="submit" class="dpa-btn dpa-btn-red">
            &#128465; Limpiar los <?php echo $totalOrphans; ?> registros huérfanos
        </button>
    </form>
</div>

<!-- Detalle por par — todo dentro de un único form con checkboxes -->
<form method="post" id="form-selected">
<input type="hidden" name="action"  value="cleanselected">
<input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">

<!-- Barra flotante de selección -->
<div id="dpa-floatbar" style="display:none;position:sticky;top:0;z-index:100;background:#1f2937;color:#fff;
     padding:10px 16px;border-radius:8px;margin-bottom:12px;display:none;align-items:center;gap:12px;flex-wrap:wrap;">
    <span id="dpa-sel-count" style="font-size:13px;font-weight:600">0 seleccionados</span>
    <button type="submit" class="dpa-btn dpa-btn-red">
        &#128465; Eliminar seleccionados
    </button>
    <button type="button" class="dpa-btn" style="background:#4b5563"
            onclick="document.querySelectorAll('.dpa-cb').forEach(cb=>cb.checked=false);updateBar();">
        Deseleccionar todo
    </button>
</div>

<div class="dpa-card">
    <h4>&#128203; Detalle por estudiante y curso</h4>
    <?php
    // Preload all learningplan names once to avoid N+1 queries
    $planNames = $DB->get_records_menu('local_learning_plans', null, '', 'id, name');

    foreach ($details as $d):
        $pair = $d['pair'];
        $recs = $d['records'];
        // Student's active plans
        $stuPlans = $DB->get_fieldset_select(
            'local_learning_users', 'learningplanid',
            'userid = :uid AND userroleid = 5',
            ['uid' => (int)$pair->userid]
        );
        $stuPlans = array_map('intval', $stuPlans);
        $orphanCount = 0;
        foreach ($recs as $r) {
            $lpid = (int)$r->learningplanid;
            if (!in_array((int)$r->status, [3,4]) && !in_array($lpid, $stuPlans)) {
                $orphanCount++;
            }
        }
    ?>
    <div class="dpa-section">
        <div class="dpa-sec-head">
            <span>
                &#128100; <strong><?php echo s($pair->firstname . ' ' . $pair->lastname); ?></strong>
                <small style="color:#6b7280">(uid=<?php echo (int)$pair->userid; ?> / <?php echo s($pair->username); ?>)</small>
                &nbsp;&mdash;&nbsp;
                &#128218; <?php echo s($pair->coursename); ?>
                <small style="color:#6b7280">(cid=<?php echo (int)$pair->courseid; ?> / <?php echo s($pair->courseshort); ?>)</small>
            </span>
            <span style="font-size:12px;color:#6b7280">
                <?php
                $stuPlanLabels = array_map(fn($pid) => $planNames[$pid] ?? "plan $pid", $stuPlans);
                echo '&#127891; Plan activo: ' . (empty($stuPlanLabels) ? '—' : implode(', ', array_map('s', $stuPlanLabels)));
                ?>
            </span>
        </div>
        <table class="dpa-tbl">
            <thead>
                <tr>
                    <th style="width:32px"><input type="checkbox" class="dpa-cb-group" title="Seleccionar huérfanos de este par" onchange="toggleGroup(this)"></th>
                    <th>ID</th>
                    <th>Estado</th>
                    <th>Nota</th>
                    <th>Progreso</th>
                    <th>Plan del registro</th>
                    <th>classid</th>
                    <th>periodid</th>
                    <th>Creado</th>
                    <th>Modificado</th>
                    <th>Decisión</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($recs as $rec):
                $st         = (int)$rec->status;
                $lpid       = (int)$rec->learningplanid;
                $info       = $STATUS_LABELS[$st] ?? ['txt' => "status=$st", 'cls' => 'secondary'];
                $isApproved = in_array($st, [3, 4]);
                $inPlan     = in_array($lpid, $stuPlans);
                $isOrphan   = !$isApproved && !$inPlan;
                $rowCls     = $isApproved ? 'row-keep' : ($isOrphan ? 'row-orphan' : '');
                $planLabel  = $lpid > 0 ? (isset($planNames[$lpid]) ? s($planNames[$lpid]) : "ID $lpid") : '—';
            ?>
                <tr class="<?php echo $rowCls; ?>">
                    <td style="text-align:center">
                        <input type="checkbox"
                               class="dpa-cb <?php echo $isApproved ? 'dpa-cb-approved' : ''; ?>"
                               name="delids[]"
                               value="<?php echo (int)$rec->id; ?>"
                               onchange="updateBar()"
                               <?php echo $isApproved ? 'title="⚠ Este registro está aprobado. Marcalo solo si estás seguro."' : ''; ?>>
                    </td>
                    <td style="font-family:monospace;font-weight:600"><?php echo (int)$rec->id; ?></td>
                    <td>
                        <span class="dpa-badge badge-<?php echo $info['cls']; ?>"><?php echo $info['txt']; ?></span>
                    </td>
                    <td style="font-weight:<?php echo $isApproved ? '700' : '400'; ?>">
                        <?php echo $rec->grade !== null ? number_format((float)$rec->grade, 2) : '—'; ?>
                    </td>
                    <td><?php echo $rec->progress !== null ? (int)$rec->progress . '%' : '—'; ?></td>
                    <td>
                        <?php echo $planLabel; ?>
                        <?php if ($lpid > 0): ?>
                            <?php if ($inPlan): ?>
                                <span class="dpa-badge badge-success" style="font-size:10px">activo</span>
                            <?php else: ?>
                                <span class="dpa-badge badge-danger" style="font-size:10px">sin plan</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td><small><?php echo (int)$rec->classid ?: '—'; ?></small></td>
                    <td><small><?php echo (int)$rec->periodid ?: '—'; ?></small></td>
                    <td style="font-size:11px;white-space:nowrap">
                        <?php echo $rec->timecreated ? date('Y-m-d H:i', (int)$rec->timecreated) : '—'; ?>
                    </td>
                    <td style="font-size:11px;white-space:nowrap">
                        <?php echo $rec->timemodified ? date('Y-m-d H:i', (int)$rec->timemodified) : '—'; ?>
                    </td>
                    <td style="font-weight:700;font-size:12px">
                        <?php if ($isApproved): ?>
                            <span style="color:#166534">&#10003; CONSERVAR</span>
                        <?php elseif ($isOrphan): ?>
                            <span style="color:#dc2626">&#128465; huérfano</span>
                        <?php else: ?>
                            <span style="color:#d97706">&#9888; en plan activo</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endforeach; ?>
</div>
</form>

<script>
function updateBar() {
    var all      = document.querySelectorAll('.dpa-cb:checked');
    var approved = document.querySelectorAll('.dpa-cb-approved:checked');
    var n = all.length, a = approved.length;
    var bar = document.getElementById('dpa-floatbar');
    bar.style.display = n > 0 ? 'flex' : 'none';
    var txt = n + ' seleccionado' + (n !== 1 ? 's' : '');
    if (a > 0) txt += ' <span style="color:#fca5a5;font-weight:700">⚠ ' + a + ' aprobado' + (a !== 1 ? 's' : '') + '</span>';
    document.getElementById('dpa-sel-count').innerHTML = txt;
}
function toggleGroup(masterCb) {
    var tbody = masterCb.closest('table').querySelector('tbody');
    tbody.querySelectorAll('.dpa-cb').forEach(cb => { cb.checked = masterCb.checked; });
    updateBar();
}
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('form-selected').addEventListener('submit', function(e) {
        var n = document.querySelectorAll('.dpa-cb:checked').length;
        var a = document.querySelectorAll('.dpa-cb-approved:checked').length;
        if (!n) { alert('Selecciona al menos un registro.'); e.preventDefault(); return; }
        var msg = '¿Eliminar ' + n + ' registro(s)?\n';
        if (a > 0) msg += '\n⚠ ATENCIÓN: ' + a + ' de ellos tiene status Aprobado/Completado.\n';
        msg += '\nEsta acción no se puede deshacer.';
        if (!confirm(msg)) e.preventDefault();
    });
});
</script>

<?php else: ?>
<div class="dpa-card">
    <div class="dpa-alert-ok">&#10003; No se encontraron inconsistencias (sección 1). Todos los pares (userid, courseid) son consistentes.</div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════════════════
     SECCIÓN 2: Aprobaciones faltantes en gmk_course_progre
     status 0/1 en plan activo + nota >= 70 en Moodle + sin registro 3/4
     ═══════════════════════════════════════════════════════════════════════ -->
<hr style="border:none;border-top:2px solid #e5e7eb;margin:28px 0 20px">
<h2 style="font-size:20px;font-weight:700;margin-bottom:6px;">
    &#9888;&#65039; Aprobaciones faltantes: nota en Moodle pero sin status 3/4
</h2>

<div class="dpa-explain" style="background:#f0f9ff;border-color:#7dd3fc">
    <strong>&#128270; ¿Qué detecta esta sección?</strong><br>
    Estudiantes con <code>gmk_course_progre.status 0 o 1</code> (No disponible / Disponible) en su plan activo,
    que <strong>no tienen ningún registro aprobado (status 3/4)</strong> para ese curso,
    pero <strong>sí tienen nota &ge; <?php echo $PASSING_GRADE; ?> en Moodle</strong> (course total o Nota Final Integrada).<br><br>
    <strong>Causa típica:</strong> el estudiante cursó y aprobó la materia, pero <code>sync_progress</code> nunca actualizó el registro
    (ej. aprobó antes de que el sistema existiera, o el sync falló), o la materia fue aprobada por revalidación
    sin pasar por el flujo normal.
</div>

<?php if (empty($missingData)): ?>
<div class="dpa-card">
    <div class="dpa-alert-ok">&#10003; No se encontraron aprobaciones faltantes.</div>
</div>
<?php else: ?>

<div class="dpa-card">
    <h4>&#128202; Resumen</h4>
    <div class="dpa-stat" style="border-color:#7dd3fc">
        <div class="num" style="color:#0369a1"><?php echo count($missingData); ?></div>
        <div class="lbl">Registros con aprobación faltante</div>
    </div>
</div>

<form method="post" id="form-fixmissing">
<input type="hidden" name="action"  value="fixmissing">
<input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">

<!-- Barra flotante sección 2 -->
<div id="dpa-floatbar2" style="display:none;position:sticky;top:0;z-index:100;background:#0369a1;color:#fff;
     padding:10px 16px;border-radius:8px;margin-bottom:12px;align-items:center;gap:12px;flex-wrap:wrap;">
    <span id="dpa-fix-count" style="font-size:13px;font-weight:600">0 seleccionados</span>
    <button type="submit" class="dpa-btn" style="background:#166534;color:#fff">
        &#10003; Registrar aprobaciones seleccionadas (status=4)
    </button>
    <button type="button" class="dpa-btn" style="background:#1e3a5f"
            onclick="document.querySelectorAll('.fix-cb').forEach(cb=>cb.checked=false);updateBar2();">
        Deseleccionar todo
    </button>
</div>

<div class="dpa-card">
    <h4>&#128203; Detalle de aprobaciones faltantes</h4>
    <table class="dpa-tbl">
        <thead>
            <tr>
                <th style="width:32px">
                    <input type="checkbox" id="fix-all"
                           onchange="document.querySelectorAll('.fix-cb').forEach(cb=>cb.checked=this.checked);updateBar2();">
                </th>
                <th>Estudiante</th>
                <th>Curso</th>
                <th>Status actual</th>
                <th>Plan</th>
                <th>Nota Moodle</th>
                <th>Fuente</th>
                <th>progre_id</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $planNamesM = isset($planNames) ? $planNames : $DB->get_records_menu('local_learning_plans', null, '', 'id, name');
        foreach ($missingData as $md):
            $mr      = $md['row'];
            $grade   = $md['grade'];
            $src     = $md['src'];
            $stInfo  = $STATUS_LABELS[(int)$mr->cpstatus] ?? ['txt' => 'status=' . (int)$mr->cpstatus, 'cls' => 'secondary'];
            $lpLabel = isset($planNamesM[(int)$mr->learningplanid])
                       ? s($planNamesM[(int)$mr->learningplanid]) : 'ID ' . (int)$mr->learningplanid;
        ?>
        <tr style="background:#f0f9ff">
            <td style="text-align:center">
                <?php if ($grade !== null && $grade >= $PASSING_GRADE): ?>
                    <input type="checkbox" class="fix-cb" name="fixids[]"
                           value="<?php echo (int)$mr->progre_id; ?>"
                           onchange="updateBar2()">
                <?php else: ?>
                    <span style="color:#d1d5db" title="Sin nota suficiente en Moodle">—</span>
                <?php endif; ?>
            </td>
            <td>
                <strong><?php echo s($mr->firstname . ' ' . $mr->lastname); ?></strong><br>
                <small style="color:#6b7280"><?php echo s($mr->username); ?> / uid=<?php echo (int)$mr->userid; ?></small>
            </td>
            <td>
                <?php echo s($mr->coursename); ?><br>
                <small style="color:#6b7280"><?php echo s($mr->courseshort); ?> / cid=<?php echo (int)$mr->courseid; ?></small>
            </td>
            <td>
                <span class="dpa-badge badge-<?php echo $stInfo['cls']; ?>"><?php echo $stInfo['txt']; ?></span>
            </td>
            <td><small><?php echo $lpLabel; ?></small></td>
            <td style="font-weight:700;color:<?php echo ($grade !== null && $grade >= $PASSING_GRADE) ? '#166534' : '#dc2626'; ?>">
                <?php echo $grade !== null ? number_format($grade, 2) : '—'; ?>
            </td>
            <td><small style="color:#6b7280"><?php echo s($src); ?></small></td>
            <td style="font-family:monospace;font-size:11px"><?php echo (int)$mr->progre_id; ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
</form>

<script>
function updateBar2() {
    var n = document.querySelectorAll('.fix-cb:checked').length;
    var bar = document.getElementById('dpa-floatbar2');
    bar.style.display = n > 0 ? 'flex' : 'none';
    document.getElementById('dpa-fix-count').textContent = n + ' seleccionado' + (n !== 1 ? 's' : '');
}
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('form-fixmissing').addEventListener('submit', function(e) {
        var n = document.querySelectorAll('.fix-cb:checked').length;
        if (!n) { alert('Selecciona al menos un registro.'); e.preventDefault(); return; }
        if (!confirm('¿Registrar ' + n + ' aprobación(es)? Se actualizará status=4, grade=nota Moodle, progress=100.')) {
            e.preventDefault();
        }
    });
});
</script>

<?php endif; ?>

<?php if ($showSec3): ?>
<!-- ═══════════════════════════════════════════════════════════════════════════
     SECCIÓN 3: Pendientes sin nota Moodle (ni course total ni NFI >= 70)
     ═══════════════════════════════════════════════════════════════════════ -->
<hr style="border:none;border-top:2px solid #e5e7eb;margin:28px 0 20px">
<h2 style="font-size:20px;font-weight:700;margin-bottom:6px;">
    &#128270; Pendientes sin nota en Moodle
    <?php if ($searchName !== ''): ?><small style="font-size:14px;color:#6b7280">— buscando: "<?php echo s($searchName); ?>"</small><?php endif; ?>
</h2>

<div class="dpa-explain" style="background:#fdf4ff;border-color:#d8b4fe">
    <strong>&#128270; ¿Qué detecta esta sección?</strong><br>
    Estudiantes con <code>status 0 o 1</code> en su plan activo, sin registro aprobado (3/4) y <strong>sin nota ≥ <?php echo $PASSING_GRADE; ?> en Moodle</strong>.<br>
    Casos típicos: aprobaron en sistema anterior, revalidación incompleta, o el progreso se lleva manualmente.
    <?php if (!$searchName): ?><br><strong>Limitado a 300 registros.</strong> Usá el buscador para filtrar por nombre.<?php endif; ?>
</div>

<?php if (empty($sec3Data)): ?>
<div class="dpa-card">
    <div class="dpa-alert-ok">&#10003; No se encontraron registros<?php echo $searchName ? ' para "' . s($searchName) . '"' : ''; ?>.</div>
</div>
<?php else: ?>
<div class="dpa-card">
    <h4>&#128202; <?php echo count($sec3Data); ?> registros encontrados</h4>
    <table class="dpa-tbl">
        <thead>
            <tr>
                <th>Estudiante</th>
                <th>Curso</th>
                <th>Status</th>
                <th>Plan</th>
                <th>Nota Moodle</th>
                <th>Nota almacenada</th>
                <th>classid</th>
                <th>progre_id</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $planNamesS = isset($planNames) ? $planNames : $DB->get_records_menu('local_learning_plans', null, '', 'id, name');
        foreach ($sec3Data as $sd):
            $sr     = $sd['row'];
            $sg     = $sd['grade'];
            $stInfo = $STATUS_LABELS[(int)$sr->cpstatus] ?? ['txt' => 'status='.(int)$sr->cpstatus, 'cls'=>'secondary'];
            $lpLbl  = isset($planNamesS[(int)$sr->learningplanid]) ? s($planNamesS[(int)$sr->learningplanid]) : 'ID '.(int)$sr->learningplanid;
        ?>
        <tr>
            <td>
                <strong><?php echo s($sr->firstname . ' ' . $sr->lastname); ?></strong><br>
                <small style="color:#6b7280"><?php echo s($sr->username); ?> / uid=<?php echo (int)$sr->userid; ?></small>
            </td>
            <td>
                <?php echo s($sr->coursename); ?><br>
                <small style="color:#6b7280"><?php echo s($sr->courseshort); ?> / cid=<?php echo (int)$sr->courseid; ?></small>
            </td>
            <td><span class="dpa-badge badge-<?php echo $stInfo['cls']; ?>"><?php echo $stInfo['txt']; ?></span></td>
            <td><small><?php echo $lpLbl; ?></small></td>
            <td style="color:<?php echo ($sg !== null && $sg >= $PASSING_GRADE) ? '#166534' : '#9ca3af'; ?>;font-weight:600">
                <?php echo $sg !== null ? number_format($sg, 2) : '—'; ?>
            </td>
            <td style="color:#6b7280"><small><?php echo $sr->stored_grade !== null ? number_format((float)$sr->stored_grade, 2) : '—'; ?></small></td>
            <td><small><?php echo (int)$sr->classid ?: '—'; ?></small></td>
            <td style="font-family:monospace;font-size:11px"><?php echo (int)$sr->progre_id; ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php endif; ?>


<?php if ($searchName !== ''): ?>
<!-- ═══════════════════════════════════════════════════════════════════════════
     SECCIÓN 4: Inspector de notas por estudiante
     Muestra el desglose completo de grade_items/grade_grades para cada materia
     pendiente (status 0/1/2) del estudiante buscado.
     Misma lógica que get_student_gradebook.php (modal director académico).
     ═══════════════════════════════════════════════════════════════════════ -->
<hr style="border:none;border-top:2px solid #e5e7eb;margin:28px 0 20px">
<h2 style="font-size:20px;font-weight:700;margin-bottom:6px;">
    &#128300; Inspector de notas: &ldquo;<?php echo s($searchName); ?>&rdquo;
</h2>

<div class="dpa-explain" style="background:#f0fdf4;border-color:#86efac">
    <strong>&#128270; ¿Qué muestra esta sección?</strong><br>
    Para cada materia con status 0, 1 o 2 del estudiante buscado, muestra el <strong>desglose completo de notas en Moodle</strong>
    (igual que el modal del panel del director académico): course total, Nota Final Integrada,
    categoría de clase (vía <code>gmk_class.gradecategoryid</code>), y todos los ítems de calificación.<br>
    Útil para diagnosticar por qué un estudiante sigue apareciendo en <em>academic_demand_gaps</em>
    pese a haber aprobado la materia.
</div>

<?php
// ── Find student(s) matching the search (max 5) ──────────────────────────
// Match against firstname, lastname, username, OR full concatenated name
$sec4Like = '%' . $DB->sql_like_escape($searchName) . '%';
$sec4Students = $DB->get_records_sql(
    "SELECT id, username, firstname, lastname
       FROM {user}
      WHERE deleted = 0
        AND (" .
        $DB->sql_like('firstname', ':fn', false) . " OR " .
        $DB->sql_like('lastname',  ':ln', false) . " OR " .
        $DB->sql_like('username',  ':un', false) . " OR " .
        $DB->sql_like($DB->sql_concat('firstname', "' '", 'lastname'), ':full', false) . " OR " .
        $DB->sql_like($DB->sql_concat('lastname', "' '", 'firstname'), ':full2', false) . "
      )
      ORDER BY lastname, firstname
      LIMIT 5",
    ['fn' => $sec4Like, 'ln' => $sec4Like, 'un' => $sec4Like,
     'full' => $sec4Like, 'full2' => $sec4Like]
);

$sec4PlanNames = isset($planNames) ? $planNames
    : $DB->get_records_menu('local_learning_plans', null, '', 'id, name');

// Helper: format a grade value with colour
$fmtGrade = function($g) use ($PASSING_GRADE) {
    if ($g === false || $g === null) {
        return '<span style="color:#9ca3af;font-weight:600">—</span>';
    }
    $gf  = round((float)$g, 2);
    $col = $gf >= $PASSING_GRADE ? '#166534' : '#dc2626';
    return "<strong style='color:$col'>$gf</strong>";
};

if (empty($sec4Students)):
?>
<div class="dpa-card">
    <div class="dpa-alert-info">&#8505; No se encontró ningún estudiante con ese nombre.</div>
</div>
<?php
else:
    foreach ($sec4Students as $stu):
        $stuId = (int)$stu->id;

        // ALL courses for this student (all statuses) for complete debug
        $sec4Courses = $DB->get_records_sql(
            "SELECT cp.id AS progre_id, cp.courseid, cp.status AS cpstatus,
                    cp.grade AS stored_grade, cp.learningplanid, cp.classid,
                    cp.periodid, cp.progress,
                    c.fullname AS coursename, c.shortname AS courseshort
               FROM {gmk_course_progre} cp
               JOIN {course} c ON c.id = cp.courseid
              WHERE cp.userid = :uid
              ORDER BY c.fullname, cp.status DESC",
            ['uid' => $stuId]
        );
?>
<div class="dpa-card">
<h4>&#128100; <?php echo s($stu->firstname . ' ' . $stu->lastname); ?>
    <small style="font-size:12px;color:#6b7280;font-weight:400">
        (<?php echo s($stu->username); ?> / uid=<?php echo $stuId; ?>)
    </small>
</h4>

<?php if (empty($sec4Courses)): ?>
    <div class="dpa-alert-info">&#8505; No tiene registros en gmk_course_progre.</div>
<?php else:
        foreach ($sec4Courses as $sc):
            $courseid = (int)$sc->courseid;
            $stInfo   = $STATUS_LABELS[(int)$sc->cpstatus] ?? ['txt' => 'status='.(int)$sc->cpstatus, 'cls' => 'secondary'];
            $lpLbl    = isset($sec4PlanNames[(int)$sc->learningplanid])
                        ? s($sec4PlanNames[(int)$sc->learningplanid])
                        : 'ID ' . (int)$sc->learningplanid;

            // ── Course total ─────────────────────────────────────────────
            $courseTotalGrade = $DB->get_field_sql(
                "SELECT COALESCE(gg.finalgrade, gg.rawgrade)
                   FROM {grade_items}  gi
                   JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :uid
                  WHERE gi.itemtype = 'course' AND gi.courseid = :cid",
                ['uid' => $stuId, 'cid' => $courseid]
            );

            // ── Nota Final Integrada ─────────────────────────────────────
            $nfiGrade = $DB->get_field_sql(
                "SELECT MAX(COALESCE(gg.finalgrade, gg.rawgrade))
                   FROM {grade_items}  gi
                   JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :uid
                  WHERE gi.courseid = :cid
                    AND (gi.itemname LIKE :n1 OR gi.itemname LIKE :n2)",
                ['uid' => $stuId, 'cid' => $courseid,
                 'n1' => '%Nota Final Integrada%', 'n2' => '%Final Integrada%']
            );

            // ── Student groups in this course ────────────────────────────
            $userGroupIds = $DB->get_fieldset_sql(
                "SELECT gm.groupid
                   FROM {groups_members} gm
                   JOIN {groups} g ON g.id = gm.groupid
                  WHERE gm.userid = :uid AND g.courseid = :cid",
                ['uid' => $stuId, 'cid' => $courseid]
            );
            $userGroupIds = array_map('intval', $userGroupIds);

            // ── gmk_class records for this course ────────────────────────
            $classRecs = $DB->get_records('gmk_class',
                ['corecourseid' => $courseid], '',
                'id,groupid,gradecategoryid,attendancemoduleid'
            );

            $studentClassIds    = [];
            $studentCategoryIds = [];
            $otherCategoryIds   = [];   // categories of OTHER groups (to detect exclusion)
            foreach ($classRecs as $cls) {
                if (in_array((int)$cls->groupid, $userGroupIds)) {
                    $studentClassIds[] = (int)$cls->id;
                    if ((int)$cls->gradecategoryid > 0) {
                        $studentCategoryIds[] = (int)$cls->gradecategoryid;
                    }
                } else {
                    if ((int)$cls->gradecategoryid > 0) {
                        $otherCategoryIds[] = (int)$cls->gradecategoryid;
                    }
                }
            }

            // ── Class category grade (student's own class) ───────────────
            $classCatGrade = null;
            if (!empty($studentCategoryIds)) {
                list($catIn, $catParams) = $DB->get_in_or_equal($studentCategoryIds, SQL_PARAMS_NAMED);
                $classCatGrade = $DB->get_field_sql(
                    "SELECT MAX(COALESCE(gg.finalgrade, gg.rawgrade))
                       FROM {grade_items}  gi
                       JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :uid
                      WHERE gi.itemtype    = 'category'
                        AND gi.courseid   = :cid
                        AND gi.iteminstance $catIn",
                    array_merge(['uid' => $stuId, 'cid' => $courseid], $catParams)
                );
            }

            // ── All grade items for this course ──────────────────────────
            $allItems = $DB->get_records_sql(
                "SELECT gi.id, gi.categoryid, gi.itemname, gi.itemtype, gi.itemmodule,
                        gi.iteminstance, gi.grademax, gi.sortorder,
                        gg.finalgrade, gg.rawgrade,
                        gc.fullname AS catname
                   FROM {grade_items}       gi
                   LEFT JOIN {grade_grades}    gg ON gg.itemid = gi.id AND gg.userid = :uid
                   LEFT JOIN {grade_categories} gc ON gc.id = gi.categoryid
                  WHERE gi.courseid = :cid
                  ORDER BY gi.sortorder ASC",
                ['uid' => $stuId, 'cid' => $courseid]
            );
?>
    <div style="border:1px solid #e5e7eb;border-radius:8px;margin-bottom:14px;overflow:hidden">
        <!-- Course header -->
        <div style="background:#f1f5f9;padding:10px 14px;font-size:13px;font-weight:600;
                    display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
            <span>&#128218; <?php echo s($sc->coursename); ?>
                <small style="color:#6b7280;font-weight:400">(cid=<?php echo $courseid; ?>
                / progre_id=<?php echo (int)$sc->progre_id; ?>)</small>
            </span>
            <span>
                <span class="dpa-badge badge-<?php echo $stInfo['cls']; ?>"><?php echo $stInfo['txt']; ?></span>
                &nbsp;
                <small style="color:#6b7280">Plan: <?php echo $lpLbl; ?></small>
            </span>
        </div>

        <!-- Key grade summary -->
        <div style="padding:10px 16px;background:#fff;display:flex;gap:20px;flex-wrap:wrap;
                    font-size:13px;border-bottom:1px solid #e5e7eb;align-items:center">
            <span>&#128202; <strong>Course total:</strong>
                <?php echo $fmtGrade($courseTotalGrade); ?>
            </span>
            <span>&#128221; <strong>NFI:</strong>
                <?php echo $fmtGrade($nfiGrade); ?>
            </span>
            <span>&#127991; <strong>Cat. clase:</strong>
                <?php echo $fmtGrade($classCatGrade); ?>
                <?php if (!empty($studentCategoryIds)): ?>
                    <small style="color:#6b7280">(catid=<?php echo implode(',', $studentCategoryIds); ?>)</small>
                <?php elseif (empty($userGroupIds)): ?>
                    <small style="color:#dc2626">&#9888; sin grupo</small>
                <?php else: ?>
                    <small style="color:#dc2626">&#9888; sin gmk_class con gradecategoryid</small>
                <?php endif; ?>
            </span>
            <span style="color:#6b7280;font-size:12px">
                Grupos: <?php echo empty($userGroupIds) ? '—' : implode(', ', $userGroupIds); ?>
                &nbsp;|&nbsp;
                classids: <?php echo empty($studentClassIds) ? '—' : implode(', ', $studentClassIds); ?>
                <?php if (!empty($otherCategoryIds)): ?>
                &nbsp;|&nbsp;
                <span style="color:#d97706">otras cats excluidas: <?php echo implode(', ', $otherCategoryIds); ?></span>
                <?php endif; ?>
            </span>
        </div>

        <!-- All grade items -->
        <?php if (!empty($allItems)): ?>
        <div style="overflow-x:auto">
        <table class="dpa-tbl" style="font-size:12px;margin:0">
            <thead>
                <tr>
                    <th>gi.id</th>
                    <th>itemtype / module</th>
                    <th>Categoría (gc.fullname)</th>
                    <th>itemname</th>
                    <th>grademax</th>
                    <th>finalgrade</th>
                    <th>rawgrade</th>
                    <th>&#128274; Vis.</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($allItems as $item):
                $isCourseItem   = $item->itemtype === 'course';
                $isOtherCatItem = !$isCourseItem
                                  && (int)$item->categoryid > 0
                                  && in_array((int)$item->categoryid, $otherCategoryIds);
                $hasGrade = ($item->finalgrade !== null || $item->rawgrade !== null);
                $rowStyle = $isCourseItem   ? 'background:#f0fdf4;font-weight:600'
                          : ($isOtherCatItem ? 'background:#fef9c3;color:#78350f'
                          : ($hasGrade       ? '' : 'color:#9ca3af'));
                $itemTypeLabel = s($item->itemtype)
                               . ($item->itemmodule ? '/' . s($item->itemmodule) : '');
                $itemname = $item->itemname ?? ($item->itemtype === 'course' ? '(Course Total)' : '—');
                // Is this item in the student's class category?
                $inStudentCat = !$isCourseItem
                                && in_array((int)$item->categoryid, $studentCategoryIds);
            ?>
            <tr style="<?php echo $rowStyle; ?>">
                <td style="font-family:monospace"><?php echo (int)$item->id; ?></td>
                <td><code style="font-size:10px"><?php echo $itemTypeLabel; ?></code></td>
                <td style="font-size:11px">
                    <?php echo s($item->catname ?? '—'); ?>
                    <?php if ($inStudentCat): ?>
                        <span class="dpa-badge badge-success" style="font-size:9px">tu clase</span>
                    <?php elseif ($isOtherCatItem): ?>
                        <span class="dpa-badge badge-warning" style="font-size:9px">otra clase</span>
                    <?php endif; ?>
                </td>
                <td><?php echo s($itemname); ?></td>
                <td><?php echo $item->grademax !== null ? number_format((float)$item->grademax, 1) : '—'; ?></td>
                <td><?php echo $fmtGrade($item->finalgrade); ?></td>
                <td style="color:#6b7280">
                    <?php echo $item->rawgrade !== null ? number_format((float)$item->rawgrade, 2) : '<span style="color:#9ca3af">null</span>'; ?>
                </td>
                <td style="font-size:11px;text-align:center">
                    <?php if ($isOtherCatItem): ?>
                        <span title="Este ítem pertenece a la categoría de otro grupo">&#128683;</span>
                    <?php elseif ($isCourseItem): ?>
                        <span title="Course total">&#9733;</span>
                    <?php else: ?>
                        <span title="Visible">&#10003;</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php else: ?>
        <div style="padding:10px 14px;font-size:13px;color:#6b7280">
            Sin <code>grade_items</code> registrados para este curso y estudiante.
        </div>
        <?php endif; ?>
    </div><!-- end course block -->
<?php
        endforeach; // courses
    endif;
?>
</div><!-- end student card -->
<?php
    endforeach; // students
endif;
?>
<?php endif; // end if $searchName !== '' ?>

</div>

<?php
echo $OUTPUT->footer();
