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
 * Fuente: inscripciones a modulo independiente vencidas y sin completar.
 *
 * Ningun proceso cierra un modulo al pasar su fecha limite: se cierra a mano con
 * "Completar modulo" (module_management.php), que calcula la nota final y no deja
 * cerrar con entregas sin calificar. Sin cerrar, la inscripcion sigue activa y la
 * asignatura queda en "Cursando" indefinidamente (casos de 2026-11-19). La bandeja
 * solo avisa; el cierre sigue siendo manual.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\dashboard\sources;

use local_grupomakro_core\local\dashboard\source;

defined('MOODLE_INTERNAL') || die();

class modules_overdue extends source {

    public function key(): string {
        return 'modules_overdue';
    }

    public function label(): string {
        return 'Módulos vencidos sin completar';
    }

    public function icon(): string {
        return 'mdi-book-clock-outline';
    }

    public function page(): string {
        return 'module_management.php';
    }

    public function capabilities(): array {
        return ['manage_modules'];
    }

    public function fetch(int $userid, bool $team, int $now): array {
        global $DB;
        $rows = $DB->get_records_sql("SELECT me.id, me.duedate, u.firstname, u.lastname, c.name
                                        FROM {gmk_module_enrollment} me
                                        JOIN {user} u ON u.id = me.userid AND u.deleted = 0
                                        JOIN {gmk_class} c ON c.id = me.classid
                                       WHERE me.status = 'active' AND me.duedate > 0 AND me.duedate < :now
                                    ORDER BY me.duedate ASC",
            ['now' => $now], 0, self::MAX_ROWS);
        $out = [];
        foreach ($rows as $r) {
            $out[] = $this->item('modules.overdue', 'MODVENC-' . $r->id,
                trim($r->firstname . ' ' . $r->lastname) . ' · ' . format_string($r->name),
                'Venció el ' . self::date((int)$r->duedate) . ' · completar o retirar en Gestión de módulos',
                (int)$r->duedate, 0, 0, $this->url());
        }
        return $out;
    }
}
