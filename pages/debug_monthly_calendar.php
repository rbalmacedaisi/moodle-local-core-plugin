<?php
require_once(__DIR__ . '/../../../config.php');
global $DB, $PAGE, $OUTPUT;

$context = context_system::instance();
require_login();
require_capability('moodle/site:config', $context);

$PAGE->set_url('/local/grupomakro_core/pages/debug_monthly_calendar.php');
$PAGE->set_context($context);
$PAGE->set_title('Debug Monthly Calendar');
$PAGE->set_heading('Diagnostic for Monthly Calendar View');

echo $OUTPUT->header();

$period_name = '2026-I'; // Default target period reported by user
$period = $DB->get_record('gmk_academic_periods', ['name' => $period_name]);

$report = [];
$report['target_period'] = $period_name;

if (!$period) {
    echo $OUTPUT->notification("Period '$period_name' not found.", 'error');
    $periods = $DB->get_records('gmk_academic_periods', null, 'id DESC', 'id, name', 0, 10);
    echo "<h3>Recent Periods in DB:</h3><ul>";
    foreach ($periods as $p) {
        echo "<li>ID: {$p->id} | Name: {$p->name}</li>";
    }
    echo "</ul>";
} else {
    $report['period_id'] = $period->id;
    $report['start_date'] = date('Y-m-d', $period->startdate);
    $report['end_date'] = date('Y-m-d', $period->enddate);
    
    echo "<h2>Period: {$period->name} (ID: {$period->id})</h2>";
    echo "Range: " . $report['start_date'] . " to " . $report['end_date'] . "<br>";
    echo "ConfigSettings: <pre>" . ($period->configsettings ?: 'None') . "</pre><br>";
    $report['configsettings'] = $period->configsettings;

    // Fetch classes
    $classes = $DB->get_records('gmk_class', ['periodid' => $period->id]);
    $report['class_count'] = count($classes);
    echo "Total Classes for this period: " . $report['class_count'] . "<br>";

    if ($report['class_count'] > 0) {
        $classes_with_schedules = 0;
        $session_report = [];
        
        foreach ($classes as $c) {
            $sessions = $DB->get_records('gmk_class_schedules', ['classid' => $c->id]);
            if ($sessions) {
                $classes_with_schedules++;
                foreach ($sessions as $s) {
                    $session_report[] = [
                        'class_id' => $c->id,
                        'class_name' => $c->name,
                        'subperiodid' => $c->subperiodid,
                        'day' => $s->day,
                        'start' => $s->start_time,
                        'end' => $s->end_time,
                        'excluded' => $s->excluded_dates
                    ];
                }
            }
        }
        $report['classes_with_schedules'] = $classes_with_schedules;
        $report['total_sessions'] = count($session_report);
        
        echo "Classes with at least one schedule: $classes_with_schedules<br>";
        echo "Total sessions found: " . count($session_report) . "<br>";
        
        if (count($session_report) > 0) {
            echo "<h3>Sample Sessions (First 10):</h3><table border='1' cellpadding='5'>";
            echo "<tr><th>Class ID</th><th>Name</th><th>Rel. Period (subperiodid)</th><th>Day</th><th>Time</th></tr>";
            for ($i = 0; $i < min(10, count($session_report)); $i++) {
                $s = $session_report[$i];
                echo "<tr>
                        <td>{$s['class_id']}</td>
                        <td>{$s['class_name']}</td>
                        <td>{$s['subperiodid']}</td>
                        <td>{$s['day']}</td>
                        <td>{$s['start']} - {$s['end']}</td>
                      </tr>";
            }
            echo "</table>";
        } else {
            echo $OUTPUT->notification("No sessions found in gmk_class_schedules for these classes.", 'warning');
        }
    } else {
        echo $OUTPUT->notification("No classes found for this period in gmk_class.", 'warning');
    }
}

// Write JSON report to disk for agent to read
file_put_contents(__DIR__ . '/debug_calendar_output.json', json_encode($report, JSON_PRETTY_PRINT));
echo "<p>Detailed JSON report written to <code>local/grupomakro_core/pages/debug_calendar_output.json</code></p>";

echo $OUTPUT->footer();
