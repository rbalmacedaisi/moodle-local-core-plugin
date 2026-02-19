<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

echo "<h1>Inspección Técnica: Gaps de Alumnos</h1>";

// 1. Check Custom Profile Fields
echo "<h2>1. Configuración de Campo 'periodo_ingreso'</h2>";
$piField = $DB->get_record('user_info_field', ['shortname' => 'periodo_ingreso']);
if ($piField) {
    $count = $DB->count_records('user_info_data', ['fieldid' => $piField->id]);
    echo "<p>ID Campo: <b>$piField->id</b> | Datos existentes: <b>$count</b></p>";
} else {
    echo "<p style='color:red'>ERROR: Campo 'periodo_ingreso' no encontrado.</p>";
}

// 2. Role Analysis
echo "<h2>2. Usuarios en local_learning_users</h2>";
$roles = $DB->get_records_sql("SELECT userrolename, COUNT(*) as count FROM {local_learning_users} GROUP BY userrolename");
foreach ($roles as $r) {
    echo "<li>Rol: <b>$r->userrolename</b> | Conteo: $r->count</li>";
}

// 3. Gap Detection Test (Strictly Students)
echo "<h2>3. Test de Detección de Gaps (Solo Alumnos)</h2>";
$piId = $piField ? $piField->id : 0;
$sql = "
    SELECT COUNT(DISTINCT u.id)
    FROM {user} u
    JOIN {local_learning_users} llu ON llu.userid = u.id AND llu.userrolename = 'student'
    LEFT JOIN {user_info_data} uid_pi ON uid_pi.userid = u.id AND uid_pi.fieldid = $piId
    WHERE u.deleted = 0 
    AND (uid_pi.data IS NULL OR uid_pi.data = '' OR llu.academicperiodid IS NULL OR llu.academicperiodid = 0)
";
$gapCount = $DB->count_records_sql($sql);
echo "<p style='font-size:20px'>Gaps detectados para rol 'student': <b style='color:red'>$gapCount</b></p>";

// 4. Detailed Sample of Students with Gaps
echo "<h2>4. Muestra de 10 Alumnos con Gaps</h2>";
$sqlSample = "
    SELECT llu.id as subid, u.firstname, u.lastname, u.idnumber, llu.academicperiodid, uid_pi.data as entry_data
    FROM {user} u
    JOIN {local_learning_users} llu ON llu.userid = u.id AND llu.userrolename = 'student'
    LEFT JOIN {user_info_data} uid_pi ON uid_pi.userid = u.id AND uid_pi.fieldid = $piId
    WHERE u.deleted = 0 
    AND (uid_pi.data IS NULL OR uid_pi.data = '' OR llu.academicperiodid IS NULL OR llu.academicperiodid = 0)
    LIMIT 10
";
try {
    $samples = $DB->get_records_sql($sqlSample);
    echo "<table border='1' cellpadding='10' style='border-collapse:collapse; width:100%'>
            <tr style='background:#eee'><th>Nombre</th><th>ID Number</th><th>AcademicPeriodID</th><th>Entry Period Data</th></tr>";
    foreach ($samples as $s) {
        $ap = ($s->academicperiodid == 0) ? '<span style="color:red">NULL/0</span>' : $s->academicperiodid;
        $ep = ($s->entry_data === null || $s->entry_data === '') ? '<span style="color:orange">VACIO</span>' : $s->entry_data;
        echo "<tr>
                <td>$s->firstname $s->lastname</td>
                <td>$s->idnumber</td>
                <td>$ap</td>
                <td>$ep</td>
              </tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "<p style='color:red'>Error en query sample: " . $e->getMessage() . "</p>";
}
