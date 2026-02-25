<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/vendor/autoload.php');

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;

global $DB;

// Get parameters BEFORE any page setup
$action = optional_param('action', '', PARAM_ALPHA);
$userid = optional_param('userid', 0, PARAM_INT);
$planid = optional_param('planid', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_INT);

// ========== BULK UPLOAD ACTION (BEFORE ANY OUTPUT) ==========
if ($action === 'bulk_upload' && isset($_FILES['uploadfile'])) {
    $uploadfile = $_FILES['uploadfile'];

    if ($uploadfile['error'] === UPLOAD_ERR_OK) {
        $tmpfile = $uploadfile['tmp_name'];

        try {
            $results = [
                'success' => [],
                'errors' => [],
                'skipped' => []
            ];

            // Get all plans for name matching
            $plans = $DB->get_records('local_learning_plans', null, '', 'id, name');
            $plan_map = [];
            foreach ($plans as $p) {
                $plan_map[strtolower(trim($p->name))] = $p->id;
            }

            // Detect file type and read accordingly
            $file_extension = strtolower(pathinfo($uploadfile['name'], PATHINFO_EXTENSION));
            $data_rows = [];

            if ($file_extension === 'xlsx') {
                // Read Excel file
                $spreadsheet = IOFactory::load($tmpfile);
                $sheet = $spreadsheet->getActiveSheet();
                $data_rows = $sheet->toArray(null, true, true, true);

                // Remove header row
                array_shift($data_rows);
            } else {
                // Read CSV file
                $fp = fopen($tmpfile, 'r');
                while (($data = fgetcsv($fp)) !== false) {
                    $data_rows[] = $data;
                }
                fclose($fp);

                // Remove header row
                array_shift($data_rows);
            }

            $row_num = 1; // Start from 1 (after header)
            foreach ($data_rows as $data) {
                $row_num++;

                // For Excel, convert from associative array (A, B, C...) to indexed
                if ($file_extension === 'xlsx') {
                    $data = array_values($data);
                }

                // Stop at instructions section
                if (empty($data[0]) || $data[0] === 'INSTRUCCIONES:') {
                    break;
                }

                $username = trim($data[0]);
                $plan_name = isset($data[4]) ? trim($data[4]) : '';

                if (empty($username)) {
                    $results['skipped'][] = "Fila $row_num: Username vacío";
                    continue;
                }

                if (empty($plan_name)) {
                    $results['errors'][] = "Fila $row_num ($username): Plan de aprendizaje vacío";
                    continue;
                }

                // Find user
                $user = $DB->get_record('user', ['username' => $username, 'deleted' => 0]);
                if (!$user) {
                    $results['errors'][] = "Fila $row_num ($username): Usuario no encontrado";
                    continue;
                }

                // Find plan
                $plan_name_lower = strtolower(trim($plan_name));
                if (!isset($plan_map[$plan_name_lower])) {
                    $results['errors'][] = "Fila $row_num ($username): Plan '$plan_name' no encontrado";
                    continue;
                }
                $plan_id = $plan_map[$plan_name_lower];

                // Step 1: Assign student role
                $student_role = $DB->get_record('role', ['shortname' => 'student']);
                if ($student_role) {
                    $context = context_system::instance();
                    $has_role = $DB->record_exists('role_assignments', ['userid' => $user->id, 'roleid' => $student_role->id]);

                    if (!$has_role) {
                        try {
                            role_assign($student_role->id, $user->id, $context->id);
                        } catch (Exception $e) {
                            $results['errors'][] = "Fila $row_num ($username): Error asignando rol - " . $e->getMessage();
                            continue;
                        }
                    }
                }

                // Step 2: Create local_learning_users record if missing
                $llu = $DB->get_record('local_learning_users', ['userid' => $user->id]);
                if (!$llu) {
                    $record = new stdClass();
                    $record->userid = $user->id;
                    $record->learningplanid = $plan_id;
                    $record->currentperiodid = 1;
                    $record->timecreated = time();
                    $record->timemodified = time();
                    $record->usermodified = $USER->id;

                    try {
                        $DB->insert_record('local_learning_users', $record);
                        $results['success'][] = "Fila $row_num ($username): Reparado exitosamente con plan '$plan_name'";
                    } catch (Exception $e) {
                        $results['errors'][] = "Fila $row_num ($username): Error creando registro - " . $e->getMessage();
                    }
                } else {
                    $results['skipped'][] = "Fila $row_num ($username): Ya tiene registro en local_learning_users";
                }
            }

            // Store results for display
            $_SESSION['bulk_upload_results'] = $results;
            redirect(new moodle_url('/local/grupomakro_core/pages/fix_student_setup.php', ['action' => 'show_results']));

        } catch (Exception $e) {
            $_SESSION['bulk_upload_error'] = $e->getMessage();
            redirect(new moodle_url('/local/grupomakro_core/pages/fix_student_setup.php'));
        }
    } else {
        $_SESSION['bulk_upload_error'] = 'Error al subir archivo';
        redirect(new moodle_url('/local/grupomakro_core/pages/fix_student_setup.php'));
    }
}

// ========== NOW SETUP PAGE (AFTER FILE OPERATIONS) ==========
admin_externalpage_setup('grupomakro_core_manage_courses');

echo $OUTPUT->header();

echo "<style>
    table { border-collapse: collapse; width: 100%; margin: 20px 0; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background-color: #f2f2f2; font-weight: bold; }
    .warning { background-color: #fff3cd; }
    .error { background-color: #f8d7da; }
    .success { background-color: #d4edda; }
    .info { background-color: #d1ecf1; }
    .section { margin: 30px 0; padding: 20px; border: 2px solid #ccc; border-radius: 5px; }
    pre { background-color: #f4f4f4; padding: 10px; border-radius: 5px; overflow-x: auto; }
    .btn { padding: 10px 20px; margin: 5px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; font-size: 14px; }
    .btn:hover { opacity: 0.9; text-decoration: none; }
    .btn-primary { background-color: #007bff; color: white; }
    .btn-success { background-color: #28a745; color: white; }
    .btn-danger { background-color: #dc3545; color: white; }
    .btn-warning { background-color: #ffc107; color: black; }
    .stat-box { display: inline-block; padding: 15px 25px; margin: 10px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #007bff; }
    .stat-number { font-size: 32px; font-weight: bold; color: #007bff; }
    .stat-label { font-size: 14px; color: #666; margin-top: 5px; }
</style>";

echo "<h1>🔧 Reparar Configuración de Estudiantes</h1>";

// ========== SHOW BULK UPLOAD RESULTS ==========
if ($action === 'show_results' && isset($_SESSION['bulk_upload_results'])) {
    $results = $_SESSION['bulk_upload_results'];
    unset($_SESSION['bulk_upload_results']);

    echo "<div class='section'>";
    echo "<h2>📊 Resultados de Carga Masiva</h2>";

    if (!empty($results['success'])) {
        echo "<div class='success' style='padding: 15px; margin: 10px 0; border-radius: 5px;'>";
        echo "<h3>✅ Procesados Exitosamente (" . count($results['success']) . ")</h3>";
        echo "<ul>";
        foreach ($results['success'] as $msg) {
            echo "<li>$msg</li>";
        }
        echo "</ul></div>";
    }

    if (!empty($results['errors'])) {
        echo "<div class='error' style='padding: 15px; margin: 10px 0; border-radius: 5px;'>";
        echo "<h3>❌ Errores (" . count($results['errors']) . ")</h3>";
        echo "<ul>";
        foreach ($results['errors'] as $msg) {
            echo "<li>$msg</li>";
        }
        echo "</ul></div>";
    }

    if (!empty($results['skipped'])) {
        echo "<div class='warning' style='padding: 15px; margin: 10px 0; border-radius: 5px;'>";
        echo "<h3>⚠️ Omitidos (" . count($results['skipped']) . ")</h3>";
        echo "<ul>";
        foreach ($results['skipped'] as $msg) {
            echo "<li>$msg</li>";
        }
        echo "</ul></div>";
    }

    echo "<a href='?' class='btn btn-primary'>Volver</a>";
    echo "</div>";
}

// ========== SHOW BULK UPLOAD ERROR ==========
if (isset($_SESSION['bulk_upload_error'])) {
    echo "<div class='error' style='padding: 15px; margin: 10px 0; border-radius: 5px;'>";
    echo "❌ Error al procesar archivo: " . $_SESSION['bulk_upload_error'];
    echo "</div>";
    unset($_SESSION['bulk_upload_error']);
}

// ========== BULK UPLOAD FORM ==========
echo "<div class='section info'>";
echo "<h2>📤 Carga Masiva desde Excel</h2>";
echo "<p>Descarga la plantilla Excel con los usuarios sin roles, completa la columna 'Plan de Aprendizaje' y sube el archivo para procesamiento masivo.</p>";

echo "<div style='margin: 20px 0;'>";
echo "<a href='download_fix_template.php' class='btn btn-success' style='font-size: 16px; padding: 15px 30px;'>";
echo "⬇️ Descargar Plantilla Excel";
echo "</a>";
echo "</div>";


echo "<form method='post' enctype='multipart/form-data' action='?action=bulk_upload' style='margin-top: 20px;'>";
echo "<div style='background: white; padding: 20px; border-radius: 5px; border: 2px dashed #ccc;'>";
echo "<h3>Subir Archivo Completado</h3>";
echo "<input type='file' name='uploadfile' accept='.xlsx,.csv' required style='padding: 10px; font-size: 14px; margin: 10px 0;'>";
echo "<br>";
echo "<input type='submit' value='⬆️ Procesar Archivo' class='btn btn-primary' style='font-size: 16px; padding: 10px 30px; margin-top: 10px;'>";
echo "</div>";
echo "</form>";

echo "<div class='warning' style='margin-top: 20px; padding: 15px;'>";
echo "<strong>⚠️ Importante:</strong><ul>";
echo "<li>La plantilla contiene SOLO los usuarios sin roles</li>";
echo "<li>Debes completar la columna 'Plan de Aprendizaje' con el nombre EXACTO del plan</li>";
echo "<li>NO modifiques las columnas de Username, Nombre, Email o ID Number</li>";
echo "<li>El sistema asignará automáticamente el rol de estudiante y creará el registro en local_learning_users</li>";
echo "<li>Puedes editar el archivo en Excel, guardar y subirlo (acepta .xlsx o .csv)</li>";
echo "</ul></div>";

echo "</div>";

// ========== HANDLE ACTIONS ==========
if ($action === 'fix_single' && $userid > 0 && $confirm === 1) {
    echo "<div class='section info'>";
    echo "<h2>🔧 Reparando Usuario ID: $userid</h2>";

    $user = $DB->get_record('user', ['id' => $userid], '*');
    if (!$user) {
        echo "<div class='error' style='padding: 10px;'>❌ Usuario no encontrado</div>";
    } else {
        $success_steps = [];
        $error_steps = [];

        // Step 1: Assign student role
        $student_role = $DB->get_record('role', ['shortname' => 'student']);
        if ($student_role) {
            $context = context_system::instance();
            $has_role = $DB->record_exists('role_assignments', ['userid' => $userid, 'roleid' => $student_role->id]);

            if (!$has_role) {
                try {
                    role_assign($student_role->id, $userid, $context->id);
                    $success_steps[] = "✅ Rol de estudiante asignado";
                } catch (Exception $e) {
                    $error_steps[] = "❌ Error asignando rol: " . $e->getMessage();
                }
            } else {
                $success_steps[] = "✅ Ya tiene rol de estudiante";
            }
        }

        // Step 2: Create local_learning_users record if missing
        $llu = $DB->get_record('local_learning_users', ['userid' => $userid]);
        if (!$llu && $planid > 0) {
            $plan = $DB->get_record('local_learning_plans', ['id' => $planid]);
            if ($plan) {
                $record = new stdClass();
                $record->userid = $userid;
                $record->learningplanid = $planid;
                $record->currentperiodid = 1; // Default to period 1, adjust if needed
                $record->timecreated = time();
                $record->timemodified = time();
                $record->usermodified = $USER->id;

                try {
                    $DB->insert_record('local_learning_users', $record);
                    $success_steps[] = "✅ Registro en local_learning_users creado con plan: {$plan->name}";
                } catch (Exception $e) {
                    $error_steps[] = "❌ Error creando registro: " . $e->getMessage();
                }
            } else {
                $error_steps[] = "❌ Plan de aprendizaje no encontrado (ID: $planid)";
            }
        } elseif ($llu) {
            $success_steps[] = "✅ Ya tiene registro en local_learning_users";
        } else {
            $error_steps[] = "⚠️ No se especificó plan de aprendizaje";
        }

        // Show results
        if (!empty($success_steps)) {
            echo "<div class='success' style='padding: 15px; margin: 10px 0;'>";
            echo "<strong>Pasos completados:</strong><ul>";
            foreach ($success_steps as $step) {
                echo "<li>$step</li>";
            }
            echo "</ul></div>";
        }

        if (!empty($error_steps)) {
            echo "<div class='error' style='padding: 15px; margin: 10px 0;'>";
            echo "<strong>Errores encontrados:</strong><ul>";
            foreach ($error_steps as $step) {
                echo "<li>$step</li>";
            }
            echo "</ul></div>";
        }

        echo "<a href='debug_student_visibility.php?search={$user->username}' class='btn btn-primary'>Ver Resultado</a> ";
        echo "<a href='?' class='btn btn-primary'>Volver</a>";
    }

    echo "</div>";
}

// ========== IDENTIFY PROBLEMATIC USERS ==========
echo "<div class='section'>";
echo "<h2>📊 Usuarios con Problemas</h2>";

// Find users without roles
$sql = "SELECT u.id, u.username, u.firstname, u.lastname, u.email, u.idnumber, u.timecreated
        FROM {user} u
        LEFT JOIN {role_assignments} ra ON ra.userid = u.id
        WHERE u.deleted = 0
        AND ra.id IS NULL
        ORDER BY u.timecreated DESC";

$users_no_roles = $DB->get_records_sql($sql);

// Find users without local_learning_users
$sql = "SELECT u.id, u.username, u.firstname, u.lastname, u.email, u.idnumber, u.timecreated
        FROM {user} u
        LEFT JOIN {local_learning_users} llu ON llu.userid = u.id
        WHERE u.deleted = 0
        AND u.id != 1
        AND llu.id IS NULL
        ORDER BY u.timecreated DESC";

$users_no_llu = $DB->get_records_sql($sql);

// Users with wrong idnumber - NOT NEEDED, both are correct
$users_wrong_idnumber = [];

echo "<div>";
echo "<div class='stat-box' style='border-left-color: #dc3545;'>";
echo "<div class='stat-number' style='color: #dc3545;'>" . count($users_no_roles) . "</div>";
echo "<div class='stat-label'>Sin Roles Asignados</div>";
echo "</div>";

echo "<div class='stat-box' style='border-left-color: #dc3545;'>";
echo "<div class='stat-number' style='color: #dc3545;'>" . count($users_no_llu) . "</div>";
echo "<div class='stat-label'>Sin local_learning_users</div>";
echo "</div>";

echo "</div>";

echo "</div>";

// ========== USERS WITHOUT ROLES ==========
if (!empty($users_no_roles)) {
    echo "<div class='section error'>";
    echo "<h2>❌ Usuarios Sin Roles Asignados (" . count($users_no_roles) . ")</h2>";
    echo "<p>Estos usuarios no tienen ningún rol asignado, por lo que no aparecen en el sistema.</p>";

    echo "<table>";
    echo "<tr><th>ID</th><th>Username</th><th>Nombre</th><th>Email</th><th>ID Number</th><th>Creado</th><th>Acciones</th></tr>";

    $plans = $DB->get_records('local_learning_plans', null, 'name ASC');

    foreach ($users_no_roles as $user) {
        $created = date('Y-m-d', $user->timecreated);
        echo "<tr>";
        echo "<td>{$user->id}</td>";
        echo "<td>{$user->username}</td>";
        echo "<td>{$user->firstname} {$user->lastname}</td>";
        echo "<td>{$user->email}</td>";
        echo "<td>{$user->idnumber}</td>";
        echo "<td>$created</td>";
        echo "<td>";
        echo "<select id='plan_{$user->id}' style='padding: 5px;'>";
        echo "<option value=''>Plan...</option>";
        foreach ($plans as $plan) {
            echo "<option value='{$plan->id}'>{$plan->name}</option>";
        }
        echo "</select> ";
        echo "<button onclick='fixUser({$user->id})' class='btn btn-success' style='padding: 5px 10px;'>Reparar</button>";
        echo "</td>";
        echo "</tr>";
    }

    echo "</table>";

    echo "<script>
    function fixUser(userid) {
        const planid = document.getElementById('plan_' + userid).value;
        if (!planid) {
            alert('Por favor selecciona un plan de aprendizaje');
            return;
        }
        if (confirm('¿Confirmas reparar este usuario?\\n\\nSe realizará:\\n- Asignar rol de estudiante\\n- Crear registro en local_learning_users\\n- Corregir ID Number si es necesario')) {
            window.location.href = '?action=fix_single&userid=' + userid + '&planid=' + planid + '&confirm=1';
        }
    }
    </script>";

    echo "</div>";
}

// ========== USERS WITHOUT LOCAL_LEARNING_USERS ==========
if (!empty($users_no_llu)) {
    $users_only_llu = array_filter($users_no_llu, function($u) use ($users_no_roles) {
        foreach ($users_no_roles as $ur) {
            if ($ur->id === $u->id) return false;
        }
        return true;
    });

    if (!empty($users_only_llu)) {
        echo "<div class='section warning'>";
        echo "<h2>⚠️ Usuarios Sin local_learning_users (pero con roles) (" . count($users_only_llu) . ")</h2>";
        echo "<p>Estos usuarios tienen roles pero no están en local_learning_users.</p>";

        echo "<table>";
        echo "<tr><th>ID</th><th>Username</th><th>Nombre</th><th>Email</th><th>Acciones</th></tr>";

        foreach (array_slice($users_only_llu, 0, 20) as $user) {
            echo "<tr>";
            echo "<td>{$user->id}</td>";
            echo "<td>{$user->username}</td>";
            echo "<td>{$user->firstname} {$user->lastname}</td>";
            echo "<td>{$user->email}</td>";
            echo "<td><a href='debug_student_visibility.php?search={$user->username}' class='btn btn-primary' style='padding: 5px 10px;'>Ver Detalles</a></td>";
            echo "</tr>";
        }

        echo "</table>";

        if (count($users_only_llu) > 20) {
            echo "<p><em>Mostrando primeros 20 de " . count($users_only_llu) . " usuarios</em></p>";
        }

        echo "</div>";
    }
}


echo $OUTPUT->footer();
