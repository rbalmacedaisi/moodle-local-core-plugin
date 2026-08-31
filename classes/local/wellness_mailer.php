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
 * Notification helper for the wellness module (RF-03.3, RF-03.4).
 *
 * Wraps Moodle's message_send() API and:
 *  - Resolves the destination user + email for a given rolekey via
 *    wellness_staff_manager.
 *  - Honours per-role notify_on_request / notify_on_change flags so the
 *    admin can mute Dulce for status-change notifications if needed.
 *  - Persists a `student_notified_at` / `staff_notified_at` timestamp on the
 *    appointment so the admin UI knows when the student/staff were last
 *    informed.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local;

defined('MOODLE_INTERNAL') || die();

class wellness_mailer {

    /** Per-appointment row, used to update student_notified_at / staff_notified_at. */
    public const TARGET_STUDENT = 'student';
    public const TARGET_STAFF   = 'staff';

    /**
     * Send a notification to the staff members currently bound to the given
     * rolekey (and, when relevant, to additional rolekeys — for instance a
     * new request notifies both talento_humano and bienestar_jefe).
     *
     * @param int   $appointmentId  Used to update staff_notified_at.
     * @param string $provider      Message provider: wellness_appointment_received | ..._staff_notification | ..._confirmed | ..._cancelled.
     * @param array $vars           Placeholders interpolated by the lang string.
     * @param int   $psychologistUserid  The specialist handling the appointment; included in CC for record-keeping.
     * @return bool true if at least one recipient got the message.
     */
    public static function send_to_staff(
        int $appointmentId,
        string $provider,
        array $vars,
        int $psychologistUserid = 0
    ): bool {
        $rolekeys = self::rolekeys_for_provider($provider, 'staff');
        if (empty($rolekeys)) {
            return false;
        }

        $anySent = false;
        $recipients = [];
        foreach ($rolekeys as $rk) {
            $row = wellness_staff_manager::get_active($rk);
            if (!$row) {
                continue;
            }
            if ((int)$row->active !== 1) {
                continue; // Mute notifications without deleting the row.
            }
            if ($provider === 'wellness_appointment_received' && !$row->notify_on_request) {
                continue;
            }
            if (in_array($provider, ['wellness_appointment_confirmed', 'wellness_appointment_cancelled'], true)
                && !$row->notify_on_change) {
                continue;
            }
            if ((int)$row->userid <= 0 && trim((string)$row->email_override) === '') {
                continue;
            }
            $recipients[] = $row;
        }

        // Best-effort: also include the psychologist as CC for the "new request"
        // notification, even if no gmk_wellness_staff_role row binds them to a
        // rolekey. They get it because they're the named specialist.
        if ($provider === 'wellness_appointment_received' && $psychologistUserid > 0) {
            $alreadyIn = false;
            foreach ($recipients as $r) {
                if ((int)$r->userid === $psychologistUserid) { $alreadyIn = true; break; }
            }
            if (!$alreadyIn) {
                global $DB;
                $psycho = $DB->get_record('user', ['id' => $psychologistUserid, 'deleted' => 0],
                    'id, suspended', IGNORE_MISSING);
                if ($psycho && (int)$psycho->suspended !== 1) {
                    $recipients[] = (object)['userid' => $psychologistUserid, 'email_override' => ''];
                }
            }
        }

        foreach ($recipients as $row) {
            $sent = self::dispatch((int)$row->userid, (string)$row->email_override, $provider, $vars);
            if ($sent) {
                $anySent = true;
            }
        }

        if ($anySent) {
            global $DB;
            $DB->set_field('gmk_wellness_psy_appts', 'staff_notified_at', time(),
                ['id' => $appointmentId]);
        }
        return $anySent;
    }

    /**
     * Send a notification to the student that booked the appointment.
     * Updates student_notified_at.
     */
    public static function send_to_student(
        int $appointmentId,
        int $studentUserid,
        string $provider,
        array $vars
    ): bool {
        global $DB;
        $student = $DB->get_record('user', ['id' => $studentUserid, 'deleted' => 0],
            'id, suspended', IGNORE_MISSING);
        if (!$student || (int)$student->suspended === 1) {
            return false;
        }
        $ok = self::dispatch($studentUserid, '', $provider, $vars);
        if ($ok) {
            $DB->set_field('gmk_wellness_psy_appts', 'student_notified_at', time(),
                ['id' => $appointmentId]);
        }
        return $ok;
    }

    /**
     * Resolve the rolekey(s) that should receive the message for a given
     * provider + target audience.
     *
     * @param string $target  self::TARGET_STUDENT | self::TARGET_STAFF
     * @return string[]
     */
    public static function rolekeys_for_provider(string $provider, string $target): array {
        if ($target !== self::TARGET_STAFF) {
            return [];
        }
        switch ($provider) {
            case 'wellness_appointment_received':
                // New request: notify Talento Humano AND Bienestar Estudiantil.
                return ['talento_humano', 'bienestar_jefe'];
            case 'wellness_appointment_staff_notification':
                return ['talento_humano', 'bienestar_jefe'];
            case 'wellness_appointment_confirmed':
            case 'wellness_appointment_cancelled':
                return ['talento_humano'];
            default:
                return [];
        }
    }

    /**
     * Core dispatch: builds a Moodle message and hands it to message_send.
     * Subject + body come from lang strings (msg:<provider>:subject / :body)
     * so the admin can edit them via the standard Moodle message UI.
     *
     * @param int $userid      Moodle user id that will receive the message.
     * @param string $emailOverride When non-empty, replaces user.email for this send only.
     *                            Used for staff_role rows with userid=0 (no Moodle login
     *                            yet, but we still need to notify a shared mailbox).
     * @return bool true on confirmed delivery.
     */
    public static function dispatch(int $userid, string $emailOverride, string $provider, array $vars): bool {
        global $CFG;
        require_once($CFG->dirroot . '/message/lib.php');

        $subject = get_string('msg:' . $provider . ':subject', 'local_grupomakro_core', $vars);
        $body    = get_string('msg:' . $provider . ':body', 'local_grupomakro_core', $vars);

        $message = new \core\message\message();
        $message->component = 'local_grupomakro_core';
        $message->name      = $provider;
        $message->userfrom  = \core_user::get_noreply_user();
        $message->subject   = $subject;
        $message->fullmessage       = strip_tags($body);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml   = $body;
        $message->smallmessage      = $subject;
        $message->notification       = 1;

        // Branch A: the recipient is a real Moodle user.
        if ($userid > 0) {
            $message->userto = $userid;
            try {
                return (bool)message_send($message);
            } catch (\Throwable $e) {
                return false;
            }
        }

        // Branch B: email-only destination (shared mailbox / role with userid=0).
        if (trim($emailOverride) === '') {
            return false;
        }
        // F-06: email_to_user()'s guard is `empty($user->id)` — falsy when
        // id is 0/null/''. The core itself uses -1 as the conventional
        // "synthetic recipient" id (see core_user::get_user(); when no row
        // exists). We populate the other fields email_to_user() touches
        // so it does not blow up further down the call stack.
        global $CFG;
        $stub = new \stdClass();
        $stub->id           = -1;
        $stub->email        = trim($emailOverride);
        $stub->firstname    = 'Bienestar';
        $stub->lastname     = 'ISI';
        $stub->maildisplay  = 1;
        $stub->auth         = 'manual';
        $stub->deleted      = 0;
        $stub->suspended     = 0;
        $stub->mailformat   = 1;
        $stub->mnethostid    = (int)$CFG->mnet_localhost_id;
        try {
            return (bool)email_to_user(
                $stub,
                \core_user::get_noreply_user(),
                $subject,
                strip_tags($body),
                $body
            );
        } catch (\Throwable $e) {
            return false;
        }
    }
}