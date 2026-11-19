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
 * Dashboard administrativo: semaforo y vencimiento de cada pendiente (§2.2).
 *
 * Reutiliza support\business_hours (horas habiles con feriados de gmk_holidays)
 * con el horario administrativo dash_hours. Los "dias habiles" se cuentan con
 * un horario derivado que abre de 00:00 a 24:00 los dias que dash_hours abre.
 *
 * Niveles: 0 en plazo, 1 por vencer (amarillo), 2 vencido (rojo), 3 critico.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\dashboard;

use local_grupomakro_core\local\support\business_hours;
use stdClass;

defined('MOODLE_INTERNAL') || die();

class sla {

    const LEVEL_OK = 0;
    const LEVEL_WARN = 1;
    const LEVEL_BREACH = 2;
    const LEVEL_CRIT = 3;

    const LEVEL_NAMES = [0 => 'ok', 1 => 'warning', 2 => 'breached', 3 => 'critical'];

    /** @var stdClass|null */
    private static $hours = null;
    /** @var stdClass|null */
    private static $days = null;

    public static function reset_cache(): void {
        self::$hours = null;
        self::$days = null;
        business_hours::reset_cache();
    }

    /** Administrative business hours (pseudo area for business_hours). */
    public static function hours_area(): stdClass {
        if (self::$hours === null) {
            $json = business_hours::parse((string)config::get('dash_hours')) ? (string)config::get('dash_hours')
                : config::DEFAULT_HOURS;
            self::$hours = (object)['hours' => $json];
        }
        return self::$hours;
    }

    /** Same open days, 24 h each: one business day = 1440 minutes. */
    public static function days_area(): stdClass {
        if (self::$days === null) {
            $days = [];
            foreach (array_keys(business_hours::parse(self::hours_area()->hours)) as $day) {
                $days[(string)$day] = [['00:00', '24:00']];
            }
            self::$days = (object)['hours' => json_encode($days ?: ['1' => [['00:00', '24:00']]])];
        }
        return self::$days;
    }

    /**
     * Evaluates one pending item.
     *
     * @param stdClass $item needs type and since (kind h/d) or deadline (kind before)
     * @return array [level, due timestamp (0 = unknown)]
     */
    public static function evaluate(stdClass $item, int $now): array {
        $cfg = config::sla((string)$item->type);
        if (!$cfg || empty($cfg['on'])) {
            return [self::LEVEL_OK, 0];
        }
        $warn = (int)$cfg['warn'];
        $due = (int)$cfg['due'];
        $crit = (int)$cfg['crit'];

        if ($cfg['kind'] === 'before') {
            $deadline = (int)($item->deadline ?? 0);
            if ($deadline <= 0) {
                return [self::LEVEL_OK, 0];
            }
            // Business days left before the deadline (0 once it has passed).
            $left = business_hours::elapsed($now, $deadline, self::days_area()) / 1440;
            $duets = self::days_before($deadline, $due);
            if ($crit >= 0 && $left <= $crit) {
                return [self::LEVEL_CRIT, $duets];
            }
            if ($left <= $due) {
                return [self::LEVEL_BREACH, $duets];
            }
            return [$left <= $warn ? self::LEVEL_WARN : self::LEVEL_OK, $duets];
        }

        $since = (int)($item->since ?? 0);
        if ($since <= 0 || $since > $now) {
            return [self::LEVEL_OK, 0];
        }
        if ($cfg['kind'] === 'd') {
            $area = self::days_area();
            $unit = 1440;
        } else {
            $area = self::hours_area();
            $unit = 60;
        }
        $elapsed = business_hours::elapsed($since, $now, $area);
        $duets = business_hours::add_minutes($since, $due * $unit, $area);
        if ($crit > 0 && $elapsed >= $crit * $unit) {
            return [self::LEVEL_CRIT, $duets];
        }
        if ($elapsed >= $due * $unit) {
            return [self::LEVEL_BREACH, $duets];
        }
        return [$elapsed >= $warn * $unit ? self::LEVEL_WARN : self::LEVEL_OK, $duets];
    }

    /**
     * Instant that is $n business days before $deadline (day granularity).
     */
    public static function days_before(int $deadline, int $n): int {
        if ($n <= 0) {
            return $deadline;
        }
        $area = self::days_area();
        $t = $deadline;
        for ($i = 0; $i < 400; $i++) {
            if (business_hours::elapsed($t, $deadline, $area) >= $n * 1440) {
                return $t;
            }
            $t -= DAYSECS;
        }
        return $t;
    }
}
