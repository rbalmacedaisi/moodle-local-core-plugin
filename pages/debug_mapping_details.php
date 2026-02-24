<?php
require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../locallib.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$classid = optional_param('class_id', 0, PARAM_INT);

echo "<style>
    body { font-family: sans-serif; padding: 20px; line-height: 1.6; }
    pre { background: #f4f4f4; padding: 10px; border: 1px solid #ddd; overflow: auto; max-height: 400px;}
    .class-item { padding: 10px; border-bottom: 1px solid #eee; }
    .class-item:hover { background: #f9f9f9; }
    .badge { padding: 3px 7px; border-radius: 4px; font-size: 0.8em; font-weight: bold; }
    .badge-approved { background: #d4edda; color: #155724; }
    .badge-pending { background: #fff3cd; color: #856404; }
</style>";

echo "<h1>Grupomakro Mapping Debugger</h1>";

if (!$classid) {
    echo "<h2>Select a Class to Debug</h2>";
    echo "<p>Showing the 30 most recent classes:</p>";
    
    $classes = $DB->get_records('gmk_class', null, 'id DESC', '*', 0, 30);
    
    if (!$classes) {
        echo "<p>No classes found in gmk_class table.</p>";
    } else {
        echo "<div style='max-width: 800px; border: 1px solid #ccc; border-radius: 8px;'>";
        foreach ($classes as $c) {
            $url = new moodle_url('/local/grupomakro_core/pages/debug_mapping_details.php', ['class_id' => $c->id]);
            $approved = $c->approved ? '<span class="badge badge-approved">Approved</span>' : '<span class="badge badge-pending">Draft</span>';
            echo "<div class='class-item'>";
            echo "<strong>ID: {$c->id}</strong> - <a href='{$url}'>{$c->name}</a> $approved<br>";
            echo "<small>Instructor ID: " . ($c->instructorid ?: 'None') . " | Course ID: {$c->courseid} | Plan: {$c->learningplanid}</small>";
            echo "</div>";
        }
        echo "</div>";
    }
} else {
    echo "<a href='debug_mapping_details.php'>&larr; Back to selection</a>";
    echo "<h2>Inspecting Class ID: $classid</h2>";

    // 1. Raw Class Record
    $class = $DB->get_record('gmk_class', ['id' => $classid]);
    if (!$class) {
        die("<div style='color:red'>Class not found in gmk_class table.</div>");
    }

    echo "<h3>1. Raw Data (DB: gmk_class)</h3>";
    echo "<pre>";
    print_r($class);
    echo "</pre>";

    // 2. Component Logic - Days
    echo "<h3>2. Logic Simulation: Days Parsing</h3>";
    $classDaysRaw = trim($class->classdays ?? '');
    echo "Raw string in DB: <code>'{$classDaysRaw}'</code><br>";
    $daysParts = explode('/', $classDaysRaw);
    $classDays = [
        'monday'    => isset($daysParts[0]) && $daysParts[0] === '1',
        'tuesday'   => isset($daysParts[1]) && $daysParts[1] === '1',
        'wednesday' => isset($daysParts[2]) && $daysParts[2] === '1',
        'thursday'  => isset($daysParts[3]) && $daysParts[3] === '1',
        'friday'    => isset($daysParts[4]) && $daysParts[4] === '1',
        'saturday'  => isset($daysParts[5]) && $daysParts[5] === '1',
        'sunday'    => isset($daysParts[6]) && $daysParts[6] === '1'
    ];
    echo "Boolean Map for Frontend:";
    echo "<pre>";
    print_r($classDays);
    echo "</pre>";

    // 3. Teacher Logic
    echo "<h3>3. Logic Simulation: Teacher Finder</h3>";
    $params = [
        'courseId' => $class->courseid,
        'initTime' => $class->inittime,
        'endTime' => $class->endtime,
        'classDays' => $class->classdays,
        'learningPlanId' => $class->learningplanid,
        'classId' => $classid
    ];
    $potentialTeachers = get_potential_class_teachers($params);

    echo "<p>Calling <code>get_potential_class_teachers()</code>...</p>";
    echo "<table border='1' cellpadding='10' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr style='background: #eee;'><th>ID (Normalizado)</th><th>Nombre</th><th>Email</th><th>¿Seleccionado?</th><th>Detalles</th></tr>";
    
    $seenIds = [];
    foreach ($potentialTeachers as $pt) {
        $ptId = (int)$pt->id;
        $isSelected = ($ptId === (int)$class->instructorid);
        $rowStyle = $isSelected ? "background: #e8f5e9; font-weight: bold;" : "";
        $isDuplicate = isset($seenIds[$ptId]) ? "<span style='color:red'> [DUPLICADO]</span>" : "";
        
        echo "<tr style='$rowStyle'>";
        echo "<td>$ptId</td>";
        echo "<td>{$pt->fullname} $isDuplicate</td>";
        echo "<td>{$pt->email}</td>";
        echo "<td>" . ($isSelected ? "YES (Matches InstructorID)" : "No") . "</td>";
        echo "<td><pre style='font-size: 0.8em; padding:5px; margin:0;'>" . print_r($pt, true) . "</pre></td>";
        echo "</tr>";
        
        $seenIds[$ptId] = true;
    }
    echo "</table>";

    // 4. Comparison Check
    echo "<h3>4. Comparison Diagnostics</h3>";
    $instructorIdInClass = (int)$class->instructorid;
    echo "Current <code>instructorid</code> stored in Class: <strong>$instructorIdInClass</strong> (Type: " . gettype($class->instructorid) . ")<br>";
    
    echo "<h4>Checking all entries for ID matches:</h4>";
    foreach ($potentialTeachers as $pt) {
        $ptIdInt = (int)$pt->id;
        $match = ($ptIdInt === $instructorIdInClass) ? "<span style='color:green'>MATCH!</span>" : "No match";
        echo "Checking Teacher ID {$pt->id}: (int){$ptIdInt} === (int){$instructorIdInClass}? &rarr; $match<br>";
    }
}
