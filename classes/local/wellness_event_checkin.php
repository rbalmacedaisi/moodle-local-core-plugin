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
 * QR self check-in for wellness events.
 *
 * Bienestar shows (or prints) one static QR per event. The student scans it,
 * signs in through the LXP and pages/wellness_event_checkin.php records the
 * attendance as 'asistio' in gmk_wellness_registration, creating the row for
 * walk-ins who never registered. Students in mora are refused, exactly like
 * the class attendance QR (attendance_qr_bridge.php).
 *
 * The QR carries an HMAC of the event id, so a code cannot be forged for
 * another event, and it only works between the event's start and end dates
 * (plus the CHECKIN_OPEN_BEFORE / CHECKIN_CLOSE_AFTER margins, 0 by default):
 * Bienestar sets the QR availability by editing the event dates.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local;

defined('MOODLE_INTERNAL') || die();

class wellness_event_checkin {

    /** @var string Config key of the HMAC secret (created on first QR render). */
    const SECRET_KEY = 'wellness_event_qr_secret';

    /** @var int Check-in opens this long before the event starts. */
    const CHECKIN_OPEN_BEFORE = 0;

    /** @var int Check-in stays open this long after the event ends. */
    const CHECKIN_CLOSE_AFTER = 0;

    /**
     * HMAC secret for event QR tokens.
     *
     * @param bool $create Generate and store one when missing (admin QR page only).
     * @return string Empty when missing and not created.
     */
    public static function secret(bool $create = false): string {
        $secret = (string)get_config('local_grupomakro_core', self::SECRET_KEY);
        if ($secret === '' && $create) {
            $secret = bin2hex(random_bytes(32));
            set_config(self::SECRET_KEY, $secret, 'local_grupomakro_core');
        }
        return $secret;
    }

    /**
     * Token printed in the QR for an event.
     *
     * @param int  $eventid
     * @param bool $create Passed to secret().
     * @return string
     */
    public static function token(int $eventid, bool $create = false): string {
        $secret = self::secret($create);
        if ($secret === '') {
            return '';
        }
        return substr(hash_hmac('sha256', 'wellness-event-checkin|' . $eventid, $secret), 0, 32);
    }

    /**
     * Whether a scanned token matches the event.
     *
     * @param int    $eventid
     * @param string $token
     * @return bool
     */
    public static function valid_token(int $eventid, string $token): bool {
        $expected = self::token($eventid);
        return $expected !== '' && $token !== '' && hash_equals($expected, $token);
    }

    /**
     * URL encoded in the QR.
     *
     * @param int $eventid
     * @return \moodle_url
     */
    public static function url(int $eventid): \moodle_url {
        return new \moodle_url('/local/grupomakro_core/pages/wellness_event_checkin.php', [
            'id' => $eventid,
            't'  => self::token($eventid, true),
        ]);
    }

    /**
     * Check-in window of an event.
     *
     * @param object $event gmk_wellness_event row.
     * @return int[] [opens, closes]; closes = 0 when the event has no end date.
     */
    public static function window(object $event): array {
        $opens = (int)$event->startdate > 0 ? (int)$event->startdate - self::CHECKIN_OPEN_BEFORE : 0;
        $end = (int)$event->enddate > 0 ? (int)$event->enddate : (int)$event->startdate;
        $closes = $end > 0 ? $end + self::CHECKIN_CLOSE_AFTER : 0;
        return [$opens, $closes];
    }

    /**
     * Whether the student is refused because of their financial state.
     *
     * Same rule as gmk_qr_payment_block_state() for class attendance: only an
     * explicit 'mora' in the Odoo-synced gmk_financial_status blocks (fail-open
     * on missing data), and the global financial bypass switches it off.
     *
     * @param int $userid
     * @return bool
     */
    public static function blocked_by_mora(int $userid): bool {
        global $DB;
        if (get_config('local_grupomakro_core', 'financial_bypass_enabled')) {
            return false;
        }
        $status = (string)$DB->get_field('gmk_financial_status', 'status', ['userid' => $userid], IGNORE_MULTIPLE);
        return strtolower(trim($status)) === 'mora';
    }

    /**
     * Record the attendance of a student who scanned the event QR.
     *
     * @param int $eventid
     * @param int $userid
     * @param int $now
     * @return array{result:string, title:string} result: ok | already | mora | closed | inactive | not_found | error
     */
    public static function checkin(int $eventid, int $userid, int $now = 0): array {
        global $DB;
        $now = $now ?: time();

        $event = $DB->get_record('gmk_wellness_event', ['id' => $eventid]);
        if (!$event) {
            return ['result' => 'not_found', 'title' => ''];
        }
        $title = (string)$event->title;
        if ((int)$event->active !== 1) {
            return ['result' => 'inactive', 'title' => $title];
        }
        // Mora goes first: a student in arrears must learn that, not "come back later".
        if (self::blocked_by_mora($userid)) {
            return ['result' => 'mora', 'title' => $title];
        }
        [$opens, $closes] = self::window($event);
        if (($opens > 0 && $now < $opens) || ($closes > 0 && $now > $closes)) {
            return ['result' => 'closed', 'title' => $title];
        }

        $modality = $event->modality === 'virtual' ? 'virtual' : 'presencial';
        try {
            $existing = $DB->get_record('gmk_wellness_registration', ['eventid' => $eventid, 'userid' => $userid]);
            if ($existing && $existing->status === 'asistio') {
                return ['result' => 'already', 'title' => $title];
            }
            if ($existing) {
                $DB->update_record('gmk_wellness_registration', (object)[
                    'id'          => (int)$existing->id,
                    'status'      => 'asistio',
                    'attended_at' => $now,
                ]);
            } else {
                // Walk-in: attended without registering first.
                $DB->insert_record('gmk_wellness_registration', (object)[
                    'eventid'       => $eventid,
                    'userid'        => $userid,
                    'status'        => 'asistio',
                    'modality'      => $modality,
                    'registered_at' => $now,
                    'attended_at'   => $now,
                    'cancelled_at'  => 0,
                    'source'        => 'qr',
                    'registered_by' => $userid,
                    'notes'         => null,
                ]);
            }
        } catch (\Throwable $e) {
            debugging('wellness_event_checkin: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return ['result' => 'error', 'title' => $title];
        }
        return ['result' => 'ok', 'title' => $title];
    }
}
