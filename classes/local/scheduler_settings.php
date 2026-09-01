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

namespace local_grupomakro_core\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Single source of truth for the scheduler parameters.
 *
 * These settings used to be scoped per academic period: the shift windows and
 * lunch range lived in gmk_academic_periods.configsettings, and the subject
 * loads and holidays carried an academicperiodid. In practice they are the same
 * for the whole institution, so every new period was born empty and the
 * planner silently fell back to its hardcoded defaults (2026-V had no config,
 * no loads and no holidays at all).
 *
 * They are now global:
 *  - the config blob lives in the plugin's own settings (set_config),
 *  - loads and holidays live under the reserved scope academicperiodid = 0.
 *
 * The old per-period rows are left untouched as history but are never read.
 *
 * @package    local_grupomakro_core
 */
class scheduler_settings {

    /** Reserved academicperiodid meaning "applies to every period". */
    const GLOBAL_SCOPE = 0;

    /** Plugin config key holding the JSON blob of scheduling parameters. */
    const CONFIG_KEY = 'scheduler_config';

    /** Plugin name for get_config/set_config. */
    const PLUGIN = 'local_grupomakro_core';

    /**
     * Default scheduling parameters, used only when nothing has been configured.
     *
     * @return \stdClass
     */
    public static function get_defaults() {
        return (object)[
            'intervalMinutes' => 30,
            'sessionDuration' => 120,
            'startTime' => '07:00',
            'endTime' => '22:00',
            'lunchStart' => '12:00',
            'lunchEnd' => '13:00',
            'shiftWindows' => (object)[
                'Diurna' => (object)['start' => '07:00', 'end' => '18:00'],
                'Nocturna' => (object)['start' => '18:00', 'end' => '22:00'],
                'Sabatina' => (object)['start' => '07:00', 'end' => '17:00'],
            ],
            'isolatedCareers' => [],
        ];
    }

    /**
     * The global scheduling parameters.
     *
     * @return \stdClass Always an object; defaults when nothing is stored yet.
     */
    public static function get_config() {
        $raw = get_config(self::PLUGIN, self::CONFIG_KEY);
        $decoded = self::decode_config($raw);

        if (!self::config_is_usable($decoded)) {
            return self::get_defaults();
        }
        return $decoded;
    }

    /**
     * Persist the global scheduling parameters.
     *
     * @param mixed $settings Array, object or JSON string.
     * @return bool
     */
    public static function save_config($settings) {
        if (is_string($settings)) {
            $clean = self::decode_config($settings);
        } else {
            $clean = self::decode_config(json_encode($settings));
        }
        if (!self::config_is_usable($clean)) {
            return false;
        }
        set_config(self::CONFIG_KEY, json_encode($clean), self::PLUGIN);
        return true;
    }

    /**
     * Decode a stored config blob into a clean object.
     *
     * Drops the numeric character keys ("0","1","2"...) that an old front-end
     * bug persisted when it spread a JSON string into the settings object.
     *
     * @param string|null $raw
     * @return \stdClass
     */
    public static function decode_config($raw) {
        if (empty($raw)) {
            return new \stdClass();
        }
        $decoded = json_decode($raw);
        if (!is_object($decoded)) {
            return new \stdClass();
        }
        $clean = new \stdClass();
        foreach (get_object_vars($decoded) as $key => $value) {
            if (!preg_match('/^\d+$/', (string)$key)) {
                $clean->$key = $value;
            }
        }
        return $clean;
    }

    /**
     * A config blob is only usable if it actually carries scheduling parameters.
     *
     * @param \stdClass $config
     * @return bool
     */
    public static function config_is_usable($config) {
        return is_object($config) && (!empty($config->shiftWindows) || !empty($config->startTime));
    }

    /**
     * The global subject loads (subject name -> total hours / weekly intensity).
     *
     * @return array Indexed array of records, ordered by subject name.
     */
    public static function get_loads() {
        global $DB;
        return array_values($DB->get_records(
            'gmk_subject_loads',
            ['academicperiodid' => self::GLOBAL_SCOPE],
            'subjectname ASC'
        ));
    }

    /**
     * The global holiday list, each row carrying a formatted_date for the client.
     *
     * Holidays are dates, so a single list covers every period: each period only
     * ever consumes the ones that fall inside its own start/end range.
     *
     * @return array Indexed array of records, ordered by date.
     */
    public static function get_holidays() {
        global $DB;
        $rows = $DB->get_records(
            'gmk_holidays',
            ['academicperiodid' => self::GLOBAL_SCOPE],
            'date ASC'
        );
        $out = [];
        foreach ($rows as $h) {
            $h->formatted_date = date('Y-m-d', $h->date);
            $out[] = $h;
        }
        return $out;
    }

    /**
     * Holiday dates as a lookup set keyed by 'Y-m-d'.
     *
     * @return array ['2026-11-03' => true, ...]
     */
    public static function get_holiday_dateset() {
        $set = [];
        foreach (self::get_holidays() as $h) {
            $set[$h->formatted_date] = true;
        }
        return $set;
    }
}
