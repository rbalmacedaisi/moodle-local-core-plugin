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
 * Smoke test for the group-grading dispatch contracts.
 *
 * Covers the endpoints that power the "calificacion grupal" feature:
 *   - activity_group_list          (list groups for an activity)
 *   - save_group_grade             (apply one grade to all members)
 *   - save_group_quiz_grade         (apply one grade to all members in a quiz)
 *   - gmk_activity_grading_flag     (the per-activity toggle row)
 *
 * Each check pins a field name + shape that the JS panel and the
 * QuickGrader modal rely on. If a future refactor renames a key
 * or changes the wrapper around the response, the smoke test
 * catches it before the user does.
 *
 * Run with:
 *   php local/grupomakro_core/cli/test_group_grading_dispatch.php
 *
 * Exit code 0 on success, non-zero on the first failure.
 *
 * @package local_grupomakro_core
 * @category   cli
 */

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

mtrace("=== Smoke test: group-grading dispatch contracts (20261001057) ===");

// --- 0. We need a real Assign activity in a real gmk_class that is
//     currently flagged for group grading. Pick the first one we find.
global $DB;
$row = $DB->get_record_sql(
    "SELECT f.cmid, f.mode, f.maxmembers,
            cm.instance AS assignmentid, cm.course AS corecourseid,
            gc.id AS classid
       FROM {gmk_activity_grading_flag} f
       JOIN {course_modules} cm ON cm.id = f.cmid
       JOIN {gmk_class} gc ON gc.corecourseid = cm.course
      WHERE f.enabled = 1
        AND f.modname = 'assign'
   ORDER BY f.cmid ASC",
    null, 0, 1
);
if (!$row) {
    mtrace("WARN: no gmk_activity_grading_flag with enabled=1 found in the DB. "
        . "Skipping the integration round-trip and only doing the static contract checks.");
    $skipRoundtrip = true;
} else {
    $skipRoundtrip = false;
    mtrace("Picked cmid={$row->cmid} assignmentid={$row->assignmentid} classid={$row->classid} mode={$row->mode}");
}

// --- 1. Static check: the dispatch in ajax.php must register the
//     4 actions the feature relies on.
$ajax = file_get_contents($CFG->dirroot . '/local/grupomakro_core/ajax.php');
$requiredActions = [
    'local_grupomakro_activity_group_list',
    'local_grupomakro_activity_group_create',
    'local_grupomakro_activity_group_update_members',
    'local_grupomakro_activity_group_delete',
    'local_grupomakro_activity_group_set_mode',
    'local_grupomakro_save_group_grade',
    'local_grupomakro_save_group_quiz_grade',
    'local_grupomakro_activity_group_join',
    'local_grupomakro_activity_group_leave',
];
foreach ($requiredActions as $action) {
    if (strpos($ajax, "case '$action':") === false) {
        mtrace("FAIL: ajax.php does not register the action '$action'. "
            . "The frontend will get a 'no action' error.");
        exit(2);
    }
}
mtrace("1) All 9 group-grading actions are registered in ajax.php ✔");

// --- 2. Static check: the 6 teacher-side actions must reach a real
//     WS class (not just the dispatch case statement).
$expectedClasses = [
    'activity_group_list',
    'activity_group_create',
    'activity_group_update_members',
    'activity_group_delete',
    'activity_group_set_mode',
    'save_group_grade',
    'save_group_quiz_grade',
];
foreach ($expectedClasses as $cls) {
    $path = $CFG->dirroot . '/local/grupomakro_core/classes/external/teacher/' . $cls . '.php';
    if (!is_file($path)) {
        mtrace("FAIL: expected WS class $cls not found at $path");
        exit(3);
    }
    mtrace("2a) $cls.php exists ✔");
}
mtrace("2) All 7 teacher-side WS classes exist on disk ✔");

// --- 3. Static check: the response shape of activity_group_list must
//     include flag, groups (each with id, name, maxmembers, mode,
//     colorindex, membercount, isfull, members[]). If a future
//     refactor renames any of these the panel will break.
$activitygrouplist = file_get_contents(
    $CFG->dirroot . '/local/grupomakro_core/classes/external/teacher/activity_group_list.php'
);
$requiredFields = [
    "'flag'", "'groups'",
    "'id'", "'cmid'", "'modname'",
    "'name'", "'maxmembers'", "'mode'", "'colorindex'",
    "'membercount'", "'isfull'", "'isempty'",
    "'members'",
];
foreach ($requiredFields as $f) {
    if (strpos($activitygrouplist, $f) === false) {
        mtrace("FAIL: activity_group_list response shape is missing $f. "
            . "The ActivityGroupsPanel would render undefined/empty for that key.");
        exit(4);
    }
}
mtrace("3) activity_group_list response shape is complete ✔");

// --- 4. Static check: save_group_grade must implement the two-call
//     protocol (pre-flight + confirm) - the JS modal relies on
//     status='warning' to decide whether to show the confirm dialog.
$savegroupgrade = file_get_contents(
    $CFG->dirroot . '/local/grupomakro_core/classes/external/teacher/save_group_grade.php'
);
if (strpos($savegroupgrade, "'warning'") === false
    || strpos($savegroupgrade, "'confirm'") === false) {
    mtrace("FAIL: save_group_grade does not implement the warning/confirm "
        . "protocol that the GroupGradeConfirmModal relies on.");
    exit(5);
}
mtrace("4) save_group_grade supports the warning/confirm two-call protocol ✔");

// --- 5. Live round-trip (only if the DB has the seed data).
if (!$skipRoundtrip) {
    // 5a. Create a throwaway group so the test does not interfere
    //     with production data.
    $groupname = 'GMK_SMOKE_TEST_' . time();
    $createclass = '\\local_grupomakro_core\\external\\teacher\\activity_group_create';
    $created = $createclass::execute(
        (int)$row->cmid,
        'assign',
        $groupname,
        (int)$row->maxmembers,
        (string)$row->mode,
        []
    );
    if (($created['status'] ?? '') !== 'success') {
        mtrace("FAIL: activity_group_create::execute() did not return success: "
            . json_encode($created));
        exit(6);
    }
    $groupid = (int)($created['groupid'] ?? 0);
    if ($groupid <= 0) {
        mtrace("FAIL: created group has no id: " . json_encode($created));
        exit(6);
    }
    mtrace("5a) activity_group_create::execute() returned status=success groupid=$groupid ✔");

    // 5b. Delete it again so we do not leave leftovers. Force=true
    //     is not needed (we created with 0 members), but pass it
    //     anyway for symmetry with the real flow.
    $deleteclass = '\\local_grupomakro_core\\external\\teacher\\activity_group_delete';
    $deleted = $deleteclass::execute($groupid, false);
    if (($deleted['status'] ?? '') !== 'success') {
        mtrace("FAIL: activity_group_delete::execute() did not return success: "
            . json_encode($deleted));
        exit(7);
    }
    mtrace("5b) activity_group_delete::execute() removed the throwaway group ✔");
}

mtrace("=== ALL CHECKS PASSED ===");
exit(0);
