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
 * Refreshes payment state for up to N revalidations in a single request.
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Antigravity
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\admin;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/revalida_manager.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;
use context_system;

class refresh_revalidations_bulk extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'revalidationids' => new external_value(PARAM_RAW, 'Comma-separated list of revalidation ids', VALUE_REQUIRED),
            'limit'           => new external_value(PARAM_INT, 'Max items to process (cap 50)', VALUE_DEFAULT, 25),
        ]);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'processed' => new external_value(PARAM_INT, 'Number of items processed'),
            'paid'      => new external_value(PARAM_INT, 'Number that came back as paid'),
            'results'   => new external_multiple_structure(new external_single_structure([
                'id'         => new external_value(PARAM_INT, 'Revalidation id'),
                'paid'       => new external_value(PARAM_BOOL, 'Paid after refresh'),
                'payment_state' => new external_value(PARAM_RAW, 'Reported state'),
            ])),
        ]);
    }

    public static function execute(string $revalidationids, int $limit = 25) {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'revalidationids' => $revalidationids,
            'limit'           => $limit,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/grupomakro_core:view_revalidations_dashboard', $context);

        $limit = max(1, min(50, (int)$params['limit']));
        $ids = array_values(array_filter(array_map('intval', explode(',', (string)$params['revalidationids']))));
        $ids = array_slice($ids, 0, $limit);

        $results = [];
        $paidCount = 0;
        $mgr = '\\local_grupomakro_core\\local\\revalida_manager';

        foreach ($ids as $rid) {
            $rec = $DB->get_record('gmk_revalidations', ['id' => $rid], '*', IGNORE_MISSING);
            if (!$rec) {
                $results[] = ['id' => $rid, 'paid' => false, 'payment_state' => 'not_found'];
                continue;
            }
            try {
                $paid = $mgr::verify_payment($rec);
                $rec = $DB->get_record('gmk_revalidations', ['id' => $rid], '*', MUST_EXIST);
                if ($paid) {
                    $paidCount++;
                }
                $results[] = [
                    'id' => $rid,
                    'paid' => (bool)$paid,
                    'payment_state' => (string)$rec->payment_state,
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'id' => $rid,
                    'paid' => false,
                    'payment_state' => 'error:' . $e->getMessage(),
                ];
            }
        }

        return [
            'processed' => count($results),
            'paid'      => $paidCount,
            'results'   => $results,
        ];
    }
}