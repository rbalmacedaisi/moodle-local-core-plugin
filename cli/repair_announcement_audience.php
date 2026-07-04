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
 * CLI utility: re-resolve the recipient audience of an admin broadcast.
 *
 * Use case: the audience resolver changed (e.g. local_learning_users is now the
 * canonical source instead of role_assignments at contextlevel=system). Existing
 * messages keep their gmk_admin_message_user snapshot from when they were first
 * published; this script rewrites the snapshot for one (or all) messages so
 * the new resolver logic is reflected without having to republish.
 *
 * Acknowledgement rows in gmk_admin_message_ack are NOT touched: any user who
 * already acknowledged keeps their ack timestamp (rows outside the new audience
 * simply become orphans that no student will reach via the LXP).
 *
 * USAGE:
 *   # Dry-run preview for one message
 *   sudo -u www-data php local/grupomakro_core/cli/repair_announcement_audience.php --messageid=1
 *
 *   # Apply for one message
 *   sudo -u www-data php local/grupomakro_core/cli/repair_announcement_audience.php --messageid=1 --apply
 *
 *   # Apply for every active admin message
 *   sudo -u www-data php local/grupomakro_core/cli/repair_announcement_audience.php --all --apply
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/announcement_manager.php');

// This Moodle 4.0.x build ships cli_get_params (plural) but not cli_get_param
// (singular). Use plain getopt() the same way the other group CLI scripts do.
$options = getopt('', ['apply', 'messageid::', 'all', 'help']);
if (isset($options['help']) || (!$options)) {
    fwrite(STDOUT, "USAGE:\n");
    fwrite(STDOUT, "  sudo -u www-data php local/grupomakro_core/cli/repair_announcement_audience.php --messageid=N\n");
    fwrite(STDOUT, "  sudo -u www-data php local/grupomakro_core/cli/repair_announcement_audience.php --messageid=N --apply\n");
    fwrite(STDOUT, "  sudo -u www-data php local/grupomakro_core/cli/repair_announcement_audience.php --all --apply\n");
    exit(0);
}
$apply = !empty($options['apply']);
$msgid = isset($options['messageid']) ? (int)$options['messageid'] : 0;
$all   = !empty($options['all']);

if (!$msgid && !$all) {
    fwrite(STDERR, "ERROR: pass --messageid=N or --all.\n");
    exit(1);
}

global $DB;

$messages = [];
if ($all) {
    $messages = $DB->get_records('gmk_admin_message', null, 'id ASC', 'id, title, audience_scope, audience_careerid, audience_groupid');
} else {
    $rec = $DB->get_record('gmk_admin_message', ['id' => $msgid], 'id, title, audience_scope, audience_careerid, audience_groupid');
    if (!$rec) {
        cli_error("Message id=$msgid not found.");
    }
    $messages[] = $rec;
}

foreach ($messages as $msg) {
    $oldcount = $DB->count_records('gmk_admin_message_user', ['messageid' => $msg->id]);
    $audience = \local_grupomakro_core\local\announcement_manager::resolve_audience(
        $msg->audience_scope,
        (int)$msg->audience_careerid,
        (int)$msg->audience_groupid
    );
    $newcount = count($audience);

    cli_writeln(sprintf(
        "  id=%d '%s'  scope=%s  recipients old=%d  resolved=%d  delta=%+d",
        $msg->id,
        mb_substr((string)$msg->title, 0, 60),
        $msg->audience_scope,
        $oldcount,
        $newcount,
        $newcount - $oldcount
    ));

    if (!$apply) {
        continue;
    }

    $trans = $DB->start_delegated_transaction();

    try {
        // Refresh snapshot. We keep any existing rows that are still in the
        // audience (preserves their careerid snapshot and timecreated) and
        // bulk-insert only the new ones. We remove users who dropped out so
        // per-career stats reflect the new audience.
        $existing = [];
        foreach ($DB->get_records('gmk_admin_message_user',
                ['messageid' => $msg->id], '', 'userid, careerid, timecreated') as $row) {
            $existing[(int)$row->userid] = $row;
        }

        $now = time();
        $removed = 0; $added = 0; $kept = 0;
        // Remove users no longer in audience.
        foreach ($existing as $uid => $row) {
            if (!isset($audience[$uid])) {
                $DB->delete_records('gmk_admin_message_user', [
                    'messageid' => $msg->id,
                    'userid'    => $uid,
                ]);
                $removed++;
            } else {
                $kept++;
            }
        }
        // Insert new users with current careerid snapshot.
        $batch = [];
        foreach ($audience as $uid => $careerid) {
            if (isset($existing[$uid])) continue;
            $batch[] = (object)[
                'messageid'   => $msg->id,
                'userid'      => (int)$uid,
                'careerid'    => (int)$careerid,
                'timecreated' => $now,
            ];
            if (count($batch) >= 500) {
                $DB->insert_records('gmk_admin_message_user', $batch);
                $added += count($batch);
                $batch = [];
            }
        }
        if (!empty($batch)) {
            $DB->insert_records('gmk_admin_message_user', $batch);
            $added += count($batch);
        }

        $trans->allow_commit();

        cli_writeln(sprintf(
            "    --> applied: +%d -%d (kept %d)",
            $added, $removed, $kept
        ));
    } catch (\Throwable $e) {
        $trans->rollback($e);
        cli_error("Failed: " . $e->getMessage());
    }
}

cli_writeln($apply ? "Done (changes applied)." : "Done (dry-run, no changes).");
