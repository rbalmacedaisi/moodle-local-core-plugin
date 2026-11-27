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
 * Fuente: retiros en manos de Bienestar (20261001135).
 *   - Nuevas sin tomar (cola del rol).
 *   - En gestion: seguimiento vencido o caso sin movimiento (del gestor asignado).
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\dashboard\sources;

use local_grupomakro_core\local\dashboard\source;

defined('MOODLE_INTERNAL') || die();

class withdrawals extends source {

    public function key(): string {
        return 'withdrawals';
    }

    public function label(): string {
        return 'Retiros: gestión de retención';
    }

    public function icon(): string {
        return 'mdi-account-arrow-right';
    }

    public function page(): string {
        return 'withdrawal_requests.php';
    }

    public function capabilities(): array {
        return ['manage_wdr_requests'];
    }

    public function fetch(int $userid, bool $team, int $now): array {
        global $DB;
        $rows = $DB->get_records_select('gmk_wdr', "status IN ('solicitada', 'en_gestion')", null, 'timecreated ASC',
            'id, request_number, fullname, program, status, channel, assigned_to, next_followup, last_activity_at, timecreated, timemodified',
            0, self::MAX_ROWS);
        $out = [];
        foreach ($rows as $r) {
            $url = $this->url(['id' => $r->id]);
            if ($r->status === 'solicitada') {
                $out[] = $this->item('wdr.receive', (string)$r->request_number,
                    $r->request_number . ' · ' . $r->fullname,
                    'Nueva: tomar el caso y contactar al estudiante' . ($r->program ? ' · ' . $r->program : ''),
                    (int)$r->timecreated, 0, 0, $url);
                continue;
            }
            $owner = (int)$r->assigned_to;
            if ((int)$r->next_followup > 0) {
                $out[] = $this->item('wdr.followup', (string)$r->request_number,
                    $r->request_number . ' · ' . $r->fullname,
                    'Seguimiento programado para el ' . self::date((int)$r->next_followup, true),
                    0, (int)$r->next_followup, $owner, $url);
            } else {
                $since = (int)($r->last_activity_at ?: $r->timemodified);
                $out[] = $this->item('wdr.manage', (string)$r->request_number,
                    $r->request_number . ' · ' . $r->fullname,
                    'En gestión sin seguimiento programado · última gestión ' . self::date($since), $since, 0, $owner, $url);
            }
        }
        return $out;
    }
}
