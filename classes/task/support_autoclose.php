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
 * Mesa de ayuda: cierra los tickets en "esperando estudiante" o "resuelto"
 * cuando el estudiante no responde en tkt_close_hours horas HABILES (24 por
 * defecto; reloj del agente asignado o del area, como el semaforo)
 * tras el ultimo mensaje del personal (§5 del plan). Idempotente.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\task;

use local_grupomakro_core\local\support\manager;

defined('MOODLE_INTERNAL') || die();

class support_autoclose extends \core\task\scheduled_task {

    public function get_name() {
        return get_string('task_support_autoclose', 'local_grupomakro_core');
    }

    public function execute() {
        // Fase 2: primero el recordatorio de tkt_remind_hours, luego el cierre.
        $reminded = manager::remind_stale();
        $closed = manager::autoclose_stale();
        mtrace('support_autoclose: ' . $reminded . ' reminder(s), ' . $closed . ' ticket(s) closed');
    }
}
