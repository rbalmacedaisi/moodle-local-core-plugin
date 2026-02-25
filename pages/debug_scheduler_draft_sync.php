<?php
require_once(__DIR__ . '/../../../config.php');
global $DB;

$periodid = required_param('periodid', PARAM_INT);

echo "<html><head><title>Debug Draft Sync</title><style>
    body { font-family: sans-serif; padding: 20px; background: #f8fafc; color: #334155; }
    h1, h2 { color: #1e293b; }
    .card { background: white; padding: 15px; border-radius: 8px; border: 1px solid #e2e8f0; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    th, td { text-align: left; padding: 8px; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
    th { background: #f8fafc; font-weight: bold; }
    .external { color: #b45309; font-weight: bold; }
</style></head><body>";

echo "<h1>Debug Scheduler Data - Period $periodid</h1>";

// 1. DRAFT DATA
$draft = $DB->get_field('gmk_academic_periods', 'draft_schedules', ['id' => $periodid]);
$decoded = json_decode($draft, true) ?: [];

echo "<div class='card'>";
echo "<h2>1. Draft Table (gmk_academic_periods.draft_schedules)</h2>";
echo "Total Items: " . count($decoded) . "<br>";

$draftExternals = array_filter($decoded, function($s) use ($periodid) {
    return ($s['isExternal'] ?? false) || (isset($s['periodid']) && $s['periodid'] != $periodid);
});
echo "Externals found in Draft: " . count($draftExternals) . "<br>";

if (count($draftExternals) > 0) {
    echo "<table><thead><tr><th>ID</th><th>Subject</th><th>Period</th><th>isExternal</th></tr></thead><tbody>";
    foreach ($draftExternals as $s) {
        echo "<tr><td>{$s['id']}</td><td>{$s['subjectName']}</td><td>" . ($s['periodid'] ?? 'N/A') . "</td><td>" . (($s['isExternal'] ?? false) ? 'YES' : 'NO') . "</td></tr>";
    }
    echo "</tbody></table>";
}
echo "</div>";

// 2. LIVE EXTERNAL DATA (What the DB thinks are overlaps)
echo "<div class='card'>";
echo "<h2>2. Database Externals (Recalculated from gmk_class_schedules)</h2>";

// Logic from scheduler.php to find overlaps
$currentPeriod = $DB->get_record('gmk_academic_periods', ['id' => $periodid]);
if ($currentPeriod) {
    $pS = $currentPeriod->startdate;
    $pE = $currentPeriod->enddate;

    $sql = "SELECT DISTINCT s.id, s.classid, c.name as subjectname, c.periodid
            FROM {gmk_class_schedules} s
            JOIN {gmk_class} c ON c.id = s.classid
            WHERE c.periodid != ?
              AND c.initdate <= ?
              AND c.enddate >= ?";
    
    $liveExternals = $DB->get_records_sql($sql, [$periodid, $pE, $pS]);
    echo "Live Externals identified: " . count($liveExternals) . "<br>";

    if (count($liveExternals) > 0) {
        echo "<table><thead><tr><th>Schedule ID</th><th>Class ID</th><th>Subject</th><th>Period</th></tr></thead><tbody>";
        foreach ($liveExternals as $le) {
            $isID125 = ($le->id == 125) ? " style='background: #fef3c7;'" : "";
            echo "<tr$isID125><td>{$le->id}</td><td>{$le->classid}</td><td>{$le->subjectname}</td><td>{$le->periodid}</td></tr>";
        }
        echo "</tbody></table>";
    }
} else {
    echo "Period not found.";
}
echo "</div>";

echo "</body></html>";
