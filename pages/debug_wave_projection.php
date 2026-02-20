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

echo "<h1>Wave Projection Diagnostic (Fixed V2)</h1>";
echo "<p>Period ID: $periodId</p>";

echo "<h2>1. Subject Bimestre Metadata</h2>";
echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
echo "<thead><tr style='background: #eee;'><th>Subject ID</th><th>Name</th><th>Level</th><th>Bimestre</th><th>Careers</th></tr></thead>";
echo "<tbody>";

if (!empty($data['all_subjects'])) {
    foreach ($data['all_subjects'] as $s) {
        // Normalize to array for easier access
        $sArr = is_object($s) ? (array)$s : $s;
        
        $id = isset($sArr['id']) ? $sArr['id'] : 'N/A';
        $name = isset($sArr['name']) ? $sArr['name'] : (isset($sArr['fullname']) ? $sArr['fullname'] : 'Unknown');
        $level = isset($sArr['semester_num']) ? $sArr['semester_num'] : '0';
        $bimestre = isset($sArr['bimestre']) ? $sArr['bimestre'] : '0';
        $careers = isset($sArr['careers']) ? implode(', ', $sArr['careers']) : 'N/A';
        
        echo "<tr><td>$id</td><td>$name</td><td>$level</td><td>$bimestre</td><td style='font-size: 10px;'>$careers</td></tr>";
    }
} else {
    echo "<tr><td colspan='5'>No subjects found.</td></tr>";
}
echo "</tbody></table>";

echo "<h2>2. Student Cohorts Summary</h2>";
$cohortGroups = [];
if (!empty($data['students'])) {
    foreach ($data['students'] as $stu) {
        $cName = $stu['career'] ?: 'Sin Carrera';
        $sName = $stu['shift'] ?: 'Sin Jornada';
        $lName = $stu['currentSemConfig'] ?: 'Sin Nivel';
        $bName = $stu['currentSubperiodConfig'] ?: 'Sin Bimestre';
        
        $key = "$cName | $sName | $lName | $bName";
        if (!isset($cohortGroups[$key])) {
            $cohortGroups[$key] = 0;
        }
        $cohortGroups[$key]++;
    }
}

echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
echo "<thead><tr style='background: #eee;'><th>Cohort (Career | Shift | Level | Subperiod)</th><th>Student Count</th></tr></thead>";
echo "<tbody>";
ksort($cohortGroups);
foreach ($cohortGroups as $key => $count) {
    echo "<tr><td>$key</td><td align='center'>$count</td></tr>";
}
echo "</tbody></table>";

echo '<h2>3. Raw Data Extract (Top 5 Subjects)</h2>';
echo '<pre>';
print_r(array_slice($data['all_subjects'], 0, 5));
echo '</pre>';
