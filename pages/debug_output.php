<?php
require_once(__DIR__ . '/../../../config.php');
global $DB, $PAGE, $OUTPUT;

$context = context_system::instance();
require_login();
require_capability('moodle/site:config', $context);

$PAGE->set_url('/local/grupomakro_core/pages/debug_output.php');
$PAGE->set_context($context);
$PAGE->set_title('Debug Academic Planning');
$PAGE->set_heading('Debug Output');

echo $OUTPUT->header();

echo "<h3>Diagnosticando Base de Datos...</h3>";
echo "<pre>";

// 1. Count Total Users
$count_users = $DB->count_records('user', ['deleted' => 0, 'suspended' => 0]);
echo "Total Active Users: $count_users\n";

// 2. Count local_learning_users
$count_llu = $DB->count_records('local_learning_users');
echo "Total Records in local_learning_users: $count_llu\n";

// 3. Distinct Roles in local_learning_users
$roles = $DB->get_records_sql("SELECT DISTINCT userrolename FROM {local_learning_users}");
echo "Roles found in local_learning_users:\n";
foreach ($roles as $r) {
    echo " - '" . $r->userrolename . "'\n";
}

// 4. Count Students with 'student' role
$count_students = $DB->count_records('local_learning_users', ['userrolename' => 'student']);
echo "Students with role 'student': $count_students\n";

// 5. Count Students with ANY role joined with User table
$sql_join = "SELECT COUNT(u.id) 
             FROM {user} u 
             JOIN {local_learning_users} llu ON llu.userid = u.id 
             WHERE u.deleted=0 AND u.suspended=0";
echo "Active Users present in Learning Plans (Any Role): " . $DB->count_records_sql($sql_join) . "\n";

// 6. Check Curriculum (local_learning_courses)
$count_courses = $DB->count_records('local_learning_courses');
echo "Total Curriculum Entries (local_learning_courses): $count_courses\n";

// 7. Check Jornada Field
$jornada = $DB->get_record('user_info_field', ['shortname' => 'jornada']);
if ($jornada) {
    echo "Field 'jornada' FOUND (ID: $jornada->id).\n";
    $data_count = $DB->count_records('user_info_data', ['fieldid' => $jornada->id]);
    echo " - Users with 'jornada' data: $data_count\n";
} else {
    echo "Field 'jornada' NOT FOUND.\n";
}

// ... (previous diagnostics)

echo "<hr><h3>Simulando Ejecución Backend (planning::get_demand_analysis)</h3>";

// Include the class file directly
$classfile = __DIR__ . '/../classes/external/admin/planning.php';
if (file_exists($classfile)) {
    require_once($classfile);
    echo "Class file loaded.<br>";
    
    try {
        // Prepare parameters
        $periodid = 0;
        $filters = json_encode(['career' => '', 'jornada' => '', 'financial_status' => '']);
        
        echo "Calling function...<br>";
        
        // We need to suppress output buffering if external_api cleans it? 
        // external_api sometimes does weird things.
        // But usually safe for read functions.
        
        $result = \local_grupomakro_core\external\admin\planning::get_demand_analysis($periodid, $filters);
        
        echo "<b>Result Count (Plans):</b> " . count($result['demand']) . "<br>";
        
        if (empty($result['demand'])) {
            echo "<b>WARNING:</b> Demand array is empty.<br>";
        } else {
            echo "<pre>" . print_r($result['demand'], true) . "</pre>";
        }
        
    } catch (Exception $e) {
        echo "<div style='color:red; font-weight:bold'>EXCEPTION THROWN: " . $e->getMessage() . "</div>";
        echo "<pre>" . $e->getTraceAsString() . "</pre>";
    }
} else {
    echo "Error: Could not find planning.php at $classfile";
}

echo "</pre>";
echo $OUTPUT->footer();
