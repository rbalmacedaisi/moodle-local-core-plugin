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
}
