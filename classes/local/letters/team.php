<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Cartas (20261001130): quien gestiona las cartas.
 *
 * El equipo de cartas son los agentes del tema "Cartas y constancias" de la
 * mesa de ayuda (gmk_tkt_agent), asi el personal se configura en un solo lugar
 * (Configuracion de la mesa de ayuda). Supervisores y gerencia son los mismos de
 * la mesa de ayuda (gmk_tkt_person + capacidades support_supervise /
 * support_receive_escalations).
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\letters;

use context_system;
use local_grupomakro_core\local\support\business_hours;
use local_grupomakro_core\local\support\manager as support;
use local_grupomakro_core\local\support\policy;
use stdClass;

defined('MOODLE_INTERNAL') || die();

class team {

    /** Config key with the id of the "Cartas y constancias" topic. */
    const CATEGORY_CONFIG = 'letters_tkt_categoryid';

    /** Statuses where someone of the team owns the letter. */
    const OWNED_STATUSES = ['solicitada', 'pagada', 'pendiente_gestion', 'pendiente_recoleccion'];

    /** @var stdClass|null|false */
    private static $category = false;

    public static function reset_caches(): void {
        self::$category = false;
    }

    /**
     * The "Cartas y constancias" topic of the help desk, or null if missing/inactive.
     */
    public static function category(): ?stdClass {
        global $DB;
        if (self::$category === false) {
            $id = (int)get_config('local_grupomakro_core', self::CATEGORY_CONFIG);
            self::$category = $id > 0 ? ($DB->get_record('gmk_tkt_category', ['id' => $id]) ?: null) : null;
        }
        return self::$category;
    }

    /**
     * Area whose business hours run the letters clock (Academico).
     */
    public static function area(): ?stdClass {
        $category = self::category();
        return $category ? policy::area((int)$category->areaid) : null;
    }

    /**
     * Active team members (agents of the topic).
     *
     * @return int[]
     */
    public static function agents(): array {
        global $DB;
        $category = self::category();
        if (!$category) {
            return [];
        }
        return array_values(array_unique(array_map('intval', $DB->get_fieldset_sql(
            "SELECT a.userid
               FROM {gmk_tkt_agent} a
               JOIN {user} u ON u.id = a.userid AND u.deleted = 0 AND u.suspended = 0
              WHERE a.categoryid = :cat
           ORDER BY a.id", ['cat' => (int)$category->id]))));
    }

    /**
     * Whom to tell about an unassigned letter: available team members, else all.
     *
     * @return int[]
     */
    public static function recipients_unassigned(): array {
        $agents = self::agents();
        if (!$agents) {
            // No team configured yet: whoever may manage requests.
            return self::managers();
        }
        $presence = support::presence_map($agents);
        $available = array_values(array_filter($agents, function($u) use ($presence) {
            return $presence[$u] ?? true;
        }));
        return $available ?: $agents;
    }

    /**
     * Users holding managerequests (fallback when the team is empty).
     *
     * @return int[]
     */
    public static function managers(): array {
        $users = get_users_by_capability(context_system::instance(), 'local/grupomakro_core:managerequests', 'u.id');
        return array_values(array_map('intval', array_keys($users)));
    }

    /**
     * Open letters owned by each user.
     *
     * @return array userid => n
     */
    public static function loads(array $userids): array {
        global $DB;
        $userids = array_values(array_filter(array_map('intval', $userids)));
        if (!$userids) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'ld');
        [$sin, $sparams] = $DB->get_in_or_equal(self::OWNED_STATUSES, SQL_PARAMS_NAMED, 'ls');
        $out = [];
        foreach ($DB->get_records_sql("SELECT assignedto, COUNT(1) AS n FROM {gmk_letter_request}
                WHERE assignedto $in AND status $sin GROUP BY assignedto", $params + $sparams) as $r) {
            $out[(int)$r->assignedto] = (int)$r->n;
        }
        return $out;
    }

    /**
     * Available team member with fewer open letters, preferring those inside
     * their business hours now. 0 when nobody is available.
     */
    public static function pick(int $now = 0): int {
        $agents = self::agents();
        if (!$agents) {
            return 0;
        }
        $now = $now ?: time();
        $category = self::category();
        $areaid = $category ? (int)$category->areaid : 0;
        $loads = self::loads($agents);
        $presence = support::presence_map($agents);
        $best = [0, 0];
        $bestload = [PHP_INT_MAX, PHP_INT_MAX];
        foreach ($agents as $uid) {
            if (!($presence[$uid] ?? true)) {
                continue;
            }
            $inhours = $areaid <= 0 || business_hours::is_open(policy::user_clock($uid, $areaid), $now);
            $slot = $inhours ? 0 : 1;
            $load = $loads[$uid] ?? 0;
            if ($load < $bestload[$slot]) {
                $best[$slot] = $uid;
                $bestload[$slot] = $load;
            }
        }
        return $best[0] ?: $best[1];
    }

    /**
     * Help desk supervisors (letters level 2).
     *
     * @return int[]
     */
    public static function supervisors(): array {
        global $DB;
        $ids = array_map('intval', $DB->get_fieldset_select('gmk_tkt_person', 'userid', "kind = 'supervisor'"));
        $users = get_users_by_capability(context_system::instance(), 'local/grupomakro_core:support_supervise', 'u.id');
        return self::active(array_merge($ids, array_map('intval', array_keys($users))));
    }

    /**
     * Gerencia (letters level 3).
     *
     * @return int[]
     */
    public static function gerencia(): array {
        global $DB;
        $ids = array_map('intval', $DB->get_fieldset_select('gmk_tkt_person', 'userid', "kind = 'gerencia'"));
        $users = get_users_by_capability(context_system::instance(),
            'local/grupomakro_core:support_receive_escalations', 'u.id');
        return self::active(array_merge($ids, array_map('intval', array_keys($users))));
    }

    /**
     * @return int[] those of $ids that are active users
     */
    public static function active(array $ids): array {
        global $DB;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal($ids);
        return array_values(array_map('intval', $DB->get_fieldset_select('user', 'id',
            "id $in AND deleted = 0 AND suspended = 0", $params)));
    }
}
