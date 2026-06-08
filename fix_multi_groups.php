<?php
// Correccion: desmatricular a estudiantes del grupo "regular" (PRESENCIAL/VIRTUAL/MIXTA)
// cuando tambien estan en el grupo "(MÓDULO) 2026-X" de la misma asignatura.
// Solo desmatricula de GRUPOS (groups_members), NO elimina matriculaciones del curso.
//
// Politica:
//   - Para cada (estudiante, curso), si tiene al menos un grupo M[ÓO]DULO y
//     al menos un grupo REGULAR, se ELIMINAN las membresias de los grupos REGULARES.
//   - Si el multi-grupo NO incluye un M[ÓO]DULO, no se toca (queda en log).
//
// Filtro defensa: excluye docentes con email que empieza con "prof." o dominio @isi.edu.pa.

define('CLI_SCRIPT', true);
require_once('/var/www/html/moodle/config.php');
global $DB;
require_once($CFG->dirroot . '/group/lib.php');

// --- Parametros ----------------------------------------------------------------
$dryrun  = false;  // true = solo muestra; false = aplica cambios
$verbose = true;   // true = muestra cada cambio
// ------------------------------------------------------------------------------

echo "=== FIX: QUITAR GRUPOS REGULARES A ESTUDIANTES CON MULTI-GRUPO QUE TIENEN MODULO ===\n\n";
echo "MODO: " . ($dryrun ? "DRY-RUN (no se aplicaran cambios)\n" : "EJECUCION REAL\n") . "\n";

// --- 1) Candidatos: cualquier usuario con >1 grupo en el mismo curso ----------
//     (sin filtro de rol para esta primera pasada; filtraremos falsos positivos despues)
echo "[1/5] Identificando todos los usuarios con multi-grupo en el mismo curso...\n";

$sql_candidatos = "
    SELECT
        u.id    AS userid,
        u.firstname,
        u.lastname,
        u.email,
        c.id    AS courseid,
        c.shortname,
        c.fullname AS coursename,
        COUNT(DISTINCT gm.groupid) AS num_grupos
    FROM {user} u
    JOIN {groups_members} gm ON gm.userid = u.id
    JOIN {groups}        g  ON g.id       = gm.groupid
    JOIN {course}        c  ON c.id       = g.courseid
    WHERE u.deleted = 0
      AND u.suspended = 0
    GROUP BY u.id, c.id
    HAVING num_grupos > 1
    ORDER BY u.lastname, u.firstname, c.shortname
";

$candidatos = $DB->get_records_sql($sql_candidatos);
echo "  -> " . count($candidatos) . " candidatos con multi-grupo\n\n";

// --- 2) Defensa contra docentes ----------------------------------------------
echo "[2/5] Filtrando falsos positivos (docentes con prefijo prof. o @isi.edu.pa)...\n";
$filas = [];
$skipped_docentes = 0;
foreach ($candidatos as $c) {
    if (stripos($c->email, 'prof.') === 0 || stripos($c->email, '@isi.edu.pa') !== false) {
        $skipped_docentes++;
        if ($verbose) {
            echo "  [SKIP docente] {$c->lastname}, {$c->firstname} ({$c->email}) | {$c->shortname}\n";
        }
        continue;
    }
    $filas[] = $c;
}
echo "  -> Quedan " . count($filas) . " estudiantes reales (skipped $skipped_docentes docentes)\n\n";

// --- 3) Analisis por estudiante/asignatura ------------------------------------
echo "[3/5] Analizando grupos de cada caso y aplicando politica...\n\n";

$regex_modulo   = '/M[ÓO]DULO.*2026[- ]?(I|II|III|IV|V)\b/i';

$total_casos           = 0;
$total_grupos_quitados = 0;
$total_grupos_mantenidos = 0;
$errores               = 0;
$log                   = [];
$casos_sin_modulo      = [];

foreach ($filas as $c) {
    // Traer todos los grupos del estudiante en ese curso
    $sql_g = "SELECT g.id AS gid, g.name AS gname
                FROM {groups_members} gm
                JOIN {groups} g ON g.id = gm.groupid
               WHERE gm.userid = ? AND g.courseid = ?";
    $grupos = $DB->get_records_sql($sql_g, [$c->userid, $c->courseid]);

    $modulos   = [];
    $regulares = [];
    foreach ($grupos as $g) {
        if (preg_match($regex_modulo, $g->gname)) {
            $modulos[] = $g;
        } else {
            $regulares[] = $g;
        }
    }

    if (empty($modulos)) {
        // Multi-grupo sin modulo -> no se toca, se reporta
        $casos_sin_modulo[] = [
            'userid'     => $c->userid,
            'estudiante' => "{$c->lastname}, {$c->firstname}",
            'email'      => $c->email,
            'asignatura' => $c->shortname,
            'grupos'     => array_map(fn($g) => $g->gname, $grupos),
        ];
        continue;
    }

    $total_casos++;
    $nombres_modulos   = array_map(fn($g) => $g->gname, $modulos);
    $nombres_regulares = array_map(fn($g) => $g->gname, $regulares);

    echo "-------------------------------------------------\n";
    echo "[CASO] {$c->lastname}, {$c->firstname} (ID: {$c->userid}) | {$c->email}\n";
    echo "   Asignatura       : [{$c->shortname}] {$c->coursename}\n";
    echo "   CONSERVAR (mod.) : " . implode(' | ', $nombres_modulos) . "\n";
    echo "   QUITAR (reg.)    : " . implode(' | ', $nombres_regulares) . "\n";

    $entry = [
        'userid'     => $c->userid,
        'estudiante' => "{$c->lastname}, {$c->firstname}",
        'email'      => $c->email,
        'courseid'   => $c->courseid,
        'asignatura' => $c->shortname . ' - ' . $c->coursename,
        'modulos'    => $nombres_modulos,
        'regulares'  => $nombres_regulares,
        'timestamp'  => date('Y-m-d H:i:s'),
        'dryrun'     => $dryrun,
    ];

    foreach ($regulares as $g) {
        $total_grupos_quitados++;
        if (!$dryrun) {
            try {
                groups_remove_member($g->gid, $c->userid);
                echo "      OK  quitado grupo [{$g->gid}] {$g->gname}\n";
            } catch (Exception $ex) {
                $errores++;
                echo "      !! ERROR quitando grupo [{$g->gid}] {$g->gname}: " . $ex->getMessage() . "\n";
            }
        } else {
            echo "      (dryrun) hubiera quitado [{$g->gid}] {$g->gname}\n";
        }
    }
    $total_grupos_mantenidos += count($modulos);
    $log[] = $entry;
}

// --- 4) Resumen ----------------------------------------------------------------
echo "\n=== RESUMEN ===\n";
echo "Candidatos (multi-grupo)               : " . count($candidatos) . "\n";
echo "Skipped docentes                       : $skipped_docentes\n";
echo "Estudiantes a corregir (con MODULO)    : $total_casos\n";
echo "Grupos MODULO conservados              : $total_grupos_mantenidos\n";
echo "Grupos REGULARES quitados              : $total_grupos_quitados\n";
echo "Errores                                : $errores\n";

// --- 5) Reporte: casos con multi-grupo SIN modulo ----------------------------
echo "\n=== ATENCION: CASOS CON MULTI-GRUPO QUE NO TIENEN MODULO ===\n";
echo "Estos quedan intactos. Revisalos manualmente.\n\n";
if (empty($casos_sin_modulo)) {
    echo "  (ninguno)\n";
} else {
    foreach ($casos_sin_modulo as $cs) {
        echo "  [{$cs['userid']}] {$cs['estudiante']} ({$cs['email']}) | {$cs['asignatura']}\n";
        foreach ($cs['grupos'] as $gn) {
            echo "      - $gn\n";
        }
    }
}

// Guardar log
$logfile = __DIR__ . '/fix_multi_groups_log_' . date('Y-m-d_His') . '.json';
file_put_contents($logfile, json_encode([
    'fix_aplicado'     => $log,
    'sin_modulo'       => $casos_sin_modulo,
    'skipped_docentes' => $skipped_docentes,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\nLog guardado en: $logfile\n";

if ($dryrun) {
    echo "\n*** ESTO FUE UN DRY-RUN. Para aplicar: cambiar \$dryrun = false ***\n";
}
