<?php
define('CLI_SCRIPT', true);
require(__DIR__ . '/../../config.php');
global $DB;

$periodName = '2026-I';
$period = $DB->get_record('gmk_academic_periods', ['name' => $periodName]);

if (!$period) {
    echo "Period $periodName not found.\n";
    $periods = $DB->get_records('gmk_academic_periods', null, 'id DESC', 'id, name', 0, 10);
    echo "Recent periods:\n";
    foreach ($periods as $p) {
        echo "- ID: {$p->id}, Name: {$p->name}\n";
    }
} else {
    echo "Period: {$period->name} (ID: {$period->id})\n";
    echo "Start: " . date('Y-m-d', $period->startdate) . "\n";
    echo "End: " . date('Y-m-d', $period->enddate) . "\n";
    
    $classCount = $DB->count_records('gmk_class', ['periodid' => $period->id]);
    echo "Total Classes for this period: $classCount\n";
    
    if ($classCount > 0) {
        $classes = $DB->get_records('gmk_class', ['periodid' => $period->id], '', 'id, name', 0, 5);
        echo "Sample classes:\n";
        foreach ($classes as $c) {
            $sessCount = $DB->count_records('gmk_class_schedules', ['classid' => $c->id]);
            echo "- ID: {$c->id}, Name: {$c->name}, Schedules: $sessCount\n";
        }
    }
}
