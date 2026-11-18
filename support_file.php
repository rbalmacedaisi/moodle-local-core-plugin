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
 * Mesa de ayuda: entrega un adjunto del chat.
 *
 *   support_file.php?id=<file id>               bandeja / Ayuda TI (sesion)
 *   support_file.php?id=<file id>&token=<wstoken>  portal del estudiante
 *   &download=1 fuerza la descarga
 *
 * Mismo control de acceso que el ticket (attachments::file_for): el
 * solicitante y el personal que puede verlo; las notas internas nunca al
 * solicitante; los tickets confidenciales de Psicologia siguen su regla. Si
 * no corresponde, 404 (no revela que el archivo existe). send_stored_file
 * atiende pedidos por rangos (los videos avanzan sin bajar todo) y con
 * objectfs puede redirigir al almacenamiento externo.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$usetoken = !empty($_GET['token']);
if ($usetoken) {
    define('NO_MOODLE_COOKIES', true);
}

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

use local_grupomakro_core\local\support\attachments;

$fileid = required_param('id', PARAM_INT);
$download = optional_param('download', 0, PARAM_BOOL);

if ($usetoken) {
    require_once($CFG->dirroot . '/webservice/lib.php');
    try {
        $webservicelib = new webservice();
        $webservicelib->authenticate_user(required_param('token', PARAM_ALPHANUM)); // Sets $USER.
    } catch (Throwable $e) {
        send_file_not_found();
    }
} else {
    require_login(null, false);
}
if (isguestuser() || empty($USER->id)) {
    send_file_not_found();
}

$file = attachments::file_for($fileid, (int)$USER->id);
if (!$file) {
    send_file_not_found();
}
\core\session\manager::write_close();
header('X-Content-Type-Options: nosniff');
// Private: never cached by shared proxies; a day in the user's browser.
send_stored_file($file, DAYSECS, 0, (bool)$download, ['cacheability' => 'private']);
