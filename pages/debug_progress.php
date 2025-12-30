<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context); // Only for super admins

$userid = optional_param('userid', 0, PARAM_INT);
$docnumber = optional_param('docnumber', '', PARAM_RAW);
$planid = optional_param('planid', 0, PARAM_INT);

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_progress.php'));
$PAGE->set_context($context);
$PAGE->set_title("Debug de Progreso Académico");
$PAGE->set_heading("Diagnóstico de Cálculo de Progreso");

echo $OUTPUT->header();

echo "<h2>Herramienta de Diagnóstico</h2>";
echo "<form method='get'>
        Número de Documento: <input type='text' name='docnumber' value='$docnumber'>
        o User ID: <input type='text' name='userid' value='$userid'>
        Plan ID (opcional): <input type='text' name='planid' value='$planid'>
        <input type='submit' value='Analizar'>
      </form><hr>";

if (!empty($docnumber) && $userid == 0) {
    global $DB;
    $field = $DB->get_record('user_info_field', array('shortname' => 'documentnumber'));
    if ($field) {
        $sql = "SELECT userid FROM {user_info_data} WHERE fieldid = :fieldid AND " . $DB->sql_compare_text('data') . " = :docnumber";
        $data = $DB->get_record_sql($sql, array('fieldid' => $field->id, 'docnumber' => (string)$docnumber));
        if ($data) {
            $userid = $data->userid;
        } else {
            // Try idnumber as fallback
            $user = $DB->get_record('user', array('idnumber' => $docnumber));
            if ($user) {
                $userid = $user->id;
            }
        }
    }
    if ($userid == 0) {
        echo "<div style='color:red'>No se encontró ningún usuario con el documento: $docnumber</div>";
    }
}

if ($userid > 0) {
    global $DB;

    $user = $DB->get_record('user', array('id' => $userid));
    if (!$user) {
        echo "<div style='color:red'>Usuario no encontrado.</div>";
    } else {
        echo "<h3>Datos del Usuario: $user->firstname $user->lastname ($user->email)</h3>";
        
        // 1. Learning Plan Enrollment
        $enrollments = $DB->get_records('local_learning_users', array('userid' => $userid));
        echo "<h4>1. Inscripciones en Planes de Aprendizaje (local_learning_users)</h4>";
        if (!$enrollments) {
            echo "<p>El usuario no está inscrito en ningún plan.</p>";
        } else {
            echo "<table border='1' cellpadding='5'><tr><th>Plan ID</th><th>Rol</th><th>Periodo Actual</th></tr>";
            foreach ($enrollments as $enrol) {
                $style = ($planid > 0 && $enrol->learningplanid == $planid) ? "style='background:#ffffcc'" : "";
                echo "<tr $style><td>$enrol->learningplanid</td><td>$enrol->userrolename</td><td>$enrol->currentperiodid</td></tr>";
            }
            echo "</table>";
        }

        // 2. Progress Records (gmk_course_progre)
        echo "<h4>2. Registros de Progreso (gmk_course_progre)</h4>";
        $conditions = array('userid' => $userid);
        if ($planid > 0) $conditions['learningplanid'] = $planid;
        
        $progressRecords = $DB->get_records('gmk_course_progre', $conditions, 'learningplanid, courseid');
        
        if (!$progressRecords) {
            echo "<p style='color:orange'>No hay registros en gmk_course_progre para este usuario.</p>";
        } else {
            echo "<table border='1' cellpadding='5' style='width:100%'>
                    <tr style='background:#eee'>
                        <th>Plan</th>
                        <th>Course ID</th>
                        <th>Nombre Curso</th>
                        <th>Créditos</th>
                        <th>Progreso (%)</th>
                        <th>Estado</th>
                        <th>Moodle Completion</th>
                        <th>Nota (Gradebook)</th>
                    </tr>";
            
            $totalCredits = 0;
            $totalWeighted = 0;
            $currentLP = 0;

            foreach ($progressRecords as $rec) {
                $course = $DB->get_record('course', array('id' => $rec->courseid), 'id, fullname');
                $cinfo = new \completion_info($course);
                $isComplete = $cinfo->is_course_complete($userid);
                
                $grade = grade_get_course_grade($userid, $rec->courseid);
                $strGrade = $grade ? $grade->str_grade : '-';

                $warning = ($rec->credits <= 0) ? "style='color:red; font-weight:bold'" : "";
                
                $completionEnabled = $cinfo->is_enabled();
                $isComplete = $cinfo->is_course_complete($userid);
                $internalComplete = ($rec->status == 3 || $rec->status == 4 || $rec->progress >= 100);
                
                $rawRecord = $DB->get_record('course_completions', ['course' => $rec->courseid, 'userid' => $userid]);
                $completionDetail = "No hay registro en course_completions";
                if ($rawRecord) {
                    $fields = [];
                    foreach ($rawRecord as $k => $v) {
                        if ($k == 'timecompleted' || $k == 'reaggregate' || $k == 'timeenrolled' || $k == 'timestarted') {
                             $v = $v ? date('Y-m-d H:i', $v) : 0;
                        }
                        $fields[] = "<b>$k:</b> $v";
                    }
                    $completionDetail = implode(' | ', $fields);
                }
                
                $completionText = $isComplete ? 'COMPLETO' : 'PENDIENTE';
                if (!$completionEnabled) {
                    $completionText .= ' (Deshabilitada)';
                    $bgColor = '#eee';
                } else {
                    $bgColor = $isComplete ? "#d4edda" : "#f8d7da";
                }
                
                echo "<tr>
                        <td>$rec->learningplanid</td>
                        <td>$rec->courseid</td>
                        <td>" . ($course ? $course->fullname : "???") . "</td>
                        <td $warning>$rec->credits</td>
                        <td>$rec->progress</td>
                        <td>$rec->status</td>
                        <td style='background:$bgColor'>$completionText<br><small>$completionDetail</small></td>
                        <td>$strGrade</td>
                      </tr>";
                
                $totalCredits += $rec->credits;
                $totalWeighted += ($internalComplete ? 1 : 0) * $rec->credits;
            }
            echo "</table>";

            if ($totalCredits > 0) {
                $calc = round(($totalWeighted / $totalCredits) * 100);
                echo "<div style='margin-top:10px; padding:10px; background:#e1f5fe'>
                        <strong>Cálculo Realizado por API (Overview):</strong><br>
                        Suma Ponderada: $totalWeighted<br>
                        Total Créditos: $totalCredits<br>
                        Resultado: $calc%
                      </div>";
            } else {
                echo "<p style='color:red'><strong>Error:</strong> Total de créditos es 0. El progreso siempre será 0% o causará errores.</p>";
            }
        }
    }
}

echo $OUTPUT->footer();
