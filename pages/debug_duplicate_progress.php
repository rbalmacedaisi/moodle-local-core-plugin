<?php
// =============================================================================
// DEBUG: Registros Duplicados en gmk_course_progre
// Identifica estudiantes con más de un registro para la misma combinación
// userid + courseid + learningplanid
// =============================================================================
require_once(__DIR__ . '/../../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

global $DB;

$PAGE->set_url('/local/grupomakro_core/pages/debug_duplicate_progress.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Debug: Registros Duplicados en Progreso');
$PAGE->set_heading('Registros Duplicados en gmk_course_progre');

// Action: Clean duplicates
$action = optional_param('action', '', PARAM_ALPHA);
$cleanLog = [];

if ($action === 'clean') {
    require_sesskey();
    
    $sql = "SELECT userid, courseid, learningplanid, COUNT(*) as cnt, MIN(id) as keep_id
            FROM {gmk_course_progre}
            GROUP BY userid, courseid, learningplanid
            HAVING COUNT(*) > 1";
    $duplicates = $DB->get_records_sql($sql);
    
    $deleted = 0;
    foreach ($duplicates as $dup) {
        // Delete all but the oldest (keep_id)
        $extras = $DB->get_records_select('gmk_course_progre',
            'userid = ? AND courseid = ? AND learningplanid = ? AND id != ?',
            [$dup->userid, $dup->courseid, $dup->learningplanid, $dup->keep_id]
        );
        foreach ($extras as $extra) {
            $DB->delete_records('gmk_course_progre', ['id' => $extra->id]);
            $deleted++;
        }
    }
    $cleanLog[] = "✅ Se eliminaron $deleted registros duplicados de " . count($duplicates) . " combinaciones.";
}

// Query: Find all duplicates
$sql = "SELECT userid, courseid, learningplanid, COUNT(*) as duplicate_count
        FROM {gmk_course_progre}
        GROUP BY userid, courseid, learningplanid
        HAVING COUNT(*) > 1
        ORDER BY COUNT(*) DESC";
$duplicates = $DB->get_records_sql($sql);

// Get user and course details
$summary = [];
$totalDuplicateRecords = 0;
$affectedStudents = [];

foreach ($duplicates as $dup) {
    $user = $DB->get_record('user', ['id' => $dup->userid], 'id, username, firstname, lastname');
    $course = $DB->get_record('course', ['id' => $dup->courseid], 'id, shortname, fullname');
    $plan = $DB->get_record('local_learning_plans', ['id' => $dup->learningplanid], 'id, name');
    
    $extraCount = $dup->duplicate_count - 1; // Records to remove
    $totalDuplicateRecords += $extraCount;
    
    if ($user) {
        $affectedStudents[$user->id] = $user;
    }
    
    $summary[] = [
        'user' => $user ? "{$user->firstname} {$user->lastname} ({$user->username})" : "ID: {$dup->userid}",
        'userid' => $dup->userid,
        'course' => $course ? $course->fullname : "ID: {$dup->courseid}",
        'courseid' => $dup->courseid,
        'plan' => $plan ? $plan->name : "ID: {$dup->learningplanid}",
        'count' => $dup->duplicate_count,
        'extra' => $extraCount
    ];
}

// Group by student for summary
$byStudent = [];
foreach ($summary as $s) {
    $key = $s['userid'];
    if (!isset($byStudent[$key])) {
        $byStudent[$key] = [
            'user' => $s['user'],
            'courses' => 0,
            'extra_records' => 0
        ];
    }
    $byStudent[$key]['courses']++;
    $byStudent[$key]['extra_records'] += $s['extra'];
}

// Sort by extra_records DESC
uasort($byStudent, function($a, $b) { return $b['extra_records'] - $a['extra_records']; });

echo $OUTPUT->header();
?>

<style>
    .debug-container { max-width: 1200px; margin: 0 auto; font-family: system-ui, -apple-system, sans-serif; }
    .stat-card { display: inline-block; padding: 16px 24px; margin: 8px; border-radius: 12px; text-align: center; min-width: 180px; }
    .stat-card h3 { font-size: 28px; margin: 0; font-weight: 700; }
    .stat-card p { font-size: 13px; margin: 4px 0 0; opacity: 0.8; }
    .card-red { background: #fee2e2; color: #991b1b; }
    .card-orange { background: #ffedd5; color: #9a3412; }
    .card-blue { background: #dbeafe; color: #1e40af; }
    table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 16px; }
    th { background: #f1f5f9; padding: 10px 12px; text-align: left; font-weight: 600; color: #475569; border-bottom: 2px solid #e2e8f0; }
    td { padding: 8px 12px; border-bottom: 1px solid #f1f5f9; }
    tr:hover td { background: #f8fafc; }
    .btn { display: inline-block; padding: 10px 20px; border-radius: 8px; font-weight: 600; text-decoration: none; cursor: pointer; border: none; font-size: 14px; }
    .btn-danger { background: #dc2626; color: white; }
    .btn-danger:hover { background: #b91c1c; }
    .alert { padding: 12px 16px; border-radius: 8px; margin: 16px 0; font-weight: 500; }
    .alert-success { background: #d1fae5; color: #065f46; }
</style>

<div class="debug-container">
    <h2 style="font-size: 22px; font-weight: 700; margin-bottom: 16px;">🔍 Registros Duplicados en gmk_course_progre</h2>
    
    <?php foreach ($cleanLog as $msg): ?>
        <div class="alert alert-success"><?php echo $msg; ?></div>
    <?php endforeach; ?>

    <!-- Stats -->
    <div style="margin-bottom: 24px;">
        <div class="stat-card card-red">
            <h3><?php echo count($summary); ?></h3>
            <p>Combinaciones duplicadas</p>
        </div>
        <div class="stat-card card-orange">
            <h3><?php echo $totalDuplicateRecords; ?></h3>
            <p>Registros sobrantes</p>
        </div>
        <div class="stat-card card-blue">
            <h3><?php echo count($affectedStudents); ?></h3>
            <p>Estudiantes afectados</p>
        </div>
    </div>

    <?php if ($totalDuplicateRecords > 0): ?>
        <form method="post" onsubmit="return confirm('¿Eliminar <?php echo $totalDuplicateRecords; ?> registros duplicados? Se conservará el más antiguo de cada combinación.');">
            <input type="hidden" name="action" value="clean">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <button type="submit" class="btn btn-danger">🧹 Limpiar <?php echo $totalDuplicateRecords; ?> Duplicados</button>
        </form>
    <?php else: ?>
        <div class="alert alert-success">✅ No se encontraron registros duplicados.</div>
    <?php endif; ?>

    <!-- Summary by Student -->
    <?php if (!empty($byStudent)): ?>
    <h3 style="margin-top: 32px; font-size: 16px; font-weight: 600;">📋 Resumen por Estudiante</h3>
    <table>
        <thead>
            <tr>
                <th>Estudiante</th>
                <th style="text-align:center">Cursos con Duplicados</th>
                <th style="text-align:center">Registros Sobrantes</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($byStudent as $uid => $s): ?>
            <tr>
                <td><?php echo htmlspecialchars($s['user']); ?></td>
                <td style="text-align:center; font-weight:600"><?php echo $s['courses']; ?></td>
                <td style="text-align:center; font-weight:600; color:#dc2626"><?php echo $s['extra_records']; ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- Detail Table -->
    <?php if (!empty($summary)): ?>
    <h3 style="margin-top: 32px; font-size: 16px; font-weight: 600;">📊 Detalle por Curso</h3>
    <table>
        <thead>
            <tr>
                <th>Estudiante</th>
                <th>Curso</th>
                <th>Plan</th>
                <th style="text-align:center">Total Registros</th>
                <th style="text-align:center">Sobrantes</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($summary as $s): ?>
            <tr>
                <td><?php echo htmlspecialchars($s['user']); ?></td>
                <td><?php echo htmlspecialchars($s['course']); ?></td>
                <td><?php echo htmlspecialchars($s['plan']); ?></td>
                <td style="text-align:center; font-weight:600"><?php echo $s['count']; ?></td>
                <td style="text-align:center; font-weight:600; color:#dc2626"><?php echo $s['extra']; ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php
echo $OUTPUT->footer();
