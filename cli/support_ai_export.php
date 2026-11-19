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
 * Mesa de ayuda (20261001126): exporta para el asistente semanal los tickets
 * resueltos o cerrados que aun no se analizaron, YA anonimizados aqui en el
 * servidor. Solo lee (deja un evento ai_export con el conteo, sin contenido).
 *
 *   php support_ai_export.php [--limit=200] [--since=<unix>] [--out=/ruta.json]
 *
 * Sin --out escribe el JSON en la salida estandar (para leerlo por SSH).
 * Excluye Psicologia, temas y tickets confidenciales, notas internas,
 * mensajes borrados y mensajes automaticos.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_grupomakro_core\local\support\assistant;

list($options, $unrecognized) = cli_get_params(['limit' => 200, 'since' => 0, 'out' => '', 'help' => false], ['h' => 'help']);
if ($options['help']) {
    cli_writeln("Uso: php support_ai_export.php [--limit=200] [--since=<unix>] [--out=/ruta.json]\n"
        . "Tickets resueltos/cerrados sin analizar, anonimizados, en JSON.");
    exit(0);
}
if (!assistant::enabled()) {
    cli_error('Faltan las tablas gmk_tkt_ai_*: corre primero admin/cli/upgrade.php.');
}
$data = assistant::export(['limit' => (int)$options['limit'], 'since' => (int)$options['since']]);
$json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if ($json === false) {
    cli_error('No se pudo generar el JSON: ' . json_last_error_msg());
}
if ($options['out'] !== '') {
    if (file_put_contents((string)$options['out'], $json) === false) {
        cli_error('No se pudo escribir ' . $options['out']);
    }
    cli_writeln(count($data['tickets']) . ' tickets exportados a ' . $options['out']);
} else {
    echo $json, "\n";
}
exit(0);
