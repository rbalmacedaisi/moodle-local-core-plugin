<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

echo "<h1>Inspección Técnica de Datos (Planificación Académica)</h1>";
echo "<p>Usa esta página para confirmar los nombres de las tablas y campos que estamos usando para la limpieza.</p>";

// 1. Check Custom Profile Fields
echo "<h2>1. Campos de Perfil (Tablas mdl_user_info_field y mdl_user_info_data)</h2>";
$fields = $DB->get_records('user_info_field', [], 'shortname ASC', 'id, shortname, name, datatype');
echo "<table border='1' cellpadding='10' style='border-collapse:collapse; width:100%'>
        <tr style='background:#eee'><th>ID</th><th>Shortname (Código)</th><th>Name (Etiqueta)</th><th>Tipo</th><th>Conteo de datos</th></tr>";
foreach ($fields as $f) {
    $count = $DB->count_records('user_info_data', ['fieldid' => $f->id]);
    $highlight = ($f->shortname == 'periodo_ingreso') ? 'style="background:#fff4cc; font-weight:bold"' : '';
    echo "<tr $highlight><td>$f->id</td><td>$f->shortname</td><td>$f->name</td><td>$f->datatype</td><td>$count</td></tr>";
}
echo "</table>";

// 2. Check Learning Subscriptions
echo "<h2>2. Estructura de Subscripciones (Tabla mdl_local_learning_users)</h2>";
$columns = $DB->get_records_sql("SHOW COLUMNS FROM {local_learning_users}");
echo "<p>Columnas detectadas en local_learning_users:</p>";
echo "<ul>";
foreach ($columns as $col) {
    if (in_array(strtolower($col->field), ['academicperiodid', 'periodid', 'currentperiodid', 'userrolename', 'learningplanid'])) {
        echo "<li><b style='color:blue'>$col->field</b> ($col->type)</li>";
    } else {
        echo "<li>$col->field</li>";
    }
}
echo "</ul>";

// 3. Check Academic Periods
echo "<h2>3. Periodos Lectivos (Tabla mdl_gmk_academic_periods)</h2>";
try {
    $periods = $DB->get_records('gmk_academic_periods', [], 'id DESC', 'id, name', 0, 10);
    echo "<table border='1' cellpadding='10' style='border-collapse:collapse'>
            <tr style='background:#eee'><th>ID</th><th>Nombre del Periodo</th></tr>";
    foreach ($periods as $p) {
        echo "<tr><td>$p->id</td><td>$p->name</td></tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "<p style='color:red'>ERROR: La tabla gmk_academic_periods no existe o tiene otro nombre. (" . $e->getMessage() . ")</p>";
}

// 4. Role Analysis
echo "<h2>4. Conteo de Alumnos por Rol (Tabla local_learning_users)</h2>";
$roles = $DB->get_records_sql("SELECT userrolename, COUNT(*) as count FROM {local_learning_users} GROUP BY userrolename");
echo "<ul>";
foreach ($roles as $r) {
    echo "<li>Rol: <b>$r->userrolename</b> | Conteo: $r->count</li>";
}
echo "</ul>";

// 5. Sample Raw Match
echo "<h2>5. Muestra de 10 Subscripciones y sus valores actuales</h2>";
$sql = "SELECT llu.id, u.id as userid, u.firstname, u.lastname, u.idnumber, 
               llu.academicperiodid,
               llu.userrolename
        FROM {local_learning_users} llu
        JOIN {user} u ON u.id = llu.userid
        WHERE u.deleted = 0 
        LIMIT 10";
$sample = $DB->get_records_sql($sql);

echo "<table border='1' cellpadding='10' style='border-collapse:collapse; width:100%'>
        <tr style='background:#eee'><th>Fullname</th><th>ID Number</th><th>Role Name</th><th>AcademicPeriodID</th><th>Data periodo_ingreso</th></tr>";
foreach ($sample as $s) {
    $piValue = $DB->get_field_sql("SELECT data FROM {user_info_data} WHERE userid = ? AND fieldid = ?", [$s->id, ($fields && isset($fields['periodo_ingreso']) ? $fields['periodo_ingreso']->id : ($DB->get_field('user_info_field', 'id', ['shortname' => 'periodo_ingreso']) ?: 0))]);
    
    $piDisplay = ($piValue === false || $piValue === null) ? '<i style="color:red">No hay registro en user_info_data</i>' : ($piValue === '' ? '<i style="color:orange">Vacio</i>' : "<b>$piValue</b>");
    $apDisplay = ($s->academicperiodid == 0 || !$s->academicperiodid) ? '<b style="color:red">0 o NULL</b>' : "<b>$s->academicperiodid</b>";

    echo "<tr>
            <td>$s->firstname $s->lastname</td>
            <td>$s->idnumber</td>
            <td>$s->userrolename</td>
            <td>$apDisplay</td>
            <td>$piDisplay</td>
          </tr>";
}
echo "</table>";

