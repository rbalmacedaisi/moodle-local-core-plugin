<?php
// Reporte: estudiantes (rol=student, matriculados activamente) inscritos en 2+
// grupos de la misma asignatura.
// Ubicacion en servidor: /var/www/html/moodle/local/grupomakro_core/check_multi_groups.php
// Ejecucion: php /var/www/html/moodle/local/grupomakro_core/check_multi_groups.php

define('CLI_SCRIPT', true);
require_once('/var/www/html/moodle/config.php');
global $DB;

echo "=== ESTUDIANTES (ROL=student) EN MAS DE 1 GRUPO DE LA MISMA ASIGNATURA ===\n\n";

// Para que un usuario cuente como "estudiante activo" en el curso debe:
//   1) Tener matriculacion activa en el curso (ue.status = 0).
//   2) Tener asignacion de rol 'student' (shortname = 'student') en el contexto del curso.
// Ademas filtramos usuarios no borrados ni suspendidos.
$sql = "
    SELECT
        u.id            AS userid,
        u.firstname,
        u.lastname,
        u.email,
        c.id            AS courseid,
        c.shortname,
        c.fullname      AS coursename,
        GROUP_CONCAT(g.name ORDER BY g.name SEPARATOR ' | ') AS grupos,
        COUNT(DISTINCT gm.groupid) AS num_grupos
    FROM {user} u
    JOIN {groups_members} gm ON gm.userid = u.id
    JOIN {groups}        g  ON g.id       = gm.groupid
    JOIN {course}        c  ON c.id       = g.courseid
    -- Matriculacion activa del usuario en el curso
    JOIN {enrol}         e  ON e.courseid = c.id
    JOIN {user_enrolments} ue ON ue.enrolid = e.id AND ue.userid = u.id AND ue.status = 0
    -- Asignacion del rol 'student' en el contexto del curso
    JOIN {context}      ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
    JOIN {role_assignments} ra ON ra.userid = u.id AND ra.contextid = ctx.id
    JOIN {role}         r   ON r.id = ra.roleid AND r.shortname = 'student'
    WHERE u.deleted   = 0
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
