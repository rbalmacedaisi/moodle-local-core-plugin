<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Cartas (20261001130): asignacion de las que no tienen responsable, alertas de
 * plazo (una por nivel y estado, agrupadas por persona), recordatorios al
 * estudiante y resumen diario. Cada 15 minutos. Idempotente.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\task;

use local_grupomakro_core\local\letters\sla;

defined('MOODLE_INTERNAL') || die();

class letters_sla extends \core\task\scheduled_task {

    public function get_name() {
        return get_string('task_letters_sla', 'local_grupomakro_core');
    }

    public function execute() {
        $stats = sla::run();
        mtrace('letters_sla: ' . json_encode($stats));
    }
}
