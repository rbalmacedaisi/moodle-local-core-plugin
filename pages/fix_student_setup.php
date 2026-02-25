<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('grupomakro_core_manage_courses');

echo $OUTPUT->header();
global $DB;

$action = optional_param('action', '', PARAM_ALPHA);
$userid = optional_param('userid', 0, PARAM_INT);
$planid = optional_param('planid', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_INT);

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
    .btn-primary { background-color: #007bff; color: white; }
    .btn-success { background-color: #28a745; color: white; }
    .btn-danger { background-color: #dc3545; color: white; }
    .btn-warning { background-color: #ffc107; color: black; }
    .stat-box { display: inline-block; padding: 15px 25px; margin: 10px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #007bff; }
    .stat-number { font-size: 32px; font-weight: bold; color: #007bff; }
    .stat-label { font-size: 14px; color: #666; margin-top: 5px; }
</style>";

echo "<h1>🔧 Reparar Configuración de Estudiantes</h1>";

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
