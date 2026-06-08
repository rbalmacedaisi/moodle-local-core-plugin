<?php
// Correccion: desmatricular a estudiantes del grupo "regular" (PRESENCIAL/VIRTUAL/MIXTA/D/N/S)
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
//   - Para cada (estudiante, curso):
//       * Listar todos los grupos del estudiante en ese curso.
//       * Clasificar cada grupo como MODULO (regex_modulo) o REGULAR (regex_regular).
//       * Si tiene >=1 MODULO y >=1 REGULAR, se ELIMINAN los REGULARES y se CONSERVAN los MODULOS.

define('CLI_SCRIPT', true);
require_once('/var/www/html/moodle/config.php');
global $DB;
require_once($CFG->dirroot . '/group/lib.php');

// --- Parametros ----------------------------------------------------------------
$dryrun  = false;  // true = solo muestra; false = aplica cambios
$verbose = true;   // true = muestra cada cambio
// ------------------------------------------------------------------------------

// Regex:
//   - MODULO  : cualquier nombre que contenga "MÓDULO" o "MODULO" seguido de 2026-I/II/III/IV/V (o sin periodo).
//   - REGULAR : contiene PRESENCIAL, VIRTUAL, MIXTA, o letra de turno (D) (N) (S) entre parentesis.
$regex_modulo   = '/\(?\s*M[ÓO]DULO\s*\)?\s*2026[- ]?(I|II|III|IV|V)\b/i';
$regex_regular  = '/(\(PRESENCIAL\)|\(VIRTUAL\)|\(MIXTA\)|\(D\)|\(N\)|\(S\))/i';

echo "=== FIX: QUITAR GRUPOS REGULARES A ESTUDIANTES QUE YA TIENEN (MÓDULO) 2026-X ===\n\n";
echo "MODO: " . ($dryrun ? "DRY-RUN (no se aplicaran cambios)\n" : "EJECUCION REAL\n") . "\n";
echo "Patron grupos regulares: $regex_regular\n";
echo "Patron grupos modulo  : $regex_modulo\n\n";

// Sub-query: usuarios con rol 'student' en un curso y matriculacion activa.
$sql_candidatos = "
    SELECT DISTINCT
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
";

echo "[1/2] Obteniendo candidatos (estudiantes con rol student, matriculacion activa, en algun grupo)...\n";
$candidatos = $DB->get_records_sql($sql_candidatos);
echo "Candidatos: " . count($candidatos) . "\n\n";

$total_casos     = 0;
$total_borrados  = 0;
$errores         = 0;
$log             = [];

echo "[2/2] Procesando cada candidato...\n";

foreach ($candidatos as $c) {
    // Defensa contra falsos positivos (docentes con rol student)
    $email = $c->email;
    if (stripos($email, 'prof.') === 0 || stripos($email, '@isi.edu.pa') !== false) {
        if ($verbose) {
            echo "  [SKIP docente] {$c->lastname}, {$c->firstname} ({$email})\n";
        }
        continue;
    }

    // Traer todos los grupos del estudiante en ese curso
    $sql_g = "SELECT g.id AS gid, g.name AS gname
                FROM {groups_members} gm
                JOIN {groups} g ON g.id = gm.groupid
               WHERE gm.userid = ? AND g.courseid = ?";
    $grupos = $DB->get_records_sql($sql_g, [$c->userid, $c->courseid]);

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

    $total_casos++;
    $nombres_modulos   = array_map(fn($g) => $g->gname, $modulos);
    $nombres_regulares = array_map(fn($g) => $g->gname, $regulares);

    if ($verbose) {
        echo "-------------------------------------------------\n";
        echo "[CASO] {$c->lastname}, {$c->firstname} (ID: {$c->userid})\n";
        echo "   Email      : {$c->email}\n";
        echo "   Asignatura : [{$c->shortname}] {$c->coursename}\n";
        echo "   MODULOS    : " . implode(' | ', $nombres_modulos) . "\n";
        echo "   REGULARES  : " . implode(' | ', $nombres_regulares) . "  <-- SE QUITAN\n";
    }

    $log[] = [
        'userid'      => $c->userid,
        'estudiante'  => "{$c->lastname}, {$c->firstname}",
        'email'       => $c->email,
        'courseid'    => $c->courseid,
        'asignatura'  => $c->shortname . ' - ' . $c->coursename,
        'modulos'     => $nombres_modulos,
        'regulares'   => $nombres_regulares,
        'timestamp'   => date('Y-m-d H:i:s'),
        'dryrun'      => $dryrun,
    ];

    // Aplicar cambios
    foreach ($regulares as $g) {
        if (!$dryrun) {
            try {
                groups_remove_member($g->gid, $c->userid);
                $total_borrados++;
            } catch (Exception $ex) {
                $errores++;
                echo "   !! ERROR quitando grupo {$g->gid} ({$g->gname}): " . $ex->getMessage() . "\n";
            }
        } else {
            $total_borrados++;
        }
    }
}

echo "\n=== RESUMEN ===\n";
echo "Casos analizados           : " . count($candidatos) . "\n";
echo "Casos con cambio aplicado  : {$total_casos}\n";
echo "Grupos regulares quitados  : {$total_borrados}\n";
echo "Errores                    : {$errores}\n";

// Guardar log en archivo
$logfile = __DIR__ . '/fix_multi_groups_log_' . date('Y-m-d_His') . '.json';
file_put_contents($logfile, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\nLog guardado en: $logfile\n";

if ($dryrun) {
    echo "\n*** ESTO FUE UN DRY-RUN. Para aplicar: cambiar \$dryrun = false ***\n";
}
