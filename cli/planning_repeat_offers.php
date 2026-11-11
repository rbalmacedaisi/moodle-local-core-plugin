<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Aviso a repitentes de clases aprobadas.
 *
 *   php planning_repeat_offers.php --period=9            -> simulacion: lista a quien se avisaria
 *   php planning_repeat_offers.php --period=9 --send     -> envia los avisos pendientes
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Grupo Makro
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_grupomakro_core\local\planning\repeat_offers;

list($options, $unrecognized) = cli_get_params(['period' => 0, 'send' => false, 'help' => false], ['h' => 'help']);
if ($options['help'] || empty($options['period'])) {
    echo "Uso: php planning_repeat_offers.php --period=ID [--send]\n";
    exit(0);
}
$sum = repeat_offers::send((int)$options['period'], !empty($options['send']));
echo (empty($options['send']) ? "SIMULACION (no se envio nada)\n" : "ENVIADO\n");
echo "pendientes={$sum['pendientes']} enviados={$sum['enviados']} fallidos={$sum['fallidos']} ya_avisados={$sum['ya_avisados']}\n";
foreach ($sum['detalle'] as $d) {
    echo "  $d\n";
}
