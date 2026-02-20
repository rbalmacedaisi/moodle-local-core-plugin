<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/planning_manager.php');

use local_grupomakro_core\local\planning_manager;

require_login();

$periodId = optional_param('periodid', 2, PARAM_INT);
$data = planning_manager::get_planning_data($periodId);

echo "<h1>Wave Projection Diagnostic (Fixed)</h1>";
echo "<p>Period ID: $periodId</p>";

echo "<h2>1. Subject Bimestre Metadata</h2>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Subject ID</th><th>Name</th><th>Level</th><th>Bimestre</th></tr>";
foreach ($data['all_subjects'] as $s) {
    echo "<tr><td>{$s['id']}</td><td>{$s['name']}</td><td>{$s['semester_num']}</td><td>{$s['bimestre']}</td></tr>";
}
echo "</table>";

echo "<h2>2. Student Cohorts</h2>";
$cohorts = [];
foreach ($data['students'] as $stu) {
    $key = $stu['career'] . ' - ' . $stu['shift'] . ' - ' . $stu['currentSemConfig'] . ' - ' . $stu['currentSubperiodConfig'];
    if (!isset($cohorts[$key])) {
        $cohorts[$key] = [
            'career' => $stu['career'],
            'shift' => $stu['shift'],
            'level' => $stu['currentSemConfig'],
            'subperiod' => $stu['currentSubperiodConfig'],
            'count' => 0
        ];
    }
    $cohorts[$key]['count']++;
}

echo "<pre>";
print_r($cohorts);
echo "</pre>";
