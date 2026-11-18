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
 * Mesa de ayuda, fase 2: resumen diario a gerencia y supervisores (§6.4). Corre
 * cada hora y envia una sola vez al dia, desde la hora tkt_digest_hour.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\task;

use local_grupomakro_core\local\support\escalation;

defined('MOODLE_INTERNAL') || die();

class support_digest extends \core\task\scheduled_task {

    public function get_name() {
        return get_string('task_support_digest', 'local_grupomakro_core');
    }

    public function execute() {
        $sent = escalation::maybe_send_digest();
        mtrace('support_digest: ' . ($sent ? 'sent to ' . $sent . ' recipient(s)' : 'not due'));
    }
}
