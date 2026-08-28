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
 * Psychology appointments + slot management (RF-03, RF-09.3).
 *
 * Public read:
 *   - get_open_slots($psychologistUserid, $from, $to): produce a concrete
 *     list of available slot occurrences within the date range, excluding
 *     already-booked appointments.
 *
 * Mutations:
 *   - request_appointment(): book a specific slot occurrence; fires the
 *     wellness_mailer notifications to staff and student.
 *   - admin_update_status(): transition a row to one of the allowed states
 *     with the matching email side-effects.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local;

defined('MOODLE_INTERNAL') || die();

class wellness_psychology_manager {

    /** Allowed status transitions enforced by admin_update_status. */
    private const ALLOWED_STATUSES = [
        'pendiente', 'confirmada', 'modificada', 'cancelada', 'atendida', 'no_asistio',
    ];

    /** Statuses that count as "open" for the slot occupancy check. */
    private const ACTIVE_STATUSES = ['pendiente', 'confirmada', 'modificada'];

    /**
     * List the recurring slots the admin has published.
     */
    public static function list_slots(bool $activeOnly = true): array {
        global $DB;
        $where = $activeOnly ? ['active' => 1] : [];
        $rows = $DB->get_records('gmk_wellness_psy_slot', $where,
            'psychologist_userid, weekday, starttime');
        return array_values(array_map(function ($r) {
            $r->id                  = (int)$r->id;
            $r->psychologist_userid = (int)$r->psychologist_userid;
            $r->weekday             = (int)$r->weekday;
            $r->duration_minutes    = (int)$r->duration_minutes;
            $r->active              = (int)$r->active;
            $r->valid_from          = (int)$r->valid_from;
            $r->valid_until         = (int)$r->valid_until;
            return $r;
        }, $rows));
    }

    /**
     * Upsert a recurring slot. id=0 inserts; id>0 updates.
     */
    public static function upsert_slot(array $payload, int $authorid): int {
        global $DB;

        $psychologist = (int)($payload['psychologist_userid'] ?? 0);
        if ($psychologist <= 0
            || !$DB->record_exists('user', ['id' => $psychologist, 'deleted' => 0, 'suspended' => 0])) {
            throw new \moodle_exception('wellness_psychologist_required', 'local_grupomakro_core');
        }
        $weekday = (int)($payload['weekday'] ?? 1);
        if ($weekday < 0 || $weekday > 6) {
            throw new \moodle_exception('wellness_weekday_invalid', 'local_grupomakro_core');
        }
        $start = self::normalize_hhmm((string)($payload['starttime'] ?? ''));
        $end   = self::normalize_hhmm((string)($payload['endtime'] ?? ''));
        if ($start === null || $end === null) {
            throw new \moodle_exception('wellness_time_required', 'local_grupomakro_core');
        }
        if (strcmp($start, $end) >= 0) {
            throw new \moodle_exception('wellness_end_before_start', 'local_grupomakro_core');
        }

        $now = time();
        $record = (object)[
            'psychologist_userid' => $psychologist,
            'weekday'             => $weekday,
            'starttime'           => $start,
            'endtime'             => $end,
            'modality'            => self::normalise_modality($payload['modality'] ?? 'presencial'),
            'duration_minutes'    => max(15, min(240, (int)($payload['duration_minutes'] ?? 45))),
            'location'            => mb_substr((string)($payload['location'] ?? ''), 0, 255),
            'active'              => !empty($payload['active']) ? 1 : 0,
            'valid_from'          => (int)($payload['valid_from'] ?? 0),
            'valid_until'         => (int)($payload['valid_until'] ?? 0),
            'usermodified'        => $authorid,
            'timemodified'        => $now,
        ];
        $id = (int)($payload['id'] ?? 0);
        if ($id > 0) {
            if (!$DB->record_exists('gmk_wellness_psy_slot', ['id' => $id])) {
                throw new \moodle_exception('wellness_slot_not_found', 'local_grupomakro_core');
            }
            $record->id = $id;
            $record->timecreated = (int)$DB->get_field('gmk_wellness_psy_slot', 'timecreated', ['id' => $id]);
            $DB->update_record('gmk_wellness_psy_slot', $record);
            return $id;
        }
        $record->timecreated = $now;
        return (int)$DB->insert_record('gmk_wellness_psy_slot', $record);
    }

    public static function set_slot_active(int $id, int $active): bool {
        global $DB;
        if (!$DB->record_exists('gmk_wellness_psy_slot', ['id' => $id])) {
            return false;
        }
        $DB->set_field('gmk_wellness_psy_slot', 'active', $active ? 1 : 0, ['id' => $id]);
        $DB->set_field('gmk_wellness_psy_slot', 'timemodified', time(), ['id' => $id]);
        return true;
    }

    /**
     * Resolve which specialist the student will be assigned to.
     * Strategy: pick the activo rolekey psicologo_titular if any;
     * otherwise fall back to the first active psicologo_suplente.
     */
    public static function default_psychologist_userid(): int {
        $titular = wellness_staff_manager::resolve_userid('psicologo_titular');
        if ($titular > 0) {
            return $titular;
        }
        return wellness_staff_manager::resolve_userid('psicologo_suplente');
    }

    /**
     * Materialise slot occurrences between $from and $to and return those
     * that are not yet booked (or whose existing booking is cancelled).
     *
     * Each element: [
     *   'slotid' => int,
     *   'psychologist_userid' => int,
     *   'weekday' => int,
     *   'starttime' => 'HH:MM',
     *   'endtime' => 'HH:MM',
     *   'modality' => string,
     *   'duration_minutes' => int,
     *   'location' => string,
     *   'occurrence_start' => unix ts,
     *   'occurrence_end' => unix ts,
     * ]
     */
    public static function get_open_slots(int $psychologistUserid, int $from, int $to, int $now = 0): array {
        global $DB;
        $now = $now ?: time();
        $from = max($from, $now);
        $slots = $DB->get_records('gmk_wellness_psy_slot',
            ['psychologist_userid' => $psychologistUserid, 'active' => 1],
            'weekday, starttime');

        if (empty($slots)) {
            return [];
        }

        $slotIds = array_keys($slots);
        [$insql, $inparams] = $DB->get_in_or_equal($slotIds, SQL_PARAMS_NAMED, 'sid');
        // F-07: include id as the first column so get_records_sql indexes
        // each row by id (not by slotid, which had duplicates that
        // collapsed multiple booked occurrences into one).
        $busy = $DB->get_records_sql(
            "SELECT id, slotid, appointment_at
               FROM {gmk_wellness_psy_appts}
              WHERE slotid $insql
                AND status IN ('pendiente', 'confirmada', 'modificada')",
            $inparams
        );

        // Build a lookup: slotid+appointment_at (HH:MM exact) => busy?
        $busyKey = [];
        foreach ($busy as $b) {
            if (empty($b->slotid)) {
                continue;
            }
            $busyKey[((int)$b->slotid) . '@' . ((int)$b->appointment_at)] = true;
        }

        $out = [];
        $cursor = $from;
        // Iterate day by day until $to.
        for ($day = $cursor; $day <= $to; $day = strtotime('+1 day', $day)) {
            $ymd = date('Y-m-d', $day);
            $weekday = (int)date('w', $day); // 0=Sun ... 6=Sat (PHP w == ISO-7)
            foreach ($slots as $s) {
                if ((int)$s->weekday !== $weekday) {
                    continue;
                }
                if ((int)$s->valid_from > 0 && $day < (int)$s->valid_from) {
                    continue;
                }
                if ((int)$s->valid_until > 0 && $day > (int)$s->valid_until) {
                    continue;
                }
                $startTs = strtotime($ymd . ' ' . $s->starttime . ':00');
                $endTs   = strtotime($ymd . ' ' . $s->endtime . ':00');
                if ($startTs === false || $endTs === false) {
                    continue;
                }
                if ($endTs <= $startTs) {
                    continue;
                }
                if ($startTs < $now) {
                    continue;
                }
                $key = ((int)$s->id) . '@' . $startTs;
                if (isset($busyKey[$key])) {
                    continue;
                }
                $out[] = [
                    'slotid'              => (int)$s->id,
                    'psychologist_userid' => (int)$s->psychologist_userid,
                    'weekday'             => $weekday,
                    'starttime'           => (string)$s->starttime,
                    'endtime'             => (string)$s->endtime,
                    'modality'            => (string)$s->modality,
                    'duration_minutes'    => (int)$s->duration_minutes,
                    'location'            => (string)$s->location,
                    'occurrence_start'    => $startTs,
                    'occurrence_end'      => $endTs,
                ];
            }
        }
        return $out;
    }

    /**
     * Book one concrete slot occurrence for the calling student.
     *
     * @return array{ok:bool, error?:string, appointment?:object}
     */
    public static function request_appointment(
        int $slotid,
        int $occurrenceStart,
        string $reason,
        string $modalityOverride = ''
    ): array {
        global $DB, $USER;

        $studentid = (int)$USER->id;
        if ($studentid <= 0
            || !$DB->record_exists('user', ['id' => $studentid, 'deleted' => 0, 'suspended' => 0])) {
            return ['ok' => false, 'error' => 'user_invalid'];
        }

        $slot = $DB->get_record('gmk_wellness_psy_slot', ['id' => $slotid]);
        if (!$slot || (int)$slot->active !== 1) {
            return ['ok' => false, 'error' => 'slot_inactive'];
        }
        if ((int)$slot->valid_from > 0 && $occurrenceStart < (int)$slot->valid_from) {
            return ['ok' => false, 'error' => 'slot_not_yet_valid'];
        }
        if ((int)$slot->valid_until > 0 && $occurrenceStart > (int)$slot->valid_until) {
            return ['ok' => false, 'error' => 'slot_expired'];
        }
        if ($occurrenceStart <= time()) {
            return ['ok' => false, 'error' => 'slot_in_past'];
        }
        // Reject duplicate bookings on the same occurrence, even from the
        // same student. The combination slotid+appointment_at is the natural
        // identity; we check it inside the transaction.
        $now = time();
        $reason = trim($reason);
        if ($reason === '') {
            return ['ok' => false, 'error' => 'reason_required'];
        }

        $modality = $modalityOverride !== '' ? $modalityOverride : (string)$slot->modality;
        $modality = self::normalise_modality($modality);

        // F-08: take a per-occurrence lock so the SELECT+INSERT sequence
        // is race-free under concurrent requests for the same slot
        // occurrence. The key includes appointment_at so a different
        // day with the same slotid does not block.
        $lockfactory = \core\lock\lock_config::get_lock_factory('local_grupomakro_core_wellness');
        $lockkey = 'wellness_psy_slot_' . $slotid . '_' . $occurrenceStart;
        $lock = $lockfactory->get_lock($lockkey, 10);
        if (!$lock) {
            return ['ok' => false, 'error' => 'busy_retry'];
        }

        $trans = null;
        try {
            $trans = $DB->start_delegated_transaction();

            $existing = $DB->get_record_sql(
                "SELECT id FROM {gmk_wellness_psy_appts}
                  WHERE slotid = ? AND appointment_at = ?
                    AND status IN ('pendiente', 'confirmada', 'modificada')
                  LIMIT 1",
                [$slotid, $occurrenceStart]
            );
            if ($existing) {
                $trans->allow_commit();
                return ['ok' => false, 'error' => 'slot_taken'];
            }

            // F-20: include appointment_at in the dedup check so the
            // student can request a *future* occurrence of the same slot
            // without colliding with a previous one.
            $existingStudent = $DB->get_record('gmk_wellness_psy_appts',
                ['slotid' => $slotid, 'userid' => $studentid,
                 'appointment_at' => $occurrenceStart, 'status' => 'pendiente']);
            if ($existingStudent) {
                $trans->allow_commit();
                return ['ok' => true, 'appointment' => $existingStudent, 'duplicate' => true];
            }

            $row = (object)[
                'userid'              => $studentid,
                'slotid'              => $slotid,
                'psychologist_userid' => (int)$slot->psychologist_userid,
                'appointment_at'      => $occurrenceStart,
                'duration_minutes'    => (int)$slot->duration_minutes,
                'modality'            => $modality,
                'reason'              => $reason,
                'status'              => 'pendiente',
                'timecreated'         => $now,
                'timemodified'        => $now,
            ];
            $row->id = (int)$DB->insert_record('gmk_wellness_psy_appts', $row);

            $trans->allow_commit();
        } catch (\Throwable $e) {
            // F-12: rollback_delegated_transaction() re-throws. Swallow it
            // so the WS gets the structured error contract.
            if ($trans !== null) {
                try { $trans->rollback($e); } catch (\Throwable $ignored) {}
            }
            return ['ok' => false, 'error' => 'db_error'];
        } finally {
            $lock->release();
        }

        // Fire notifications (outside the txn so a mail failure doesn't
        // undo the booking). Failures are silent â€” the appointment row is
        // the source of truth.
        $vars = [
            'studentname' => fullname($USER),
            'reason'      => $reason,
            'date'        => userdate($occurrenceStart, get_string('strftimedatefullshort')),
            'time'        => userdate($occurrenceStart, get_string('strftimetime')),
            'duration'    => (int)$slot->duration_minutes,
            'modality'    => get_string('wellness:lxp:modality_' . $modality, 'local_grupomakro_core'),
        ];
        wellness_mailer::send_to_staff($row->id, 'wellness_appointment_received', $vars, (int)$slot->psychologist_userid);
        wellness_mailer::send_to_student($row->id, $studentid, 'wellness_appointment_received', $vars);

        return ['ok' => true, 'appointment' => $row];
    }

    /**
     * Student's own appointments, newest first.
     */
    public static function list_for_student(int $userid): array {
        global $DB;
        $sql = "SELECT a.id, a.userid, a.slotid, a.psychologist_userid, a.appointment_at,
                       a.duration_minutes, a.modality, a.reason, a.status,
                       a.status_changed_at, a.attendees_notes, a.cancel_reason,
                       a.timecreated,
                       u.firstname AS psycho_firstname,
                       u.lastname  AS psycho_lastname
                  FROM {gmk_wellness_psy_appts} a
             LEFT JOIN {user} u ON u.id = a.psychologist_userid
                 WHERE a.userid = :uid
              ORDER BY a.appointment_at DESC";
        $rows = $DB->get_records_sql($sql, ['uid' => $userid]);
        return array_values(array_map(function ($r) {
            $r->id                  = (int)$r->id;
            $r->userid              = (int)$r->userid;
            $r->slotid              = (int)$r->slotid;
            $r->psychologist_userid = (int)$r->psychologist_userid;
            $r->appointment_at      = (int)$r->appointment_at;
            $r->duration_minutes    = (int)$r->duration_minutes;
            $r->status              = (string)$r->status;
            $r->status_changed_at  = (int)$r->status_changed_at;
            $r->timecreated         = (int)$r->timecreated;
            // F-32: psycho_* columns (LEFT JOIN) with fallback to #id when
            // the specialist user was deleted.
            $r->psychologist_name   = trim(((string)($r->psycho_firstname ?? ''))
                                       . ' ' . ((string)($r->psycho_lastname ?? '')));
            if ($r->psychologist_name === '') {
                $r->psychologist_name = '#' . (int)$r->psychologist_userid;
            }
            return $r;
        }, $rows));
    }

    /**
     * Admin agenda view.
     *
     * @param int $psychologistUserid 0 = every psychologist.
     */
    public static function list_admin(?int $psychologistUserid, ?string $status, int $from, int $to): array {
        global $DB;
        $where = '1=1';
        $params = [];
        if ($psychologistUserid && $psychologistUserid > 0) {
            $where .= ' AND a.psychologist_userid = :pid';
            $params['pid'] = $psychologistUserid;
        }
        if ($status && in_array($status, self::ALLOWED_STATUSES, true)) {
            $where .= ' AND a.status = :status';
            $params['status'] = $status;
        }
        if ($from > 0) {
            $where .= ' AND a.appointment_at >= :from';
            $params['from'] = $from;
        }
        if ($to > 0) {
            $where .= ' AND a.appointment_at <= :to';
            $params['to'] = $to;
        }
        // F-32: LEFT JOIN so the row stays visible if the specialist was deleted
// or the appointment has psychologist_userid = 0.
        $sql = "SELECT a.*,
                       u.firstname AS psycho_firstname,
                       u.lastname  AS psycho_lastname,
                       u.email     AS psycho_email,
                       stu.firstname AS student_firstname,
                       stu.lastname  AS student_lastname,
                       stu.email     AS student_email
                  FROM {gmk_wellness_psy_appts} a
             LEFT JOIN {user} u  ON u.id  = a.psychologist_userid
             LEFT JOIN {user} stu ON stu.id = a.userid
                 WHERE $where
              ORDER BY a.appointment_at ASC";
        $rows = $DB->get_records_sql($sql, $params);
        return array_values(array_map(function ($r) {
            $r->id                  = (int)$r->id;
            $r->userid              = (int)$r->userid;
            $r->slotid              = (int)$r->slotid;
            $r->psychologist_userid = (int)$r->psychologist_userid;
            $r->appointment_at      = (int)$r->appointment_at;
            $r->duration_minutes    = (int)$r->duration_minutes;
            $r->status_changed_at  = (int)$r->status_changed_at;
            $r->status_changed_by  = (int)$r->status_changed_by;
            $r->student_notified_at = (int)$r->student_notified_at;
            $r->staff_notified_at   = (int)$r->staff_notified_at;
            $r->cancelled_by        = (int)$r->cancelled_by;
            $r->timecreated         = (int)$r->timecreated;
            $r->timemodified        = (int)$r->timemodified;
            // F-32: psych info is now from psycho_firstname/psycho_lastname
            // (LEFT JOIN); fall back to "id #N" when the user was deleted.
            $r->psychologist_name   = trim(((string)$r->psycho_firstname ?? '')
                                       . ' ' . ((string)$r->psycho_lastname ?? ''));
            if ($r->psychologist_name === '') {
                $r->psychologist_name = '#' . (int)$r->psychologist_userid;
            }
            return $r;
        }, $rows));
    }

    /**
     * Apply a status transition from the admin panel.
     * Side effects: notifies the student when relevant.
     */
    public static function admin_update_status(
        int $appointmentid,
        string $newStatus,
        int $authorid,
        string $cancelReason = '',
        string $attendeesNotes = '',
        int $newOccurrenceStart = 0
    ): array {
        global $DB;
        $row = $DB->get_record('gmk_wellness_psy_appts', ['id' => $appointmentid]);
        if (!$row) {
            return ['ok' => false, 'error' => 'appointment_not_found'];
        }
        if (!in_array($newStatus, self::ALLOWED_STATUSES, true)) {
            return ['ok' => false, 'error' => 'invalid_status'];
        }
        $oldStatus = (string)$row->status;
        if ($oldStatus === $newStatus && empty($cancelReason) && empty($attendeesNotes) && empty($newOccurrenceStart)) {
            return ['ok' => true, 'noop' => true];
        }

        $now = time();
        $update = (object)[
            'id'                => (int)$row->id,
            'status'            => $newStatus,
            'status_changed_at' => $now,
            'status_changed_by' => $authorid,
            'timemodified'      => $now,
        ];
        if ($cancelReason !== '') {
            $update->cancel_reason = $cancelReason;
        }
        if ($attendeesNotes !== '') {
            $update->attendees_notes = $attendeesNotes;
        }
        if ($newOccurrenceStart > 0 && $newStatus === 'modificada') {
            $update->appointment_at = $newOccurrenceStart;
            $update->slotid = 0; // Reschedule: detached from the original slot recurrence.
        }
        if ($newStatus === 'cancelada') {
            $update->cancelled_by = $authorid;
            $update->cancelled_at = $now;
        }
        $DB->update_record('gmk_wellness_psy_appts', $update);

        // Notify the student only when the status changed (or when the
        // occurrence was modified), to avoid duplicate emails on idempotent
        // re-saves.
        $studentUserid = (int)$row->userid;
        $psychologistUserid = (int)$row->psychologist_userid;
        $appointment_at = (int)$update->appointment_at;

        $vars = [
            'studentname'  => self::student_fullname($studentUserid),
            'reason'       => (string)$row->reason,
            'date'         => userdate($appointment_at, get_string('strftimedatefullshort')),
            'time'         => userdate($appointment_at, get_string('strftimetime')),
            'duration'     => (int)$row->duration_minutes,
            'modality'     => get_string('wellness:lxp:modality_' . $row->modality, 'local_grupomakro_core'),
            'oldstatus'    => get_string('wellness:lxp:status_' . $oldStatus, 'local_grupomakro_core'),
            'newstatus'    => get_string('wellness:lxp:status_' . $newStatus, 'local_grupomakro_core'),
            'cancelreason' => $cancelReason,
        ];

        switch ($newStatus) {
            case 'confirmada':
            case 'modificada':
                wellness_mailer::send_to_student($appointmentid, $studentUserid,
                    'wellness_appointment_confirmed', $vars);
                break;
            case 'cancelada':
                wellness_mailer::send_to_student($appointmentid, $studentUserid,
                    'wellness_appointment_cancelled', $vars);
                wellness_mailer::send_to_staff($appointmentid,
                    'wellness_appointment_cancelled', $vars, $psychologistUserid);
                break;
            case 'atendida':
            case 'no_asistio':
                // No student email (closes the cycle), but record the change.
                break;
        }
        return ['ok' => true];
    }

    /**
     * Student cancels their own appointment before staff confirms.
     */
    public static function student_cancel(int $appointmentid, int $userid, string $reason = ''): array {
        global $DB;
        $row = $DB->get_record('gmk_wellness_psy_appts', ['id' => $appointmentid]);
        if (!$row || (int)$row->userid !== $userid) {
            return ['ok' => false, 'error' => 'not_owner'];
        }
        if (!in_array($row->status, ['pendiente', 'confirmada'], true)) {
            return ['ok' => false, 'error' => 'not_cancellable'];
        }
        $now = time();
        $DB->update_record('gmk_wellness_psy_appts', (object)[
            'id'                => (int)$row->id,
            'status'            => 'cancelada',
            'cancelled_by'      => $userid,
            'cancel_reason'     => $reason !== '' ? $reason : 'Cancelada por el estudiante',
            'status_changed_at' => $now,
            'status_changed_by' => $userid,
            'timemodified'      => $now,
        ]);
        // Staff gets a courtesy ping (so they don't keep looking for the student).
        $vars = [
            'studentname'  => self::student_fullname($userid),
            'reason'       => (string)$row->reason,
            'date'         => userdate((int)$row->appointment_at, get_string('strftimedatefullshort')),
            'time'         => userdate((int)$row->appointment_at, get_string('strftimetime')),
            'duration'     => (int)$row->duration_minutes,
            'modality'     => get_string('wellness:lxp:modality_' . $row->modality, 'local_grupomakro_core'),
            'oldstatus'    => get_string('wellness:lxp:status_pendiente', 'local_grupomakro_core'),
            'newstatus'    => get_string('wellness:lxp:status_cancelada', 'local_grupomakro_core'),
            'cancelreason' => $reason !== '' ? $reason : 'Cancelada por el estudiante',
        ];
        wellness_mailer::send_to_staff($appointmentid, 'wellness_appointment_cancelled', $vars,
            (int)$row->psychologist_userid);
        return ['ok' => true];
    }

    private static function normalise_modality(string $raw): string {
        $m = strtolower(trim($raw));
        if (!in_array($m, ['presencial', 'virtual', 'mixto'], true)) {
            return 'presencial';
        }
        return $m;
    }

    private static function normalize_hhmm(string $raw): ?string {
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', trim($raw), $m)) {
            return null;
        }
        return $m[1] . ':' . $m[2];
    }

    private static function student_fullname(int $userid): string {
        global $DB;
        $u = $DB->get_record('user', ['id' => $userid], 'firstname, lastname', IGNORE_MISSING);
        if (!$u) {
            return '';
        }
        return trim(((string)$u->firstname) . ' ' . ((string)$u->lastname));
    }
}