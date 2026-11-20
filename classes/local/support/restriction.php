<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Mesa de ayuda: acceso restringido por mora (20261001132).
 *
 * Caso real TKT-2026-0007: un estudiante bloqueado por facturas vencidas abrió
 * un ticket a Soporte Tecnológico ("No puedo ingresar a clases") con la captura
 * de la pantalla "Acceso restringido". Eso no lo resuelve TI: lo resuelve Cobros
 * por WhatsApp. No hace falta leer la imagen: Moodle ya sabe quién está en mora
 * (gmk_financial_status, sincronizado desde Odoo por cron y webhooks), así que
 * el portal le muestra el botón de Cobros antes de abrir la solicitud y, si la
 * abre igual, el ticket recibe el aviso con el enlace y una nota para el agente.
 *
 * Todo es configurable en la mesa de ayuda (Configuración): tkt_mora_redirect,
 * tkt_mora_label, tkt_mora_whatsapp y la plantilla financial_block.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\support;

use stdClass;

defined('MOODLE_INTERNAL') || die();

class restriction {

    /** Estados de gmk_financial_status que bloquean el portal por deuda. */
    const BLOCKING = ['mora'];

    /** Area externa (WhatsApp) que atiende la deuda. */
    const AREA_CODE = 'finanzas';

    /**
     * Restriction of $userid that the help desk should redirect, or null.
     *
     * @return array|null {reason, title, message, url, label, since}
     */
    public static function for_user(int $userid): ?array {
        global $DB;
        if ($userid <= 0 || !config::get_int('tkt_mora_redirect')) {
            return null;
        }
        $rows = $DB->get_records('gmk_financial_status', ['userid' => $userid], 'lastupdated DESC, id DESC',
            'id, status, lastupdated', 0, 1);
        $row = $rows ? reset($rows) : null;
        if (!$row || !in_array((string)$row->status, self::BLOCKING, true)) {
            return null;
        }
        $area = self::area();
        if (!$area) {
            return null;
        }
        $user = \core_user::get_user($userid);
        $message = $user ? strtr((string)config::get('tkt_mora_whatsapp'), [
            '{estudiante}' => fullname($user),
            '{cedula}' => manager::document_number($user),
        ]) : '';
        return [
            'reason' => 'mora',
            'title' => 'Tu acceso está restringido por facturas vencidas',
            'message' => 'Esto no lo resuelve la mesa de ayuda: lo atiende Cobros por WhatsApp. '
                . 'Cuando regularices, el acceso se habilita solo en unos minutos.',
            'url' => manager::external_link($area, $message),
            'label' => (string)config::get('tkt_mora_label'),
            'since' => (int)$row->lastupdated,
        ];
    }

    /**
     * Base WhatsApp link (no prefilled text) to write inside a ticket message.
     */
    public static function plain_link(): string {
        $area = self::area();
        return $area ? trim((string)$area->external_url) : '';
    }

    private static function area(): ?stdClass {
        global $DB;
        $area = $DB->get_record('gmk_tkt_area', ['code' => self::AREA_CODE, 'active' => 1]);
        return ($area && trim((string)$area->external_url) !== '') ? $area : null;
    }

    /**
     * New ticket from a student in mora: public notice with the WhatsApp link,
     * internal note for the agent and an event (for the weekly report).
     * Called inside the creation transaction.
     */
    public static function on_ticket_created(stdClass $ticket, array $block, array $vars): void {
        $link = self::plain_link();
        manager::system_message($ticket, 'financial_block', $vars + ['{enlace_cobros}' => $link]);
        $since = $block['since'] > 0 ? userdate($block['since'], '%d/%m/%Y %H:%M') : '';
        manager::insert_message($ticket, 0, manager::ROLE_SYSTEM,
            'Estudiante con acceso restringido por mora (estado financiero de Odoo'
            . ($since !== '' ? ', actualizado ' . $since : '') . '). Se le indicó escribir a Cobros por WhatsApp. '
            . 'Si su consulta es por eso, puedes cerrar la solicitud.', true);
        manager::log_event((int)$ticket->id, 'financial_block', '', 'mora', 0);
    }
}
