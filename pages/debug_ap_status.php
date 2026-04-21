<?php
require_once(__DIR__ . '/../../../config.php');
global $DB, $PAGE, $OUTPUT, $CFG;

$context = context_system::instance();
require_login();
require_capability('moodle/site:config', $context);

$PAGE->set_url('/local/grupomakro_core/pages/debug_ap_status.php');
$PAGE->set_context($context);
$PAGE->set_title('Debug - Academic Planning Status');
$PAGE->set_heading('Debug: gmk_academic_planning Status Breakdown');
$PAGE->set_pagelayout('admin');

echo $OUTPUT->header();

echo '<style>
body { font-family: "Segoe UI", Tahoma, sans-serif; background: #f8fafc; padding: 20px; }
.debug-container { max-width: 1200px; margin: 0 auto; }
h1 { color: #1e293b; border-bottom: 3px solid #3b82f6; padding-bottom: 10px; }
h2 { color: #334155; margin-top: 30px; border-left: 4px solid #3b82f6; padding-left: 12px; }
.card { background: white; border-radius: 12px; padding: 20px; margin: 15px 0; box-shadow: 0 2px 8px rgba(0,0,0,0.08); border: 1px solid #e2e8f0; }
table { width: 100%; border-collapse: collapse; margin: 10px 0; font-size: 13px; }
th { background: #f1f5f9; padding: 10px; text-align: left; font-weight: 600; color: #334155; border-bottom: 2px solid #cbd5e1; }
td { padding: 8px 10px; border-bottom: 1px solid #e2e8f0; }
.badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
.badge-green { background: #dcfce7; color: #166534; }
.badge-red { background: #fee2e2; color: #991b1b; }
.badge-yellow { background: #fef3c7; color: #92400e; }
.badge-blue { background: #dbeafe; color: #1e40af; }
.badge-gray { background: #f1f5f9; color: #475569; }
pre { background: #1e293b; color: #e2e8f0; padding: 15px; border-radius: 8px; overflow-x: auto; font-size: 12px; }
</style>';

echo '<div class="debug-container">';
echo '<h1>Debug: gmk_academic_planning Status Breakdown</h1>';

$periodId = optional_param('periodid', 0, PARAM_INT);

echo '<div class="card">';
echo '<h3>Seleccionar Periodo</h3>';
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
    echo '<div class="card">';
    echo '<h3>Periodo Seleccionado: ID=' . $periodId . '</h3>';
    $period = $DB->get_record('gmk_academic_periods', ['id' => $periodId]);
    if ($period) {
        echo '<p><strong>Nombre:</strong> ' . $period->name . ' | <strong>Status:</strong> ' . $period->status . '</p>';
    }
    echo '</div>';

    echo '<div class="card">';
    echo '<h3>Status Breakdown (Status vs Count)</h3>';
    $sql = "SELECT status, COUNT(*) as total FROM {gmk_academic_planning} WHERE academicperiodid = ? GROUP BY status ORDER BY status";
    $statusCounts = $DB->get_records_sql($sql, [$periodId]);
    echo '<table>';
    echo '<tr><th>Status</th><th>Count</th><th>Description</th></tr>';
    $total = 0;
    foreach ($statusCounts as $sc) {
        $desc = '';
        if ($sc->status == 0) $desc = 'No Disponible';
        elseif ($sc->status == 1) $desc = 'Disponible (CONFIRMED)';
        elseif ($sc->status == 2) $desc = 'IGNORED (Omitir Auto)';
        elseif ($sc->status == 3) $desc = 'Approved/Completed';
        elseif ($sc->status == 4) $desc = 'Approved/Completed';
        elseif ($sc->status == 5) $desc = 'Failed/Reprobada';
        else $desc = 'Other';
        $badgeClass = $sc->status == 2 ? 'badge-red' : ($sc->status == 1 ? 'badge-green' : 'badge-gray');
        echo "<tr><td><span class='badge {$badgeClass}'>{$sc->status}</span></td><td><strong>{$sc->total}</strong></td><td>{$desc}</td></tr>";
        $total += $sc->total;
    }
    echo '<tr style="background:#f1f5f9;font-weight:bold;"><td>TOTAL</td><td>' . $total . '</td><td></td></tr>';
    echo '</table>';
    echo '</div>';

    echo '<div class="card">';
    echo '<h3>Registros NO Ignored (status != 2)</h3>';
    $nonIgnored = $DB->get_records('gmk_academic_planning', ['academicperiodid' => $periodId, 'status' => 1]);
    echo '<p><span class="badge badge-green">' . count($nonIgnored) . '</span> registros con status=1 (Disponible)</p>';
    if (count($nonIgnored) > 0) {
        echo '<table>';
        echo '<tr><th>ID</th><th>courseid</th><th>learningplanid</th><th>periodid</th><th>projected_students</th></tr>';
        foreach ($nonIgnored as $ni) {
            echo "<tr><td>{$ni->id}</td><td>{$ni->courseid}</td><td>{$ni->learningplanid}</td><td>{$ni->periodid}</td><td>{$ni->projected_students}</td></tr>";
        }
        echo '</table>';
    }
    echo '</div>';

    echo '<div class="card">';
    echo '<h3>Primeros 20 registros IGNORED (status=2)</h3>';
    $ignored = $DB->get_records('gmk_academic_planning', ['academicperiodid' => $periodId, 'status' => 2], 'id ASC', '*', 0, 20);
    echo '<p><span class="badge badge-red">' . $DB->count_records('gmk_academic_planning', ['academicperiodid' => $periodId, 'status' => 2]) . '</span> registros con status=2 (Ignored)</p>';
    if ($ignored) {
        echo '<table>';
        echo '<tr><th>ID</th><th>courseid</th><th>learningplanid</th><th>periodid</th><th>projected_students</th></tr>';
        foreach ($ignored as $ig) {
            echo "<tr><td>{$ig->id}</td><td>{$ig->courseid}</td><td>{$ig->learningplanid}</td><td>{$ig->periodid}</td><td>{$ig->projected_students}</td></tr>";
        }
        echo '</table>';
    }
    echo '</div>';

    echo '<div class="card">';
    echo '<h3>Todos los registros (status 0, 1, 99, etc.) - Primeros 30</h3>';
    $others = $DB->get_records_select('gmk_academic_planning', "academicperiodid = ? AND status NOT IN (2)", [$periodId], 'id ASC', '*', 0, 30);
    echo '<p>Total: <span class="badge badge-blue">' . $DB->count_records_select('gmk_academic_planning', "academicperiodid = ? AND status NOT IN (2)", [$periodId]) . '</span></p>';
    if ($others) {
        echo '<table>';
        echo '<tr><th>ID</th><th>courseid</th><th>learningplanid</th><th>status</th><th>projected_students</th></tr>';
        foreach ($others as $ot) {
            $badgeClass = $ot->status == 1 ? 'badge-green' : ($ot->status == 0 ? 'badge-yellow' : 'badge-gray');
            echo "<tr><td>{$ot->id}</td><td>{$ot->courseid}</td><td>{$ot->learningplanid}</td><td><span class='badge {$badgeClass}'>{$ot->status}</span></td><td>{$ot->projected_students}</td></tr>";
        }
        echo '</table>';
    }
    echo '</div>';
}

echo '</div>';
echo $OUTPUT->footer();