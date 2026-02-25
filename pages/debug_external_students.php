<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('grupomakro_core_manage_courses');

echo $OUTPUT->header();
global $DB;

$periodid = optional_param('periodid', 0, PARAM_INT);
$classid = optional_param('classid', 0, PARAM_INT);

echo "<style>
    table { border-collapse: collapse; width: 100%; margin: 20px 0; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background-color: #f2f2f2; font-weight: bold; }
    .external { background-color: #fff3cd; }
    .normal { background-color: #d1ecf1; }
    .section { margin: 30px 0; padding: 20px; border: 2px solid #ccc; border-radius: 5px; }
    .warning { background-color: #f8d7da; padding: 10px; border-radius: 5px; margin: 10px 0; }
    .info { background-color: #d1ecf1; padding: 10px; border-radius: 5px; margin: 10px 0; }
    .success { background-color: #d4edda; padding: 10px; border-radius: 5px; margin: 10px 0; }
    pre { background-color: #f4f4f4; padding: 10px; border-radius: 5px; overflow-x: auto; }
    .clickable { cursor: pointer; color: blue; text-decoration: underline; }
</style>";

echo "<h1>🔍 Debug: Estudiantes de Clases Externas (Otros Períodos)</h1>";

// ========== SECTION 1: Period Selection ==========
echo "<div class='section'>";
echo "<h2>📅 Selección de Período</h2>";

$periods = $DB->get_records('gmk_academic_periods', null, 'id DESC', 'id, name, startdate, enddate');
if (empty($periodid) && !empty($periods)) {
    $periodid = reset($periods)->id;
}

echo "<form method='get' style='margin: 10px 0;'>";
echo "Período: <select name='periodid' onchange='this.form.submit()'>";
foreach ($periods as $p) {
    $selected = ($p->id == $periodid) ? 'selected' : '';
    echo "<option value='$p->id' $selected>$p->name (ID: $p->id)</option>";
}
echo "</select> ";
echo "<input type='submit' value='Cambiar'>";
echo "</form>";

$currentPeriod = $DB->get_record('gmk_academic_periods', ['id' => $periodid]);
if ($currentPeriod) {
    echo "<div class='info'><strong>Período Actual:</strong> {$currentPeriod->name}<br>";
    echo "<strong>Fechas:</strong> " . date('Y-m-d', $currentPeriod->startdate) . " a " . date('Y-m-d', $currentPeriod->enddate) . "</div>";
}
echo "</div>";

// ========== SECTION 2: Find External Classes ==========
echo "<div class='section'>";
echo "<h2>🔎 Clases Externas (Otros Períodos) vs Normales</h2>";

$sql = "SELECT c.id, c.name, c.courseid, c.corecourseid, c.periodid, c.groupid,
               c.initdate, c.enddate, c.instructorid,
               u.firstname, u.lastname,
               p.name as period_name
        FROM {gmk_class} c
        LEFT JOIN {user} u ON u.id = c.instructorid
        LEFT JOIN {gmk_academic_periods} p ON p.id = c.periodid
        WHERE c.periodid = :periodid1
           OR (c.periodid != :periodid2 AND c.initdate <= :enddate AND c.enddate >= :startdate)
        ORDER BY (c.periodid = :periodid3) DESC, c.id";

$params = [
    'periodid1' => $periodid,
    'periodid2' => $periodid,
    'periodid3' => $periodid,
    'startdate' => $currentPeriod->startdate,
    'enddate' => $currentPeriod->enddate
];

$classes = $DB->get_records_sql($sql, $params);

$externalCount = 0;
$normalCount = 0;

echo "<table>";
echo "<tr><th>ID</th><th>Tipo</th><th>Nombre</th><th>Período ID</th><th>Período</th><th>Core Course ID</th><th>Instructor</th><th>Acciones</th></tr>";

foreach ($classes as $c) {
    $isExternal = ($c->periodid != $periodid);
    $rowClass = $isExternal ? 'external' : 'normal';
    $typeLabel = $isExternal ? '🟡 EXTERNA' : '🔵 NORMAL';

    if ($isExternal) $externalCount++;
    else $normalCount++;

    $instructor = $c->firstname ? "{$c->firstname} {$c->lastname}" : "Sin asignar";

    echo "<tr class='$rowClass'>";
    echo "<td>{$c->id}</td>";
    echo "<td><strong>$typeLabel</strong></td>";
    echo "<td>{$c->name}</td>";
    echo "<td>{$c->periodid}</td>";
    echo "<td>{$c->period_name}</td>";
    echo "<td>" . ($c->corecourseid ?: 'N/A') . "</td>";
    echo "<td>$instructor</td>";
    echo "<td><a href='?periodid=$periodid&classid={$c->id}' class='clickable'>Ver Detalles</a></td>";
    echo "</tr>";
}

echo "</table>";
echo "<div class='info'><strong>Total Normales:</strong> $normalCount | <strong>Total Externas:</strong> $externalCount</div>";
echo "</div>";

// ========== SECTION 3: Detailed Class Analysis ==========
if ($classid > 0) {
    echo "<div class='section'>";
    echo "<h2>📊 Análisis Detallado de Clase ID: $classid</h2>";

    $class = $DB->get_record('gmk_class', ['id' => $classid]);
    if (!$class) {
        echo "<div class='warning'>⚠️ Clase no encontrada</div>";
    } else {
        $isExternal = ($class->periodid != $periodid);

        // Class Basic Info
        echo "<h3>" . ($isExternal ? "🟡 CLASE EXTERNA" : "🔵 CLASE NORMAL") . "</h3>";
        echo "<div class='info'>";
        echo "<strong>Nombre:</strong> {$class->name}<br>";
        echo "<strong>Período de la Clase:</strong> {$class->periodid}<br>";
        echo "<strong>Período Activo:</strong> $periodid<br>";
        echo "<strong>¿Es Externa?:</strong> " . ($isExternal ? "SÍ" : "NO") . "<br>";
        echo "<strong>Core Course ID:</strong> " . ($class->corecourseid ?: 'N/A') . "<br>";
        echo "<strong>Group ID:</strong> " . ($class->groupid ?: 'N/A') . "<br>";
        echo "</div>";

        // gmk_class full record
        echo "<h3>📋 Registro Completo en gmk_class</h3>";
        echo "<pre>" . print_r($class, true) . "</pre>";

        // Student Counts by Table
        echo "<h3>👥 Estudiantes por Tabla</h3>";

        $queue_count = $DB->count_records('gmk_class_queue', ['classid' => $classid]);
        $progre_count = $DB->count_records('gmk_course_progre', ['classid' => $classid]);
        $prereg_count = $DB->count_records('gmk_class_pre_registration', ['classid' => $classid]);

        $group_members_count = 0;
        if ($class->groupid) {
            $group_members_count = $DB->count_records('groups_members', ['groupid' => $class->groupid]);
        }

        echo "<table>";
        echo "<tr><th>Tabla</th><th>Cantidad</th></tr>";
        echo "<tr><td>gmk_class_queue (Planeados)</td><td>$queue_count</td></tr>";
        echo "<tr><td>gmk_course_progre (Matriculados en período)</td><td>$progre_count</td></tr>";
        echo "<tr><td>gmk_class_pre_registration (Pre-registrados)</td><td>$prereg_count</td></tr>";
        echo "<tr><td>groups_members (Grupo Moodle)</td><td>$group_members_count</td></tr>";
        echo "</table>";

        // Moodle Course Enrollments
        if ($class->corecourseid) {
            echo "<h3>🎓 Estudiantes Matriculados en Curso Moodle (corecourseid: {$class->corecourseid})</h3>";

            $enrol_sql = "SELECT u.id, u.firstname, u.lastname, u.email, u.username,
                                 ue.timecreated as enrol_time,
                                 e.enrol as enrol_method
                          FROM {user} u
                          JOIN {user_enrolments} ue ON u.id = ue.userid
                          JOIN {enrol} e ON ue.enrolid = e.id
                          WHERE e.courseid = :courseid
                          ORDER BY u.lastname, u.firstname";

            $enrolled_users = $DB->get_records_sql($enrol_sql, ['courseid' => $class->corecourseid]);

            echo "<div class='success'><strong>Total Matriculados en Moodle:</strong> " . count($enrolled_users) . "</div>";

            if (!empty($enrolled_users)) {
                echo "<table>";
                echo "<tr><th>ID</th><th>Nombre</th><th>Email</th><th>Username</th><th>Método</th><th>Fecha Matrícula</th></tr>";
                foreach ($enrolled_users as $u) {
                    $enrol_date = date('Y-m-d H:i', $u->enrol_time);
                    echo "<tr>";
                    echo "<td>{$u->id}</td>";
                    echo "<td>{$u->firstname} {$u->lastname}</td>";
                    echo "<td>{$u->email}</td>";
                    echo "<td>{$u->username}</td>";
                    echo "<td>{$u->enrol_method}</td>";
                    echo "<td>$enrol_date</td>";
                    echo "</tr>";
                }
                echo "</table>";

                // Export JSON
                echo "<h4>📤 Exportar Datos (JSON)</h4>";
                echo "<pre>" . json_encode($enrolled_users, JSON_PRETTY_PRINT) . "</pre>";
            } else {
                echo "<div class='warning'>⚠️ No hay estudiantes matriculados en el curso Moodle</div>";
            }
        } else {
            echo "<div class='warning'>⚠️ Esta clase NO tiene un corecourseid asociado</div>";
        }

        // Group Members Detail
        if ($class->groupid && $group_members_count > 0) {
            echo "<h3>👨‍👩‍👧‍👦 Miembros del Grupo Moodle (groupid: {$class->groupid})</h3>";

            $group_sql = "SELECT u.id, u.firstname, u.lastname, u.email, u.username,
                                 gm.timeadded
                          FROM {user} u
                          JOIN {groups_members} gm ON u.id = gm.userid
                          WHERE gm.groupid = :groupid
                          ORDER BY u.lastname, u.firstname";

            $group_members = $DB->get_records_sql($group_sql, ['groupid' => $class->groupid]);

            echo "<table>";
            echo "<tr><th>ID</th><th>Nombre</th><th>Email</th><th>Username</th><th>Agregado</th></tr>";
            foreach ($group_members as $u) {
                $added_date = date('Y-m-d H:i', $u->timeadded);
                echo "<tr>";
                echo "<td>{$u->id}</td>";
                echo "<td>{$u->firstname} {$u->lastname}</td>";
                echo "<td>{$u->email}</td>";
                echo "<td>{$u->username}</td>";
                echo "<td>$added_date</td>";
                echo "</tr>";
            }
            echo "</table>";
        }

        // SQL Queries Used
        echo "<h3>🔧 Consultas SQL Ejecutadas</h3>";
        echo "<pre>";
        echo "-- Estudiantes matriculados en Moodle:\n";
        echo $enrol_sql . "\n\n";
        if ($class->groupid) {
            echo "-- Miembros del grupo:\n";
            echo $group_sql . "\n";
        }
        echo "</pre>";
    }

    echo "</div>";
}

echo "<div class='info'><strong>Uso:</strong> Selecciona un período arriba y haz clic en 'Ver Detalles' para analizar cualquier clase.</div>";

echo $OUTPUT->footer();
