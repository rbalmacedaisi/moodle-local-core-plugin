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
 * External (admin): per-career acknowledgement counters for one broadcast.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\admin\announcement;

use context_system;
use external_api;
use external_function_parameters;
use external_single_structure;
use external_multiple_structure;
use external_value;
use Exception;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/announcement_manager.php');

class get_message_stats extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'messageid' => new external_value(PARAM_INT, 'Broadcast id', VALUE_REQUIRED),
        ]);
    }

    public static function execute($messageid) {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), ['messageid' => $messageid]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:viewannouncements', $context);

        if (!$DB->record_exists('gmk_admin_message', ['id' => $params['messageid']])) {
            throw new Exception('message_not_found');
        }

        $stats = \local_grupomakro_core\local\announcement_manager::per_career_stats((int)$params['messageid']);
        $total = 0; $acked = 0;
        foreach ($stats as $s) {
            $total += $s['total'];
            $acked += $s['acked'];
        }
        $pct = $total > 0 ? round(($acked / $total) * 100, 1) : 0.0;
        return [
            'stats'            => $stats,
            'total_recipients' => $total,
            'total_acked'      => $acked,
            'percent'          => $pct,
        ];
    }

    public static function execute_returns() {
        $statstructure = new external_single_structure([
            'careerid'    => new external_value(PARAM_INT,   'local_learning_plans.id (0 = sin carrera)'),
            'careername'  => new external_value(PARAM_TEXT,  'Display name of the career'),
            'total'       => new external_value(PARAM_INT,   'Total recipients in the bucket'),
            'acked'       => new external_value(PARAM_INT,   'Acknowledged recipients'),
            'pending'     => new external_value(PARAM_INT,   'Recipients still pending'),
            'percent'     => new external_value(PARAM_FLOAT, 'Percent acknowledged, 0..100'),
        ]);

        return new external_single_structure([
            'stats'            => new external_multiple_structure($statstructure, 'Per-career rows'),
            'total_recipients' => new external_value(PARAM_INT,   'Grand total across buckets'),
            'total_acked'      => new external_value(PARAM_INT,   'Grand total acknowledged'),
            'percent'          => new external_value(PARAM_FLOAT, '0..100'),
        ]);
    }
}
