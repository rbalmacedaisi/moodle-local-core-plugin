<?php
// Reporte: estudiantes inscritos en 2 o mas grupos de la misma asignatura.
// Ubicacion en servidor: /var/www/html/moodle/local/grupomakro_core/check_multi_groups.php
// Ejecucion: php /var/www/html/moodle/local/grupomakro_core/check_multi_groups.php

define('CLI_SCRIPT', true);
require_once('/var/www/html/moodle/config.php');
global $DB;

echo "=== ESTUDIANTES EN MAS DE 1 GRUPO DE LA MISMA ASIGNATURA ===\n\n";

$sql = "
    SELECT
        u.id AS userid,
        u.firstname,
        u.lastname,
        u.email,
        c.id AS courseid,
        c.shortname,
        c.fullname AS coursename,
        GROUP_CONCAT(g.name ORDER BY g.name SEPARATOR ' | ') AS grupos,
        COUNT(DISTINCT gm.groupid) AS num_grupos
    FROM {user} u
    JOIN {groups_members} gm ON u.id = gm.userid
    JOIN {groups} g ON gm.groupid = g.id
    JOIN {course} c ON g.courseid = c.id
    WHERE u.deleted = 0
      AND u.suspended = 0
    GROUP BY u.id, c.id
    HAVING num_grupos > 1
    ORDER BY num_grupos DESC, u.lastname, u.firstname
";

$results = $DB->get_records_sql($sql);

echo "Total de casos encontrados: " . count($results) . "\n\n";

if (empty($results)) {
    echo "No se encontraron casos.\n";
    exit(0);
}

// Resumen por asignatura
echo "=== RESUMEN POR ASIGNATURA ===\n";
$bycourse = [];
foreach ($results as $row) {
    $key = $row->shortname;
    if (!isset($bycourse[$key])) {
        $bycourse[$key] = [
            'shortname'  => $row->shortname,
            'coursename' => $row->coursename,
            'count'      => 0,
        ];
    }
    $bycourse[$key]['count']++;
}
foreach ($bycourse as $c) {
    echo "  - [" . $c['shortname'] . "] " . $c['coursename'] . ': ' . $c['count'] . " estudiante(s)\n";
}

// Detalle
echo "\n=== DETALLE DE CASOS ===\n\n";
foreach ($results as $row) {
    echo "[" . $row->num_grupos . " grupos] " . $row->lastname . ", " . $row->firstname . " (ID: " . $row->userid . ")\n";
    echo "   Email: " . $row->email . "\n";
    echo "   Asignatura: [" . $row->shortname . "] " . $row->coursename . "\n";
    echo "   Grupos: " . $row->grupos . "\n\n";
}
