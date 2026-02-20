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

// 0. Dump Subperiods Table
echo "<h2>0. Subperiods Table Raw Data</h2>";
$subperiodsTable = $DB->get_records('local_learning_subperiods', [], 'id ASC', '*', 0, 50);
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>Name</th><th>Plan ID</th><th>Period ID</th><th>Position</th></tr>";
foreach ($subperiodsTable as $sp) {
    echo "<tr><td>{$sp->id}</td><td>{$sp->name}</td><td>{$sp->learningplanid}</td><td>{$sp->periodid}</td><td>{$sp->position}</td></tr>";
}
echo "</table>";

// 1b. Map Subperiods for name resolution in Subjects table
$subNamesMap = [];
foreach ($subperiodsTable as $sp) {
    $subNamesMap[$sp->position + 1] = $sp->name; // position is 0-indexed in DB
}

echo "<h2>1. Subject Bimestre Metadata</h2>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Subject ID</th><th>Name</th><th>Level</th><th>Bimestre (Pos)</th><th>Probable Name</th></tr>";
foreach ($data['all_subjects'] as $s) {
    if (is_array($s)) {
        $id = $s['id'];
        $name = $s['name'];
        $level = $s['semester_num'];
        $bimestre = isset($s['bimestre']) ? $s['bimestre'] : 'NOT SET';
    } else {
        $id = $s->id;
        $name = $s->fullname;
        $level = $s->semester_num;
        $bimestre = isset($s->bimestre) ? $s->bimestre : 'NOT SET';
    }
    
    $spName = isset($subNamesMap[$bimestre]) ? $subNamesMap[$bimestre] : 'N/A';
    
    $color = ($bimestre === 'NOT SET') ? 'red' : 'inherit';
    echo "<tr><td>$id</td><td>$name</td><td>$level</td><td style='color:$color'>$bimestre</td><td>$spName</td></tr>";
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
