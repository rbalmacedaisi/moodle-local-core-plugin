<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$classid = optional_param('classid', 0, PARAM_INT);

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_schedule_approval.php'));
$PAGE->set_context($context);
$PAGE->set_title('Debug Schedule Approval');
$PAGE->set_heading('Debug Schedule Approval');

echo $OUTPUT->header();

if (!$classid) {
    echo "<h3>Ingrese un Class ID para diagnosticar</h3>";
    echo "<form method='get'>";
    echo "<input type='number' name='classid' placeholder='Class ID' required>";
    echo "<button type='submit'>Diagnosticar</button>";
    echo "</form>";
    echo $OUTPUT->footer();
    exit;
}

echo "<h2>Diagnóstico de Aprobación de Horario - Class ID: $classid</h2>";

// PASO 1: Verificar que la clase existe
echo "<h3>PASO 1: Verificar Clase</h3>";
$class = $DB->get_record('gmk_class', ['id' => $classid]);
if (!$class) {
    echo "<p style='color:red'>❌ ERROR: La clase $classid no existe</p>";
    echo $OUTPUT->footer();
    exit;
}

echo "<pre>";
echo "✅ Clase encontrada:\n";
echo "   ID: {$class->id}\n";
echo "   Course ID: {$class->corecourseid}\n";
echo "   Group ID: {$class->groupid}\n";
echo "   Learning Plan ID: {$class->learningplanid}\n";
echo "   Approved: " . ($class->approved ? 'Sí' : 'No') . "\n";
echo "</pre>";

// PASO 2: Obtener estudiantes pre-registrados
echo "<h3>PASO 2: Estudiantes Pre-Registrados</h3>";
$preRegisteredStudents = $DB->get_records('gmk_class_pre_registration', ['classid' => $classid]);
echo "<p>Total: <strong>" . count($preRegisteredStudents) . "</strong></p>";

if (count($preRegisteredStudents) > 0) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>User ID</th><th>Nombre</th><th>Email</th><th>Tiene Progress Record?</th><th>Status en Progress</th></tr>";
    foreach ($preRegisteredStudents as $student) {
        $user = $DB->get_record('user', ['id' => $student->userid]);
        $progressRecord = $DB->get_record('gmk_course_progre', [
            'userid' => $student->userid,
            'courseid' => $class->corecourseid,
            'learningplanid' => $class->learningplanid
        ]);

        $hasProgress = $progressRecord ? '✅ Sí' : '❌ No';
        $status = $progressRecord ? $progressRecord->status : 'N/A';

        echo "<tr>";
        echo "<td>{$student->userid}</td>";
        echo "<td>{$user->firstname} {$user->lastname}</td>";
        echo "<td>{$user->email}</td>";
        echo "<td>$hasProgress</td>";
        echo "<td>$status</td>";
        echo "</tr>";
    }
    echo "</table>";
}

// PASO 3: Obtener estudiantes en cola
echo "<h3>PASO 3: Estudiantes en Cola (Queue)</h3>";
$queuedStudents = $DB->get_records('gmk_class_queue', ['classid' => $classid]);
echo "<p>Total: <strong>" . count($queuedStudents) . "</strong></p>";

if (count($queuedStudents) > 0) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>User ID</th><th>Nombre</th><th>Email</th><th>Tiene Progress Record?</th><th>Status en Progress</th></tr>";
    foreach ($queuedStudents as $student) {
        $user = $DB->get_record('user', ['id' => $student->userid]);
        $progressRecord = $DB->get_record('gmk_course_progre', [
            'userid' => $student->userid,
            'courseid' => $class->corecourseid,
            'learningplanid' => $class->learningplanid
        ]);

        $hasProgress = $progressRecord ? '✅ Sí' : '❌ No';
        $status = $progressRecord ? $progressRecord->status : 'N/A';

        echo "<tr>";
        echo "<td>{$student->userid}</td>";
        echo "<td>{$user->firstname} {$user->lastname}</td>";
        echo "<td>{$user->email}</td>";
        echo "<td>$hasProgress</td>";
        echo "<td>$status</td>";
        echo "</tr>";
    }
    echo "</table>";
}

// PASO 4: Total de estudiantes que deberían enrollarse
$totalStudents = count($preRegisteredStudents) + count($queuedStudents);
echo "<h3>PASO 4: Total de Estudiantes a Enrollar</h3>";
echo "<p><strong>$totalStudents</strong> estudiantes (Pre-registrados + Cola)</p>";

// PASO 5: Verificar estudiantes ya enrollados en el grupo
echo "<h3>PASO 5: Estudiantes Actualmente en el Grupo Moodle</h3>";
$groupMembers = $DB->get_records('groups_members', ['groupid' => $class->groupid]);
echo "<p>Total: <strong>" . count($groupMembers) . "</strong></p>";

if (count($groupMembers) > 0) {
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>User ID</th><th>Nombre</th><th>Email</th><th>¿Estaba Pre-registrado?</th></tr>";
    foreach ($groupMembers as $member) {
        $user = $DB->get_record('user', ['id' => $member->userid]);
        $wasPreRegistered = $DB->record_exists('gmk_class_pre_registration', ['classid' => $classid, 'userid' => $member->userid]);
        $wasQueued = $DB->record_exists('gmk_class_queue', ['classid' => $classid, 'userid' => $member->userid]);

        $origin = $wasPreRegistered ? '✅ Pre-registrado' : ($wasQueued ? '✅ Cola' : '❌ Otro origen');

        echo "<tr>";
        echo "<td>{$member->userid}</td>";
        echo "<td>{$user->firstname} {$user->lastname}</td>";
        echo "<td>{$user->email}</td>";
        echo "<td>$origin</td>";
        echo "</tr>";
    }
    echo "</table>";
}

// PASO 6: Verificar enrollment en el curso Moodle
echo "<h3>PASO 6: Estudiantes Enrollados en el Curso Moodle</h3>";
$courseContext = context_course::instance($class->corecourseid);
$enrolledUsers = get_enrolled_users($courseContext, 'mod/assignment:submit');
echo "<p>Total: <strong>" . count($enrolledUsers) . "</strong></p>";

// PASO 7: Análisis de discrepancias
echo "<h3>PASO 7: Análisis de Discrepancias</h3>";
$allStudentsToEnroll = array_merge($preRegisteredStudents, $queuedStudents);
$missingFromGroup = [];
$missingFromCourse = [];

foreach ($allStudentsToEnroll as $student) {
    $inGroup = $DB->record_exists('groups_members', ['groupid' => $class->groupid, 'userid' => $student->userid]);
    $inCourse = false;
    foreach ($enrolledUsers as $enrolledUser) {
        if ($enrolledUser->id == $student->userid) {
            $inCourse = true;
            break;
        }
    }

    if (!$inGroup) {
        $user = $DB->get_record('user', ['id' => $student->userid]);
        $missingFromGroup[] = "{$user->firstname} {$user->lastname} (ID: {$student->userid})";
    }

    if (!$inCourse) {
        $user = $DB->get_record('user', ['id' => $student->userid]);
        $missingFromCourse[] = "{$user->firstname} {$user->lastname} (ID: {$student->userid})";
    }
}

if (count($missingFromGroup) > 0) {
    echo "<p style='color:red'>❌ <strong>" . count($missingFromGroup) . " estudiantes NO se agregaron al grupo:</strong></p>";
    echo "<ul>";
    foreach ($missingFromGroup as $missing) {
        echo "<li>$missing</li>";
    }
    echo "</ul>";
} else {
    echo "<p style='color:green'>✅ Todos los estudiantes están en el grupo</p>";
}

if (count($missingFromCourse) > 0) {
    echo "<p style='color:red'>❌ <strong>" . count($missingFromCourse) . " estudiantes NO se enrollaron en el curso:</strong></p>";
    echo "<ul>";
    foreach ($missingFromCourse as $missing) {
        echo "<li>$missing</li>";
    }
    echo "</ul>";
} else {
    echo "<p style='color:green'>✅ Todos los estudiantes están enrollados en el curso</p>";
}

// PASO 8: Verificar registros en gmk_course_progre
echo "<h3>PASO 8: Verificar Registros en gmk_course_progre</h3>";
$missingProgress = [];
foreach ($allStudentsToEnroll as $student) {
    $progressRecord = $DB->get_record('gmk_course_progre', [
        'userid' => $student->userid,
        'courseid' => $class->corecourseid,
        'learningplanid' => $class->learningplanid
    ]);

    if (!$progressRecord) {
        $user = $DB->get_record('user', ['id' => $student->userid]);
        $missingProgress[] = "{$user->firstname} {$user->lastname} (ID: {$student->userid})";
    } else {
        // Check if classid and groupid were assigned
        if ($progressRecord->classid != $class->id || $progressRecord->groupid != $class->groupid) {
            $user = $DB->get_record('user', ['id' => $student->userid]);
            echo "<p style='color:orange'>⚠️ <strong>{$user->firstname} {$user->lastname}</strong> tiene progress record pero classid/groupid no coinciden:</p>";
            echo "<ul>";
            echo "<li>Progress classid: {$progressRecord->classid} (Esperado: {$class->id})</li>";
            echo "<li>Progress groupid: {$progressRecord->groupid} (Esperado: {$class->groupid})</li>";
            echo "</ul>";
        }
    }
}

if (count($missingProgress) > 0) {
    echo "<p style='color:red'>❌ <strong>" . count($missingProgress) . " estudiantes NO tienen registro en gmk_course_progre:</strong></p>";
    echo "<ul>";
    foreach ($missingProgress as $missing) {
        echo "<li>$missing</li>";
    }
    echo "</ul>";
} else {
    echo "<p style='color:green'>✅ Todos los estudiantes tienen registro en gmk_course_progre</p>";
}

// PASO 9: Posibles Causas y Soluciones
echo "<h3>PASO 9: Posibles Causas del Problema</h3>";
echo "<div style='border: 1px solid #ccc; padding: 10px; background-color: #f9f9f9;'>";
echo "<p><strong>La función enrolApprovedScheduleStudents() hace lo siguiente:</strong></p>";
echo "<ol>";
echo "<li>Enrolla al estudiante en el curso Moodle con rol de estudiante</li>";
echo "<li>Agrega al estudiante al grupo usando <code>groups_add_member()</code></li>";
echo "<li>Si el paso 2 es exitoso, llama a <code>assign_class_to_course_progress()</code></li>";
echo "</ol>";

echo "<p><strong>Posibles razones por las que algunos estudiantes no se enrollaron:</strong></p>";
echo "<ul>";
echo "<li>❌ <code>groups_add_member()</code> falló silenciosamente (retornó false)</li>";
echo "<li>❌ El estudiante ya estaba en el grupo (groups_add_member retorna false si ya existe)</li>";
echo "<li>❌ El estudiante no se pudo enrollar en el curso Moodle primero</li>";
echo "<li>❌ Error en get_manual_enroll() - no se encontró instancia manual de enrollment</li>";
echo "<li>❌ El estudiante no tiene un learning plan válido</li>";
echo "</ul>";

echo "<p><strong>Verificar en logs de Moodle:</strong></p>";
echo "<ul>";
echo "<li>Ir a: Site administration → Reports → Logs</li>";
echo "<li>Filtrar por: User (seleccionar los estudiantes que faltaron), Course (curso {$class->corecourseid})</li>";
echo "<li>Buscar errores relacionados con enrollment o groups</li>";
echo "</ul>";
echo "</div>";

echo $OUTPUT->footer();
