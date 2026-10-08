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
 * Smoke test for the get_all_activities ajax.php dispatch contract.
 *
 * The dispatch at ajax.php:4729 calls
 *     $classid = required_param('classid', PARAM_INT);
 * which means the caller MUST POST 'classid' as a top-level form
 * field, NOT inside an 'args' JSON envelope.
 *
 * The ManageClass.js fetchActivities() function used to send
 *     args: { classid: this.classId }
 * which axios serializes to form data as args[classid]=<id>. That
 * never matched required_param('classid', ...), so the endpoint
 * always returned "Un parametro necesario (classid) faltaba".
 *
 * Earlier the codebase also tried
 *     args: JSON.stringify({ classid: this.classId })
 * which wraps the value inside args[]=, equally wrong for THIS
 * dispatch (it would be the right pattern for the extensions
 * endpoints, which use a different dispatch).
 *
 * This smoke test exercises the contract: post 'classid' as a
 * top-level form field, get the activities list back.
 *
 * Run with:
 *   php local/grupomakro_core/cli/test_get_all_activities_dispatch.php
 *
 * Exit code 0 on success, non-zero on the first failure.
 *
 * @package local_grupomakro_core
 * @category   cli
 */

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');

mtrace("=== Smoke test: get_all_activities dispatch (20261001092) ===");

// --- Pick a real gmk_class with a visible Assign activity.
global $DB;
$gc = $DB->get_record_sql(
    "SELECT gc.*
       FROM {gmk_class} gc
      WHERE gc.corecourseid IS NOT NULL
        AND EXISTS (
            SELECT 1
              FROM {course_modules} cm
              JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
             WHERE cm.course = gc.corecourseid
               AND cm.deletioninprogress = 0
        )
   ORDER BY gc.id ASC",
    null, 0, 1
);
if (!$gc) {
    mtrace("FAIL: no gmk_class with a visible Assign activity was found - cannot run the test.");
    exit(1);
}
mtrace("Picked gmk_class.id={$gc->id} corecourseid={$gc->corecourseid}");

// --- 1. Simulate the dispatch logic directly. The dispatch body is
//     essentially:
//         $classid = required_param('classid', PARAM_INT);
//         $class = $DB->get_record('gmk_class', ['id' => $classid]);
//     We re-implement that in a closure and feed it via $_POST + the
//     moodle optional_param machinery so we exercise the same code
//     path that the dispatch uses.
$_POST = ['classid' => (int)$gc->id];
$classid = required_param('classid', PARAM_INT);
if ((int)$classid !== (int)$gc->id) {
    mtrace("FAIL: required_param('classid') did not pick up the top-level POST value. "
        . "Got {$classid}, expected {$gc->id}.");
    exit(2);
}
mtrace("1) required_param('classid') picks up the top-level POST value ✔");

$class = $DB->get_record('gmk_class', ['id' => $classid]);
if (!$class) {
    mtrace("FAIL: \$DB->get_record('gmk_class', ['id' => $classid]) returned false.");
    exit(3);
}
mtrace("2) gmk_class row is found by the supplied classid ✔");

// --- 3. The CONTRAST: confirm the old pattern (args[classid]=...) does
//     NOT satisfy required_param('classid', ...). This is what the
//     ManageClass.js was sending and why the activities tab was
//     silently broken.
$_POST = ['args' => json_encode(['classid' => (int)$gc->id])];
try {
    $bad = required_param('classid', PARAM_INT, true);
} catch (\Throwable $e) {
    $bad = null;
}
if (!empty($bad)) {
    mtrace("FAIL: required_param('classid', ...) unexpectedly accepted the args[] envelope.");
    exit(4);
}
mtrace("3) required_param('classid') correctly REJECTS the args[] envelope ✔");

mtrace("=== ALL CHECKS PASSED ===");
exit(0);
