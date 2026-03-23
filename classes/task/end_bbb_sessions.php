<?php
namespace local_grupomakro_core\task;

use core\task\scheduled_task;

defined('MOODLE_INTERNAL') || die();

/**
 * Scheduled task: end BBB meetings at their scheduled end_time.
 *
 * Runs every 5 minutes. Finds class sessions whose end_time falls within
 * the last 10 minutes and calls the BBB API to terminate the meeting.
 */
class end_bbb_sessions extends scheduled_task {

    public function get_name() {
        return 'Finalizar sesiones BBB expiradas';
    }

    public function execute() {
        global $DB;

        mtrace('Starting end_bbb_sessions task...');

        $now = time();

        // Day label map matching the values stored in gmk_class_schedules.day
        // (Spanish, no accents, first letter capitalized)
        $dayMap     = ['Domingo', 'Lunes', 'Martes', 'Miercoles', 'Jueves', 'Viernes', 'Sabado'];
        $todayLabel = $dayMap[(int)date('w', $now)];

        // Current time and 10-minute-ago time as HH:MM strings for SQL comparison
        $currentHHMM = date('H:i', $now);
        $windowAgoHHMM = date('H:i', $now - 600); // 10-minute window

        mtrace("Day: {$todayLabel} | Window: {$windowAgoHHMM} – {$currentHHMM}");

        // Get BBB server configuration
        $bbbcfg    = get_config('bigbluebuttonbn');
        $serverUrl = isset($bbbcfg->server_url) ? rtrim((string)$bbbcfg->server_url, '/') . '/' : '';
        $secret    = isset($bbbcfg->shared_secret) ? (string)$bbbcfg->shared_secret : '';

        if (empty($serverUrl) || empty($secret)) {
            mtrace('ERROR: BBB server_url or shared_secret not configured. Aborting.');
            return;
        }

        // Find sessions ending in this window that belong to non-closed classes
        $sql = "SELECT cs.id AS schedid,
                       cs.classid,
                       cs.end_time,
                       rel.bbbid
                  FROM {gmk_class_schedules} cs
                  JOIN {gmk_bbb_attendance_relation} rel ON rel.classid = cs.classid
                  JOIN {gmk_class} gc                   ON gc.id = cs.classid
                 WHERE cs.day      = :today
                   AND cs.end_time >= :window_ago
                   AND cs.end_time <= :current_time
                   AND gc.closed   = 0
                   AND cs.end_time IS NOT NULL
                   AND cs.end_time <> ''";

        $sessions = $DB->get_records_sql($sql, [
            'today'        => $todayLabel,
            'window_ago'   => $windowAgoHHMM,
            'current_time' => $currentHHMM,
        ]);

        $count = count($sessions);
        mtrace("Found {$count} session(s) to end.");

        if ($count === 0) {
            mtrace('Task completed.');
            return;
        }

        foreach ($sessions as $session) {
            try {
                mtrace("  classid={$session->classid}, bbbid={$session->bbbid}, end_time={$session->end_time}");

                $bbbinstance = $DB->get_record('bigbluebuttonbn', ['id' => (int)$session->bbbid]);
                if (!$bbbinstance) {
                    mtrace("  BBB instance {$session->bbbid} not found in bigbluebuttonbn table. Skipping.");
                    continue;
                }

                $meetingId = (string)$bbbinstance->meetingid;
                $modPw     = (string)$bbbinstance->moderatorpass;

                if (empty($meetingId)) {
                    mtrace("  meetingid is empty for bbbid={$session->bbbid}. Skipping.");
                    continue;
                }

                // Build BBB API end URL with checksum
                $queryString = 'meetingID=' . urlencode($meetingId) . '&password=' . urlencode($modPw);
                $checksum    = sha1('end' . $queryString . $secret);
                $endUrl      = $serverUrl . 'api/end?' . $queryString . '&checksum=' . $checksum;

                // Call BBB API using Moodle's curl wrapper
                $curl = new \curl();
                $curl->setopt([
                    'CURLOPT_TIMEOUT'        => 10,
                    'CURLOPT_SSL_VERIFYPEER' => false,
                ]);
                $response = (string)$curl->get($endUrl);

                if ($curl->get_errno()) {
                    mtrace("  CURL error for classid={$session->classid}: " . $curl->error);
                    continue;
                }

                if (strpos($response, '<returncode>SUCCESS</returncode>') !== false) {
                    mtrace("  OK — meeting ended for classid={$session->classid} (meetingID={$meetingId})");
                } elseif (strpos($response, 'notFound') !== false) {
                    mtrace("  Meeting not found — already ended for classid={$session->classid}");
                } else {
                    mtrace("  BBB response for classid={$session->classid}: " . substr($response, 0, 300));
                }

            } catch (\Exception $e) {
                mtrace("  Exception for classid={$session->classid}: " . $e->getMessage());
            }
        }

        mtrace('Task completed.');
    }
}
