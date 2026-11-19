<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Cartas (20261001130): metricas de pendientes y ANS para el dashboard
 * administrativo (otra sesion lo consume; contrato en
 * docs/planning/PLAN_CARTAS_OPTIMIZACION.md §4.3). Solo lectura.
 *
 * El nivel de cada carta es el que guarda la tarea letters_sla (sla_level):
 * el dashboard no recalcula reglas.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\letters;

use context_system;
use local_grupomakro_core\local\support\business_hours;

defined('MOODLE_INTERNAL') || die();

class metrics {

    /** Dashboard item type per letter status. */
    const ITEM_TYPES = [
        'pendiente_gestion' => 'elaborar',
        'pendiente_recoleccion' => 'sin_recoger',
        'pagada' => 'atascada',
        'solicitada' => 'atascada',
    ];

    /**
     * Pending letters of $userid ('mine') or of the whole team ('team', only for
     * managerequests). Contract with the admin dashboard.
     *
     * @return array ['counts' => [total, warning, breached, critical], 'items' => [...]]
     */
    public static function pending_for_staff(int $userid, string $scope = 'mine', int $limit = 3): array {
        global $DB;
        $empty = ['counts' => ['total' => 0, 'warning' => 0, 'breached' => 0, 'critical' => 0], 'items' => []];
        [$in, $params] = $DB->get_in_or_equal(array_keys(self::ITEM_TYPES), SQL_PARAMS_NAMED, 'st');
        $where = "r.status $in";
        if ($scope === 'team') {
            if (!has_capability('local/grupomakro_core:managerequests', context_system::instance(), $userid)) {
                return $empty;
            }
        } else {
            $where .= ' AND r.assignedto = :u';
            $params['u'] = $userid;
        }
        $counts = $DB->get_record_sql("SELECT COUNT(1) AS total,
                SUM(CASE WHEN r.sla_level = 1 THEN 1 ELSE 0 END) AS warning,
                SUM(CASE WHEN r.sla_level = 2 THEN 1 ELSE 0 END) AS breached,
                SUM(CASE WHEN r.sla_level >= 3 THEN 1 ELSE 0 END) AS critical
              FROM {gmk_letter_request} r WHERE $where", $params);
        $out = $empty;
        foreach (['total', 'warning', 'breached', 'critical'] as $k) {
            $out['counts'][$k] = (int)($counts->$k ?? 0);
        }
        if ($limit <= 0 || $out['counts']['total'] === 0) {
            return $out;
        }
        $rows = $DB->get_records_sql("SELECT r.id, r.userid, r.status, r.status_since, r.timemodified, r.due_at,
                       r.sla_level, r.assignedto, r.legacy, t.name AS typename
                  FROM {gmk_letter_request} r
                  JOIN {gmk_letter_type} t ON t.id = r.lettertypeid
                 WHERE $where
              ORDER BY r.sla_level DESC, CASE WHEN r.due_at = 0 THEN 1 ELSE 0 END, r.due_at ASC, r.id ASC",
            $params, 0, $limit);
        $names = manager::user_names(array_map(function($r) {
            return (int)$r->userid;
        }, $rows));
        $labels = manager::get_status_labels();
        foreach ($rows as $r) {
            $out['items'][] = [
                'type' => self::ITEM_TYPES[$r->status] ?? 'elaborar',
                'ref' => 'CARTA-' . (int)$r->id,
                'title' => format_string((string)$r->typename) . ' · ' . ($names[(int)$r->userid] ?? ''),
                'subtitle' => ($labels[$r->status] ?? $r->status) . (!empty($r->legacy) ? ' · confirmar si ya se gestionó' : ''),
                'since' => (int)$r->status_since ?: (int)$r->timemodified,
                'due' => (int)$r->due_at,
                'level' => min(3, (int)$r->sla_level),
                'owner_userid' => (int)$r->assignedto > 0 ? (int)$r->assignedto : null,
                'url' => (new \moodle_url('/local/grupomakro_core/pages/letter_desk.php', ['request' => (int)$r->id]))
                    ->out(false),
            ];
        }
        return $out;
    }

    /**
     * One row per owner (plus "sin asignar") with their open letters and ANS.
     *
     * @param array $filters lettertypeid, status, roleshortname
     * @return array
     */
    public static function pending_by_owner(array $filters = [], int $now = 0): array {
        global $DB;
        $now = $now ?: time();
        [$in, $params] = $DB->get_in_or_equal(team::OWNED_STATUSES, SQL_PARAMS_NAMED, 'st');
        $where = "r.status $in";
        if (!empty($filters['lettertypeid'])) {
            $where .= ' AND r.lettertypeid = :lt';
            $params['lt'] = (int)$filters['lettertypeid'];
        }
        if (!empty($filters['status'])) {
            $where .= ' AND r.status = :fs';
            $params['fs'] = (string)$filters['status'];
        }
        $rows = $DB->get_records_sql("SELECT r.id, r.status, r.assignedto, r.sla_level, r.status_since, r.timemodified
                                         FROM {gmk_letter_request} r WHERE $where", $params);
        $area = team::area();
        $owners = [];
        foreach ($rows as $r) {
            $uid = (int)$r->assignedto;
            if (!isset($owners[$uid])) {
                $owners[$uid] = ['userid' => $uid, 'total' => 0, 'by_status' => [], 'on_time' => 0, 'due_soon' => 0,
                    'overdue' => 0, 'critical' => 0, 'oldest_minutes' => 0, 'sum' => 0];
            }
            $o = &$owners[$uid];
            $o['total']++;
            $o['by_status'][$r->status] = ($o['by_status'][$r->status] ?? 0) + 1;
            $level = (int)$r->sla_level;
            $key = $level >= 3 ? 'critical' : ($level === 2 ? 'overdue' : ($level === 1 ? 'due_soon' : 'on_time'));
            $o[$key]++;
            $minutes = business_hours::elapsed((int)$r->status_since ?: (int)$r->timemodified, $now, $area);
            $o['oldest_minutes'] = max($o['oldest_minutes'], $minutes);
            $o['sum'] += $minutes;
            unset($o);
        }
        // Team members with nothing pending also appear (load 0).
        foreach (team::agents() as $uid) {
            if (!isset($owners[$uid])) {
                $owners[$uid] = ['userid' => $uid, 'total' => 0, 'by_status' => [], 'on_time' => 0, 'due_soon' => 0,
                    'overdue' => 0, 'critical' => 0, 'oldest_minutes' => 0, 'sum' => 0];
            }
        }
        $names = manager::user_names(array_keys($owners));
        $roles = self::system_roles(array_keys($owners));
        $compliance = self::compliance_30d($now);
        $out = [];
        foreach ($owners as $uid => $o) {
            if (!empty($filters['roleshortname']) && !in_array($filters['roleshortname'], $roles[$uid] ?? [], true)) {
                continue;
            }
            $out[] = [
                'userid' => $uid,
                'fullname' => $uid > 0 ? ($names[$uid] ?? '') : 'Sin asignar',
                'roles' => $roles[$uid] ?? [],
                'total' => $o['total'],
                'by_status' => $o['by_status'],
                'on_time' => $o['on_time'],
                'due_soon' => $o['due_soon'],
                'overdue' => $o['overdue'],
                'critical' => $o['critical'],
                'oldest_minutes' => $o['oldest_minutes'],
                'avg_minutes' => $o['total'] > 0 ? (int)round($o['sum'] / $o['total']) : 0,
                'sla_compliance_30d' => $compliance[$uid] ?? null,
            ];
        }
        usort($out, function($a, $b) {
            return [$b['critical'], $b['overdue'], $b['total']] <=> [$a['critical'], $a['overdue'], $a['total']];
        });
        return $out;
    }

    /**
     * Per letter type, letters created in [$from, $to]: finished, % within ANS,
     * median and p90 of the cycle (business minutes), rejections.
     */
    public static function summary(int $from, int $to): array {
        global $DB;
        $types = $DB->get_records('gmk_letter_type', null, 'name');
        $rows = $DB->get_records_select('gmk_letter_request', 'timecreated >= :f AND timecreated <= :t',
            ['f' => $from, 't' => $to], 'id', 'id, lettertypeid, status, timecreated');
        $finish = self::finish_times(array_keys($rows));
        $breached = self::breached_ids(array_keys($rows));
        $area = team::area();
        $out = [];
        foreach ($types as $t) {
            $out[(int)$t->id] = ['lettertypeid' => (int)$t->id, 'name' => format_string($t->name), 'created' => 0,
                'finished' => 0, 'rejected' => 0, 'cancelled' => 0, 'open' => 0, 'within_sla_pct' => null,
                'cycle_median_minutes' => null, 'cycle_p90_minutes' => null, 'cycles' => [], 'within' => 0];
        }
        foreach ($rows as $r) {
            if (!isset($out[(int)$r->lettertypeid])) {
                continue;
            }
            $o = &$out[(int)$r->lettertypeid];
            $o['created']++;
            if ($r->status === 'rechazada') {
                $o['rejected']++;
            } else if ($r->status === 'cancelada') {
                $o['cancelled']++;
            } else if (isset($finish[(int)$r->id])) {
                $o['finished']++;
                $o['cycles'][] = business_hours::elapsed((int)$r->timecreated, $finish[(int)$r->id], $area);
                if (!isset($breached[(int)$r->id])) {
                    $o['within']++;
                }
            } else {
                $o['open']++;
            }
            unset($o);
        }
        foreach ($out as &$o) {
            if ($o['cycles']) {
                sort($o['cycles']);
                $n = count($o['cycles']);
                $o['cycle_median_minutes'] = $o['cycles'][intdiv($n, 2)];
                $o['cycle_p90_minutes'] = $o['cycles'][min($n - 1, (int)floor($n * 0.9))];
                $o['within_sla_pct'] = round(100 * $o['within'] / $n, 1);
            }
            unset($o['cycles'], $o['within']);
        }
        unset($o);
        return array_values($out);
    }

    /**
     * @return array requestid => time it became ready (generada_digital / pendiente_recoleccion / entregada)
     */
    private static function finish_times(array $ids): array {
        global $DB;
        if (!$ids) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'r');
        $rows = $DB->get_records_sql("SELECT requestid, MIN(timecreated) AS t FROM {gmk_letter_request_event}
                WHERE requestid $in AND eventtype = 'status_change'
                  AND newstatus IN ('generada_digital', 'pendiente_recoleccion', 'entregada')
             GROUP BY requestid", $params);
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r->requestid] = (int)$r->t;
        }
        return $out;
    }

    /**
     * @return array requestid => true for letters that reached "vencida" at some point
     */
    private static function breached_ids(array $ids): array {
        global $DB;
        if (!$ids) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'r');
        $out = [];
        foreach ($DB->get_fieldset_sql("SELECT DISTINCT requestid FROM {gmk_letter_request_event}
                WHERE requestid $in AND eventtype = 'sla_level' AND " . $DB->sql_like('metadata', ':m'),
                $params + ['m' => '%"to":2%']) as $id) {
            $out[(int)$id] = true;
        }
        foreach ($DB->get_fieldset_sql("SELECT DISTINCT requestid FROM {gmk_letter_request_event}
                WHERE requestid $in AND eventtype = 'sla_level' AND " . $DB->sql_like('metadata', ':m'),
                $params + ['m' => '%"to":3%']) as $id) {
            $out[(int)$id] = true;
        }
        return $out;
    }

    /**
     * Owner => % of letters finished in the last 30 days without reaching "vencida".
     */
    private static function compliance_30d(int $now): array {
        global $DB;
        $rows = $DB->get_records_sql("SELECT DISTINCT r.id, r.assignedto FROM {gmk_letter_request} r
                JOIN {gmk_letter_request_event} e ON e.requestid = r.id AND e.eventtype = 'status_change'
                 AND e.newstatus IN ('generada_digital', 'pendiente_recoleccion', 'entregada') AND e.timecreated >= :since
             WHERE r.assignedto > 0", ['since' => $now - 30 * DAYSECS]);
        if (!$rows) {
            return [];
        }
        $breached = self::breached_ids(array_keys($rows));
        $tot = [];
        $ok = [];
        foreach ($rows as $r) {
            $uid = (int)$r->assignedto;
            $tot[$uid] = ($tot[$uid] ?? 0) + 1;
            $ok[$uid] = ($ok[$uid] ?? 0) + (isset($breached[(int)$r->id]) ? 0 : 1);
        }
        $out = [];
        foreach ($tot as $uid => $n) {
            $out[$uid] = round(100 * $ok[$uid] / $n, 1);
        }
        return $out;
    }

    /**
     * @return array userid => role shortnames at system level
     */
    private static function system_roles(array $userids): array {
        global $DB;
        $userids = array_values(array_filter(array_map('intval', $userids)));
        if (!$userids) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal($userids);
        $rows = $DB->get_records_sql("SELECT ra.id, ra.userid, r.shortname FROM {role_assignments} ra
                JOIN {role} r ON r.id = ra.roleid
                JOIN {context} c ON c.id = ra.contextid AND c.contextlevel = " . CONTEXT_SYSTEM . "
               WHERE ra.userid $in", $params);
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r->userid][] = (string)$r->shortname;
        }
        return $out;
    }
}
