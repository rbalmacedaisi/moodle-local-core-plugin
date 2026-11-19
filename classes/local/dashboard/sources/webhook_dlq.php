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
 * Fuente: webhooks financieros de Odoo que no se pudieron aplicar (cola DLQ).
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\dashboard\sources;

use local_grupomakro_core\local\dashboard\source;

defined('MOODLE_INTERNAL') || die();

class webhook_dlq extends source {

    const REASONS = [
        'invoice_paid' => 'Factura pagada',
        'invoice_unreconciled' => 'Pago desconciliado',
        'invoice_due_date_changed' => 'Cambio de vencimiento',
        'invoice_cancelled' => 'Factura anulada',
        'contract_type_changed' => 'Cambio de tipo de contrato',
    ];

    public function key(): string {
        return 'webhook_dlq';
    }

    public function label(): string {
        return 'Pagos de Odoo sin aplicar';
    }

    public function icon(): string {
        return 'mdi-cash-sync';
    }

    public function page(): string {
        return 'financial_webhook_dlq.php';
    }

    public function capabilities(): array {
        return ['manage_financial_webhooks'];
    }

    public function fetch(int $userid, bool $team, int $now): array {
        global $DB;
        $rows = $DB->get_records_select('gmk_financial_webhook_dlq', "state = 'pending'", [], 'timecreated ASC',
            'id, reason, attempts, timecreated', 0, self::MAX_ROWS);
        $out = [];
        foreach ($rows as $r) {
            $out[] = $this->item('dlq.pending', 'DLQ-' . $r->id, self::REASONS[$r->reason] ?? (string)$r->reason,
                (int)$r->attempts . ' intento(s) · desde el ' . self::date((int)$r->timecreated, true),
                (int)$r->timecreated, 0, 0, $this->url());
        }
        return $out;
    }
}
