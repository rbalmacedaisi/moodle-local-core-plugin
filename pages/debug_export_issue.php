<?php
/**
 * Página de diagnóstico para el problema de exportación "(Sin curso activo)"
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

// Solo administradores o directores académicos (ajustar según sea necesario)
require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_export_issue.php'));
$PAGE->set_context($context);
$PAGE->set_title('Diagnóstico de Exportación');
$PAGE->set_heading('Diagnóstico: Estudiantes "(Sin curso activo)"');

echo $OUTPUT->header();

echo $OUTPUT->notification('Esta página ayuda a identificar por qué algunos estudiantes aparecen sin cursos en las exportaciones.', 'info');

global $DB;

// 1. Estudiantes inscritos en Planes de Aprendizaje pero sin registros en gmk_course_progre
$sql = "SELECT 
            lpu.id as lpuid,
            lpu.userid, 
            u.firstname, 
            u.lastname, 
            u.username,
            lp.id as planid, 
            lp.name as planname,
            lpu.userroleid,
            r.shortname as rolename,
            lpu.timecreated as enrollment_date,
            (SELECT COUNT(*) FROM {local_learning_courses} llc WHERE llc.learningplanid = lp.id) as course_count
        FROM {local_learning_users} lpu
        JOIN {user} u ON u.id = lpu.userid
        JOIN {local_learning_plans} lp ON lp.id = lpu.learningplanid
        JOIN {role} r ON r.id = lpu.userroleid
        LEFT JOIN {gmk_course_progre} gcp ON (gcp.userid = lpu.userid AND gcp.learningplanid = lpu.learningplanid)
        WHERE gcp.id IS NULL
        ORDER BY lpu.timecreated DESC";

$missing_students = $DB->get_records_sql($sql);

echo html_writer::tag('h3', 'Estudiantes con registros de progreso faltantes (gmk_course_progre)');

if (empty($missing_students)) {
    echo $OUTPUT->notification('No se encontraron estudiantes con registros faltantes en esta consulta.', 'success');
} else {
    $table = new html_table();
    $table->head = ['ID Usuario', 'Nombre', 'Username', 'Plan de Aprendizaje', 'Rol ID', 'Nombre Rol', 'Cursos en Plan', 'Fecha Inscripción', 'Posible Causa'];
    
    foreach ($missing_students as $s) {
        $cause = 'Desconocida';
        if ($s->userroleid != 5) {
            $cause = '<b>Rol incorrecto:</b> El sistema solo inicializa progreso para Rol ID 5 (student).';
        } else if ($s->course_count == 0) {
            $cause = '<b>Plan vacío:</b> El plan de aprendizaje no tiene cursos asociados.';
        } else {
            $cause = '<b>Fallo de evento:</b> El disparador no se ejecutó o falló silenciosamente.';
        }
        
        $table->data[] = [
            $s->userid,
            fullname($s),
            $s->username,
            $s->planname . " ({$s->planid})",
            $s->userroleid,
            $s->rolename,
            $s->course_count,
            userdate($s->enrollment_date),
            $cause
        ];
    }
    echo html_writer::table($table);
}

// 2. Verificación de Roles
echo html_writer::tag('h3', 'Verificación de Roles en el Sistema');
$roles = $DB->get_records('role', [], '', 'id, shortname, name');
$role_table = new html_table();
$role_table->head = ['ID', 'Shortname', 'Name'];
foreach ($roles as $r) {
    $row = [$r->id, $r->shortname, $r->name];
    if ($r->id == 5 && $r->shortname != 'student') {
        $row[1] = "<span style='color:red'>{$r->shortname} (¡Atención! Se esperaba 'student')</span>";
    }
    $role_table->data[] = $row;
}
echo html_writer::table($role_table);

echo $OUTPUT->footer();
