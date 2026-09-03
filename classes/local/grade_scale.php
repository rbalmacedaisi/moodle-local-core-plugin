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
 * Institutional grading scale (Institutos Técnicos Superiores).
 *
 * Single source of truth for the letter grade / concept shown next to a numeric
 * grade and for the points that feed the cumulative academic index.
 *
 * The band thresholds MUST stay aligned with gmk_classify_student_grade()
 * (locallib.php): grade > 70.9 approves, 60.0..70.9 is eligible for revalidation
 * and anything below 60.0 fails. That is why the bounds are written as
 * "> 90.9 / > 80.9 / > 70.9 / >= 60.0" instead of rounding the grade first: a
 * 70.5 rounded up would print "C — Regular" (a passing letter) on a row the
 * system holds as pending revalidation, and the document would contradict
 * itself.
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Antigravity
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Letter scale + academic index helper.
 */
class grade_scale {

    /** @var string Plugin component name (get_config). */
    const COMPONENT = 'local_grupomakro_core';

    /** @var string Config key holding the points scale used by the academic index. */
    const SETTING_SCALE = 'credit_index_scale';

    /** @var int[] Supported point scales for the academic index. */
    const SCALES = [3, 4];

    /** @var int Scale used when the setting is missing or invalid. */
    const DEFAULT_SCALE = 3;

    /**
     * Canonical passing threshold, mirrors gmk_classify_student_grade().
     * A grade must be strictly greater than this to be approved.
     */
    const PASS_THRESHOLD = 70.9;

    /**
     * Letter bands, highest first.
     *
     * - min / inclusive: lower bound of the band ('inclusive' => false means ">").
     * - range: printable numeric range, as published by the institution.
     * - points: index points per supported scale.
     *
     * @var array[]
     */
    const BANDS = [
        [
            'letter'    => 'A',
            'concept'   => 'Sobresaliente',
            'range'     => '91 - 100',
            'min'       => 90.9,
            'inclusive' => false,
            'points'    => [3 => 3.0, 4 => 4.0],
            'color'     => '1B8E4F',
        ],
        [
            'letter'    => 'B',
            'concept'   => 'Buena',
            'range'     => '81 - 90',
            'min'       => 80.9,
            'inclusive' => false,
            'points'    => [3 => 2.0, 4 => 3.0],
            'color'     => '1B8E4F',
        ],
        [
            'letter'    => 'C',
            'concept'   => 'Regular',
            'range'     => '71 - 80',
            'min'       => 70.9,
            'inclusive' => false,
            'points'    => [3 => 1.0, 4 => 2.0],
            'color'     => '1B8E4F',
        ],
        [
            'letter'    => 'D',
            'concept'   => 'No satisface',
            'range'     => '61 - 70',
            'min'       => 60.0,
            'inclusive' => true,
            'points'    => [3 => 0.0, 4 => 1.0],
            'color'     => 'EF6C00',
        ],
        [
            'letter'    => 'F',
            'concept'   => 'Fracasa',
            'range'     => '0 - 60',
            'min'       => 0.0,
            'inclusive' => true,
            'points'    => [3 => 0.0, 4 => 0.0],
            'color'     => 'C62828',
        ],
    ];

    /**
     * Points scale configured for the academic index (3 = 0.00-3.00, 4 = 0.00-4.00).
     *
     * @return int
     */
    public static function points_scale(): int {
        $raw = (int)get_config(self::COMPONENT, self::SETTING_SCALE);
        return in_array($raw, self::SCALES, true) ? $raw : self::DEFAULT_SCALE;
    }

    /**
     * Normalise a grade coming from the pensum (string with 2 decimals, '-' when absent).
     *
     * @param mixed $grade
     * @return float|null Null when there is no usable numeric grade.
     */
    public static function to_float($grade): ?float {
        if ($grade === null || is_bool($grade)) {
            return null;
        }
        if (is_int($grade) || is_float($grade)) {
            $value = (float)$grade;
        } else {
            $raw = trim((string)$grade);
            if ($raw === '' || $raw === '-' || $raw === '--') {
                return null;
            }
            $raw = str_replace(',', '.', $raw);
            if (!is_numeric($raw)) {
                return null;
            }
            $value = (float)$raw;
        }
        if ($value < 0.0 || $value > 100.0) {
            return null;
        }
        return $value;
    }

    /**
     * Resolve the band a grade falls into.
     *
     * @param mixed $grade
     * @return array|null Null when there is no usable numeric grade.
     */
    public static function band_for($grade): ?array {
        $value = self::to_float($grade);
        if ($value === null) {
            return null;
        }
        foreach (self::BANDS as $band) {
            $hit = $band['inclusive'] ? ($value >= $band['min']) : ($value > $band['min']);
            if ($hit) {
                return $band;
            }
        }
        return null;
    }

    /**
     * Letter for a grade ('' when the grade is not usable).
     *
     * @param mixed $grade
     * @return string
     */
    public static function letter_for($grade): string {
        $band = self::band_for($grade);
        return $band ? $band['letter'] : '';
    }

    /**
     * Index points for a grade on the given (or configured) scale.
     *
     * @param mixed    $grade
     * @param int|null $scale
     * @return float|null Null when the grade is not usable.
     */
    public static function points_for($grade, ?int $scale = null): ?float {
        $band = self::band_for($grade);
        if ($band === null) {
            return null;
        }
        $scale = ($scale !== null && in_array($scale, self::SCALES, true)) ? $scale : self::points_scale();
        return (float)($band['points'][$scale] ?? $band['points'][self::DEFAULT_SCALE]);
    }

    /**
     * Whether a grade passes, using the canonical institutional threshold.
     *
     * @param mixed $grade
     * @return bool
     */
    public static function is_passing($grade): bool {
        $value = self::to_float($grade);
        return $value !== null && $value > self::PASS_THRESHOLD;
    }

    /**
     * Printable scale legend (numeric range, letter, concept, points).
     *
     * @param int|null $scale
     * @return array[]
     */
    public static function legend(?int $scale = null): array {
        $scale = ($scale !== null && in_array($scale, self::SCALES, true)) ? $scale : self::points_scale();
        $rows = [];
        foreach (self::BANDS as $band) {
            $rows[] = [
                'range'   => $band['range'],
                'letter'  => $band['letter'],
                'concept' => $band['concept'],
                'points'  => number_format((float)($band['points'][$scale] ?? 0), 2),
                'color'   => $band['color'],
            ];
        }
        return $rows;
    }

    /**
     * Format an index value for display.
     *
     * @param float|null $value
     * @return string
     */
    public static function format_index(?float $value): string {
        return $value === null ? '--' : number_format($value, 2);
    }
}
