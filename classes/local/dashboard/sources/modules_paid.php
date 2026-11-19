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
 * Fuente: solicitudes de modulo independiente pagadas y sin matricular.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\dashboard\sources;

use local_grupomakro_core\local\dashboard\source;

defined('MOODLE_INTERNAL') || die();

class modules_paid extends source {

    public function key(): string {
        return 'modules_paid';
    }

    public function label(): string {
        return 'Módulos pagados sin matricular';
    }

    public function icon(): string {
        return 'mdi-book-plus-outline';
    }

    public function page(): string {
        return 'module_management.php';
    }

    public function capabilities(): array {
        return ['manage_modules'];
    }

    public function fetch(int $userid, bool $team, int $now): array {
        global $DB;
        $rows = $DB->get_records_sql("SELECT m.id, m.paidat, m.timemodified, u.firstname, u.lastname, c.fullname
                                        FROM {gmk_module_invoice_requests} m
                                        JOIN {user} u ON u.id = m.userid
                                   LEFT JOIN {course} c ON c.id = m.corecourseid
                                       WHERE m.status = 'paid' AND m.enrolled_classid = 0
                                    ORDER BY m.paidat ASC", [], 0, self::MAX_ROWS);
        $out = [];
        foreach ($rows as $r) {
            $since = (int)$r->paidat ?: (int)$r->timemodified;
            $out[] = $this->item('modules.paid', 'MOD-' . $r->id,
                trim($r->firstname . ' ' . $r->lastname) . ($r->fullname ? ' · ' . format_string($r->fullname) : ''),
                'Pagado el ' . self::date($since, true), $since, 0, 0, $this->url());
        }
        return $out;
    }
}
