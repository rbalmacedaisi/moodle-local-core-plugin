<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Foto y comparacion del estado academico (solo lectura).
 *
 *   php planning_snapshot.php --take --name=antes_publicar
 *   php planning_snapshot.php --diff=ANTES.json.gz,DESPUES.json.gz [--ignore=timemodified] [--json]
 *   php planning_snapshot.php --list
 *
 * Ver classes/local/planning/state_snapshot.php.
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Grupo Makro
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_grupomakro_core\local\planning\state_snapshot;

list($options, $unrecognized) = cli_get_params(
    ['take' => false, 'name' => '', 'diff' => '', 'ignore' => '', 'json' => false, 'list' => false, 'help' => false],
    ['h' => 'help']
);

if ($options['help'] || (!$options['take'] && !$options['diff'] && !$options['list'])) {
    echo "Foto del estado academico (clases, sesiones, BBB, eventos, matriculas, progreso, planificacion).\n\n";
    echo "  --take --name=X        toma una foto y la guarda\n";
    echo "  --diff=A,B             compara dos fotos (rutas o nombres de archivo)\n";
    echo "  --ignore=col1,col2     columnas que no cuentan como cambio\n";
    echo "  --json                 salida del diff en JSON\n";
    echo "  --list                 lista las fotos guardadas\n";
    exit(0);
}

if ($options['list']) {
    foreach (glob(state_snapshot::dir() . '/*.json.gz') as $f) {
        echo basename($f) . '  ' . round(filesize($f) / 1024) . " KB\n";
    }
    exit(0);
}

if ($options['take']) {
    $t0 = microtime(true);
    $snap = state_snapshot::take($options['name']);
    $path = state_snapshot::save($snap, $options['name']);
    $counts = [];
    foreach (array_keys($snap['tables']) as $t) {
        $n = state_snapshot::count_rows($snap, $t);
        $counts[] = $t . '=' . ($n === null ? 'n/a' : $n);
    }
    echo "Foto guardada: $path\n";
    echo "Base: {$snap['meta']['dbname']}  (" . round(microtime(true) - $t0, 1) . "s)\n";
    echo implode("\n", $counts) . "\n";
    exit(0);
}

$files = array_map('trim', explode(',', $options['diff']));
if (count($files) !== 2) {
    cli_error('--diff necesita dos fotos separadas por coma');
}
$a = state_snapshot::load($files[0]);
$b = state_snapshot::load($files[1]);
if ($a['meta']['dbname'] !== $b['meta']['dbname']) {
    cli_error("Las fotos son de bases distintas ({$a['meta']['dbname']} vs {$b['meta']['dbname']}).");
}
$ignore = $options['ignore'] !== '' ? array_map('trim', explode(',', $options['ignore'])) : [];
$diff = state_snapshot::diff($a, $b, $ignore);
if ($options['json']) {
    echo json_encode($diff, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    echo "Antes:   {$a['meta']['label']} " . date('Y-m-d H:i:s', $a['meta']['time']) . "\n";
    echo "Despues: {$b['meta']['label']} " . date('Y-m-d H:i:s', $b['meta']['time']) . "\n\n";
    echo state_snapshot::summarize($diff);
}
exit($diff ? 2 : 0);
