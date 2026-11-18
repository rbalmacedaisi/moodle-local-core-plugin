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
 * Descarga/vista de un archivo entregado en un módulo, desde la gestión de
 * módulos. Permite revisar la entrega a quien gestiona módulos aunque no
 * tenga el permiso de calificar tareas en el curso.
 *
 * @package     local_grupomakro_core
 * @copyright   2026 Solutto Consulting
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/grupomakro_core:manage_modules', $context);

$enrollmentid = required_param('enrollmentid', PARAM_INT);
$fileid       = required_param('fileid', PARAM_INT);
$download     = optional_param('download', 0, PARAM_BOOL);

$file = \local_grupomakro_core\module_grading::get_submission_file($enrollmentid, $fileid);
if (!$file) {
    throw new moodle_exception('filenotfound', 'error');
}
\core\session\manager::write_close();
send_stored_file($file, 0, 0, (bool)$download);
