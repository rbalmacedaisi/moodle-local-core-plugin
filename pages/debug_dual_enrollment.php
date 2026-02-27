<?php
/**
 * Debug and Fix Dual Enrollment: Students in Soldadura must also be in Buceo Comercial
 * DYNAMIC VERSION with real-time AJAX updates
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

// AJAX Handler - Process enrollment requests
$ajax = optional_param('ajax', '', PARAM_ALPHA);
if ($ajax === 'enroll') {
    header('Content-Type: application/json');

    try {
        $userid = required_param('userid', PARAM_INT);
        $direction = required_param('direction', PARAM_ALPHANUMEXT); // 'to_buceo' or 'to_soldadura'

        $studentRoleId = $DB->get_field('role', 'id', ['shortname' => 'student']);

        // Find learning plans
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

        if (!$soldaduraPlan || !$buceoPlan) {
            echo json_encode(['status' => 'error', 'message' => 'Learning plans not found']);
            exit;
        }

        $targetPlan = ($direction === 'to_buceo') ? $buceoPlan : $soldaduraPlan;

        // Get user info
        $user = $DB->get_record('user', ['id' => $userid], 'id, firstname, lastname, email');
        if (!$user) {
            echo json_encode(['status' => 'error', 'message' => "User ID $userid not found"]);
            exit;
        }

        // Check if already enrolled
        $existingEnrollment = $DB->get_record('local_learning_users', [
            'userid' => $userid,
            'learningplanid' => $targetPlan->id,
            'userroleid' => $studentRoleId
        ]);

        if ($existingEnrollment) {
            echo json_encode([
                'status' => 'warning',
                'message' => "{$user->firstname} {$user->lastname} ya está matriculado en {$targetPlan->name}"
            ]);
            exit;
        }

        // Get first period of target plan
        $firstPeriod = $DB->get_record_sql(
            "SELECT id, name FROM {local_learning_periods} WHERE learningplanid = ? ORDER BY id ASC",
            [$targetPlan->id],
            IGNORE_MULTIPLE
        );

        if (!$firstPeriod) {
            echo json_encode(['status' => 'error', 'message' => "No period found for {$targetPlan->name}"]);
            exit;
        }

        // 1. Create enrollment
        $enrollment = new stdClass();
        $enrollment->userid = $userid;
        $enrollment->learningplanid = $targetPlan->id;
        $enrollment->userroleid = $studentRoleId;
        $enrollment->userrolename = 'student';
        $enrollment->currentperiodid = $firstPeriod->id;
        $enrollment->currentsubperiodid = 0;
        $enrollment->timecreated = time();
        $enrollment->timemodified = time();

        $enrollmentId = $DB->insert_record('local_learning_users', $enrollment);

        // 2. Create progress matrix
        local_grupomakro_progress_manager::create_learningplan_user_progress(
            $userid,
            $targetPlan->id,
            $studentRoleId
        );

        // 3. Count created courses
        $courseCount = $DB->count_records('gmk_course_progre', [
            'userid' => $userid,
            'learningplanid' => $targetPlan->id
        ]);

        echo json_encode([
            'status' => 'success',
            'message' => "✓ {$user->firstname} {$user->lastname} matriculado exitosamente",
            'details' => [
                'enrollment_id' => $enrollmentId,
                'courses_created' => $courseCount,
                'period' => $firstPeriod->name
            ]
        ]);

    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }

    exit;
}

// Regular page display
admin_externalpage_setup('grupomakro_core_manage_courses');
echo $OUTPUT->header();

echo "<style>
    table { border-collapse: collapse; width: 100%; margin: 20px 0; font-size: 13px; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background-color: #f2f2f2; font-weight: bold; position: sticky; top: 0; }
    .missing { background-color: #fff3cd; }
    .complete { background-color: #d4edda; }
    .processing { background-color: #cfe2ff; }
    .error { background-color: #f8d7da; }
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
    .btn:disabled { opacity: 0.5; cursor: not-allowed; }
    .checkbox { width: 20px; height: 20px; }
    .progress-container { margin: 20px 0; padding: 15px; background: #f8f9fa; border-radius: 5px; display: none; }
    .progress-bar { width: 100%; height: 30px; background: #e9ecef; border-radius: 5px; overflow: hidden; margin: 10px 0; }
    .progress-fill { height: 100%; background: #28a745; transition: width 0.3s; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; }
    .log-entry { padding: 5px 10px; margin: 3px 0; border-left: 3px solid #ccc; background: white; border-radius: 3px; font-size: 12px; }
    .log-entry.success { border-color: #28a745; }
    .log-entry.error { border-color: #dc3545; background: #fff5f5; }
    .log-entry.warning { border-color: #ffc107; background: #fffbf0; }
    .spinner { display: inline-block; width: 16px; height: 16px; border: 2px solid #f3f3f3; border-top: 2px solid #007bff; border-radius: 50%; animation: spin 1s linear infinite; }
    @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
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
    echo "<div class='warning'><strong>ERROR:</strong> No se pueden continuar sin ambos learning plans.</div>";
    echo $OUTPUT->footer();
    die();
}

$studentRoleId = $DB->get_field('role', 'id', ['shortname' => 'student']);

// ========== SECTION 2: Soldadura -> Buceo ==========
echo "<div class='section'>";
echo "<h2>👥 Paso 2A: Estudiantes en Soldadura sin Buceo</h2>";

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
    echo "<div id='progress-buceo' class='progress-container'>
        <h3>Progreso de Matrícula a Buceo</h3>
        <div class='progress-bar'>
            <div id='progress-fill-buceo' class='progress-fill' style='width: 0%'>0%</div>
        </div>
        <div id='progress-log-buceo' style='max-height: 300px; overflow-y: auto;'></div>
    </div>";

    echo "<table id='table-soldadura-buceo'>";
    echo "<tr>";
    echo "<th><input type='checkbox' id='selectAll' onclick='toggleAll(this)'></th>";
    echo "<th>ID</th><th>Nombre</th><th>Email</th><th>Username</th><th>Período</th><th>Estado</th>";
    echo "</tr>";

    foreach ($missingStudents as $student) {
        echo "<tr class='missing' data-userid='{$student->userid}'>";
        echo "<td><input type='checkbox' class='student-checkbox' value='{$student->userid}'></td>";
        echo "<td>{$student->userid}</td>";
        echo "<td>{$student->firstname} {$student->lastname}</td>";
        echo "<td>{$student->email}</td>";
        echo "<td>{$student->username}</td>";
        echo "<td>{$student->current_period}</td>";
        echo "<td class='status-cell'>Pendiente</td>";
        echo "</tr>";
    }
    echo "</table>";

    echo "<div style='margin-top: 20px;'>";
    echo "<button id='btn-enroll-buceo' class='btn btn-success' onclick='enrollSelected(\"to_buceo\")'>✓ Matricular Seleccionados en Buceo</button>";
    echo "</div>";
}

if (!empty($alreadyEnrolled)) {
    echo "<h3>Estudiantes Ya Matriculados en Buceo</h3>";
    echo "<table>";
    echo "<tr><th>ID</th><th>Nombre</th><th>Email</th></tr>";
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

// ========== SECTION 2B: Buceo -> Soldadura ==========
echo "<div class='section'>";
echo "<h2>👥 Paso 2B: Estudiantes en Buceo sin Soldadura</h2>";

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

echo "<div class='info'><strong>Total estudiantes en Buceo:</strong> " . count($buceoStudents) . "</div>";

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
    echo "<div id='progress-soldadura' class='progress-container'>
        <h3>Progreso de Matrícula a Soldadura</h3>
        <div class='progress-bar'>
            <div id='progress-fill-soldadura' class='progress-fill' style='width: 0%'>0%</div>
        </div>
        <div id='progress-log-soldadura' style='max-height: 300px; overflow-y: auto;'></div>
    </div>";

    echo "<table id='table-buceo-soldadura'>";
    echo "<tr>";
    echo "<th><input type='checkbox' id='selectAllReverse' onclick='toggleAllReverse(this)'></th>";
    echo "<th>ID</th><th>Nombre</th><th>Email</th><th>Username</th><th>Período</th><th>Estado</th>";
    echo "</tr>";

    foreach ($missingInSoldadura as $student) {
        echo "<tr class='missing' data-userid='{$student->userid}'>";
        echo "<td><input type='checkbox' class='student-checkbox-reverse' value='{$student->userid}'></td>";
        echo "<td>{$student->userid}</td>";
        echo "<td>{$student->firstname} {$student->lastname}</td>";
        echo "<td>{$student->email}</td>";
        echo "<td>{$student->username}</td>";
        echo "<td>{$student->current_period}</td>";
        echo "<td class='status-cell'>Pendiente</td>";
        echo "</tr>";
    }
    echo "</table>";

    echo "<div style='margin-top: 20px;'>";
    echo "<button id='btn-enroll-soldadura' class='btn btn-success' onclick='enrollSelected(\"to_soldadura\")'>✓ Matricular Seleccionados en Soldadura</button>";
    echo "</div>";
}

if (!empty($alreadyInSoldadura)) {
    echo "<h3>Estudiantes Ya Matriculados en Soldadura</h3>";
    echo "<table>";
    echo "<tr><th>ID</th><th>Nombre</th><th>Email</th></tr>";
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

// ========== JavaScript for Dynamic Enrollment ==========
echo "<script>
function toggleAll(source) {
    const checkboxes = document.querySelectorAll('.student-checkbox');
    checkboxes.forEach(cb => cb.checked = source.checked);
}

function toggleAllReverse(source) {
    const checkboxes = document.querySelectorAll('.student-checkbox-reverse');
    checkboxes.forEach(cb => cb.checked = source.checked);
}

async function enrollSelected(direction) {
    const isBuceo = (direction === 'to_buceo');
    const checkboxClass = isBuceo ? '.student-checkbox' : '.student-checkbox-reverse';
    const progressId = isBuceo ? 'buceo' : 'soldadura';
    const btnId = isBuceo ? 'btn-enroll-buceo' : 'btn-enroll-soldadura';
    const tableId = isBuceo ? 'table-soldadura-buceo' : 'table-buceo-soldadura';

    // Get selected checkboxes
    const checkboxes = document.querySelectorAll(checkboxClass + ':checked');

    if (checkboxes.length === 0) {
        alert('Por favor selecciona al menos un estudiante');
        return;
    }

    const targetName = isBuceo ? 'Buceo Comercial' : 'Soldadura';
    if (!confirm(`¿Matricular ` + checkboxes.length + ` estudiante(s) en ` + targetName + `?`)) {
        return;
    }

    // Show progress container
    document.getElementById('progress-' + progressId).style.display = 'block';

    // Disable button
    const btn = document.getElementById(btnId);
    btn.disabled = true;
    btn.innerHTML = '<span class=\"spinner\"></span> Procesando...';

    const userIds = Array.from(checkboxes).map(cb => parseInt(cb.value));
    const total = userIds.length;
    let processed = 0;
    let succeeded = 0;
    let failed = 0;

    const progressFill = document.getElementById('progress-fill-' + progressId);
    const progressLog = document.getElementById('progress-log-' + progressId);

    // Clear previous log
    progressLog.innerHTML = '';

    // Process each user
    for (const userid of userIds) {
        try {
            // Update row status
            const row = document.querySelector('#' + tableId + ' tr[data-userid=\"' + userid + '\"]');
            if (row) {
                row.className = 'processing';
                const statusCell = row.querySelector('.status-cell');
                statusCell.innerHTML = '<span class=\"spinner\"></span> Procesando...';
            }

            // Make AJAX request
            const formData = new FormData();
            formData.append('ajax', 'enroll');
            formData.append('userid', userid);
            formData.append('direction', direction);
            formData.append('sesskey', M.cfg.sesskey);

            const response = await fetch(window.location.href, {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            processed++;

            if (result.status === 'success') {
                succeeded++;
                if (row) {
                    row.className = 'complete';
                    const statusCell = row.querySelector('.status-cell');
                    statusCell.textContent = '✓ Completado';
                }

                const logEntry = document.createElement('div');
                logEntry.className = 'log-entry success';
                logEntry.textContent = '✓ ' + result.message;
                if (result.details) {
                    logEntry.textContent += ' (Enrollment ID: ' + result.details.enrollment_id + ', Cursos: ' + result.details.courses_created + ')';
                }
                progressLog.appendChild(logEntry);
            } else {
                failed++;
                if (row) {
                    row.className = 'error';
                    const statusCell = row.querySelector('.status-cell');
                    statusCell.textContent = '✗ Error';
                }

                const logEntry = document.createElement('div');
                logEntry.className = 'log-entry error';
                logEntry.textContent = '✗ ' + result.message;
                progressLog.appendChild(logEntry);
            }

            // Update progress bar
            const percentage = Math.round((processed / total) * 100);
            progressFill.style.width = percentage + '%';
            progressFill.textContent = percentage + '%';

            // Scroll log to bottom
            progressLog.scrollTop = progressLog.scrollHeight;

        } catch (error) {
            failed++;
            processed++;

            const row = document.querySelector('#' + tableId + ' tr[data-userid=\"' + userid + '\"]');
            if (row) {
                row.className = 'error';
                const statusCell = row.querySelector('.status-cell');
                statusCell.textContent = '✗ Error';
            }

            const logEntry = document.createElement('div');
            logEntry.className = 'log-entry error';
            logEntry.textContent = '✗ Error procesando usuario ' + userid + ': ' + error.message;
            progressLog.appendChild(logEntry);
        }
    }

    // Final summary
    const summary = document.createElement('div');
    summary.className = 'log-entry info';
    summary.innerHTML = '<strong>RESUMEN:</strong> Total: ' + total + ' | Exitosos: ' + succeeded + ' | Fallidos: ' + failed;
    progressLog.appendChild(summary);

    // Re-enable button
    btn.disabled = false;
    btn.innerHTML = '✓ Matricular Seleccionados en ' + targetName;

    // Show reload option
    if (succeeded > 0) {
        setTimeout(() => {
            if (confirm('Matrícula completada. ¿Recargar la página para ver los cambios?')) {
                location.reload();
            }
        }, 1000);
    }
}
</script>";

echo $OUTPUT->footer();
