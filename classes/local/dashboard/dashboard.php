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
 * Dashboard administrativo: arma las tarjetas de la pagina (§2.4).
 *
 * "Lo mio" (mine): las fuentes cuya cola es del usuario (capacidad), con los
 * pendientes asignados a el o sin responsable. "Mi equipo" (team): todas las
 * fuentes con su cola completa; exige view_admin_dashboard_team.
 * Resultado cacheado 60 s por usuario y vista (MUC admindash).
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\dashboard;

use context_system;
use moodle_url;
use stdClass;

defined('MOODLE_INTERNAL') || die();

class dashboard {

    const CACHE_TTL = 60;
    const CARD_ITEMS = 10;

    public static function can_view(int $userid): bool {
        return has_capability('local/grupomakro_core:view_admin_dashboard', context_system::instance(), $userid);
    }

    public static function can_team(int $userid): bool {
        return has_capability('local/grupomakro_core:view_admin_dashboard_team', context_system::instance(), $userid);
    }

    private static function cache(): ?\cache {
        try {
            return \cache::make('local_grupomakro_core', 'admindash');
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function invalidate_all(): void {
        $cache = self::cache();
        if ($cache) {
            $cache->purge();
        }
    }

    /**
     * Page data for $userid (cached CACHE_TTL seconds unless $fresh).
     */
    public static function get(int $userid, string $scope = 'mine', bool $fresh = false): array {
        $scope = ($scope === 'team' && self::can_team($userid)) ? 'team' : 'mine';
        $cache = self::cache();
        $key = $userid . '_' . $scope;
        $now = time();
        if (!$fresh && $cache) {
            $hit = $cache->get($key);
            if (is_array($hit) && ($hit['generated'] ?? 0) > $now - self::CACHE_TTL) {
                return $hit;
            }
        }
        $data = self::build($userid, $scope, $now);
        if ($cache) {
            $cache->set($key, $data);
        }
        return $data;
    }

    /**
     * Evaluated, sorted pending items of one source for the user.
     *
     * @return stdClass[]
     */
    public static function source_items(source $s, int $userid, string $scope, int $now): array {
        $team = $scope === 'team' || $scope === 'all';
        $items = $s->fetch($userid, $team, $now);
        if ($scope === 'mine') {
            $items = array_values(array_filter($items, function($it) use ($userid) {
                return (int)$it->owner === 0 || (int)$it->owner === $userid;
            }));
        }
        foreach ($items as $it) {
            if (!isset($it->level)) {
                [$it->level, $it->due] = sla::evaluate($it, $now);
            }
            $it->level = (int)$it->level;
            $it->due = (int)($it->due ?? 0);
        }
        usort($items, function($a, $b) {
            if ($a->level !== $b->level) {
                return $b->level <=> $a->level;
            }
            $ad = $a->due ?: PHP_INT_MAX;
            $bd = $b->due ?: PHP_INT_MAX;
            if ($ad !== $bd) {
                return $ad <=> $bd;
            }
            return ((int)$a->since ?: (int)$a->deadline) <=> ((int)$b->since ?: (int)$b->deadline);
        });
        return $items;
    }

    public static function counts(array $items): array {
        $c = ['total' => count($items), 'warning' => 0, 'breached' => 0, 'critical' => 0];
        foreach ($items as $it) {
            if ($it->level >= sla::LEVEL_WARN) {
                $c[sla::LEVEL_NAMES[min(3, $it->level)]]++;
            }
        }
        return $c;
    }

    public static function build(int $userid, string $scope, int $now): array {
        global $DB;
        config::reset_cache();
        $cards = [];
        $owners = [];
        foreach (registry::sources() as $key => $s) {
            if ($scope === 'mine' && !$s->owns($userid)) {
                continue;
            }
            $card = [
                'key' => $key,
                'label' => $s->label(),
                'icon' => $s->icon(),
                'url' => $s->url(),
                'external' => $s->external_sla(),
                'error' => '',
                'counts' => ['total' => 0, 'warning' => 0, 'breached' => 0, 'critical' => 0],
                'truncated' => false,
                'items' => [],
            ];
            try {
                $items = self::source_items($s, $userid, $scope, $now);
                $card['counts'] = self::counts($items);
                $card['truncated'] = count($items) >= source::MAX_ROWS;
                foreach (array_slice($items, 0, self::CARD_ITEMS) as $it) {
                    $card['items'][] = [
                        'ref' => (string)$it->ref,
                        'title' => (string)$it->title,
                        'subtitle' => (string)$it->subtitle,
                        'level' => (int)$it->level,
                        'due' => (int)$it->due,
                        'since' => (int)$it->since,
                        'owner' => (int)$it->owner,
                        'ownername' => '',
                        'url' => (string)$it->url,
                    ];
                    if ((int)$it->owner > 0) {
                        $owners[(int)$it->owner] = true;
                    }
                }
            } catch (\Throwable $e) {
                $card['error'] = 'No se pudo leer esta bandeja.';
                if (function_exists('gmk_log')) {
                    gmk_log('admin dashboard source ' . $key . ' failed: ' . $e->getMessage());
                }
            }
            $cards[] = $card;
        }

        if ($scope === 'team' && $owners) {
            $names = [];
            [$in, $params] = $DB->get_in_or_equal(array_keys($owners));
            foreach ($DB->get_records_select('user', "id $in", $params, '', 'id, firstname, lastname') as $u) {
                $names[(int)$u->id] = trim($u->firstname . ' ' . $u->lastname);
            }
            foreach ($cards as &$card) {
                foreach ($card['items'] as &$it) {
                    $it['ownername'] = $it['owner'] > 0 ? ($names[$it['owner']] ?? '') : 'Sin responsable';
                }
                unset($it);
            }
            unset($card);
        }

        usort($cards, function($a, $b) {
            $ua = $a['counts']['critical'] * 1000000 + $a['counts']['breached'] * 1000 + $a['counts']['warning'];
            $ub = $b['counts']['critical'] * 1000000 + $b['counts']['breached'] * 1000 + $b['counts']['warning'];
            if ($ua !== $ub) {
                return $ub <=> $ua;
            }
            return $b['counts']['total'] <=> $a['counts']['total'];
        });

        $totals = ['total' => 0, 'warning' => 0, 'breached' => 0, 'critical' => 0];
        foreach ($cards as $c) {
            foreach ($totals as $k => $v) {
                $totals[$k] += $c['counts'][$k];
            }
        }
        $user = \core_user::get_user($userid);
        $context = context_system::instance();
        $isteacher = $DB->record_exists_sql("SELECT 1 FROM {gmk_class}
                                              WHERE (instructorid = :u1 OR supportinstructorid = :u2) AND closed = 0",
            ['u1' => $userid, 'u2' => $userid]);
        return [
            'generated' => $now,
            'scope' => $scope,
            'canteam' => self::can_team($userid),
            'firstname' => $user ? (string)$user->firstname : '',
            'totals' => $totals,
            'cards' => $cards,
            'teacherurl' => $isteacher
                ? (new moodle_url('/local/grupomakro_core/pages/teacher_dashboard.php'))->out(false) : '',
            'settingsurl' => has_capability('local/grupomakro_core:dashboard_manage', $context, $userid)
                ? (new moodle_url('/local/grupomakro_core/pages/dashboard_settings.php'))->out(false) : '',
            'hours' => \local_grupomakro_core\local\support\business_hours::describe(sla::hours_area()),
        ];
    }
}
