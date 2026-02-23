<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_class_mapping.php'));
$PAGE->set_context($context);
$PAGE->set_title('Debug Class Mapping');
$PAGE->set_heading('Debug Class Mapping');

echo $OUTPUT->header();

$classid = optional_param('classid', 0, PARAM_INT);
$inspectdemand = optional_param('inspectdemand', 0, PARAM_INT);
$periodid = optional_param('periodid', 1, PARAM_INT); // Default to 2026-I usually

$periods = $DB->get_records('gmk_academic_periods');
echo "<form method='GET'>Period: <select name='periodid'>";
foreach ($periods as $p) {
    $sel = ($p->id == $periodid) ? 'selected' : '';
    echo "<option value='{$p->id}' $sel>{$p->name}</option>";
}
echo "</select> <input type='submit' value='Change Period'> <a href='?periodid=$periodid&inspectdemand=1'>Inspect Demand Data</a></form><br>";

if ($inspectdemand) {
    echo "<h2>Demand Data for Period ID: $periodid</h2>";
    try {
        $demandData = scheduler::get_demand_data($periodid);
        echo "<pre>" . json_encode($demandData, JSON_PRETTY_PRINT) . "</pre>";
    } catch (Exception $e) {
        echo "Error fetching demand: " . $e->getMessage();
    }
    echo $OUTPUT->footer();
    die();
}

echo "<h3>Recent Classes (gmk_class)</h3>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>Name</th><th>Course ID (Subject)</th><th>Period ID</th><th>Learning Plan ID</th><th>Action</th></tr>";
foreach ($classes as $c) {
    $style = ($c->id == $classid) ? "style='background-color: #ffffcc;'" : "";
    echo "<tr $style>";
    echo "<td>{$c->id}</td>";
    echo "<td>{$c->name}</td>";
    echo "<td>{$c->courseid}</td>";
    echo "<td>{$c->periodid}</td>";
    echo "<td>{$c->learningplanid}</td>";
    echo "<td><a href='?classid={$c->id}'>Inspect</a></td>";
    echo "</tr>";
}
echo "</table>";

if ($classid) {
    echo "<h2>Inspecting Class ID: $classid</h2>";
    
    $rawClass = $DB->get_record('gmk_class', ['id' => $classid]);
    echo "<h3>Raw Database Record (gmk_class)</h3>";
    echo "<pre>" . print_r($rawClass, true) . "</pre>";
    
    echo "<h3>Processed by list_classes()</h3>";
    $processedClasses = list_classes(['id' => $classid]);
    $pClass = $processedClasses[$classid];
    echo "<pre>" . print_r($pClass, true) . "</pre>";
    
    echo "<h3>Metadata Lookup (local_learning_courses)</h3>";
    if ($rawClass->courseid) {
        $meta = $DB->get_record('local_learning_courses', ['id' => $rawClass->courseid]);
        if ($meta) {
            echo "<p style='color: green;'>Found metadata!</p>";
            echo "<pre>" . print_r($meta, true) . "</pre>";
        } else {
            echo "<p style='color: red;'>Metadata NOT found for local_learning_courses.id = {$rawClass->courseid}</p>";
            
            // Try searching by name?
            $similar = $DB->get_records_select('local_learning_courses', "fullname LIKE ?", ["%{$rawClass->name}%"]);
            if ($similar) {
                echo "<p>Similar courses by name:</p>";
                echo "<pre>" . print_r($similar, true) . "</pre>";
            }
        }
    } else {
        echo "<p>No Course ID stored in class record.</p>";
    }
    
    echo "<h3>Learning Plan & Period Details</h3>";
    if (isset($pClass->learningplanid)) {
        $lp = $DB->get_record('local_learning_plans', ['id' => $pClass->learningplanid]);
        echo "Learning Plan: " . ($lp ? $lp->name : "NOT FOUND (ID: {$pClass->learningplanid})") . "<br>";
    }
    if (isset($pClass->periodid)) {
        // This could be Level or Institutional depending on what's stored
        $lvl = $DB->get_record('local_learning_periods', ['id' => $pClass->periodid]);
        echo "Academic Level (local_learning_periods): " . ($lvl ? $lvl->name : "NOT FOUND (ID: {$pClass->periodid})") . "<br>";
        
        $inst = $DB->get_record('gmk_academic_periods', ['id' => $pClass->periodid]);
        echo "Institutional Period (gmk_academic_periods): " . ($inst ? $inst->name : "NOT FOUND (ID: {$pClass->periodid})") . "<br>";
    }
    
    echo "<h3>System Search by Name: \"{$rawClass->name}\"</h3>";
    $matches = $DB->get_records_select('local_learning_courses', "fullname = ? OR idnumber = ?", [$rawClass->name, $rawClass->name]);
    if ($matches) {
        echo "<p style='color: blue;'>Exact matches found in local_learning_courses:</p>";
        foreach ($matches as $m) {
            echo "ID: {$m->id} | Name: {$m->fullname} | LP ID: {$m->learningplanid} | Period ID: {$m->periodid}<br>";
        }
    } else {
        echo "<p style='color: orange;'>No exact match by fullname or idnumber.</p>";
        $likeMatches = $DB->get_records_select('local_learning_courses', "fullname LIKE ?", ["%".substr($rawClass->name, 0, 10)."%"]);
        echo "<p>Partial matches (first 10 chars):</p>";
        foreach ($likeMatches as $m) {
            echo "ID: {$m->id} | Name: {$m->fullname}<br>";
        }
    }

echo $OUTPUT->footer();
