<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/gradelib.php');

$classid = optional_param('classid', 0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);

echo "<h1>Debug Gradebook</h1>";

if ($classid) {
    $class = $DB->get_record('gmk_class', ['id' => $classid]);
    if ($class) {
        $courseid = $class->corecourseid;
        echo "<h2>Class: {$class->name} (ID: $classid)</h2>";
        echo "<p>Grade Category ID: {$class->gradecategoryid}</p>";
    }
}

if ($courseid) {
    echo "<h2>Course ID: $courseid</h2>";
    $target_cat = \grade_category::fetch_course_category($courseid);
    if ($classid && !empty($class->gradecategoryid)) {
        $class_cat = \grade_category::fetch(['id' => $class->gradecategoryid]);
        if ($class_cat) $target_cat = $class_cat;
    }
    
    echo "<h3>Category: {$target_cat->fullname} (ID: {$target_cat->id})</h3>";
    echo "<p>Aggregation Method: {$target_cat->aggregation} (" . ($target_cat->aggregation == 13 ? 'Natural' : 'Weighted Mean/Other') . ")</p>";

    $grade_items = \grade_item::fetch_all(['courseid' => $courseid]);
    
    echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
    echo "<tr>
            <th>ID</th>
            <th>Name</th>
            <th>Type</th>
            <th>Max</th>
            <th>Weight (coef)</th>
            <th>Weight (coef2)</th>
            <th>Override</th>
            <th>Locked</th>
            <th>Hidden</th>
          </tr>";

    foreach ($grade_items as $gi) {
        if ($gi->itemtype == 'course' || $gi->itemtype == 'category') continue;
        
        $row_style = "";
        if ($gi->itemname && strpos($gi->itemname, 'Nota Final Integrada') !== false) {
            $row_style = "style='background-color: #ffe0e0;'";
        }

        echo "<tr $row_style>";
        echo "<td>{$gi->id}</td>";
        echo "<td>" . ($gi->itemname ?: ($gi->itemtype . ' ' . $gi->itemmodule)) . "</td>";
        echo "<td>{$gi->itemtype}</td>";
        echo "<td>{$gi->grademax}</td>";
        echo "<td>{$gi->aggregationcoef}</td>";
        echo "<td>{$gi->aggregationcoef2}</td>";
        echo "<td>{$gi->weightoverride}</td>";
        echo "<td>{$gi->locked}</td>";
        echo "<td>{$gi->hidden}</td>";
        echo "</tr>";
    }
    echo "</table>";

    // Simulate the normalization logic
    echo "<h3>Simulated Normalization Logic</h3>";
    $items = [];
    foreach ($grade_items as $gi) {
        if ($gi->itemtype == 'course' || $gi->itemtype == 'category') continue;
        if ($gi->itemname && strpos($gi->itemname, 'Nota Final Integrada') !== false) continue;

        $weight = ($target_cat->aggregation == 13) ? (float)$gi->aggregationcoef2 : (float)$gi->aggregationcoef;
        $items[] = [
            'name' => $gi->itemname,
            'weight' => $weight,
            'override' => $gi->weightoverride,
            'grademax' => $gi->grademax
        ];
    }

    $sum_max = 0;
    $sum_weights = 0;
    foreach ($items as $it) {
        $sum_max += $it['grademax'];
        $sum_weights += $it['weight'];
    }

    echo "<p>Sum Max: $sum_max</p>";
    echo "<p>Sum Weights: $sum_weights</p>";

    if ($sum_weights <= 0 && $sum_max > 0) {
        echo "<p>Case: All weights zero. Distributing by max grade...</p>";
        foreach ($items as &$it) {
            $it['norm_weight'] = ($it['grademax'] / $sum_max) * 100;
        }
    } else if ($sum_weights > 0) {
        echo "<p>Case: Some weights set. Normalizing...</p>";
        $effective_sum = 0;
        foreach ($items as &$it) {
            if ($it['weight'] <= 0 && $it['override'] == 0) {
                $it['temp'] = 1.0;
            } else {
                $it['temp'] = $it['weight'];
            }
            $effective_sum += $it['temp'];
        }
        echo "<p>Effective Sum: $effective_sum</p>";
        foreach ($items as &$it) {
            $it['norm_weight'] = ($it['temp'] / $effective_sum) * 100;
        }
    }

    echo "<table border='1' cellpadding='5' style='border-collapse: collapse; margin-top: 10px;'>";
    echo "<tr><th>Name</th><th>Original Weight</th><th>Override</th><th>Normalized %</th></tr>";
    foreach ($items as $it) {
        echo "<tr>";
        echo "<td>{$it['name']}</td>";
        echo "<td>{$it['weight']}</td>";
        echo "<td>{$it['override']}</td>";
        echo "<td>" . number_format($it['norm_weight']??0, 2) . "%</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>Please provide classid or courseid in URL.</p>";
}
