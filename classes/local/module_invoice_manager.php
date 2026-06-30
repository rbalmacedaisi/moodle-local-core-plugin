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
 * Module invoice request manager.
 *
 * Handles the request → invoice → payment → enrollment flow for independent
 * study modules. Mirrors the proven Odoo proxy pattern from
 * {@see \local_grupomakro_core\local\revalida_manager}.
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Antigravity
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local;

use stdClass;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->dirroot . '/local/grupomakro_core/locallib.php');

/**
 * Module invoice business logic.
 *
 * Lifecycle of a module request:
 *   pending_payment → (payment received) → paid → (enroll) → enrolled
 *   pending_payment → (30d without payment) → expired
 *   pending_payment → (academic cancels)   → cancelled
 */
class module_invoice_manager {

    /** @var string Odoo invoice ref prefix that links the invoice back to a module request row. */
    const MODULE_REF_PREFIX = 'MODULE_REQ:';

    /** @var int Default expiry in days for unpaid requests (overridden by setting). */
    const DEFAULT_EXPIRY_DAYS = 30;

    /** Allowed module_type values. */
    const ALLOWED_MODULE_TYPES = ['tronco_comun', 'materias_especializadas'];

    /**
     * Creates a new module invoice request and asks the Express proxy to
     * generate the Odoo invoice.
     *
     * Validates: no active enrollment, no pending payment request for the
     * same (userid, corecourseid).
     *
     * @param int    $userid
     * @param int    $corecourseid
     * @param int    $learningplanid
     * @param string $moduleType  One of ALLOWED_MODULE_TYPES.
     * @param int    $actorid     User creating the request.
     * @return array{ok:bool, error:?string, request:?stdClass}
     */
    public static function create_request(
        int $userid,
        int $corecourseid,
        int $learningplanid,
        string $moduleType,
        int $actorid
    ): array {
        global $DB;

        if (!in_array($moduleType, self::ALLOWED_MODULE_TYPES, true)) {
            return ['ok' => false, 'error' => 'Tipo de módulo inválido.', 'request' => null];
        }

        // Validate user and course.
        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], 'id,firstname,lastname,email', MUST_EXIST);
        $course = $DB->get_record('course', ['id' => $corecourseid], 'id,fullname,shortname', MUST_EXIST);

        // Block: active module enrollment for this course.
        $activeEnrollment = $DB->get_record_sql(
            "SELECT gme.id
               FROM {gmk_module_enrollment} gme
               JOIN {gmk_class} gc ON gc.id = gme.classid
              WHERE gme.userid = :uid
                AND gc.corecourseid = :cid
                AND gme.status = 'active'",
            ['uid' => $userid, 'cid' => $corecourseid]
        );
        if ($activeEnrollment) {
            return [
                'ok'      => false,
                'error'   => 'El estudiante ya tiene un módulo activo para esta asignatura.',
                'request' => null,
            ];
        }

        // Block: existing pending or paid request.
        $existingPending = $DB->get_record('gmk_module_invoice_requests', [
            'userid'       => $userid,
            'corecourseid' => $corecourseid,
            'status'       => 'pending_payment',
        ]);
        if ($existingPending) {
            return [
                'ok'      => false,
                'error'   => 'Ya existe una solicitud de módulo pendiente de pago para este estudiante y asignatura.',
                'request' => $existingPending,
            ];
        }
        $existingPaid = $DB->get_record('gmk_module_invoice_requests', [
            'userid'       => $userid,
            'corecourseid' => $corecourseid,
            'status'       => 'paid',
        ]);
        if ($existingPaid) {
            return [
                'ok'      => false,
                'error'   => 'Ya existe una solicitud pagada lista para inscribir.',
                'request' => $existingPaid,
            ];
        }

        // Resolve cost / product id from settings.
        $productid = (int)self::get_product_id_for_type($moduleType);
        $cost      = (float)self::get_cost_for_type($moduleType);
        if ($productid <= 0 || $cost <= 0) {
            return [
                'ok'      => false,
                'error'   => 'El producto/costo de Odoo no está configurado para este tipo de módulo.',
                'request' => null,
            ];
        }

        // Pre-validate that the configured product actually exists in Odoo,
        // otherwise we would surface an opaque XML-RPC fault later.
        $productCheck = self::validate_product_in_odoo($productid);
        if (!$productCheck['exists']) {
            return [
                'ok'      => false,
                'error'   => 'El producto Odoo configurado para ' .
                              self::module_type_label($moduleType) .
                              ' (ID=' . $productid . ') no existe o fue eliminado. ' .
                              'Contacte al administrador para actualizar la configuración.',
                'request' => null,
            ];
        }

        // Expiry window (configurable, default 30 days).
        $expirydays = (int)get_config('local_grupomakro_core', 'module_request_expiry_days');
        if ($expirydays <= 0) {
            $expirydays = self::DEFAULT_EXPIRY_DAYS;
        }
        $now     = time();
        $expires = $now + ($expirydays * DAYSECS);

        // Insert the request row first (idempotency anchor).
        $rec               = new stdClass();
        $rec->userid         = $userid;
        $rec->corecourseid   = $corecourseid;
        $rec->learningplanid = $learningplanid;
        $rec->module_type    = $moduleType;
        $rec->amount         = $cost;
        $rec->payment_state  = 'unpaid';
        $rec->status         = 'pending_payment';
        $rec->expires_at     = $expires;
        $rec->paidat         = 0;
        $rec->createdby      = $actorid;
        $rec->timecreated    = $now;
        $rec->timemodified   = $now;
        $rec->id = (int)$DB->insert_record('gmk_module_invoice_requests', $rec);

        // Ask Express to create the Odoo invoice (idempotent by external_request_id).
        try {
            $invoice = self::create_invoice($rec, $user);
            $rec->invoice_extref = self::MODULE_REF_PREFIX . $rec->id;
            $rec->invoice_id     = (string)($invoice['invoice_id'] ?? '');
            $rec->invoice_number = (string)($invoice['invoice_number'] ?? '');
            $rec->payment_link   = (string)($invoice['payment_link'] ?? '');
            $rec->timemodified   = time();
            $DB->update_record('gmk_module_invoice_requests', $rec);
        } catch (\Throwable $e) {
            // Keep the row so the request can be retried; surface a sanitized error.
            \gmk_log('ERROR: module invoice failed requestid=' . $rec->id . ' msg=' . $e->getMessage());
            $sanitized = self::sanitize_invoice_error($e->getMessage(), $productid);
            return [
                'ok'      => false,
                'error'   => $sanitized,
                'request' => $rec,
            ];
        }

        return ['ok' => true, 'error' => null, 'request' => $rec];
    }

    /**
     * Marks a request as enrolled against a specific gmk_class.id.
     *
     * Called by {@see \local_grupomakro_core\external\schedule\enroll_module}
     * after a successful enrollment, so the request stops appearing as "paid
     * ready to enroll" on dashboards.
     *
     * @param int $requestId
     * @param int $classId  gmk_class.id assigned to the student.
     * @param int $actorid
     * @return bool
     */
    public static function mark_enrolled(int $requestId, int $classId, int $actorid): bool {
        global $DB;
        $rec = $DB->get_record('gmk_module_invoice_requests', ['id' => $requestId]);
        if (!$rec) {
            return false;
        }
        $update = (object)[
            'id'              => $requestId,
            'status'          => 'enrolled',
            'enrolled_classid' => $classId,
            'timemodified'    => time(),
        ];
        $DB->update_record('gmk_module_invoice_requests', $update);
        return true;
    }

    /**
     * Cancels a pending_payment request. No-op when already paid/enrolled.
     *
     * @param int $requestId
     * @param int $actorid
     * @return array{ok:bool, error:?string}
     */
    public static function cancel(int $requestId, int $actorid): array {
        global $DB;
        $rec = $DB->get_record('gmk_module_invoice_requests', ['id' => $requestId]);
        if (!$rec) {
            return ['ok' => false, 'error' => 'Solicitud no encontrada.'];
        }
        if (!in_array($rec->status, ['pending_payment'], true)) {
            return [
                'ok'    => false,
                'error' => 'Solo se pueden cancelar solicitudes con pago pendiente.',
            ];
        }
        $DB->update_record('gmk_module_invoice_requests', (object)[
            'id'           => $requestId,
            'status'       => 'cancelled',
            'timemodified' => time(),
        ]);
        return ['ok' => true, 'error' => null];
    }

    /**
     * On-demand payment verification: asks Express/Odoo for the invoice payment
     * state and marks the row paid when confirmed.
     *
     * @param stdClass $rec
     * @return bool True when the invoice is paid.
     */
    public static function verify_payment(stdClass $rec): bool {
        global $DB;
        if (empty($rec->invoice_id)) {
            return false;
        }
        $response = self::call_odoo_proxy('/api/odoo/modules/invoice-status', [
            'invoice_id'          => (string)$rec->invoice_id,
            'external_request_id' => (string)$rec->id,
        ]);
        $paid = !empty($response['success']) && (string)($response['payment_state'] ?? '') === 'paid';
        if ($paid && $rec->payment_state !== 'paid') {
            $update = (object)[
                'id'            => (int)$rec->id,
                'payment_state' => 'paid',
                'status'        => 'paid',
                'paidat'        => time(),
                'timemodified'  => time(),
            ];
            if (!empty($response['invoice_id'])) {
                $update->invoice_id = (string)$response['invoice_id'];
            }
            if (!empty($response['invoice_number'])) {
                $update->invoice_number = (string)$response['invoice_number'];
            }
            $DB->update_record('gmk_module_invoice_requests', $update);
        }
        return $paid;
    }

    /**
     * Refreshes payment state for a single request and returns a UI-friendly
     * snapshot for the academic "Verificar pago" button.
     *
     * @param int $requestId
     * @return array
     */
    public static function refresh_payment(int $requestId): array {
        global $DB;
        $rec = $DB->get_record('gmk_module_invoice_requests', ['id' => $requestId], '*', MUST_EXIST);
        if (empty($rec->invoice_id)) {
            return [
                'ok'             => false,
                'paid'           => false,
                'payment_state'  => 'unpaid',
                'request_status' => (string)$rec->status,
                'invoice_number' => (string)$rec->invoice_number,
                'message'        => 'La solicitud no tiene factura asociada.',
            ];
        }
        $paid = self::verify_payment($rec);
        $rec  = $DB->get_record('gmk_module_invoice_requests', ['id' => $requestId], '*', MUST_EXIST);
        return [
            'ok'             => true,
            'paid'           => $paid,
            'payment_state'  => (string)$rec->payment_state,
            'request_status' => (string)$rec->status,
            'invoice_number' => (string)$rec->invoice_number,
            'invoice_id'     => (string)$rec->invoice_id,
            'message'        => $paid
                ? 'Factura pagada. Ya puede inscribir el módulo.'
                : 'La factura aún no está pagada.',
        ];
    }

    /**
     * Marks a request as paid from an Odoo payment webhook payload.
     *
     * @param array $payload
     * @return array{success:bool, message:string}
     */
    public static function handle_payment_webhook(array $payload): array {
        global $DB;
        $extref         = trim((string)($payload['external_request_id'] ?? ''));
        $invoiceid      = trim((string)($payload['invoice_id'] ?? ''));
        $invoicenumber  = trim((string)($payload['invoice_number'] ?? ''));
        $payment_state  = trim((string)($payload['payment_state'] ?? ''));

        if ($payment_state !== 'paid') {
            return ['success' => true, 'message' => 'ignored_not_paid'];
        }

        $rec = null;
        if ($extref !== '' && ctype_digit($extref)) {
            $rec = $DB->get_record('gmk_module_invoice_requests', ['id' => (int)$extref], '*', IGNORE_MISSING);
        }
        if (!$rec && $invoiceid !== '') {
            $rec = $DB->get_record('gmk_module_invoice_requests', ['invoice_id' => $invoiceid], '*', IGNORE_MISSING);
        }
        if (!$rec) {
            return ['success' => false, 'message' => 'request_not_found'];
        }
        if ($rec->payment_state === 'paid') {
            return ['success' => true, 'message' => 'already_paid'];
        }

        $update = (object)[
            'id'            => (int)$rec->id,
            'payment_state' => 'paid',
            'status'        => 'paid',
            'paidat'        => time(),
            'timemodified'  => time(),
        ];
        if ($invoiceid !== '') {
            $update->invoice_id = $invoiceid;
        }
        if ($invoicenumber !== '') {
            $update->invoice_number = $invoicenumber;
        }
        $DB->update_record('gmk_module_invoice_requests', $update);
        return ['success' => true, 'message' => 'marked_paid'];
    }

    /**
     * Finds the most recent paid request for a (user, course) — used by the
     * enrollment gate inside enroll_module.
     *
     * @param int $userid
     * @param int $corecourseid
     * @return stdClass|null
     */
    public static function find_paid_for_user_course(int $userid, int $corecourseid): ?stdClass {
        global $DB;
        $rec = $DB->get_record('gmk_module_invoice_requests', [
            'userid'       => $userid,
            'corecourseid' => $corecourseid,
            'status'       => 'paid',
        ]);
        return $rec ?: null;
    }

    /**
     * Returns active (pending_payment OR paid) requests for a given user.
     * Used by both the LXP profile page (which filters to pending only
     * client-side) and the academic grademodal (which needs to know about
     * paid requests so it can offer the "Inscribir ahora" action).
     * Terminal statuses (enrolled/expired/cancelled) are excluded.
     *
     * @param int $userid
     * @return array
     */
    public static function get_pending_for_user(int $userid): array {
        global $DB;
        $rows = $DB->get_records_sql(
            "SELECT r.id, r.userid, r.corecourseid, r.learningplanid, r.module_type,
                    r.invoice_id, r.invoice_number, r.payment_link, r.amount,
                    r.payment_state, r.status, r.expires_at, r.paidat,
                    c.fullname AS coursename
               FROM {gmk_module_invoice_requests} r
               JOIN {course} c ON c.id = r.corecourseid
              WHERE r.userid = :uid
                AND r.status IN ('pending_payment', 'paid')
           ORDER BY r.id DESC",
            ['uid' => $userid]
        );
        return array_values($rows);
    }

    /**
     * Returns ALL requests (any status) for the academic panel, optionally
     * filtered by status and/or user search.
     *
     * @param string|null $statusFilter
     * @param string|null $userSearch
     * @param int $limit
     * @return array
     */
    public static function list_requests(?string $statusFilter = null, ?string $userSearch = null, int $limit = 200): array {
        global $DB;
        $where  = ['1=1'];
        $params = [];
        if (!empty($statusFilter)) {
            $where[]  = 'r.status = :status';
            $params['status'] = $statusFilter;
        }
        if (!empty($userSearch)) {
            $where[] = '(' . $DB->sql_like('u.firstname', ':us1', false) . ' OR '
                          . $DB->sql_like('u.lastname',  ':us2', false) . ' OR '
                          . $DB->sql_like('u.email',     ':us3', false) . ')';
            $params['us1'] = '%' . $userSearch . '%';
            $params['us2'] = '%' . $userSearch . '%';
            $params['us3'] = '%' . $userSearch . '%';
        }
        $sql = "SELECT r.id, r.userid, r.corecourseid, r.learningplanid, r.module_type,
                       r.invoice_id, r.invoice_number, r.payment_link, r.amount,
                       r.payment_state, r.status, r.expires_at, r.paidat,
                       r.enrolled_classid, r.timecreated, r.timemodified,
                       u.firstname, u.lastname, u.email,
                       c.fullname AS coursename
                  FROM {gmk_module_invoice_requests} r
                  JOIN {user}   u ON u.id = r.userid
                  JOIN {course} c ON c.id = r.corecourseid
                 WHERE " . implode(' AND ', $where) . "
              ORDER BY r.id DESC";
        return array_values($DB->get_records_sql($sql, $params, 0, $limit));
    }

    /**
     * Auto-expires pending requests past their expires_at timestamp. Called by
     * the cron task {@see \local_grupomakro_core\task\expire_module_requests_task}.
     *
     * @return int Number of rows expired.
     */
    public static function expire_pending(): int {
        global $DB;
        $now = time();
        $sql = "SELECT id
                  FROM {gmk_module_invoice_requests}
                 WHERE status = 'pending_payment'
                   AND expires_at > 0
                   AND expires_at < ?";
        $ids = $DB->get_fieldset_sql($sql, [$now]);
        if (empty($ids)) {
            return 0;
        }
        list($insql, $inparams) = $DB->get_in_or_equal($ids);
        $DB->execute(
            "UPDATE {gmk_module_invoice_requests}
                SET status = 'expired', timemodified = ?
              WHERE id $insql",
            array_merge([$now], $inparams)
        );
        return count($ids);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internal helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Returns the configured Odoo product id for a given module_type.
     *
     * @param string $moduleType
     * @return int
     */
    private static function get_product_id_for_type(string $moduleType): int {
        $key = $moduleType === 'materias_especializadas'
            ? 'module_me_odoo_product_id'
            : 'module_tc_odoo_product_id';
        return (int)get_config('local_grupomakro_core', $key);
    }

    /**
     * Returns the configured module cost for a given module_type.
     *
     * @param string $moduleType
     * @return float
     */
    private static function get_cost_for_type(string $moduleType): float {
        $key = $moduleType === 'materias_especializadas'
            ? 'module_me_cost'
            : 'module_tc_cost';
        return (float)get_config('local_grupomakro_core', $key);
    }

    /**
     * Creates the Odoo invoice for a module request via the Express proxy.
     *
     * @param stdClass $rec
     * @param stdClass $user
     * @return array{invoice_id:string, invoice_number:string, payment_link:string}
     * @throws moodle_exception
     */
    private static function create_invoice(stdClass $rec, stdClass $user): array {
        $documentnumber = self::get_user_document_number((int)$user->id);
        if ($documentnumber === '') {
            throw new moodle_exception('El estudiante no tiene número de documento para facturar.');
        }
        $productid   = (int)self::get_product_id_for_type((string)$rec->module_type);
        $cost        = (float)$rec->amount;
        $coursename  = (string)$GLOBALS['DB']->get_field('course', 'fullname', ['id' => (int)$rec->corecourseid]);
        $typeLabel   = self::module_type_label((string)$rec->module_type);

        $payload = [
            'external_request_id' => (string)$rec->id,
            'document_number'     => $documentnumber,
            'student_email'       => (string)$user->email,
            'amount'              => $cost,
            'currency'            => 'USD',
            'odoo_product_id'     => $productid,
            'description'         => 'Módulo (' . $typeLabel . '): ' . $coursename . ' (#' . $rec->id . ')',
            'module_request_id'   => (string)$rec->id,
            'module_type'         => (string)$rec->module_type,
        ];
        $response = self::call_odoo_proxy('/api/odoo/modules/invoice', $payload);
        if (empty($response['success'])) {
            throw new moodle_exception(
                'No se pudo generar la factura: '
                . (isset($response['error']) ? (string)$response['error'] : 'desconocido')
            );
        }
        return [
            'invoice_id'     => (string)($response['invoice_id'] ?? ''),
            'invoice_number' => (string)($response['invoice_number'] ?? ''),
            'payment_link'   => (string)($response['payment_link'] ?? ''),
        ];
    }

    /**
     * Human-readable label for a module_type.
     */
    public static function module_type_label(string $moduleType): string {
        switch ($moduleType) {
            case 'materias_especializadas':
                return 'Materias Especializadas';
            case 'tronco_comun':
            default:
                return 'Tronco Común';
        }
    }

    /**
     * Resolves a student's identification (documentnumber profile field, then idnumber).
     */
    private static function get_user_document_number(int $userid): string {
        global $DB;
        $fielddoc = $DB->get_record('user_info_field', ['shortname' => 'documentnumber']);
        if ($fielddoc) {
            $val = $DB->get_field('user_info_data', 'data', ['fieldid' => $fielddoc->id, 'userid' => $userid]);
            if ($val !== false && trim((string)$val) !== '') {
                return trim((string)$val);
            }
        }
        $idnumber = (string)$DB->get_field('user', 'idnumber', ['id' => $userid]);
        return trim($idnumber);
    }

    /**
     * Verifies that a configured Odoo product.product still exists. Used by
     * create_request() to fail-fast with a clean message instead of letting
     * the invoice creation later surface an opaque XML-RPC fault.
     *
     * @param int $productId
     * @return array{exists:bool, id:int, name:string, error:?string}
     */
    public static function validate_product_in_odoo(int $productId): array {
        if ($productId <= 0) {
            return ['exists' => false, 'id' => $productId, 'name' => '', 'error' => 'invalid_product_id'];
        }
        $response = self::call_odoo_proxy_get('/api/odoo/products/exists', [
            'product_id' => $productId,
        ]);
        if (!is_array($response)) {
            return ['exists' => false, 'id' => $productId, 'name' => '', 'error' => 'odoo_proxy_unreachable'];
        }
        if (empty($response['success'])) {
            return [
                'exists' => false,
                'id'     => $productId,
                'name'   => '',
                'error'  => isset($response['error']) ? (string)$response['error'] : 'odoo_query_failed',
            ];
        }
        return [
            'exists' => !empty($response['exists']),
            'id'     => $productId,
            'name'   => (string)($response['name'] ?? ''),
            'error'  => !empty($response['exists']) ? null : 'product_not_found',
        ];
    }

    /**
     * Sanitizes raw exception messages coming from the Odoo proxy so the UI
     * never sees XML-RPC stack traces, internal HTTP codes, or English
     * payloads. Falls back to a generic message when the cause is unknown.
     *
     * @param string $raw
     * @param int $productId
     * @return string
     */
    private static function sanitize_invoice_error(string $raw, int $productId): string {
        // Detect the product-not-found XML-RPC signature.
        if (stripos($raw, 'Record does not exist') !== false && stripos($raw, 'product.product') !== false) {
            return 'El producto Odoo configurado (ID=' . $productId . ') no existe o fue eliminado. '
                 . 'Contacte al administrador para actualizar la configuración.';
        }
        // Partner lookup failure.
        if (stripos($raw, 'partner_not_found_for_document') !== false) {
            return 'No se encontró el contacto del estudiante en Odoo. '
                 . 'Verifique que el estudiante esté sincronizado como contacto.';
        }
        // Product id missing.
        if (stripos($raw, 'odoo_product_id_required') !== false) {
            return 'No hay un producto Odoo configurado para este tipo de módulo.';
        }
        // Network / proxy failure.
        if (stripos($raw, 'odoo_proxy_unreachable') !== false || stripos($raw, 'invalid_json_response') !== false) {
            return 'No se pudo comunicar con el servidor proxy de Odoo. Reintente en unos minutos.';
        }
        // Generic fallback (truncate to avoid dumping huge stacks).
        return 'No se pudo generar la factura. Por favor contacte al administrador. (Detalle: '
             . substr(preg_replace('/\s+/', ' ', $raw), 0, 200) . ')';
    }

    /**
     * POSTs a JSON payload to the Express Odoo proxy. Mirrors revalida_manager::call_odoo_proxy.
     */
    private static function call_odoo_proxy(string $path, array $payload): array {
        $baseurl = get_config('local_grupomakro_core', 'odoo_proxy_url');
        if (empty($baseurl)) {
            $baseurl = 'https://lms.isi.edu.pa:4000';
        }
        $url         = rtrim($baseurl, '/') . $path;
        $jsonpayload = json_encode($payload);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonpayload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        $raw     = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error   = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['success' => false, 'error' => $error];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['success' => false, 'error' => 'invalid_json_response', 'httpcode' => $httpcode, 'raw' => $raw];
        }
        if (($httpcode < 200 || $httpcode >= 300) && !isset($decoded['success'])) {
            $decoded['success'] = false;
        }
        return $decoded;
    }

    /**
     * GET helper for the Odoo proxy (used by product existence checks).
     * Same error envelope as call_odoo_proxy() but reads query parameters
     * from the URL instead of sending a JSON body.
     *
     * @param string $path
     * @param array<string,mixed> $params
     * @return array
     */
    private static function call_odoo_proxy_get(string $path, array $params): array {
        $baseurl = get_config('local_grupomakro_core', 'odoo_proxy_url');
        if (empty($baseurl)) {
            $baseurl = 'https://lms.isi.edu.pa:4000';
        }
        $url = rtrim($baseurl, '/') . $path;
        if (!empty($params)) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPGET, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $raw     = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error   = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['success' => false, 'error' => $error];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['success' => false, 'error' => 'invalid_json_response', 'httpcode' => $httpcode, 'raw' => $raw];
        }
        if (($httpcode < 200 || $httpcode >= 300) && !isset($decoded['success'])) {
            $decoded['success'] = false;
        }
        return $decoded;
    }
}