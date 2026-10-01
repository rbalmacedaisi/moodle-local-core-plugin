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
 * Withdrawal (RET-01) request manager: CRUD + concurrent-safe per-year
 * correlative assignment + status state machine.
 *
 * Lives in classes/local/ rather than classes/ because it is not a Web
 * Service. The 8 services around it live in classes/external/wdr/.
 *
 * Concurrency model for `generate_request_number()`:
 *   We open a transaction, take a SELECT ... FOR UPDATE on the gmk_wdr_seq
 *   row for the year (creating it lazily when missing), read next_number,
 *   compute {PREFIX}-{YEAR}-{NNNN}, increment next_number, commit. Two
 *   concurrent requests serialise on the row lock and get distinct numbers.
 *
 * Number format: {prefix}-{YYYY}-{NNNN} where prefix is snapshotted at the
 * moment the year row is first created. Changing the
 *   `retirement_request_prefix` admin setting in mid-year therefore does NOT
 *   affect already-issued numbers — exactly the desired behaviour.
 *
 * Statuses (string, see wdr.status comments):
 *   solicitada              -> initial value when the student submits
 *   pendiente_firma_presencial
 *   firmada_digital        -> the admin uploaded a scanned signed copy
 *   recibida_direccion_academica
 *   recibida_direccion_administrativa
 *   procesada               -> closed, the student is officially withdrawn
 *   rechazada               -> rejection_reason populated
 *   cancelada               -> student cancelled before being processed
 *
 * Processing flow (process_withdrawal):
 *   The Procesar button in the admin inbox invokes this method which:
 *     1. Resolves the student's `documentnumber` (custom field).
 *     2. Calls Express GET /api/odoo/wdr/pending-balance.
 *     3. If hasBalance && !force && retirement_block_when_has_balance -> 409
 *        (with balance payload so the UI can prompt for force+reason).
 *     4. Else calls Express POST /api/odoo/wdr/process-retirement with
 *        force+reason; on success updates the wdr row to `procesada` and
 *        snapshots the audit columns (processed_at/by, process_odoo_*,
 *        process_balance_*, process_*_updated).
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Solutto Consulting
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/lib/pdfs/ret01_pdf_generator.php');

class wdr_manager {

    /** @var string[] Canonical statuses; the state machine only allows these. */
    public const STATUSES = [
        'solicitada',
        'pendiente_firma_presencial',
        'firmada_digital',
        'recibida_direccion_academica',
        'recibida_direccion_administrativa',
        'procesada',
        'rechazada',
        'cancelada',
    ];

    /** @var string[] Reasons defined by the RET-01 PDF letter. */
    public const REASONS = ['A', 'B', 'C', 'D', 'E', 'F'];

    /** @var string[] Payment options on RET-01 section 3 (alt payment allocations). */
    public const PAYMENT_OPTIONS = ['cambio_carrera', 'transferencia_derechos', 'no_aplica'];

    /**
     * Allocates the next request_number for the given year, creating the
     * gmk_wdr_seq row on first use. Concurrent-safe via row-level lock.
     *
     * Snapshot semantics: the row's `prefix` and `pad_length` are captured
     * from current admin settings the FIRST time the row is created and
     * never updated again. Changing the setting later does not rewrite
     * historical numbers.
     *
     * @param int|null $year Defaults to current year (4-digit).
     * @return array{request_number:string, year:int}
     */
    public static function generate_request_number(?int $year = null): array {
        global $DB;

        $year = $year ?: (int)gmdate('Y');

        // Read settings OUTSIDE the transaction so we only block for the
        // critical section. On the lazy insert we cache them onto the row.
        $prefix     = self::get_active_prefix();
        $pad        = max(1, (int)self::get_active_pad_length());

        $transaction = $DB->start_delegated_transaction();
        try {
            $row = $DB->get_record('gmk_wdr_seq', ['year' => $year], '*', IGNORE_MISSING);

            if (!$row) {
                // Lazy insert. Uniqueness on year_uix guarantees only one
                // concurrent inserter wins; the loser reads the row below.
                $newrow = (object)[
                    'year'        => $year,
                    'prefix'      => $prefix,
                    'pad_length'  => $pad,
                    'next_number' => 1,
                    'timecreated' => time(),
                    'timemodified'=> time(),
                ];
                try {
                    $newid = $DB->insert_record('gmk_wdr_seq', $newrow);
                    $row = $DB->get_record('gmk_wdr_seq', ['id' => $newid], '*', MUST_EXIST);
                } catch (dml_write_exception $e) {
                    // Another transaction beat us to it: read the row.
                    $row = $DB->get_record('gmk_wdr_seq', ['year' => $year], '*', MUST_EXIST);
                }
            } else {
                // Lock the existing row for the critical section.
                $DB->get_record('gmk_wdr_seq', ['id' => $row->id], '*', MUST_EXIST);
            }

            $number      = (int)$row->next_number;
            $requestnum  = sprintf('%s-%d-%0' . (int)$row->pad_length . 'd',
                $row->prefix, $year, $number);

            $DB->set_field('gmk_wdr_seq', 'next_number', $number + 1, ['id' => $row->id]);
            $DB->set_field('gmk_wdr_seq', 'timemodified', time(), ['id' => $row->id]);

            $DB->commit_delegated_transaction($transaction);

            return ['request_number' => $requestnum, 'year' => $year];
        } catch (\Throwable $e) {
            $DB->rollback_delegated_transaction($transaction);
            throw $e;
        }
    }

    /**
     * Reads the configured prefix with the legacy fallback (RET).
     */
    public static function get_active_prefix(): string {
        $v = get_config('local_grupomakro_core', 'retirement_request_prefix');
        $v = is_string($v) ? trim($v) : '';
        return $v !== '' ? $v : 'RET';
    }

    /**
     * Reads the configured pad length with sensible fallback (4).
     */
    public static function get_active_pad_length(): int {
        $v = get_config('local_grupomakro_core', 'retirement_request_pad_length');
        $v = is_numeric($v) ? (int)$v : 4;
        return max(1, $v);
    }

    /**
     * Reads the configured institutional format version (default "2026.3").
     */
    public static function get_template_version(): string {
        $v = get_config('local_grupomakro_core', 'retirement_template_version');
        return is_string($v) && trim($v) !== '' ? trim($v) : '2026.3';
    }

    /**
     * Creates a new withdrawal request for the given user.
     *
     * Snapshots: fullname (from user record at call time), program (from
     * local_learning_users when available), phone/email/id_number (from
     * user + profile fields).
     *
     * @param int   $userid The student userid.
     * @param array $data   The wizard payload (reason, payment_option,
     *                       payment_option_detail, observations, fullname,
     *                       program, current_period, last_period, phone,
     *                       id_number, email, payment_mode).
     * @return \stdClass The freshly-inserted row (with request_number assigned).
     */
    public static function create_request(int $userid, array $data): \stdClass {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/user/profile/lib.php');

        // Defensive: student MUST be the calling user OR siteadmin in tests.
        $context = \context_system::instance();
        if (!is_siteadmin() && $userid !== (int)$GLOBALS['USER']->id) {
            throw new \moodle_exception('nopermissions', 'error');
        }

        // Lookup student name and id from user table.
        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);

        // Try to read the career/program from local_learning_users + plans.
        $program = '';
        try {
            $llu = $DB->get_record('local_learning_users', ['userid' => $userid]);
            if ($llu && !empty($llu->learningplanid)) {
                $lp = $DB->get_record('local_learning_plans', ['id' => $llu->learningplanid]);
                if ($lp && !empty($lp->name)) {
                    $program = (string)$lp->name;
                }
            }
        } catch (\Throwable $e) {
            // The plan lookup is best-effort.
        }

        // Validate.
        $reason     = isset($data['reason']) && in_array($data['reason'], self::REASONS, true)
                        ? $data['reason'] : '';
        $payopt     = isset($data['payment_option']) && in_array($data['payment_option'], self::PAYMENT_OPTIONS, true)
                        ? $data['payment_option'] : '';
        $paydetail  = isset($data['payment_option_detail']) ? clean_param((string)$data['payment_option_detail'], PARAM_TEXT) : '';
        $obs        = isset($data['observations']) ? clean_param((string)$data['observations'], PARAM_TEXT) : '';
        $curperiod  = isset($data['current_period']) ? clean_param((string)$data['current_period'], PARAM_TEXT) : '';
        $lastperiod = isset($data['last_period']) ? clean_param((string)$data['last_period'], PARAM_TEXT) : '';
        $phone      = isset($data['phone']) ? clean_param((string)$data['phone'], PARAM_TEXT) : '';
        $idnumber   = isset($data['id_number']) ? clean_param((string)$data['id_number'], PARAM_TEXT) : (string)$user->idnumber;
        $email      = isset($data['email']) ? clean_param((string)$data['email'], PARAM_EMAIL) : $user->email;
        $paymode    = isset($data['payment_mode']) && in_array($data['payment_mode'], ['mensual', 'quincenal'], true)
                        ? $data['payment_mode'] : '';
        $fullname   = isset($data['fullname']) && trim((string)$data['fullname']) !== ''
                        ? clean_param((string)$data['fullname'], PARAM_TEXT)
                        : fullname($user);

        if ($reason === '') {
            throw new \moodle_exception('invalidparameter', 'error');
        }

        // Allocate the next request number (creates the year row lazily).
        $alloc = self::generate_request_number();

        $now = time();
        $record = (object)[
            'userid'                => $userid,
            'request_number'        => $alloc['request_number'],
            'fullname'              => $fullname,
            'program'               => $program,
            'current_period'        => $curperiod,
            'last_period'           => $lastperiod,
            'phone'                 => $phone,
            'id_number'             => $idnumber,
            'email'                 => $email,
            'payment_mode'          => $paymode,
            'reason'                => $reason,
            'payment_option'        => $payopt,
            'payment_option_detail' => $paydetail,
            'observations'          => $obs,
            'status'                => 'solicitada',
            'timecreated'           => $now,
            'timemodified'          => $now,
            'usermodified'          => $userid,
        ];

        // Insert with a UNIQUE-collision fallback. If two concurrent
        // requests collide on request_number (extremely unlikely: that means
        // we issued the same number twice, which the lock prevents), we
        // re-allocate and retry once.
        try {
            $record->id = $DB->insert_record('gmk_wdr', $record);
        } catch (dml_write_exception $e) {
            $record->request_number = self::generate_request_number()['request_number'];
            $record->id = $DB->insert_record('gmk_wdr', $record);
        }
        return $record;
    }

    /**
     * Returns the list of withdrawal requests for one student (newest first).
     *
     * @return array
     */
    public static function list_for_user(int $userid): array {
        global $DB;
        return $DB->get_records('gmk_wdr', ['userid' => $userid], 'timecreated DESC', '*');
    }

    /**
     * Returns one row by id, or throws.
     */
    public static function get(int $id): \stdClass {
        global $DB;
        return $DB->get_record('gmk_wdr', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Returns one row by id, checking that the calling user is allowed to
     * see it (owner OR has view_wdr_requests).
     *
     * @return \stdClass
     */
    public static function get_for_user(int $id, int $viewerid, bool $isadmin): \stdClass {
        global $DB;
        $row = self::get($id);
        if (!$isadmin && (int)$row->userid !== $viewerid) {
            throw new \moodle_exception('nopermissions', 'error');
        }
        return $row;
    }

    /**
     * Administrative status update for the inbox.
     *
     * @param int    $id           Request id.
     * @param string $action       One of: 'record_da', 'record_admin', 'reject', 'process', 'mark_pendiente_firma'.
     * @param int    $actorid      Moodle userid performing the action.
     * @param string|null $reject_reason Required only when action == 'reject'.
     * @return \stdClass The updated row.
     */
    public static function admin_update(int $id, string $action, int $actorid, ?string $reject_reason = null): \stdClass {
        global $DB;

        $row = self::get($id);
        $now = time();

        switch ($action) {
            case 'record_da':
                $row->received_da_at  = $now;
                $row->received_da_by  = $actorid;
                $row->status = 'recibida_direccion_academica';
                break;
            case 'record_admin':
                $row->received_admin_at  = $now;
                $row->received_admin_by  = $actorid;
                $row->status = 'recibida_direccion_administrativa';
                break;
            case 'reject':
                $reason = trim((string)$reject_reason);
                if ($reason === '') {
                    throw new \moodle_exception('missingparam', 'error', '', 'reject_reason');
                }
                $row->status = 'rechazada';
                $row->rejection_reason = $reason;
                break;
            case 'process':
                $row->status = 'procesada';
                break;
            case 'mark_pendiente_firma':
                $row->status = 'pendiente_firma_presencial';
                break;
            default:
                throw new \moodle_exception('invalidparameter', 'error');
        }
        $row->timemodified = $now;
        $row->usermodified = $actorid;

        $DB->update_record('gmk_wdr', $row);
        return $row;
    }

    /**
     * Stores the signed scanned PDF into the withdrawal_requests filearea
     * for one request. Returns the filearea-relative path.
     *
     * Validates magic (`%PDF-`), extension and size. The size cap mirrors
     * admin_upload_wellness_image (12 MB) so the UI cap stays consistent.
     *
     * @param int    $requestid Request id (used as itemid).
     * @param string $filename  The original filename from the upload.
     * @param string $content   Raw bytes of the PDF.
     * @return string Relative path stored on the file record.
     */
    public static function store_scanned(int $requestid, string $filename, string $content): string {
        global $USER;
        $context = \context_system::instance();
        $fs = get_file_storage();

        // Defensive validation. The filearea will be globally addressable
        // through pluginfile.php on lib.php:view_wdr_requests.
        if (strlen($content) === 0 || strlen($content) > 12 * 1024 * 1024) {
            throw new \moodle_exception('invalidparameter', 'error');
        }
        if (substr($content, 0, 5) !== '%PDF-') {
            throw new \moodle_exception('invalidparameter', 'error');
        }
        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'pdf') {
            throw new \moodle_exception('invalidparameter', 'error');
        }

        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'ret01_signed.pdf';
        $filepath = '/';
        $filearea = 'withdrawal_requests';

        // Replace any prior file at the same slot so the slot holds a
        // single version (the latest signed copy).
        $fs->delete_area_files($context->id, 'local_grupomakro_core', $filearea, $requestid);

        $stored = $fs->create_file_from_string([
            'contextid' => $context->id,
            'component'=> 'local_grupomakro_core',
            'filearea' => $filearea,
            'itemid'   => $requestid,
            'filepath' => $filepath,
            'filename' => $safe,
            'userid'   => (int)$USER->id,
        ], $content);

        return $filepath . $safe;
    }

    /**
     * Returns the signed scanned file (or null) for download through the
     * external service.
     */
    public static function get_scanned(int $requestid): ?\stored_file {
        $context = \context_system::instance();
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'local_grupomakro_core', 'withdrawal_requests', $requestid, 'id', false);
        if (empty($files)) {
            return null;
        }
        return reset($files);
    }

    /**
     * Generates the PDF body for a request and returns the raw bytes.
     */
    public static function render_pdf(int $id): string {
        $row = self::get($id);
        $generator = new \local_grupomakro_core\local\pdf\ret01_pdf_generator($row);
        return $generator->render();
    }

    /**
     * Resolves the student's `documentnumber` (Moodle custom field) for
     * the Odoo lookup. Empty string if missing — the caller is expected
     * to treat that as a hard error since Odoo requires a VAT match.
     *
     * @return string The document number, or '' if missing.
     */
    public static function resolve_document_number(int $userid): string {
        global $DB;
        try {
            $field = $DB->get_record('user_info_field', ['shortname' => 'documentnumber']);
        } catch (\Throwable $e) {
            return '';
        }
        if (!$field) {
            return '';
        }
        $rec = $DB->get_record('user_info_data',
            ['userid' => $userid, 'fieldid' => $field->id], 'data');
        return $rec ? trim((string)$rec->data) : '';
    }

    /**
     * Whether the global "block processing when balance is pending" setting
     * is ON. Defaults to ON so the institutional rule survives a missing
     * setting during a partial upgrade.
     */
    public static function block_when_has_balance(): bool {
        $v = get_config('local_grupomakro_core', 'retirement_block_when_has_balance');
        // get_config returns '0'/'1' strings for checkboxes; truthy covers both
        // explicit ON and the legacy unset (defaults to ON).
        return $v === null || $v === false || $v === '' ? true : (bool)(int)$v;
    }

    /**
     * Reads the Express proxy URL from the same setting that
     * local_grupomakro_sync_financial_status() uses, with a hard-coded
     * fallback so the system works even if the setting was never saved.
     *
     * @return string URL with no trailing slash.
     */
    public static function odoo_proxy_url(): string {
        $url = get_config('local_grupomakro_core', 'odoo_proxy_url');
        if (empty($url)) {
            $url = 'https://lms.isi.edu.pa:4000';
        }
        return rtrim((string)$url, '/');
    }

    /**
     * Reads the shared X-Api-Key with the proxy. Empty string disables
     * the header (matches Express's no-op middleware when the env var is
     * unset on its side too).
     */
    public static function odoo_proxy_api_key(): string {
        $v = get_config('local_grupomakro_core', 'odoo_proxy_api_key');
        return is_string($v) ? trim($v) : '';
    }

    /**
     * Performs a JSON request against the Express proxy with X-Api-Key
     * when configured. Returns { status, body, error }. `body` is the
     * decoded JSON when available, null otherwise.
     *
     * @param string $method  GET | POST
     * @param string $path    Path-only URL fragment (e.g. "/api/odoo/wdr/pending-balance")
     * @param array  $query   Query string parameters
     * @param array  $payload JSON body (POST only)
     * @return array{status:int, body:?array, error:?string}
     */
    public static function call_odoo_proxy(string $method, string $path, array $query = [], array $payload = []): array {
        $url = self::odoo_proxy_url() . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        $apiKey = self::odoo_proxy_api_key();
        if ($apiKey !== '') {
            $headers[] = 'X-Api-Key: ' . $apiKey;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // dev/cert-rollover; proxy is internal.
        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        } else {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        }

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $errstr = curl_error($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $raw === false) {
            return [
                'status' => 0,
                'body' => null,
                'error' => $errstr !== '' ? $errstr : 'curl_errno_' . $errno,
            ];
        }

        $body = null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $body = $decoded;
            }
        }
        return ['status' => $http, 'body' => $body, 'error' => null];
    }

    /**
     * Closes the retirement loop: drives the WDR state to `procesada`
     * after consulting Odoo for the partner's pending balance and
     * optionally forcing the override when balance > 0.
     *
     * Behaviour matrix (with retirement_block_when_has_balance=ON, default):
     *
     *   hasBalance=false, force=*            -> call Odoo, mark procesada.
     *   hasBalance=true,  force=false        -> moodle_exception 'pendingbalance'.
     *   hasBalance=true,  force=true, no len -> moodle_exception 'reasonrequired'.
     *   hasBalance=true,  force=true, len>=10-> call Odoo, mark procesada + forced_*
     *
     * With retirement_block_when_has_balance=OFF:
     *
     *   hasBalance=true, force=false        -> call Odoo (admin opts out of the
     *                                          institutional rule), proceeds.
     *   hasBalance=true, force=true         -> same as above + audits forced_*.
     *
     * @param int    $id       WDR request id.
     * @param int    $actorid  Admin userid clicking Procesar.
     * @param bool   $force    True to bypass the balance block.
     * @param string|null $reason  Required when force=true, must be >= 10 chars.
     * @return \stdClass The updated wdr row.
     * @throws \moodle_exception on validation errors or upstream failures.
     */
    public static function process_withdrawal(int $id, int $actorid, bool $force = false, ?string $reason = null): \stdClass {
        global $DB;

        $row = self::get($id);

        // Only certain statuses are eligible for processing. We refuse
        // anything already closed (procesada / rechazada / cancelada) and
        // anything still waiting for the student (solicitada /
        // pendiente_firma_presencial / firmada_digital).
        $eligibleStates = ['recibida_direccion_academica', 'recibida_direccion_administrativa'];
        if (!in_array($row->status, $eligibleStates, true)) {
            throw new \moodle_exception('invalidwdrstatus', 'local_grupomakro_core', '', $row->status);
        }

        $documentNumber = self::resolve_document_number((int)$row->userid);
        if ($documentNumber === '') {
            throw new \moodle_exception('wdr_missing_document_number', 'local_grupomakro_core');
        }

        // 1. Consult balance.
        $balanceResp = self::call_odoo_proxy('GET', '/api/odoo/wdr/pending-balance',
            ['documentNumber' => $documentNumber]);
        if ($balanceResp['error'] !== null || $balanceResp['status'] !== 200 || !is_array($balanceResp['body'])) {
            throw new \moodle_exception('wdr_balance_check_failed', 'local_grupomakro_core',
                '', $balanceResp['error'] ?? ('http_' . $balanceResp['status']));
        }
        $balance = $balanceResp['body'];
        $hasBalance = (bool)($balance['hasBalance'] ?? false);
        $balanceTotal = isset($balance['total']) ? (float)$balance['total'] : 0.0;
        $balanceCurrency = isset($balance['currency']) ? (string)$balance['currency'] : '';

        // 2. Block if needed.
        if ($hasBalance && !$force && self::block_when_has_balance()) {
            // Throw with extra data attached so the WS can serialise the
            // balance to the inbox UI. moodle_exception supports ->a
            // through $a and we pass a JSON-encoded payload so the API
            // response can hint back.
            $ex = new \moodle_exception('wdr_pending_balance', 'local_grupomakro_core', '',
                json_encode([
                    'documentNumber' => $documentNumber,
                    'total'          => $balanceTotal,
                    'currency'       => $balanceCurrency,
                    'invoiceCount'   => $balance['invoiceCount'] ?? 0,
                    'overdueCount'   => $balance['overdueCount'] ?? 0,
                    'fetchedAt'      => $balance['fetchedAt'] ?? null,
                ]));
            // Custom: attach the body for the inbox rendering. moodle_exception
            // doesn't have a native extension slot, but the WS can detect
            // this code (wdr_pending_balance) and fetch the latest balance
            // itself, so we just propagate the message; richer payload is
            // exposed via admin_get_request_detail() which always queries
            // /pending-balance on the client side anyway.
            throw $ex;
        }

        // 3. If force=true, validate the justification.
        if ($force) {
            $reason = trim((string)$reason);
            if ($reason === '' || mb_strlen($reason) < 10) {
                throw new \moodle_exception('wdr_force_reason_required', 'local_grupomakro_core');
            }
        } else {
            $reason = '';
        }

        // 4. Drive the wizard. Express enforces 'pending_balance' itself
        // (defence-in-depth) so we still pass force + reason explicitly.
        $actor = $DB->get_record('user', ['id' => $actorid], 'id, username, email, idnumber', MUST_EXIST);
        $procResp = self::call_odoo_proxy('POST', '/api/odoo/wdr/process-retirement', [], [
            'documentNumber'  => $documentNumber,
            'wdrId'           => (int)$row->id,
            'reason'          => $reason !== '' ? $reason : 'Retiro procesado por ' . $actor->username,
            'force'           => $force,
            'actor_username'  => $actor->username,
            'actor_email'     => $actor->email,
            'actor_moodle_id' => (int)$actor->id,
        ]);

        if ($procResp['status'] !== 200 || !is_array($procResp['body']) || empty($procResp['body']['success'])) {
            $errCode = is_array($procResp['body']) ? ($procResp['body']['error'] ?? 'unknown') : 'no_body';
            throw new \moodle_exception('wdr_process_failed', 'local_grupomakro_core',
                '', $errCode . '|http_' . $procResp['status']);
        }
        $procBody = $procResp['body'];

        // 5. Persist the audit + status.
        $now = time();
        $row->status = 'procesada';
        $row->processed_at = $now;
        $row->processed_by = $actorid;
        $row->process_odoo_partner_id = isset($procBody['partner_id']) ? (int)$procBody['partner_id'] : 0;
        $row->process_balance_total = $balanceTotal;
        $row->process_balance_currency = $balanceCurrency;
        $row->process_invoices_updated = isset($procBody['invoices_updated']) ? (int)$procBody['invoices_updated'] : 0;
        $row->process_subs_updated = isset($procBody['subscriptions_updated']) ? (int)$procBody['subscriptions_updated'] : 0;
        if ($force) {
            $row->forced_at = $now;
            $row->forced_by = $actorid;
            $row->forced_reason = $reason;
        }
        $row->timemodified = $now;
        $row->usermodified = $actorid;

        $DB->update_record('gmk_wdr', $row);
        return $row;
    }
}
