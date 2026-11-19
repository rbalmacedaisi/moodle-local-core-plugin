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
 * Fuente: estudiantes bloqueados por inasistencia en clases EN CURSO, sin
 * revisar (alert_level 3, blocked_at sin unblocked_at posterior).
 *
 * Solo clases que aun no terminan (decision de Ramiro, 19-nov-2026): en una
 * clase ya terminada el bloqueo es el resultado normal (perdio la clase por
 * faltas) y no queda nada que revisar; contarlos solo metia ruido (91 de 137).
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\dashboard\sources;

use local_grupomakro_core\local\dashboard\source;

defined('MOODLE_INTERNAL') || die();

class absence_blocks extends source {

    public function key(): string {
        return 'absence_blocks';
    }

    public function label(): string {
        return 'Bloqueos por inasistencia';
    }

    public function icon(): string {
        return 'mdi-account-cancel';
    }

    public function page(): string {
        return 'absence_dashboard.php';
    }

    public function capabilities(): array {
        return ['viewabsencedashboard'];
    }

    public function fetch(int $userid, bool $team, int $now): array {
        global $DB;
        $rows = $DB->get_records_sql("SELECT a.id, a.classid, a.absence_count, a.blocked_at, u.firstname, u.lastname,
                                             c.name AS classname
                                        FROM {gmk_class_absence_state} a
                                        JOIN {gmk_class} c ON c.id = a.classid AND c.closed = 0
                                             AND (c.enddate IS NULL OR c.enddate = 0 OR c.enddate > :now)
                                        JOIN {user} u ON u.id = a.userid AND u.deleted = 0
                                       WHERE a.alert_level = 3 AND a.blocked_at > 0
                                         AND (a.unblocked_at IS NULL OR a.unblocked_at < a.blocked_at)
                                    ORDER BY a.blocked_at ASC", ['now' => $now], 0, self::MAX_ROWS);
        $out = [];
        foreach ($rows as $r) {
            $out[] = $this->item('absence.block', 'ABS-' . $r->id,
                trim($r->firstname . ' ' . $r->lastname) . ' · ' . format_string($r->classname),
                (int)$r->absence_count . ' inasistencia(s) · bloqueado el ' . self::date((int)$r->blocked_at),
                (int)$r->blocked_at, 0, 0, $this->url(['classid' => (int)$r->classid]));
        }
        return $out;
    }
}
