<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Perfil del estudiante (20261001134): envia a Odoo los campos editados en Moodle.
 * Si el proxy u Odoo fallan, la tarea falla y Moodle la reintenta con espera creciente.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\task;

use local_grupomakro_core\local\odoo_profile_sync;

defined('MOODLE_INTERNAL') || die();

class sync_profile_to_odoo extends \core\task\adhoc_task {

    public function execute() {
        $data = json_decode(json_encode($this->get_custom_data()), true) ?: [];
        $userid = (int)($data['userid'] ?? 0);
        $changes = (array)($data['changes'] ?? []);
        if ($userid <= 0 || !$changes) {
            return;
        }
        odoo_profile_sync::send($userid, (int)($data['actorid'] ?? 0), $changes,
            (string)($data['previousdocumentnumber'] ?? ''));
        mtrace('sync_profile_to_odoo: u' . $userid . ' ' . implode(', ', array_keys($changes)));
    }
}
