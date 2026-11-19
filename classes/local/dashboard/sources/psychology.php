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
 * Fuente: citas de psicologia sin confirmar y citas pasadas sin cerrar.
 *
 * PRIVADA: ningun item lleva nombre del estudiante ni motivo, ni siquiera para
 * el psicologo (el detalle esta en su panel). Responsable = psychologist_userid.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\dashboard\sources;

use local_grupomakro_core\local\dashboard\source;

defined('MOODLE_INTERNAL') || die();

class psychology extends source {

    public function key(): string {
        return 'psychology';
    }

    public function label(): string {
        return 'Citas de psicología';
    }

    public function icon(): string {
        return 'mdi-head-heart-outline';
    }

    public function page(): string {
        return 'wellness_psychology_panel.php';
    }

    public function capabilities(): array {
        return ['manage_psychology_appointments'];
    }

    public function is_private(): bool {
        return true;
    }

    public function fetch(int $userid, bool $team, int $now): array {
        global $DB;
        $rows = $DB->get_records_sql("SELECT id, status, appointment_at, psychologist_userid, timecreated
                                        FROM {gmk_wellness_psy_appts}
                                       WHERE status = 'pendiente'
                                          OR (status IN ('confirmada', 'modificada') AND appointment_at > 0 AND appointment_at < :now)
                                    ORDER BY timecreated ASC", ['now' => $now], 0, self::MAX_ROWS);
        $out = [];
        foreach ($rows as $r) {
            $confirm = $r->status === 'pendiente';
            $out[] = $this->item($confirm ? 'psy.confirm' : 'psy.close', 'CITA-' . $r->id,
                'Cita del ' . self::date((int)$r->appointment_at, true),
                $confirm ? 'Solicitada, falta confirmar' : 'Ya pasó: marcar atendida o no asistió',
                $confirm ? (int)$r->timecreated : (int)$r->appointment_at, 0, (int)$r->psychologist_userid, $this->url());
        }
        return $out;
    }
}
