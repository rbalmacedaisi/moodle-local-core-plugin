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
 * Fuente: solicitudes de retiro RET-01 (gmk_wdr) sin recibir o sin procesar.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\dashboard\sources;

use local_grupomakro_core\local\dashboard\source;

defined('MOODLE_INTERNAL') || die();

class withdrawals extends source {

    const RECEIVE = [
        'solicitada' => 'Solicitada',
        'pendiente_firma_presencial' => 'Pendiente de firma presencial',
        'firmada_digital' => 'Firmada (falta sello)',
    ];
    const PROCESS = [
        'recibida_direccion_academica' => 'Recibida por Dirección Académica',
        'recibida_direccion_administrativa' => 'Recibida por Dirección Administrativa',
    ];

    public function key(): string {
        return 'withdrawals';
    }

    public function label(): string {
        return 'Retiros (RET-01)';
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
        $labels = self::RECEIVE + self::PROCESS;
        [$in, $params] = $DB->get_in_or_equal(array_keys($labels), SQL_PARAMS_NAMED, 'wd');
        $rows = $DB->get_records_select('gmk_wdr', "status $in", $params, 'timecreated ASC',
            'id, request_number, fullname, program, status, timecreated, received_da_at, received_admin_at', 0, self::MAX_ROWS);
        $out = [];
        foreach ($rows as $r) {
            $process = isset(self::PROCESS[$r->status]);
            $since = $process ? max((int)$r->received_da_at, (int)$r->received_admin_at) : 0;
            $out[] = $this->item($process ? 'wdr.process' : 'wdr.receive', (string)$r->request_number,
                (string)$r->request_number . ' · ' . (string)$r->fullname,
                $labels[$r->status] . ($r->program ? ' · ' . (string)$r->program : ''),
                $since > 0 ? $since : (int)$r->timecreated, 0, 0, $this->url());
        }
        return $out;
    }
}
