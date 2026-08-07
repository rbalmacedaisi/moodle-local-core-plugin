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
 * Test CLI for the status_change_manager (aplazo / retirar / reactivation).
 *
 * Exercises the main business flows without a PHPUnit rig:
 *   1. validation: reason < 10 chars rejected
 *   2. validation: aplazar without target period rejected
 *   3. aplazar: drops active courses, writes suspension row, flips profile
 *   4. retirar: drops active courses, writes suspension row, flips profile
 *   5. reactivation (renovar on retirado): drops courses, flips back to activo
 *   6. get_history: returns the rows in reverse chronological order
 *
 * Fixtures use synthetic user 900002 + plan 99 + course 99001 to avoid
 * touching real students. Tests rollback at the end so the DB is left
 * clean.
 *
 * Usage:
 *   php local/grupomakro_core/cli/test_status_change_manager.php
 *
 * Exits 0 on success, 1 on any failed assertion.
 *
 * @package     local_grupomakro_core
 * @category    cli
 * @copyright   2026 Solutto Consulting
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/progress_manager.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/status_change_manager.php');
require_once($CFG->libdir . '/clilib.php');

global $DB;

const TEST_USERID         = 900002;
const TEST_PLANID         = 99;
const TEST_COURSEID       = 99001;
const TEST_PERIOD_ID      = 1;
const TEST_PERIOD_NAME    = 'TEST-PERIOD-AUTOMATICO';

/**
 * Tiny assert helper. Prints PASS/FAIL and tracks exit code.
 */
$failures = 0;
$tests    = 0;

function check(string $name, bool $cond, string $detail = ''): void {
    global $failures, $tests;
    $tests++;
    if ($cond) {
        cli_writeln("  PASS  $name");
    } else {
        $failures++;
        cli_writeln("  FAIL  $name  $detail");
    }
}

function check_eq(string $name, $expected, $actual): void {
    global $failures, $tests;
    $tests++;
    if ($expected === $actual) {
        cli_writeln("  PASS  $name");
    } else {
        $failures++;
        cli_writeln("  FAIL  $name  expected: " . var_export($expected, true) . " got: " . var_export($actual, true));
    }
}

function section(string $title): void {
    cli_writeln("");
    cli_writeln("=== $title ===");
}

/**
 * Setup: ensure the synthetic user/plan/course exist and the student has
 * known state (activo, no active courses).
 *
 * Returns ['user' => $user, 'lpu' => $lpUser] on success.
 *
 * @return array
 */
function setup_fixture(): array {
    global $DB;

    // Ensure user.
    if (!$DB->record_exists('user', ['id' => TEST_USERID])) {
        $user = (object)[
            'id' => TEST_USERID,
            'username' => 'gmk_test_statuschange',
            'firstname' => 'Test',
            'lastname' => 'StatusChange',
            'email' => 'gmk_test_statuschange@isi.edu.pa',
            'mnethostid' => $DB->get_field('mnethost', 'id', ['wwwroot' => 'http://localhost']),
            'confirmed' => 1,
            'suspended' => 0,
            'deleted' => 0,
        ];
        $user->id = $DB->insert_record('user', $user);
    }
    $user = $DB->get_record('user', ['id' => TEST_USERID]);

    // Ensure learning plan.
    if (!$DB->record_exists('local_learning_plans', ['id' => TEST_PLANID])) {
        $DB->insert_record('local_learning_plans', (object)[
            'id' => TEST_PLANID,
            'name' => 'Test Plan StatusChange',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    // Ensure course.
    if (!$DB->record_exists('course', ['id' => TEST_COURSEID])) {
        $course = (object)[
            'id' => TEST_COURSEID,
            'category' => 1,
            'fullname' => 'Test Course StatusChange',
            'shortname' => 'TEST-SC',
            'summary' => 'fixture',
            'format' => 'topics',
            'timecreated' => time(),
            'timemodified' => time(),
            'visible' => 1,
        ];
        $DB->insert_record('course', $course);
    }

    // Ensure the lp_user row in estado activo.
    $lpu = $DB->get_record('local_learning_users', ['userid' => TEST_USERID, 'learningplanid' => TEST_PLANID]);
    $lpu->status = 'activo';
    $lpu->currentperiodid = 1;
    $lpu->currentsubperiodid = 1;
    $lpu->academicperiodid = TEST_PERIOD_ID;
    $lpu->timemodified = time();
    if ($DB->record_exists('local_learning_users', ['id' => $lpu->id])) {
        $DB->update_record('local_learning_users', $lpu);
    } else {
        $lpu->userid = TEST_USERID;
        $lpu->learningplanid = TEST_PLANID;
        $lpu->id = $DB->insert_record('local_learning_users', $lpu);
    }

    // Clean active courses.
    $DB->delete_records('gmk_course_progre', ['userid' => TEST_USERID, 'learningplanid' => TEST_PLANID]);

    // Clean previous suspension rows.
    $DB->delete_records('gmk_student_suspension', ['userid' => TEST_USERID]);

    return ['user' => $user, 'lpu' => $lpu];
}

/**
 * Insert a fake "currently in progress" course for the user/plan.
 */
function add_active_course(int $corecourseid, int $classid = 901, int $groupid = 9): int {
    global $DB;
    $rec = (object)[
        'userid' => TEST_USERID,
        'learningplanid' => TEST_PLANID,
        'courseid' => $corecourseid,
        'classid' => $classid,
        'groupid' => $groupid,
        'status' => COURSE_IN_PROGRESS,
        'progress' => 50.0,
        'grade' => 0,
        'timemodified' => time(),
        'timecreated' => time(),
    ];
    return $DB->insert_record('gmk_course_progre', $rec);
}

/**
 * Setup the periodos.periodo equivalent (gmk_academic_periods) row.
 */
function ensure_period(): int {
    global $DB;
    if (!$DB->record_exists('gmk_academic_periods', ['id' => TEST_PERIOD_ID])) {
        $DB->insert_record('gmk_academic_periods', (object)[
            'id' => TEST_PERIOD_ID,
            'name' => TEST_PERIOD_NAME,
            'startdate' => time() - 86400,
            'enddate' => time() + 86400 * 30,
            'status' => 1,
        ]);
    }
    return TEST_PERIOD_ID;
}

ensure_period();
$fixture = setup_fixture();

cli_writeln("=== Status change manager test suite ===");

// ============================================================================
// 1. Validation: reason too short
// ============================================================================
section("1. Validation: reason too short");
$result = local_grupomakro_status_change_manager::execute(
    TEST_USERID,
    'aplazar',
    'corto',  // < 10 chars
    TEST_PERIOD_ID
);
check_eq('status', 'error', $result['status'] ?? '?');
check('contains motivo-low-chars message', str_contains($result['message'] ?? '', '10'),
    'message: ' . ($result['message'] ?? ''));

// ============================================================================
// 2. Validation: aplazar without target_period_id
// ============================================================================
section("2. Validation: aplazar without target_period_id");
$result = local_grupomakro_status_change_manager::execute(
    TEST_USERID,
    'aplazar',
    'Motivo valido de mas de diez caracteres',
    null
);
check_eq('status', 'error', $result['status'] ?? '?');
check('contains periodo message', str_contains($result['message'] ?? '', 'periodo'),
    'message: ' . ($result['message'] ?? ''));

// ============================================================================
// 3. Executing aplazar: drops courses, writes suspension, flips profile
// ============================================================================
section("3. Aplazar drops active courses + writes suspension");

$active1 = add_active_course(TEST_COURSEID, 901, 9);
$active2 = add_active_course(99002, 902, 9);

$result = local_grupomakro_status_change_manager::execute(
    TEST_USERID,
    'aplazar',
    'Aplazo de prueba - problemas economicos',
    TEST_PERIOD_ID,
    $USER->id ?? 2
);

check_eq('status', 'success', $result['status'] ?? '?');
check_eq('newstatus', 'aplazado', $result['data']['newstatus'] ?? '?');
check_eq('droppedcourses_count', 2, count($result['data']['courses_dropped'] ?? []));

$lpu = $DB->get_record('local_learning_users', ['userid' => TEST_USERID, 'learningplanid' => TEST_PLANID]);
check_eq('local_learning_users.status', 'aplazado', $lpu->status);

$courses_after = $DB->get_records('gmk_course_progre', ['userid' => TEST_USERID, 'learningplanid' => TEST_PLANID]);
foreach ($courses_after as $c) {
    check_eq('course classid cleared', 0, (int)$c->classid);
    check_eq('course groupid cleared', 0, (int)$c->groupid);
    check_eq('course status reset', COURSE_AVAILABLE, (int)$c->status);
}

$suspension = $DB->get_record('gmk_student_suspension',
    ['userid' => TEST_USERID, 'status' => 'aplazo'],
    '*',
    IGNORE_MULTIPLE
);
check('suspension row exists', !empty($suspension));
check_eq('suspension.target_period_id', TEST_PERIOD_ID, (int)($suspension->targetperiodid ?? 0));
check_eq('suspension.origin', 'lxp', $suspension->origin ?? '');

$details = json_decode($suspension->details ?? '{}', true);
check('details dropped_courses populated', isset($details['dropped_courses']) && count($details['dropped_courses']) === 2);

// ============================================================================
// 4. Executing retirar
// ============================================================================
section("4. Retirar drops active courses + writes suspension");

// Setup active courses again (after aplaz they were cleared).
add_active_course(TEST_COURSEID, 901, 9);
add_active_course(99002, 902, 9);

$result = local_grupomakro_status_change_manager::execute(
    TEST_USERID,
    'retirar',
    'Retiro de prueba - decision personal',
    null,
    $USER->id ?? 2
);

check_eq('status', 'success', $result['status'] ?? '?');
check_eq('newstatus', 'retirado', $result['data']['newstatus'] ?? '?');

$lpu = $DB->get_record('local_learning_users', ['userid' => TEST_USERID, 'learningplanid' => TEST_PLANID]);
check_eq('local_learning_users.status', 'retirado', $lpu->status);

$suspension = $DB->get_record('gmk_student_suspension',
    ['userid' => TEST_USERID, 'status' => 'retiro'],
    '*',
    IGNORE_MULTIPLE
);
check('retiro suspension row exists', !empty($suspension));
check_eq('retiro.target_period_id', null, $suspension->targetperiodid ?? -1);
check_eq('retiro.origin', 'lxp', $suspension->origin ?? '');

$courses_after = $DB->get_records('gmk_course_progre', ['userid' => TEST_USERID, 'learningplanid' => TEST_PLANID]);
foreach ($courses_after as $c) {
    check_eq('course classid cleared post-retiro', 0, (int)$c->classid);
    check_eq('course status reset post-retiro', COURSE_AVAILABLE, (int)$c->status);
}

// ============================================================================
// 5. get_history returns rows in reverse chronological order
// ============================================================================
section("5. get_history returns rows in chronological order");
$history = local_grupomakro_status_change_manager::get_history(TEST_USERID);
check('history has at least 2 entries', count($history) >= 2,
    'got ' . count($history));

// Most recent first: retiro should be before aplaz.
$statuses = array_column($history, 'status');
check('retiro appears before aplaz', array_search('retiro', $statuses) < array_search('aplazo', $statuses),
    'order: ' . implode(',', $statuses));

// All rows have origin='lxp'.
$origins = array_unique(array_column($history, 'origin'));
check_eq('all origins are lxp', 'lxp', implode('|', $origins));

// Reasons are preserved.
$reasons = array_filter(array_column($history, 'reason'));
check('reasons populated', count($reasons) >= 2, 'reasons: ' . implode('|', $reasons));

// ============================================================================
// 6. drop_active_courses_for_user helper
// ============================================================================
section("6. drop_active_courses_for_user helper");
add_active_course(TEST_COURSEID, 901, 9);
$dropped = local_grupomakro_progress_manager::drop_active_courses_for_user(
    TEST_USERID, 'test_run', 2
);
check('returns array of courseids', is_array($dropped));
check('returns at least 1 courseid', count($dropped) >= 1, 'got ' . implode(',', $dropped));

$still_active = $DB->count_records('gmk_course_progre', [
    'userid' => TEST_USERID,
    'learningplanid' => TEST_PLANID,
    'status' => COURSE_IN_PROGRESS,
]);
check_eq('no active courses remain', 0, $still_active);

// ============================================================================
// 7. suggest_reactivation_period
// ============================================================================
section("7. suggest_reactivation_period returns current or last");
$now = time();
$row = local_grupomakro_progress_manager::suggest_reactivation_period($now);
check('returns a row', !empty($row));
check('has id', isset($row->id));

// ============================================================================
// CLEANUP
// ============================================================================
section("Cleanup");
$DB->delete_records('gmk_student_suspension', ['userid' => TEST_USERID]);
$DB->delete_records('gmk_course_progre', ['userid' => TEST_USERID, 'learningplanid' => TEST_PLANID]);
$DB->delete_records('local_learning_users', ['userid' => TEST_USERID, 'learningplanid' => TEST_PLANID]);
$DB->delete_records('user', ['id' => TEST_USERID]);
$DB->delete_records('course', ['id' => TEST_COURSEID]);
$DB->delete_records('course', ['id' => 99002]);
$DB->delete_records('local_learning_plans', ['id' => TEST_PLANID]);
$DB->delete_records('gmk_academic_periods', ['id' => TEST_PERIOD_ID]);
cli_writeln("  cleanup done");

// ============================================================================
// SUMMARY
// ============================================================================
cli_writeln("");
cli_writeln("=== Summary ===");
cli_writeln("$tests tests, $failures failures");

if ($failures > 0) {
    cli_writeln("FAILED");
    exit(1);
}
cli_writeln("OK");
exit(0);