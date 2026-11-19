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
 * Dashboard administrativo: resumen diario de pendientes. Corre cada hora y
 * envia una vez por dia habil desde dash_digest_hour (dash_digest_enabled).
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\task;

use local_grupomakro_core\local\dashboard\escalation;

defined('MOODLE_INTERNAL') || die();

class dashboard_digest extends \core\task\scheduled_task {

    public function get_name() {
        return get_string('task_dashboard_digest', 'local_grupomakro_core');
    }

    public function execute() {
        $stats = escalation::digest();
        mtrace('dashboard_digest: ' . json_encode($stats));
    }
}
