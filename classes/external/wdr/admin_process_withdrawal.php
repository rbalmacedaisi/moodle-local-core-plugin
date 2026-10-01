<?php
namespace local_grupomakro_core\external\wdr;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/wdr_manager.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;

/**
 * Closes the WDR loop: queries Express for the partner's pending balance,
 * optionally forces the override with a justification, and on success marks
 * the request as `procesada` with the full audit (partner id, balance,
 * invoice/subscription counters).
 *
 * Returns the updated row's status and the captured audit columns.
 *
 * Errors raised by the manager:
 *   - invalidwdrstatus
 *   - wdr_missing_document_number
 *   - wdr_balance_check_failed
 *   - wdr_pending_balance
 *   - wdr_force_reason_required
 *   - wdr_process_failed
 *
 * Companion to admin_update_status which handles the non-process inbox
 * transitions (record_da, record_admin, reject, mark_pendiente_firma).
 */
class admin_process_withdrawal extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id'     => new external_value(PARAM_INT, 'WDR request id'),
            'force'  => new external_value(PARAM_BOOL,
                'True to bypass the balance block (admin override).',
                VALUE_DEFAULT, false),
            'reason' => new external_value(PARAM_TEXT,
                'Justification (required when force=true; min 10 chars).',
                VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(int $id, bool $force, string $reason): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'id'     => $id,
            'force'  => $force,
            'reason' => $reason,
        ]);

        $context = \context_system::instance();
        require_capability('local/grupomakro_core:manage_wdr_requests', $context);

        $row = \local_grupomakro_core\local\wdr_manager::process_withdrawal(
            (int)$params['id'],
            (int)$USER->id,
            (bool)$params['force'],
            (string)$params['reason']
        );

        return [
            'id'                       => (int)$row->id,
            'status'                   => (string)$row->status,
            'processed_at'             => (int)($row->processed_at ?? 0),
            'processed_by'             => (int)($row->processed_by ?? 0),
            'forced'                   => !empty($row->forced_at),
            'forced_at'                => (int)($row->forced_at ?? 0),
            'forced_reason'            => (string)($row->forced_reason ?? ''),
            'process_odoo_partner_id'  => (int)($row->process_odoo_partner_id ?? 0),
            'process_balance_total'    => (float)($row->process_balance_total ?? 0),
            'process_balance_currency' => (string)($row->process_balance_currency ?? ''),
            'process_invoices_updated' => (int)($row->process_invoices_updated ?? 0),
            'process_subs_updated'     => (int)($row->process_subs_updated ?? 0),
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'id'                       => new external_value(PARAM_INT, 'Request id'),
            'status'                   => new external_value(PARAM_TEXT, 'New status (procesada)'),
            'processed_at'             => new external_value(PARAM_INT, 'Unix ts when processed'),
            'processed_by'             => new external_value(PARAM_INT, 'userid of the admin who processed'),
            'forced'                   => new external_value(PARAM_BOOL, 'Whether a force override was used'),
            'forced_at'                => new external_value(PARAM_INT, 'Unix ts of the override'),
            'forced_reason'            => new external_value(PARAM_TEXT, 'Justification written by the admin'),
            'process_odoo_partner_id'  => new external_value(PARAM_INT, 'Odoo res.partner.id at processing time'),
            'process_balance_total'    => new external_value(PARAM_FLOAT, 'Pending balance at processing time'),
            'process_balance_currency' => new external_value(PARAM_TEXT, 'Currency reported by Odoo'),
            'process_invoices_updated' => new external_value(PARAM_INT, 'Invoices touched by the wizard'),
            'process_subs_updated'     => new external_value(PARAM_INT, 'Subscriptions deactivated by the wizard'),
        ]);
    }
}