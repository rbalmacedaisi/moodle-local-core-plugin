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
 * Fuente: lista de control de la planificacion del PROXIMO periodo (el primer
 * gmk_academic_periods con startdate en el futuro). Cada punto incompleto es
 * un pendiente con fecha limite = inicio de clases (la jornada que empieza
 * primero, o el inicio del periodo), ANS 'planning.next'.
 *
 * Solo lee: configsettings (auto_proposal, board_draft, board_published,
 * board_scenarios), gmk_class por periodid y gmk_academic_calendar.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\dashboard\sources;

use local_grupomakro_core\local\dashboard\source;

defined('MOODLE_INTERNAL') || die();

class planning extends source {

    public function key(): string {
        return 'planning';
    }

    public function label(): string {
        return 'Planificación del próximo periodo';
    }

    public function icon(): string {
        return 'mdi-calendar-clock';
    }

    public function page(): string {
        return 'academic_planning.php';
    }

    public function capabilities(): array {
        return ['manage_academic_planning'];
    }

    /** First upcoming period, or null. */
    public static function next_period(int $now): ?\stdClass {
        global $DB;
        $rows = $DB->get_records_select('gmk_academic_periods', 'startdate > :now', ['now' => $now], 'startdate ASC',
            'id, name, startdate, enddate, configsettings', 0, 1);
        return $rows ? reset($rows) : null;
    }

    public function fetch(int $userid, bool $team, int $now): array {
        global $DB;
        $period = self::next_period($now);
        if (!$period) {
            return [];
        }
        $pid = (int)$period->id;
        $cfg = json_decode((string)$period->configsettings, true);
        $cfg = is_array($cfg) ? $cfg : [];

        $deadline = (int)$period->startdate;
        if (!empty($cfg['jornada_starts']) && is_array($cfg['jornada_starts'])) {
            $starts = array_filter(array_map('intval', $cfg['jornada_starts']));
            if ($starts) {
                $deadline = min($deadline, min($starts));
            }
        }
        $name = format_string($period->name);
        $sub = $name . ' · inicia el ' . self::date($deadline);
        $url = $this->url(['periodid' => $pid]);
        $out = [];
        $add = function(string $check, string $title) use (&$out, $pid, $sub, $deadline, $url) {
            $out[] = $this->item('planning.next', 'PLAN-' . $pid . '-' . $check, $title, $sub, 0, $deadline, 0, $url);
        };

        $pub = is_array($cfg['board_published'] ?? null) ? $cfg['board_published'] : [];
        $published = !empty($pub['groups']) || !empty($pub['modules']);
        if (!$published) {
            if (empty($cfg['auto_proposal']) && empty($cfg['board_draft'])) {
                $add('proposal', 'Sin propuesta de grupos en el tablero');
            } else {
                $add('publish', 'Tablero sin publicar');
            }
        }
        if (!empty($pub['pending'])) {
            $add('halfpublished', 'Publicación incompleta: ' . count((array)$pub['pending']) . ' grupo(s) a medias');
        }

        $noteacher = $DB->count_records_select('gmk_class', 'periodid = :p AND closed = 0 AND (instructorid IS NULL OR instructorid = 0)',
            ['p' => $pid]);
        if ($noteacher) {
            $add('teacher', $noteacher . ' clase(s) sin docente');
        }
        $noschedule = (int)$DB->count_records_sql("SELECT COUNT(1) FROM {gmk_class} c
                                                     WHERE c.periodid = :p AND c.closed = 0
                                                       AND NOT EXISTS (SELECT 1 FROM {gmk_class_schedules} s WHERE s.classid = c.id)",
            ['p' => $pid]);
        if ($noschedule) {
            $add('schedule', $noschedule . ' clase(s) sin horario');
        }
        if (!$DB->record_exists('gmk_academic_calendar', ['academicperiodid' => $pid])) {
            $add('calendar', 'Sin calendario académico cargado');
        }
        $pendingscenarios = 0;
        foreach ((array)($cfg['board_scenarios'] ?? []) as $sc) {
            if (is_array($sc) && !empty($sc['effective']) && empty($sc['applied'])) {
                $pendingscenarios++;
            }
        }
        if ($pendingscenarios) {
            $add('scenarios', $pendingscenarios . ' escenario(s) de horario con fecha efectiva sin aplicar');
        }
        return $out;
    }
}
