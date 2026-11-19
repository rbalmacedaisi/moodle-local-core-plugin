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
 * Dashboard administrativo: alertas escalonadas de ANS (responsable,
 * supervisores, gerencia) sin duplicar. Cada 15 minutos; no hace nada con
 * dash_alerts_enabled = 0. La primera pasada registra niveles sin avisar.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\task;

use local_grupomakro_core\local\dashboard\escalation;

defined('MOODLE_INTERNAL') || die();

class dashboard_escalate extends \core\task\scheduled_task {

    public function get_name() {
        return get_string('task_dashboard_escalate', 'local_grupomakro_core');
    }

    public function execute() {
        $stats = escalation::run();
        mtrace('dashboard_escalate: ' . json_encode($stats));
    }
}
