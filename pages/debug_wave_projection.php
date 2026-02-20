<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/planning_manager.php');

use local_grupomakro_core\local\planning_manager;

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$periodId = optional_param('periodid', 0, PARAM_INT);
if (!$periodId) {
    $periodId = $DB->get_field_sql("SELECT id FROM {gmk_academic_periods} WHERE status = 1 ORDER BY id DESC", [], IGNORE_MULTIPLE);
}

$data = planning_manager::get_planning_data($periodId);

echo "<h1>Wave Projection Diagnostic</h1>";
echo "<p>Period ID: $periodId</p>";

echo "<h2>1. Subject Bimestre Metadata</h2>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Subject ID</th><th>Name</th><th>Level</th><th>Bimestre (Backend)</th></tr>";
foreach ($data['all_subjects'] as $s) {
    $bimestre = isset($s->bimestre) ? $s->bimestre : 'NOT SET';
    $color = ($bimestre === 'NOT SET') ? 'red' : 'inherit';
    echo "<tr><td>{$s->id}</td><td>{$s->fullname}</td><td>{$s->semester_num}</td><td style='color:$color'>$bimestre</td></tr>";
}
echo "</table>";

echo "<h2>2. Student Cohorts</h2>";
echo "<pre>";
// Extract sample cohorts to see curL and curB
$cohorts = [];
foreach ($data['students'] as $st) {
    if (!isset($st['currentSemConfig'])) continue;
    $ckey = "{$st['career']} - {$st['shift']} - {$st['currentSemConfig']} - {$st['currentSubperiodConfig']}";
    if (!isset($cohorts[$ckey])) {
        $cohorts[$ckey] = [
            'career' => $st['career'],
            'shift' => $st['shift'],
            'level' => $st['currentSemConfig'],
            'subperiod' => $st['currentSubperiodConfig'],
            'count' => 0
        ];
    }
    $cohorts[$ckey]['count']++;
}
print_r(array_slice($cohorts, 0, 10)); // Top 10 cohorts
echo "</pre>";

echo "<h2>3. Raw Data Sample</h2>";
echo "<pre>";
print_r(array_slice($data['all_subjects'], 0, 5));
echo "</pre>";
