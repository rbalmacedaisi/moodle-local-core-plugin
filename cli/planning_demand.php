<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Demanda por cohorte de un periodo academico (SOLO LECTURA).
 *
 *   php planning_demand.php --period=9 [--plan=14] [--student=USERID] [--json=/ruta/salida.json]
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Grupo Makro
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_grupomakro_core\local\planning\demand_engine;

list($options, $unrecognized) = cli_get_params(
    ['period' => 0, 'plan' => '', 'student' => 0, 'json' => '', 'help' => false],
    ['h' => 'help']
);
if ($options['help'] || empty($options['period'])) {
    echo "Uso: php planning_demand.php --period=ID [--plan=ID,ID] [--student=USERID] [--json=archivo]\n";
    exit(0);
}
$plans = $options['plan'] !== '' ? array_map('intval', explode(',', $options['plan'])) : [];
$t0 = microtime(true);
$engine = new demand_engine((int)$options['period'], $plans);
$res = $engine->compute();
if (!empty($res['error'])) {
    cli_error($res['error']);
}
$secs = round(microtime(true) - $t0, 2);

if ($options['json'] !== '') {
    file_put_contents($options['json'], json_encode($res, JSON_UNESCAPED_UNICODE));
    echo "JSON: {$options['json']}\n";
}

if (!empty($options['student'])) {
    foreach ($res['students'] as $s) {
        if ($s['userid'] == $options['student']) {
            echo json_encode($s, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        }
    }
    exit(0);
}

echo "Periodo {$res['period']['name']}  bloque1 " . date('Y-m-d', $res['blocks'][1]['start'])
    . "  bloque2 " . date('Y-m-d', $res['blocks'][2]['start']) . "  ({$secs}s)\n";
echo "Totales: " . json_encode($res['totals']) . "\n";
echo "Issues: " . count($res['issues']) . "\n\n";

echo "COHORTES (plan | ingreso | jornada | tam | nivel B1 | desfasados)\n";
foreach ($res['cohorts'] as $c) {
    printf("  %-3d %-10s %-9s %3d  %-6s %d\n", $c['planid'], $c['ingreso'], $c['jornada'] ?: '-', $c['size'],
        $c['level1'], $c['desfasados']);
}
echo "\nDEMANDA (bloque | asignatura | jornada | listos | niv/atr/rep/adel | condic | bloqueados)\n";
foreach ($res['demand'] as $d) {
    $bc = $d['by_category'];
    printf("  B%d %-55s %-9s %3d  %d/%d/%d/%d  %d  %d\n", $d['block'], mb_substr($d['name'], 0, 55), $d['jornada'] ?: '-',
        $d['ready'], $bc['nivel'], $bc['atrasada'], $bc['repite'], $bc['adelanto'], $d['conditional'], $d['blocked']);
}
