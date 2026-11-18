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
 * Mesa de ayuda (fase 2): semaforo en minutos habiles, escalera de
 * escalamiento y perfiles del modo incisivo. Todo sale de config (§12-bis).
 *
 * Niveles del semaforo (gmk_tkt_ticket.escalation_level):
 *   0 verde (< tkt_sla_green), 1 verde con aviso, 2 amarillo (>= tkt_sla_yellow),
 *   3 naranja (>= tkt_sla_orange), 4 rojo (>= tkt_sla_red).
 *
 * tkt_escalation (JSON): por nivel 1..4, que hacer al cruzarlo:
 *   agent        aviso al agente asignado (o a los agentes del tema si no hay)
 *   student      mensaje automatico "sigues en cola" (plantilla sla_warning)
 *   supervisors  alerta a supervisores
 *   gerencia     alerta a gerencia (se repite cada tkt_red_repeat en el nivel 4)
 *   assign       si sigue sin asignar, al agente con menor carga
 *   priority     sube a prioridad alta
 *   requeue      si el agente esta ausente, devuelve el ticket a la cola
 *
 * tkt_incisive (JSON):
 *   profiles: code => {label, lock: none|orange|all, sound, title, osnotify, soundname}
 *   areas:    area code => profile (agentes de esa area)
 *   admins:   profile de administradores del sitio y supervisores sin area
 *   others:   profile por defecto
 *   users:    userid => profile (gana sobre todo)
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\support;

use stdClass;

defined('MOODLE_INTERNAL') || die();

class policy {

    const LEVEL_NONE = 0;
    const LEVEL_WARN = 1;
    const LEVEL_YELLOW = 2;
    const LEVEL_ORANGE = 3;
    const LEVEL_RED = 4;

    const SEMAPHORES = [0 => 'green', 1 => 'green_warn', 2 => 'yellow', 3 => 'orange', 4 => 'red'];

    const ESCALATION_ACTIONS = ['agent', 'student', 'supervisors', 'gerencia', 'assign', 'priority', 'requeue'];

    const DEFAULT_ESCALATION = [
        '1' => ['agent' => 1, 'student' => 1],
        '2' => ['supervisors' => 1, 'assign' => 1],
        '3' => ['supervisors' => 1, 'priority' => 1, 'requeue' => 1],
        '4' => ['gerencia' => 1],
    ];

    const LOCKS = ['none', 'orange', 'all'];
    const SOUNDS = ['alarma', 'campana', 'pitido'];

    const DEFAULT_INCISIVE = [
        'profiles' => [
            'ti' => ['label' => 'Agente de TI', 'lock' => 'all', 'sound' => 1, 'title' => 1, 'osnotify' => 1,
                'soundname' => 'alarma'],
            'admin' => ['label' => 'Administradores', 'lock' => 'orange', 'sound' => 1, 'title' => 1, 'osnotify' => 1,
                'soundname' => 'campana'],
            'academico' => ['label' => 'Agentes académicos', 'lock' => 'none', 'sound' => 1, 'title' => 1, 'osnotify' => 1,
                'soundname' => 'campana'],
            'silencioso' => ['label' => 'Solo indicador', 'lock' => 'none', 'sound' => 0, 'title' => 1, 'osnotify' => 0,
                'soundname' => 'campana'],
        ],
        'areas' => ['ti' => 'ti', 'academico' => 'academico', 'bienestar' => 'academico', 'psicologia' => 'academico'],
        'admins' => 'admin',
        'others' => 'academico',
        'users' => [],
    ];

    /** Lock strength, to pick the strongest profile when someone is agent of several areas. */
    const LOCK_RANK = ['none' => 0, 'orange' => 1, 'all' => 2];

    // ---------------------------------------------------------------------
    // Semaphore
    // ---------------------------------------------------------------------

    /**
     * @return int[] [warn, yellow, orange, red] thresholds in business minutes
     */
    public static function thresholds(): array {
        return [
            config::get_int('tkt_sla_green'),
            config::get_int('tkt_sla_yellow'),
            config::get_int('tkt_sla_orange'),
            config::get_int('tkt_sla_red'),
        ];
    }

    public static function level_for(int $minutes): int {
        [$warn, $yellow, $orange, $red] = self::thresholds();
        if ($minutes >= $red) {
            return self::LEVEL_RED;
        }
        if ($minutes >= $orange) {
            return self::LEVEL_ORANGE;
        }
        if ($minutes >= $yellow) {
            return self::LEVEL_YELLOW;
        }
        return $minutes >= $warn ? self::LEVEL_WARN : self::LEVEL_NONE;
    }

    public static function semaphore_for(int $minutes, bool $waiting): string {
        return $waiting ? self::SEMAPHORES[self::level_for($minutes)] : 'none';
    }

    /** @var array per-request area cache id => record */
    private static $areas = [];

    public static function area(int $areaid): ?stdClass {
        global $DB;
        if (!array_key_exists($areaid, self::$areas)) {
            self::$areas[$areaid] = $DB->get_record('gmk_tkt_area', ['id' => $areaid]) ?: null;
        }
        return self::$areas[$areaid];
    }

    /** @var array|null userid => own business hours JSON (gmk_tkt_presence.hours), loaded once per request */
    private static $agenthours = null;

    public static function reset_caches(): void {
        self::$areas = [];
        self::$agenthours = null;
        business_hours::reset_cache();
    }

    /**
     * Own business hours of $userid ('' = none: they work the area's hours).
     */
    public static function agent_hours(int $userid): string {
        global $DB;
        if (self::$agenthours === null) {
            self::$agenthours = [];
            try {
                $rows = $DB->get_records_select('gmk_tkt_presence', 'hours IS NOT NULL', [], '', 'id, userid, hours');
                foreach ($rows as $r) {
                    if (trim((string)$r->hours) !== '' && business_hours::parse((string)$r->hours)) {
                        self::$agenthours[(int)$r->userid] = (string)$r->hours;
                    }
                }
            } catch (\dml_exception $e) {
                self::$agenthours = [];
            }
        }
        return self::$agenthours[$userid] ?? '';
    }

    /**
     * Schedule that measures $userid's time on tickets of $areaid: their own
     * hours when they have them, else the area's (§6.2, horario por agente).
     */
    public static function user_clock(int $userid, int $areaid): ?stdClass {
        $own = $userid > 0 ? self::agent_hours($userid) : '';
        return $own !== '' ? (object)['hours' => $own] : self::area($areaid);
    }

    /**
     * Clock of a ticket: the assigned agent's hours; unassigned, the area's.
     */
    public static function clock(stdClass $ticket): ?stdClass {
        return self::user_clock((int)($ticket->assignedto ?? 0), (int)$ticket->areaid);
    }

    /**
     * Business minutes the requester has been waiting for staff (0 when not
     * waiting), on the assigned agent's clock (or the area's when unassigned).
     */
    public static function waiting_minutes(stdClass $ticket, int $now = 0): int {
        if ((int)$ticket->waiting_since <= 0 || !manager::is_open_status((string)$ticket->status)) {
            return 0;
        }
        return business_hours::elapsed((int)$ticket->waiting_since, $now ?: time(), self::clock($ticket));
    }

    // ---------------------------------------------------------------------
    // JSON settings
    // ---------------------------------------------------------------------

    /**
     * Completes and validates a JSON setting. With $strict, bad values throw
     * (settings page); otherwise they fall back to defaults (reading).
     */
    public static function normalise(string $name, ?array $data, bool $strict = false): array {
        if ($name === 'tkt_escalation') {
            return self::normalise_escalation($data, $strict);
        }
        if ($name === 'tkt_incisive') {
            return self::normalise_incisive($data, $strict);
        }
        return $data ?? [];
    }

    private static function normalise_escalation(?array $data, bool $strict): array {
        $source = $data === null ? self::DEFAULT_ESCALATION : $data;
        $out = [];
        for ($level = 1; $level <= 4; $level++) {
            $row = (array)($source[(string)$level] ?? []);
            foreach (array_keys($row) as $k) {
                if ($strict && !in_array($k, self::ESCALATION_ACTIONS, true)) {
                    throw new \invalid_parameter_exception('Acción de escalamiento desconocida: ' . $k);
                }
            }
            $clean = [];
            foreach (self::ESCALATION_ACTIONS as $action) {
                $clean[$action] = !empty($row[$action]) ? 1 : 0;
            }
            $out[(string)$level] = $clean;
        }
        return $out;
    }

    private static function normalise_incisive(?array $data, bool $strict): array {
        $def = self::DEFAULT_INCISIVE;
        $data = $data ?? $def;
        $profiles = [];
        foreach ((array)($data['profiles'] ?? $def['profiles']) as $code => $p) {
            $code = clean_param((string)$code, PARAM_ALPHANUMEXT);
            if ($code === '') {
                continue;
            }
            $p = (array)$p;
            $lock = (string)($p['lock'] ?? 'none');
            if (!in_array($lock, self::LOCKS, true)) {
                if ($strict) {
                    throw new \invalid_parameter_exception('Bloqueo inválido en el perfil ' . $code);
                }
                $lock = 'none';
            }
            $sound = (string)($p['soundname'] ?? 'campana');
            $profiles[$code] = [
                'label' => \core_text::substr(trim(clean_param((string)($p['label'] ?? $code), PARAM_TEXT)), 0, 60) ?: $code,
                'lock' => $lock,
                'sound' => !empty($p['sound']) ? 1 : 0,
                'title' => !empty($p['title']) ? 1 : 0,
                'osnotify' => !empty($p['osnotify']) ? 1 : 0,
                'soundname' => in_array($sound, self::SOUNDS, true) ? $sound : 'campana',
            ];
        }
        if (!$profiles) {
            if ($strict) {
                throw new \invalid_parameter_exception('Debe existir al menos un perfil');
            }
            $profiles = $def['profiles'];
        }
        $first = array_key_first($profiles);
        $pick = function($code) use ($profiles, $first, $strict) {
            $code = (string)$code;
            if (isset($profiles[$code])) {
                return $code;
            }
            if ($strict && $code !== '') {
                throw new \invalid_parameter_exception('Perfil inexistente: ' . $code);
            }
            return isset($profiles['academico']) ? 'academico' : $first;
        };
        $areas = [];
        foreach ((array)($data['areas'] ?? []) as $areacode => $profile) {
            $areacode = clean_param((string)$areacode, PARAM_ALPHANUMEXT);
            if ($areacode !== '' && (string)$profile !== '') {
                $areas[$areacode] = $pick($profile);
            }
        }
        $users = [];
        foreach ((array)($data['users'] ?? []) as $uid => $profile) {
            if ((int)$uid > 0 && (string)$profile !== '') {
                $users[(string)(int)$uid] = $pick($profile);
            }
        }
        return [
            'profiles' => $profiles,
            'areas' => $areas,
            'admins' => $pick($data['admins'] ?? 'admin'),
            'others' => $pick($data['others'] ?? 'academico'),
            'users' => $users ?: new \stdClass(),
        ];
    }

    /**
     * Incisive profile of $userid: per person, else the strongest profile of
     * the areas where they are agent, else administrators/supervisors, else others.
     *
     * @return array profile + 'code'
     */
    public static function incisive_profile(int $userid): array {
        global $DB;
        $cfg = config::get_json('tkt_incisive');
        $profiles = $cfg['profiles'];
        $users = (array)$cfg['users'];
        $code = null;
        if (isset($users[(string)$userid])) {
            $code = $users[(string)$userid];
        } else {
            $p = manager::profile($userid);
            if ($p->areas) {
                [$in, $params] = $DB->get_in_or_equal($p->areas);
                $best = null;
                foreach ($DB->get_fieldset_select('gmk_tkt_area', 'code', "id $in", $params) as $areacode) {
                    $candidate = $cfg['areas'][$areacode] ?? null;
                    if ($candidate && isset($profiles[$candidate]) && ($best === null
                            || self::LOCK_RANK[$profiles[$candidate]['lock']] > self::LOCK_RANK[$profiles[$best]['lock']])) {
                        $best = $candidate;
                    }
                }
                $code = $best;
            }
            if ($code === null && (is_siteadmin($userid) || $p->supervisor)) {
                $code = $cfg['admins'];
            }
        }
        $code = isset($profiles[$code]) ? $code : $cfg['others'];
        return ['code' => $code] + $profiles[$code];
    }

    /**
     * Escalation actions of $level (1..4).
     */
    public static function escalation_actions(int $level): array {
        $cfg = config::get_json('tkt_escalation');
        return $cfg[(string)$level] ?? [];
    }
}
