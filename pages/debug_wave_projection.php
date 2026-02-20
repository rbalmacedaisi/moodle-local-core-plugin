<?php
// Enable error reporting to find 500 cause
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/planning_manager.php');

use local_grupomakro_core\local\planning_manager;

require_login();

$periodId = optional_param('periodid', 2, PARAM_INT);
try {
    $data = planning_manager::get_planning_data($periodId);
} catch (Exception $e) {
    echo "<h1>Error fetching data</h1>";
    echo "<pre>" . $e->getMessage() . "\n" . $e->getTraceAsString() . "</pre>";
    die();
}

echo "<h1>Wave Projection Diagnostic (Fixed V3)</h1>";
echo "<p>Period ID: $periodId</p>";
echo "<div style='background: #fff3cd; padding: 15px; border-radius: 5px; margin-bottom: 20px; font-weight: bold;'>";
echo "Nota: Si ves '-' en las materias de Nivel I para el futuro (P-II en adelante), es CORRECTO. <br/>";
echo "Eso significa que los estudiantes de Nivel I ya habrán avanzado a Niveles superiores y ya no necesitan esas materias.";
echo "</div>";

// Calculate Top Projections manually in PHP to demonstrate the logic
$projections = [];
foreach ($data['all_subjects'] as $s) {
    $sArr = (array)$s;
    $sName = $sArr['name'] ?? 'Unknown';
    $projections[$sName] = [
        'id' => $sArr['id'] ?? '?',
        'level' => $sArr['semester_num'] ?? 0,
        'bimestre' => $sArr['bimestre'] ?? 0,
        'p2' => 0, 'p3' => 0, 'p4' => 0
    ];
}

// Simplified Wave logic for debug display
foreach ($data['students'] as $stu) {
    $curL = (int)filter_var($stu['currentSemConfig'], FILTER_SANITIZE_NUMBER_INT) ?: 1;
    $isB2 = (strpos(strtoupper($stu['currentSubperiodConfig']), 'II') !== false || strpos($stu['currentSubperiodConfig'], '2') !== false);
    
    // Start at P-I Planning state
    if ($isB2) { $curL++; $curB = 1; } 
    else { $curB = 2; }
    
    // Wave P-II to P-IV
    for ($p = 2; $p <= 4; $p++) {
        // Advance
        if ($curB == 1) { $curB = 2; }
        else { $curB = 1; $curL++; }
        
        // Find subjects
        foreach ($data['all_subjects'] as $s) {
            $sArr = (array)$s;
            if (($sArr['semester_num'] ?? 0) == $curL && ($sArr['bimestre'] ?? 0) == $curB) {
                if (in_array($stu['career'], $sArr['careers'] ?? [])) {
                    $projections[$sArr['name']]['p'.$p]++;
                }
            }
        }
    }
}

echo "<h2>2. Top Proyectados en el Futuro (P-II, P-III, P-IV)</h2>";
echo "<table border='1' cellpadding='8' style='border-collapse: collapse; min-width: 600px;'>";
echo "<thead><tr style='background: #004085; color: white;'><th>Materia</th><th>Nivel</th><th>Bimestre</th><th>P-II (Futuro)</th><th>P-III</th><th>P-IV</th></tr></thead>";
echo "<tbody>";

uasort($projections, function($a, $b) { return ($b['p2'] + $b['p3']) - ($a['p2'] + $a['p3']); });

$count = 0;
foreach ($projections as $name => $p) {
    if ($p['p2'] == 0 && $p['p3'] == 0 && $p['p4'] == 0) continue;
    $count++;
    echo "<tr><td>$name</td><td align='center'>{$p['level']}</td><td align='center'>{$p['bimestre']}</td>";
    echo "<td align='center' style='font-weight: bold; background: #e7f3ff;'>{$p['p2']}</td>";
    echo "<td align='center'>{$p['p3']}</td>";
    echo "<td align='center'>{$p['p4']}</td></tr>";
    if ($count > 15) break; 
}
if ($count == 0) echo "<tr><td colspan='6' align='center'>No se detectó demanda futura. Revisa el filtrado por carrera.</td></tr>";

echo "</tbody></table>";

echo "<h2>3. Detalle de Estudiantes (Cohortes)</h2>";
echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
echo "<thead><tr style='background: #eee;'><th>Carrera | Jornada | Nivel | Bimestre Actual</th><th>Total Estudiantes</th></tr></thead>";
$cohortGroups = [];
foreach ($data['students'] as $stu) {
    $key = "{$stu['career']} | {$stu['shift']} | {$stu['currentSemConfig']} | {$stu['currentSubperiodConfig']}";
    $cohortGroups[$key] = ($cohortGroups[$key] ?? 0) + 1;
}
ksort($cohortGroups);
foreach ($cohortGroups as $k => $c) {
    echo "<tr><td>$k</td><td align='center'>$c</td></tr>";
}
echo "</table>";
