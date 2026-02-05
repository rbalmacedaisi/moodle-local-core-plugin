<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/gradelib.php');

$classid = optional_param('classid', 0, PARAM_INT);

require_login();
$systemcontext = context_system::instance();
require_capability('moodle/site:config', $systemcontext);

echo $OUTPUT->header();
echo "<h1>Diagnostic: Gradebook Save</h1>";

if (!$classid) {
    echo "<h3>Seleccionar Clase para Diagnóstico</h3>";
    
    $search = optional_param('search', '', PARAM_TEXT);
    echo "<form method='get'>
            Buscar por nombre: <input type='text' name='search' value='" . s($search) . "'> 
            <input type='submit' value='Buscar'>
          </form><br>";

    $where = "";
    $params = [];
    if ($search) {
        $where = "WHERE c.name LIKE :search OR co.fullname LIKE :search2";
        $params['search'] = "%$search%";
        $params['search2'] = "%$search%";
    }

    $recent_classes = $DB->get_records_sql("
        SELECT c.id, c.name, co.fullname as coursename, co.id as courseid
        FROM {gmk_class} c
        JOIN {course} co ON c.corecourseid = co.id
        $where
        ORDER BY c.id DESC
        LIMIT 20
    ", $params);

    echo "<ul>";
    foreach ($recent_classes as $rc) {
        echo "<li><a href='?classid={$rc->id}'>[ID: {$rc->id}] <b>{$rc->name}</b> - {$rc->coursename} (Course ID: {$rc->courseid})</a></li>";
    }
    echo "</ul>";

    echo "<hr><form method='get'>Ingresar Class ID manual: <input type='text' name='classid'> <input type='submit'></form>";
    echo $OUTPUT->footer();
    die();
}

$class = $DB->get_record('gmk_class', ['id' => $classid]);
if (!$class) {
    echo "Class not found.";
    echo $OUTPUT->footer();
    die();
}

$course = $DB->get_record('course', ['id' => $class->corecourseid]);
echo "<h2>Course: {$course->fullname} (ID: {$course->id})</h2>";

// Determine Category context
$target_cat = \grade_category::fetch_course_category($course->id);
if (!empty($class->gradecategoryid)) {
    $class_cat = \grade_category::fetch(['id' => $class->gradecategoryid]);
    if ($class_cat) {
        $target_cat = $class_cat;
        echo "<p>Using Class Category: {$target_cat->fullname}</p>";
    }
} else {
    echo "<p>Using Course Default Category</p>";
}

$aggregation = $target_cat->aggregation;
$is_natural = ($aggregation == 13);
echo "<p>Aggregation Method: <b>$aggregation</b> (" . ($is_natural ? "NATURAL" : "OTHER") . ")</p>";

// Fetch items
$grade_items = \grade_item::fetch_all(['courseid' => $course->id]);

if (optional_param('run_test', 0, PARAM_BOOL)) {
    echo "<h3>System Update Test</h3>";
    $test_item_id = required_param('test_item_id', PARAM_INT);
    $new_weight = required_param('new_weight', PARAM_FLOAT);
    
    $gi = \grade_item::fetch(['id' => $test_item_id]);
    if ($gi) {
        echo "Updating Item: {$gi->itemname} (ID: $test_item_id)<br>";
        echo "Current Coef1: {$gi->aggregationcoef}, Coef2: {$gi->aggregationcoef2}, Override: {$gi->weightoverride}<br>";
        
        if ($is_natural) {
            $gi->aggregationcoef2 = $new_weight;
            $gi->weightoverride = 1;
            echo "Setting Coef2 = $new_weight, Override = 1<br>";
        } else {
            $gi->aggregationcoef = $new_weight;
            echo "Setting Coef1 = $new_weight<br>";
        }
        
        $res = $gi->update();
        echo "Update Result: " . ($res ? "TRUE" : "FALSE (or no changes detected)") . "<br>";
        
        // Regrade
        echo "Triggering Regrade...<br>";
        \grade_regrade_final_grades($course->id);
        echo "Done.<br>";
        
        // Refetch to verify
        $verify = \grade_item::fetch(['id' => $test_item_id]);
        echo "Verified Coef1: {$verify->aggregationcoef}, Coef2: {$verify->aggregationcoef2}, Override: {$verify->weightoverride}<br>";
    }
}

echo "<h3>Current Items</h3>";
echo "<table border='1' cellpadding='5'>";
echo "<tr><th>ID</th><th>Name</th><th>Type</th><th>Coef 1 (Weighted)</th><th>Coef 2 (Natural)</th><th>Override</th><th>Grademax</th><th>Test Update</th></tr>";

foreach ($grade_items as $gi) {
    if ($gi->itemtype == 'course' || $gi->itemtype == 'category') continue;
    
    echo "<tr>";
    echo "<td>{$gi->id}</td>";
    echo "<td>{$gi->itemname}</td>";
    echo "<td>{$gi->itemtype}</td>";
    echo "<td>{$gi->aggregationcoef}</td>";
    echo "<td>{$gi->aggregationcoef2}</td>";
    echo "<td>{$gi->weightoverride}</td>";
    echo "<td>{$gi->grademax}</td>";
    echo "<td>
            <form method='post' action='?classid=$classid' style='display:inline'>
                <input type='hidden' name='run_test' value='1'>
                <input type='hidden' name='test_item_id' value='{$gi->id}'>
                <input type='text' name='new_weight' size='5'>
                <input type='submit' value='Apply'>
            </form>
          </td>";
    echo "</tr>";
}
echo "</table>";

echo $OUTPUT->footer();
