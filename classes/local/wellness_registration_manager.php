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
 * Wellness event registrations: handles concurrent inserts, capacity checks,
 * cancellation flow and the CSV export of registered users.
 *
 * Concurrency strategy:
 *  - We acquire a MUC lock keyed on `wellness_event_capacity_{id}` for the
 *    duration of the registration transaction. The same key is acquired for
 *    cancellations when status flips away from 'confirmada' so the waitlist
 *    promotion logic doesn't double-promote.
 *  - All writes happen inside a delegated transaction.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local;

defined('MOODLE_INTERNAL') || die();

class wellness_registration_manager {

    /**
     * Seconds we'll wait to acquire the lock before giving up with
     * busy_retry. This is NOT a lock lifetime — the actual lifetime is
     * managed by the lock factory and survives until release() in finally.
     */
    private const LOCK_WAIT_SECONDS = 10;

    /**
     * Register a student to an event.
     *
     * Behaviour:
     *  - If the student is already registered in status confirm/lista_de_espera/atendio, returns the existing row.
     *  - Otherwise tries to insert with status 'confirmada'.
     *  - If capacity is full and waitlist is enabled, inserts with status 'lista_de_espera'.
     *  - If capacity is full and waitlist is disabled, returns error 'capacity_full'.
     *
     * @return array{ok:bool, status:string, registrationid:int, error?:string}
     */
    public static function register(int $eventid, int $userid, string $modality = '', string $source = 'lxp', int $registeredby = 0): array {
        global $DB;

        $event = $DB->get_record('gmk_wellness_event', ['id' => $eventid]);
        if (!$event) {
            return ['ok' => false, 'status' => '', 'registrationid' => 0, 'error' => 'event_not_found'];
        }
        if ((int)$event->active !== 1) {
            return ['ok' => false, 'status' => '', 'registrationid' => 0, 'error' => 'event_inactive'];
        }
        if (!wellness_event_manager::is_registration_open($event)) {
            return ['ok' => false, 'status' => '', 'registrationid' => 0, 'error' => 'registration_closed'];
        }
        if (!$DB->record_exists('user', ['id' => $userid, 'deleted' => 0, 'suspended' => 0])) {
            return ['ok' => false, 'status' => '', 'registrationid' => 0, 'error' => 'user_invalid'];
        }

        if ($modality === '' && in_array($event->modality, ['presencial', 'virtual', 'mixto'], true)) {
            $modality = $event->modality === 'mixto' ? 'presencial' : $event->modality;
        }

$lockfactory = \core\lock\lock_config::get_lock_factory('local_grupomakro_core_wellness');
        $lock = $lockfactory->get_lock('wellness_event_capacity_' . $eventid, self::LOCK_WAIT_SECONDS);
        if (!$lock) {
            return ['ok' => false, 'status' => '', 'registrationid' => 0, 'error' => 'busy_retry'];
        }

$trans = null;
        try {
            $trans = $DB->start_delegated_transaction();

            // Existing registration for this (event, user)?
            $existing = $DB->get_record('gmk_wellness_registration',
                ['eventid' => $eventid, 'userid' => $userid]);
            if ($existing && in_array($existing->status, ['confirmada', 'lista_de_espera', 'asistio'], true)) {
                $trans->allow_commit();
                return [
                    'ok' => true,
                    'status' => (string)$existing->status,
                    'registrationid' => (int)$existing->id,
                ];
            }

            // Capacity check
            $confirmed = (int)$DB->count_records('gmk_wellness_registration',
                ['eventid' => $eventid, 'status' => 'confirmada']);

            $status = 'confirmada';
            $error = null;
            if ((int)$event->capacity > 0 && $confirmed >= (int)$event->capacity) {
                if ((int)$event->allow_waitlist === 1) {
                    $status = 'lista_de_espera';
                } else {
                    $error = 'capacity_full';
                }
            }

            $now = time();
            if ($error) {
                $trans->allow_commit();
                return ['ok' => false, 'status' => '', 'registrationid' => 0, 'error' => $error];
            }

            if ($existing) {
                $row = (object)[
                    'id'             => (int)$existing->id,
                    'eventid'        => $eventid,
                    'userid'         => $userid,
                    'status'         => $status,
                    'modality'       => $modality,
                    'registered_at'  => $now,
                    'attended_at'    => 0,
                    'cancelled_at'   => 0,
                    'source'         => $source,
                    'registered_by'  => $registeredby,
                    'notes'          => $existing->notes ?? null,
                ];
                $DB->update_record('gmk_wellness_registration', $row);
                $regid = (int)$existing->id;
            } else {
                $regid = (int)$DB->insert_record('gmk_wellness_registration', (object)[
                    'eventid'        => $eventid,
                    'userid'         => $userid,
                    'status'         => $status,
                    'modality'       => $modality,
                    'registered_at'  => $now,
                    'attended_at'    => 0,
                    'cancelled_at'   => 0,
                    'source'         => $source,
                    'registered_by'  => $registeredby,
                    'notes'          => null,
                ]);
            }

            $trans->allow_commit();
            return ['ok' => true, 'status' => $status, 'registrationid' => $regid];
        } catch (\Throwable $e) {
            // rollback_delegated_transaction() re-throws, so we swallow it
            // here and return the structured error contract the WS expects.
            if ($trans !== null) {
                try { $trans->rollback($e); } catch (\Throwable $ignored) {}
            }
            return ['ok' => false, 'status' => '', 'registrationid' => 0, 'error' => 'db_error'];
        } finally {
            $lock->release();
        }
    }

    /**
     * Cancel a registration: marks the row as 'cancelada' and, if there is
     * a waitlist, promotes the head of the waitlist into 'confirmada'.
     * The same student can re-register afterwards.
     */
public static function cancel(int $eventid, int $userid, bool $byuser = true): array {
        global $DB;

        $lockfactory = \core\lock\lock_config::get_lock_factory('local_grupomakro_core_wellness');
        $lock = $lockfactory->get_lock('wellness_event_capacity_' . $eventid, self::LOCK_WAIT_SECONDS);
        if (!$lock) {
            return ['ok' => false, 'error' => 'busy_retry'];
        }

        $trans = null;
        try {
            $trans = $DB->start_delegated_transaction();

            // Re-read the row UNDER the lock to avoid two cancellations
            // promoting two different waitlist heads (F-10).
            $row = $DB->get_record('gmk_wellness_registration',
                ['eventid' => $eventid, 'userid' => $userid]);
            if (!$row) {
                $trans->allow_commit();
                return ['ok' => false, 'error' => 'not_registered'];
            }
            if ($row->status === 'cancelada') {
                $trans->allow_commit();
                return ['ok' => true, 'already' => true];
            }

            $DB->set_field('gmk_wellness_registration', 'status', 'cancelada',
                ['id' => $row->id]);
            $DB->set_field('gmk_wellness_registration', 'cancelled_at', time(),
                ['id' => $row->id]);

            // Promote head of waitlist (if any).
            $head = $DB->get_record_sql(
                "SELECT id, userid FROM {gmk_wellness_registration}
                  WHERE eventid = :eid AND status = 'lista_de_espera'
                  ORDER BY registered_at ASC, id ASC",
                ['eid' => $eventid], IGNORE_MULTIPLE
            );
            if ($head) {
                $DB->set_field('gmk_wellness_registration', 'status', 'confirmada',
                    ['id' => $head->id]);
            }

            $trans->allow_commit();
            return ['ok' => true];
        } catch (\Throwable $e) {
            if ($trans !== null) {
                try { $trans->rollback($e); } catch (\Throwable $ignored) {}
            }
            return ['ok' => false, 'error' => 'db_error'];
        } finally {
            $lock->release();
        }
    }

    /**
     * List registrations of a single student across all events. Used by
     * the "Mis inscripciones" page in the LXP wellness hub.
     *
     * @return array<int,object>
     */
    public static function list_for_user(int $userid): array {
        global $DB;
        $sql = "SELECT r.id, r.eventid, r.status, r.modality, r.registered_at, r.attended_at, r.cancelled_at,
                       e.title, e.summary, e.startdate, e.enddate, e.modality AS event_modality,
                       e.location, e.virtual_url, e.cover_path, e.category, e.capacity
                  FROM {gmk_wellness_registration} r
                  JOIN {gmk_wellness_event} e ON e.id = r.eventid
                 WHERE r.userid = :uid
              ORDER BY r.registered_at DESC";
        $rows = $DB->get_records_sql($sql, ['uid' => $userid]);
        return array_values(array_map(function ($r) {
            $r->id            = (int)$r->id;
            $r->eventid       = (int)$r->eventid;
            $r->registered_at = (int)$r->registered_at;
            $r->attended_at   = (int)$r->attended_at;
            $r->cancelled_at  = (int)$r->cancelled_at;
            $r->startdate     = (int)$r->startdate;
            $r->enddate       = (int)$r->enddate;
            $r->capacity      = (int)$r->capacity;
            return $r;
        }, $rows));
    }

/**
     * Mark the attendance outcome for a single registration (RF-09.2 admin).
     * Restricted to 'asistio' / 'no_asistio' — capacity-impacting changes
     * (confirmada/cancelada) MUST go through register()/cancel() so the
     * cup lock and the waitlist promotion logic stay consistent.
     */
    public static function mark_attendance(int $registrationid, string $status, int $authorid): bool {
        global $DB;
        if (!in_array($status, ['asistio', 'no_asistio'], true)) {
            return false;
        }
        $row = $DB->get_record('gmk_wellness_registration', ['id' => $registrationid]);
        if (!$row) {
            return false;
        }
        if (!in_array($row->status, ['confirmada', 'lista_de_espera'], true)) {
            // Cannot change attendance on a row that was never actively registered.
            return false;
        }
        $DB->set_field('gmk_wellness_registration', 'status', $status, ['id' => $registrationid]);
        if ($status === 'asistio') {
            $DB->set_field('gmk_wellness_registration', 'attended_at', time(), ['id' => $registrationid]);
        }
        return true;
    }

    /**
     * Detailed registrations list of a single event for the admin UI
     * (different from CSV export: returns structured rows with userinfo).
     *
     * @return array<int,object>
     */
    public static function list_event_registrations(int $eventid): array {
        global $DB;
        $sql = "SELECT r.*, u.firstname, u.lastname, u.email, u.username
                  FROM {gmk_wellness_registration} r
                  JOIN {user} u ON u.id = r.userid
                 WHERE r.eventid = :eid
              ORDER BY r.registered_at ASC";
        $rows = $DB->get_records_sql($sql, ['eid' => $eventid]);
        return array_values(array_map(function ($r) {
            $r->id            = (int)$r->id;
            $r->eventid       = (int)$r->eventid;
            $r->userid        = (int)$r->userid;
            $r->registered_at = (int)$r->registered_at;
            $r->attended_at   = (int)$r->attended_at;
            $r->cancelled_at  = (int)$r->cancelled_at;
            return $r;
        }, $rows));
    }
}

