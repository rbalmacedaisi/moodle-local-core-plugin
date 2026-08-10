<?php
/**
 * Webhook endpoint for Odoo -> Moodle status change notifications.
 *
 * Triggered at the end of wizard.aplazar.estudiante.do_aplazo / do_retiro /
 * do_reactivacion when the Odoo side has ir.config_parameter
 *   - subscription_oca.moodle_sync_url
 *   - subscription_oca.moodle_sync_token
 * configured. Closes the gap where the Odoo wizard is run directly from the
 * Odoo UI (bypassing LXP -> Express -> Odoo), so the Moodle side stays in
 * sync without the operator having to re-enter the change in the LXP
 * wizard.
 *
 * Auth: HMAC-like shared secret in X-Odoo-Webhook-Token header, matched
 * against plugin setting odoo_incoming_webhook_secret (admin -> Plugins ->
 * grupomakro_core -> Odoo Incoming Webhook Secret).
 *
 * Idempotency: if the student's current worst local_learning_users.status
 * already matches the target state implied by the action, the webhook is a
 * no-op and returns {skipped:true, reason:already_in_target_state}. This
 * avoids a double write when the LXP wizard is the source of truth: LXP
 * updates Moodle first, then calls Express -> Odoo which fires this webhook
 * back to Moodle - at that point Moodle already has the correct state and
 * skips the second update.
 *
 * Expected payload (Content-Type: application/json):
 *   {
 *     "vat": "<cedula>",
 *     "action": "aplazo" | "retiro" | "reactivar",
 *     "reason": "<free text, required for audit>",
 *     "actor_username": "<odoo user login, optional>",
 *     "target_period_name": "<gmk_academic_periods.name, only for aplazo>",
 *     "odoo_message_subject": "<chatter subject from Odoo, optional>"
 *   }
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Solutto Consulting
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');

require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/progress_manager.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/status_change_manager.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/student/update_student_status.php');

use local_grupomakro_core\external\student\update_student_status;

header('Content-Type: application/json');

/**
 * Idempotent responder: writes {success:bool, ...} JSON and exits with the
 * matching HTTP status code.
 */
function odoo_webhook_respond(int $http, array $body): void {
    http_response_code($http);
    echo json_encode($body);
    exit;
}

try {
    // ── Auth ──────────────────────────────────────────────────────────────
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $providedToken = '';
    foreach (['X-Odoo-Webhook-Token', 'x-odoo-webhook-token'] as $h) {
        if (!empty($headers[$h])) { $providedToken = trim((string)$headers[$h]); break; }
    }
    $configuredToken = (string)get_config('local_grupomakro_core', 'odoo_incoming_webhook_secret');
    if ($configuredToken === '' || $providedToken === '' || !hash_equals($configuredToken, $providedToken)) {
        odoo_webhook_respond(401, ['success' => false, 'error' => 'invalid_token']);
    }

    // ── Payload ───────────────────────────────────────────────────────────
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        odoo_webhook_respond(400, ['success' => false, 'error' => 'invalid_json_payload']);
    }

    $vat     = trim((string)($payload['vat']      ?? ''));
    $action  = trim((string)($payload['action']   ?? ''));
    $reason  = trim((string)($payload['reason']   ?? ''));
    $actorUsername = trim((string)($payload['actor_username'] ?? 'odoo'));
    $targetPeriodName = trim((string)($payload['target_period_name'] ?? ''));
    $odooSubject = trim((string)($payload['odoo_message_subject'] ?? ''));

    if ($vat === '') {
        odoo_webhook_respond(400, ['success' => false, 'error' => 'missing_vat']);
    }
    if (!in_array($action, ['aplazo', 'retiro', 'reactivar'], true)) {
        odoo_webhook_respond(400, ['success' => false, 'error' => 'invalid_action', 'action' => $action]);
    }
    if (mb_strlen($reason) < 5) {
        odoo_webhook_respond(400, ['success' => false, 'error' => 'reason_too_short']);
    }

    // ── Resolve user by VAT via the same helper the Odoo sync uses ───────
    $user = \local_grupomakro_progress_manager::find_user_by_vat_public($vat);
    if (!$user) {
        odoo_webhook_respond(404, ['success' => false, 'error' => 'user_not_found', 'vat' => $vat]);
    }

    // ── Look up target period (aplazo only) ─────────────────────────────
    $targetPeriod = null;
    if ($action === 'aplazo' && $targetPeriodName !== '') {
        $targetPeriod = $DB->get_record('gmk_academic_periods', ['name' => $targetPeriodName, 'status' => 1]);
        if (!$targetPeriod) {
            odoo_webhook_respond(404, [
                'success' => false,
                'error'   => 'target_period_not_found',
                'name'    => $targetPeriodName,
            ]);
        }
    }

    // ── Compute target status and check current state ──────────────────
    $targetStatus = $action === 'aplazo' ? 'aplazado'
                   : ($action === 'retiro' ? 'retirado' : 'activo');

    $lpUsers = $DB->get_records('local_learning_users', ['userid' => $user->id]);
    if (empty($lpUsers)) {
        odoo_webhook_respond(409, ['success' => false, 'error' => 'user_has_no_enrollments']);
    }

    $currentStatuses = array_map(function($lpu) { return $lpu->status; }, $lpUsers);
    $currentStatus = \local_grupomakro_status_change_manager::worst_status_public($currentStatuses);

    // Idempotency: if current already matches target, skip the write.
    if ($currentStatus === $targetStatus) {
        odoo_webhook_respond(200, [
            'success'        => true,
            'skipped'        => true,
            'reason'         => 'already_in_target_state',
            'current_status' => $currentStatus,
            'target_status'  => $targetStatus,
        ]);
    }

    // ── Apply the change in a delegated transaction ─────────────────────
    $reasonTag = $action === 'aplazo' ? 'student_deferred'
               : ($action === 'retiro' ? 'student_withdrawn' : 'student_reactivated');
    $internalAction = $action === 'reactivar' ? 'renovacion' : $action;
    $profileValue   = $action === 'aplazo' ? 'Aplazado'
                    : ($action === 'retiro' ? 'Retirado' : 'Activo');

    $transaction = $DB->start_delegated_transaction();
    try {
        // Drop active courses (no-op if already empty).
        $droppedIds = \local_grupomakro_progress_manager::drop_active_courses_for_user(
            $user->id, $reasonTag, 2 /* default to admin */,
        );

        // Flip local_learning_users.status in all plans.
        foreach ($lpUsers as $lpu) {
            $lpu->status = $targetStatus;
            $lpu->timemodified = time();
            $DB->update_record('local_learning_users', $lpu);
        }

        // Profile field mirror.
        update_student_status::write_profile_field($user->id, 'studentstatus', $profileValue);

        // Insert suspension row with origin=odoo + odoo-specific details.
        $suspension = new stdClass();
        $suspension->userid                 = $user->id;
        $suspension->status                 = $internalAction;
        $suspension->reason                 = $reason;
        $suspension->targetperiodid         = $targetPeriod ? $targetPeriod->id : null;
        $suspension->active_courses_dropped = json_encode($droppedIds);
        $suspension->usermodified           = 2; // actor = admin fallback
        $suspension->timecreated            = time();
        $suspension->origin                 = 'odoo';
        $suspension->details                = json_encode([
            'odoo_action'         => true,
            'odoo_actor'          => $actorUsername,
            'odoo_message_subject' => $odooSubject,
            'previous_status'     => $currentStatus,
            'target_period'       => $targetPeriod ? [
                'id'   => (int)$targetPeriod->id,
                'name' => $targetPeriod->name,
            ] : null,
            'dropped_courses'     => $droppedIds,
        ]);
        $suspensionId = $DB->insert_record('gmk_student_suspension', $suspension);

        $transaction->allow_commit();

        odoo_webhook_respond(200, [
            'success'         => true,
            'skipped'         => false,
            'suspension_id'   => (int)$suspensionId,
            'previous_status' => $currentStatus,
            'new_status'      => $targetStatus,
            'courses_dropped' => $droppedIds,
        ]);
    } catch (Exception $e) {
        $transaction->rollback($e);
        odoo_webhook_respond(500, ['success' => false, 'error' => 'db_error', 'message' => $e->getMessage()]);
    }
} catch (Throwable $e) {
    odoo_webhook_respond(500, ['success' => false, 'error' => 'internal_error', 'message' => $e->getMessage()]);
}