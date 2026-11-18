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
 * Mesa de ayuda: horario habil por area.
 *
 * gmk_tkt_area.hours guarda JSON {"1": [["08:00","21:00"]], ..., "6": [["08:00","16:00"]]}
 * con el dia ISO (1 lunes .. 7 domingo) y uno o varios tramos por dia. Los
 * feriados de gmk_holidays cierran el dia completo.
 *
 * Fase 1 solo usa is_open() (mensaje automatico "fuera de horario"). El
 * calculo de minutos habiles transcurridos (elapsed) para el semaforo llega en
 * la fase 2 y debe vivir aqui mismo.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\support;

use stdClass;

defined('MOODLE_INTERNAL') || die();

class business_hours {

    const DAY_NAMES = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes',
        6 => 'sábado', 7 => 'domingo'];

    /**
     * Parses and validates an hours JSON. Invalid ranges are dropped.
     *
     * @return array day(int 1..7) => [[startmin, endmin], ...]
     */
    public static function parse(?string $json): array {
        $out = [];
        $data = json_decode((string)$json, true);
        if (!is_array($data)) {
            return $out;
        }
        foreach ($data as $day => $ranges) {
            $day = (int)$day;
            if ($day < 1 || $day > 7 || !is_array($ranges)) {
                continue;
            }
            foreach ($ranges as $range) {
                if (!is_array($range) || count($range) !== 2) {
                    continue;
                }
                $start = self::to_minutes((string)$range[0]);
                $end = self::to_minutes((string)$range[1]);
                if ($start === null || $end === null || $end <= $start) {
                    continue;
                }
                $out[$day][] = [$start, $end];
            }
            if (!empty($out[$day])) {
                usort($out[$day], function($a, $b) {
                    return $a[0] <=> $b[0];
                });
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * Normalises an hours JSON sent by the settings page; throws on bad input.
     */
    public static function normalise(string $json): string {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \invalid_parameter_exception('Horario: JSON inválido');
        }
        $clean = [];
        foreach ($data as $day => $ranges) {
            $day = (int)$day;
            if ($day < 1 || $day > 7) {
                throw new \invalid_parameter_exception('Horario: día inválido ' . $day);
            }
            foreach ((array)$ranges as $range) {
                if (!is_array($range) || count($range) !== 2) {
                    throw new \invalid_parameter_exception('Horario: tramo inválido');
                }
                $start = self::to_minutes((string)$range[0]);
                $end = self::to_minutes((string)$range[1]);
                if ($start === null || $end === null || $end <= $start) {
                    throw new \invalid_parameter_exception('Horario: tramo inválido ' . json_encode($range));
                }
                $clean[(string)$day][] = [self::to_hhmm($start), self::to_hhmm($end)];
            }
        }
        ksort($clean);
        return json_encode($clean);
    }

    public static function is_open(stdClass $area, int $time): bool {
        $hours = self::parse($area->hours ?? '');
        if (empty($hours)) {
            // No hours configured: treat as always open (no out-of-hours notice).
            return true;
        }
        $dt = self::datetime($time);
        $day = (int)$dt->format('N');
        if (empty($hours[$day]) || self::is_holiday($dt)) {
            return false;
        }
        $minute = (int)$dt->format('G') * 60 + (int)$dt->format('i');
        foreach ($hours[$day] as [$start, $end]) {
            if ($minute >= $start && $minute < $end) {
                return true;
            }
        }
        return false;
    }

    /**
     * Human readable schedule: "lunes a viernes 8:00–21:00; sábado 8:00–16:00".
     */
    public static function describe(stdClass $area): string {
        $hours = self::parse($area->hours ?? '');
        if (empty($hours)) {
            return '';
        }
        // Group consecutive days that share the same ranges.
        $groups = [];
        foreach ($hours as $day => $ranges) {
            $key = json_encode($ranges);
            $last = end($groups);
            if ($last && $last['key'] === $key && $last['to'] === $day - 1) {
                $groups[key($groups)]['to'] = $day;
            } else {
                $groups[] = ['key' => $key, 'from' => $day, 'to' => $day, 'ranges' => $ranges];
            }
        }
        $parts = [];
        foreach ($groups as $g) {
            $days = $g['from'] === $g['to'] ? self::DAY_NAMES[$g['from']]
                : self::DAY_NAMES[$g['from']] . ' a ' . self::DAY_NAMES[$g['to']];
            $ranges = array_map(function($r) {
                return self::to_hhmm($r[0], false) . '–' . self::to_hhmm($r[1], false);
            }, $g['ranges']);
            $parts[] = $days . ' ' . implode(' y ', $ranges);
        }
        return implode('; ', $parts);
    }

    private static function is_holiday(\DateTimeImmutable $dt): bool {
        global $DB;
        $start = $dt->setTime(0, 0)->getTimestamp();
        try {
            return $DB->record_exists_select('gmk_holidays', 'date >= :s AND date < :e',
                ['s' => $start, 'e' => $start + DAYSECS]);
        } catch (\dml_exception $e) {
            return false;
        }
    }

    private static function datetime(int $time): \DateTimeImmutable {
        $tz = \core_date::get_server_timezone_object();
        return (new \DateTimeImmutable('@' . $time))->setTimezone($tz);
    }

    private static function to_minutes(string $hhmm): ?int {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($hhmm), $m)) {
            return null;
        }
        $h = (int)$m[1];
        $i = (int)$m[2];
        if ($h > 24 || $i > 59 || ($h === 24 && $i > 0)) {
            return null;
        }
        return $h * 60 + $i;
    }

    private static function to_hhmm(int $minutes, bool $pad = true): string {
        $h = intdiv($minutes, 60);
        $i = $minutes % 60;
        return ($pad ? sprintf('%02d', $h) : (string)$h) . ':' . sprintf('%02d', $i);
    }
}
