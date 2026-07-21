<?php
/**
 * cleanup_resurrected_classes.php  (one-off, 2026-07-21)
 *
 * Limpia el daño del incidente de resurrección de clases:
 *  1) Elimina correctamente (delete_class) las clases resucitadas por la
 *     publicación masiva de las 22:31 que quedaron publicadas sin quererlo.
 *  2) Barre el draft_schedules del periodo dejando fuera cualquier ficha
 *     "fantasma" (id numérico que ya no existe como clase real).
 *
 * Antes de tocar nada, vuelca un respaldo JSON de cada clase (registro +
 * schedules + queue) y del draft completo.
 *
 * USO:
 *   php cleanup_resurrected_classes.php                 (dry-run, no cambia nada)
 *   php cleanup_resurrected_classes.php --apply
 *
 * Opcionales:
 *   --period=5                 Periodo institucional a barrer (default: 5)
 *   --ids=9786,9787,9789,9790  Clases a eliminar (default: esas 4)
 *   --backup-dir=/home/ubuntu/draft_backups
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

date_default_timezone_set('America/Panama');
cron_setup_user();

global $DB;

$argv = $_SERVER['argv'];
$apply = in_array('--apply', $argv, true);
$periodid = 5;
$ids = [9786, 9787, 9789, 9790];
$backupDir = '/home/ubuntu/draft_backups';

foreach ($argv as $a) {
    if (strpos($a, '--period=') === 0) {
        $periodid = (int)substr($a, 9);
    } else if (strpos($a, '--ids=') === 0) {
        $ids = array_values(array_filter(array_map('intval', explode(',', substr($a, 6)))));
    } else if (strpos($a, '--backup-dir=') === 0) {
        $backupDir = substr($a, 13);
    }
}

if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0775, true);
}
$stamp = date('Ymd_His');

echo "=== cleanup_resurrected_classes (" . ($apply ? "APPLY" : "DRY-RUN") . ") ===\n";
echo "Periodo: {$periodid}\n";
echo "Clases a eliminar: " . implode(', ', $ids) . "\n\n";

// ---------------------------------------------------------------------------
// 1) Respaldo de las clases objetivo (registro + schedules + queue)
// ---------------------------------------------------------------------------
$classBackup = [];
foreach ($ids as $cid) {
    $rec = $DB->get_record('gmk_class', ['id' => $cid]);
    if (!$rec) {
        echo "  AVISO: clase {$cid} ya no existe, se omite.\n";
        continue;
    }
    $classBackup[$cid] = [
        'class'     => $rec,
        'schedules' => array_values($DB->get_records('gmk_class_schedules', ['classid' => $cid])),
        'queue'     => array_values($DB->get_records('gmk_class_queue', ['classid' => $cid])),
        'progre'    => array_values($DB->get_records('gmk_course_progre', ['classid' => $cid])),
    ];
    echo "  respaldada clase {$cid}: {$rec->name}\n";
}
$classBackupFile = "{$backupDir}/deleted_classes_{$stamp}.json";
file_put_contents($classBackupFile, json_encode($classBackup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "Backup clases -> {$classBackupFile}\n\n";

// ---------------------------------------------------------------------------
// 2) Respaldo del draft actual
// ---------------------------------------------------------------------------
$draftjson = $DB->get_field('gmk_academic_periods', 'draft_schedules', ['id' => $periodid]);
$draftBackupFile = "{$backupDir}/draft_period{$periodid}_{$stamp}.json";
file_put_contents($draftBackupFile, (string)$draftjson);
echo "Backup draft -> {$draftBackupFile} (" . strlen((string)$draftjson) . " bytes)\n\n";

// ---------------------------------------------------------------------------
// 3) Eliminar las clases resucitadas (delete_class = limpieza completa)
// ---------------------------------------------------------------------------
echo "--- Eliminación de clases ---\n";
foreach ($ids as $cid) {
    if (!isset($classBackup[$cid])) {
        continue;
    }
    $name = $classBackup[$cid]['class']->name;
    if (!$apply) {
        echo "  [dry-run] delete_class({$cid})  {$name}\n";
        continue;
    }
    try {
        delete_class($cid, 'Limpieza incidente 2026-07-21: clase resucitada por publicación con estado obsoleto del tablero');
        echo "  OK eliminada {$cid}  {$name}\n";
    } catch (\Throwable $e) {
        echo "  ERROR eliminando {$cid}: " . $e->getMessage() . "\n";
    }
}
echo "\n";

// ---------------------------------------------------------------------------
// 4) Barrido de fichas fantasma del draft
//    (id numérico que ya no existe como clase real del periodo)
// ---------------------------------------------------------------------------
echo "--- Barrido de fichas fantasma del draft ---\n";
$draftjson = $DB->get_field('gmk_academic_periods', 'draft_schedules', ['id' => $periodid]);
$items = json_decode((string)$draftjson, true);
if (!is_array($items)) {
    echo "  Draft vacío o no parseable, nada que barrer.\n";
} else {
    $validids = $DB->get_fieldset_select('gmk_class', 'id', 'periodid = ?', [$periodid]);
    $validset = [];
    foreach ($validids as $v) {
        $validset[(int)$v] = true;
    }
    $kept = [];
    $ghosts = [];
    foreach ($items as $it) {
        $rid = is_array($it) ? ($it['id'] ?? null) : null;
        $isExternal = is_array($it) && !empty($it['isExternal']);
        if (is_numeric($rid) && (int)$rid > 0 && !$isExternal && !isset($validset[(int)$rid])) {
            $ghosts[] = [(int)$rid, is_array($it) ? ($it['subjectName'] ?? '') : ''];
            continue;
        }
        $kept[] = $it;
    }
    echo "  Fichas en draft: " . count($items) . " | fantasmas: " . count($ghosts) . " | quedan: " . count($kept) . "\n";
    foreach ($ghosts as $g) {
        echo "    - fantasma id={$g[0]}  {$g[1]}\n";
    }
    if ($apply && count($ghosts) > 0) {
        $DB->set_field('gmk_academic_periods', 'draft_schedules', json_encode(array_values($kept)), ['id' => $periodid]);
        echo "  Draft actualizado (fantasmas removidos).\n";
    } else if (!$apply) {
        echo "  [dry-run] no se modificó el draft.\n";
    }
}

echo "\n=== FIN " . ($apply ? "(cambios aplicados)" : "(dry-run, sin cambios)") . " ===\n";
