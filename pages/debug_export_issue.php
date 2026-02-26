<?php
/**
 * Página de diagnóstico para el problema de exportación "(Sin curso activo)"
 * Versión enfocado estrictamente en ESTUDIANTES.
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/enrollib.php');

// Solo administradores o directores académicos
require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_export_issue.php'));
$PAGE->set_context($context);
$PAGE->set_title('Diagnóstico de Exportación - Estudiantes');
$PAGE->set_heading('Diagnóstico: Estudiantes con "(Sin curso activo)"');

echo $OUTPUT->header();

global $DB;

// 1. Obtener el ID del rol de estudiante
$studentrole = $DB->get_record('role', ['shortname' => 'student'], 'id', MUST_EXIST);
$studentroleid = $studentrole->id;

echo $OUTPUT->notification("Buscando fallos solo para el Rol ID: $studentroleid (student).", 'info');

// 2. Estudiantes (Rol ID 5) inscritos en Planes de Aprendizaje pero sin registros en gmk_course_progre
$sql = "SELECT 
            lpu.id as lpuid,
            lpu.userid, 
            u.firstname, 
            u.lastname, 
            u.username,
            lp.id as planid, 
            lp.name as planname,
            lpu.timecreated as enrollment_date
        FROM {local_learning_users} lpu
        JOIN {user} u ON u.id = lpu.userid
        JOIN {local_learning_plans} lp ON lp.id = lpu.learningplanid
        LEFT JOIN {gmk_course_progre} gcp ON (gcp.userid = lpu.userid AND gcp.learningplanid = lpu.learningplanid)
        WHERE lpu.userroleid = :roleid 
          AND gcp.id IS NULL
        ORDER BY lpu.timecreated DESC";

$missing_students = $DB->get_records_sql($sql, ['roleid' => $studentroleid]);

echo html_writer::tag('h3', 'Estudiantes sin registros de progreso (gmk_course_progre)');

if (empty($missing_students)) {
    echo $OUTPUT->notification('No se encontraron estudiantes con registros faltantes para el rol "student".', 'success');
} else {
    $table = new html_table();
    $table->head = [
        'ID Usuario', 
        'Nombre Completo', 
        'Username', 
        'Plan (ID)', 
        'Cursos en Plan', 
        'Matriculado en Moodle?', 
        'Fecha LP Enroll', 
        'Diagnóstico sugerido'
    ];
    
    foreach ($missing_students as $s) {
        // Obtener cursos del plan
        $plan_courses = $DB->get_records('local_learning_courses', ['learningplanid' => $s->planid], '', 'courseid');
        $course_count = count($plan_courses);
        
        $moodle_enrolled_count = 0;
        if ($course_count > 0) {
            foreach ($plan_courses as $pc) {
                if (is_enrolled(context_course::instance($pc->courseid), $s->userid)) {
                    $moodle_enrolled_count++;
                }
            }
        }
        
        $diag = "Desconocido";
        $enrolled_status = "N/A (Sin cursos)";
        
        if ($course_count == 0) {
            $diag = "<span style='color:orange'>Plan sin materias. No hay nada que exportar.</span>";
            $enrolled_status = "0";
        } else if ($moodle_enrolled_count == 0) {
            $diag = "<span style='color:red'><b>ERROR CRÍTICO:</b> Inscrito en LP pero NO matriculado en los cursos de Moodle. Falló `enrol_user_in_learningplan_courses`.</span>";
            $enrolled_status = "<span style='color:red'>NO (0/$course_count)</span>";
        } else if ($moodle_enrolled_count < $course_count) {
            $diag = "<span style='color:orange'>Inscripción parcial en Moodle. Faltan materias por enrolar.</span>";
            $enrolled_status = "Parcial ($moodle_enrolled_count/$course_count)";
        } else {
            $diag = "<span style='color:blue'>Matriculado en Moodle pero sin `gmk_course_progre`. Falló el trigger `learningplanuser_added`.</span>";
            $enrolled_status = "SÍ ($moodle_enrolled_count/$course_count)";
        }
        
        $table->data[] = [
            $s->userid,
            fullname($s),
            $s->username,
            $s->planname . " ({$s->planid})",
            $course_count,
            $enrolled_status,
            userdate($s->enrollment_date),
            $diag
        ];
    }
    echo html_writer::table($table);
}

// 3. Resumen de cursos del plan para un caso de ejemplo (parámetro opcional)
$sample_userid = optional_param('checkuser', 0, PARAM_INT);
$sample_planid = optional_param('checkplan', 0, PARAM_INT);

if ($sample_userid && $sample_planid) {
    echo html_writer::tag('h3', "Análisis Detallado para Usuario $sample_userid en Plan $sample_planid");
    // ... (Podría añadir más detalle aquí si el usuario lo requiere)
}

echo $OUTPUT->footer();
