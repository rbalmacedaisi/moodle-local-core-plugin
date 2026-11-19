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
 * Fuente: clases terminadas hace mas de 7 dias que siguen abiertas (la tarea
 * close_expired_schedules no pudo cerrarlas: pesos distintos de 100 % o
 * asistencia sin marcar). El reloj del ANS empieza al terminar la gracia.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\dashboard\sources;

use local_grupomakro_core\local\dashboard\source;

defined('MOODLE_INTERNAL') || die();

class class_closing extends source {

    const GRACE_DAYS = 7;

    public function key(): string {
        return 'class_closing';
    }

    public function label(): string {
        return 'Clases sin cerrar';
    }

    public function icon(): string {
        return 'mdi-lock-clock';
    }

    public function page(): string {
        return 'manage_courses.php';
    }

    public function capabilities(): array {
        return ['manage_courses'];
    }

    public function fetch(int $userid, bool $team, int $now): array {
        global $DB;
        $rows = $DB->get_records_sql("SELECT c.id, c.name, c.enddate, u.firstname, u.lastname
                                        FROM {gmk_class} c
                                   LEFT JOIN {user} u ON u.id = c.instructorid
                                       WHERE c.closed = 0 AND c.enddate > 0 AND c.enddate < :lim
                                    ORDER BY c.enddate ASC",
            ['lim' => $now - self::GRACE_DAYS * DAYSECS], 0, self::MAX_ROWS);
        $out = [];
        foreach ($rows as $r) {
            $teacher = trim((string)$r->firstname . ' ' . (string)$r->lastname);
            $out[] = $this->item('class.closing', 'CLASE-' . $r->id, format_string($r->name),
                'Terminó el ' . self::date((int)$r->enddate) . ($teacher !== '' ? ' · ' . $teacher : ''),
                (int)$r->enddate + self::GRACE_DAYS * DAYSECS, 0, 0, $this->url());
        }
        return $out;
    }
}
