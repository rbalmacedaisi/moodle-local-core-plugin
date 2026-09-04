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
 * Financial status webhook receiver.
 *
 * Endpoint POST que recibe pushes HMAC-firmados desde Express
 * /api/odoo/cache/invalidate cuando una factura del partner cambia
 * (pago, anulación, cambio de vencimiento, unreconcile) o cuando cambia
 * el tipo de contrato especial (beca/IFARHU). Por cada push:
 *
 *  1. Valida la firma HMAC-SHA-256 sobre el payload canónico.
 *  2. Resuelve el userid de Moodle a partir del documentnumber del push.
 *  3. Llama local_grupomakro_sync_financial_status([$userid]) que hace
 *     un DELETE+INSERT en gmk_financial_status (idempotente).
 *  4. Si la operación falla, encola el evento en
 *     gmk_financial_webhook_dlq para inspección/retry manual.
 *
 * El secreto compartido se toma de Moodle config (parámetro
 * 'financial_webhook_secret', default 'gmk_payment_invalidate_2026'
 * para mantener compatibilidad con el webhook Odoo existente).
 *
 * Modos:
 *  - POST normal   → procesa el push (default)
 *  - POST ?action=retry&id=X → reprocesa una fila de la DLQ
 *  - GET  ?action=stats   → métricas básicas para health check
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Grupomakro
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

header('Content-Type: application/json');

// ============================================================================
// Helpers compartidos (visibles desde el handler de retry)
// ============================================================================

/**
 * Devuelve el secreto compartido con Odoo/Express.
 */
function fwh_get_secret(): string {
    $secret = (string)get_config('local_grupomakro_core', 'financial_webhook_secret');
    if ($secret === '') {
        $secret = 'gmk_payment_invalidate_2026';
    }
    return $secret;
}

/**
 * Payload canónico para la firma. Debe coincidir exactamente con
 * canonicalInvalidatePayload() en rest_express/server.js: orden de
 * claves y JSON sin espacios extra.
 *
 *  - partner_vat
 *  - invoice_id
 *  - reason
 *  - event_time
 */
function fwh_canonical_payload(array $payload): string {
    $canonical = [
        'partner_vat' => isset($payload['partner_vat']) ? (string)$payload['partner_vat'] : '',
        'invoice_id'  => isset($payload['invoice_id'])  ? (string)$payload['invoice_id']  : '',
        'reason'      => isset($payload['reason'])      ? (string)$payload['reason']      : '',
        'event_time'  => isset($payload['event_time'])  ? (string)$payload['event_time']  : '',
    ];
    return json_encode($canonical);
}

/**
 * Valida la firma HMAC. Devuelve true si coincide.
 */
function fwh_verify_signature(array $payload, string $providedsignature, string $secret): bool {
    if ($providedsignature === '') {
        return false;
    }
    $expected = hash_hmac('sha256', fwh_canonical_payload($payload), $secret);
    $received = strtolower(trim(preg_replace('/^sha256=/i', '', $providedsignature)));
    if (strlen($received) !== strlen($expected)) {
        return false;
    }
    return hash_equals($expected, $received);
}

/**
 * Resuelve el userid de Moodle a partir del documentnumber.
 * Devuelve 0 si no se encuentra.
 */
function fwh_resolve_userid(string $vat): int {
    global $DB;

    $vat = trim($vat);
    if ($vat === '') {
        return 0;
    }

    $fieldid = (int)$DB->get_field('user_info_field', 'id', ['shortname' => 'documentnumber']);
    if ($fieldid <= 0) {
        return 0;
    }

    $userid = $DB->get_field_sql(
        "SELECT d.userid
           FROM {user_info_data} d
           JOIN {user} u ON u.id = d.userid
          WHERE d.fieldid = :fid
            AND d.data = :vat
            AND u.deleted = 0
          LIMIT 1",
        ['fid' => $fieldid, 'vat' => $vat]
    );

    return $userid ? (int)$userid : 0;
}

/**
 * Log a archivo rotable para diagnóstico.
 */
function fwh_log(string $line): void {
    global $CFG;
    $dir = $CFG->dataroot . '/local_grupomakro_core';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $file = $dir . '/financial_webhook.log';
    $msg = '[' . date('Y-m-d H:i:s') . '] ' . $line . PHP_EOL;
    @file_put_contents($file, $msg, FILE_APPEND);
}

/**
 * Encola un fallo en la DLQ. Incrementa attempts si ya existe una fila
 * pendiente para el mismo (vat, reason, invoice_id).
 */
function fwh_enqueue_dlq(array $payload, string $signature, string $error): int {
    global $DB;

    $now = time();
    $vat = (string)($payload['partner_vat'] ?? '');
    $reason = (string)($payload['reason'] ?? '');
    $invoiceid = (string)($payload['invoice_id'] ?? '');
    $eventtime = (string)($payload['event_time'] ?? '');

    // Buscar fila pendiente existente para idempotencia.
    $existing = $DB->get_record('gmk_financial_webhook_dlq', [
        'partner_vat' => $vat,
        'reason'      => $reason,
        'invoice_id'  => $invoiceid,
        'state'       => 'pending',
    ]);

    if ($existing) {
        $existing->attempts = (int)$existing->attempts + 1;
        $existing->last_error = $error;
        $existing->last_received_at = $now;
        $existing->signature = $signature;
        $existing->payload = json_encode($payload);
        $DB->update_record('gmk_financial_webhook_dlq', $existing);
        return (int)$existing->id;
    }

    $record = new stdClass();
    $record->partner_vat      = $vat;
    $record->reason           = $reason;
    $record->invoice_id       = $invoiceid;
    $record->event_time       = $eventtime;
    $record->payload          = json_encode($payload);
    $record->signature        = $signature;
    $record->attempts         = 1;
    $record->last_error       = $error;
    $record->last_received_at = $now;
    $record->state            = 'pending';
    $record->timecreated      = $now;
    return (int)$DB->insert_record('gmk_financial_webhook_dlq', $record);
}

/**
 * Procesa un push. Devuelve array con status + detalles.
 */
function fwh_process_payload(array $payload, string $signature): array {
    $vat = trim((string)($payload['partner_vat'] ?? ''));
    if ($vat === '') {
        fwh_enqueue_dlq($payload, $signature, 'missing partner_vat');
        return ['success' => false, 'error' => 'partner_vat_required'];
    }

    $userid = fwh_resolve_userid($vat);
    if ($userid <= 0) {
        // No es un fallo bloqueante: el partner de Odoo puede no tener
        // counterpart en Moodle todavía. Lo logueamos pero respondemos 200.
        fwh_log("WARN: no Moodle user found for vat={$vat} reason=" . ($payload['reason'] ?? ''));
        return [
            'success' => true,
            'skipped' => 'no_user_for_vat',
            'partner_vat' => $vat,
        ];
    }

    try {
        $result = local_grupomakro_sync_financial_status([$userid]);
        if (isset($result['error'])) {
            $err = (string)$result['error'];
            $details = (string)($result['details'] ?? '');
            $msg = $err . ($details !== '' ? ' (' . $details . ')' : '');
            $dlqid = fwh_enqueue_dlq($payload, $signature, $msg);
            fwh_log("ERR sync failed userid={$userid} vat={$vat} dlq={$dlqid}: {$msg}");
            return [
                'success' => false,
                'error'   => $msg,
                'dlq_id'  => $dlqid,
            ];
        }
        fwh_log("OK userid={$userid} vat={$vat} updated=" . ($result['updated'] ?? 0));
        return [
            'success'      => true,
            'userid'       => $userid,
            'partner_vat'  => $vat,
            'updated'      => (int)($result['updated'] ?? 0),
        ];
    } catch (Throwable $e) {
        $err = $e->getMessage();
        $dlqid = fwh_enqueue_dlq($payload, $signature, 'exception: ' . $err);
        fwh_log("EXC userid={$userid} vat={$vat} dlq={$dlqid}: {$err}");
        return [
            'success' => false,
            'error'   => $err,
            'dlq_id'  => $dlqid,
        ];
    }
}

// ============================================================================
// Routing principal
// ============================================================================

$action = optional_param('action', '', PARAM_ALPHA);

try {
    // -- Modo stats (GET): devuelve métricas para health check.
    if ($action === 'stats') {
        require_capability('local/grupomakro_core:manage_financial_webhooks', context_system::instance());
        $stats = [
            'success'              => true,
            'dlq_pending'          => (int)$DB->count_records('gmk_financial_webhook_dlq', ['state' => 'pending']),
            'dlq_resolved'         => (int)$DB->count_records('gmk_financial_webhook_dlq', ['state' => 'resolved']),
            'dlq_abandoned'        => (int)$DB->count_records('gmk_financial_webhook_dlq', ['state' => 'abandoned']),
            'gmk_financial_total'  => (int)$DB->count_records('gmk_financial_status'),
            'fresh_lt_1h'          => (int)$DB->count_records_select(
                'gmk_financial_status',
                'lastupdated > ?',
                [time() - 3600]
            ),
            'stale_gt_24h'         => (int)$DB->count_records_select(
                'gmk_financial_status',
                'lastupdated < ?',
                [time() - 86400]
            ),
        ];
        echo json_encode($stats);
        exit;
    }

    // -- Modo retry (POST): reprocesa una fila de la DLQ por id.
    if ($action === 'retry') {
        require_capability('local/grupomakro_core:manage_financial_webhooks', context_system::instance());
        $dlqid = required_param('id', PARAM_INT);
        $row   = $DB->get_record('gmk_financial_webhook_dlq', ['id' => $dlqid], '*', MUST_EXIST);
        if ($row->state !== 'pending') {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'dlq_row_not_pending', 'state' => $row->state]);
            exit;
        }
        $payload   = json_decode($row->payload, true) ?: [];
        $signature = (string)($row->signature ?? '');
        $result    = fwh_process_payload($payload, $signature);
        if (!empty($result['success']) && empty($result['error'])) {
            $row->state = 'resolved';
            $row->last_error = null;
            $row->last_received_at = time();
            $DB->update_record('gmk_financial_webhook_dlq', $row);
            $result['dlq_id'] = $dlqid;
            $result['new_state'] = 'resolved';
        } else {
            $row->attempts = (int)$row->attempts + 1;
            $row->last_error = (string)($result['error'] ?? 'unknown');
            $row->last_received_at = time();
            // Tras 10 intentos se abandona.
            if ($row->attempts >= 10) {
                $row->state = 'abandoned';
                $result['new_state'] = 'abandoned';
            } else {
                $result['new_state'] = 'pending';
            }
            $DB->update_record('gmk_financial_webhook_dlq', $row);
        }
        echo json_encode($result);
        exit;
    }

    // -- Modo resolve (POST): marca una fila de la DLQ como resuelta manualmente.
    if ($action === 'resolve') {
        require_capability('local/grupomakro_core:manage_financial_webhooks', context_system::instance());
        $dlqid = required_param('id', PARAM_INT);
        $row   = $DB->get_record('gmk_financial_webhook_dlq', ['id' => $dlqid], '*', MUST_EXIST);
        $row->state = 'resolved';
        $row->last_error = 'manually resolved';
        $row->last_received_at = time();
        $DB->update_record('gmk_financial_webhook_dlq', $row);
        echo json_encode(['success' => true, 'dlq_id' => $dlqid, 'new_state' => 'resolved']);
        exit;
    }

    // -- Modo principal: push firmado.
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
        exit;
    }

    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        fwh_log("ERR invalid_payload: " . substr((string)$raw, 0, 200));
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_payload']);
        exit;
    }

    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $providedsig = '';
    if (!empty($headers['X-Odoo-Signature'])) {
        $providedsig = trim((string)$headers['X-Odoo-Signature']);
    } else if (!empty($headers['x-odoo-signature'])) {
        $providedsig = trim((string)$headers['x-odoo-signature']);
    } else if (!empty($payload['signature'])) {
        $providedsig = trim((string)$payload['signature']);
    }

    $secret = fwh_get_secret();
    if (!fwh_verify_signature($payload, $providedsig, $secret)) {
        fwh_log("ERR invalid_signature vat=" . ($payload['partner_vat'] ?? '?'));
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'invalid_signature']);
        exit;
    }

    $result = fwh_process_payload($payload, $providedsig);
    // success=false no es necesariamente un crash del servidor: el caso
    // comun es que el partner_vat no existia o que el sync a Odoo fallo y
    // se encolo en la DLQ. Devolvemos 200 con success=false para que el
    // caller (Express) sepa que el payload fue procesado y no reintente
    // agresivamente. Solo devolvemos 500 si la excepcion se propago arriba.
    $code = 200;
    http_response_code($code);
    echo json_encode($result);
    exit;
} catch (Throwable $e) {
    fwh_log("FATAL " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}