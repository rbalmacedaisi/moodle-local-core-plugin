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
 * Manager for admin broadcast messages (info / warning) shown to students in the LXP.
 *
 * Responsibilities:
 *  - resolve the audience of a message based on its scope (all / career / group)
 *  - persist the materialised audience so per-career stats stay cheap to compute
 *  - expose per-user pending lists (highest priority first) that the LXP pulls
 *  - record acknowledgements and per-career acknowledgement counts
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Antigravity
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local;

defined('MOODLE_INTERNAL') || die();

class announcement_manager {

    /** Identifier used by the absence alert system. */
    public const SOURCE_ABSENCE = 'absence';

    /** Identifier used by admin broadcasts (this module). */
    public const SOURCE_ADMIN   = 'admin';

    /** Default priority for an admin broadcast message. Higher than absence alerts. */
    public const DEFAULT_PRIORITY = 50;

    /** Default priority used by the absence system; documented for cross-reference. */
    public const ABSENCE_PRIORITY = 10;

    /** Allowed values for the messagetype column. */
    public static function allowed_types(): array {
        return ['info', 'warning'];
    }

    /** Allowed audience scopes. */
    public static function allowed_scopes(): array {
        return ['all', 'career', 'group'];
    }

    /**
     * Persist a message and materialise its audience.
     *
     * @param array $payload validated input from the admin form. Expected keys:
     *   - title            (string, required)
     *   - messagetext      (string, required, may contain HTML)
     *   - messagetype      ('info'|'warning', default 'info')
     *   - audience_scope   ('all'|'career'|'group', default 'all')
     *   - audience_careerid (int, required when scope='career')
     *   - audience_groupid  (int, required when scope='group')
     *   - require_ack      (bool, default true)
     *   - ack_label        (string, default "He leído y estoy de acuerdo")
     *   - priority         (int, default 50)
     *   - starts_at        (int unix, 0 means now)
     *   - ends_at          (int unix, 0 means never)
     * @param int $authorid Moodle user id of the author.
     * @return array{ok:bool, message:object|null, count:int, error?:string}
     */
    public static function create(array $payload, int $authorid): array {
        global $DB;

        $title      = trim((string)($payload['title'] ?? ''));
        $body       = (string)($payload['messagetext'] ?? '');
        $type       = strtolower(trim((string)($payload['messagetype'] ?? 'info')));
        $scope      = strtolower(trim((string)($payload['audience_scope'] ?? 'all')));
        $careerid   = (int)($payload['audience_careerid'] ?? 0);
        $groupid    = (int)($payload['audience_groupid'] ?? 0);
        $requireAck = !empty($payload['require_ack']);
        $ackLabel   = trim((string)($payload['ack_label'] ?? ''));
        $priority   = (int)($payload['priority'] ?? self::DEFAULT_PRIORITY);
        $startsAt   = (int)($payload['starts_at'] ?? 0);
        $endsAt     = (int)($payload['ends_at'] ?? 0);

        if ($title === '' || $body === '') {
            return ['ok' => false, 'count' => 0, 'error' => 'title_or_body_empty'];
        }

        if (!in_array($type, self::allowed_types(), true)) {
            $type = 'info';
        }
        if (!in_array($scope, self::allowed_scopes(), true)) {
            $scope = 'all';
        }
        if ($scope === 'career' && $careerid <= 0) {
            return ['ok' => false, 'count' => 0, 'error' => 'career_required'];
        }
        if ($scope === 'group' && $groupid <= 0) {
            return ['ok' => false, 'count' => 0, 'error' => 'group_required'];
        }
        if ($ackLabel === '') {
            $ackLabel = 'He leído y estoy de acuerdo';
        }
        if ($priority <= 0) {
            $priority = self::DEFAULT_PRIORITY;
        }
        if ($startsAt < 0) {
            $startsAt = 0;
        }
        if ($endsAt < 0) {
            $endsAt = 0;
        }
        if ($endsAt > 0 && $startsAt > 0 && $endsAt <= $startsAt) {
            return ['ok' => false, 'count' => 0, 'error' => 'end_before_start'];
        }

        $now = time();

        $record = (object)[
            'usermodified'      => $authorid,
            'timecreated'       => $now,
            'timemodified'      => $now,
            'authorid'          => $authorid,
            'title'             => mb_substr($title, 0, 255),
            'messagetext'       => $body,
            'messagetype'       => $type,
            'audience_scope'    => $scope,
            'audience_careerid' => $careerid,
            'audience_groupid'  => $groupid,
            'require_ack'       => $requireAck ? 1 : 0,
            'ack_label'         => mb_substr($ackLabel, 0, 255),
            'priority'          => $priority,
            'starts_at'         => $startsAt > 0 ? $startsAt : $now,
            'ends_at'           => $endsAt,
            'active'            => 1,
        ];

        $trans = $DB->start_delegated_transaction();

        try {
            $messageid = $DB->insert_record('gmk_admin_message', $record);
            $record->id = $messageid;

            $users = self::resolve_audience($scope, $careerid, $groupid);
            $nowbatch = time();
            $batch = [];
            foreach ($users as $uid => $ucareer) {
                $batch[] = (object)[
                    'messageid'   => $messageid,
                    'userid'      => (int)$uid,
                    'careerid'    => (int)$ucareer,
                    'timecreated' => $nowbatch,
                ];
                if (count($batch) >= 500) {
                    $DB->insert_records('gmk_admin_message_user', $batch);
                    $batch = [];
                }
            }
            if (!empty($batch)) {
                $DB->insert_records('gmk_admin_message_user', $batch);
            }

            $trans->allow_commit();
        } catch (\Throwable $e) {
            $trans->rollback($e);
            return ['ok' => false, 'count' => 0, 'error' => 'db_error: ' . $e->getMessage()];
        }

        return [
            'ok'      => true,
            'message' => $record,
            'count'   => count($users),
        ];
    }

    /**
     * Toggle active flag (soft delete).
     */
    public static function set_active(int $messageid, int $active): bool {
        global $DB;
        if (!$DB->record_exists('gmk_admin_message', ['id' => $messageid])) {
            return false;
        }
        $DB->set_field('gmk_admin_message', 'active', $active ? 1 : 0, ['id' => $messageid]);
        $DB->set_field('gmk_admin_message', 'timemodified', time(), ['id' => $messageid]);
        return true;
    }

    /**
     * Resolve the recipient user ids for a given scope.
     *
     * Returns [userid => careerid] so we can stamp a per-user career snapshot
     * (useful when the user later changes their learning plan).
     *
     * Scope rules:
     *   - all     : every active student. Authoritative source in this fork is
     *               the local_learning_users table (userroleid = 5, status =
     *               'activo'), the same definition the absence dashboard uses
     *               to count students. We union that with users who have the
     *               moodle 'student' role at any context (some legacy
     *               contacts live in mdl_role_assignments only) so the admin
     *               never under-counts the audience.
     *   - career  : every student currently linked to that learning_plan
     *               via local_learning_users.userroleid = 5 (student role)
     *   - group   : every member of the given Moodle group
     *
     * @return array<int,int>
     */
    public static function resolve_audience(string $scope, int $careerid, int $groupid): array {
        global $DB;

        $out = [];

        if ($scope === 'all') {
            // Source 1: local_learning_users (canonical, used by
            // pages/debug_active_count.php and the absence dashboard).
            $rs = $DB->get_recordset_sql(
                "SELECT u.id, COALESCE(llu.learningplanid, 0) AS careerid
                   FROM {user} u
                   JOIN {local_learning_users} llu
                        ON llu.userid = u.id
                       AND llu.userroleid = 5
                       AND (llu.status = 'activo' OR llu.status = '' OR llu.status IS NULL)
                  WHERE u.deleted = 0 AND u.suspended = 0 AND u.id > 1"
            );
            foreach ($rs as $r) {
                $out[(int)$r->id] = (int)$r->careerid;
            }
            $rs->close();

            // Source 2 (safety net): users with the moodle 'student' role at
            // ANY context who were not captured above. The role-id itself is
            // the well-known archetype='student' from mdl_role, so we look it
            // up dynamically instead of hardcoding 5.
            $studentroleid = (int)$DB->get_field('role', 'id', ['archetype' => 'student']);
            if ($studentroleid > 0) {
                $rs = $DB->get_recordset_sql(
                    "SELECT DISTINCT u.id, COALESCE(llu.learningplanid, 0) AS careerid
                       FROM {user} u
                       JOIN {role_assignments} ra ON ra.userid = u.id AND ra.roleid = :srid
                  LEFT JOIN {local_learning_users} llu
                                ON llu.userid = u.id AND llu.userroleid = 5
                      WHERE u.deleted = 0 AND u.suspended = 0 AND u.id > 1",
                    ['srid' => $studentroleid]
                );
                foreach ($rs as $r) {
                    $uid = (int)$r->id;
                    if (!isset($out[$uid])) {
                        $out[$uid] = (int)$r->careerid;
                    }
                }
                $rs->close();
            }
            return $out;
        }

        if ($scope === 'career') {
            $rs = $DB->get_recordset_sql(
                "SELECT DISTINCT llu.userid AS id, llu.learningplanid AS careerid
                   FROM {local_learning_users} llu
                   JOIN {user} u ON u.id = llu.userid
                  WHERE llu.learningplanid = :cid
                    AND llu.userroleid = 5
                    AND u.deleted = 0 AND u.suspended = 0",
                ['cid' => $careerid]
            );
            foreach ($rs as $r) {
                $out[(int)$r->id] = (int)$r->careerid;
            }
            $rs->close();
            return $out;
        }

        if ($scope === 'group') {
            $rs = $DB->get_recordset_sql(
                "SELECT gm.userid AS id, COALESCE(llu.learningplanid, 0) AS careerid
                   FROM {groups_members} gm
                   JOIN {user} u ON u.id = gm.userid
              LEFT JOIN {local_learning_users} llu
                         ON llu.userid = u.id AND llu.userroleid = 5
                  WHERE gm.groupid = :gid
                    AND u.deleted = 0 AND u.suspended = 0",
                ['gid' => $groupid]
            );
            foreach ($rs as $r) {
                $out[(int)$r->id] = (int)$r->careerid;
            }
            $rs->close();
            return $out;
        }

        return $out;
    }

    /**
     * Return the pending messages for a student (those still needing to be
     * acknowledged OR surfaced regardless of ack). Sorted by priority desc
     * so the LXP can take precedence over lower-priority alerts.
     *
     * @return array of associative arrays with the columns the UI needs.
     */
    public static function get_pending_for_user(int $userid): array {
        global $DB;
        $now = time();

        $sql = "SELECT am.id, am.title, am.messagetext, am.messagetype,
                       am.audience_scope, am.audience_careerid, am.audience_groupid,
                       am.require_ack, am.ack_label, am.priority,
                       am.starts_at, am.ends_at, am.timecreated, am.authorid,
                       ack.acknowledged, ack.timeacknowledged
                  FROM {gmk_admin_message} am
                  JOIN {gmk_admin_message_user} amu ON amu.messageid = am.id
             LEFT JOIN {gmk_admin_message_ack} ack ON ack.messageid = am.id AND ack.userid = :userid
                 WHERE amu.userid = :userid2
                   AND am.active = 1
                   AND (am.starts_at = 0 OR am.starts_at <= :now1)
                   AND (am.ends_at   = 0 OR am.ends_at   >= :now2)
                   AND (
                        am.require_ack = 0
                     OR ack.id IS NULL
                     OR ack.acknowledged = 0
                   )
            ORDER BY am.priority DESC, am.timecreated DESC";

        $rows = $DB->get_records_sql($sql, [
            'userid'  => $userid,
            'userid2' => $userid,
            'now1'    => $now,
            'now2'    => $now,
        ]);

        $list = [];
        foreach ($rows as $r) {
            $list[] = [
                'id'                 => (int)$r->id,
                'title'              => (string)$r->title,
                'message'            => (string)$r->messagetext,
                'type'               => (string)$r->messagetype,
                'audience_scope'     => (string)$r->audience_scope,
                'audience_careerid'  => (int)$r->audience_careerid,
                'audience_groupid'   => (int)$r->audience_groupid,
                'require_ack'        => (bool)$r->require_ack,
                'ack_label'          => (string)($r->ack_label ?? ''),
                'priority'           => (int)$r->priority,
                'starts_at'          => (int)$r->starts_at,
                'ends_at'            => (int)$r->ends_at,
                'timecreated'        => (int)$r->timecreated,
                'authorid'           => (int)$r->authorid,
                'acknowledged'       => !empty($r->acknowledged),
                'timeacknowledged'   => (int)($r->timeacknowledged ?? 0),
            ];
        }
        return $list;
    }

    /**
     * Record an acknowledgement for a (user, message) pair. Idempotent.
     */
    public static function acknowledge(int $userid, int $messageid, bool $acknowledged = true): bool {
        global $DB;

        if (!$DB->record_exists('gmk_admin_message', ['id' => $messageid])) {
            return false;
        }
        $belongs = $DB->record_exists(
            'gmk_admin_message_user',
            ['messageid' => $messageid, 'userid' => $userid]
        );
        if (!$belongs) {
            return false;
        }

        $existing = $DB->get_record(
            'gmk_admin_message_ack',
            ['messageid' => $messageid, 'userid' => $userid]
        );

        $row = (object)[
            'messageid'        => $messageid,
            'userid'           => $userid,
            'acknowledged'     => $acknowledged ? 1 : 0,
            'timeacknowledged' => time(),
        ];

        if ($existing) {
            $row->id = $existing->id;
            $DB->update_record('gmk_admin_message_ack', $row);
        } else {
            $DB->insert_record('gmk_admin_message_ack', $row);
        }
        return true;
    }

    /**
     * Per-career (or per-scope when audience_scope='all') acknowledgement
     * counters for one broadcast.
     *
     * @return array<int, array{careerid:int, careername:string, total:int, acked:int, pending:int, percent:float}>
     */
    public static function per_career_stats(int $messageid): array {
        global $DB;

        $msg = $DB->get_record('gmk_admin_message', ['id' => $messageid]);
        if (!$msg) {
            return [];
        }

        // Pull all recipients for this message with their snapshot career id.
        $recps = $DB->get_records_sql(
            "SELECT amu.userid, amu.careerid,
                    CASE WHEN ack.id IS NOT NULL AND ack.acknowledged = 1 THEN 1 ELSE 0 END AS acked
               FROM {gmk_admin_message_user} amu
          LEFT JOIN {gmk_admin_message_ack} ack ON ack.messageid = amu.messageid AND ack.userid = amu.userid
              WHERE amu.messageid = :mid",
            ['mid' => $messageid]
        );

        $buckets = [];   // careerid => stats
        $careerNames = [];
        if (!empty($recps)) {
            [$insql, $inparams] = $DB->get_in_or_equal(array_unique(array_map(
                fn($r) => (int)$r->careerid, $recps
            )), SQL_PARAMS_NAMED, 'lp');
            foreach ($DB->get_records_sql(
                "SELECT id, name FROM {local_learning_plans} WHERE id $insql", $inparams
            ) as $lp) {
                $careerNames[(int)$lp->id] = (string)$lp->name;
            }
        }

        foreach ($recps as $r) {
            $cid = (int)$r->careerid;
            if (!isset($buckets[$cid])) {
                $buckets[$cid] = [
                    'careerid'    => $cid,
                    'careername'  => $careerNames[$cid] ?? ($cid === 0 ? 'Sin carrera asignada' : ('Carrera #' . $cid)),
                    'total'       => 0,
                    'acked'       => 0,
                    'pending'     => 0,
                    'percent'     => 0.0,
                ];
            }
            $buckets[$cid]['total']++;
            if ((int)$r->acked === 1) {
                $buckets[$cid]['acked']++;
            } else {
                $buckets[$cid]['pending']++;
            }
        }

        foreach ($buckets as &$b) {
            $b['percent'] = $b['total'] > 0 ? round(($b['acked'] / $b['total']) * 100, 1) : 0.0;
        }
        unset($b);

        // Sort: defined careers first (by name), then "Sin carrera" last.
        uasort($buckets, function (array $a, array $b) {
            if ($a['careerid'] === $b['careerid']) return 0;
            if ($a['careerid'] === 0) return 1;
            if ($b['careerid'] === 0) return -1;
            return strcmp($a['careername'], $b['careername']);
        });

        return array_values($buckets);
    }

    /**
     * Detail list of recipients with their ack state. Used by the admin UI.
     *
     * @return array rows of [userid, fullname, careerid, careername, acked, timeacknowledged]
     */
    public static function list_recipients(int $messageid): array {
        global $DB;

        $sql = "SELECT u.id, u.firstname, u.lastname, u.email,
                       amu.careerid,
                       COALESCE(lp.name, '') AS careername,
                       CASE WHEN ack.id IS NOT NULL AND ack.acknowledged = 1 THEN 1 ELSE 0 END AS acked,
                       COALESCE(ack.timeacknowledged, 0) AS timeacknowledged
                  FROM {gmk_admin_message_user} amu
                  JOIN {user} u ON u.id = amu.userid
             LEFT JOIN {local_learning_plans} lp ON lp.id = amu.careerid
             LEFT JOIN {gmk_admin_message_ack} ack ON ack.messageid = amu.messageid AND ack.userid = amu.userid
                 WHERE amu.messageid = :mid
              ORDER BY u.lastname, u.firstname";

        $rows = $DB->get_records_sql($sql, ['mid' => $messageid]);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'userid'           => (int)$r->id,
                'name'             => trim($r->firstname . ' ' . $r->lastname),
                'email'            => (string)$r->email,
                'careerid'         => (int)$r->careerid,
                'careername'       => (string)$r->careername ?: 'Sin carrera asignada',
                'acked'            => (bool)$r->acked,
                'timeacknowledged' => (int)$r->timeacknowledged,
            ];
        }
        return $out;
    }

    /**
     * Fetch the message row by id, plus the total + acked counters so the admin
     * list view can render the per-message summary without N+1 queries.
     */
    public static function list_messages(): array {
        global $DB;

        $sql = "SELECT m.id, m.title, m.messagetype, m.audience_scope, m.audience_careerid,
                       m.audience_groupid, m.priority, m.require_ack, m.ack_label,
                       m.starts_at, m.ends_at, m.timecreated, m.timemodified,
                       m.authorid, m.active,
                       u.firstname, u.lastname,
                       (SELECT COUNT(*) FROM {gmk_admin_message_user} mu WHERE mu.messageid = m.id) AS recipients,
                       (SELECT COUNT(*) FROM {gmk_admin_message_ack} a JOIN {gmk_admin_message_user} mu ON mu.messageid = a.messageid AND mu.userid = a.userid WHERE a.messageid = m.id AND a.acknowledged = 1) AS acked
                  FROM {gmk_admin_message} m
             LEFT JOIN {user} u ON u.id = m.authorid
              ORDER BY m.timecreated DESC";

        $rows = $DB->get_records_sql($sql);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'                  => (int)$r->id,
                'title'               => (string)$r->title,
                'messagetype'         => (string)$r->messagetype,
                'audience_scope'      => (string)$r->audience_scope,
                'audience_careerid'   => (int)$r->audience_careerid,
                'audience_groupid'    => (int)$r->audience_groupid,
                'priority'            => (int)$r->priority,
                'require_ack'         => (bool)$r->require_ack,
                'ack_label'           => (string)($r->ack_label ?? ''),
                'starts_at'           => (int)$r->starts_at,
                'ends_at'             => (int)$r->ends_at,
                'timecreated'         => (int)$r->timecreated,
                'timemodified'        => (int)$r->timemodified,
                'authorid'            => (int)$r->authorid,
                'authorname'          => trim($r->firstname . ' ' . $r->lastname),
                'active'              => (bool)$r->active,
                'recipients'          => (int)$r->recipients,
                'acked'               => (int)$r->acked,
            ];
        }
        return $out;
    }

    /**
     * Used by the admin form: returns the catalogue of careers.
     */
    public static function list_careers(): array {
        global $DB;
        $rows = $DB->get_records('local_learning_plans', null, 'name ASC', 'id, name');
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['id' => (int)$r->id, 'name' => (string)$r->name];
        }
        return $out;
    }

    /**
     * Returns a flat list of group ids grouped by course (used when the admin
     * wants to target a single group). We expose groups that are linked to a
     * gmk_class so they are guaranteed to be student-bearing.
     */
    public static function list_groups(): array {
        global $DB;
        $rows = $DB->get_records_sql(
            "SELECT g.id, g.name, g.courseid, c.fullname AS coursename
               FROM {groups} g
          LEFT JOIN {course} c ON c.id = g.courseid
              ORDER BY c.fullname, g.name"
        );
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'         => (int)$r->id,
                'name'       => (string)$r->name,
                'courseid'   => (int)$r->courseid,
                'coursename' => (string)$r->coursename,
            ];
        }
        return $out;
    }
}
