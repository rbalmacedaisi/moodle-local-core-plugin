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
 * Teacher-driven revalidation manager.
 *
 * Handles: eligibility, scheduling (BBB session next week + Odoo invoice),
 * payment verification and grade consolidation. Mirrors the proven Odoo proxy
 * pattern from {@see \local_grupomakro_core\local\letters\manager}.
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
require_once($GLOBALS['CFG']->dirroot . '/local/grupomakro_core/classes/local/progress_manager.php');

/**
 * Revalidation business logic.
 */
class revalida_manager {

    /** @var string Odoo invoice ref prefix that links the invoice back to a revalidation row. */
    const REVALID_REF_PREFIX = 'REVALID_REQ:';

    /** @var float A revalidation exam grade strictly above this passes (canonical rule). */
    const PASS_THRESHOLD = 70.4;

    /** @var float Consolidated final course grade when the revalidation is passed. */
    const PASS_FINAL_GRADE = 71.0;

    /**
     * Whether a student is eligible to take a revalidation, using the existing
     * institutional rule: theoretical subject (no practical hours) and final
     * grade in the 60.0–70.4 window.
     *
     * @param float $grade
     * @param int   $practicalhours
     * @return bool
     */
    public static function is_eligible(float $grade, int $practicalhours): bool {
        return $practicalhours === 0 && $grade >= 60.0 && $grade <= self::PASS_THRESHOLD;
    }

    /**
     * Returns the revalidation rows for a class keyed by userid (for the teacher grid).
     *
     * @param int $classid
     * @return array<int, stdClass>
     */
    public static function get_for_class(int $classid): array {
        global $DB;
        $rows = $DB->get_records('gmk_revalidations', ['classid' => $classid]);
        $byuser = [];
        foreach ($rows as $r) {
            $r->bbb_url = self::bbb_url((int)$r->bbbcmid);
            $byuser[(int)$r->userid] = $r;
        }
        return $byuser;
    }

    /**
     * Returns the revalidation rows for a student keyed by corecourseid (for the director panel).
     *
     * @param int $userid
     * @return array<int, stdClass>
     */
    public static function get_for_user(int $userid): array {
        global $DB;
        $rows = $DB->get_records('gmk_revalidations', ['userid' => $userid]);
        $bycourse = [];
        foreach ($rows as $r) {
            $bycourse[(int)$r->corecourseid] = $r;
        }
        return $bycourse;
    }

    /**
     * Schedules revalidations for the given students of a class.
     *
     * Validates the gradebook weights sum to 100%, creates one shared BBB session
     * for the next-week slot, and for each student creates the Odoo invoice and a
     * gmk_revalidations row.
     *
     * @param int   $classid
     * @param int[] $userids
     * @param int   $actorid
     * @return array{ok:bool, error:?string, records:array}
     */
    public static function schedule(int $classid, array $userids, int $actorid): array {
        global $DB;

        $class = $DB->get_record('gmk_class', ['id' => $classid], '*', MUST_EXIST);

        // Weights must total 100% before revalidations can be scheduled.
        $weighterror = self::validate_weights($class);
        if ($weighterror !== null) {
            return ['ok' => false, 'error' => $weighterror, 'records' => []];
        }

        $userids = array_values(array_unique(array_map('intval', $userids)));
        if (empty($userids)) {
            return ['ok' => false, 'error' => 'No se seleccionaron estudiantes.', 'records' => []];
        }

        // Compute the next-week session window (same weekday/time as the class).
        [$sessionstart, $sessionend] = self::compute_next_week_session($class);
        if ($sessionstart <= 0) {
            return ['ok' => false, 'error' => 'No se pudo determinar el horario de la clase para la sesión de reválida.', 'records' => []];
        }

        // One shared BBB session for this batch.
        $bbbcmid = 0;
        try {
            $bbbcmid = self::create_bbb_session($class, $sessionstart, $sessionend);
        } catch (\Throwable $e) {
            \gmk_log('ERROR: revalida BBB session failed classid=' . $classid . ' msg=' . $e->getMessage());
            return ['ok' => false, 'error' => 'No se pudo crear la sesión de BigBlueButton: ' . $e->getMessage(), 'records' => []];
        }

        $now = time();
        $records = [];
        foreach ($userids as $userid) {
            $progre = $DB->get_record('gmk_course_progre',
                ['classid' => $classid, 'userid' => $userid], '*', IGNORE_MULTIPLE);
            if (!$progre) {
                continue;
            }

            $grade = \gmk_get_student_class_grade($classid, $userid);
            if ($grade === null) {
                continue;
            }
            if (!self::is_eligible((float)$grade, (int)($progre->practicalhours ?? 0))) {
                continue; // Defensive: only eligible students.
            }

            // Upsert the revalidation row (unique by class+user).
            $existing = $DB->get_record('gmk_revalidations', ['classid' => $classid, 'userid' => $userid]);
            $rec = $existing ?: new stdClass();
            $rec->classid        = $classid;
            $rec->userid         = $userid;
            $rec->corecourseid   = (int)$class->corecourseid;
            $rec->learningplanid = (int)($progre->learningplanid ?? 0);
            $rec->progreid       = (int)$progre->id;
            $rec->originalgrade  = round((float)$grade, 2);
            $rec->result         = 'pending';
            $rec->bbbcmid        = (int)$bbbcmid;
            $rec->sessionstart   = (int)$sessionstart;
            $rec->sessionend     = (int)$sessionend;
            $rec->status         = 'scheduled';
            $rec->timemodified   = $now;
            if ($existing) {
                $rec->id = (int)$existing->id;
                $DB->update_record('gmk_revalidations', $rec);
            } else {
                $rec->payment_state = 'unpaid';
                $rec->paidat        = 0;
                $rec->createdby     = $actorid;
                $rec->timecreated   = $now;
                $rec->id = (int)$DB->insert_record('gmk_revalidations', $rec);
            }

            // Create / locate the Odoo invoice (idempotent by ref REVALID_REQ:<id>).
            try {
                $invoice = self::create_invoice($rec, $userid);
                $rec->invoice_extref  = self::REVALID_REF_PREFIX . $rec->id;
                $rec->invoice_id      = (string)($invoice['invoice_id'] ?? '');
                $rec->invoice_number  = (string)($invoice['invoice_number'] ?? '');
                $rec->payment_link    = (string)($invoice['payment_link'] ?? '');
                $rec->timemodified    = time();
                $DB->update_record('gmk_revalidations', $rec);
            } catch (\Throwable $e) {
                \gmk_log('WARNING: revalida invoice failed revalidationid=' . $rec->id . ' msg=' . $e->getMessage());
                // Keep the row; the invoice can be retried. Surface the issue per record.
                $rec->invoice_error = $e->getMessage();
            }

            $rec->bbb_url = self::bbb_url((int)$rec->bbbcmid);
            $records[] = $rec;
        }

        if (empty($records)) {
            return ['ok' => false, 'error' => 'Ningún estudiante seleccionado es elegible a reválida.', 'records' => []];
        }

        return ['ok' => true, 'error' => null, 'records' => $records];
    }

    /**
     * Saves the revalidation grade after verifying the invoice is paid, then
     * consolidates the final course grade/status.
     *
     * @param int   $revalidationid
     * @param float $grade
     * @param int   $actorid
     * @return array{ok:bool, error:?string, result:?string, finalgrade:?float}
     */
    public static function save_grade(int $revalidationid, float $grade, int $actorid): array {
        global $DB;

        $rec = $DB->get_record('gmk_revalidations', ['id' => $revalidationid], '*', MUST_EXIST);

        // Payment gate: re-verify against Odoo if not already marked paid.
        if ($rec->payment_state !== 'paid') {
            if (!self::verify_payment($rec)) {
                return ['ok' => false, 'error' => 'La factura de reválida aún no está pagada.', 'result' => null, 'finalgrade' => null];
            }
            $rec = $DB->get_record('gmk_revalidations', ['id' => $revalidationid], '*', MUST_EXIST);
        }

        if ($grade < 0 || $grade > 100) {
            return ['ok' => false, 'error' => 'La nota debe estar entre 0 y 100.', 'result' => null, 'finalgrade' => null];
        }

        $passed = ($grade > self::PASS_THRESHOLD);
        $finalgrade = $passed ? self::PASS_FINAL_GRADE : round((float)$rec->originalgrade, 2);
        $newstatus  = $passed ? COURSE_APPROVED : COURSE_FAILED;
        $now = time();

        $transaction = $DB->start_delegated_transaction();
        try {
            // Consolidate into the student's course progress.
            $DB->execute(
                "UPDATE {gmk_course_progre}
                    SET status = :s, grade = :g, progress = :p, timemodified = :t
                  WHERE id = :id",
                ['s' => $newstatus, 'g' => $finalgrade, 'p' => 100.0, 't' => $now, 'id' => (int)$rec->progreid]
            );

            $rec->revalidgrade = round($grade, 2);
            $rec->result       = $passed ? 'approved' : 'failed';
            $rec->status       = 'consolidated';
            $rec->timemodified = $now;
            $DB->update_record('gmk_revalidations', $rec);

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            return ['ok' => false, 'error' => 'Error al guardar la nota de reválida: ' . $e->getMessage(), 'result' => null, 'finalgrade' => null];
        }

        return ['ok' => true, 'error' => null, 'result' => $rec->result, 'finalgrade' => $finalgrade];
    }

    /**
     * On-demand payment verification: asks Express/Odoo for the invoice payment state
     * and marks the row paid when confirmed.
     *
     * @param stdClass $rec
     * @return bool True when the invoice is paid.
     */
    public static function verify_payment(stdClass $rec): bool {
        global $DB;
        if (empty($rec->invoice_id)) {
            return false;
        }
        $response = self::call_odoo_proxy('/api/odoo/revalidations/invoice-status', [
            'invoice_id' => (string)$rec->invoice_id,
            'external_request_id' => (string)$rec->id,
        ]);
        $paid = !empty($response['success']) && (string)($response['payment_state'] ?? '') === 'paid';
        if ($paid && $rec->payment_state !== 'paid') {
            $DB->update_record('gmk_revalidations', (object)[
                'id' => (int)$rec->id,
                'payment_state' => 'paid',
                'paidat' => time(),
                'timemodified' => time(),
            ]);
        }
        return $paid;
    }

    /**
     * Marks a revalidation as paid from an Odoo payment webhook payload.
     *
     * @param array $payload
     * @return array{success:bool, message:string}
     */
    public static function handle_payment_webhook(array $payload): array {
        global $DB;
        $extref = trim((string)($payload['external_request_id'] ?? ''));
        $invoiceid = trim((string)($payload['invoice_id'] ?? ''));
        $invoicenumber = trim((string)($payload['invoice_number'] ?? ''));

        $rec = null;
        if ($extref !== '' && ctype_digit($extref)) {
            $rec = $DB->get_record('gmk_revalidations', ['id' => (int)$extref], '*', IGNORE_MISSING);
        }
        if (!$rec && $invoiceid !== '') {
            $rec = $DB->get_record('gmk_revalidations', ['invoice_id' => $invoiceid], '*', IGNORE_MISSING);
        }
        if (!$rec) {
            return ['success' => false, 'message' => 'revalidation_not_found'];
        }
        if ($rec->payment_state === 'paid') {
            return ['success' => true, 'message' => 'already_paid'];
        }

        $update = (object)[
            'id' => (int)$rec->id,
            'payment_state' => 'paid',
            'paidat' => time(),
            'timemodified' => time(),
        ];
        if ($invoiceid !== '') {
            $update->invoice_id = $invoiceid;
        }
        if ($invoicenumber !== '') {
            $update->invoice_number = $invoicenumber;
        }
        $DB->update_record('gmk_revalidations', $update);
        return ['success' => true, 'message' => 'marked_paid'];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internal helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Validates that the class gradebook category weights total 100%.
     *
     * @param stdClass $class
     * @return string|null Error message or null when valid.
     */
    private static function validate_weights(stdClass $class): ?string {
        global $DB;
        if (empty($class->gradecategoryid)) {
            return 'La clase no tiene categoría de calificaciones configurada.';
        }
        $gitems = $DB->get_records_select('grade_items',
            "categoryid = :cat AND itemtype IN ('mod','manual') AND itemnumber = 0",
            ['cat' => $class->gradecategoryid], '', 'id, aggregationcoef');
        if (empty($gitems)) {
            return 'No hay actividades calificables en esta clase.';
        }
        $wsum = array_sum(array_column((array)$gitems, 'aggregationcoef'));
        if (abs($wsum - 100.0) > 0.5) {
            return "Las ponderaciones suman {$wsum}% — deben sumar 100% antes de programar reválidas.";
        }
        return null;
    }

    /**
     * Creates the Odoo invoice for a revalidation via the Express proxy.
     *
     * @param stdClass $rec
     * @param int $userid
     * @return array{invoice_id:string, invoice_number:string, payment_link:string}
     * @throws moodle_exception
     */
    private static function create_invoice(stdClass $rec, int $userid): array {
        global $DB;
        $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
        $documentnumber = self::get_user_document_number($userid);
        if ($documentnumber === '') {
            throw new moodle_exception('El estudiante no tiene número de documento para facturar.');
        }
        $productid = (int)get_config('local_grupomakro_core', 'revalida_default_odoo_product_id');
        $cost = (float)get_config('local_grupomakro_core', 'revalida_cost');
        $coursename = (string)$DB->get_field('course', 'fullname', ['id' => (int)$rec->corecourseid]);

        $payload = [
            'external_request_id' => (string)$rec->id,
            'document_number' => $documentnumber,
            'student_email' => (string)$user->email,
            'amount' => $cost,
            'currency' => 'USD',
            'odoo_product_id' => $productid,
            'description' => 'Reválida: ' . $coursename . ' (#' . $rec->id . ')',
            'revalidation_id' => (string)$rec->id,
        ];
        $response = self::call_odoo_proxy('/api/odoo/revalidations/invoice', $payload);
        if (empty($response['success'])) {
            throw new moodle_exception('No se pudo generar la factura de reválida: '
                . (isset($response['error']) ? (string)$response['error'] : 'desconocido'));
        }
        return [
            'invoice_id' => (string)($response['invoice_id'] ?? ''),
            'invoice_number' => (string)($response['invoice_number'] ?? ''),
            'payment_link' => (string)($response['payment_link'] ?? ''),
        ];
    }

    /**
     * POSTs a JSON payload to the Express Odoo proxy. Mirrors letters\manager::call_odoo_proxy.
     *
     * @param string $path
     * @param array $payload
     * @return array
     */
    private static function call_odoo_proxy(string $path, array $payload): array {
        $baseurl = get_config('local_grupomakro_core', 'odoo_proxy_url');
        if (empty($baseurl)) {
            $baseurl = 'https://lms.isi.edu.pa:4000';
        }
        $url = rtrim($baseurl, '/') . $path;
        $jsonpayload = json_encode($payload);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonpayload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        $raw = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
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
     * Resolves the student's identification (documentnumber profile field, then idnumber).
     *
     * @param int $userid
     * @return string
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
     * Computes the next-week revalidation session window from the class schedule:
     * the first occurrence of the class weekday/time at least 7 days after the
     * class end date.
     *
     * @param stdClass $class
     * @return array{0:int,1:int} [startTS, endTS]
     */
    private static function compute_next_week_session(stdClass $class): array {
        global $DB;

        $daynamemap = [
            'lunes' => 'Monday', 'monday' => 'Monday',
            'martes' => 'Tuesday', 'tuesday' => 'Tuesday',
            'miercoles' => 'Wednesday', 'wednesday' => 'Wednesday',
            'jueves' => 'Thursday', 'thursday' => 'Thursday',
            'viernes' => 'Friday', 'friday' => 'Friday',
            'sabado' => 'Saturday', 'saturday' => 'Saturday',
            'domingo' => 'Sunday', 'sunday' => 'Sunday',
        ];

        $sched = $DB->get_record_sql(
            "SELECT * FROM {gmk_class_schedules} WHERE classid = :cid ORDER BY id ASC",
            ['cid' => (int)$class->id], IGNORE_MULTIPLE
        );

        $starttime = null;
        $endtime = null;
        $targetday = null;
        if ($sched) {
            $starttime = !empty($sched->start_time) ? $sched->start_time : null;
            $endtime   = !empty($sched->end_time) ? $sched->end_time : null;
            $targetday = $daynamemap[\cleanString((string)$sched->day)] ?? null;
        }
        if (!$starttime) {
            $starttime = !empty($class->inittime) ? $class->inittime : '08:00';
        }
        if (!$endtime) {
            $endtime = !empty($class->endtime) ? $class->endtime : null;
        }
        if (!$targetday && !empty($class->classdays)) {
            // First active day in the bitmask (Mon..Sun).
            $names = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
            $bits = explode('/', (string)$class->classdays);
            foreach ($bits as $i => $bit) {
                if ((string)$bit === '1' && isset($names[$i])) {
                    $targetday = $names[$i];
                    break;
                }
            }
        }
        if (!$targetday) {
            $targetday = date('l'); // Last-resort fallback.
        }

        // Base = one week after the class ends (or after now if the class has no end / is future).
        $base = !empty($class->enddate) ? (int)$class->enddate : time();
        $base = max($base, time());
        $cursor = new \DateTime(date('Y-m-d', $base));
        $cursor->modify('+7 day');
        // Advance to the next matching weekday.
        $guard = 0;
        while ($cursor->format('l') !== $targetday && $guard < 14) {
            $cursor->modify('+1 day');
            $guard++;
        }

        $datestr = $cursor->format('Y-m-d');
        $startts = strtotime($datestr . ' ' . $starttime . ':00');
        if ($startts === false) {
            return [0, 0];
        }
        $duration = (int)($class->classduration ?? 0);
        if ($endtime) {
            $s = strtotime('1970-01-01 ' . $starttime . ':00 UTC');
            $e = strtotime('1970-01-01 ' . $endtime . ':00 UTC');
            if ($e > $s) {
                $duration = $e - $s;
            }
        }
        if ($duration <= 0) {
            $duration = 2 * HOURSECS;
        }
        return [$startts, $startts + $duration];
    }

    /**
     * Creates the shared BBB session for a revalidation batch, reusing the class
     * BBB activity builder. Returns the course_module id.
     *
     * @param stdClass $class
     * @param int $startts
     * @param int $endts
     * @return int cmid
     */
    private static function create_bbb_session(stdClass $class, int $startts, int $endts): int {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/modlib.php');

        $course = get_course((int)$class->corecourseid);
        $class->course = $course;
        $sectionnumber = (int)$DB->get_field('course_sections', 'section', ['id' => (int)$class->coursesectionid]);
        $bbbmoduleid = (int)\gmk_get_module_id_by_name('bigbluebuttonbn');

        $info = \create_big_blue_button_activity($class, $startts, $endts, $bbbmoduleid, $sectionnumber);
        $cmid = (int)($info->coursemodule ?? 0);

        // Rename the instance so it is clearly a revalidation session.
        if (!empty($info->instance)) {
            $label = 'Reválida - ' . $class->name . ' - ' . date('d/m/Y H:i', $startts);
            $DB->set_field('bigbluebuttonbn', 'name', $label, ['id' => (int)$info->instance]);
        }
        if ($cmid > 0 && !empty($class->coursesectionid)) {
            \gmk_ensure_cmid_in_section_sequence((int)$class->coursesectionid, $cmid);
        }
        return $cmid;
    }

    /**
     * Builds the student-facing BBB view URL for a course module.
     *
     * @param int $cmid
     * @return string
     */
    private static function bbb_url(int $cmid): string {
        global $CFG;
        if ($cmid <= 0) {
            return '';
        }
        return $CFG->wwwroot . '/mod/bigbluebuttonbn/view.php?id=' . $cmid;
    }
}
