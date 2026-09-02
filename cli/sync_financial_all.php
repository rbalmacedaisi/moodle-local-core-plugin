<?php
/**
 * CLI bootstrap: sincroniza gmk_financial_status contra Odoo para todos
 * los estudiantes que tengan documentnumber.
 *
 * Reusa local_grupomakro_sync_financial_status() que ya soporta array de
 * userids. Corre en chunks de N para no saturar el proxy Express y va
 * midiendo ETA. Es la red de seguridad inicial: una vez que el cron
 * residual cada 6h + el webhook Express→Moodle están activos, este script
 * solo se necesita para resincronizaciones masivas puntuales.
 *
 * Uso:
 *   php local/grupomakro_core/cli/sync_financial_all.php \
 *       [--batch=15] [--throttle=2] [--max=0] [--dry-run]
 *
 *     --batch=N     estudiantes por llamada al proxy (default 15)
 *     --throttle=S  segundos de pausa entre batches (default 2)
 *     --max=N       procesar a lo sumo N estudiantes (0 = todos, default 0)
 *     --only-active excluir suspendidos (default: se incluyen)
 *     --dry-run     no toca la BD; solo cuenta y mide
 *     --logfile=PATH  además de STDERR, append a un archivo de log
 *
 * Salida: STDERR (formato CLI Moodle) + opcional logfile. Exit code 0 OK,
 * 1 error fatal, 130 SIGTERM/interrupción limpia.
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Grupomakro
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognized) = cli_get_params(
    [
        'batch'     => 15,
        'throttle'  => 2,
        'max'       => 0,
        'only-active' => false,
        'dry-run'   => false,
        'logfile'   => '',
        'help'      => false,
    ],
    [
        'b' => 'batch',
        't' => 'throttle',
        'm' => 'max',
        'a' => 'only-active',
        'd' => 'dry-run',
        'l' => 'logfile',
        'h' => 'help',
    ]
);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error("Opción no reconocida: \n  " . $unrecognized);
}

if ($options['help']) {
    echo "CLI bootstrap de sincronización financiera (Odoo → Moodle).

Opciones:
  --batch=N     estudiantes por llamada al proxy (default 15)
  --throttle=S  segundos de pausa entre batches (default 2)
  --max=N       procesar a lo sumo N estudiantes (0 = todos, default 0)
  --only-active excluir usuarios suspendidos (default: se incluyen, igual
                que el cron residual, para que la tabla no quede a medias)
  --dry-run     no toca la BD; solo cuenta y mide
  --logfile=PATH  append de log a un archivo además de STDERR
  -h, --help    muestra esta ayuda

Ejemplos:
  sudo -u www-data php admin/cli/..  # no, este es el comando real:
  sudo -u www-data php local/grupomakro_core/cli/sync_financial_all.php
  sudo -u www-data php local/grupomakro_core/cli/sync_financial_all.php --dry-run
  sudo -u www-data php local/grupomakro_core/cli/sync_financial_all.php --batch=10 --throttle=3 --logfile=/var/log/moodle-financial-bootstrap.log
";
    exit(0);
}

$batch     = max(1, (int)$options['batch']);
$throttle  = max(0, (int)$options['throttle']);
$max       = max(0, (int)$options['max']);
$onlyactive = !empty($options['only-active']);
$dryrun    = !empty($options['dry-run']);
$logfile   = (string)$options['logfile'];

// Manejo de SIGTERM para terminar limpio al final del batch.
$shouldstop = false;
if (function_exists('pcntl_signal') && function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, function () use (&$shouldstop) {
        $shouldstop = true;
    });
    pcntl_signal(SIGINT, function () use (&$shouldstop) {
        $shouldstop = true;
    });
}

/**
 * Emite una línea a STDERR y, si hay logfile, append.
 */
function bs_log(string $line, string $logfile = ''): void {
    $timestamp = date('Y-m-d H:i:s');
    $msg = "[$timestamp] $line";
    fwrite(STDERR, $msg . PHP_EOL);
    if ($logfile !== '') {
        @file_put_contents($logfile, $msg . PHP_EOL, FILE_APPEND);
    }
}

raise_memory_limit(MEMORY_HUGE);
core_php_time_limit::raise(0); // Sin límite; el proceso puede correr horas.

bs_log("=== Bootstrap financiero Moodle → Odoo ===", $logfile);
bs_log(sprintf(
    "batch=%d throttle=%ds max=%d only-active=%s dry-run=%s",
    $batch,
    $throttle,
    $max,
    $onlyactive ? 'SI' : 'no',
    $dryrun ? 'SI' : 'no'
), $logfile);

// 1) Listar todos los userids con documentnumber no vacío.
$fieldDoc = $DB->get_record('user_info_field', ['shortname' => 'documentnumber']);
if (!$fieldDoc) {
    bs_log("ERROR: campo personalizado 'documentnumber' no existe. Abortando.", $logfile);
    exit(1);
}

// Por defecto se incluyen los suspendidos: el cron residual tampoco los
// filtra, y excluirlos dejaba la tabla permanentemente a medias — sus filas
// nunca se refrescaban y figuraban como stale para siempre. Los usuarios
// borrados si se excluyen siempre.
$suspendedclause = $onlyactive ? 'AND u.suspended = 0' : '';

$sql = "SELECT u.id, d.data AS documentnumber
          FROM {user} u
          JOIN {user_info_data} d
            ON d.userid = u.id
           AND d.fieldid = :fieldid
         WHERE u.deleted = 0
           $suspendedclause
           AND d.data IS NOT NULL
           AND d.data != ''
      ORDER BY u.id ASC";

$rows = $DB->get_records_sql($sql, ['fieldid' => $fieldDoc->id]);
if (empty($rows)) {
    bs_log("No se encontraron estudiantes con documentnumber.", $logfile);
    exit(0);
}

$allids = array_keys($rows);
$total  = count($allids);
if ($max > 0 && $max < $total) {
    $allids = array_slice($allids, 0, $max);
    $total  = count($allids);
}

bs_log(sprintf("Encontrados %d estudiantes%s a procesar.", $total, $max > 0 ? " (limitado por --max)" : ""), $logfile);

// 2) Modo dry-run: solo contar, no tocar.
if ($dryrun) {
    bs_log("--dry-run: no se modifica la BD. Saliendo.", $logfile);
    exit(0);
}

// 3) Procesar en chunks.
$starttime  = microtime(true);
$updated    = 0;
$errors     = 0;
$processed  = 0;
$idx        = 0;
$totalchunks = (int)ceil($total / $batch);

foreach (array_chunk($allids, $batch) as $chunkindex => $chunk) {
    if ($shouldstop) {
        bs_log("SIGTERM recibido. Terminando limpio.", $logfile);
        break;
    }

    $idx += count($chunk);
    $chunkstart = microtime(true);
    $result = local_grupomakro_sync_financial_status($chunk);

    if (isset($result['error'])) {
        $errors++;
        bs_log(sprintf(
            "[chunk %d/%d] ERROR: %s%s",
            $chunkindex + 1,
            $totalchunks,
            $result['error'],
            isset($result['details']) ? ' (' . substr($result['details'], 0, 120) . ')' : ''
        ), $logfile);
    } else {
        $chunkupdated = (int)($result['updated'] ?? 0);
        $updated     += $chunkupdated;
        bs_log(sprintf(
            "[chunk %d/%d] OK: %d actualizados de %d solicitados (%.2fs)",
            $chunkindex + 1,
            $totalchunks,
            $chunkupdated,
            count($chunk),
            microtime(true) - $chunkstart
        ), $logfile);
    }

    $processed += count($chunk);

    // ETA.
    $elapsed   = microtime(true) - $starttime;
    $remaining = $total - $processed;
    $eta       = $remaining > 0 ? ($elapsed / max(1, $processed)) * $remaining : 0;
    bs_log(sprintf(
        "Progreso: %d/%d (%.1f%%) — elapsed %.1fs — ETA %.1fs",
        $processed,
        $total,
        100 * $processed / $total,
        $elapsed,
        $eta
    ), $logfile);

    if ($shouldstop) {
        break;
    }

    if ($throttle > 0 && $idx < $total) {
        sleep($throttle);
    }
}

$totalelapsed = microtime(true) - $starttime;
bs_log(sprintf(
    "=== Terminado. actualizados=%d errores=%d elapsed=%.1fs ===",
    $updated,
    $errors,
    $totalelapsed
), $logfile);

exit($shouldstop ? 130 : 0);