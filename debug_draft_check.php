<?php
define('NO_OUTPUT_BUFFERING', true);
require_once(__DIR__ . '/../../config.php');

$periodid = optional_param('periodid', 0, PARAM_INT);

echo "<h1>Debug Draft Schedules</h1>";

if (!$periodid) {
    $periods = $DB->get_records('gmk_academic_periods', null, 'id DESC');
    echo "<h2>Select a Period:</h2><ul>";
    foreach ($periods as $p) {
        echo "<li><a href='debug_draft_check.php?periodid={$p->id}'>{$p->name} (ID: {$p->id})</a></li>";
    }
    echo "</ul>";
    exit;
}

$period = $DB->get_record('gmk_academic_periods', ['id' => $periodid]);

if (!$period) {
    die("Period not found");
}

echo "<h2>Period: {$period->name}</h2>";
echo "<p>Draft Schedules Content Length: " . strlen($period->draft_schedules) . " characters</p>";

if (empty($period->draft_schedules)) {
    echo "<p style='color:red'>DRAFT IS EMPTY</p>";
} else {
    echo "<h3>Raw Data (first 500 chars):</h3>";
    echo "<pre>" . s(substr($period->draft_schedules, 0, 500)) . "...</pre>";
    
    $decoded = json_decode($period->draft_schedules);
    if (is_null($decoded)) {
        echo "<p style='color:red'>JSON DECODE FAILED: " . json_last_error_msg() . "</p>";
    } else {
        echo "<p style='color:green'>JSON DECODE SUCCESSFUL</p>";
        echo "<p>Count: " . count((array)$decoded) . " items</p>";
        
        echo "<h3>First 3 Items Sample:</h3>";
        $count = 0;
        foreach ($decoded as $item) {
            echo "<pre>" . print_r($item, true) . "</pre>";
            $count++;
            if ($count >= 3) break;
        }
    }
}
