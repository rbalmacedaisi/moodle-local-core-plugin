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
 * Mesa de ayuda (fase 2): escalera de escalamiento, escalamiento por falta de
 * acuse y resumen diario (§6.3, §6.4, §8.5 capa 6 del plan).
 *
 * Idempotencia: gmk_tkt_ticket.escalation_level guarda el ultimo nivel ya
 * procesado de la espera actual (vuelve a 0 cuando el personal responde o se
 * reabre). Un nivel solo se procesa al cruzarlo; la repeticion en rojo se mide
 * con escalated_at. El aviso por falta de acuse deja un evento 'noack' con el
 * instante en que empezo la alerta y no se repite para esa misma alerta.
 * Todo lee la configuracion en cada pasada.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\support;

use stdClass;

defined('MOODLE_INTERNAL') || die();

class escalation {

    const LEVEL_LABELS = [1 => 'verde con aviso', 2 => 'amarillo', 3 => 'naranja', 4 => 'rojo'];

    /**
     * One pass of the scheduled task.
     *
     * @return array counters for the task log
     */
    public static function run(int $now = 0): array {
        global $DB;
        $now = $now ?: time();
        $stats = ['tickets' => 0, 'escalated' => 0, 'repeated' => 0, 'noack' => 0, 'errors' => 0];
        if (!config::get_int('tkt_escalation_enabled')) {
            return $stats;
        }
        policy::reset_caches();
        [$in, $params] = $DB->get_in_or_equal(manager::open_statuses(), SQL_PARAMS_NAMED, 'es');
        $rows = $DB->get_records_select('gmk_tkt_ticket', "status $in AND waiting_since > 0", $params, 'waiting_since ASC',
            '*', 0, 2000);
        foreach ($rows as $ticket) {
            $stats['tickets']++;
            try {
                $r = self::process_ticket($ticket, $now);
                if ($r === 'escalated') {
                    $stats['escalated']++;
                } else if ($r === 'repeated') {
                    $stats['repeated']++;
                }
                if (self::check_ack($ticket, $now)) {
                    $stats['noack']++;
                }
            } catch (\Throwable $e) {
                $stats['errors']++;
                if (function_exists('mtrace')) {
                    mtrace('support_escalate: ticket ' . $ticket->id . ' failed: ' . $e->getMessage());
                }
            }
        }
        manager::flush_notifications();
        return $stats;
    }

    /**
     * Applies the ladder to one ticket.
     *
     * @return string 'escalated' | 'repeated' | 'none'
     */
    public static function process_ticket(stdClass $ticket, int $now): string {
        global $DB;
        $minutes = policy::waiting_minutes($ticket, $now);
        $target = policy::level_for($minutes);
        $current = (int)$ticket->escalation_level;

        if ($target > $current) {
            // Every level crossed since the last pass gets its side effects; the
            // notifications are merged so a long outage does not flood anyone.
            $merged = [];
            for ($level = $current + 1; $level <= $target; $level++) {
                foreach (policy::escalation_actions($level) as $action => $on) {
                    if ($on) {
                        $merged[$action] = $level;
                    }
                }
            }
            $mark = manager::outbox_size();
            $transaction = $DB->start_delegated_transaction();
            try {
                $done = self::apply_actions($ticket, $merged, $minutes, $target);
                manager::update_ticket($ticket, ['escalation_level' => $target, 'escalated_at' => $now]);
                manager::log_event((int)$ticket->id, 'escalation', (string)$current, (string)$target, 0,
                    ['minutes' => $minutes, 'actions' => $done]);
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                // Nothing of this ticket is kept, so none of its notices may go out.
                manager::outbox_truncate($mark);
                $transaction->rollback($e);
            }
            return 'escalated';
        }

        $repeat = config::get_int('tkt_red_repeat');
        if ($current >= policy::LEVEL_RED && $target >= policy::LEVEL_RED && $repeat > 0
                && (int)$ticket->escalated_at > 0
                && business_hours::elapsed((int)$ticket->escalated_at, $now, policy::area((int)$ticket->areaid)) >= $repeat) {
            $actions = policy::escalation_actions(policy::LEVEL_RED);
            $done = [];
            if (!empty($actions['gerencia'])) {
                $n = self::notify_group('support_escalation', manager::gerencia_for($ticket), $ticket, $minutes, $target);
                $done[] = 'gerencia:' . $n;
            }
            $transaction = $DB->start_delegated_transaction();
            manager::update_ticket($ticket, ['escalated_at' => $now]);
            manager::log_event((int)$ticket->id, 'escalation', (string)$current, (string)$current, 0,
                ['minutes' => $minutes, 'actions' => $done, 'repeat' => true]);
            $transaction->allow_commit();
            return 'repeated';
        }
        return 'none';
    }

    /**
     * @param array $actions action => level that asked for it
     * @return string[] what was done, for the event log
     */
    private static function apply_actions(stdClass $ticket, array $actions, int $minutes, int $level): array {
        global $DB;
        $done = [];
        $category = $DB->get_record('gmk_tkt_category', ['id' => (int)$ticket->categoryid]);

        if (!empty($actions['assign']) && (int)$ticket->assignedto === 0 && $category) {
            $agent = manager::pick_agent($category, true);
            if ($agent > 0) {
                manager::apply_assignment($ticket, $agent, 0, 'escalation');
                manager::system_message($ticket, 'assigned', manager::template_vars($ticket));
                manager::queue_notification('support_assigned', [$agent], $ticket, null);
                $done[] = 'asignado';
            }
        }
        if (!empty($actions['requeue']) && (int)$ticket->assignedto > 0 && !manager::is_available((int)$ticket->assignedto)) {
            manager::log_event((int)$ticket->id, 'unassigned', (string)$ticket->assignedto, '0', 0, ['how' => 'escalation']);
            $update = ['assignedto' => 0];
            if ($ticket->status === manager::S_ASSIGNED) {
                manager::set_status($ticket, manager::S_NEW, 0, $update);
            } else {
                manager::update_ticket($ticket, $update);
            }
            if ($category) {
                manager::queue_notification('support_new_ticket', manager::category_staff($category), $ticket, null);
            }
            $done[] = 'a la cola (agente ausente)';
        }
        if (!empty($actions['priority']) && (int)$ticket->priority < manager::PRIORITY_HIGH) {
            manager::log_event((int)$ticket->id, 'priority', (string)$ticket->priority, (string)manager::PRIORITY_HIGH, 0,
                ['how' => 'escalation']);
            manager::update_ticket($ticket, ['priority' => manager::PRIORITY_HIGH]);
            $done[] = 'prioridad alta';
        }
        if (!empty($actions['student'])) {
            if (manager::system_message($ticket, 'sla_warning', manager::template_vars($ticket))) {
                $done[] = 'mensaje al solicitante';
            }
        }
        if (!empty($actions['agent'])) {
            $recipients = (int)$ticket->assignedto > 0 ? [(int)$ticket->assignedto]
                : ($category ? manager::category_staff($category) : []);
            $done[] = 'agente:' . self::notify_group('support_sla_warning', $recipients, $ticket, $minutes, $level);
        }
        if (!empty($actions['supervisors'])) {
            $done[] = 'supervisores:' . self::notify_group('support_sla_breach', manager::supervisors_for($ticket), $ticket,
                $minutes, $level);
        }
        if (!empty($actions['gerencia'])) {
            $done[] = 'gerencia:' . self::notify_group('support_escalation', manager::gerencia_for($ticket), $ticket,
                $minutes, $level);
        }
        return $done;
    }

    private static function notify_group(string $provider, array $recipients, stdClass $ticket, int $minutes, int $level): int {
        $recipients = array_values(array_unique(array_filter(array_map('intval', $recipients))));
        if ($recipients) {
            manager::queue_notification($provider, $recipients, $ticket, null, [
                'minutes' => self::format_minutes($minutes),
                'level' => self::LEVEL_LABELS[$level] ?? (string)$level,
            ]);
        }
        return count($recipients);
    }

    /**
     * §8.5 capa 6: nobody responsible opened or took the ticket within
     * tkt_ack_minutes business minutes of the alert: tell the backups (or the
     * supervisors when there is none) and, if configured, the site admins.
     *
     * @return bool whether an alert was sent now
     */
    public static function check_ack(stdClass $ticket, int $now): bool {
        global $DB;
        $limit = config::get_int('tkt_ack_minutes');
        $since = manager::alert_since($ticket);
        if ($limit <= 0 || $since <= 0) {
            return false;
        }
        $area = policy::area((int)$ticket->areaid);
        if (business_hours::elapsed($since, $now, $area) < $limit) {
            return false;
        }
        $category = $DB->get_record('gmk_tkt_category', ['id' => (int)$ticket->categoryid]);
        $responsible = (int)$ticket->assignedto > 0 ? [(int)$ticket->assignedto]
            : ($category ? manager::category_staff($category) : []);
        if ($responsible) {
            [$in, $params] = $DB->get_in_or_equal($responsible, SQL_PARAMS_NAMED, 'ak');
            $params += ['t' => (int)$ticket->id, 's' => $since];
            if ($DB->record_exists_select('gmk_tkt_ack', "ticketid = :t AND timeack >= :s AND userid $in", $params)) {
                return false;
            }
        }
        $done = $DB->record_exists_select('gmk_tkt_event',
            "ticketid = :t AND eventtype = 'noack' AND " . $DB->sql_compare_text('newvalue') . " = :v",
            ['t' => (int)$ticket->id, 'v' => (string)$since]);
        if ($done) {
            return false;
        }

        $recipients = [];
        if ($responsible) {
            [$in, $params] = $DB->get_in_or_equal($responsible, SQL_PARAMS_NAMED, 'bk');
            $params['cat'] = (int)$ticket->categoryid;
            $recipients = array_map('intval', $DB->get_fieldset_select('gmk_tkt_backup', 'backupuserid',
                "userid $in AND (categoryid = 0 OR categoryid = :cat)", $params));
        }
        if (!$recipients) {
            $recipients = manager::supervisors_for($ticket);
        }
        if (config::get_int('tkt_ack_admins')) {
            $recipients = array_merge($recipients, array_map(function($u) {
                return (int)$u->id;
            }, get_admins()));
        }
        $recipients = array_diff(manager::visible_to($recipients, $ticket), $responsible);

        manager::log_event((int)$ticket->id, 'noack', '', (string)$since, 0,
            ['recipients' => count($recipients), 'minutes' => $limit]);
        if ($recipients) {
            manager::queue_notification('support_noack', array_values($recipients), $ticket, null, [
                'minutes' => self::format_minutes(business_hours::elapsed($since, $now, $area)),
            ]);
        }
        return true;
    }

    public static function format_minutes(int $minutes): string {
        if ($minutes < 60) {
            return $minutes . ' min';
        }
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return $h . ' h' . ($m ? ' ' . $m . ' min' : '');
    }

    // ---------------------------------------------------------------------
    // Daily digest (§6.4)
    // ---------------------------------------------------------------------

    /**
     * Sends the digest once a day, at or after tkt_digest_hour (server time).
     *
     * @return int recipients (0 when it was not due)
     */
    public static function maybe_send_digest(int $now = 0, bool $force = false): int {
        $now = $now ?: time();
        if (!config::get_int('tkt_digest_enabled') && !$force) {
            return 0;
        }
        $local = (new \DateTimeImmutable('@' . $now))->setTimezone(\core_date::get_server_timezone_object());
        $today = $local->format('Y-m-d');
        if (!$force && ((int)$local->format('G') < config::get_int('tkt_digest_hour')
                || get_config('local_grupomakro_core', 'tkt_digest_last') === $today)) {
            return 0;
        }
        $recipients = array_values(array_unique(array_merge(manager::gerencia_for(null), self::all_supervisors())));
        $sent = 0;
        foreach ($recipients as $uid) {
            $to = \core_user::get_user($uid);
            if (!$to || !empty($to->deleted) || !empty($to->suspended)) {
                continue;
            }
            $digest = self::build_digest($uid, $now);
            self::send_digest($to, $digest, $local);
            $sent++;
        }
        if (!$force) {
            set_config('tkt_digest_last', $today, 'local_grupomakro_core');
        }
        manager::log_event(0, 'daily_digest', '', $today, 0, ['recipients' => $sent]);
        return $sent;
    }

    private static function all_supervisors(): array {
        global $DB;
        $ids = array_map('intval', $DB->get_fieldset_select('gmk_tkt_person', 'userid', "kind = 'supervisor'"));
        $users = get_users_by_capability(\context_system::instance(), 'local/grupomakro_core:support_supervise', 'u.id');
        return array_values(array_unique(array_merge($ids, array_map('intval', array_keys($users)))));
    }

    /**
     * Digest data as $userid may see it (confidential tickets only count, never show names).
     */
    public static function build_digest(int $userid, int $now): array {
        global $DB;
        [$in, $params] = $DB->get_in_or_equal(manager::open_statuses(), SQL_PARAMS_NAMED, 'dg');
        $open = $DB->get_records_select('gmk_tkt_ticket', "status $in", $params, 'waiting_since ASC', '*', 0, 5000);
        $areas = $DB->get_records('gmk_tkt_area', null, 'sortorder, id', 'id, name, hours');
        $byarea = [];
        foreach ($areas as $a) {
            $byarea[(int)$a->id] = ['name' => format_string($a->name), 'open' => 0, 'unassigned' => 0, 'overdue' => 0];
        }
        $waiting = [];
        foreach ($open as $t) {
            $aid = (int)$t->areaid;
            if (!isset($byarea[$aid])) {
                $byarea[$aid] = ['name' => '—', 'open' => 0, 'unassigned' => 0, 'overdue' => 0];
            }
            $byarea[$aid]['open']++;
            if ((int)$t->assignedto === 0) {
                $byarea[$aid]['unassigned']++;
            }
            $m = policy::waiting_minutes($t, $now);
            if ($m > 0 && policy::level_for($m) >= policy::LEVEL_RED) {
                $byarea[$aid]['overdue']++;
            }
            if ($m > 0) {
                $waiting[] = [$m, $t];
            }
        }
        usort($waiting, function($a, $b) {
            return $b[0] <=> $a[0];
        });
        $top = [];
        foreach ($waiting as [$m, $t]) {
            if (count($top) >= 10) {
                break;
            }
            if (!manager::staff_can_view($t, $userid)) {
                continue;
            }
            $student = !empty($t->confidential) ? null : \core_user::get_user((int)$t->userid);
            $agent = (int)$t->assignedto > 0 ? \core_user::get_user((int)$t->assignedto) : null;
            $top[] = [
                'number' => (string)$t->number,
                'area' => $byarea[(int)$t->areaid]['name'] ?? '',
                'who' => !empty($t->confidential) ? 'Ticket confidencial' : ($student ? fullname($student) : ''),
                'agent' => $agent ? fullname($agent) : 'Sin asignar',
                'minutes' => $m,
            ];
        }

        // Yesterday (server day): first response time in business minutes and ratings.
        $local = (new \DateTimeImmutable('@' . $now))->setTimezone(\core_date::get_server_timezone_object());
        $daystart = $local->setTime(0, 0)->modify('-1 day')->getTimestamp();
        $dayend = $local->setTime(0, 0)->getTimestamp();
        $answered = $DB->get_records_select('gmk_tkt_ticket', 'first_response_at >= :s AND first_response_at < :e',
            ['s' => $daystart, 'e' => $dayend], '', 'id, areaid, timecreated, first_response_at');
        $sum = 0;
        foreach ($answered as $t) {
            $sum += business_hours::elapsed((int)$t->timecreated, (int)$t->first_response_at, policy::area((int)$t->areaid));
        }
        $rating = $DB->get_record_sql('SELECT COUNT(1) AS n, AVG(rating) AS avg FROM {gmk_tkt_ticket}
                                        WHERE rating > 0 AND rated_at >= :s', ['s' => $now - 30 * DAYSECS]);
        $ratingday = $DB->get_record_sql('SELECT COUNT(1) AS n, AVG(rating) AS avg FROM {gmk_tkt_ticket}
                                           WHERE rating > 0 AND rated_at >= :s AND rated_at < :e',
            ['s' => $daystart, 'e' => $dayend]);
        return [
            'areas' => array_values(array_filter($byarea, function($a) {
                return $a['open'] > 0;
            })),
            'top' => $top,
            'answered' => count($answered),
            'avgfirst' => $answered ? (int)round($sum / count($answered)) : null,
            'rating30' => (int)$rating->n ? round((float)$rating->avg, 1) : null,
            'rating30n' => (int)$rating->n,
            'ratingday' => (int)$ratingday->n ? round((float)$ratingday->avg, 1) : null,
            'ratingdayn' => (int)$ratingday->n,
        ];
    }

    private static function send_digest(stdClass $to, array $d, \DateTimeImmutable $local): void {
        global $CFG;
        require_once($CFG->dirroot . '/message/lib.php');
        $url = (new \moodle_url('/local/grupomakro_core/pages/support_desk.php'))->out(false);
        $lines = ['Mesa de ayuda · resumen del ' . $local->format('d/m/Y'), ''];
        $html = '<h3>Mesa de ayuda · resumen del ' . s($local->format('d/m/Y')) . '</h3>';
        $totals = ['open' => 0, 'unassigned' => 0, 'overdue' => 0];
        $html .= '<table border="1" cellpadding="4" cellspacing="0" style="border-collapse:collapse">'
            . '<tr><th>Área</th><th>Abiertos</th><th>Sin asignar</th><th>Vencidos (rojo)</th></tr>';
        foreach ($d['areas'] as $a) {
            $lines[] = sprintf('%s: %d abiertos, %d sin asignar, %d vencidos', $a['name'], $a['open'], $a['unassigned'],
                $a['overdue']);
            $html .= '<tr><td>' . s($a['name']) . '</td><td>' . $a['open'] . '</td><td>' . $a['unassigned'] . '</td><td>'
                . $a['overdue'] . '</td></tr>';
            foreach ($totals as $k => $v) {
                $totals[$k] += $a[$k];
            }
        }
        $html .= '<tr><th>Total</th><th>' . $totals['open'] . '</th><th>' . $totals['unassigned'] . '</th><th>'
            . $totals['overdue'] . '</th></tr></table>';
        if (!$d['areas']) {
            $lines[] = 'No hay tickets abiertos.';
            $html .= '<p>No hay tickets abiertos.</p>';
        }
        $lines[] = '';
        if ($d['top']) {
            $lines[] = 'Los que más llevan esperando (minutos hábiles):';
            $html .= '<h4>Los que más llevan esperando (minutos hábiles)</h4><ol>';
            foreach ($d['top'] as $t) {
                $text = $t['number'] . ' · ' . $t['area'] . ' · ' . $t['who'] . ' · ' . $t['agent'] . ' · '
                    . self::format_minutes($t['minutes']);
                $lines[] = '- ' . $text;
                $html .= '<li>' . s($text) . '</li>';
            }
            $html .= '</ol>';
        }
        $first = $d['avgfirst'] === null ? 'sin tickets respondidos ayer'
            : self::format_minutes($d['avgfirst']) . ' hábiles (' . $d['answered'] . ' tickets)';
        $rating = ($d['ratingday'] === null ? 'ayer sin calificaciones' : 'ayer ' . $d['ratingday'] . ' (' . $d['ratingdayn'] . ')')
            . '; últimos 30 días ' . ($d['rating30'] === null ? 'sin calificaciones' : $d['rating30'] . ' (' . $d['rating30n'] . ')');
        $lines[] = 'Primera respuesta promedio de ayer: ' . $first;
        $lines[] = 'Calificación promedio: ' . $rating;
        $lines[] = '';
        $lines[] = 'Bandeja: ' . $url;
        $html .= '<p>Primera respuesta promedio de ayer: <b>' . s($first) . '</b><br>Calificación promedio: <b>' . s($rating)
            . '</b></p><p><a href="' . s($url) . '">Abrir la bandeja</a></p>';

        $msg = new \core\message\message();
        $msg->component = 'local_grupomakro_core';
        $msg->name = 'support_daily_digest';
        $msg->userfrom = \core_user::get_noreply_user();
        $msg->userto = $to;
        $msg->subject = 'Mesa de ayuda: resumen diario ' . $local->format('d/m/Y') . ' · ' . $totals['open'] . ' abiertos, '
            . $totals['overdue'] . ' vencidos';
        $msg->fullmessage = implode("\n", $lines);
        $msg->fullmessageformat = FORMAT_PLAIN;
        $msg->fullmessagehtml = $html;
        $msg->smallmessage = $msg->subject;
        $msg->notification = 1;
        $msg->contexturl = $url;
        $msg->contexturlname = 'Mesa de ayuda';
        $msg->courseid = SITEID;
        message_send($msg);
    }
}
