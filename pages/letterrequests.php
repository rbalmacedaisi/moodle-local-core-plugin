<?php
// This file is part of Moodle - https://moodle.org/

/**
 * Cartas: la bandeja vieja (tabla con un select de estados y borrado por GET)
 * se reemplazo por pages/letter_desk.php en 20261001130. Se conserva la URL
 * (menus y enlaces guardados) y se redirige.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

require_login();
require_capability('local/grupomakro_core:managerequests', context_system::instance());

$params = [];
$requestid = optional_param('requestid', 0, PARAM_INT);
if ($requestid > 0) {
    $params['request'] = $requestid;
}
redirect(new moodle_url('/local/grupomakro_core/pages/letter_desk.php', $params));
