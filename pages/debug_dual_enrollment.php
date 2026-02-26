<?php
/**
 * Debug and Fix Dual Enrollment: Students in Soldadura must also be in Buceo Comercial
 *
 * @package    local_grupomakro_core
 * @copyright  2025 Antigravity
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/progress_manager.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

global $DB;

$action = optional_param('action', '', PARAM_ALPHA);
$userids = optional_param('userids', '', PARAM_RAW); // Comma-separated user IDs

// Display the page
admin_externalpage_setup('grupomakro_core_manage_courses');
echo $OUTPUT->header();

echo "<style>
    table { border-collapse: collapse; width: 100%; margin: 20px 0; font-size: 13px; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background-color: #f2f2f2; font-weight: bold; position: sticky; top: 0; }
    .missing { background-color: #fff3cd; }
    .complete { background-color: #d4edda; }
    .section { margin: 30px 0; padding: 20px; border: 2px solid #ccc; border-radius: 5px; }
    .warning { background-color: #f8d7da; padding: 10px; border-radius: 5px; margin: 10px 0; }
    .info { background-color: #d1ecf1; padding: 10px; border-radius: 5px; margin: 10px 0; }
    .success { background-color: #d4edda; padding: 10px; border-radius: 5px; margin: 10px 0; }
    pre { background-color: #f4f4f4; padding: 10px; border-radius: 5px; overflow-x: auto; font-size: 12px; }
    .btn { padding: 10px 20px; border-radius: 5px; text-decoration: none; display: inline-block; margin: 5px; cursor: pointer; border: none; font-weight: bold; }
    .btn-primary { background-color: #007bff; color: white; }
    .btn-success { background-color: #28a745; color: white; }
    .btn-danger { background-color: #dc3545; color: white; }
    .btn:hover { opacity: 0.8; }
    .checkbox { width: 20px; height: 20px; }
</style>";

echo "<h1>🔍 Debug: Matrícula Dual Bidireccional (Soldadura ↔ Buceo Comercial)</h1>";

// ========== SECTION 1: Find Learning Plans ==========
echo "<div class='section'>";
echo "<h2>📋 Paso 1: Identificar Learning Plans</h2>";

$soldaduraPlan = $DB->get_record_sql(
    "SELECT id, name FROM {local_learning_plans} WHERE name LIKE '%SOLDADURA SUBACUÁTICA%' OR name LIKE '%SOLDADURA%'",
    null,
    IGNORE_MULTIPLE
);

$buceoPlan = $DB->get_record_sql(
    "SELECT id, name FROM {local_learning_plans} WHERE name LIKE '%BUCEO COMERCIAL%' OR name LIKE '%BUCEO%'",
    null,
    IGNORE_MULTIPLE
);

if ($soldaduraPlan) {
    echo "<div class='success'><strong>✓ Plan Soldadura encontrado:</strong> {$soldaduraPlan->name} (ID: {$soldaduraPlan->id})</div>";
} else {
    echo "<div class='warning'><strong>✗ Plan Soldadura NO encontrado</strong></div>";
    echo "<p>Todos los learning plans disponibles:</p>";
    $allPlans = $DB->get_records('local_learning_plans', null, 'name ASC', 'id, name');
    echo "<ul>";
    foreach ($allPlans as $plan) {
        echo "<li>ID {$plan->id}: {$plan->name}</li>";
    }
    echo "</ul>";
}

if ($buceoPlan) {
    echo "<div class='success'><strong>✓ Plan Buceo encontrado:</strong> {$buceoPlan->name} (ID: {$buceoPlan->id})</div>";
} else {
    echo "<div class='warning'><strong>✗ Plan Buceo Comercial NO encontrado</strong></div>";
}

echo "</div>";

if (!$soldaduraPlan || !$buceoPlan) {
    echo "<div class='warning'><strong>ERROR:</strong> No se pueden continuar sin ambos learning plans. Por favor verifica los nombres.</div>";
    echo $OUTPUT->footer();
    die();
}

// ========== SECTION 2: Identify Students Needing Dual Enrollment ==========
echo "<div class='section'>";
echo "<h2>👥 Paso 2: Estudiantes en Soldadura sin Buceo</h2>";

$studentRoleId = $DB->get_field('role', 'id', ['shortname' => 'student']);

// Get all students enrolled in Soldadura
$sql = "SELECT u.id as userid, u.firstname, u.lastname, u.email, u.username,
               llu.id as enrollment_id, llu.currentperiodid, per.name as current_period
        FROM {user} u
        JOIN {local_learning_users} llu ON llu.userid = u.id
        LEFT JOIN {local_learning_periods} per ON per.id = llu.currentperiodid
        WHERE llu.learningplanid = :soldadura_plan
        AND llu.userroleid = :student_role
        AND u.deleted = 0
        ORDER BY u.lastname, u.firstname";

$soldaduraStudents = $DB->get_records_sql($sql, [
    'soldadura_plan' => $soldaduraPlan->id,
    'student_role' => $studentRoleId
]);

echo "<div class='info'><strong>Total estudiantes en Soldadura:</strong> " . count($soldaduraStudents) . "</div>";

// Check which ones are NOT in Buceo
$missingStudents = [];
$alreadyEnrolled = [];

foreach ($soldaduraStudents as $student) {
    $buceoEnrollment = $DB->get_record('local_learning_users', [
        'userid' => $student->userid,
        'learningplanid' => $buceoPlan->id,
        'userroleid' => $studentRoleId
    ]);

    if (!$buceoEnrollment) {
        $missingStudents[] = $student;
    } else {
        $alreadyEnrolled[] = $student;
    }
}

echo "<div class='warning'><strong>⚠️ Estudiantes SIN matrícula en Buceo:</strong> " . count($missingStudents) . "</div>";
echo "<div class='success'><strong>✓ Estudiantes YA matriculados en Buceo:</strong> " . count($alreadyEnrolled) . "</div>";

if (!empty($missingStudents)) {
    echo "<form method='post' id='enrollForm'>";
    echo "<input type='hidden' name='action' value='enroll'>";
    echo "<table>";
    echo "<tr>";
    echo "<th><input type='checkbox' id='selectAll' onclick='toggleAll(this)'></th>";
    echo "<th>ID Usuario</th><th>Nombre Completo</th><th>Email</th><th>Username</th><th>Período Actual (Soldadura)</th>";
    echo "</tr>";

    $userIdsList = [];
    foreach ($missingStudents as $student) {
        $userIdsList[] = $student->userid;
        echo "<tr class='missing'>";
        echo "<td><input type='checkbox' name='selected_users[]' value='{$student->userid}' class='student-checkbox'></td>";
        echo "<td>{$student->userid}</td>";
        echo "<td>{$student->firstname} {$student->lastname}</td>";
        echo "<td>{$student->email}</td>";
        echo "<td>{$student->username}</td>";
        echo "<td>{$student->current_period}</td>";
        echo "</tr>";
    }
    echo "</table>";

    echo "<div style='margin-top: 20px;'>";
    echo "<button type='submit' class='btn btn-success' onclick=\"return confirm('¿Matricular a los estudiantes seleccionados en {$buceoPlan->name}?');\">✓ Matricular Seleccionados en Buceo Comercial</button>";
    echo "</div>";
    echo "</form>";
}

if (!empty($alreadyEnrolled)) {
    echo "<h3>Estudiantes Ya Matriculados</h3>";
    echo "<table>";
    echo "<tr><th>ID Usuario</th><th>Nombre Completo</th><th>Email</th></tr>";
    foreach ($alreadyEnrolled as $student) {
        echo "<tr class='complete'>";
        echo "<td>{$student->userid}</td>";
        echo "<td>{$student->firstname} {$student->lastname}</td>";
        echo "<td>{$student->email}</td>";
        echo "</tr>";
    }
    echo "</table>";
}

echo "</div>";

// ========== SECTION 2B: Identify Students in Buceo without Soldadura ==========
echo "<div class='section'>";
echo "<h2>👥 Paso 2B: Estudiantes en Buceo sin Soldadura (Validación Inversa)</h2>";

// Get all students enrolled in Buceo
$sql = "SELECT u.id as userid, u.firstname, u.lastname, u.email, u.username,
               llu.id as enrollment_id, llu.currentperiodid, per.name as current_period
        FROM {user} u
        JOIN {local_learning_users} llu ON llu.userid = u.id
        LEFT JOIN {local_learning_periods} per ON per.id = llu.currentperiodid
        WHERE llu.learningplanid = :buceo_plan
        AND llu.userroleid = :student_role
        AND u.deleted = 0
        ORDER BY u.lastname, u.firstname";

$buceoStudents = $DB->get_records_sql($sql, [
    'buceo_plan' => $buceoPlan->id,
    'student_role' => $studentRoleId
]);

echo "<div class='info'><strong>Total estudiantes en Buceo Comercial:</strong> " . count($buceoStudents) . "</div>";

// Check which ones are NOT in Soldadura
$missingInSoldadura = [];
$alreadyInSoldadura = [];

foreach ($buceoStudents as $student) {
    $soldaduraEnrollment = $DB->get_record('local_learning_users', [
        'userid' => $student->userid,
        'learningplanid' => $soldaduraPlan->id,
        'userroleid' => $studentRoleId
    ]);

    if (!$soldaduraEnrollment) {
        $missingInSoldadura[] = $student;
    } else {
        $alreadyInSoldadura[] = $student;
    }
}

echo "<div class='warning'><strong>⚠️ Estudiantes SIN matrícula en Soldadura:</strong> " . count($missingInSoldadura) . "</div>";
echo "<div class='success'><strong>✓ Estudiantes YA matriculados en Soldadura:</strong> " . count($alreadyInSoldadura) . "</div>";

if (!empty($missingInSoldadura)) {
    echo "<form method='post' id='enrollFormReverse'>";
    echo "<input type='hidden' name='action' value='enroll_reverse'>";
    echo "<table>";
    echo "<tr>";
    echo "<th><input type='checkbox' id='selectAllReverse' onclick='toggleAllReverse(this)'></th>";
    echo "<th>ID Usuario</th><th>Nombre Completo</th><th>Email</th><th>Username</th><th>Período Actual (Buceo)</th>";
    echo "</tr>";

    foreach ($missingInSoldadura as $student) {
        echo "<tr class='missing'>";
        echo "<td><input type='checkbox' name='selected_users_reverse[]' value='{$student->userid}' class='student-checkbox-reverse'></td>";
        echo "<td>{$student->userid}</td>";
        echo "<td>{$student->firstname} {$student->lastname}</td>";
        echo "<td>{$student->email}</td>";
        echo "<td>{$student->username}</td>";
        echo "<td>{$student->current_period}</td>";
        echo "</tr>";
    }
    echo "</table>";

    echo "<div style='margin-top: 20px;'>";
    echo "<button type='submit' class='btn btn-success' onclick=\"return confirm('¿Matricular a los estudiantes seleccionados en {$soldaduraPlan->name}?');\">✓ Matricular Seleccionados en Soldadura</button>";
    echo "</div>";
    echo "</form>";
}

if (!empty($alreadyInSoldadura)) {
    echo "<h3>Estudiantes Ya Matriculados en Soldadura</h3>";
    echo "<table>";
    echo "<tr><th>ID Usuario</th><th>Nombre Completo</th><th>Email</th></tr>";
    foreach ($alreadyInSoldadura as $student) {
        echo "<tr class='complete'>";
        echo "<td>{$student->userid}</td>";
        echo "<td>{$student->firstname} {$student->lastname}</td>";
        echo "<td>{$student->email}</td>";
        echo "</tr>";
    }
    echo "</table>";
}

echo "</div>";

// ========== SECTION 3: Process Enrollment (Soldadura -> Buceo) ==========
if ($action === 'enroll') {
    echo "<div class='section'>";
    echo "<h2>🔄 Paso 3: Procesando Matrículas</h2>";

    $selectedUsers = optional_param_array('selected_users', [], PARAM_INT);

    if (empty($selectedUsers)) {
        echo "<div class='warning'>⚠️ No se seleccionaron estudiantes.</div>";
    } else {
        echo "<div class='info'>Procesando " . count($selectedUsers) . " estudiantes...</div>";

        $success = 0;
        $errors = [];

        // Get first period of Buceo plan
        $firstBuceoPeriod = $DB->get_record_sql(
            "SELECT id, name FROM {local_learning_periods} WHERE learningplanid = ? ORDER BY id ASC",
            [$buceoPlan->id],
            IGNORE_MULTIPLE
        );

        if (!$firstBuceoPeriod) {
            echo "<div class='warning'><strong>ERROR:</strong> No se encontró período inicial para {$buceoPlan->name}</div>";
        } else {
            echo "<div class='info'><strong>Período inicial de Buceo:</strong> {$firstBuceoPeriod->name} (ID: {$firstBuceoPeriod->id})</div>";

            foreach ($selectedUsers as $userid) {
                try {
                    $user = $DB->get_record('user', ['id' => $userid], 'id, firstname, lastname, email');
                    if (!$user) {
                        $errors[] = "Usuario ID $userid no encontrado";
                        continue;
                    }

                    // 1. Check if already enrolled (double-check)
                    $existingEnrollment = $DB->get_record('local_learning_users', [
                        'userid' => $userid,
                        'learningplanid' => $buceoPlan->id
                    ]);

                    if ($existingEnrollment) {
                        $errors[] = "{$user->firstname} {$user->lastname}: Ya matriculado";
                        continue;
                    }

                    // 2. Create enrollment in local_learning_users
                    $enrollment = new stdClass();
                    $enrollment->userid = $userid;
                    $enrollment->learningplanid = $buceoPlan->id;
                    $enrollment->userroleid = $studentRoleId;
                    $enrollment->userrolename = 'student';
                    $enrollment->currentperiodid = $firstBuceoPeriod->id;
                    $enrollment->currentsubperiodid = 0;
                    $enrollment->timecreated = time();
                    $enrollment->timemodified = time();

                    $enrollmentId = $DB->insert_record('local_learning_users', $enrollment);

                    // 3. Create progress matrix (gmk_course_progre) for all courses in the plan
                    local_grupomakro_progress_manager::create_learningplan_user_progress(
                        $userid,
                        $buceoPlan->id,
                        $studentRoleId
                    );

                    echo "<div class='success'>✓ {$user->firstname} {$user->lastname}: Matriculado exitosamente (Enrollment ID: $enrollmentId)</div>";
                    $success++;

                } catch (Exception $e) {
                    $errors[] = "Usuario ID $userid: " . $e->getMessage();
                }
            }

            echo "<div class='info' style='margin-top: 20px;'>";
            echo "<strong>Resumen:</strong><br>";
            echo "✓ Exitosos: $success<br>";
            echo "✗ Errores: " . count($errors);
            echo "</div>";

            if (!empty($errors)) {
                echo "<div class='warning'>";
                echo "<strong>Errores encontrados:</strong><br>";
                foreach ($errors as $error) {
                    echo "• " . htmlspecialchars($error) . "<br>";
                }
                echo "</div>";
            }
        }
    }

    echo "<div style='margin-top: 20px;'>";
    echo "<a href='debug_dual_enrollment.php' class='btn btn-primary'>↻ Recargar Página</a>";
    echo "</div>";

    echo "</div>";
}

// ========== SECTION 3B: Process Reverse Enrollment (Buceo -> Soldadura) ==========
if ($action === 'enroll_reverse') {
    echo "<div class='section'>";
    echo "<h2>🔄 Paso 3B: Procesando Matrículas Inversas (Buceo → Soldadura)</h2>";

    $selectedUsersReverse = optional_param_array('selected_users_reverse', [], PARAM_INT);

    if (empty($selectedUsersReverse)) {
        echo "<div class='warning'>⚠️ No se seleccionaron estudiantes.</div>";
    } else {
        echo "<div class='info'>Procesando " . count($selectedUsersReverse) . " estudiantes...</div>";

        $success = 0;
        $errors = [];

        // Get first period of Soldadura plan
        $firstSoldaduraPeriod = $DB->get_record_sql(
            "SELECT id, name FROM {local_learning_periods} WHERE learningplanid = ? ORDER BY id ASC",
            [$soldaduraPlan->id],
            IGNORE_MULTIPLE
        );

        if (!$firstSoldaduraPeriod) {
            echo "<div class='warning'><strong>ERROR:</strong> No se encontró período inicial para {$soldaduraPlan->name}</div>";
        } else {
            echo "<div class='info'><strong>Período inicial de Soldadura:</strong> {$firstSoldaduraPeriod->name} (ID: {$firstSoldaduraPeriod->id})</div>";

            foreach ($selectedUsersReverse as $userid) {
                try {
                    $user = $DB->get_record('user', ['id' => $userid], 'id, firstname, lastname, email');
                    if (!$user) {
                        $errors[] = "Usuario ID $userid no encontrado";
                        continue;
                    }

                    // 1. Check if already enrolled (double-check)
                    $existingEnrollment = $DB->get_record('local_learning_users', [
                        'userid' => $userid,
                        'learningplanid' => $soldaduraPlan->id
                    ]);

                    if ($existingEnrollment) {
                        $errors[] = "{$user->firstname} {$user->lastname}: Ya matriculado";
                        continue;
                    }

                    // 2. Create enrollment in local_learning_users
                    $enrollment = new stdClass();
                    $enrollment->userid = $userid;
                    $enrollment->learningplanid = $soldaduraPlan->id;
                    $enrollment->userroleid = $studentRoleId;
                    $enrollment->userrolename = 'student';
                    $enrollment->currentperiodid = $firstSoldaduraPeriod->id;
                    $enrollment->currentsubperiodid = 0;
                    $enrollment->timecreated = time();
                    $enrollment->timemodified = time();

                    $enrollmentId = $DB->insert_record('local_learning_users', $enrollment);

                    // 3. Create progress matrix (gmk_course_progre) for all courses in the plan
                    local_grupomakro_progress_manager::create_learningplan_user_progress(
                        $userid,
                        $soldaduraPlan->id,
                        $studentRoleId
                    );

                    echo "<div class='success'>✓ {$user->firstname} {$user->lastname}: Matriculado exitosamente en Soldadura (Enrollment ID: $enrollmentId)</div>";
                    $success++;

                } catch (Exception $e) {
                    $errors[] = "Usuario ID $userid: " . $e->getMessage();
                }
            }

            echo "<div class='info' style='margin-top: 20px;'>";
            echo "<strong>Resumen:</strong><br>";
            echo "✓ Exitosos: $success<br>";
            echo "✗ Errores: " . count($errors);
            echo "</div>";

            if (!empty($errors)) {
                echo "<div class='warning'>";
                echo "<strong>Errores encontrados:</strong><br>";
                foreach ($errors as $error) {
                    echo "• " . htmlspecialchars($error) . "<br>";
                }
                echo "</div>";
            }
        }
    }

    echo "<div style='margin-top: 20px;'>";
    echo "<a href='debug_dual_enrollment.php' class='btn btn-primary'>↻ Recargar Página</a>";
    echo "</div>";

    echo "</div>";
}

// ========== SECTION 4: Technical Details ==========
echo "<div class='section'>";
echo "<h2>🔧 Detalles Técnicos</h2>";

echo "<h3>Consultas SQL Utilizadas</h3>";
echo "<pre>";
echo "-- Estudiantes en Soldadura sin Buceo:\n";
echo "SELECT u.id, u.firstname, u.lastname
FROM mdl_user u
JOIN mdl_local_learning_users llu ON llu.userid = u.id
WHERE llu.learningplanid = {$soldaduraPlan->id}
AND llu.userroleid = $studentRoleId
AND NOT EXISTS (
    SELECT 1 FROM mdl_local_learning_users llu2
    WHERE llu2.userid = u.id
    AND llu2.learningplanid = {$buceoPlan->id}
    AND llu2.userroleid = $studentRoleId
)\n\n";

echo "-- Estudiantes en Buceo sin Soldadura (Validación Inversa):\n";
echo "SELECT u.id, u.firstname, u.lastname
FROM mdl_user u
JOIN mdl_local_learning_users llu ON llu.userid = u.id
WHERE llu.learningplanid = {$buceoPlan->id}
AND llu.userroleid = $studentRoleId
AND NOT EXISTS (
    SELECT 1 FROM mdl_local_learning_users llu2
    WHERE llu2.userid = u.id
    AND llu2.learningplanid = {$soldaduraPlan->id}
    AND llu2.userroleid = $studentRoleId
)\n\n";

echo "-- Obtener primer período:\n";
echo "SELECT id, name FROM mdl_local_learning_periods
WHERE learningplanid = [PLAN_ID]
ORDER BY id ASC LIMIT 1\n";
echo "</pre>";

echo "<h3>Proceso de Matrícula Dual</h3>";
echo "<ol>";
echo "<li><strong>Validación bidireccional:</strong> Se verifica en ambas direcciones (Soldadura↔Buceo)</li>";
echo "<li><strong>Crear registro en local_learning_users:</strong> Asocia usuario con learning plan faltante</li>";
echo "<li><strong>Asignar rol de estudiante:</strong> userroleid = $studentRoleId</li>";
echo "<li><strong>Asignar período inicial:</strong> currentperiodid = primer período del plan destino</li>";
echo "<li><strong>Crear malla de progreso:</strong> create_learningplan_user_progress() genera registros en gmk_course_progre</li>";
echo "</ol>";

echo "<h3>Regla de Negocio</h3>";
echo "<p><strong>Matrícula Dual Obligatoria:</strong> Los estudiantes matriculados en <em>Soldadura Subacuática</em> DEBEN estar matriculados también en <em>Buceo Comercial</em>, y viceversa. Esta herramienta identifica y corrige inconsistencias en ambas direcciones.</p>";

echo "</div>";

echo "<script>
function toggleAll(source) {
    const checkboxes = document.querySelectorAll('.student-checkbox');
    checkboxes.forEach(cb => cb.checked = source.checked);
}

function toggleAllReverse(source) {
    const checkboxes = document.querySelectorAll('.student-checkbox-reverse');
    checkboxes.forEach(cb => cb.checked = source.checked);
}
</script>";

echo $OUTPUT->footer();
