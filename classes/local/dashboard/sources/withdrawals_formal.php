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
 * Fuente: retiros enviados a retiro formal (Secretaria Academica y Registros,
 * 20261001135). Cuenta desde que Bienestar los envio.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\dashboard\sources;

use local_grupomakro_core\local\dashboard\source;

defined('MOODLE_INTERNAL') || die();

class withdrawals_formal extends source {

    public function key(): string {
        return 'withdrawals_formal';
    }

    public function label(): string {
        return 'Retiros: retiro formal';
    }

    public function icon(): string {
        return 'mdi-file-sign';
    }

    public function page(): string {
        return 'withdrawal_requests.php';
    }

    public function capabilities(): array {
        return ['process_wdr_formal'];
    }

    public function fetch(int $userid, bool $team, int $now): array {
        global $DB;
        $rows = $DB->get_records_select('gmk_wdr', "status IN ('retiro_formal', 'pendiente_firma_presencial', 'firmada_digital',
                'recibida_direccion_academica', 'recibida_direccion_administrativa')", null, 'timecreated ASC',
            'id, request_number, fullname, program, formal_requested_at, student_signed_at, received_da_at, received_admin_at,
             scanned_pdf_path, timemodified', 0, self::MAX_ROWS);
        $out = [];
        foreach ($rows as $r) {
            $missing = [];
            if (empty($r->student_signed_at)) {
                $missing[] = 'firma';
            }
            if (empty($r->received_da_at)) {
                $missing[] = 'recibido DA';
            }
            if (empty($r->received_admin_at)) {
                $missing[] = 'recibido DAdm';
            }
            if (empty($r->scanned_pdf_path)) {
                $missing[] = 'escaneado';
            }
            $out[] = $this->item('wdr.process', (string)$r->request_number, $r->request_number . ' · ' . $r->fullname,
                $missing ? 'Falta: ' . implode(', ', $missing) : 'Checklist completo: ejecutar el retiro formal',
                (int)($r->formal_requested_at ?: $r->timemodified), 0, 0, $this->url(['id' => $r->id]));
        }
        return $out;
    }
}
