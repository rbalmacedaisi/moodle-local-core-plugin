<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('grupomakro_core_manage_courses');

echo $OUTPUT->header();
global $DB;

$search = optional_param('search', '', PARAM_TEXT);

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
    .search-box { padding: 20px; background: #f8f9fa; border-radius: 8px; margin: 20px 0; }
    .issue { background-color: #f8d7da; font-weight: bold; }
    .ok { background-color: #d4edda; }
</style>";

echo "<h1>🔍 Debug: Visibilidad de Estudiantes en Panel Académico</h1>";

// ========== SEARCH FORM ==========
echo "<div class='search-box'>";
echo "<h2>🔎 Buscar Estudiante</h2>";
echo "<form method='get'>";
echo "<input type='text' name='search' value='" . htmlspecialchars($search) . "' placeholder='Cédula, nombre, email...' style='padding: 10px; width: 300px; font-size: 16px;'> ";
echo "<input type='submit' value='Buscar' style='padding: 10px 20px; font-size: 16px; cursor: pointer;'>";
echo "</form>";
echo "<p style='margin-top: 10px; color: #666;'><em>Ejemplo: 7-715-1653</em></p>";
echo "</div>";

if (!empty($search)) {
    echo "<div class='section'>";
    echo "<h2>📋 Resultados de Búsqueda: \"" . htmlspecialchars($search) . "\"</h2>";

    // Search users
    $sql = "SELECT u.id, u.username, u.firstname, u.lastname, u.email, u.idnumber, u.deleted, u.suspended, u.timecreated
            FROM {user} u
            WHERE (u.idnumber LIKE :search1
                   OR u.firstname LIKE :search2
                   OR u.lastname LIKE :search3
                   OR u.email LIKE :search4
                   OR u.username LIKE :search5)
            ORDER BY u.deleted ASC, u.id DESC";

    $search_param = '%' . $search . '%';
    $users = $DB->get_records_sql($sql, [
        'search1' => $search_param,
        'search2' => $search_param,
        'search3' => $search_param,
        'search4' => $search_param,
        'search5' => $search_param
    ]);

    if (empty($users)) {
        echo "<div class='warning' style='padding: 15px; border-radius: 5px;'>";
        echo "⚠️ No se encontraron usuarios con ese criterio de búsqueda.";
        echo "</div>";
    } else {
        echo "<p>Se encontraron <strong>" . count($users) . "</strong> usuario(s)</p>";

        foreach ($users as $user) {
            $statusClass = $user->deleted ? 'error' : ($user->suspended ? 'warning' : 'success');
            $statusText = $user->deleted ? '❌ ELIMINADO' : ($user->suspended ? '⏸️ SUSPENDIDO' : '✅ ACTIVO');

            echo "<div class='section $statusClass'>";
            echo "<h3>Usuario: {$user->firstname} {$user->lastname} ($statusText)</h3>";

            // Basic user info
            echo "<table>";
            echo "<tr><th>Campo</th><th>Valor</th></tr>";
            echo "<tr><td><strong>ID Usuario</strong></td><td>{$user->id}</td></tr>";
            echo "<tr><td><strong>Username</strong></td><td>{$user->username}</td></tr>";
            echo "<tr><td><strong>Email</strong></td><td>{$user->email}</td></tr>";
            echo "<tr><td><strong>ID Number (Cédula)</strong></td><td>" . ($user->idnumber ?: '<span class="issue">❌ NO TIENE</span>') . "</td></tr>";
            echo "<tr><td><strong>Deleted</strong></td><td>" . ($user->deleted ? '<span class="issue">❌ SÍ</span>' : '<span class="ok">✅ NO</span>') . "</td></tr>";
            echo "<tr><td><strong>Suspended</strong></td><td>" . ($user->suspended ? '<span class="issue">⚠️ SÍ</span>' : '<span class="ok">✅ NO</span>') . "</td></tr>";
            echo "<tr><td><strong>Fecha Creación</strong></td><td>" . date('Y-m-d H:i:s', $user->timecreated) . "</td></tr>";
            echo "</table>";

            // Check roles
            echo "<h4>🎭 Roles Asignados</h4>";
            $roles_sql = "SELECT r.id, r.shortname, r.name, c.id as contextid, c.contextlevel
                          FROM {role_assignments} ra
                          JOIN {role} r ON r.id = ra.roleid
                          JOIN {context} c ON c.id = ra.contextid
                          WHERE ra.userid = :userid";
            $roles = $DB->get_records_sql($roles_sql, ['userid' => $user->id]);

            if (empty($roles)) {
                echo "<div class='issue' style='padding: 10px; border-radius: 5px;'>";
                echo "❌ <strong>NO TIENE ROLES ASIGNADOS</strong> - Esto explica por qué no aparece como estudiante";
                echo "</div>";
            } else {
                echo "<table>";
                echo "<tr><th>Rol</th><th>Nombre</th><th>Context Level</th></tr>";
                foreach ($roles as $role) {
                    $context_level_text = $role->contextlevel == 10 ? 'Sistema' : ($role->contextlevel == 50 ? 'Curso' : "Level {$role->contextlevel}");
                    echo "<tr>";
                    echo "<td>{$role->shortname}</td>";
                    echo "<td>{$role->name}</td>";
                    echo "<td>$context_level_text</td>";
                    echo "</tr>";
                }
                echo "</table>";

                $is_student = false;
                foreach ($roles as $role) {
                    if ($role->shortname === 'student') {
                        $is_student = true;
                        break;
                    }
                }

                if (!$is_student) {
                    echo "<div class='warning' style='padding: 10px; border-radius: 5px; margin-top: 10px;'>";
                    echo "⚠️ <strong>NO TIENE ROL DE ESTUDIANTE</strong> - Esto puede afectar su visibilidad";
                    echo "</div>";
                }
            }

            // Check local_learning_users
            echo "<h4>📚 Registro en local_learning_users</h4>";
            $llu = $DB->get_record('local_learning_users', ['userid' => $user->id]);

            if (!$llu) {
                echo "<div class='issue' style='padding: 10px; border-radius: 5px;'>";
                echo "❌ <strong>NO TIENE REGISTRO EN local_learning_users</strong><br>";
                echo "Este es el problema principal. El estudiante NO aparecerá en el panel académico sin este registro.";
                echo "</div>";
            } else {
                echo "<table>";
                echo "<tr><th>Campo</th><th>Valor</th><th>Estado</th></tr>";

                $plan_ok = $llu->learningplanid > 0;
                $plan = null;
                if ($plan_ok) {
                    $plan = $DB->get_record('local_learning_plans', ['id' => $llu->learningplanid], 'id, name');
                }

                echo "<tr class='" . ($plan_ok ? 'ok' : 'issue') . "'>";
                echo "<td><strong>Learning Plan ID</strong></td>";
                echo "<td>{$llu->learningplanid}</td>";
                echo "<td>" . ($plan_ok ? "✅ {$plan->name}" : "❌ INVÁLIDO") . "</td>";
                echo "</tr>";

                $period_ok = $llu->currentperiodid > 0;
                $period = null;
                if ($period_ok) {
                    $period = $DB->get_record('gmk_academic_periods', ['id' => $llu->currentperiodid], 'id, name');
                }

                echo "<tr class='" . ($period_ok ? 'ok' : 'warning') . "'>";
                echo "<td><strong>Current Period ID</strong></td>";
                echo "<td>" . ($llu->currentperiodid ?: '0 (Sin período)') . "</td>";
                echo "<td>" . ($period_ok ? "✅ {$period->name}" : "⚠️ Sin período actual") . "</td>";
                echo "</tr>";

                echo "<tr>";
                echo "<td><strong>Fecha Creación</strong></td>";
                echo "<td colspan='2'>" . date('Y-m-d H:i:s', $llu->timecreated) . "</td>";
                echo "</tr>";

                echo "<tr>";
                echo "<td><strong>Última Modificación</strong></td>";
                echo "<td colspan='2'>" . date('Y-m-d H:i:s', $llu->timemodified) . "</td>";
                echo "</tr>";

                echo "</table>";

                echo "<h5>Registro Completo:</h5>";
                echo "<pre>" . print_r($llu, true) . "</pre>";
            }

            // Check course enrollments
            echo "<h4>📖 Matrículas en Cursos</h4>";
            $enrol_sql = "SELECT c.id, c.fullname, c.shortname, ue.timecreated, e.enrol
                          FROM {user_enrolments} ue
                          JOIN {enrol} e ON e.id = ue.enrolid
                          JOIN {course} c ON c.id = e.courseid
                          WHERE ue.userid = :userid
                          AND c.id != 1
                          ORDER BY ue.timecreated DESC
                          LIMIT 10";

            $enrollments = $DB->get_records_sql($enrol_sql, ['userid' => $user->id]);

            if (empty($enrollments)) {
                echo "<p>No tiene matrículas en cursos (además del Site)</p>";
            } else {
                echo "<table>";
                echo "<tr><th>ID</th><th>Curso</th><th>Método</th><th>Fecha Matrícula</th></tr>";
                foreach ($enrollments as $enrol) {
                    echo "<tr>";
                    echo "<td>{$enrol->id}</td>";
                    echo "<td>{$enrol->fullname} ({$enrol->shortname})</td>";
                    echo "<td>{$enrol->enrol}</td>";
                    echo "<td>" . date('Y-m-d H:i', $enrol->timecreated) . "</td>";
                    echo "</tr>";
                }
                echo "</table>";
            }

            // Check gmk_course_progre
            echo "<h4>📊 Progreso Académico (gmk_course_progre)</h4>";
            $progre_records = $DB->get_records('gmk_course_progre', ['userid' => $user->id], 'timemodified DESC', '*', 0, 10);

            if (empty($progre_records)) {
                echo "<p>No tiene registros en gmk_course_progre</p>";
            } else {
                echo "<p>Mostrando últimos " . count($progre_records) . " registros:</p>";
                echo "<table>";
                echo "<tr><th>ID</th><th>Course ID</th><th>Period ID</th><th>Status</th><th>Grade</th><th>Class ID</th></tr>";
                foreach ($progre_records as $prog) {
                    echo "<tr>";
                    echo "<td>{$prog->id}</td>";
                    echo "<td>{$prog->courseid}</td>";
                    echo "<td>{$prog->periodid}</td>";
                    echo "<td>{$prog->status}</td>";
                    echo "<td>{$prog->grade}</td>";
                    echo "<td>" . ($prog->classid ?: 'N/A') . "</td>";
                    echo "</tr>";
                }
                echo "</table>";
            }

            // DIAGNOSIS
            echo "<div class='section info'>";
            echo "<h4>🔍 DIAGNÓSTICO</h4>";

            $issues = [];
            $warnings = [];

            if ($user->deleted) {
                $issues[] = "Usuario está ELIMINADO (deleted=1)";
            }
            if ($user->suspended) {
                $warnings[] = "Usuario está SUSPENDIDO (suspended=1)";
            }
            if (empty($user->idnumber)) {
                $warnings[] = "No tiene ID Number (cédula)";
            }
            if (empty($roles)) {
                $issues[] = "No tiene roles asignados";
            } elseif (!$is_student) {
                $issues[] = "No tiene rol de 'student'";
            }
            if (!$llu) {
                $issues[] = "NO tiene registro en local_learning_users - CRÍTICO";
            } else {
                if (!$plan_ok) {
                    $issues[] = "Plan de aprendizaje inválido (ID: {$llu->learningplanid})";
                }
                if (!$period_ok) {
                    $warnings[] = "No tiene período actual asignado (currentperiodid = 0)";
                }
            }

            if (!empty($issues)) {
                echo "<div class='error' style='padding: 15px; border-radius: 5px; margin: 10px 0;'>";
                echo "<strong>❌ PROBLEMAS CRÍTICOS:</strong><ul>";
                foreach ($issues as $issue) {
                    echo "<li>$issue</li>";
                }
                echo "</ul></div>";
            }

            if (!empty($warnings)) {
                echo "<div class='warning' style='padding: 15px; border-radius: 5px; margin: 10px 0;'>";
                echo "<strong>⚠️ ADVERTENCIAS:</strong><ul>";
                foreach ($warnings as $warning) {
                    echo "<li>$warning</li>";
                }
                echo "</ul></div>";
            }

            if (empty($issues) && empty($warnings)) {
                echo "<div class='success' style='padding: 15px; border-radius: 5px; margin: 10px 0;'>";
                echo "✅ <strong>El usuario parece estar configurado correctamente.</strong><br>";
                echo "Si no aparece en el panel académico, puede ser un problema de caché o filtros aplicados.";
                echo "</div>";
            }

            echo "</div>";

            echo "</div>"; // End user section
        }
    }

    echo "</div>";
}

// ========== GENERAL STATISTICS ==========
echo "<div class='section'>";
echo "<h2>📊 Estadísticas del Sistema</h2>";

$stats = [];

// Total users
$stats['total_users'] = $DB->count_records('user', ['deleted' => 0]);

// Users with student role
$student_role = $DB->get_record('role', ['shortname' => 'student']);
if ($student_role) {
    $stats['users_with_student_role'] = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT userid) FROM {role_assignments} WHERE roleid = :roleid",
        ['roleid' => $student_role->id]
    );
}

// Users in local_learning_users
$stats['users_in_llu'] = $DB->count_records('local_learning_users');

// Users with period assigned
$stats['users_with_period'] = $DB->count_records_select('local_learning_users', 'currentperiodid > 0');

// Users without idnumber
$stats['users_without_idnumber'] = $DB->count_records_select('user', "idnumber IS NULL OR idnumber = '' AND deleted = 0");

echo "<table>";
echo "<tr><th>Métrica</th><th>Valor</th></tr>";
echo "<tr><td>Total usuarios activos</td><td>{$stats['total_users']}</td></tr>";
echo "<tr><td>Usuarios con rol 'student'</td><td>{$stats['users_with_student_role']}</td></tr>";
echo "<tr><td>Usuarios en local_learning_users</td><td>{$stats['users_in_llu']}</td></tr>";
echo "<tr><td>Usuarios con período actual asignado</td><td>{$stats['users_with_period']}</td></tr>";
echo "<tr><td>Usuarios sin ID Number (cédula)</td><td>{$stats['users_without_idnumber']}</td></tr>";
echo "</table>";

echo "</div>";

echo $OUTPUT->footer();
