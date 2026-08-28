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
 * Student digital ID card manager (RF-07, RF-09.4).
 *
 * - Lazily issues a carnet the first time a student requests it (or
 *   automatically on login if wellness_carnet_auto_issue is enabled).
 * - Generates a random 40-char qr_token used by the public verifier.
 * - Public verify_token() resolves (userid, token, valid_until) WITHOUT
 *   trusting the URL alone: it looks up the carnet by userid and compares
 *   the token with hash_equals (constant-time).
 * - Photo: when photo_path is empty, the carnet falls back to the user's
 *   Moodle profile picture (RF-09.4 sync option).
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local;

defined('MOODLE_INTERNAL') || die();

class wellness_carnet_manager {

    /** Carnet status values. */
    public const STATUS_ACTIVO     = 'activo';
    public const STATUS_SUSPENDIDO = 'suspendido';
    public const STATUS_EGRESADO   = 'egresado';

    /** Default validity window in months when settings has no value. */
    public const DEFAULT_VALIDITY_MONTHS = 12;

    /** qr_token alphabet — 40 chars URL-safe. */
    private const TOKEN_ALPHABET = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    /**
     * Lazy issue: returns the existing carnet untouched. Only creates a
     * new row when the user has never had one. Status (suspendido/egresado)
     * and validity dates are NEVER overwritten here — that is the job of
     * renew() (admin-only) and set_status() (suspend/reinstate).
     *
     * @return object The carnet row.
     */
    public static function issue(int $userid, int $authorid = 0): object {
        global $DB;

        if ($userid <= 0
            || !$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            throw new \moodle_exception('wellness_carnet_user_invalid', 'local_grupomakro_core');
        }

        $existing = self::get($userid);
        if ($existing) {
            return $existing; // Never reissue on every read.
        }

        $user = $DB->get_record('user', ['id' => $userid]);

        $validityMonths = (int)get_config('local_grupomakro_core', 'wellness_carnet_validity_months');
        if ($validityMonths <= 0) {
            $validityMonths = self::DEFAULT_VALIDITY_MONTHS;
        }
        $now = time();
        $validFrom = $now;
        $validUntil = self::compute_valid_until($validFrom, $validityMonths);

        $fullname   = trim(fullname($user));
        $docnumber  = (string)$user->idnumber;
        $planName   = self::resolve_current_plan_name($userid);
        $admission  = self::resolve_admission_date($userid);

        $record = (object)[
            'userid'             => $userid,
            'fullname'           => $fullname !== '' ? $fullname : $user->username,
            'documentnumber'     => $docnumber,
            'learning_plan_name' => $planName,
            'admission_date'     => $admission,
            'valid_from'         => $validFrom,
            'valid_until'        => $validUntil,
            'status'             => self::STATUS_ACTIVO,
            'qr_token'           => self::generate_token(),
            'photo_path'         => '',
            'issued_at'          => $now,
            'revoked_at'         => 0,
            'usermodified'       => $authorid ?: $userid,
            'timemodified'       => $now,
        ];

        $record->id = (int)$DB->insert_record('gmk_wellness_carnet', $record);
        return $DB->get_record('gmk_wellness_carnet', ['id' => $record->id]);
    }

    /**
     * Admin-only reissue: extend validity another window and reset status
     * to activo. Use this when an admin manually clicks "Renovar" in the
     * back-office. NEVER called from the LXP or login path.
     */
    public static function renew(int $userid, int $authorid): ?object {
        global $DB;
        $existing = self::get($userid);
        if (!$existing) {
            return self::issue($userid, $authorid);
        }
        $validityMonths = (int)get_config('local_grupomakro_core', 'wellness_carnet_validity_months');
        if ($validityMonths <= 0) {
            $validityMonths = self::DEFAULT_VALIDITY_MONTHS;
        }
        $now = time();
        $existing->valid_from  = $now;
        $existing->valid_until = self::compute_valid_until($now, $validityMonths);
        $existing->status       = self::STATUS_ACTIVO;
        $existing->revoked_at   = 0;
        $existing->usermodified = $authorid;
        $existing->timemodified = $now;
        $DB->update_record('gmk_wellness_carnet', $existing);
        return self::get($userid);
    }

/**
     * Compute the validity end, normalised so 31-Jan + 1 month = 28-Feb
     * (not 3-Mar). When the requested day does not exist in the target
     * month (e.g. 31st, or 29-Feb in a non-leap year), the helper returns
     * the LAST day of the intended target month — not the month after.
     * The original time-of-day is preserved so the carnet does not
     * silently expire at 00:00.
     *
     * Implementation uses pure integer + mktime math because strtotime's
     * behaviour around month arithmetic depends on the runtime build
     * (Windows vs Linux, "month" vs "months" plural) and silently
     * no-ops when the offset is 1 on Windows. Building (year, month, day)
     * with intdiv / modulo + mktime is unambiguous and locale-independent.
     */
    private static function compute_valid_until(int $from, int $months): int {
        $h = (int)date('H', $from);
        $i = (int)date('i', $from);
        $s = (int)date('s', $from);

        $origYear  = (int)date('Y', $from);
        $origMonth = (int)date('n', $from);
        $origDay   = (int)date('j', $from);

        // (year, month) of the intended target, wrapping across years.
        $totalMonths = ($origYear * 12) + ($origMonth - 1) + $months;
        $targetYear  = intdiv($totalMonths, 12);
        $targetMonth = ($totalMonths % 12) + 1;

        // Clamp the day when the target month can't hold it (e.g. 31 in Feb).
        $firstOfTarget = mktime(0, 0, 0, $targetMonth, 1, $targetYear);
        $daysInTarget = (int)date('t', $firstOfTarget);
        $targetDay = ($origDay > $daysInTarget) ? $daysInTarget : $origDay;

        return mktime($h, $i, $s, $targetMonth, $targetDay, $targetYear);
    }

    /**
     * Read the carnet for $userid, or null.
     */
    public static function get(int $userid): ?object {
        global $DB;
        $row = $DB->get_record('gmk_wellness_carnet', ['userid' => $userid]);
        return $row ?: null;
    }

    /**
     * Force a new qr_token (used when a carnet is compromised / lost).
     */
    public static function regenerate_token(int $userid, int $authorid): bool {
        global $DB;
        $existing = self::get($userid);
        if (!$existing) {
            return false;
        }
        $DB->set_field('gmk_wellness_carnet', 'qr_token', self::generate_token(), ['id' => $existing->id]);
        $DB->set_field('gmk_wellness_carnet', 'usermodified', $authorid, ['id' => $existing->id]);
        $DB->set_field('gmk_wellness_carnet', 'timemodified', time(), ['id' => $existing->id]);
        return true;
    }

    /**
     * Suspend or revoke (sets status and revoked_at, leaves the row in
     * place so the LXP can render a "Carnet no vigente" message).
     */
    public static function set_status(int $userid, string $status, int $authorid): bool {
        global $DB;
        if (!in_array($status, [self::STATUS_ACTIVO, self::STATUS_SUSPENDIDO, self::STATUS_EGRESADO], true)) {
            return false;
        }
        $existing = self::get($userid);
        if (!$existing) {
            return false;
        }
        $update = (object)[
            'id'           => (int)$existing->id,
            'status'       => $status,
            'revoked_at'   => $status === self::STATUS_ACTIVO ? 0 : time(),
            'usermodified' => $authorid,
            'timemodified' => time(),
        ];
        // When re-activating, extend validity another window.
        if ($status === self::STATUS_ACTIVO) {
            $validityMonths = (int)get_config('local_grupomakro_core', 'wellness_carnet_validity_months');
            if ($validityMonths <= 0) {
                $validityMonths = self::DEFAULT_VALIDITY_MONTHS;
            }
            // N-04: route through the same helper as issue()/renew() so the
            // three entry points always agree on the new valid_until.
            $update->valid_from  = time();
            $update->valid_until = self::compute_valid_until(time(), $validityMonths);
        }
        $DB->update_record('gmk_wellness_carnet', $update);
        return true;
    }

    /**
     * Verify a carnet via the public URL parameters.
     *   - Looks up the carnet by userid (token is NOT enough to find the row).
     *   - Compares the submitted token with hash_equals() (constant time).
     *   - Checks the validity window.
     *
     * Returns the cast payload (carnet + display fields) for the verifier
     * page, or null when ANY check fails. The page deliberately collapses
     * "no such user", "wrong token" and "expired" into a single generic
     * "invalid" message to avoid enumeration.
     *
     * @param int $userid
     * @param string $token
     * @return array{carnet:object, status:string, valid_until:int, fullname:string, plan:string}|null
     */
    public static function verify_public(int $userid, string $token): ?array {
        global $DB;
        if ($userid <= 0 || $token === '') {
            return null;
        }
        $row = $DB->get_record('gmk_wellness_carnet', ['userid' => $userid]);
        if (!$row) {
            return null;
        }
        if (!hash_equals((string)$row->qr_token, $token)) {
            return null;
        }
        $now = time();
        if ((int)$row->valid_until > 0 && (int)$row->valid_until < $now) {
            return [
                'carnet'      => $row,
                'status'      => 'expired',
                'valid_until' => (int)$row->valid_until,
                'fullname'    => (string)$row->fullname,
                'plan'        => (string)$row->learning_plan_name,
            ];
        }
        if ((string)$row->status === self::STATUS_SUSPENDIDO) {
            return [
                'carnet'      => $row,
                'status'      => 'suspended',
                'valid_until' => (int)$row->valid_until,
                'fullname'    => (string)$row->fullname,
                'plan'        => (string)$row->learning_plan_name,
            ];
        }
        if ((string)$row->status === self::STATUS_EGRESADO) {
            return [
                'carnet'      => $row,
                'status'      => 'egresado',
                'valid_until' => (int)$row->valid_until,
                'fullname'    => (string)$row->fullname,
                'plan'        => (string)$row->learning_plan_name,
            ];
        }
        return [
            'carnet'      => $row,
            'status'      => 'active',
            'valid_until' => (int)$row->valid_until,
            'fullname'    => (string)$row->fullname,
            'plan'        => (string)$row->learning_plan_name,
        ];
    }

    /**
     * Build the absolute URL the QR encodes. The query string is opaque
     * to scanners; the verifier page is the only thing that interprets it.
     */
    public static function build_qr_url(string $wwwroot, int $userid, string $token): string {
        return rtrim($wwwroot, '/')
            . '/local/grupomakro_core/pages/carnet_verify.php'
            . '?u=' . ((int)$userid)
            . '&t=' . urlencode($token);
    }

    /**
     * Resolve the photo URL. RF-09.4: the carnet always uses the user's
     * Moodle profile picture. photo_path is reserved for a future upload
     * flow; until then it is silently ignored.
     */
    public static function photo_url(?object $carnet, int $userid, string $wwwroot): string {
        global $DB;
        $root = rtrim($wwwroot, '/');
        $u = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], 'picture', IGNORE_MISSING);
        if ($u && (int)$u->picture > 0) {
            $usercontext = \context_user::instance($userid);
            return $root . '/pluginfile.php/' . $usercontext->id . '/user/icon/boost/f1?rev=' . (int)$u->picture;
        }
        return '';
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private static function generate_token(): string {
        $alphabet = self::TOKEN_ALPHABET;
        $max = strlen($alphabet) - 1;
        $token = '';
        for ($i = 0; $i < 40; $i++) {
            $token .= $alphabet[random_int(0, $max)];
        }
        return $token;
    }

    /** Authoritative role id for "student" in local_learning_users. */
    private const STUDENT_USERROLEID = 5;

    /** Statuses considered "current" for the carnet. */
    private const ACTIVE_PLAN_STATUSES = ['activo', ''];

    private static function resolve_current_plan_name(int $userid): string {
        global $DB;
        $row = $DB->get_record_sql(
            "SELECT lp.name AS plan_name
               FROM {local_learning_users} llu
               JOIN {local_learning_plans} lp ON lp.id = llu.learningplanid
              WHERE llu.userid = :uid
                AND llu.userroleid = :role
                AND (llu.status = 'activo' OR llu.status = '' OR llu.status IS NULL)
           ORDER BY llu.timecreated DESC",
            ['uid' => $userid, 'role' => self::STUDENT_USERROLEID], IGNORE_MULTIPLE);
        return (string)($row->plan_name ?? '');
    }

    private static function resolve_admission_date(int $userid): int {
        global $DB;
        // F-30: gm.timeadded (when the student joined the group), not
        // g.timecreated (when the group was created).
        $row = $DB->get_record_sql(
            "SELECT MIN(gm.timeadded) AS first_seen
               FROM {groups_members} gm
              WHERE gm.userid = :uid",
            ['uid' => $userid], IGNORE_MULTIPLE);
        if ($row && !empty($row->first_seen)) {
            return (int)$row->first_seen;
        }
        $u = $DB->get_record('user', ['id' => $userid], 'timecreated', IGNORE_MISSING);
        return $u ? (int)$u->timecreated : 0;
    }
}