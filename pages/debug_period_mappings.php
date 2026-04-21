<?php
require_once(__DIR__ . '/../../../config.php');
global $DB, $PAGE, $OUTPUT, $CFG;

$context = context_system::instance();
require_login();
require_capability('moodle/site:config', $context);

$PAGE->set_url('/local/grupomakro_core/pages/debug_period_mappings.php');
$PAGE->set_context($context);
$PAGE->set_title('Debug - Period Mappings');
$PAGE->set_heading('Debug: Period Mappings & Academic Planning');
$PAGE->set_pagelayout('admin');

echo $OUTPUT->header();

$periodId = optional_param('periodid', 0, PARAM_INT);

echo '<style>
body { font-family: "Segoe UI", Tahoma, sans-serif; background: #f8fafc; padding: 20px; }
.debug-container { max-width: 1200px; margin: 0 auto; }
h1 { color: #1e293b; border-bottom: 3px solid #3b82f6; padding-bottom: 10px; }
h2 { color: #334155; margin-top: 30px; border-left: 4px solid #3b82f6; padding-left: 12px; }
h3 { color: #475569; margin-top: 20px; }
.card { background: white; border-radius: 12px; padding: 20px; margin: 15px 0; box-shadow: 0 2px 8px rgba(0,0,0,0.08); border: 1px solid #e2e8f0; }
table { width: 100%; border-collapse: collapse; margin: 10px 0; font-size: 13px; }
th { background: #f1f5f9; padding: 10px; text-align: left; font-weight: 600; color: #334155; border-bottom: 2px solid #cbd5e1; }
td { padding: 8px 10px; border-bottom: 1px solid #e2e8f0; }
tr:hover { background: #f8fafc; }
.badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.badge-green { background: #dcfce7; color: #166534; }
.badge-red { background: #fee2e2; color: #991b1b; }
.badge-blue { background: #dbeafe; color: #1e40af; }
.badge-yellow { background: #fef3c7; color: #92400e; }
pre { background: #1e293b; color: #e2e8f0; padding: 15px; border-radius: 8px; overflow-x: auto; font-size: 12px; line-height: 1.5; }
select, button { padding: 10px 16px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 14px; cursor: pointer; }
button { background: #3b82f6; color: white; border: none; font-weight: 600; }
button:hover { background: #2563eb; }
.quick-link { display: inline-block; padding: 8px 16px; background: #3b82f6; color: white; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: 500; margin: 5px; }
</style>';

echo '<div class="debug-container">';
echo '<h1>Debug: Period Mappings & Academic Planning</h1>';

echo '<div class="card">';
echo '<h3>Seleccionar Periodo Base</h3>';
echo '<form method="get" style="display:flex;gap:15px;align-items:center;">';
echo '<select name="periodid" onchange="this.form.submit()">';
echo '<option value="">-- Seleccione un periodo --</option>';

$periods = $DB->get_records('gmk_academic_periods', [], 'id DESC', 'id, name');
foreach ($periods as $p) {
    $selected = ($p->id == $periodId) ? 'selected' : '';
    echo "<option value='{$p->id}' {$selected}>{$p->name}</option>";
}
echo '</select>';
echo '<button type="submit">Verificar</button>';
echo '</form>';
echo '</div>';

if ($periodId) {
    echo '<div class="card">';
    echo '<h3>gmk_academic_periods (Periodo Seleccionado)</h3>';
    $period = $DB->get_record('gmk_academic_periods', ['id' => $periodId]);
    if ($period) {
        echo '<table>';
        echo '<tr><th>ID</th><th>Nombre</th><th>Status</th></tr>';
        echo "<tr><td>{$period->id}</td><td>{$period->name}</td><td>{$period->status}</td></tr>";
        echo '</table>';
    }
    echo '</div>';

    echo '<div class="card">';
    echo '<h3>gmk_planning_period_maps (Mappings para Periodo Base)</h3>';
    $mappings = $DB->get_records('gmk_planning_period_maps', ['base_period_id' => $periodId], 'relative_index ASC');
    if ($mappings) {
        echo '<table>';
        echo '<tr><th>relative_index</th><th>target_period_id</th><th>target_period_name</th></tr>';
        foreach ($mappings as $m) {
            $targetPeriod = $DB->get_record('gmk_academic_periods', ['id' => $m->target_period_id]);
            $targetName = $targetPeriod ? $targetPeriod->name : 'DESCONOCIDO';
            echo "<tr><td>{$m->relative_index}</td><td>{$m->target_period_id}</td><td>{$targetName}</td></tr>";
        }
        echo '</table>';
    } else {
        echo '<p class="badge badge-yellow">No hay mappings configurados para este periodo</p>';
    }
    echo '</div>';

    echo '<div class="card">';
    echo '<h3>gmk_academic_planning (Registros con academicperiodid = ' . $periodId . ')</h3>';
    $plannings = $DB->get_records('gmk_academic_planning', ['academicperiodid' => $periodId]);
    echo '<p><span class="badge badge-blue">' . count($plannings) . ' registros</span></p>';
    if ($plannings) {
        echo '<table>';
        echo '<tr><th>ID</th><th>courseid</th><th>learningplanid</th><th>status</th><th>projected_students</th></tr>';
        foreach ($plannings as $p) {
            $statusClass = $p->status == 2 ? 'badge-red' : ($p->status == 1 ? 'badge-green' : 'badge-blue');
            $statusLabel = $p->status == 2 ? 'IGNORED' : ($p->status == 1 ? 'CONFIRMED' : 'OTHER');
            echo "<tr><td>{$p->id}</td><td>{$p->courseid}</td><td>{$p->learningplanid}</td><td><span class='badge {$statusClass}'>{$statusLabel}</span></td><td>{$p->projected_students}</td></tr>";
        }
        echo '</table>';
    }
    echo '</div>';

    echo '<div class="card">';
    echo '<h3>Verificación: ¿A qué periodo lectivo pertenecen las columnas P-I, P-II, etc.?</h3>';

    $targetPeriodByColumn = [];
    $mappings = $DB->get_records('gmk_planning_period_maps', ['base_period_id' => $periodId], 'relative_index ASC');
    foreach ($mappings as $m) {
        $targetPeriod = $DB->get_record('gmk_academic_periods', ['id' => $m->target_period_id]);
        if ($targetPeriod) {
            $columnName = 'P-' . ($m->relative_index + 1);
            $targetPeriodByColumn[$columnName] = [
                'target_id' => $m->target_period_id,
                'target_name' => $targetPeriod->name
            ];
        }
    }

    if (!empty($targetPeriodByColumn)) {
        echo '<table>';
        echo '<tr><th>Columna</th><th>Target Period ID</th><th>Target Period Name</th></tr>';
        foreach ($targetPeriodByColumn as $col => $data) {
            echo "<tr><td><strong>{$col}</strong></td><td>{$data['target_id']}</td><td>{$data['target_name']}</td></tr>";
        }
        echo '</table>';
    } else {
        echo '<p>No hay mappings - usando automatic logic (P-I = periodId, P-II = periodId+1, etc.)</p>';
    }
    echo '</div>';

    echo '<div class="card">';
    echo '<h3>Test: ¿Cuántos registros hay en gmk_academic_planning para cada target period?</h3>';
    echo '<table>';
    echo '<tr><th>Periodo</th><th>Registros en gmk_academic_planning</th><th>Notas</th></tr>';

    if (!empty($targetPeriodByColumn)) {
        foreach ($targetPeriodByColumn as $col => $data) {
            $count = $DB->count_records('gmk_academic_planning', ['academicperiodid' => $data['target_id']]);
            echo "<tr><td>{$col} ({$data['target_name']})</td><td>{$count}</td><td>academicperiodid={$data['target_id']}</td></tr>";
        }
    } else {
        $count = $DB->count_records('gmk_academic_planning', ['academicperiodid' => $periodId]);
        echo "<tr><td>Base (periodId={$periodId})</td><td>{$count}</td><td>Sin mappings</td></tr>";
    }
    echo '</table>';
    echo '</div>';
}

echo '<div class="card">';
echo '<h3>Referencia Rápida</h3>';
echo '<ul>';
echo '<li><strong>base_period_id</strong>: El periodo seleccionado en el dropdown de Horarios</li>';
echo '<li><strong>relative_index</strong>: 0=P-I, 1=P-II, 2=P-III, etc.</li>';
echo '<li><strong>target_period_id</strong>: El periodo lectivo concreto asociado a esa columna</li>';
echo '<li><strong>gmk_academic_planning.academicperiodid</strong>: Se filtra por target_period_id, no por base_period_id</li>';
echo '</ul>';
echo '</div>';

echo '</div>'; // debug-container
echo $OUTPUT->footer();