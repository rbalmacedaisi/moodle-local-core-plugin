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
 * Fuente: clases sin aprobar que empiezan en los proximos 60 dias (o ya
 * empezaron). Fecha limite = initdate (ANS 'class.approval').
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\dashboard\sources;

use local_grupomakro_core\local\dashboard\source;

defined('MOODLE_INTERNAL') || die();

class class_approval extends source {

    public function key(): string {
        return 'class_approval';
    }

    public function label(): string {
        return 'Clases sin aprobar';
    }

    public function icon(): string {
        return 'mdi-clipboard-check-outline';
    }

    public function page(): string {
        // scheduleapproval.php needs id + periodsid: the panel lists them.
        return 'schedulepanel.php';
    }

    public function capabilities(): array {
        return ['manage_schedules'];
    }

    public function fetch(int $userid, bool $team, int $now): array {
        global $DB;
        $rows = $DB->get_records_select('gmk_class',
            'approved = 0 AND closed = 0 AND initdate > 0 AND initdate < :lim',
            ['lim' => $now + 60 * DAYSECS], 'initdate ASC', 'id, name, initdate, shift', 0, self::MAX_ROWS);
        $out = [];
        foreach ($rows as $r) {
            $out[] = $this->item('class.approval', 'CLASE-' . $r->id, format_string($r->name),
                'Inicia el ' . self::date((int)$r->initdate) . ($r->shift ? ' · ' . (string)$r->shift : ''),
                0, (int)$r->initdate, 0, $this->url());
        }
        return $out;
    }
}
