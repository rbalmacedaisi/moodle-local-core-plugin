<?php
/**
 * Diagnostic Report: Grade Discrepancies
 * This script identifies students whose grades in gmk_course_progre don't match Moodle or are missing.
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/gradelib.php');

admin_externalpage_setup('grupomakro_core_import_grades');

if (!is_siteadmin()) {
    print_error('onlyadmins', 'error');
}

$action = optional_param('action', '', PARAM_TEXT);

if ($action === 'download_csv') {
    $filename = 'reporte_discrepancia_notas_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $fp = fopen('php://output', 'w');
    fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM
    
    fputcsv($fp, ['UserID', 'Username', 'Nombre', 'PlanID', 'Plan', 'CourseID', 'Shortname', 'Nota Moodle', 'Nota GMK', 'Estado GMK', 'Nota OK?']);
    
    $studentsWithPlans = $DB->get_records('local_learning_users', ['userroleid' => 5]); // Role 5 = student
    
    foreach ($studentsWithPlans as $slp) {
        $user = $DB->get_record('user', ['id' => $slp->userid], 'id, username, firstname, lastname');
        if (!$user) continue;
        
        $plan = $DB->get_record('local_learning_plans', ['id' => $slp->learningplanid], 'id, name');
        
        // Get all courses for this plan
        $courses = $DB->get_records('local_learning_courses', ['learningplanid' => $slp->learningplanid]);
        
        foreach ($courses as $lpc) {
            $course = $DB->get_record('course', ['id' => $lpc->courseid], 'id, shortname');
            if (!$course) continue;
            
            // 1. Get Moodle Grade
            $gradeObj = grade_get_course_grade($user->id, $course->id);
            $moodleGrade = ($gradeObj && isset($gradeObj->grade)) ? round((float)$gradeObj->grade, 2) : 0;
            
            // 2. Get GMK Grade
            $gmkProgre = $DB->get_record('gmk_course_progre', [
                'userid' => $user->id, 
                'courseid' => $course->id, 
                'learningplanid' => $slp->learningplanid
            ]);
            
            $gmkGrade = $gmkProgre ? round((float)$gmkProgre->grade, 2) : 'FALTANTE';
            $gmkStatus = $gmkProgre ? $gmkProgre->status : 'FALTANTE';
            
            $isMatching = ($gmkProgre && abs($moodleGrade - $gmkGrade) < 0.01) ? 'SI' : 'NO';
            
            // Skip if both are 0 and record exists (noisy) - Optional
            // if ($moodleGrade == 0 && $gmkGrade == 0 && $gmkProgre) continue;
            
            fputcsv($fp, [
                $user->id,
                $user->username,
                $user->firstname . ' ' . $user->lastname,
                $plan->id,
                $plan->name,
                $course->id,
                $course->shortname,
                $moodleGrade,
                $gmkGrade,
                $gmkStatus,
                $isMatching
            ]);
        }
    }
    
    fclose($fp);
    die;
}

echo $OUTPUT->header();
echo $OUTPUT->heading('Reporte de Discrepancia de Notas');
echo '<p>Este reporte analiza todos los estudiantes en planes de aprendizaje y compara sus notas finales en Moodle con los registros de la tabla grupomakro (`gmk_course_progre`).</p>';
echo '<p><b>Útil para:</b> Identificar qué notas no se cargaron correctamente durante la importación masiva.</p>';

echo '<div class="alert alert-info py-4 text-center">';
echo '<a href="?action=download_csv" class="btn btn-primary btn-lg"><i class="fa fa-download"></i> Descargar Reporte Completo (CSV)</a>';
echo '</div>';

echo $OUTPUT->footer();
