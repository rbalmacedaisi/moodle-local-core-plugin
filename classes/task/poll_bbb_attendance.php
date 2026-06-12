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
 * Scheduled task: samples live BBB meetings and accumulates per-student presence time.
 *
 * Runs every 2 minutes. Performs a single getMeetings call to the BBB server, maps each
 * live meeting to its attendance session via gmk_bbb_attendance_relation, and credits
 * the elapsed time since the previous sample to each present student. Reliable presence
 * data (unlike the irregular bigbluebuttonbn_logs "Join" events) used by
 * reconcile_bbb_attendance to mark attendance at 70% permanence.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\task;

use core\task\scheduled_task;

defined('MOODLE_INTERNAL') || die();

class poll_bbb_attendance extends scheduled_task {

    /** @var int Max seconds credited per sample (≈2× the 2-min interval) to avoid over-crediting gaps. */
    const MAX_CREDIT_SECONDS = 300;

    public function get_name() {
        return 'Registrar asistencia BBB (muestreo de permanencia)';
    }

    public function execute() {
        global $DB, $CFG;

        $now = time();

        // BBB server/secret may live as config.php overrides ($CFG->bigbluebuttonbn_*)
        // or as plugin config; prefer the $CFG overrides used by this install.
        $bbbcfg    = get_config('bigbluebuttonbn');
        $serverurl = !empty($CFG->bigbluebuttonbn_server_url) ? (string)$CFG->bigbluebuttonbn_server_url
            : (!empty($bbbcfg->server_url) ? (string)$bbbcfg->server_url : '');
        $secret    = !empty($CFG->bigbluebuttonbn_shared_secret) ? (string)$CFG->bigbluebuttonbn_shared_secret
            : (!empty($bbbcfg->shared_secret) ? (string)$bbbcfg->shared_secret : '');
        $serverurl = $serverurl !== '' ? rtrim($serverurl, '/') . '/' : '';
        if ($serverurl === '' || $secret === '') {
            mtrace('poll_bbb_attendance: BBB server_url/shared_secret not configured. Skipping.');
            return;
        }

        // Active relations (non-closed classes), keyed by bbbid and by base meeting hash.
        $relations = $DB->get_records_sql(
            "SELECT rel.id, rel.bbbid, rel.attendancesessionid, rel.classid,
                    b.meetingid AS basehash, cl.groupid
               FROM {gmk_bbb_attendance_relation} rel
               JOIN {gmk_class} cl ON cl.id = rel.classid AND cl.closed = 0
          LEFT JOIN {bigbluebuttonbn} b ON b.id = rel.bbbid
              WHERE rel.attendancesessionid > 0 AND rel.bbbid > 0"
        );
        if (empty($relations)) {
            return;
        }
        $bybbbid = [];
        $byhash  = [];
        foreach ($relations as $r) {
            $bybbbid[(int)$r->bbbid] = $r;
            if (!empty($r->basehash)) {
                $byhash[(string)$r->basehash] = $r;
            }
        }

        // Single getMeetings call returns ALL live meetings + attendees (lightweight on BBB).
        $checksum = sha1('getMeetings' . $secret);
        $url = $serverurl . 'api/getMeetings?checksum=' . $checksum;

        $curl = new \curl();
        $curl->setopt(['CURLOPT_TIMEOUT' => 15, 'CURLOPT_SSL_VERIFYPEER' => false]);
        $response = (string)$curl->get($url);
        if ($curl->get_errno() || $response === '') {
            mtrace('poll_bbb_attendance: getMeetings failed: ' . $curl->error);
            return;
        }

        $xml = @simplexml_load_string($response);
        if (!$xml || (string)$xml->returncode !== 'SUCCESS') {
            return;
        }
        if (!isset($xml->meetings) || !isset($xml->meetings->meeting)) {
            return; // No live meetings.
        }

        $groupmemberscache = [];
        $samples = 0;

        foreach ($xml->meetings->meeting as $m) {
            $meetingid = (string)$m->meetingID;
            if ($meetingid === '') {
                continue;
            }
            $clean = preg_replace('/\[\d+\]$/', '', $meetingid); // strip breakout marker e.g. [0]

            // Resolve to a relation: (a) trailing -<courseid>-<bbbid>, (b) base hash prefix.
            $rel = null;
            if (preg_match('/-(\d+)-(\d+)$/', $clean, $mm)) {
                $cand = (int)$mm[2];
                if (isset($bybbbid[$cand])) {
                    $rel = $bybbbid[$cand];
                }
            }
            if ($rel === null) {
                foreach ($byhash as $hash => $r) {
                    if (strpos($clean, $hash) === 0) {
                        $rel = $r;
                        break;
                    }
                }
            }
            if ($rel === null) {
                continue;
            }

            $sessionid = (int)$rel->attendancesessionid;
            $classid   = (int)$rel->classid;
            $bbbid     = (int)$rel->bbbid;
            $groupid   = (int)$rel->groupid;

            if (!isset($groupmemberscache[$groupid])) {
                $groupmemberscache[$groupid] = $groupid > 0
                    ? array_flip(array_keys($DB->get_records('groups_members', ['groupid' => $groupid], '', 'userid')))
                    : [];
            }
            $members = $groupmemberscache[$groupid];

            if (!isset($m->attendees) || !isset($m->attendees->attendee)) {
                continue;
            }

            foreach ($m->attendees->attendee as $att) {
                $uid = (int)trim((string)$att->userID);
                if ($uid <= 0) {
                    continue;
                }
                $ismod    = (strtoupper(trim((string)$att->role)) === 'MODERATOR') ? 1 : 0;
                $ismember = isset($members[$uid]);
                // Record group students (to mark) and moderators (only to bound the meeting window).
                if (!$ismember && !$ismod) {
                    continue;
                }

                $existing = $DB->get_record('gmk_bbb_presence',
                    ['attendancesessionid' => $sessionid, 'userid' => $uid]);

                if (!$existing) {
                    $rec = new \stdClass();
                    $rec->attendancesessionid = $sessionid;
                    $rec->classid         = $classid;
                    $rec->bbbid           = $bbbid;
                    $rec->userid          = $uid;
                    $rec->ismoderator     = $ismod;
                    $rec->present_seconds = 0;
                    $rec->sample_count    = 1;
                    $rec->first_seen      = $now;
                    $rec->last_seen       = $now;
                    $rec->reconciled      = 0;
                    $rec->timecreated     = $now;
                    $rec->timemodified    = $now;
                    $DB->insert_record('gmk_bbb_presence', $rec);
                } else {
                    if ((int)$existing->reconciled === 1) {
                        continue; // Session already reconciled; do not mutate.
                    }
                    $delta = $now - (int)$existing->last_seen;
                    if ($delta < 0) {
                        $delta = 0;
                    } else if ($delta > self::MAX_CREDIT_SECONDS) {
                        $delta = self::MAX_CREDIT_SECONDS;
                    }
                    $existing->present_seconds = (int)$existing->present_seconds + $delta;
                    $existing->sample_count    = (int)$existing->sample_count + 1;
                    $existing->last_seen       = $now;
                    if ($ismod) {
                        $existing->ismoderator = 1;
                    }
                    $existing->timemodified = $now;
                    $DB->update_record('gmk_bbb_presence', $existing);
                }
                $samples++;
            }
        }

        if ($samples > 0) {
            mtrace("poll_bbb_attendance: recorded $samples attendee sample(s).");
        }
    }
}
