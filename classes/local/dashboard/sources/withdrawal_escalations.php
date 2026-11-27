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
 * Fuente: escalamientos de casos de retiro asignados a la persona (20261001135).
 * Es "mia" solo para quien tiene un escalamiento abierto, aunque no gestione retiros.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\dashboard\sources;

use local_grupomakro_core\local\dashboard\source;

defined('MOODLE_INTERNAL') || die();

class withdrawal_escalations extends source {

    public function key(): string {
        return 'withdrawal_escalations';
    }

    public function label(): string {
        return 'Retiros: casos escalados';
    }

    public function icon(): string {
        return 'mdi-arrow-up-bold-circle-outline';
    }

    public function page(): string {
        return 'withdrawal_requests.php';
    }

    public function capabilities(): array {
        return ['manage_wdr_requests'];
    }

    public function owns(int $userid): bool {
        global $DB;
        return $DB->record_exists('gmk_wdr_log', ['type' => 'escalamiento', 'assignee' => $userid, 'resolved_at' => 0]);
    }

    public function queue_users(): array {
        return [];
    }

    public function fetch(int $userid, bool $team, int $now): array {
        global $DB;
        $rows = $DB->get_records_sql("SELECT l.id, l.wdrid, l.area, l.assignee, l.due, l.timecreated, w.request_number, w.fullname
                FROM {gmk_wdr_log} l JOIN {gmk_wdr} w ON w.id = l.wdrid
               WHERE l.type = 'escalamiento' AND l.resolved_at = 0
            ORDER BY l.timecreated ASC", [], 0, self::MAX_ROWS);
        $out = [];
        foreach ($rows as $r) {
            $area = \local_grupomakro_core\local\wdr_flow::AREAS[$r->area]['label'] ?? $r->area;
            $out[] = $this->item('wdr.escalation', 'ESC-' . $r->id, $r->request_number . ' · ' . $r->fullname,
                'Escalado a ' . $area . ($r->due ? ' · responder antes del ' . self::date((int)$r->due) : ''),
                (int)$r->timecreated, 0, (int)$r->assignee, $this->url(['id' => $r->wdrid]));
        }
        return $out;
    }
}
