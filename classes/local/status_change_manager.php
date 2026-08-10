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

/**
 * Status change manager: business logic for the LXP wizard that postpones,
 * withdraws or registers a status change for a student, including the
 * Odoo-side effects that were previously only triggered by the Odoo wizard.
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Solutto Consulting
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/progress_manager.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/student/update_student_status.php');

use local_grupomakro_core\external\student\update_student_status;

class local_grupomakro_status_change_manager
{
    /** Action constants */
    const ACTION_APLAZAR = 'aplazar';
    const ACTION_RETIRAR = 'retirar';
    const ACTION_REACTIVAR = 'reactivar';

    /** Cache TTL for the per-vat pending-invoices lookup, in seconds. */
    const PENDING_INVOICES_CACHE_TTL = 30;

    /**
     * Build the payload for the preview step of the wizard.
     *
     * Combines Moodle-side data (academicstatus, plans, active courses) with
     * a best-effort lookup of pending invoices from Odoo through the Express
     * proxy. If the proxy is unreachable, the response still succeeds with an
     * empty invoice list and a flag so the UI can warn the user.
     *
     * @param int $userid
     * @return array
     */
    public static function build_preview($userid) {
        global $DB;

        $user = $DB->get_record('user', ['id' => $userid], 'id, username, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename, email, suspended, deleted', MUST_EXIST);
        $user->vat = self::get_user_vat($userid);

        // Profile field institucional.
        $studentstatus = self::get_profile_field($userid, 'studentstatus');

        // Estado académico por plan (peor caso entre los planes del usuario).
        $lpUsers = $DB->get_records('local_learning_users', ['userid' => $userid]);
        $academicstatuses = [];
        foreach ($lpUsers as $lpu) {
            $academicstatuses[] = $lpu->status;
        }
        $academicstatus = self::worst_status($academicstatuses);
        $reactivableStatuses = ['retirado', 'aplazado', 'suspendido', 'desertor'];
        $isReactivation = in_array($academicstatus, $reactivableStatuses, true);

        // Detalle por plan + cursos activos.
        $carrers = [];
        foreach ($lpUsers as $lpu) {
            $plan = $DB->get_record('local_learning_plans', ['id' => $lpu->learningplanid], 'id, name');
            $period = $lpu->currentperiodid
                ? $DB->get_record('local_learning_periods', ['id' => $lpu->currentperiodid], 'id, name')
                : null;
            $subperiod = $lpu->currentsubperiodid
                ? $DB->get_record('local_learning_subperiods', ['id' => $lpu->currentsubperiodid], 'id, name')
                : null;
            $acPeriod = $lpu->academicperiodid
                ? $DB->get_record('gmk_academic_periods', ['id' => $lpu->academicperiodid], 'id, name, startdate, enddate')
                : null;

            $activeCourses = $DB->get_records_sql(
                "SELECT cp.id, cp.courseid, c.fullname AS coursename, cp.classid,
                        cp.groupid, cp.progress, cp.grade, cp.status
                   FROM {gmk_course_progre} cp
                   JOIN {course} c ON c.id = cp.courseid
                  WHERE cp.userid = :userid
                    AND cp.learningplanid = :lpid
                    AND cp.status = :inprogress",
                ['userid' => $userid, 'lpid' => $lpu->learningplanid, 'inprogress' => COURSE_IN_PROGRESS]
            );

            $activeRows = [];
            foreach ($activeCourses as $ac) {
                $activeRows[] = [
                    'gcpid'    => (int)$ac->id,
                    'courseid' => (int)$ac->courseid,
                    'name'     => $ac->coursename,
                    'classid'  => (int)$ac->classid,
                    'groupid'  => (int)$ac->groupid,
                    'progress' => isset($ac->progress) ? (float)$ac->progress : null,
                    'grade'    => isset($ac->grade) ? (float)$ac->grade : null,
                    'status'   => (int)$ac->status,
                ];
            }

            $carrers[] = [
                'planid'              => (int)$lpu->learningplanid,
                'plan_name'           => $plan ? $plan->name : ('Plan ' . $lpu->learningplanid),
                'career'              => $plan ? $plan->name : null,
                'status'              => $lpu->status,
                'current_period_id'   => $period ? (int)$period->id : 0,
                'current_period_name' => $period ? $period->name : '--',
                'current_subperiod_id'   => $subperiod ? (int)$subperiod->id : 0,
                'current_subperiod_name' => $subperiod ? $subperiod->name : '--',
                'academic_period_id'   => $acPeriod ? (int)$acPeriod->id : 0,
                'academic_period_name' => $acPeriod ? $acPeriod->name : '--',
                'active_courses' => $activeRows,
            ];
        }

        // Periodos lectivos para el dropdown.
        $allAcPeriods = array_values(array_map(function($ap) {
            return [
                'id'        => (int)$ap->id,
                'name'      => $ap->name,
                'startdate' => (int)$ap->startdate,
                'enddate'   => isset($ap->enddate) ? (int)$ap->enddate : null,
                'status'    => (int)$ap->status,
            ];
        }, $DB->get_records('gmk_academic_periods', null, 'startdate DESC')));

        // Facturas pendientes via Express/Odoo (best-effort).
        $invoicesData = self::fetch_pending_invoices_from_odoo($user);
        $pendingInvoices = $invoicesData['invoices'];
        $pendingInvoicesUnavailable = $invoicesData['unavailable'];

        return [
            'userid'         => (int)$userid,
            'username'       => $user->username,
            'fullname'       => fullname($user),
            'vat'            => $user->vat,
            'email'          => $user->email,
            'studentstatus'  => $studentstatus,
            'academicstatus' => $academicstatus,
            'isreactivation' => $isReactivation,
            'carrers'        => $carrers,
            'pending_invoices' => $pendingInvoices,
            'pending_invoices_unavailable' => $pendingInvoicesUnavailable,
            'target_periods' => $allAcPeriods,
        ];
    }

    /**
     * Execute the wizard. Wraps a delegated transaction, writes the Moodle
     * side first and then calls the Express proxy for the Odoo side effects.
     *
     * @param int    $userid
     * @param string $action          'aplazar' or 'retirar'.
     * @param string $reason
     * @param int    $targetPeriodId  Required for 'aplazar'.
     * @param int    $actorUserid     Moodle user id performing the action.
     * @return array
     */
    public static function execute($userid, $action, $reason, $targetPeriodId = null, $actorUserid = 0) {
        global $DB, $USER;

        if (!in_array($action, [self::ACTION_APLAZAR, self::ACTION_RETIRAR, self::ACTION_REACTIVAR], true)) {
            return ['status' => 'error', 'message' => 'Acción no soportada.'];
        }
        if (trim((string)$reason) === '' || mb_strlen(trim((string)$reason)) < 10) {
            return ['status' => 'error', 'message' => 'Debe ingresar un motivo de al menos 10 caracteres.'];
        }
        if ($action === self::ACTION_APLAZAR && empty($targetPeriodId)) {
            return ['status' => 'error', 'message' => 'Debe seleccionar el periodo lectivo destino.'];
        }
        if ($actorUserid <= 0) {
            $actorUserid = $USER->id ?? 2;
        }

        $user = $DB->get_record('user', ['id' => $userid], 'id, username, firstname, lastname, email', MUST_EXIST);
        $user->vat = self::get_user_vat($userid);
        $lpUsers = $DB->get_records('local_learning_users', ['userid' => $userid]);
        if (empty($lpUsers)) {
            return ['status' => 'error', 'message' => 'El estudiante no tiene inscripciones activas.'];
        }

        // Validar periodo destino si aplazar.
        $targetPeriod = null;
        if ($action === self::ACTION_APLAZAR) {
            $targetPeriod = $DB->get_record('gmk_academic_periods', ['id' => $targetPeriodId, 'status' => 1]);
            if (!$targetPeriod) {
                return ['status' => 'error', 'message' => 'El periodo lectivo seleccionado no existe o está cerrado.'];
            }
        }

        $previousStatus = self::worst_status(array_map(function($lpu) { return $lpu->status; }, $lpUsers));
        $previousStatuses = [];
        foreach ($lpUsers as $lpu) { $previousStatuses[] = $lpu->status; }

        // ─── State guards (Fix #1) ────────────────────────────────────────
        // Avoid double-aplazar / double-retirar by short-circuiting with a
        // clear error message. Reactivation is only valid from a terminal
        // status; transitional states (e.g. desertor) need manual handling.
        if ($action === self::ACTION_APLAZAR) {
            if ($previousStatus === 'aplazado') {
                return [
                    'status'  => 'error',
                    'message' => 'El estudiante ya está aplazado. Si necesita cambiar el periodo destino, primero reactívelo y luego aplácelo nuevamente.',
                ];
            }
            if ($previousStatus === 'retirado') {
                return [
                    'status'  => 'error',
                    'message' => 'El estudiante está retirado. Use "Reactivar" antes de poder aplazar.',
                ];
            }
        } elseif ($action === self::ACTION_RETIRAR) {
            if ($previousStatus === 'retirado') {
                return [
                    'status'  => 'error',
                    'message' => 'El estudiante ya está retirado. No se puede retirar dos veces.',
                ];
            }
            // retiring an already-deferred student is a valid transition.
        } elseif ($action === self::ACTION_REACTIVAR) {
            $reactivable = ['retirado', 'aplazado', 'suspendido', 'desertor'];
            if (!in_array($previousStatus, $reactivable, true)) {
                return [
                    'status'  => 'error',
                    'message' => 'El estudiante no está en un estado que requiera reactivación (estado actual: ' . ($previousStatus ?: 'sin estado') . ').',
                ];
            }
        }

        // Action-specific knobs.
        if ($action === self::ACTION_REACTIVAR) {
            $internalAction = 'renovacion';   // we keep the gmk_student_suspension.status='renovacion' taxonomy
            $statusValue    = 'activo';
            $reasonTag      = 'student_reactivated';
            $profileValue   = 'Activo';
        } else {
            $internalAction = $action === self::ACTION_APLAZAR ? 'aplazo' : 'retiro';
            $statusValue    = $action === self::ACTION_APLAZAR ? 'aplazado' : 'retirado';
            $reasonTag      = $action === self::ACTION_APLAZAR ? 'student_deferred' : 'student_withdrawn';
            $profileValue   = $action === self::ACTION_APLAZAR ? 'Aplazado' : 'Retirado';
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            // 1) Des-matricular cursos activos y registrar movimientos.
            //    On reactivation the student should have 0 active courses already,
            //    but we still call it for safety (idempotent).
            $droppedIds = \local_grupomakro_progress_manager::drop_active_courses_for_user(
                $userid, $reasonTag, $actorUserid
            );

            // 2) Flip status academico en TODOS los planes.
            foreach ($lpUsers as $lpu) {
                $lpu->status = $statusValue;
                $lpu->timemodified = time();
                $DB->update_record('local_learning_users', $lpu);
            }

            // 3) Reflejar en el profile field institucional.
            update_student_status::write_profile_field($userid, 'studentstatus', $profileValue);

            // 4) Insertar fila de suspension.
            $suspension = new stdClass();
            $suspension->userid                 = $userid;
            $suspension->status                 = $internalAction;
            $suspension->reason                 = trim($reason);
            $suspension->targetperiodid         = $targetPeriod ? $targetPeriod->id : null;
            $suspension->active_courses_dropped = json_encode($droppedIds);
            $suspension->usermodified           = $actorUserid;
            $suspension->timecreated            = time();
            $suspension->origin                 = 'lxp';
            $suspension->details                = json_encode([
                'previous_status'   => $previousStatus,
                'previous_per_plan' => $previousStatuses,
                'target_period'     => $targetPeriod ? [
                    'id'   => (int)$targetPeriod->id,
                    'name' => $targetPeriod->name,
                ] : null,
                'dropped_courses'   => $droppedIds,
                'moodle_user_id'    => (int)$userid,
                'is_reactivation'   => $action === self::ACTION_REACTIVAR,
            ]);
            $suspensionId = $DB->insert_record('gmk_student_suspension', $suspension);

            $transaction->allow_commit();

            // 5) Después del commit Moodle, llamar a Express/Odoo best-effort.
            if ($action === self::ACTION_REACTIVAR) {
                $odooResult = self::dispatch_odoo_reactivation($user, $reason, $actorUserid);
            } else {
                $odooResult = self::dispatch_odoo_action($user, $action, $reason, $targetPeriod, $actorUserid);
            }

            // Persistir el resultado de Odoo dentro de details.odoo_result.
            if (!empty($odooResult)) {
                $suspension = $DB->get_record('gmk_student_suspension', ['id' => $suspensionId]);
                $details = json_decode($suspension->details ?? '{}', true) ?: [];
                $details['odoo_result'] = $odooResult;
                $suspension->details = json_encode($details);
                $DB->update_record('gmk_student_suspension', $suspension);
            }

            \gmk_log("status_change_manager: user $userid action=$action actor=$actorUserid dropped=" . count($droppedIds) . " odoo=" . json_encode($odooResult));

            $successMessages = [
                self::ACTION_APLAZAR   => 'Estudiante aplazado correctamente.',
                self::ACTION_RETIRAR   => 'Estudiante retirado correctamente.',
                self::ACTION_REACTIVAR => 'Estudiante reactivado correctamente.',
            ];

            return [
                'status'  => 'success',
                'message' => $successMessages[$action],
                'data' => [
                    'userid'         => (int)$userid,
                    'newstatus'      => $statusValue,
                    'suspension_id'  => (int)$suspensionId,
                    'courses_dropped' => $droppedIds,
                    'odoo_sync'      => $odooResult,
                ],
            ];
        } catch (Exception $e) {
            $transaction->rollback($e);
            return ['status' => 'error', 'message' => 'Error al aplicar el cambio: ' . $e->getMessage()];
        }
    }

    /**
     * History rows for the v-timeline in grademodal.js.
     *
     * @param int $userid
     * @return array
     */
    public static function get_history($userid) {
        global $DB;

        $rows = $DB->get_records_sql(
            "SELECT s.*,
                    u1.firstname AS actor_firstname, u1.lastname AS actor_lastname, u1.username AS actor_username,
                    ap.name AS target_period_name
               FROM {gmk_student_suspension} s
          LEFT JOIN {user} u1 ON u1.id = s.usermodified
          LEFT JOIN {gmk_academic_periods} ap ON ap.id = s.targetperiodid
              WHERE s.userid = :userid
           ORDER BY s.timecreated DESC, s.id DESC",
            ['userid' => $userid]
        );

        $out = [];
        foreach ($rows as $r) {
            $details = null;
            if (!empty($r->details)) {
                $decoded = json_decode($r->details, true);
                $details = is_array($decoded) ? $decoded : null;
            }
            $dropped = [];
            if (!empty($r->active_courses_dropped)) {
                $decoded = json_decode($r->active_courses_dropped, true);
                if (is_array($decoded)) {
                    $dropped = $decoded;
                }
            }
            $out[] = [
                'id'         => (int)$r->id,
                'status'     => $r->status,
                'origin'     => $r->origin ?? 'odoo',
                'reason'     => $r->reason,
                'target_period_id'   => $r->targetperiodid ? (int)$r->targetperiodid : null,
                'target_period_name' => $r->target_period_name,
                'active_courses_dropped' => $dropped,
                'details'    => $details,
                'actor' => [
                    'id'        => (int)$r->usermodified,
                    'username'  => $r->actor_username,
                    'fullname'  => trim(($r->actor_firstname ?? '') . ' ' . ($r->actor_lastname ?? '')),
                ],
                'timecreated' => (int)$r->timecreated,
            ];
        }
        return $out;
    }

    /**
     * Thin wrapper around Express `/api/odoo/students/:vat/pending-invoices`.
     * Returns ['invoices' => [...], 'unavailable' => bool].
     */
    protected static function fetch_pending_invoices_from_odoo($user) {
        $proxyUrl = get_config('local_grupomakro_core', 'odoo_proxy_url');
        if (empty($proxyUrl) || empty($user->vat)) {
            return ['invoices' => [], 'unavailable' => true];
        }
        $url = rtrim($proxyUrl, '/') . '/api/odoo/students/' . rawurlencode($user->vat) . '/pending-invoices';

        $headers = ['Accept' => 'application/json'];
        $apiKey = self::get_proxy_api_key();
        if (!empty($apiKey)) {
            $headers['X-Api-Key'] = $apiKey;
        }

        $curl = new curl();
        $curl->setHeader($headers);
        $curl->setopt([
            'CURLOPT_TIMEOUT'        => 5,
            'CURLOPT_CONNECTTIMEOUT' => 3,
        ]);
        $raw = $curl->get($url);
        $info = $curl->get_info();
        $httpCode = isset($info['http_code']) ? (int)$info['http_code'] : 0;
        $err = $curl->error ?? '';

        if ($err || $httpCode < 200 || $httpCode >= 300 || empty($raw)) {
            return ['invoices' => [], 'unavailable' => true];
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload) || !isset($payload['invoices'])) {
            return ['invoices' => [], 'unavailable' => true];
        }
        $today = time();
        $rows = [];
        foreach ($payload['invoices'] as $inv) {
            $dueTs = isset($inv['invoice_date_due']) ? strtotime((string)$inv['invoice_date_due']) : null;
            $rows[] = [
                'id'              => (int)($inv['id'] ?? 0),
                'number'          => $inv['number'] ?? '',
                'invoice_date'    => $inv['invoice_date'] ?? null,
                'invoice_date_due'=> $inv['invoice_date_due'] ?? null,
                'amount_total'    => isset($inv['amount_total']) ? (float)$inv['amount_total'] : null,
                'amount_residual' => isset($inv['amount_residual']) ? (float)$inv['amount_residual'] : null,
                'currency'        => $inv['currency'] ?? 'USD',
                'state'           => $inv['state'] ?? 'posted',
                'payment_state'   => $inv['payment_state'] ?? 'not_paid',
                'is_overdue'      => $dueTs ? ($dueTs < $today) : false,
            ];
        }
        return ['invoices' => $rows, 'unavailable' => false];
    }

    /**
     * Thin wrapper around Express POST `/api/odoo/students/{aplazar,retirar}`.
     * Returns a structured result that the caller can persist or surface.
     */
    protected static function dispatch_odoo_action($user, $action, $reason, $targetPeriod, $actorUserid) {
        global $DB, $USER;

        $proxyUrl = get_config('local_grupomakro_core', 'odoo_proxy_url');
        if (empty($proxyUrl) || empty($user->vat)) {
            return ['attempted' => false, 'success' => false, 'message' => 'Sin URL del proxy Odoo configurada o estudiante sin VAT.'];
        }

        $endpoint = $action === self::ACTION_APLAZAR ? 'aplazar' : 'retirar';
        $url = rtrim($proxyUrl, '/') . '/api/odoo/students/' . $endpoint;

        $actor = $DB->get_record('user', ['id' => $actorUserid], 'id, username, email', IGNORE_MISSING) ?? null;
        $body = [
            'vat'                => $user->vat,
            'reason'             => $reason,
            'actor_username'     => $actor ? $actor->username : ($USER->username ?? null),
            'actor_email'        => $actor ? $actor->email : ($USER->email ?? null),
            'actor_moodle_id'    => (int)$actorUserid,
        ];
        if ($action === self::ACTION_APLAZAR && $targetPeriod) {
            $body['target_period_name'] = $targetPeriod->name;
            $body['target_period_id']   = (int)$targetPeriod->id;
        }

        // Use native curl with explicit string-form headers. Moodle's
        // $curl->setHeader(['Content-Type' => 'application/json']) drops the
        // key and stores just the value, which produces malformed headers and
        // makes Express's body-parser fail to parse the JSON (HTTP 400
        // missing_vat). Native curl is the pattern already used elsewhere
        // in this plugin (locallib.php sync_financial_status, pages/bypass_financial.php).
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        $apiKey = self::get_proxy_api_key();
        if (!empty($apiKey)) {
            $headers[] = 'X-Api-Key: ' . $apiKey;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        $raw     = curl_exec($ch);
        $info    = curl_getinfo($ch);
        $httpCode = isset($info['http_code']) ? (int)$info['http_code'] : 0;
        $err     = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['attempted' => true, 'success' => false, 'http_code' => $httpCode, 'message' => 'Error de conexión: ' . $err];
        }
        $payload = json_decode((string)$raw, true);
        return [
            'attempted'  => true,
            'success'    => $httpCode >= 200 && $httpCode < 300 && (is_array($payload) && ($payload['success'] ?? true)),
            'http_code'  => $httpCode,
            'response'   => $payload,
            'message'    => is_array($payload) ? ($payload['message'] ?? ('HTTP ' . $httpCode)) : ('HTTP ' . $httpCode),
        ];
    }

    /**
     * Thin wrapper around Express POST /api/odoo/students/reactivar.
     * Used when reactivation is triggered from the status-change wizard
     * (not from the renewal/renovar flow, which has its own dispatcher in
     * ajax.php). Same error envelope as dispatch_odoo_action() so callers
     * can persist it identically in gmk_student_suspension.details.
     */
    protected static function dispatch_odoo_reactivation($user, $reason, $actorUserid) {
        global $DB, $USER;

        $proxyUrl = get_config('local_grupomakro_core', 'odoo_proxy_url');
        if (empty($proxyUrl) || empty($user->vat)) {
            return ['attempted' => false, 'success' => false, 'message' => 'Sin URL del proxy Odoo configurada o estudiante sin VAT.'];
        }

        $url = rtrim($proxyUrl, '/') . '/api/odoo/students/reactivar';

        $actor = $DB->get_record('user', ['id' => $actorUserid], 'id, username, email', IGNORE_MISSING) ?? null;
        $body = [
            'vat'             => $user->vat,
            'reason'          => $reason,
            'actor_username'  => $actor ? $actor->username : ($USER->username ?? null),
            'actor_email'     => $actor ? $actor->email    : ($USER->email    ?? null),
            'actor_moodle_id' => (int)$actorUserid,
        ];

        // Native curl: see dispatch_odoo_action() for rationale.
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        $apiKey = self::get_proxy_api_key();
        if (!empty($apiKey)) {
            $headers[] = 'X-Api-Key: ' . $apiKey;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        $raw     = curl_exec($ch);
        $info    = curl_getinfo($ch);
        $httpCode = isset($info['http_code']) ? (int)$info['http_code'] : 0;
        $err     = curl_error($ch);
        curl_close($ch);

        if ($err) {
            return ['attempted' => true, 'success' => false, 'http_code' => $httpCode, 'message' => 'Error de conexión: ' . $err];
        }
        $payload = json_decode((string)$raw, true);
        return [
            'attempted' => true,
            'success'   => $httpCode >= 200 && $httpCode < 300 && (is_array($payload) && ($payload['success'] ?? true)),
            'http_code' => $httpCode,
            'response'  => $payload,
            'message'   => is_array($payload) ? ($payload['message'] ?? ('HTTP ' . $httpCode)) : ('HTTP ' . $httpCode),
        ];
    }

    /**
     * Public wrapper around dispatch_odoo_reactivation() so callers outside
     * the class hierarchy (e.g. ajax.php's local_grupomakro_renovar_student
     * block when isReactivation=true) can fire the Odoo sync. Same envelope
     * (attempted/success/http_code/response/message).
     */
    public static function dispatch_odoo_reactivation_public($user, $reason, $actorUserid) {
        return self::dispatch_odoo_reactivation($user, $reason, $actorUserid);
    }

    /**
     * Read the Odoo proxy API key from Moodle plugin config. Returns empty
     * string if not configured; in that case the Express middleware allows
     * the request (backwards-compatible with the open /api/odoo/status/bulk).
     */
    protected static function get_proxy_api_key() {
        $key = get_config('local_grupomakro_core', 'odoo_proxy_api_key');
        return is_string($key) ? trim($key) : '';
    }

    /**
     * Return the worst (most blocking) status from a list. Order from
     * worst to best: retirado > desertor > aplazado > suspendido > activo > ...
     */
    protected static function worst_status(array $statuses) {
        $order = [
            'retirado'   => 100,
            'desertor'   => 90,
            'aplazado'   => 80,
            'suspendido' => 70,
            'egresado'   => 30,
            'graduado'   => 20,
            'activo'     => 10,
        ];
        $best = null;
        $bestScore = -1;
        foreach ($statuses as $s) {
            $s = strtolower((string)$s);
            $score = $order[$s] ?? 0;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $s;
            }
        }
        return $best;
    }

    /**
     * Read a custom profile field by shortname for a user.
     */
    protected static function get_profile_field($userid, $shortname) {
        global $DB;
        $field = $DB->get_record('user_info_field', ['shortname' => $shortname]);
        if (!$field) {
            return null;
        }
        $data = $DB->get_record('user_info_data', ['userid' => $userid, 'fieldid' => $field->id]);
        return $data ? $data->data : null;
    }

    /**
     * Resolve the student's VAT (cedula) for Odoo. Falls back to the
     * custom profile field shortnamed 'vat', or 'cedula', in that order.
     * Returns an empty string if no VAT is configured for the user.
     *
     * NOTE: mdl_user does NOT have a `vat` column in this deployment
     * (it's a custom profile field). Reading it via $user->vat throws
     * 'Unknown column vat in field list' on a $DB->get_record query.
     *
     * Tries shortnames in order: 'vat' (Odoo-style), 'cedula'
     * (Latin-American), and 'documentnumber' (this Moodle's actual
     * fieldname for the cedula, id=5 in user_info_field).
     *
     * @param int $userid
     * @return string
     */
    protected static function get_user_vat($userid) {
        foreach (['vat', 'cedula', 'documentnumber'] as $shortname) {
            $val = self::get_profile_field($userid, $shortname);
            if (!empty($val)) {
                return (string)$val;
            }
        }
        return '';
    }
}