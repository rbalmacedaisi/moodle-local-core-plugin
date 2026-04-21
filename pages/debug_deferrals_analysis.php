<?php
require_once(__DIR__ . '/../../../config.php');
global $DB, $PAGE, $OUTPUT, $CFG;

$context = context_system::instance();
require_login();
require_capability('moodle/site:config', $context);

$PAGE->set_url('/local/grupomakro_core/pages/debug_deferrals_analysis.php');
$PAGE->set_context($context);
$PAGE->set_title('Debug - Deferrals Analysis');
$PAGE->set_heading('Debug: Deferrals & Student Projection Analysis');
$PAGE->set_pagelayout('admin');

echo $OUTPUT->header();

echo '<style>
body { font-family: "Segoe UI", Tahoma, sans-serif; background: #f8fafc; padding: 20px; }
.debug-container { max-width: 1400px; margin: 0 auto; }
h1 { color: #1e293b; border-bottom: 3px solid #3b82f6; padding-bottom: 10px; }
h2 { color: #334155; margin-top: 30px; border-left: 4px solid #3b82f6; padding-left: 12px; }
h3 { color: #475569; margin-top: 20px; }
.card { background: white; border-radius: 12px; padding: 20px; margin: 15px 0; box-shadow: 0 2px 8px rgba(0,0,0,0.08); border: 1px solid #e2e8f0; }
table { width: 100%; border-collapse: collapse; margin: 10px 0; font-size: 13px; }
th { background: #f1f5f9; padding: 10px; text-align: left; font-weight: 600; color: #334155; border-bottom: 2px solid #cbd5e1; position: sticky; top: 0; z-index: 10; }
td { padding: 8px 10px; border-bottom: 1px solid #e2e8f0; }
tr:hover { background: #f8fafc; }
.badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.badge-green { background: #dcfce7; color: #166534; }
.badge-red { background: #fee2e2; color: #991b1b; }
.badge-yellow { background: #fef3c7; color: #92400e; }
.badge-blue { background: #dbeafe; color: #1e40af; }
.badge-gray { background: #f1f5f9; color: #475569; }
.overflow-auto { overflow-x: auto; max-height: 500px; overflow-y: auto; }
</style>';

echo '<div class="debug-container">';
echo '<h1>Debug: Deferrals & Student Projection Analysis</h1>';

$periodId = optional_param('periodid', 0, PARAM_INT);

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
echo '</form>';
echo '</div>';

if ($periodId) {
    $period = $DB->get_record('gmk_academic_periods', ['id' => $periodId]);
    echo '<div class="card">';
    echo '<h3>Periodo Seleccionado: ID=' . $periodId . ' (' . ($period ? $period->name : 'N/A') . ')</h3>';
    echo '</div>';

    echo '<div class="card">';
    echo '<h2>gmk_academic_deferrals - Registros para este periodo</h2>';
    $deferrals = $DB->get_records('gmk_academic_deferrals', ['academicperiodid' => $periodId]);
    echo '<p><span class="badge badge-blue">' . count($deferrals) . '</span> registros de deferrals</p>';

    if ($deferrals) {
        echo '<div class="overflow-auto">';
        echo '<table>';
        echo '<tr><th>ID</th><th>courseid</th><th>career</th><th>shift</th><th>current_level</th><th>target_period_index</th><th>Descripción</th></tr>';
        foreach ($deferrals as $d) {
            $desc = '';
            if ($d->target_period_index == 0) $desc = 'P-I';
            elseif ($d->target_period_index == 1) $desc = 'P-II';
            else $desc = 'P-' . ($d->target_period_index + 1);
            $badgeClass = $d->target_period_index == 0 ? 'badge-green' : ($d->target_period_index == 1 ? 'badge-yellow' : 'badge-gray');
            echo "<tr>
                <td>{$d->id}</td>
                <td>{$d->courseid}</td>
                <td>{$d->career}</td>
                <td>{$d->shift}</td>
                <td>{$d->current_level}</td>
                <td><span class='badge {$badgeClass}'>{$d->target_period_index} ({$desc})</span></td>
                <td>{$desc}</td>
            </tr>";
        }
        echo '</table>';
        echo '</div>';
    }
    echo '</div>';

    echo '<div class="card">';
    echo '<h2>Resumen: ¿Cuántos deferrals por target_period_index?</h2>';
    $sql = "SELECT target_period_index, COUNT(*) as total FROM {gmk_academic_deferrals} WHERE academicperiodid = ? GROUP BY target_period_index ORDER BY target_period_index";
    $summary = $DB->get_records_sql($sql, [$periodId]);
    echo '<table>';
    echo '<tr><th>target_period_index</th><th>Periodo</th><th>Cantidad</th></tr>';
    foreach ($summary as $s) {
        $desc = $s->target_period_index == 0 ? 'P-I' : 'P-II';
        if ($s->target_period_index > 1) $desc = 'P-' . ($s->target_period_index + 1);
        echo "<tr><td>{$s->target_period_index}</td><td>{$desc}</td><td>{$s->total}</td></tr>";
    }
    echo '</table>';
    echo '</div>';

    echo '<div class="card">';
    echo '<h2>Estudiantes con pending subjects que coinciden con cursos en deferrals P-II</h2>';
    echo '<p>Un estudiante deberia aparecer en P-II si:</p>';
    echo '<ul>';
    echo '<li>1. Su cohort (career-shift-level) tiene un deferral a P-II para alguna de sus pending subjects, O</li>';
    echo '<li>2. El estudiante esta en currentsubperiod = BIMESTRE 2 (y avanza al siguiente nivel en P-II)</li>';
    echo '</ul>';

    $sql = "SELECT llu.id, u.firstname, u.lastname, u.idnumber, lp.name as planname,
                   p.name as periodname, sp.name as subperiodname
            FROM {local_learning_users} llu
            JOIN {user} u ON u.id = llu.userid
            JOIN {local_learning_plans} lp ON lp.id = llu.learningplanid
            LEFT JOIN {local_learning_periods} p ON p.id = llu.currentperiodid
            LEFT JOIN {local_learning_subperiods} sp ON sp.id = llu.currentsubperiodid
            WHERE u.deleted = 0 AND u.suspended = 0 AND llu.status = 'activo'
            ORDER BY lp.name, u.lastname
            LIMIT 50";
    $students = $DB->get_records_sql($sql);

    echo '<p>Primeros 50 estudiantes activos:</p>';
    echo '<div class="overflow-auto">';
    echo '<table>';
    echo '<tr><th>ID</th><th>Nombre</th><th>idnumber</th><th>Plan</th><th>Periodo</th><th>Subperiodo</th></tr>';
    foreach ($students as $s) {
        echo "<tr>
            <td>{$s->id}</td>
            <td>{$s->firstname} {$s->lastname}</td>
            <td>{$s->idnumber}</td>
            <td>{$s->planname}</td>
            <td>{$s->periodname}</td>
            <td><strong>{$s->subperiodname}</strong></td>
        </tr>";
    }
    echo '</table>';
    echo '</div>';
    echo '</div>';

    echo '<div class="card">';
    echo '<h2>Como funciona la logica de get_demand_data:</h2>';
    echo '<pre>';
    echo '1. get_planning_data($periodId) devuelve estudiantes con pendingSubjects
2. get_demand_data usa $planningData["planning_projections"] para construir globalIgnoredMap
3. globalIgnoredMap = cursos con status=2 en gmk_academic_planning para el targetPeriodId
4. En Paso 3, se excluyen estudiantes si TODAS sus pending subjects estan en globalIgnoredMap

PROBLEMA: El codigo filtra por academicperiodid=$periodId directamente,
pero los deferrals son por academicperiodid del periodo BASE, y los
estudiantes pueden estar en diferentes subperiodos (P-I o P-II).

El filtro course_ignored deveria considerar:
- Si el estudiante esta en P-I (BIMESTRE 1) -> usar academicperiodid del mapping P-I
- Si el estudiante esta en P-II (BIMESTRE 2) -> usar academicperiodid del mapping P-II
';
    echo '</pre>';
    echo '</div>';
}

echo '</div>';
echo $OUTPUT->footer();