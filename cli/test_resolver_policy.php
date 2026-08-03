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
 * Test CLI for the academic grade resolver policy.
 *
 * Verifies that the resolver picks MAX(grade) across movements and the
 * correct status when attempts are active / closed / annulled.
 *
 * Usage:
 *   php local/grupomakro_core/cli/test_resolver_policy.php [--user=1999]
 *
 * The default fixtures exercise a synthetic user 900001 + plan 99 + course
 * 99001 to avoid colliding with real students. Real users can be tested via
 * --user=<id> together with --plan and --course.
 *
 * @package     local_grupomakro_core
 * @category    cli
 * @copyright   2026 Solutto Consulting
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/academic_movement_manager.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/academic_grade_resolver.php');

global $CFG, $DB;

$opts = getopt('', ['user:', 'plan:', 'course:']);
$userid         = (int)($opts['user'] ?? 900001);
$learningplanid = (int)($opts['plan'] ?? 99);
$corecourseid   = (int)($opts['course'] ?? 99001);

$mgr      = 'local_grupomakro_academic_movement_manager';
$resolver = 'local_grupomakro_academic_grade_resolver';

$failures = 0;
$total    = 0;

function assertion(string $name, bool $cond, $expected = null, $actual = null) {
    global $failures, $total;
    $total++;
    if ($cond) {
        cli_writeln("  [PASS] $name");
        return true;
    }
    $failures++;
    $exp = is_scalar($expected) || $expected === null ? var_export($expected, true) : json_encode($expected);
    $act = is_scalar($actual) || $actual === null ? var_export($actual, true) : json_encode($actual);
    cli_writeln("  [FAIL] $name  expected=$exp actual=$act");
    return false;
}

function reset_fixture($DB, $mgr, $userid, $learningplanid, $corecourseid) {
    // Annul any leftover fixture rows from previous runs so the resolver starts
    // from a known state without breaking the unique attempt_no index.
    $DB->execute(
        'UPDATE {gmk_academic_movements} SET annulled = 1, annulled_at = ?, annul_reason = ?
         WHERE userid = ? AND learningplanid = ? AND corecourseid = ?',
        [time(), 'test_resolver_policy fixture reset', $userid, $learningplanid, $corecourseid]
    );
    $DB->delete_records('gmk_course_attempts', [
        'userid'         => $userid,
        'learningplanid' => $learningplanid,
        'corecourseid'   => $corecourseid,
    ]);
}

// T1. Best grade wins.
reset_fixture($DB, $mgr, $userid, $learningplanid, $corecourseid);
cli_writeln("T1: approved 90 + retried 60 -> best grade wins (90)");
$mgr::record_movement([
    'userid' => $userid, 'learningplanid' => $learningplanid, 'corecourseid' => $corecourseid,
    'source' => 'class_close', 'source_record_id' => 1,
    'grade' => 90.0, 'course_status' => 4, 'effective_at' => 1700000000,
]);
$mgr::record_movement([
    'userid' => $userid, 'learningplanid' => $learningplanid, 'corecourseid' => $corecourseid,
    'source' => 'class_close', 'source_record_id' => 2,
    'grade' => 60.0, 'course_status' => 5, 'effective_at' => 1700001000,
]);
$r = $resolver::resolve_official_grade($userid, $learningplanid, $corecourseid);
assertion('T1 best grade is 90', $r['grade'] === 90.0, 90.0, $r['grade']);
assertion('T1 status is approved (4)', $r['status'] === 4, 4, $r['status']);

// T2. Lower best wins when retried higher.
reset_fixture($DB, $mgr, $userid, $learningplanid, $corecourseid);
cli_writeln("T2: failed 60 + approved 71 -> best grade wins (71)");
$mgr::record_movement([
    'userid' => $userid, 'learningplanid' => $learningplanid, 'corecourseid' => $corecourseid,
    'source' => 'class_close', 'source_record_id' => 10,
    'grade' => 60.0, 'course_status' => 5, 'effective_at' => 1700000000,
]);
$mgr::record_movement([
    'userid' => $userid, 'learningplanid' => $learningplanid, 'corecourseid' => $corecourseid,
    'source' => 'revalidation', 'source_record_id' => 11,
    'grade' => 71.0, 'course_status' => 4, 'effective_at' => 1700001000,
]);
$r = $resolver::resolve_official_grade($userid, $learningplanid, $corecourseid);
assertion('T2 best grade is 71', $r['grade'] === 71.0, 71.0, $r['grade']);
assertion('T2 status is approved (4)', $r['status'] === 4, 4, $r['status']);

// T3. Tie broken by effective_at DESC.
reset_fixture($DB, $mgr, $userid, $learningplanid, $corecourseid);
cli_writeln("T3: tie 90/90 -> most recent effective_at wins");
$mgr::record_movement([
    'userid' => $userid, 'learningplanid' => $learningplanid, 'corecourseid' => $corecourseid,
    'source' => 'class_close', 'source_record_id' => 100,
    'grade' => 90.0, 'course_status' => 4, 'effective_at' => 1700000000,
]);
$mgr::record_movement([
    'userid' => $userid, 'learningplanid' => $learningplanid, 'corecourseid' => $corecourseid,
    'source' => 'class_close', 'source_record_id' => 101,
    'grade' => 90.0, 'course_status' => 4, 'effective_at' => 1700002000,
]);
$r = $resolver::resolve_official_grade($userid, $learningplanid, $corecourseid);
assertion('T3 grade 90', $r['grade'] === 90.0, 90.0, $r['grade']);
assertion('T3 most recent source_record_id (101)', $r['source'] === 'class_close' && (int)$r['effective_at'] === 1700002000,
    'effective_at=1700002000', $r['effective_at']);

// T4. All movements annulled -> no grade, status AVAILABLE.
reset_fixture($DB, $mgr, $userid, $learningplanid, $corecourseid);
cli_writeln("T4: all movements annulled -> no grade, status AVAILABLE");
$m1 = $mgr::record_movement([
    'userid' => $userid, 'learningplanid' => $learningplanid, 'corecourseid' => $corecourseid,
    'source' => 'class_close', 'source_record_id' => 200,
    'grade' => 90.0, 'course_status' => 4, 'effective_at' => 1700000000,
]);
$mgr::annul_movement($m1, 'fixture annul for T4 (test_resolver_policy)', 1);
$r = $resolver::resolve_official_grade($userid, $learningplanid, $corecourseid);
assertion('T4 grade null', $r['grade'] === null, null, $r['grade']);
assertion('T4 status available (1)', $r['status'] === 1, 1, $r['status']);

// T5. Active attempt + best antecedent -> status IN_PROGRESS, grade from history.
reset_fixture($DB, $mgr, $userid, $learningplanid, $corecourseid);
cli_writeln("T5: active attempt + best 90 -> status IN_PROGRESS, grade 90");
$mgr::record_movement([
    'userid' => $userid, 'learningplanid' => $learningplanid, 'corecourseid' => $corecourseid,
    'source' => 'class_close', 'source_record_id' => 300,
    'grade' => 90.0, 'course_status' => 4, 'effective_at' => 1700000000,
]);
$mgr::upsert_attempt([
    'userid' => $userid, 'learningplanid' => $learningplanid, 'corecourseid' => $corecourseid,
    'classid' => null, 'attempt_no' => 1, 'is_module' => 0,
    'enroll_date' => 1700000500, 'end_date' => null, 'status' => 'active', 'usermodified' => 1,
]);
$r = $resolver::resolve_official_grade($userid, $learningplanid, $corecourseid);
assertion('T5 best grade is 90', $r['grade'] === 90.0, 90.0, $r['grade']);
assertion('T5 status in_progress (2) because attempt active', $r['status'] === 2, 2, $r['status']);
assertion('T5 attempts_active >= 1', $r['attempts_active'] >= 1, '>=1', $r['attempts_active']);

cli_writeln('');
cli_writeln("Total: $total, Failures: $failures");
if ($failures > 0) {
    cli_error("Resolver policy tests FAILED ($failures failures).");
}
cli_writeln("Resolver policy tests passed.");
