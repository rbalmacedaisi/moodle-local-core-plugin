<?php
// Simple file download - no Moodle overhead
require_once(__DIR__ . '/../../../config.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

global $DB;

// Get all users without roles
$sql = "SELECT u.id, u.username, u.firstname, u.lastname, u.email, u.idnumber, u.timecreated
        FROM {user} u
        LEFT JOIN {role_assignments} ra ON ra.userid = u.id
        WHERE u.deleted = 0
        AND ra.id IS NULL
        ORDER BY u.timecreated DESC";

$users_no_roles = $DB->get_records_sql($sql);

// Get all learning plans
$plans = $DB->get_records('local_learning_plans', null, 'name ASC', 'id, name');
$plan_names = array_map(function($p) { return $p->name; }, $plans);

// Clean all buffers
while (ob_get_level()) {
    ob_end_clean();
}

$filename = 'plantilla_reparacion_estudiantes_' . date('Y-m-d_His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM

// Headers
fputcsv($output, ['Username (Cédula)', 'Nombre Completo', 'Email', 'ID Number (Expediente)', 'Plan de Aprendizaje']);

// Data rows
foreach ($users_no_roles as $user) {
    fputcsv($output, [
        $user->username,
        $user->firstname . ' ' . $user->lastname,
        $user->email,
        $user->idnumber,
        '' // Empty for user to fill
    ]);
}

// Add instructions
fputcsv($output, []);
fputcsv($output, ['INSTRUCCIONES:']);
fputcsv($output, ['1. Complete la columna "Plan de Aprendizaje" con el nombre EXACTO del plan']);
fputcsv($output, ['2. NO modifique las otras columnas']);
fputcsv($output, ['3. Planes disponibles: ' . implode(', ', $plan_names)]);

fclose($output);
exit;
