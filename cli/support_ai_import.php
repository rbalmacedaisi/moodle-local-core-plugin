<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Mesa de ayuda (20261001126): importa los resultados del asistente semanal
 * (tipificaciones y sugerencias). Solo escribe en gmk_tkt_ai_*: nada llega a
 * los estudiantes hasta que alguien pulsa "Anadir" en la configuracion.
 *
 *   php support_ai_import.php --file=/ruta/resultados.json [--dry-run]
 *
 * Idempotente: el mismo runkey no se importa dos veces y un ticket ya
 * tipificado se omite. --dry-run valida y cuenta todo sin escribir nada.
 * Formato: docs/agents/analisis_semanal_tickets.md. Respaldo manual del WS
 * local_grupomakro_support_ai_submit (mismo codigo).
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_grupomakro_core\local\support\assistant;

list($options, $unrecognized) = cli_get_params(['file' => '', 'dry-run' => false, 'help' => false], ['h' => 'help']);
if ($options['help'] || $options['file'] === '') {
    cli_writeln("Uso: php support_ai_import.php --file=/ruta/resultados.json [--dry-run]");
    exit(0);
}
if (!assistant::enabled()) {
    cli_error('Faltan las tablas gmk_tkt_ai_*: corre primero admin/cli/upgrade.php.');
}
$raw = @file_get_contents((string)$options['file']);
if ($raw === false) {
    cli_error('No se pudo leer ' . $options['file']);
}
$data = json_decode($raw, true);
if (!is_array($data)) {
    cli_error('JSON inválido: ' . json_last_error_msg());
}
try {
    $report = assistant::import($data, (int)get_admin()->id, !empty($options['dry-run']));
} catch (\moodle_exception $e) {
    cli_error('Importación rechazada: ' . ($e->debuginfo ?: $e->getMessage()));
}
echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
exit(0);
