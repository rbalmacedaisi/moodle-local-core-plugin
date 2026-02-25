<?php
require_once(__DIR__ . '/../../../config.php');
global $DB;

$periodid = optional_param('periodid', 0, PARAM_INT);

echo "<html><head><title>Debug Draft Sync</title><style>
    body { font-family: sans-serif; padding: 20px; background: #f8fafc; color: #334155; }
    h1, h2 { color: #1e293b; }
    .card { background: white; padding: 15px; border-radius: 8px; border: 1px solid #e2e8f0; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    th, td { text-align: left; padding: 8px; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
    th { background: #f8fafc; font-weight: bold; }
    .external { color: #b45309; font-weight: bold; }
    select, button { padding: 8px; border-radius: 4px; border: 1px solid #ccc; }
</style></head><body>";

if (!$periodid) {
    $periods = $DB->get_records('gmk_academic_periods', null, 'id DESC');
    echo "<h1>Debug Scheduler Data - Select Period</h1>";
    echo "<form method='GET'><select name='periodid'>";
    foreach ($periods as $p) {
        echo "<option value='{$p->id}'>{$p->name} (ID: {$p->id})</option>";
    }
    echo "</select> <button type='submit'>Analyze</button></form>";
    echo "</body></html>";
    exit;
}

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

    $sql = "SELECT c.id, c.name as subjectname, c.periodid, c.initdate, c.enddate
            FROM {gmk_class} c
            WHERE (c.periodid != ? OR c.periodid IS NULL OR c.periodid = 0)
              AND c.initdate <= ?
              AND c.enddate >= ?";
    
    $liveExternals = $DB->get_records_sql($sql, [$periodid, $pE, $pS]);
    echo "Live Externals identified in gmk_class: " . count($liveExternals) . "<br>";

    if (count($liveExternals) > 0) {
        echo "<table><thead><tr><th>Class ID</th><th>Subject</th><th>Period</th><th>Start</th><th>End</th></tr></thead><tbody>";
        foreach ($liveExternals as $le) {
            $isID125 = ($le->id == 125) ? " style='background: #fef3c7;'" : "";
            echo "<tr$isID125><td>{$le->id}</td><td>{$le->subjectname}</td><td>{$le->periodid}</td><td>" . date('Y-m-d', $le->initdate) . "</td><td>" . date('Y-m-d', $le->enddate) . "</td></tr>";
        }
        echo "</tbody></table>";
    }
} else {
    echo "Period not found.";
}
echo "</div>";

// 3. ORPHAN CHECK (Are there overlaps we didn't expect?)
echo "<div class='card'>";
echo "<h2>3. Investigation: The 'Hidden' 55 Externals</h2>";
echo "<p>The Planning Board says 56 externals. My SQL found only 1 (ID 125). Let's see everything that overlaps period $periodid regardless of filters.</p>";

if ($currentPeriod) {
    echo "<p>Using Period ID: $periodid (Dates: " . date('Y-m-d', $pS) . " to " . date('Y-m-d', $pE) . ")</p>";
    $sqlAllOverlaps = "SELECT c.id, c.name, c.periodid, c.courseid, c.corecourseid, c.initdate, c.enddate
                       FROM {gmk_class} c
                       WHERE c.initdate <= ? AND c.enddate >= ?
                       ORDER BY c.id ASC";
    $allOverlaps = $DB->get_records_sql($sqlAllOverlaps, [$pE, $pS]);
    
    echo "Total records overlapping dates of Period $periodid: " . count($allOverlaps) . "<br>";
    
    echo "<table><thead><tr><th>ID</th><th>Subject</th><th>courseid</th><th>coreid</th><th>PeriodCol</th><th>Status</th><th>isExtSim</th></tr></thead><tbody>";
    foreach ($allOverlaps as $c) {
        $isInternal = ($c->periodid == $periodid);
        $status = $isInternal ? "INTERNAL" : "EXTERNAL";
        if ($c->id == 125) $status .= " (ID 125)";
        
        // Simulate Healing logic (exactly like scheduler.php fix)
        $academic_period_id = (int)($c->periodid ?? 0);
        if (empty($academic_period_id) && !empty($c->courseid)) {
            $subj = $DB->get_record('local_learning_courses', ['id' => $c->courseid], 'periodid');
            if ($subj) $academic_period_id = $subj->periodid;
        }
        $finalPeriodId = (int)$academic_period_id;
        $isExtSim = ($finalPeriodId !== (int)$periodid && $finalPeriodId !== 0) ? 'YES' : 'NO';

        $style = "";
        if ($isExtSim == 'YES') $style = " style='background: #fee2e2;'";
        if (empty($c->periodid)) $style = " style='background: #fef3c7;'";
        
        echo "<tr$style><td>{$c->id}</td><td>{$c->name}</td><td>{$c->courseid}</td><td>{$c->corecourseid}</td><td>" . ($c->periodid ?: '0') . "</td><td>$status</td><td>$isExtSim</td></tr>";
    }
    echo "</tbody></table>";
}
// 4. ACTUAL FUNCTION CALL
echo "<div class='card'>";
echo "<h2>4. Actual Backend Output (local_grupomakro_core_scheduler::get_generated_schedules)</h2>";
echo "<p>Let's call the function that the AJAX uses and see exactly what it returns.</p>";

require_once(__DIR__ . '/../classes/external/admin/scheduler.php');
try {
    $realItems = \local_grupomakro_core_scheduler::get_generated_schedules($periodid, true);
    echo "Function returned " . count($realItems) . " items.<br>";
    
    echo "<table><thead><tr><th>ID</th><th>subjectName</th><th>periodid</th><th>isExternal</th></tr></thead><tbody>";
    foreach ($realItems as $item) {
        // Show ID 125, 9114, and first 5 matches
        $show = false;
        if ($item['id'] == 125 || $item['id'] == 9114) $show = true;
        
        if ($show || count($realItems) < 10) {
            $style = ($item['isExternal'] ? " style='background: #fee2e2;'" : "");
            echo "<tr$style><td>{$item['id']}</td><td>{$item['subjectName']}</td><td>{$item['periodid']}</td><td>" . ($item['isExternal'] ? 'YES' : 'NO') . "</td></tr>";
        }
    }
    echo "</tbody></table>";
    echo "<p><i>(Showing only IDs 125 and 9114 for clarity)</i></p>";
} catch (\Exception $e) {
    echo "Error calling function: " . $e->getMessage();
}
echo "</div>";

echo "</body></html>";
