<?php
// Correccion: desmatricular a estudiantes del grupo "regular" (PRESENCIAL/VIRTUAL)
// cuando tambien estan en el grupo "(MÓDULO) 2026-X" de la misma asignatura.
// Solo desmatricula de GRUPOS (groups_members), NO elimina matriculaciones del curso.
//
// Ubicacion en servidor: /var/www/html/moodle/local/grupomakro_core/fix_multi_groups.php
// Ejecucion: php /var/www/html/moodle/local/grupomakro_core/fix_multi_groups.php
//
// Filtros:
//   - Usuarios con rol 'student' en el curso y matriculacion activa.
//   - Excluye emails con prefijo "prof." o dominio "@isi.edu.pa" (defensa contra docentes).
//
// Politica:
//   - Si un estudiante en un curso tiene >=1 grupo "(MÓDULO) 2026-..."
//     Y ademas tiene >=1 grupo "regular" (PRESENCIAL/VIRTUAL/Diurno/Nocturno/Sabatino/etc.),
//     se ELIMINAN sus membresias de los grupos "regulares" y se CONSERVAN las del MODULO.

define('CLI_SCRIPT', true);
require_once('/var/www/html/moodle/config.php');
global $DB;

require_once($CFG->dirroot . '/group/lib.php');

// --- Parametros ----------------------------------------------------------------
$dryrun  = false;  // true = solo muestra; false = aplica cambios
$verbose = true;   // true = muestra cada cambio
// ------------------------------------------------------------------------------

echo "=== FIX: QUITAR GRUPOS REGULARES A ESTUDIANTES QUE YA TIENEN (MÓDULO) 2026-X ===\n\n";
echo "MODO: " . ($dryrun ? "DRY-RUN (no se aplicaran cambios)\n" : "EJECUCION REAL\n") . "\n";

// Regex para identificar grupos "regulares" (los que NO son MÓDULO 2026-).
// Consideramos regulares los que contengan: PRESENCIAL, VIRTUAL, o letras de turno
// (D) Diurno, (N) Nocturno, (S) Sabatino, siempre que NO contengan "MÓDULO".
$regex_regular  = '/(\(PRESENCIAL\)|\(VIRTUAL\)|\(D\)|\(N\)|\(S\))/i';
$regex_modulo   = '/\(MÓDULO\)\s*2026[- ]?(I|II|III|IV|V)\b/i';

echo "Patron grupos regulares: $regex_regular\n";
echo "Patron grupos modulo  : $regex_modulo\n\n";

$sql = "
    SELECT
        u.id            AS userid,
        u.firstname,
        u.lastname,
        u.email,
        c.id            AS courseid,
        c.shortname,
        c.fullname      AS coursename
    FROM {user} u
    JOIN {groups_members} gm ON gm.userid = u.id
    JOIN {groups}        g  ON g.id       = gm.groupid
    JOIN {course}        c  ON c.id       = g.courseid
    JOIN {enrol}         e  ON e.courseid = c.id
    JOIN {user_enrolments} ue ON ue.enrolid = e.id AND ue.userid = u.id AND ue.status = 0
    JOIN {context}      ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
    JOIN {role_assignments} ra ON ra.userid = u.id AND ra.contextid = ctx.id
    JOIN {role}         r   ON r.id = ra.roleid AND r.shortname = 'student'
    WHERE u.deleted   = 0
      AND u.suspended = 0
      AND g.name REGEXP 'M[ÓO]DULO'
    GROUP BY u.id, c.id
    HAVING COUNT(DISTINCT gm.groupid) > 1
    ORDER BY u.lastname, u.firstname
";

$rs = $DB->get_recordset_sql($sql);

$total_casos   = 0;
$total_borrados = 0;
$errores        = 0;
$log            = [];

foreach ($rs as $caso) {
    $total_casos++;

    // Defensa contra falsos positivos (docentes con rol student)
    $email = $caso->email;
    if (stripos($email, 'prof.') === 0 || stripos($email, '@isi.edu.pa') !== false) {
        if ($verbose) {
            echo "  [SKIP docente] {$caso->lastname}, {$caso->firstname} ({$email})\n";
        }
        continue;
    }

    // Traer todos los grupos del estudiante en ese curso
    $sql_g = "SELECT g.id AS gid, g.name AS gname
                FROM {groups_members} gm
                JOIN {groups} g ON g.id = gm.groupid
               WHERE gm.userid = ? AND g.courseid = ?";
    $grupos = $DB->get_records_sql($sql_g, [$caso->userid, $caso->courseid]);

    // Clasificar grupos
    $modulos   = [];
    $regulares = [];
    foreach ($grupos as $g) {
        if (preg_match($regex_modulo, $g->gname)) {
            $modulos[] = $g;
        } elseif (preg_match($regex_regular, $g->gname)) {
            $regulares[] = $g;
        }
    }

    // Politica: si tiene >=1 modulo Y >=1 regular, se quitan los regulares
    if (empty($modulos) || empty($regulares)) {
        continue;
    }

    $nombres_modulos   = array_map(fn($g) => $g->gname, $modulos);
    $nombres_regulares = array_map(fn($g) => $g->gname, $regulares);

    if ($verbose) {
        echo "-------------------------------------------------\n";
        echo "[CASO] {$caso->lastname}, {$caso->firstname} (ID: {$caso->userid})\n";
        echo "   Email      : {$caso->email}\n";
        echo "   Asignatura : [{$caso->shortname}] {$caso->coursename}\n";
        echo "   MODULOS    : " . implode(' | ', $nombres_modulos) . "\n";
        echo "   REGULARES  : " . implode(' | ', $nombres_regulares) . "  <-- SE QUITAN\n";
    }

    // Backup en log
    $log[] = [
        'userid'      => $caso->userid,
        'estudiante'  => "{$caso->lastname}, {$caso->firstname}",
        'email'       => $caso->email,
        'courseid'    => $caso->courseid,
        'asignatura'  => $caso->shortname . ' - ' . $caso->coursename,
        'modulos'     => $nombres_modulos,
        'regulares'   => $nombres_regulares,
        'timestamp'   => date('Y-m-d H:i:s'),
        'dryrun'      => $dryrun,
    ];

    // Aplicar cambios
    foreach ($regulares as $g) {
        if (!$dryrun) {
            try {
                groups_remove_member($g->gid, $caso->userid);
                $total_borrados++;
            } catch (Exception $ex) {
                $errores++;
                echo "   !! ERROR quitando grupo {$g->gid} ({$g->gname}): " . $ex->getMessage() . "\n";
            }
        } else {
            $total_borrados++;  // cuenta lo que SE HABRIA borrado
        }
    }
}
$rs->close();

echo "\n=== RESUMEN ===\n";
echo "Casos analizados           : {$total_casos}\n";
echo "Casos con cambio a aplicar : " . count($log) . "\n";
echo "Grupos regulares a quitar  : {$total_borrados}\n";
echo "Errores                    : {$errores}\n";

// Guardar log en archivo
$logfile = __DIR__ . '/fix_multi_groups_log_' . date('Y-m-d_His') . '.json';
file_put_contents($logfile, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\nLog guardado en: $logfile\n";
echo "Para ejecutar de verdad: cambiar \$dryrun = false en el script y volver a correr.\n";
