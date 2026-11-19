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
 * Dashboard administrativo: alertas escalonadas de ANS y resumen diario (§2.3).
 *
 * Idempotencia (mismo patron que support\escalation): gmk_dash_alert guarda,
 * por fuente + ref, cada nivel ya avisado y el "ancla" (since o deadline) del
 * pendiente. Un nivel se avisa una sola vez; si el pendiente sale de la cola,
 * vuelve a 0 o cambia su ancla (se reabrio), sus filas se borran y la proxima
 * vez se avisa de nuevo.
 *
 * Escalera: al cruzar un nivel, el responsable (o la cola del rol) recibe el
 * aviso; desde vencido (2) tambien los supervisores del tipo; al llegar a
 * critico (3) tambien gerencia. Un solo mensaje por persona y pasada, con la
 * lista agrupada. Las fuentes con ANS propio (tickets, cartas) se ignoran.
 *
 * Primera pasada: si dash_alerts_primed = 0 se registran los niveles actuales
 * SIN enviar nada (decision de Ramiro, §7.5) y se marca primed = 1.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\dashboard;

use context_system;
use moodle_url;
use stdClass;

defined('MOODLE_INTERNAL') || die();

class escalation {

    const LEVEL_TEXT = [1 => 'por vencer', 2 => 'vencido', 3 => 'crítico'];
    const PROVIDERS = [1 => 'dashboard_sla_warning', 2 => 'dashboard_sla_breach', 3 => 'dashboard_escalation'];
    const MAX_LINES = 15;

    /** @var array messages prepared in the last run (selftest) */
    public static $lastmessages = [];

    /**
     * One pass of the scheduled task.
     *
     * @param bool $send false = prepare the messages but do not send them (selftest)
     * @return array counters for the task log
     */
    public static function run(int $now = 0, bool $send = true): array {
        global $DB;
        $now = $now ?: time();
        $stats = ['sources' => 0, 'items' => 0, 'new' => 0, 'cleared' => 0, 'messages' => 0, 'silent' => 0, 'errors' => 0];
        self::$lastmessages = [];
        config::reset_cache();
        if (!config::get_int('dash_alerts_enabled')) {
            return $stats;
        }
        $silent = !config::get_int('dash_alerts_primed');
        $stats['silent'] = (int)$silent;

        $inserts = [];
        $deletes = [];
        $bucket = [];
        $queuecache = [];
        $supcache = [];
        $gerencia = null;

        foreach (registry::sources() as $key => $s) {
            if ($s->external_sla()) {
                continue;
            }
            try {
                $items = dashboard::source_items($s, 0, 'all', $now);
            } catch (\Throwable $e) {
                // Unknown state: keep its rows untouched.
                $stats['errors']++;
                if (function_exists('mtrace')) {
                    mtrace('dashboard_escalate: source ' . $key . ' failed: ' . $e->getMessage());
                }
                continue;
            }
            $stats['sources']++;
            $sent = [];
            foreach ($DB->get_records('gmk_dash_alert', ['srckey' => $key]) as $row) {
                $ref = (string)$row->itemref;
                $sent[$ref]['ids'][] = (int)$row->id;
                $sent[$ref]['level'] = max($sent[$ref]['level'] ?? 0, (int)$row->alertlevel);
                $sent[$ref]['anchor'] = (int)$row->anchor;
            }
            $seen = [];
            foreach ($items as $it) {
                $ref = (string)$it->ref;
                $seen[$ref] = true;
                $stats['items']++;
                $anchor = (int)$it->since ?: (int)$it->deadline;
                $prev = $sent[$ref] ?? null;
                if ($prev && ($prev['anchor'] !== $anchor || $it->level <= 0)) {
                    $deletes = array_merge($deletes, $prev['ids']);
                    $stats['cleared']++;
                    $prev = null;
                }
                $already = $prev ? (int)$prev['level'] : 0;
                if ($it->level <= $already) {
                    continue;
                }
                $inserts[] = (object)['srckey' => $key, 'itemref' => $ref, 'slatype' => (string)$it->type, 'alertlevel' => (int)$it->level,
                    'anchor' => $anchor, 'timesent' => $now, 'silent' => (int)$silent];
                $stats['new']++;
                if ($silent) {
                    continue;
                }
                $cfg = config::sla((string)$it->type) ?: [];
                $recipients = [];
                if ((int)$it->owner > 0) {
                    $recipients[] = (int)$it->owner;
                } else {
                    if (!isset($queuecache[$key])) {
                        $queuecache[$key] = $s->queue_users();
                    }
                    $recipients = array_merge($recipients, $queuecache[$key]);
                }
                if ($it->level >= sla::LEVEL_BREACH) {
                    $spec = (string)($cfg['sup'] ?? '');
                    if (!isset($supcache[$spec])) {
                        $supcache[$spec] = self::resolve_people($spec);
                    }
                    $recipients = array_merge($recipients, $supcache[$spec]);
                }
                if ($it->level >= sla::LEVEL_CRIT && $already < sla::LEVEL_CRIT) {
                    if ($gerencia === null) {
                        $gerencia = self::gerencia();
                    }
                    $recipients = array_merge($recipients, $gerencia);
                }
                foreach (array_unique($recipients) as $rid) {
                    $bucket[$rid][] = [$it, $s->label()];
                }
            }
            foreach ($sent as $ref => $prev) {
                if (!isset($seen[$ref])) {
                    $deletes = array_merge($deletes, $prev['ids']);
                    $stats['cleared']++;
                }
            }
        }

        $transaction = $DB->start_delegated_transaction();
        if ($deletes) {
            foreach (array_chunk(array_values(array_unique($deletes)), 500) as $chunk) {
                $DB->delete_records_list('gmk_dash_alert', 'id', $chunk);
            }
        }
        if ($inserts) {
            $DB->insert_records('gmk_dash_alert', $inserts);
        }
        $transaction->allow_commit();
        // A source that failed would otherwise "speak" everything on the next pass: stay silent until all were read.
        if ($silent && $stats['errors'] === 0) {
            config::set('dash_alerts_primed', 1);
        }

        foreach ($bucket as $rid => $entries) {
            $msg = self::compose((int)$rid, $entries);
            self::$lastmessages[] = $msg;
            if ($send && self::send($msg)) {
                $stats['messages']++;
            }
        }
        if (!$send) {
            $stats['messages'] = count(self::$lastmessages);
        }
        return $stats;
    }

    /**
     * Users from a supervisor spec: role shortnames, "wellness:<rolekey>" or "user:<id>", comma separated.
     *
     * @return int[]
     */
    public static function resolve_people(string $spec): array {
        global $DB;
        $ids = [];
        $context = context_system::instance();
        foreach (array_filter(array_map('trim', explode(',', $spec))) as $part) {
            if (strpos($part, 'user:') === 0) {
                $ids[] = (int)substr($part, 5);
            } else if (strpos($part, 'wellness:') === 0) {
                $ids = array_merge($ids, array_map('intval', $DB->get_fieldset_select('gmk_wellness_staff_role', 'userid',
                    'rolekey = :k AND active = 1 AND userid > 0', ['k' => substr($part, 9)])));
            } else if ($role = $DB->get_record('role', ['shortname' => $part], 'id')) {
                // Role holders at system level (where the gmk_* roles are assigned).
                $ids = array_merge($ids, array_map('intval', $DB->get_fieldset_sql(
                    "SELECT DISTINCT ra.userid
                       FROM {role_assignments} ra
                       JOIN {user} u ON u.id = ra.userid AND u.deleted = 0 AND u.suspended = 0
                      WHERE ra.roleid = :r AND ra.contextid = :c",
                    ['r' => (int)$role->id, 'c' => (int)$context->id])));
            }
        }
        return array_values(array_unique(array_filter($ids)));
    }

    /** Gerencia: holders of gmk_gerencia plus dashboard_receive_escalations. */
    public static function gerencia(): array {
        $ids = self::resolve_people('gmk_gerencia');
        $users = get_users_by_capability(context_system::instance(), 'local/grupomakro_core:dashboard_receive_escalations',
            'u.id', '', '', '', '', '', false);
        foreach ($users as $u) {
            $ids[] = (int)$u->id;
        }
        return array_values(array_unique($ids));
    }

    /**
     * @param array $entries [[item, source label], ...]
     */
    public static function compose(int $userid, array $entries): stdClass {
        $max = 1;
        $byname = [1 => 0, 2 => 0, 3 => 0];
        foreach ($entries as [$it]) {
            $max = max($max, (int)$it->level);
            $byname[min(3, (int)$it->level)]++;
        }
        usort($entries, function($a, $b) {
            return $b[0]->level <=> $a[0]->level;
        });
        $parts = [];
        foreach ([3, 2, 1] as $l) {
            if ($byname[$l]) {
                $parts[] = $byname[$l] . ' ' . self::LEVEL_TEXT[$l];
            }
        }
        $subject = 'Pendientes con ANS: ' . implode(', ', $parts);
        $lines = [];
        foreach (array_slice($entries, 0, self::MAX_LINES) as [$it, $label]) {
            $due = (int)$it->due > 0 ? ' (vence ' . userdate((int)$it->due, '%d/%m %H:%M', \core_date::get_server_timezone()) . ')' : '';
            $lines[] = '• [' . self::LEVEL_TEXT[min(3, (int)$it->level)] . '] ' . $label . ': ' . $it->title . $due;
        }
        if (count($entries) > self::MAX_LINES) {
            $lines[] = '… y ' . (count($entries) - self::MAX_LINES) . ' más.';
        }
        $url = (new moodle_url('/local/grupomakro_core/pages/admin_dashboard.php'))->out(false);
        $body = implode("\n", $lines) . "\n\nAbrir Mis pendientes: " . $url;
        return (object)['userid' => $userid, 'provider' => self::PROVIDERS[$max], 'subject' => $subject, 'body' => $body,
            'url' => $url, 'count' => count($entries)];
    }

    public static function send(stdClass $m): bool {
        global $CFG;
        require_once($CFG->dirroot . '/message/lib.php');
        try {
            $to = \core_user::get_user($m->userid);
            if (!$to || !empty($to->deleted) || !empty($to->suspended)) {
                return false;
            }
            $msg = new \core\message\message();
            $msg->component = 'local_grupomakro_core';
            $msg->name = $m->provider;
            $msg->userfrom = \core_user::get_noreply_user();
            $msg->userto = $to;
            $msg->subject = $m->subject;
            $msg->fullmessage = $m->body;
            $msg->fullmessageformat = FORMAT_PLAIN;
            $msg->fullmessagehtml = nl2br(s($m->body));
            $msg->smallmessage = $m->subject;
            $msg->notification = 1;
            $msg->contexturl = $m->url;
            $msg->contexturlname = 'Mis pendientes';
            $msg->courseid = SITEID;
            return (bool)message_send($msg);
        } catch (\Throwable $e) {
            if (function_exists('gmk_log')) {
                gmk_log('dashboard notify ' . $m->provider . ' to ' . $m->userid . ' failed: ' . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Daily digest: once per business day from dash_digest_hour, to each user of
     * the dashboard with something pending in "lo mio".
     *
     * @return array counters
     */
    public static function digest(int $now = 0, bool $send = true): array {
        $now = $now ?: time();
        $stats = ['users' => 0, 'messages' => 0, 'skipped' => ''];
        self::$lastmessages = [];
        config::reset_cache();
        if (!config::get_int('dash_digest_enabled')) {
            $stats['skipped'] = 'apagado';
            return $stats;
        }
        $tz = \core_date::get_server_timezone();
        $today = userdate($now, '%Y-%m-%d', $tz);
        if ((int)userdate($now, '%H', $tz) < config::get_int('dash_digest_hour')) {
            $stats['skipped'] = 'antes de la hora';
            return $stats;
        }
        if ((string)config::get('dash_digest_last') === $today) {
            $stats['skipped'] = 'ya enviado hoy';
            return $stats;
        }
        if (!\local_grupomakro_core\local\support\business_hours::is_open(sla::days_area(), $now)) {
            $stats['skipped'] = 'día no hábil';
            return $stats;
        }
        // Marked first: a crash halfway must not send twice.
        config::set('dash_digest_last', $today);
        $users = get_users_by_capability(context_system::instance(), 'local/grupomakro_core:view_admin_dashboard',
            'u.id', '', '', '', '', '', false);
        $url = (new moodle_url('/local/grupomakro_core/pages/admin_dashboard.php'))->out(false);
        foreach ($users as $u) {
            $stats['users']++;
            $data = dashboard::build((int)$u->id, 'mine', $now);
            if ($data['totals']['total'] <= 0) {
                continue;
            }
            $lines = [];
            foreach ($data['cards'] as $c) {
                if ($c['counts']['total'] <= 0) {
                    continue;
                }
                $extra = [];
                if ($c['counts']['critical']) {
                    $extra[] = $c['counts']['critical'] . ' crítico(s)';
                }
                if ($c['counts']['breached']) {
                    $extra[] = $c['counts']['breached'] . ' vencido(s)';
                }
                if ($c['counts']['warning']) {
                    $extra[] = $c['counts']['warning'] . ' por vencer';
                }
                $lines[] = '• ' . $c['label'] . ': ' . $c['counts']['total'] . ($extra ? ' (' . implode(', ', $extra) . ')' : '');
            }
            $t = $data['totals'];
            $overdue = $t['breached'] + $t['critical'];
            $m = (object)['userid' => (int)$u->id, 'provider' => 'dashboard_daily_digest',
                'subject' => 'Tus pendientes de hoy: ' . $t['total'] . ($overdue ? ' (' . $overdue . ' vencidos)' : ''),
                'body' => implode("\n", $lines) . "\n\nAbrir Mis pendientes: " . $url, 'url' => $url, 'count' => $t['total']];
            self::$lastmessages[] = $m;
            if ($send && self::send($m)) {
                $stats['messages']++;
            }
        }
        if (!$send) {
            $stats['messages'] = count(self::$lastmessages);
        }
        return $stats;
    }
}
