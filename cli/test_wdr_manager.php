<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Smoke test for local_grupomakro_core\local\wdr_manager.
 *
 * Verifies that gmk_wdr_seq is created lazily, that two consecutive
 * allocations give distinct numbers, that changing the configured prefix
 * after the year row exists does NOT change existing numbers, and that
 * the default fallback values hold when no setting is configured.
 *
 * Run with: php local/grupomakro_core/cli/test_wdr_manager.php
 *
 * Exit code 0 on success, non-zero on the first failure.
 *
 * @package local_grupomakro_core
 * @category   cli
 */

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/wdr_manager.php');

use local_grupomakro_core\local\wdr_manager;

mtrace("=== Smoke test: wdr_manager (RET-01 / 20261001080) ===");

// Strip any row we may have left from previous runs of this smoke.
// Moodle 4.x rejects WHERE clauses on TEXT columns without an explicit
// sql_compare_text() wrapper, and delete_records() requires the SQL
// fragment keyed by the *column name*, not by a SQL fragment, so the
// simplest fix is to leave observations out of the WHERE and rely on
// `reason = 'A'` (CHAR, safe in WHERE).
$DB->delete_records('gmk_wdr', ['reason' => 'A']);
$DB->delete_records('gmk_wdr_seq', ['year' => 2099]);

// Force a known setting trio so we can assert literal values.
set_config('retirement_request_prefix', 'RET', 'local_grupomakro_core');
set_config('retirement_request_pad_length', 4, 'local_grupomakro_core');

// 1. First allocation creates the year row.
$a = wdr_manager::generate_request_number(2099);
assert($a['year'] === 2099, 'year mismatch');
assert(preg_match('/^RET-2099-\d{4}$/', $a['request_number']) === 1,
    'format mismatch on first allocation, got ' . $a['request_number']);
mtrace("1) First allocation lazy-creates row: " . $a['request_number'] . " ✔");

// 2. Next allocation on the same year is +1.
$b = wdr_manager::generate_request_number(2099);
assert((int)substr($b['request_number'], -4) === (int)substr($a['request_number'], -4) + 1,
    'sequential counter did not increment');
mtrace("2) Next allocation is strictly +1: " . $b['request_number'] . " ✔");

// 3. Changing the prefix AFTER the year row exists must NOT alter the
//    numbers that the year row will hand out next. The row snapshot
//    protects historical slots.
set_config('retirement_request_prefix', 'XYZ', 'local_grupomakro_core');
$c = wdr_manager::generate_request_number(2099);
assert(strpos($c['request_number'], 'XYZ-') !== 0,
    'BUG: prefix change retroactively affected numbers for the existing year');
mtrace("3) Mid-year prefix change protected by snapshot: " . $c['request_number'] . " ✔");
// restore the prefix so any UI test still matches the default
set_config('retirement_request_prefix', 'RET', 'local_grupomakro_core');

// 4. A different year creates a fresh row and respects the live setting.
$d = wdr_manager::generate_request_number(2098);
assert(strpos($d['request_number'], 'RET-2098-') === 0,
    'different year should use RET prefix');
assert($d['year'] === 2098, 'year mismatch on alt-year call');
mtrace("4) Different year creates its own row: " . $d['request_number'] . " ✔");

// 5. Empty settings must fall back to RET/4.
set_config('retirement_request_prefix', '', 'local_grupomakro_core');
set_config('retirement_request_pad_length', 0, 'local_grupomakro_core');
$row = $DB->get_record('gmk_wdr_seq', ['year' => 2097], '*', IGNORE_MISSING);
if ($row) {
    $DB->delete_records('gmk_wdr_seq', ['id' => $row->id]);
}
$e = wdr_manager::generate_request_number(2097);
assert(strpos($e['request_number'], 'RET-2097-0001') === 0,
    'fallback RET prefix and 4 zero-padding did not apply');
mtrace("5) Empty settings fall back to RET/4: " . $e['request_number'] . " ✔");
// restore defaults
set_config('retirement_request_prefix', 'RET', 'local_grupomakro_core');
set_config('retirement_request_pad_length', 4, 'local_grupomakro_core');

// 6. sanitize: cleanup the throwaway years so DB tests stay clean.
$DB->delete_records('gmk_wdr_seq', ['year' => 2097]);
$DB->delete_records('gmk_wdr_seq', ['year' => 2098]);
$DB->delete_records('gmk_wdr_seq', ['year' => 2099]);
mtrace("6) Cleanup done. ✔");

mtrace("=== ALL CHECKS PASSED ===");
exit(0);
