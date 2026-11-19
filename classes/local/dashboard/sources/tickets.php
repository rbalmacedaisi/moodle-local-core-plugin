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
 * Fuente: tickets de la mesa de ayuda. Usa la visibilidad de la bandeja
 * (support\manager::desk_where) y el semaforo de la mesa (policy): no se
 * recalcula ni se escala aqui (support_escalate ya lo hace).
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\dashboard\sources;

use local_grupomakro_core\local\dashboard\sla;
use local_grupomakro_core\local\dashboard\source;
use local_grupomakro_core\local\support\business_hours;
use local_grupomakro_core\local\support\config as support_config;
use local_grupomakro_core\local\support\manager;
use local_grupomakro_core\local\support\policy;

defined('MOODLE_INTERNAL') || die();

class tickets extends source {

    /** Statuses that are still work for the desk. */
    const PENDING = [manager::S_NEW, manager::S_ASSIGNED, manager::S_INPROGRESS, manager::S_REOPENED];

    public function key(): string {
        return 'tickets';
    }

    public function label(): string {
        return 'Tickets de la mesa de ayuda';
    }

    public function icon(): string {
        return 'mdi-lifebuoy';
    }

    public function page(): string {
        return 'support_desk.php';
    }

    public function capabilities(): array {
        return ['support_agent'];
    }

    public function external_sla(): bool {
        return true;
    }

    public function owns(int $userid): bool {
        return (bool)manager::profile($userid)->isstaff;
    }

    public function fetch(int $userid, bool $team, int $now): array {
        global $DB;
        $p = manager::profile($userid);
        if (!$p->isstaff && !$team) {
            return [];
        }
        $tabs = $team && ($p->supervisor || $p->reassign) ? ['all'] : ['mine', 'unassigned'];
        $rows = [];
        foreach ($tabs as $tab) {
            [$where, $params] = manager::desk_where($userid, $p, $tab, '');
            $sql = "SELECT t.id, t.number, t.subject, t.status, t.assignedto, t.areaid, t.waiting_since, t.confidential
                      FROM {gmk_tkt_ticket} t
                      JOIN {user} s ON s.id = t.userid
                     WHERE $where
                  ORDER BY t.waiting_since ASC";
            foreach ($DB->get_records_sql($sql, $params, 0, self::MAX_ROWS) as $r) {
                $rows[(int)$r->id] = $r;
            }
        }
        $red = support_config::get_int('tkt_sla_red');
        $out = [];
        foreach ($rows as $r) {
            // Only what the staff still has to do (Ramiro, 19-nov-2026): resolved
            // tickets waiting for the requester's confirmation and tickets waiting
            // for the student are not pending for the desk.
            if (!in_array((string)$r->status, self::PENDING, true)) {
                continue;
            }
            $minutes = policy::waiting_minutes($r, $now);
            $tl = policy::level_for($minutes);
            if ($tl >= policy::LEVEL_RED) {
                $level = sla::LEVEL_BREACH;
            } else if ($tl >= policy::LEVEL_YELLOW) {
                $level = sla::LEVEL_WARN;
            } else {
                $level = sla::LEVEL_OK;
            }
            $item = $this->item('tickets', (string)$r->number,
                (string)$r->number . ' · ' . (!empty($r->confidential) ? 'Ticket confidencial' : format_string($r->subject)),
                (int)$r->waiting_since > 0 ? 'Esperando respuesta' : 'Abierto, esperando al solicitante',
                (int)$r->waiting_since, 0, (int)$r->assignedto,
                $this->url(['ticket' => (int)$r->id]));
            $item->level = (int)$r->waiting_since > 0 ? $level : sla::LEVEL_OK;
            $item->due = (int)$r->waiting_since > 0
                ? business_hours::add_minutes((int)$r->waiting_since, $red, policy::clock($r)) : 0;
            $out[] = $item;
        }
        return $out;
    }
}
