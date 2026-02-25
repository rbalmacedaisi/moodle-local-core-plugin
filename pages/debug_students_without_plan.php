<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('grupomakro_core_manage_courses');

echo $OUTPUT->header();
global $DB;

$action = optional_param('action', '', PARAM_ALPHA);
$userid = optional_param('userid', 0, PARAM_INT);
$planid = optional_param('planid', 0, PARAM_INT);

echo "<style>
    table { border-collapse: collapse; width: 100%; margin: 20px 0; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background-color: #f2f2f2; font-weight: bold; }
    .warning { background-color: #fff3cd; }
    .error { background-color: #f8d7da; }
    .success { background-color: #d4edda; }
    .info { background-color: #d1ecf1; }
    .section { margin: 30px 0; padding: 20px; border: 2px solid #ccc; border-radius: 5px; }
    .stat-box { display: inline-block; padding: 15px 25px; margin: 10px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #007bff; }
    .stat-number { font-size: 32px; font-weight: bold; color: #007bff; }
    .stat-label { font-size: 14px; color: #666; margin-top: 5px; }
    pre { background-color: #f4f4f4; padding: 10px; border-radius: 5px; overflow-x: auto; }
    .btn { padding: 5px 10px; margin: 2px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; display: inline-block; }
    .btn-primary { background-color: #007bff; color: white; }
    .btn-success { background-color: #28a745; color: white; }
    .btn-danger { background-color: #dc3545; color: white; }
    .btn-warning { background-color: #ffc107; color: black; }
</style>";

echo "<h1>🔍 Debug: Estudiantes Sin Plan de Aprendizaje</h1>";

// ========== HANDLE ACTIONS ==========
if ($action === 'assign_plan' && $userid > 0 && $planid > 0) {
    echo "<div class='section info'>";
    echo "<h2>🔧 Asignando Plan de Aprendizaje</h2>";

    $user = $DB->get_record('user', ['id' => $userid], 'id, firstname, lastname, idnumber');
    $plan = $DB->get_record('local_learning_plans', ['id' => $planid], 'id, name');

    if ($user && $plan) {
        // Check if already exists
        $existing = $DB->get_record('local_learning_users', ['userid' => $userid]);

        if ($existing) {
            echo "<div class='warning' style='padding: 10px; border-radius: 5px; margin: 10px 0;'>";
            echo "<strong>⚠️ El usuario ya tiene un registro en local_learning_users</strong><br>";
            echo "Plan actual: ID {$existing->learningplanid}<br>";
            echo "¿Deseas actualizar el plan?<br><br>";
            echo "<a href='?action=update_plan&userid=$userid&planid=$planid' class='btn btn-warning'>Actualizar Plan</a> ";
            echo "<a href='?' class='btn btn-primary'>Cancelar</a>";
            echo "</div>";
        } else {
            // Create new record
            $record = new stdClass();
            $record->userid = $userid;
            $record->learningplanid = $planid;
            $record->currentperiodid = 0;
            $record->timecreated = time();
            $record->timemodified = time();
            $record->usermodified = $USER->id;

            try {
                $DB->insert_record('local_learning_users', $record);
                echo "<div class='success' style='padding: 10px; border-radius: 5px; margin: 10px 0;'>";
                echo "<strong>✅ Plan asignado exitosamente</strong><br>";
                echo "Usuario: {$user->firstname} {$user->lastname} ({$user->idnumber})<br>";
                echo "Plan: {$plan->name}<br>";
                echo "<a href='?' class='btn btn-primary'>Volver</a>";
                echo "</div>";
            } catch (Exception $e) {
                echo "<div class='error' style='padding: 10px; border-radius: 5px; margin: 10px 0;'>";
                echo "<strong>❌ Error al asignar plan:</strong><br>";
                echo $e->getMessage();
                echo "</div>";
            }
        }
    } else {
        echo "<div class='error' style='padding: 10px; border-radius: 5px; margin: 10px 0;'>";
        echo "❌ Usuario o Plan no encontrado";
        echo "</div>";
    }

    echo "</div>";
}

if ($action === 'update_plan' && $userid > 0 && $planid > 0) {
    echo "<div class='section info'>";
    echo "<h2>🔧 Actualizando Plan de Aprendizaje</h2>";

    $existing = $DB->get_record('local_learning_users', ['userid' => $userid]);
    if ($existing) {
        $existing->learningplanid = $planid;
        $existing->timemodified = time();
        $existing->usermodified = $USER->id;

        try {
            $DB->update_record('local_learning_users', $existing);
            echo "<div class='success' style='padding: 10px; border-radius: 5px; margin: 10px 0;'>";
            echo "✅ Plan actualizado exitosamente<br>";
            echo "<a href='?' class='btn btn-primary'>Volver</a>";
            echo "</div>";
        } catch (Exception $e) {
            echo "<div class='error' style='padding: 10px; border-radius: 5px; margin: 10px 0;'>";
            echo "❌ Error: " . $e->getMessage();
            echo "</div>";
        }
    }
    echo "</div>";
}

// ========== STATISTICS ==========
echo "<div class='section'>";
echo "<h2>📊 Estadísticas Generales</h2>";

$student_role = $DB->get_record('role', ['shortname' => 'student']);
$total_students_with_role = 0;
if ($student_role) {
    $sql = "SELECT COUNT(DISTINCT ra.userid) as count
            FROM {role_assignments} ra
            JOIN {user} u ON u.id = ra.userid
            WHERE ra.roleid = :roleid
            AND u.deleted = 0";
    $total_students_with_role = $DB->count_records_sql($sql, ['roleid' => $student_role->id]);
}

$students_with_plan = $DB->count_records_sql(
    "SELECT COUNT(DISTINCT llu.userid)
     FROM {local_learning_users} llu
     JOIN {user} u ON u.id = llu.userid
     WHERE u.deleted = 0"
);

$sql = "SELECT COUNT(DISTINCT ra.userid) as count
        FROM {role_assignments} ra
        JOIN {user} u ON u.id = ra.userid
        LEFT JOIN {local_learning_users} llu ON llu.userid = ra.userid
        WHERE ra.roleid = :roleid
        AND u.deleted = 0
        AND llu.id IS NULL";
$students_without_plan = $DB->count_records_sql($sql, ['roleid' => $student_role->id]);

echo "<div>";
echo "<div class='stat-box'>";
echo "<div class='stat-number'>$total_students_with_role</div>";
echo "<div class='stat-label'>Total Estudiantes (con rol)</div>";
echo "</div>";

echo "<div class='stat-box' style='border-left-color: #28a745;'>";
echo "<div class='stat-number' style='color: #28a745;'>$students_with_plan</div>";
echo "<div class='stat-label'>Con Plan de Aprendizaje</div>";
echo "</div>";

echo "<div class='stat-box' style='border-left-color: #dc3545;'>";
echo "<div class='stat-number' style='color: #dc3545;'>$students_without_plan</div>";
echo "<div class='stat-label'>Sin Plan de Aprendizaje</div>";
echo "</div>";
echo "</div>";

echo "</div>";

// ========== LIST OF STUDENTS WITHOUT PLAN ==========
echo "<div class='section'>";
echo "<h2>👥 Estudiantes Sin Plan de Aprendizaje</h2>";

$sql = "SELECT u.id, u.username, u.firstname, u.lastname, u.email, u.idnumber, u.timecreated
        FROM {role_assignments} ra
        JOIN {user} u ON u.id = ra.userid
        LEFT JOIN {local_learning_users} llu ON llu.userid = ra.userid
        WHERE ra.roleid = :roleid
        AND u.deleted = 0
        AND llu.id IS NULL
        ORDER BY u.timecreated DESC";

$students_no_plan = $DB->get_records_sql($sql, ['roleid' => $student_role->id]);

if (empty($students_no_plan)) {
    echo "<div class='success' style='padding: 10px; border-radius: 5px;'>";
    echo "✅ ¡Excelente! Todos los estudiantes tienen un plan de aprendizaje asignado.";
    echo "</div>";
} else {
    echo "<p><strong>Total:</strong> " . count($students_no_plan) . " estudiantes sin plan</p>";

    echo "<table>";
    echo "<tr>
            <th>ID</th>
            <th>Nombre</th>
            <th>Email</th>
            <th>Username</th>
            <th>ID Number</th>
            <th>Fecha Creación</th>
            <th>Acciones</th>
          </tr>";

    $plans = $DB->get_records('local_learning_plans', null, 'name ASC');

    foreach ($students_no_plan as $student) {
        $created = date('Y-m-d H:i', $student->timecreated);
        echo "<tr class='warning'>";
        echo "<td>{$student->id}</td>";
        echo "<td>{$student->firstname} {$student->lastname}</td>";
        echo "<td>{$student->email}</td>";
        echo "<td>{$student->username}</td>";
        echo "<td>" . ($student->idnumber ?: 'N/A') . "</td>";
        echo "<td>$created</td>";
        echo "<td>";
        echo "<select id='plan_select_{$student->id}' style='padding: 5px; margin-right: 5px;'>";
        echo "<option value=''>Seleccionar Plan...</option>";
        foreach ($plans as $plan) {
            echo "<option value='{$plan->id}'>{$plan->name}</option>";
        }
        echo "</select>";
        echo "<button onclick='assignPlan({$student->id})' class='btn btn-success'>Asignar</button>";
        echo "</td>";
        echo "</tr>";
    }

    echo "</table>";

    echo "<script>
    function assignPlan(userid) {
        const selectId = 'plan_select_' + userid;
        const planid = document.getElementById(selectId).value;
        if (!planid) {
            alert('Por favor selecciona un plan de aprendizaje');
            return;
        }
        if (confirm('¿Confirmas asignar este plan al estudiante?')) {
            window.location.href = '?action=assign_plan&userid=' + userid + '&planid=' + planid;
        }
    }
    </script>";
}

echo "</div>";

// ========== ENROLLMENTS WITHOUT PLAN ==========
echo "<div class='section'>";
echo "<h2>📚 Matrículas en Cursos (Estudiantes sin Plan)</h2>";
echo "<p>Cursos en los que están matriculados estudiantes que no tienen plan de aprendizaje:</p>";

$sql = "SELECT c.id, c.fullname, c.shortname, COUNT(DISTINCT ue.userid) as student_count
        FROM {course} c
        JOIN {enrol} e ON e.courseid = c.id
        JOIN {user_enrolments} ue ON ue.enrolid = e.id
        JOIN {role_assignments} ra ON ra.userid = ue.userid
        LEFT JOIN {local_learning_users} llu ON llu.userid = ue.userid
        WHERE ra.roleid = :roleid
        AND llu.id IS NULL
        AND c.id != 1
        GROUP BY c.id, c.fullname, c.shortname
        HAVING COUNT(DISTINCT ue.userid) > 0
        ORDER BY student_count DESC";

$courses = $DB->get_records_sql($sql, ['roleid' => $student_role->id]);

if (empty($courses)) {
    echo "<div class='success' style='padding: 10px; border-radius: 5px;'>";
    echo "✅ No hay matrículas de estudiantes sin plan";
    echo "</div>";
} else {
    echo "<table>";
    echo "<tr><th>ID Curso</th><th>Nombre del Curso</th><th>Nombre Corto</th><th>Estudiantes Sin Plan</th></tr>";
    foreach ($courses as $course) {
        echo "<tr>";
        echo "<td>{$course->id}</td>";
        echo "<td>{$course->fullname}</td>";
        echo "<td>{$course->shortname}</td>";
        echo "<td class='warning'><strong>{$course->student_count}</strong></td>";
        echo "</tr>";
    }
    echo "</table>";
}

echo "</div>";

// ========== AVAILABLE PLANS ==========
echo "<div class='section'>";
echo "<h2>📋 Planes de Aprendizaje Disponibles</h2>";

$plans = $DB->get_records('local_learning_plans', null, 'name ASC');
$plan_stats = [];

foreach ($plans as $plan) {
    $student_count = $DB->count_records('local_learning_users', ['learningplanid' => $plan->id]);
    $plan_stats[$plan->id] = $student_count;
}

echo "<table>";
echo "<tr><th>ID</th><th>Nombre del Plan</th><th>Estudiantes Asignados</th></tr>";
foreach ($plans as $plan) {
    $count = $plan_stats[$plan->id];
    echo "<tr>";
    echo "<td>{$plan->id}</td>";
    echo "<td><strong>{$plan->name}</strong></td>";
    echo "<td>$count</td>";
    echo "</tr>";
}
echo "</table>";

echo "</div>";

// ========== ANALYSIS & RECOMMENDATIONS ==========
echo "<div class='section info'>";
echo "<h2>💡 Análisis y Recomendaciones</h2>";

if ($students_without_plan > 0) {
    $percentage = round(($students_without_plan / $total_students_with_role) * 100, 2);

    echo "<div style='padding: 15px; background: white; border-radius: 5px; margin: 10px 0;'>";
    echo "<h3>⚠️ Situación Actual</h3>";
    echo "<ul>";
    echo "<li><strong>{$percentage}%</strong> de los estudiantes no tienen plan de aprendizaje asignado</li>";
    echo "<li>Esto puede causar problemas en:</li>";
    echo "<ul>";
    echo "<li>El scheduler (no aparecerán en la demanda)</li>";
    echo "<li>Reportes de progreso académico</li>";
    echo "<li>Asignación de cursos por nivel/semestre</li>";
    echo "</ul>";
    echo "</ul>";
    echo "</div>";

    echo "<div style='padding: 15px; background: white; border-radius: 5px; margin: 10px 0;'>";
    echo "<h3>🔧 Pasos para Subsanar</h3>";
    echo "<ol>";
    echo "<li><strong>Identificar la fuente de integración:</strong> Revisa de dónde vienen estos usuarios (API, import, etc.)</li>";
    echo "<li><strong>Actualizar la integración:</strong> Asegúrate de que la integración asigne un plan al crear usuarios</li>";
    echo "<li><strong>Asignación manual (temporal):</strong> Usa esta página para asignar planes manualmente a los estudiantes existentes</li>";
    echo "<li><strong>Script de migración masiva:</strong> Si hay muchos usuarios, considera crear un script para asignación masiva</li>";
    echo "</ol>";
    echo "</div>";

    echo "<div style='padding: 15px; background: #fff3cd; border-radius: 5px; margin: 10px 0;'>";
    echo "<h3>📝 SQL para Asignación Masiva (Ejemplo)</h3>";
    echo "<p>Si todos los estudiantes sin plan deben ir al mismo plan, puedes usar:</p>";
    echo "<pre>";
    echo "-- Insertar en local_learning_users para todos los estudiantes sin plan\n";
    echo "INSERT INTO mdl_local_learning_users (userid, learningplanid, currentperiodid, timecreated, timemodified, usermodified)\n";
    echo "SELECT ra.userid, [PLAN_ID], 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP(), 2\n";
    echo "FROM mdl_role_assignments ra\n";
    echo "LEFT JOIN mdl_local_learning_users llu ON llu.userid = ra.userid\n";
    echo "WHERE ra.roleid = {$student_role->id}\n";
    echo "AND llu.id IS NULL;\n";
    echo "\n-- Reemplaza [PLAN_ID] con el ID del plan deseado";
    echo "</pre>";
    echo "<p><strong>⚠️ PRECAUCIÓN:</strong> Prueba primero en un entorno de desarrollo</p>";
    echo "</div>";

} else {
    echo "<div class='success' style='padding: 15px; border-radius: 5px;'>";
    echo "<h3>✅ Sistema en Orden</h3>";
    echo "<p>Todos los estudiantes tienen un plan de aprendizaje asignado. ¡Excelente trabajo!</p>";
    echo "</div>";
}

echo "</div>";

echo $OUTPUT->footer();
