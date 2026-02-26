<?php
/**
 * Debug page for unassigned students
 * 
 * Identifies users who exist in Moodle but do not have a record in local_learning_users.
 */
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php'); // For sync_student_progress

admin_externalpage_setup('grupomakro_core_manage_courses');

// -------------------------------------------------------------
// AJAX Handler for Enrollment
// -------------------------------------------------------------
$ajax = optional_param('ajax', '', PARAM_ALPHA);
if ($ajax === 'enroll') {
    header('Content-Type: application/json');
    try {
        require_sesskey();
        
        $userid = required_param('userid', PARAM_INT);
        $planid = required_param('planid', PARAM_INT);
        $roleid = required_param('roleid', PARAM_INT); // student role ID

        global $DB;

        // 1. Check if user already enrolled
        if ($DB->record_exists('local_learning_users', ['userid' => $userid, 'learningplanid' => $planid])) {
            echo json_encode(['status' => 'error', 'message' => 'El estudiante ya está matriculado en este plan.']);
            die();
        }

        // 2. Insert into local_learning_users
        $record = new stdClass();
        $record->learningplanid = $planid;
        $record->userid = $userid;
        $record->userrolename = 'student';
        $record->timecreated = time();
        $record->timemodified = time();
        
        // Find default academic period (if any) or leave blank for now
        $academicperiod = $DB->get_record('gmk_academic_periods', ['status' => 1], '*', IGNORE_MULTIPLE);
        if ($academicperiod) {
            $record->academicperiodid = $academicperiod->id;
        }

        $DB->insert_record('local_learning_users', $record);

        // 3. Assign role in learning plan context
        $plan_context = context_module::instance($DB->get_field('course_modules', 'id', ['instance' => $planid, 'module' => $DB->get_field('modules', 'id', ['name' => 'learningplan'])]));
        
        if ($plan_context) {
             role_assign($roleid, $userid, $plan_context->id);
        } else {
             // Fallback to system or ignore if context not found (plugin specific logic)
        }

        // 4. Initialize progress grid
        if (function_exists('sync_student_progress')) {
            sync_student_progress($userid);
        }

        echo json_encode(['status' => 'success', 'message' => 'Estudiante matriculado y malla inicializada correctamente.']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    die();
}
// -------------------------------------------------------------

echo $OUTPUT->header();

echo "<h2>Diagnóstico: Estudiantes sin Plan de Aprendizaje</h2>";
echo "<div class='alert alert-info'>Esta página muestra los usuarios que existen en Moodle pero <strong>NO tienen ningún registro en la tabla <code>local_learning_users</code></strong>. Te ayudará a identificar los estudiantes creados vía API que no completaron su asociación al plan (e.g. matrícula).</div>";

$auth_filter = optional_param('auth_filter', '', PARAM_ALPHANUMEXT);
$days_filter = optional_param('days', 30, PARAM_INT);
$role_filter = optional_param('role', 'student', PARAM_ALPHA);

global $DB;

// Base query to find users NOT in local_learning_users
$sql_select = "SELECT u.id, u.username, u.firstname, u.lastname, u.email, u.idnumber, u.auth, u.timecreated, u.lastaccess ";
$sql_from = "FROM {user} u 
             LEFT JOIN {local_learning_users} llu ON u.id = llu.userid ";

$sql_where = "WHERE llu.id IS NULL AND u.deleted = 0 AND u.id > 2 ";
$params = [];

if ($auth_filter && $auth_filter !== 'all') {
    $sql_where .= " AND u.auth = :auth ";
    $params['auth'] = $auth_filter;
}

if ($days_filter > 0) {
    $cutoff = time() - ($days_filter * DAYSECS);
    $sql_where .= " AND u.timecreated >= :cutoff ";
    $params['cutoff'] = $cutoff;
}

// Subquery to exclude users who have editingteacher or teacher roles anywhere in the system
$sql_where .= " AND NOT EXISTS (
    SELECT 1 FROM {role_assignments} ra2 
    JOIN {role} r2 ON ra2.roleid = r2.id 
    WHERE ra2.userid = u.id AND r2.shortname IN ('editingteacher', 'teacher')
) ";

// Ensure they have the student role if filter is selected. 
// Standard student role in moodle is usually shortname 'student'
if ($role_filter === 'student') {
    // Get role ID for student
    $student_role = $DB->get_record('role', ['shortname' => 'student']);
    if ($student_role) {
        $sql_from .= " JOIN {role_assignments} ra ON ra.userid = u.id AND ra.roleid = :roleid ";
        $params['roleid'] = $student_role->id;
    }
}

$sql = $sql_select . $sql_from . $sql_where . " ORDER BY u.timecreated DESC LIMIT 1000";
$users = $DB->get_records_sql($sql, $params);

// Get distinct auth methods for filter
$auth_methods = $DB->get_records_sql_menu("SELECT DISTINCT auth, auth AS name FROM {user} WHERE deleted = 0 AND id > 2");

// Filters Form UI
echo "<div class='bg-light p-3 border mb-4 rounded'>";
echo "<form method='get' class='form-inline d-flex align-items-center flex-wrap' style='gap: 15px;'>";

echo "<div>";
echo "<label class='font-weight-bold mr-2'>Método Auth:</label>";
echo "<select name='auth_filter' class='form-control form-control-sm' onchange='this.form.submit()'>";
echo "<option value='all' " . ($auth_filter === 'all' || $auth_filter === '' ? 'selected' : '') . ">Todos</option>";
foreach ($auth_methods as $method) {
    $selected = ($auth_filter === $method) ? 'selected' : '';
    echo "<option value='$method' $selected>$method</option>";
}
echo "</select>";
echo "</div>";

echo "<div>";
echo "<label class='font-weight-bold mr-2'>Rol Moodle:</label>";
echo "<select name='role' class='form-control form-control-sm' onchange='this.form.submit()'>";
echo "<option value='all' " . ($role_filter === 'all' ? 'selected' : '') . ">Cualquiera</option>";
echo "<option value='student' " . ($role_filter === 'student' ? 'selected' : '') . ">Solo rol 'student'</option>";
echo "</select>";
echo "</div>";

echo "<div>";
echo "<label class='font-weight-bold mr-2'>Creados hace (días):</label>";
echo "<input type='number' name='days' value='$days_filter' class='form-control form-control-sm' style='width: 70px;' min='0'> ";
echo "<small class='text-muted'>(0 = sin límite)</small>";
echo "</div>";

echo "<button type='submit' class='btn btn-primary btn-sm'>Aplicar Filtros</button>";
echo "</form>";
echo "</div>";

if (empty($users)) {
    echo "<div class='alert alert-success mt-3'>No se encontraron usuarios sin plan de aprendizaje bajo estos filtros.</div>";
} else {
    echo "<div class='alert alert-warning mt-3'>Se localizaron <strong>" . count($users) . "</strong> usuarios sin plan asociado (mostrando hasta 1000).</div>";
    
    echo "<div class='table-responsive mt-3'>";
    echo "<table class='table table-bordered table-striped table-hover'>";
    echo "<thead class='thead-dark'><tr>
            <th>ID Moodle</th>
            <th>ID Number</th>
            <th>Email</th>
            <th>Nombre Completo</th>
            <th>Autenticación</th>
            <th>Fecha Registro</th>
            <th><i class='fa fa-graduation-cap'></i> Matricular a Plan</th>
            <th>Acciones</th>
          </tr></thead>";
    echo "<tbody>";

    // Fetch learning plans for dropdown
    $learning_plans = $DB->get_records('local_learning_plans', ['active' => 1], 'name ASC', 'id, name');
    
    // Get student role id
    $student_role = $DB->get_record('role', ['shortname' => 'student']);
    $roleid = $student_role ? $student_role->id : 5;

    foreach ($users as $u) {
        $profileurl = new moodle_url('/user/profile.php', ['id' => $u->id]);
        
        echo "<tr id='row_{$u->id}'>";
        echo "<td>{$u->id}</td>";
        echo "<td>" . ($u->idnumber ? "<strong>{$u->idnumber}</strong>" : '<span class="text-muted">No tiene</span>') . "</td>";
        echo "<td>{$u->email}</td>";
        echo "<td>{$u->firstname} {$u->lastname}</td>";
        echo "<td><span class='badge badge-secondary'>{$u->auth}</span></td>";
        echo "<td>" . userdate($u->timecreated, '%d/%m/%Y') . "</td>";
        
        // Enrollment cell
        echo "<td>";
        echo "<div class='d-flex align-items-center' style='gap:5px;'>";
        echo "<select id='plan_{$u->id}' class='form-control form-control-sm' style='max-width:200px;'>";
        echo "<option value=''>-- Seleccione Plan --</option>";
        foreach ($learning_plans as $lp) {
            echo "<option value='{$lp->id}'>" . shorten_text($lp->name, 40) . "</option>";
        }
        echo "</select>";
        echo "<button class='btn btn-sm btn-success' onclick='enrollStudent({$u->id}, {$roleid})'><i class='fa fa-plus'></i> Matricular</button>";
        echo "</div>";
        echo "</td>";

        echo "<td><a href='{$profileurl}' target='_blank' class='btn btn-sm btn-info'>Perfil</a></td>";
        echo "</tr>";
    }
    echo "</tbody></table>";
    echo "</div>";
    
    // JS for enrollment
    echo "<script>
    function enrollStudent(userid, roleid) {
        const planSelect = document.getElementById('plan_' + userid);
        const planid = planSelect.value;
        if (!planid) {
            alert('Por favor, selecciona un plan de aprendizaje.');
            return;
        }

        const btn = event.target.closest('button');
        btn.disabled = true;
        btn.innerHTML = '<i class=\"fa fa-spinner fa-spin\"></i>';

        fetch('debug_unassigned_students.php?ajax=enroll&sesskey=" . sesskey() . "&userid=' + userid + '&planid=' + planid + '&roleid=' + roleid, {
            method: 'POST'
        })
        .then(response => response.json())
        .then(data => {
            if (data.status === 'success') {
                const row = document.getElementById('row_' + userid);
                row.style.backgroundColor = '#d4edda';
                const cell = btn.closest('td');
                cell.innerHTML = '<span class=\"text-success\"><i class=\"fa fa-check\"></i> Matriculado</span>';
            } else {
                alert('Error: ' + data.message);
                btn.disabled = false;
                btn.innerHTML = '<i class=\"fa fa-plus\"></i> Matricular';
            }
        })
        .catch(error => {
            alert('Error de red: ' + error);
            btn.disabled = false;
            btn.innerHTML = '<i class=\"fa fa-plus\"></i> Matricular';
        });
    }
    </script>";
}

echo $OUTPUT->footer();
