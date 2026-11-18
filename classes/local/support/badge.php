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
 * Mesa de ayuda (fase 2): datos del indicador flotante de Moodle (§8.2) y del
 * modo incisivo (§8.5 capas 1-3). Un solo servicio, support_get_badge, que el
 * JS consulta cada tkt_badge_poll segundos; la respuesta se cachea 20 s por
 * persona (cache supportbadge) y se invalida al acusar, tomar, posponer o
 * cambiar la disponibilidad.
 *
 * Alertas (lo que pide acuse): por cada ticket abierto que espera al personal,
 *  - asignado a mi: mensaje nuevo del solicitante, asignacion nueva o un nivel
 *    de escalamiento nuevo;
 *  - sin asignar en mis temas (agente o respaldo): ticket nuevo;
 *  - supervisores: tickets visibles en naranja o rojo.
 * Una alerta se apaga con el acuse (abrir el ticket en la bandeja, tomarlo o
 * responder) posterior a manager::alert_since(). Ver la pildora no cuenta.
 *
 * Privacidad: nunca viaja el texto de un mensaje; en tickets confidenciales
 * tampoco el nombre del solicitante ("Ticket confidencial").
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\support;

use moodle_exception;
use stdClass;

defined('MOODLE_INTERNAL') || die();

class badge {

    const CACHE_TTL = 20;
    const MAX_ALERTS = 10;

    private static function cache(): ?\cache {
        try {
            return \cache::make('local_grupomakro_core', 'supportbadge');
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function invalidate(int $userid): void {
        $cache = self::cache();
        if ($cache) {
            $cache->delete($userid);
        }
    }

    /**
     * Badge data for $userid (cached CACHE_TTL seconds).
     */
    public static function get(int $userid, bool $fresh = false): array {
        $cache = self::cache();
        $now = time();
        if (!$fresh && $cache) {
            $hit = $cache->get($userid);
            if (is_array($hit) && ($hit['generated'] ?? 0) > $now - self::CACHE_TTL) {
                return $hit;
            }
        }
        $data = self::build($userid, $now);
        if ($cache) {
            $cache->set($userid, $data);
        }
        return $data;
    }

    public static function build(int $userid, int $now): array {
        $p = manager::profile($userid);
        $out = [
            'generated' => $now,
            'isstaff' => (bool)$p->isstaff,
            'staff' => null,
            'requester' => null,
            'deskurl' => (new \moodle_url('/local/grupomakro_core/pages/support_desk.php'))->out(false),
            'myurl' => (new \moodle_url('/local/grupomakro_core/pages/support_my.php'))->out(false),
            'poll' => max(10, config::get_int('tkt_badge_poll')),
        ];
        if ($p->isstaff) {
            $out['staff'] = self::staff_part($userid, $p, $now);
        }
        $isteacher = manager::is_teacher($userid);
        $replies = manager::requester_replies($userid, 'teacher');
        $ratings = manager::pending_ratings($userid, $now, 'teacher');
        if ($isteacher || $replies || $ratings) {
            $out['requester'] = ['teacher' => $isteacher, 'replies' => $replies, 'ratings' => $ratings];
        }
        return $out;
    }

    private static function staff_part(int $userid, stdClass $p, int $now): array {
        global $DB;
        $available = manager::is_available($userid);
        $inhours = self::in_hours($p, $now);
        $profile = policy::incisive_profile($userid);
        $active = $available && $inhours;

        [$w, $params] = manager::desk_where($userid, $p, 'unassigned', '');
        $unassigned = (int)$DB->count_records_sql("SELECT COUNT(1) FROM {gmk_tkt_ticket} t JOIN {user} s ON s.id = t.userid
            WHERE $w", $params);
        [$w, $params] = manager::desk_where($userid, $p, 'mine', '');
        $params['ru'] = $userid;
        $params['ru2'] = $userid;
        $mine = $DB->get_record_sql("SELECT COUNT(1) AS n,
                       SUM(CASE WHEN EXISTS (SELECT 1 FROM {gmk_tkt_message} m
                                              WHERE m.ticketid = t.id AND m.deleted = 0 AND m.senderid = t.userid
                                                AND m.id > COALESCE((SELECT r.lastreadmessageid FROM {gmk_tkt_read} r
                                                                      WHERE r.ticketid = t.id AND r.userid = :ru), 0))
                                THEN 1 ELSE 0 END) AS unread
                  FROM {gmk_tkt_ticket} t JOIN {user} s ON s.id = t.userid
                 WHERE $w AND t.assignedto = :ru2", $params);
        $overdue = manager::overdue_ids($userid, $p);

        $alerts = self::alerts($userid, $p, $profile, $now);

        $staff = [
            'available' => $available,
            'inhours' => $inhours,
            'active' => $active,
            'unassigned' => $unassigned,
            'mine' => (int)$mine->n,
            'mineunread' => (int)$mine->unread,
            'overdue' => count($overdue),
            'alerts' => $alerts,
            'profile' => [
                'code' => $profile['code'],
                'label' => $profile['label'],
                // Out of hours or away: only the pill and Moodle's bell (§8.5 "respeta la disponibilidad").
                'lock' => $active ? $profile['lock'] : 'none',
                'sound' => $active && $profile['sound'],
                'title' => $active && $profile['title'],
                'osnotify' => $active && $profile['osnotify'],
                'soundname' => $profile['soundname'],
            ],
            'snoozeminutes' => max(1, config::get_int('tkt_snooze_minutes')),
            'snoozemax' => config::get_int('tkt_snooze_max'),
            'supervisor' => (bool)$p->supervisor,
            'dashboard' => $p->supervisor ? self::dashboard($userid, $p, $now) : null,
        ];
        return $staff;
    }

    /**
     * Whether at least one of the user's areas is within business hours now.
     */
    private static function in_hours(stdClass $p, int $now): bool {
        if (!$p->areas) {
            return true;
        }
        foreach ($p->areas as $areaid) {
            $area = policy::area((int)$areaid);
            if ($area && business_hours::is_open($area, $now)) {
                return true;
            }
        }
        return false;
    }

    private static function alerts(int $userid, stdClass $p, array $profile, int $now): array {
        global $DB;
        [$in, $params] = $DB->get_in_or_equal(manager::open_statuses(), SQL_PARAMS_NAMED, 'ba');
        $conds = ['t.assignedto = :me'];
        $params['me'] = $userid;
        if ($p->cats) {
            [$cin, $cparams] = $DB->get_in_or_equal($p->cats, SQL_PARAMS_NAMED, 'bc');
            $conds[] = "(t.assignedto = 0 AND t.categoryid $cin)";
            $params += $cparams;
        }
        if ($p->supervisor) {
            $conds[] = 't.escalation_level >= ' . policy::LEVEL_ORANGE;
        }
        $rows = $DB->get_records_sql("SELECT t.*, a.name AS areaname, c.name AS categoryname
                                        FROM {gmk_tkt_ticket} t
                                   LEFT JOIN {gmk_tkt_area} a ON a.id = t.areaid
                                   LEFT JOIN {gmk_tkt_category} c ON c.id = t.categoryid
                                       WHERE t.status $in AND t.waiting_since > 0 AND (" . implode(' OR ', $conds) . ")
                                    ORDER BY t.waiting_since ASC", $params, 0, 60);
        if (!$rows) {
            return [];
        }
        [$tin, $tparams] = $DB->get_in_or_equal(array_keys($rows), SQL_PARAMS_NAMED, 'bt');
        $acks = [];
        foreach ($DB->get_records_select('gmk_tkt_ack', "userid = :u AND ticketid $tin", ['u' => $userid] + $tparams) as $a) {
            $acks[(int)$a->ticketid] = $a;
        }
        $requesters = manager::load_users(array_map(function($r) {
            return empty($r->confidential) ? (int)$r->userid : 0;
        }, $rows));

        $out = [];
        foreach ($rows as $t) {
            if (count($out) >= self::MAX_ALERTS) {
                break;
            }
            $mine = (int)$t->assignedto === $userid;
            $queue = (int)$t->assignedto === 0 && in_array((int)$t->categoryid, $p->cats, true);
            if (!$mine && !$queue && !manager::staff_can_view($t, $userid)) {
                continue;
            }
            $since = manager::alert_since($t);
            $ack = $acks[(int)$t->id] ?? null;
            if ($since <= 0 || ($ack && (int)$ack->timeack >= $since)) {
                continue;
            }
            $minutes = policy::waiting_minutes($t, $now);
            $level = policy::level_for($minutes);
            if (!$mine && !$queue && $level < policy::LEVEL_ORANGE) {
                continue;
            }
            if ($mine) {
                $kind = (int)$t->assigned_at >= $since ? 'assigned'
                    : ((int)$t->escalated_at >= $since && (int)$t->escalated_at > (int)$t->last_student_msg_at ? 'overdue' : 'message');
            } else {
                $kind = $queue ? 'new' : 'overdue';
            }
            $snoozed = $ack && (int)$ack->snoozekey === $since && (int)$ack->snoozeduntil > $now;
            $lockrule = $profile['lock'];
            $lock = $lockrule === 'all' || ($lockrule === 'orange' && $level >= policy::LEVEL_ORANGE);
            $requester = $requesters[(int)$t->userid] ?? null;
            $out[] = [
                'ticketid' => (int)$t->id,
                'key' => (int)$t->id . '-' . $since,
                'number' => (string)$t->number,
                'kind' => $kind,
                'areaname' => format_string((string)$t->areaname),
                'categoryname' => format_string((string)$t->categoryname),
                'who' => !empty($t->confidential) ? 'Ticket confidencial' : ($requester ? fullname($requester) : ''),
                'requesterrole' => (string)$t->requesterrole,
                'confidential' => (bool)$t->confidential,
                'waitingminutes' => $minutes,
                'semaphore' => policy::SEMAPHORES[$level],
                'level' => $level,
                'since' => $since,
                'cantake' => (int)$t->assignedto !== $userid && ((int)$t->assignedto === 0 || $p->supervisor),
                'lock' => $lock,
                'snoozed' => $snoozed,
                'snoozes' => ($ack && (int)$ack->snoozekey === $since) ? (int)$ack->snoozes : 0,
            ];
        }
        return $out;
    }

    /**
     * Supervisor mini dashboard: totals per area and the agent with most open tickets.
     */
    private static function dashboard(int $userid, stdClass $p, int $now): array {
        global $DB;
        [$w, $params] = manager::desk_where($userid, $p, 'all', '');
        $rows = $DB->get_records_sql("SELECT t.id, t.areaid, t.assignedto, t.status, t.waiting_since
                                        FROM {gmk_tkt_ticket} t JOIN {user} s ON s.id = t.userid
                                       WHERE $w", $params, 0, 3000);
        $areas = [];
        $agents = [];
        foreach ($rows as $t) {
            $aid = (int)$t->areaid;
            if (!isset($areas[$aid])) {
                $area = policy::area($aid);
                $areas[$aid] = ['name' => $area ? format_string($area->name) : '—', 'open' => 0, 'unassigned' => 0,
                    'overdue' => 0];
            }
            $areas[$aid]['open']++;
            if ((int)$t->assignedto === 0) {
                $areas[$aid]['unassigned']++;
            } else {
                $agents[(int)$t->assignedto] = ($agents[(int)$t->assignedto] ?? 0) + 1;
            }
            if (policy::level_for(policy::waiting_minutes($t, $now)) >= policy::LEVEL_RED) {
                $areas[$aid]['overdue']++;
            }
        }
        $top = null;
        if ($agents) {
            arsort($agents);
            $uid = (int)array_key_first($agents);
            $u = manager::load_users([$uid]);
            $top = ['name' => isset($u[$uid]) ? fullname($u[$uid]) : '', 'open' => $agents[$uid]];
        }
        return ['areas' => array_values($areas), 'topagent' => $top];
    }

    /**
     * "Posponer" on the full-screen lock: at most tkt_snooze_max times per alert.
     *
     * @return int snoozed until (timestamp)
     */
    public static function snooze(int $ticketid, int $userid): int {
        global $DB;
        [$ticket] = manager::require_ticket($ticketid, $userid);
        if ((int)$ticket->userid === $userid) {
            throw new moodle_exception('tkt_err_noaccess', 'local_grupomakro_core');
        }
        $since = manager::alert_since($ticket);
        if ($since <= 0) {
            return 0;
        }
        $max = config::get_int('tkt_snooze_max');
        $now = time();
        $until = $now + max(1, config::get_int('tkt_snooze_minutes')) * MINSECS;
        $row = $DB->get_record('gmk_tkt_ack', ['ticketid' => $ticketid, 'userid' => $userid]);
        $count = ($row && (int)$row->snoozekey === $since) ? (int)$row->snoozes : 0;
        if ($count >= $max) {
            throw new moodle_exception('tkt_err_nosnooze', 'local_grupomakro_core', '', $max);
        }
        if ($row) {
            $DB->update_record('gmk_tkt_ack', (object)['id' => $row->id, 'snoozekey' => $since, 'snoozes' => $count + 1,
                'snoozeduntil' => $until]);
        } else {
            $DB->insert_record('gmk_tkt_ack', (object)['ticketid' => $ticketid, 'userid' => $userid, 'timeack' => 0,
                'how' => '', 'snoozekey' => $since, 'snoozes' => 1, 'snoozeduntil' => $until]);
        }
        manager::log_event($ticketid, 'snooze', (string)$count, (string)($count + 1), $userid);
        self::invalidate($userid);
        return $until;
    }

    /**
     * Cheap per-session decision for before_footer: is this user help-desk
     * staff and/or a teacher? Re-checked every 5 minutes.
     *
     * @return array ['staff' => bool, 'teacher' => bool]
     */
    public static function session_flags(int $userid): array {
        global $SESSION;
        $now = time();
        $cached = $SESSION->gmk_support_flags ?? null;
        if (is_array($cached) && ($cached['userid'] ?? 0) === $userid && ($cached['time'] ?? 0) > $now - 300) {
            return $cached;
        }
        $flags = ['userid' => $userid, 'time' => $now, 'staff' => false, 'teacher' => false];
        try {
            $flags['staff'] = (bool)manager::profile($userid)->isstaff;
            $flags['teacher'] = manager::is_teacher($userid)
                && has_capability('local/grupomakro_core:support_create', \context_system::instance(), $userid);
        } catch (\Throwable $e) {
            // Tables not installed yet (mid-upgrade): show nothing.
        }
        $SESSION->gmk_support_flags = $flags;
        return $flags;
    }
}
