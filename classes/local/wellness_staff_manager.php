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
 * Editable staff roster for the wellness module (RF-03, RF-09.3).
 *
 * - Resolves the active Moodle user + email for a given rolekey
 *   (psicologo_titular, psicologo_suplente, talento_humano, bienestar_jefe,
 *   bienestar_asistente). Email_override lets the admin route notifications
 *   to a shared mailbox while still linking the row to a real user.
 * - Records every change to gmk_wellness_staff_audit so RR.HH. can see who
 *   replaced whom and when.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local;

defined('MOODLE_INTERNAL') || die();

class wellness_staff_manager {

    /** Catalogue of rolekeys the system understands. */
    public const SYSTEM_ROLES = [
        'psicologo_titular'   => 'Psicólogo/a — Titular',
        'psicologo_suplente'  => 'Psicólogo/a — Suplente',
        'talento_humano'      => 'Talento Humano',
        'bienestar_jefe'      => 'Bienestar Estudiantil (Jefe)',
        'bienestar_asistente' => 'Bienestar Estudiantil (Asistente)',
    ];

    /**
     * Return the row for a rolekey (UNIQUE), regardless of the active flag.
     * The history of changes is kept in gmk_wellness_staff_audit; this is
     * the raw row.
     */
    public static function get_role(string $rolekey): ?object {
        global $DB;
        $row = $DB->get_record('gmk_wellness_staff_role', ['rolekey' => $rolekey]);
        if (!$row) {
            return null;
        }
        return $row;
    }

    /**
     * Return the row for a rolekey ONLY when active=1. This is what the
     * mailer and the psychology default-specialist lookup use, so the
     * admin can disable a role without the booking flow falling back to
     * a "deleted from the roster" specialist.
     */
    public static function get_active(string $rolekey): ?object {
        $row = self::get_role($rolekey);
        if (!$row) {
            return null;
        }
        if ((int)$row->active !== 1) {
            return null;
        }
        return $row;
    }

    /**
     * Resolve the email address used by the wellness_mailer to reach this role.
     * Falls back to the linked user's email when no override is configured.
     * Returns null when the role is missing, disabled (active=0) or the
     * linked user is missing/suspended.
     *
     * @return string|null
     */
    public static function resolve_email(string $rolekey): ?string {
        $row = self::get_active($rolekey);
        if (!$row) {
            return null;
        }
        $override = trim((string)$row->email_override);
        if ($override !== '') {
            return $override;
        }
        if ((int)$row->userid <= 0) {
            return null;
        }
        global $DB;
        $u = $DB->get_record('user', ['id' => (int)$row->userid, 'deleted' => 0],
            'email, suspended', IGNORE_MISSING);
        if (!$u || (int)$u->suspended === 1) {
            return null;
        }
        return (string)$u->email !== '' ? (string)$u->email : null;
    }

    /**
     * Linked Moodle userid (0 when the role is unassigned or disabled).
     * Disabled roles are excluded so a deactivated psychologist is not
     * silently chosen as the default specialist.
     */
    public static function resolve_userid(string $rolekey): int {
        $row = self::get_active($rolekey);
        return $row ? (int)$row->userid : 0;
    }

    /**
     * List every role (active and inactive) joined with the linked user
     * for the admin Vue table.
     */
    public static function list_with_resolved(): array {
        global $DB;
        $rows = $DB->get_records_sql(
            "SELECT r.id, r.rolekey, r.role_label, r.userid, r.email_override,
                    r.notify_on_request, r.notify_on_change, r.active,
                    r.usermodified, r.timecreated, r.timemodified,
                    u.firstname, u.lastname, u.email AS user_email, u.suspended
               FROM {gmk_wellness_staff_role} r
          LEFT JOIN {user} u ON u.id = r.userid
           ORDER BY r.active DESC, r.rolekey ASC");
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'                => (int)$r->id,
                'rolekey'           => (string)$r->rolekey,
                'role_label'        => (string)$r->role_label,
                'userid'            => (int)$r->userid,
                'user_fullname'     => trim(((string)$r->firstname ?? '') . ' ' . ((string)$r->lastname ?? '')),
                'user_email'        => (string)($r->user_email ?? ''),
                'user_suspended'    => !empty($r->suspended),
                'email_override'    => (string)$r->email_override,
                'effective_email'   => self::effective_email($r),
                'notify_on_request' => (int)$r->notify_on_request,
                'notify_on_change'  => (int)$r->notify_on_change,
                'active'            => (int)$r->active,
                'usermodified'      => (int)$r->usermodified,
                'timecreated'       => (int)$r->timecreated,
                'timemodified'      => (int)$r->timemodified,
            ];
        }
        return $out;
    }

    private static function effective_email(object $r): string {
        $override = trim((string)$r->email_override);
        if ($override !== '') {
            return $override;
        }
        return (string)($r->user_email ?? '');
    }

    /**
     * Insert a new row, or update the existing one for the same rolekey
     * (always keeping one active row per rolekey, per the UNIQUE index).
     *
     * @return int row id.
     */
    public static function upsert(
        string $rolekey,
        string $roleLabel,
        int $userid,
        string $emailOverride,
        bool $notifyRequest,
        bool $notifyChange,
        int $authorid
    ): int {
        global $DB;

        $rolekey = trim($rolekey);
        if ($rolekey === '') {
            throw new \moodle_exception('wellness_staff_rolekey_required', 'local_grupomakro_core');
        }
        $roleLabel = mb_substr(trim($roleLabel), 0, 128);
        if ($roleLabel === '') {
            // Fall back to the system catalogue label so the row is never
            // visually empty in the back-office.
            $roleLabel = self::SYSTEM_ROLES[$rolekey] ?? $rolekey;
        }
        $emailOverride = mb_substr(trim($emailOverride), 0, 255);
        if ($emailOverride !== '' && !filter_var($emailOverride, FILTER_VALIDATE_EMAIL)) {
            throw new \moodle_exception('wellness_staff_email_invalid', 'local_grupomakro_core');
        }
        if ($userid > 0 && !$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            throw new \moodle_exception('wellness_staff_user_invalid', 'local_grupomakro_core');
        }
        // At least one of (linked userid, explicit email) must be set. Otherwise
        // the mailer has nothing to send to and the row is silently useless.
        if ($userid <= 0 && trim($emailOverride) === '') {
            throw new \moodle_exception('wellness_staff_no_target', 'local_grupomakro_core');
        }

        $now = time();
        // get_role() (not get_active()): when the admin re-assigns a role
        // that exists with active=0 (e.g. seeded by
        // seed_canonical_roles_if_empty() or left behind by set_active()),
        // we must UPDATE the existing row — re-activating via the UPDATE
        // branch — instead of INSERTing a duplicate rolekey that would
        // trip the UNIQUE(rolekey) constraint.
        $existing = self::get_role($rolekey);

        $record = (object)[
            'rolekey'           => $rolekey,
            'role_label'        => $roleLabel,
            'userid'            => $userid,
            'email_override'    => $emailOverride,
            'notify_on_request' => $notifyRequest ? 1 : 0,
            'notify_on_change'  => $notifyChange ? 1 : 0,
            'active'            => 1,
            'usermodified'      => $authorid,
            'timemodified'      => $now,
        ];

        $trans = null;
        try {
            $trans = $DB->start_delegated_transaction();

            if ($existing) {
                self::log_change($existing, $record, $authorid);
                $record->id = (int)$existing->id;
                $record->timecreated = (int)$existing->timecreated;
                $DB->update_record('gmk_wellness_staff_role', $record);
                $id = (int)$existing->id;
            } else {
                $record->timecreated = $now;
                $id = (int)$DB->insert_record('gmk_wellness_staff_role', $record);
            }
            $trans->allow_commit();
            return $id;
        } catch (\Throwable $e) {
            // F-12 pattern: rollback_delegated_transaction() re-throws,
            // so swallow the rethrow to honour the structured error contract
            // the WS expects. (N.B. upsert() currently has no caller that
            // catches the exception — it just propagates to the WS — but
            // we apply the same pattern as everywhere else for consistency.)
            if ($trans !== null) {
                try { $trans->rollback($e); } catch (\Throwable $ignored) {}
            }
            throw $e;
        }
    }

    /**
     * Soft-delete (active=0). Hard delete is intentionally not exposed.
     * With UNIQUE(rolekey) there is only one row per rolekey, so toggling
     * active=0 only disables notifications — the configuration stays put.
     */
    public static function set_active(int $id, int $active, int $authorid): bool {
        global $DB;
        $row = $DB->get_record('gmk_wellness_staff_role', ['id' => $id]);
        if (!$row) {
            return false;
        }
        $new = (object)[
            'id'           => (int)$row->id,
            'active'       => $active ? 1 : 0,
            'usermodified' => $authorid,
            'timemodified' => time(),
        ];
        $DB->update_record('gmk_wellness_staff_role', $new);
        return true;
    }

    /**
     * Append a row to gmk_wellness_staff_audit capturing who changed what.
     * Always called from inside a transaction.
     */
    public static function log_change(object $old, object $new, int $changedBy): void {
        global $DB;
        $oldEmail = trim((string)$old->email_override);
        $newEmail = trim((string)$new->email_override);
        if ((int)$old->userid === (int)$new->userid && $oldEmail === $newEmail
            && (int)$old->notify_on_request === (int)$new->notify_on_request
            && (int)$old->notify_on_change === (int)$new->notify_on_change) {
            // No-op audit; saves an unnecessary row when the admin re-submits
            // the form without changing anything.
            return;
        }
        $DB->insert_record('gmk_wellness_staff_audit', (object)[
            'rolekey'    => (string)$new->rolekey,
            'old_userid' => (int)$old->userid,
            'new_userid' => (int)$new->userid,
            'old_email'  => $oldEmail,
            'new_email'  => $newEmail,
            'changed_by' => $changedBy,
            'changed_at' => time(),
            'note'       => null,
        ]);
    }

    /**
     * Audit history for one rolekey, newest first.
     */
    public static function history(string $rolekey): array {
        global $DB;
        $sql = "SELECT a.id, a.rolekey, a.old_userid, a.new_userid, a.old_email, a.new_email,
                       a.changed_by, a.changed_at, a.note,
                       u.firstname, u.lastname,
                       old.firstname AS old_firstname, old.lastname AS old_lastname,
                       new.firstname AS new_firstname, new.lastname AS new_lastname
                  FROM {gmk_wellness_staff_audit} a
             LEFT JOIN {user} u   ON u.id   = a.changed_by
             LEFT JOIN {user} old ON old.id = a.old_userid
             LEFT JOIN {user} new ON new.id = a.new_userid
                 WHERE a.rolekey = :rk
              ORDER BY a.changed_at DESC, a.id DESC";
        $rows = $DB->get_records_sql($sql, ['rk' => $rolekey]);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'             => (int)$r->id,
                'rolekey'        => (string)$r->rolekey,
                'old_userid'     => (int)$r->old_userid,
                'new_userid'     => (int)$r->new_userid,
                'old_fullname'   => trim(((string)($r->old_firstname ?? '')) . ' ' . ((string)($r->old_lastname ?? ''))),
                'new_fullname'   => trim(((string)($r->new_firstname ?? '')) . ' ' . ((string)($r->new_lastname ?? ''))),
                'old_email'      => (string)$r->old_email,
                'new_email'      => (string)$r->new_email,
                'changed_by'     => (int)$r->changed_by,
                'changed_by_name'=> trim(((string)($r->firstname ?? '')) . ' ' . ((string)($r->lastname ?? ''))),
                'changed_at'     => (int)$r->changed_at,
                'note'           => (string)($r->note ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Ensure the 5 canonical rolekeys exist (active=0 if no assignment yet)
     * so the admin UI is never empty after a fresh install.
     */
    public static function seed_canonical_roles_if_empty(): int {
        global $DB;
        if ($DB->record_exists('gmk_wellness_staff_role', [])) {
            return 0;
        }
        $now = time();
        $created = 0;
        foreach (self::SYSTEM_ROLES as $rolekey => $label) {
            $DB->insert_record('gmk_wellness_staff_role', (object)[
                'rolekey'           => $rolekey,
                'role_label'        => $label,
                'userid'            => 0,
                'email_override'    => '',
                'notify_on_request' => 1,
                'notify_on_change'  => 1,
                'active'            => 0,
                'usermodified'      => 2,
                'timecreated'       => $now,
                'timemodified'      => $now,
            ]);
            $created++;
        }
        return $created;
    }
}