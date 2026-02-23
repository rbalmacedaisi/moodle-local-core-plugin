<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/../locallib.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_class_metadata.php'));
$PAGE->set_title("Debug Class Metadata Mapping");

$classid = optional_param('class_id', null, PARAM_INT);
$periodid = optional_param('period_id', null, PARAM_INT);
$search_subject = optional_param('search_subject', 'acuerdos', PARAM_TEXT);

echo $OUTPUT->header();
echo "<h1>Debug Class Metadata Mapping</h1>";

global $DB;

// Selection Form
echo "<div style='background: #f4f4f4; padding: 15px; border-radius: 8px; margin-bottom: 20px;'>";
echo "<form method='get' id='debug-form'>";

// Period Selector
$periods = $DB->get_records_menu('gmk_academic_periods', [], 'id DESC', 'id, name');
echo "<strong>1. Seleccionar Periodo Institucional:</strong><br>";
echo "<select name='period_id' onchange='document.getElementById(\"debug-form\").submit()'>";
echo "<option value=''>-- Seleccionar Periodo --</option>";
foreach ($periods as $pid => $pname) {
    $sel = ($periodid == $pid) ? 'selected' : '';
    echo "<option value='$pid' $sel>$pname</option>";
}
echo "</select><br><br>";

// Class Selector (filtered by period if selected)
if ($periodid) {
    $classes_list = $DB->get_records_menu('gmk_class', ['periodid' => $periodid], 'id DESC', 'id, name');
    if ($classes_list) {
        echo "<strong>2. Seleccionar Clase:</strong><br>";
        echo "<select name='class_id' onchange='document.getElementById(\"debug-form\").submit()'>";
        echo "<option value=''>-- Seleccionar Clase --</option>";
        foreach ($classes_list as $cid => $cname) {
            $sel = ($classid == $cid) ? 'selected' : '';
            echo "<option value='$cid' $sel>[$cid] $cname</option>";
        }
        echo "</select>";
    } else {
        echo "<em>No se encontraron clases en este periodo.</em>";
    }
} else {
    echo "<em>Selecciona un periodo para ver las clases.</em>";
}
echo "</form>";

// Search Subject Form
echo "<hr><form method='get'>";
echo "<strong>Buscar Materia por Nombre:</strong><br>";
echo "<input type='text' name='search_subject' value='" . s($search_subject) . "'>";
echo "<input type='submit' value='Buscar Subject ID'>";
echo "</form>";
echo "</div>";

if ($search_subject) {
    echo "<h2>Resultados de búsqueda para: '$search_subject'</h2>";
    $sql = "SELECT lpc.id as subjectid, lpc.courseid as moodleid, lp.name as planname, p.name as levelname, c.fullname
            FROM {local_learning_courses} lpc
            JOIN {local_learning_plans} lp ON lp.id = lpc.learningplanid
            JOIN {local_learning_periods} p ON p.id = lpc.periodid
            JOIN {course} c ON c.id = lpc.courseid
            WHERE c.fullname LIKE :search OR c.shortname LIKE :search2";
    $matches = $DB->get_records_sql($sql, ['search' => "%$search_subject%", 'search2' => "%$search_subject%"]);
    if ($matches) {
        echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>
                <tr><th>Subject ID</th><th>Moodle ID</th><th>Carrera</th><th>Nivel</th><th>Nombre Moodle</th></tr>";
        foreach ($matches as $m) {
            echo "<tr><td>{$m->subjectid}</td><td>{$m->moodleid}</td><td>{$m->planname}</td><td>{$m->levelname}</td><td>{$m->fullname}</td></tr>";
        }
        echo "</table>";
    } else {
        echo "No se encontraron materias.";
    }
}

if ($classid) {
    echo "<h2>Data for Class ID: $classid</h2>";
    
    // 1. Raw gmk_class record
    $class_raw = $DB->get_record('gmk_class', ['id' => $classid]);
    echo "<h3>1. Raw gmk_class record</h3>";
    echo "<pre>" . print_r($class_raw, true) . "</pre>";
    
    // 2. local_learning_courses record (Subject)
    if ($class_raw && !empty($class_raw->courseid)) {
        $subject = $DB->get_record('local_learning_courses', ['id' => $class_raw->courseid]);
        echo "<h3>2. local_learning_courses record (Subject ID: {$class_raw->courseid})</h3>";
        echo "<pre>" . print_r($subject, true) . "</pre>";
        
        if ($subject && !empty($subject->courseid)) {
            $core_course = $DB->get_record('course', ['id' => $subject->courseid]);
            echo "<h3>3. Moodle Core Course (ID: {$subject->courseid})</h3>";
            echo "<pre>" . print_r($core_course, true) . "</pre>";
        }
    }
    
    // 3. Result of list_classes
    $list_classes_result = list_classes(['id' => $classid]);
    echo "<h3>4. Result of list_classes(['id' => $classid])</h3>";
    echo "<pre>" . print_r($list_classes_result[$classid], true) . "</pre>";
    
    // 4. Learning Plan and Period info
    if ($class_raw && !empty($class_raw->learningplanid)) {
        $lp = $DB->get_record('local_learning_plans', ['id' => $class_raw->learningplanid]);
        echo "<h3>5. Learning Plan (ID: {$class_raw->learningplanid})</h3>";
        echo "<pre>" . print_r($lp, true) . "</pre>";
    }
    
    if ($class_raw && !empty($class_raw->periodid)) {
        $period = $DB->get_record('local_learning_periods', ['id' => $class_raw->periodid]);
        echo "<h3>6. Period / Level (ID: {$class_raw->periodid})</h3>";
        echo "<pre>" . print_r($period, true) . "</pre>";
    }
}

echo $OUTPUT->footer();
