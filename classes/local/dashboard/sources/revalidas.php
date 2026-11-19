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
 * Fuente: revalidas con la sesion ya pasada y sin nota (la pone el docente;
 * el personal le da seguimiento) y revalidas sin factura (reintentar).
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\dashboard\sources;

use local_grupomakro_core\local\dashboard\source;

defined('MOODLE_INTERNAL') || die();

class revalidas extends source {

    public function key(): string {
        return 'revalidas';
    }

    public function label(): string {
        return 'Revalidas';
    }

    public function icon(): string {
        return 'mdi-file-certificate-outline';
    }

    public function page(): string {
        return 'revalidations_director.php';
    }

    public function capabilities(): array {
        return ['view_revalidations_dashboard'];
    }

    public function fetch(int $userid, bool $team, int $now): array {
        global $DB;
        $rows = $DB->get_records_sql("SELECT r.id, r.sessionend, r.timecreated, r.invoice_id, r.payment_state,
                                             u.firstname, u.lastname, c.name AS classname,
                                             t.firstname AS tfirst, t.lastname AS tlast
                                        FROM {gmk_revalidations} r
                                        JOIN {user} u ON u.id = r.userid
                                   LEFT JOIN {gmk_class} c ON c.id = r.classid
                                   LEFT JOIN {user} t ON t.id = c.instructorid
                                       WHERE r.status = 'scheduled'
                                         AND ((r.sessionend > 0 AND r.sessionend < :now)
                                              OR (r.payment_state = 'unpaid' AND (r.invoice_id IS NULL OR r.invoice_id = '')
                                                  AND r.timecreated < :recent))
                                    ORDER BY r.timecreated ASC",
            ['now' => $now, 'recent' => $now - 10 * MINSECS], 0, self::MAX_ROWS);
        $out = [];
        foreach ($rows as $r) {
            $who = trim($r->firstname . ' ' . $r->lastname) . ($r->classname ? ' · ' . format_string($r->classname) : '');
            if ((int)$r->sessionend > 0 && (int)$r->sessionend < $now) {
                $teacher = trim((string)$r->tfirst . ' ' . (string)$r->tlast);
                $out[] = $this->item('revalida.grade', 'REV-' . $r->id . '-nota', $who,
                    'Sin nota desde el ' . self::date((int)$r->sessionend) . ($teacher !== '' ? ' · docente: ' . $teacher : ''),
                    (int)$r->sessionend, 0, 0, $this->url());
            }
            if ($r->payment_state === 'unpaid' && trim((string)$r->invoice_id) === '') {
                $out[] = $this->item('revalida.invoice', 'REV-' . $r->id . '-factura', $who,
                    'La factura no se generó: reintentar', (int)$r->timecreated, 0, 0, $this->url());
            }
        }
        return $out;
    }
}
