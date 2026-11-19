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
 * Fuente: casos de seguimiento de inasistencias (local\absence\cases).
 *
 * Desde 20261001131 el pendiente ya no es el bloqueo sino la GESTION del caso
 * (Ramiro, 19-nov-2026: "que desaparezcan del dashboard los que se les ha hecho
 * seguimiento hasta la nueva fecha de seguimiento"):
 *  - caso sin ninguna gestion -> 'absence.block' desde el bloqueo;
 *  - caso con el proximo seguimiento vencido -> 'absence.followup' desde esa fecha;
 *  - caso gestionado y al dia, o cerrado -> no cuenta.
 * Solo bloqueos de clases en curso (los casos se abren y cierran solos).
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\dashboard\sources;

use local_grupomakro_core\local\absence\cases;
use local_grupomakro_core\local\dashboard\source;

defined('MOODLE_INTERNAL') || die();

class absence_blocks extends source {

    public function key(): string {
        return 'absence_blocks';
    }

    public function label(): string {
        return 'Seguimiento de inasistencias';
    }

    public function icon(): string {
        return 'mdi-account-cancel';
    }

    public function page(): string {
        return 'absence_cases.php';
    }

    public function capabilities(): array {
        return ['manage_absence_cases'];
    }

    public function fetch(int $userid, bool $team, int $now): array {
        global $DB;
        cases::sync($now);
        $rows = $DB->get_records_sql("SELECT k.id, k.blocked_at, k.timecreated, k.actions, k.next_followup, k.last_result,
                                             u.firstname, u.lastname, c.name AS classname, a.absence_count
                                        FROM {gmk_abs_case} k
                                        JOIN {user} u ON u.id = k.userid
                                        JOIN {gmk_class} c ON c.id = k.classid
                                   LEFT JOIN {gmk_class_absence_state} a ON a.userid = k.userid AND a.classid = k.classid
                                       WHERE k.status <> :closed
                                         AND (k.actions = 0 OR (k.next_followup > 0 AND k.next_followup <= :now))
                                    ORDER BY k.blocked_at ASC",
            ['closed' => cases::S_CLOSED, 'now' => $now], 0, self::MAX_ROWS);
        $out = [];
        foreach ($rows as $r) {
            $who = trim($r->firstname . ' ' . $r->lastname) . ' · ' . format_string($r->classname);
            if ((int)$r->actions === 0) {
                $out[] = $this->item('absence.block', 'ABS-' . $r->id, $who,
                    (int)$r->absence_count . ' inasistencia(s) · bloqueado el ' . self::date((int)$r->blocked_at)
                    . ' · sin gestión', cases::clock_start($r), 0, 0, cases::url((int)$r->id));
            } else {
                $out[] = $this->item('absence.followup', 'ABS-' . $r->id, $who,
                    'Seguimiento programado para el ' . self::date((int)$r->next_followup)
                    . ($r->last_result ? ' · último resultado: ' . (cases::RESULTS[$r->last_result] ?? $r->last_result) : ''),
                    (int)$r->next_followup, 0, 0, cases::url((int)$r->id));
            }
        }
        return $out;
    }
}
