<?php
/**
 * Página de diagnóstico detallada para el problema de exportación "(Sin curso activo)"
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
$PAGE->set_title('Diagnóstico de Datos de Progreso');
$PAGE->set_heading('Diagnóstico: Inspección Rápida de Progreso');

echo $OUTPUT->header();

global $DB;

// 1. Obtener el ID del rol de estudiante
$studentrole = $DB->get_record('role', ['shortname' => 'student'], 'id', MUST_EXIST);
$studentroleid = $studentrole->id;

echo html_writer::tag('h3', 'Listado General de Inscripciones y Progreso (Primeros 100)');

$sql = "SELECT lpu.userid, u.firstname, u.lastname, u.username, lpu.userrolename, lpu.userroleid, lp.id as lp_id, lp.name as lp_name,
               (SELECT COUNT(*) FROM {gmk_course_progre} gcp WHERE gcp.userid = lpu.userid AND gcp.learningplanid = lpu.learningplanid) as progre_count
        FROM {local_learning_users} lpu
        JOIN {user} u ON u.id = lpu.userid
        JOIN {local_learning_plans} lp ON lp.id = lpu.learningplanid
        ORDER BY lpu.timecreated DESC
        LIMIT 100";
$all_students = $DB->get_records_sql($sql);

$table = new html_table();
$table->head = ['User ID', 'Nombre', 'Usuario', 'Rol Name', 'Rol ID', 'Plan', 'Registros Progreso'];

foreach ($all_students as $s) {
    $count_style = $s->progre_count == 0 ? 'color:red; font-weight:bold;' : 'color:green;';
    $table->data[] = [
        $s->userid,
        fullname($s),
        $s->username,
        $s->userrolename,
        $s->userroleid,
        $s->lp_name,
        html_writer::tag('span', $s->progre_count, ['style' => $count_style])
    ];
}
echo html_writer::table($table);

echo $OUTPUT->footer();
