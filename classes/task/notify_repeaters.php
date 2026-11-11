<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Aviso diario a repitentes: clase aprobada disponible de la asignatura que reprobaron.
 *
 * Solo actua si planning_notify_repeaters esta activo. Revisa los periodos abiertos que estan por iniciar o
 * recien iniciados (entre 21 dias antes y 45 dias despues del inicio) y envia los avisos pendientes.
 * Ver classes/local/planning/repeat_offers.php.
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Grupo Makro
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\task;

use core\task\scheduled_task;
use local_grupomakro_core\local\planning\repeat_offers;

defined('MOODLE_INTERNAL') || die();

class notify_repeaters extends scheduled_task {

    public function get_name() {
        return get_string('tasknotifyrepeaters', 'local_grupomakro_core');
    }

    public function execute() {
        global $DB;
        if (!repeat_offers::enabled()) {
            mtrace('Aviso a repitentes desactivado (planning_notify_repeaters).');
            return;
        }
        $now = time();
        foreach ($DB->get_records('gmk_academic_periods', ['status' => 1], 'startdate ASC', 'id, name, startdate') as $p) {
            if ($p->startdate < $now - 45 * 86400 || $p->startdate > $now + 21 * 86400) {
                continue;
            }
            $sum = repeat_offers::send((int)$p->id, true);
            mtrace("{$p->name}: enviados {$sum['enviados']}, fallidos {$sum['fallidos']}, ya avisados {$sum['ya_avisados']}");
        }
    }
}
