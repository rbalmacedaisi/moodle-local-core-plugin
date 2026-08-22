<?php

namespace local_grupomakro_core\event;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

/**
 * Event handler for user login events.
 */
class user_login_handler {

    /**
     * Minimum age (seconds) between automatic financial-status refreshes on login.
     * Keeps the proxy from being hammered when the same student logs in repeatedly.
     */
    const FINANCIAL_REFRESH_MIN_AGE = 6 * 3600;

    /**
     * Defensive throttle: even if the snapshot is stale, ignore a refresh that
     * was attempted within the last 60 seconds (e.g. user logged out and back
     * in immediately, or two devices hit login in parallel).
     */
    const FINANCIAL_REFRESH_DEFENSIVE = 60;

    /**
     * Handle user_loggedin event.
     *
     * @param \core\event\user_loggedin $event
     */
    public static function user_loggedin(\core\event\user_loggedin $event) {
        global $DB, $CFG;

        $userid = $event->userid;

        // Grace period: grant first-login access until end of month
        self::maybe_create_grace_period($userid);

        // Refresh financial status snapshot from Odoo (best-effort, throttled).
        // Failures are swallowed: a broken proxy must NEVER block a login.
        self::maybe_refresh_financial_status($userid);

        // DEBUG LOGGING
        $log_file = $CFG->dirroot . '/local/grupomakro_core/redirect_debug.log';
        $log_msg = date('Y-m-d H:i:s') . " - [Handler: user_login_handler] Login Event for User ID: $userid\n";

        // 1. Check for ACTIVE classes (Target: Teacher Dashboard). Support teachers
        // (gmk_class.supportinstructorid) get the same redirect treatment.
        $has_active_classes = $DB->record_exists_sql(
            "SELECT 1 FROM {gmk_class}
              WHERE (instructorid = :uid OR supportinstructorid = :uid)
                AND closed = 0
              LIMIT 1",
            ['uid' => (int)$userid]
        );
        $log_msg .= " - Has Active Classes: " . ($has_active_classes ? 'YES' : 'NO') . "\n";

        if ($has_active_classes) {
            file_put_contents($log_file, $log_msg . " - REDIRECTING to Teacher Dashboard\n", FILE_APPEND);
            $url = new \moodle_url('/local/grupomakro_core/pages/teacher_dashboard.php');
            redirect($url);
        }

        // 2. Check for INACTIVE Teacher status (Target: Inactive Dashboard)
        $has_past_classes = $DB->record_exists_sql(
            "SELECT 1 FROM {gmk_class}
              WHERE (instructorid = :uid OR supportinstructorid = :uid)
              LIMIT 1",
            ['uid' => (int)$userid]
        );
        $has_skills = $DB->record_exists('gmk_teacher_skill_relation', ['userid' => $userid]);
        $has_availability = $DB->record_exists('gmk_teacher_disponibility', ['userid' => $userid]);

        $log_msg .= " - Past Classes: " . ($has_past_classes ? 'YES' : 'NO') . "\n";
        $log_msg .= " - Skills: " . ($has_skills ? 'YES' : 'NO') . "\n";
        $log_msg .= " - Availability: " . ($has_availability ? 'YES' : 'NO') . "\n";

        if ($has_past_classes || $has_skills || $has_availability) {
            file_put_contents($log_file, $log_msg . " - REDIRECTING to Inactive Dashboard\n", FILE_APPEND);
            $url = new \moodle_url('/local/grupomakro_core/pages/inactive_teacher_dashboard.php');
            redirect($url);
        }

        file_put_contents($log_file, $log_msg . " - NO REDIRECT (Standard Moodle Behavior)\n", FILE_APPEND);
    }

    /**
     * Grant a first-login grace period until the last second of the current month.
     * Only runs when grace_period_enabled is active and the user is a Student logging in for the first time.
     */
    public static function maybe_create_grace_period(int $userid): void {
        global $DB;

        if (!get_config('local_grupomakro_core', 'grace_period_enabled')) {
            return;
        }

        $user = $DB->get_record('user', ['id' => $userid], 'id, lastlogin');
        if (!$user || $user->lastlogin != 0) {
            return; // Not first login
        }

        if ($DB->record_exists('gmk_grace_period', ['userid' => $userid])) {
            return; // Already has a grace period record
        }

        // Only for students (custom field usertype = 'Estudiante')
        $usertypefieldid = $DB->get_field('user_info_field', 'id', ['shortname' => 'usertype']);
        if ($usertypefieldid) {
            $usertype = $DB->get_field('user_info_data', 'data',
                ['userid' => $userid, 'fieldid' => $usertypefieldid]);
            if ($usertype && $usertype !== 'Estudiante') {
                return;
            }
        }

        // Get document number from custom field
        $documentnumber = '';
        $docfieldid = $DB->get_field('user_info_field', 'id', ['shortname' => 'documentnumber']);
        if ($docfieldid) {
            $documentnumber = $DB->get_field('user_info_data', 'data',
                ['userid' => $userid, 'fieldid' => $docfieldid]) ?: '';
        }

        // graceuntil = last second of last day of current month
        $graceuntil = mktime(23, 59, 59, (int)date('n'), (int)date('t'), (int)date('Y'));

        $record = new \stdClass();
        $record->userid         = $userid;
        $record->documentnumber = $documentnumber;
        $record->graceuntil     = $graceuntil;
        $record->timecreated    = time();
        $DB->insert_record('gmk_grace_period', $record);

        error_log("[grupomakro_core] Periodo de gracia creado para userid=$userid doc=$documentnumber hasta=" . date('Y-m-d', $graceuntil));
    }

    /**
     * Best-effort refresh of gmk_financial_status for a single student during
     * login. Throttled to 6h between refreshes by default and additionally
     * guarded against double-firing within 60s. Wrapped in try/catch so any
     * proxy failure NEVER blocks the login flow.
     *
     * Only triggers for users typed as 'Estudiante' (mirrors the grace period
     * gate) so admin/teacher logins don't waste proxy calls.
     *
     * @param int $userid
     * @return void
     */
    public static function maybe_refresh_financial_status(int $userid): void {
        global $DB, $CFG;

        // Only students — mirrors maybe_create_grace_period gate.
        $usertypefieldid = $DB->get_field('user_info_field', 'id', ['shortname' => 'usertype']);
        if ($usertypefieldid) {
            $usertype = $DB->get_field('user_info_data', 'data',
                ['userid' => $userid, 'fieldid' => $usertypefieldid]);
            if ($usertype && $usertype !== 'Estudiante') {
                return;
            }
        }

        // Need a document number — without one we cannot query Odoo.
        $docfieldid = $DB->get_field('user_info_field', 'id', ['shortname' => 'documentnumber']);
        if (!$docfieldid) {
            return;
        }
        $documentnumber = trim((string)$DB->get_field('user_info_data', 'data',
            ['userid' => $userid, 'fieldid' => $docfieldid]));
        if ($documentnumber === '') {
            return;
        }

        // Throttle: refresh only if the snapshot is older than FINANCIAL_REFRESH_MIN_AGE
        // or never recorded. Defensive second gate avoids burst refresh on
        // immediate re-login.
        $now = time();
        $current = $DB->get_record('gmk_financial_status', ['userid' => $userid], 'id, lastupdated');
        if ($current) {
            $age = $now - (int)$current->lastupdated;
            if ($age < self::FINANCIAL_REFRESH_MIN_AGE) {
                return;
            }
            if ($age < self::FINANCIAL_REFRESH_DEFENSIVE) {
                return;
            }
        }

        // Best-effort. Catches Throwable so nothing leaks to the login flow.
        try {
            $result = local_grupomakro_sync_financial_status([$userid]);
            if (isset($result['error'])) {
                $err = (string)$result['error'];
                $details = (string)($result['details'] ?? '');
                error_log("[grupomakro_core] login sync FAILED userid=$userid: {$err}"
                    . ($details !== '' ? ' (' . substr($details, 0, 200) . ')' : ''));
            }
        } catch (\Throwable $e) {
            error_log("[grupomakro_core] login sync EXCEPTION userid=$userid: " . $e->getMessage());
        }
    }
}
