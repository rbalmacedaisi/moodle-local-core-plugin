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
 * Fuente: eventos de Bienestar. Terminados (ultimos 60 dias) con inscritos
 * aun 'confirmada' = falta pasar asistencia; proximos con lista de espera.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\dashboard\sources;

use local_grupomakro_core\local\dashboard\source;

defined('MOODLE_INTERNAL') || die();

class wellness_events extends source {

    public function key(): string {
        return 'wellness_events';
    }

    public function label(): string {
        return 'Eventos de Bienestar';
    }

    public function icon(): string {
        return 'mdi-party-popper';
    }

    public function page(): string {
        return 'wellness_dashboard.php';
    }

    public function capabilities(): array {
        return ['manage_wellness'];
    }

    public function fetch(int $userid, bool $team, int $now): array {
        global $DB;
        $out = [];
        $ended = $DB->get_records_sql("SELECT e.id, e.title, e.startdate, e.enddate,
                                              (SELECT COUNT(1) FROM {gmk_wellness_registration} r
                                                WHERE r.eventid = e.id AND r.status = 'confirmada') AS pending
                                         FROM {gmk_wellness_event} e
                                        WHERE e.active = 1 AND e.startdate > :from AND e.startdate < :now
                                          AND e.enddate > 0 AND e.enddate < :now2
                                     ORDER BY e.enddate ASC",
            ['from' => $now - 60 * DAYSECS, 'now' => $now, 'now2' => $now], 0, self::MAX_ROWS);
        foreach ($ended as $e) {
            if ((int)$e->pending <= 0) {
                continue;
            }
            $out[] = $this->item('wellness.attendance', 'EVT-' . $e->id . '-asistencia', format_string($e->title),
                (int)$e->pending . ' inscrito(s) sin asistencia · terminó el ' . self::date((int)$e->enddate),
                (int)$e->enddate, 0, 0, $this->url());
        }
        $upcoming = $DB->get_records_sql("SELECT e.id, e.title, e.startdate,
                                                 (SELECT COUNT(1) FROM {gmk_wellness_registration} r
                                                   WHERE r.eventid = e.id AND r.status = 'lista_de_espera') AS waiting
                                            FROM {gmk_wellness_event} e
                                           WHERE e.active = 1 AND e.startdate > :now AND e.startdate < :lim
                                        ORDER BY e.startdate ASC",
            ['now' => $now, 'lim' => $now + 90 * DAYSECS], 0, self::MAX_ROWS);
        foreach ($upcoming as $e) {
            if ((int)$e->waiting <= 0) {
                continue;
            }
            $out[] = $this->item('wellness.waitlist', 'EVT-' . $e->id . '-espera', format_string($e->title),
                (int)$e->waiting . ' en lista de espera · el ' . self::date((int)$e->startdate, true),
                0, (int)$e->startdate, 0, $this->url());
        }
        return $out;
    }
}
