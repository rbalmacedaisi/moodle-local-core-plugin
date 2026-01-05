<?php
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/external/admin/planning.php');

global $DB;

echo "--- Debugging Demand Analysis ---\n";

// 1. Check User Info Field 'jornada'
$jornadaField = $DB->get_record('user_info_field', ['shortname' => 'jornada']);
echo "Field 'jornada': " . ($jornadaField ? "Found (id: {$jornadaField->id})" : "NOT FOUND") . "\n";

// 2. Count Active Students in Learning Plans
$sql = "SELECT COUNT(u.id) 
        FROM {user} u
        JOIN {local_learning_users} llu ON llu.userid = u.id
        WHERE u.deleted = 0 AND u.suspended = 0 AND llu.userrolename = 'student'";
$count = $DB->count_records_sql($sql);
echo "Active Students with Learning Plan: $count\n";

if ($count == 0) {
    echo "(!) No active students found in local_learning_users with role 'student'.\n";
    // Check without 'student' role restriction?
     $sql2 = "SELECT COUNT(u.id) FROM {user} u JOIN {local_learning_users} llu ON llu.userid = u.id WHERE u.deleted=0";
     echo "  - Total in local_learning_users (any role): " . $DB->count_records_sql($sql2) . "\n";
}

// 3. Check Curricula
$plan_courses_count = $DB->count_records('local_learning_courses');
echo "Total Curriculum Entries (courses assigned to plans): $plan_courses_count\n";

// 4. Run the actual logic query (simplified)
$jornadaJoin = "";
$jornadaSelect = "";
if ($jornadaField) {
    $jornadaJoin = "LEFT JOIN {user_info_data} uid_j ON uid_j.userid = u.id AND uid_j.fieldid = " . $jornadaField->id;
    $jornadaSelect = ", uid_j.data AS jornada";
}

$full_sql = "SELECT u.id, u.firstname, u.lastname, lp.name as planname, llu.currentperiodid $jornadaSelect
        FROM {user} u
        JOIN {local_learning_users} llu ON llu.userid = u.id
        JOIN {local_learning_plans} lp ON lp.id = llu.learningplanid
        $jornadaJoin
        WHERE u.deleted = 0 AND u.suspended = 0 AND llu.userrolename = 'student'";

$students = $DB->get_records_sql($full_sql, [], 0, 5); // Get first 5
echo "Sample Students:\n";
foreach ($students as $stu) {
    echo " - ID: {$stu->id}, Name: {$stu->firstname}, Plan: {$stu->planname}, Jornada: " . ($stu->jornada ?? 'NULL') . "\n";
}

// 5. Test Filters logic (simulate Controller)
$filters = ['career' => '', 'jornada' => '', 'financial_status' => ''];
echo "\n--- Simulation ---\n";
// Call the class method directly if possible, or just raw logic.
// Let's call the actual function to see what it returns.
try {
    $result = \local_grupomakro_core\external\admin\planning::get_demand_analysis(0, json_encode($filters));
    $demand = $result['demand'];
    $total_plans = count($demand);
    echo "Function returned demand for $total_plans plans.\n";
    if ($total_plans > 0) {
        foreach($demand as $pid => $data) {
            echo " Plan: {$data['name']}\n";
            foreach($data['jornadas'] as $jname => $periods) {
                echo "   Jornada: '$jname' -> " . count($periods) . " periods with pending subjects.\n";
            }
        }
    }
} catch (Exception $e) {
    echo "ERROR calling function: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
