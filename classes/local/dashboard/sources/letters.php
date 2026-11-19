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
 * Fuente: solicitudes de cartas.
 *
 * Contrato acordado con la sesion de cartas (PLAN_DASHBOARD_ADMINISTRATIVO.md
 * §3): si letters\manager::get_pending_for_staff() existe, se usa tal cual
 * (trae su nivel y su responsable; sus alertas las envia la tarea letters_sla).
 * Mientras no exista, se cuenta por estado + timemodified con los ANS
 * letters.* de este dashboard.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\dashboard\sources;

use local_grupomakro_core\local\dashboard\source;
use local_grupomakro_core\local\letters\manager as letters_manager;

defined('MOODLE_INTERNAL') || die();

class letters extends source {

    const TYPE_BY_STATUS = [
        'pendiente_gestion' => 'letters.elaborar',
        'pendiente_recoleccion' => 'letters.sin_recoger',
        'pagada' => 'letters.atascada',
        'solicitada' => 'letters.atascada',
    ];

    const LABELS = [
        'pendiente_gestion' => 'Por elaborar',
        'pendiente_recoleccion' => 'Lista, sin recoger',
        'pagada' => 'Pagada sin generar',
        'solicitada' => 'Solicitada sin avanzar',
    ];

    public function key(): string {
        return 'letters';
    }

    public function label(): string {
        return 'Cartas';
    }

    public function icon(): string {
        return 'mdi-email-newsletter';
    }

    public function page(): string {
        return 'letterrequests.php';
    }

    public function capabilities(): array {
        return ['managerequests'];
    }

    public static function has_contract(): bool {
        return class_exists(letters_manager::class) && method_exists(letters_manager::class, 'get_pending_for_staff');
    }

    public function external_sla(): bool {
        return self::has_contract();
    }

    public function fetch(int $userid, bool $team, int $now): array {
        if (self::has_contract()) {
            return $this->from_contract($userid);
        }
        global $DB;
        [$in, $params] = $DB->get_in_or_equal(array_keys(self::TYPE_BY_STATUS), SQL_PARAMS_NAMED, 'ls');
        // A request that just changed passes on its own (paid -> generated); give it 15 min before counting it as stuck.
        $params['recent'] = $now - 15 * MINSECS;
        $rows = $DB->get_records_sql("SELECT r.id, r.status, r.timecreated, r.timemodified, u.firstname, u.lastname,
                                             t.name AS typename
                                        FROM {gmk_letter_request} r
                                        JOIN {user} u ON u.id = r.userid
                                   LEFT JOIN {gmk_letter_type} t ON t.id = r.lettertypeid
                                       WHERE r.status $in
                                         AND (r.status NOT IN ('pagada', 'solicitada') OR r.timemodified < :recent)
                                    ORDER BY r.timemodified ASC", $params, 0, self::MAX_ROWS);
        $out = [];
        foreach ($rows as $r) {
            $out[] = $this->item(self::TYPE_BY_STATUS[$r->status], 'CARTA-' . $r->id,
                trim($r->firstname . ' ' . $r->lastname) . ($r->typename ? ' · ' . format_string($r->typename) : ''),
                self::LABELS[$r->status] . ' desde el ' . self::date((int)$r->timemodified, true),
                (int)($r->timemodified ?: $r->timecreated), 0, 0,
                $this->url(['status' => $r->status]));
        }
        return $out;
    }

    private function from_contract(int $userid): array {
        // 'team' = the whole open queue for anyone with managerequests (unassigned
        // included); the dashboard keeps owner = me or none for "lo mio".
        $res = letters_manager::get_pending_for_staff($userid, 'team', self::MAX_ROWS);
        $out = [];
        foreach ((array)($res['items'] ?? []) as $it) {
            $it = (object)$it;
            $item = $this->item('letters.' . (string)($it->type ?? ''), (string)($it->ref ?? ''), (string)($it->title ?? ''),
                (string)($it->subtitle ?? ''), (int)($it->since ?? 0), 0, (int)($it->owner_userid ?? 0),
                (string)($it->url ?? $this->url()));
            $item->level = (int)($it->level ?? 0);
            $item->due = (int)($it->due ?? 0);
            $out[] = $item;
        }
        return $out;
    }
}
