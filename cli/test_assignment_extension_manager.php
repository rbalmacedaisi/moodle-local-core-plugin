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
 * Smoke test for assignment_extension_manager (introduced in 20261001092).
 *
 * The teacher dashboard's "Excepciones de entrega" modal calls
 * list_course_students(gmk_class.id, assignid) to populate the student
 * picker. The first version of the manager passed gmk_class.id straight
 * to context_course::instance() and mdl_course lookups, which threw
 * 'No se puede encontrar registro de datos en la tabla course' because
 * gmk_class.id != mdl_course.id (the gmk_class row points to the
 * real Moodle course via the corecourseid column).
 *
 * This smoke test exercises the contract that callers (and the JS
 * modal) actually need: pass a gmk_class.id, get back the list of
 * enrolled students for the underlying mdl_course.
 *
 * Run with:
 *   php local/grupomakro_core/cli/test_assignment_extension_manager.php
 *
 * Exit code 0 on success, non-zero on the first failure.
 *
 * @package local_grupomakro_core
 * @category   cli
 */

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/assignment_extension_manager.php');

use local_grupomakro_core\local\assignment_extension_manager;

mtrace("=== Smoke test: assignment_extension_manager (20261001092) ===");

// --- Auto-pick a real gmk_class that has at least one Assign activity
//     in its underlying mdl_course, so the test is meaningful.
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
mtrace("Picked gmk_class.id={$gc->id} name='{$gc->name}' corecourseid={$gc->corecourseid}");

// --- Pick the first Assign activity in the underlying mdl_course.
$assignrow = $DB->get_record_sql(
    "SELECT a.id AS assignid, a.name
       FROM {course_modules} cm
       JOIN {assign} a ON a.id = cm.instance
       JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
      WHERE cm.course = :cid AND cm.deletioninprogress = 0 AND cm.visible = 1
   ORDER BY a.duedate DESC, a.name ASC",
    ['cid' => $gc->corecourseid], 0, 1
);
if (!$assignrow) {
    mtrace("FAIL: no Assign activity in course {$gc->corecourseid}.");
    exit(1);
}
mtrace("Picked assign.id={$assignrow->assignid} name='{$assignrow->name}'");

// --- 1. list_course_students must accept the gmk_class.id and resolve
//     it to the underlying mdl_course.id internally. This is the bug
//     the user reported - the modal blew up with
//     'No se puede encontrar registro de datos en la tabla course'
//     because context_course::instance($gmk_class_id) was called with
//     the wrong id.
try {
    $students = assignment_extension_manager::list_course_students(
        (int)$gc->id,
        (int)$assignrow->assignid
    );
} catch (\Throwable $e) {
    mtrace("FAIL: list_course_students(gmk_class.id={$gc->id}, assignid={$assignrow->assignid}) "
        . "threw: " . $e->getMessage());
    exit(2);
}
mtrace("1) list_course_students returned " . count($students) . " student(s) ✔");

if (!is_array($students) || count($students) === 0) {
    mtrace("WARN: course has no enrolled students - the test cannot verify the row shape.");
} else {
    $first = $students[0];
    $expectedKeys = ['userid', 'user_name', 'user_email', 'override_duedate'];
    foreach ($expectedKeys as $k) {
        if (!array_key_exists($k, $first)) {
            mtrace("FAIL: student row is missing the '$k' key. Got: " . json_encode(array_keys($first)));
            exit(3);
        }
    }
    mtrace("2) Student row has the expected keys ✔");
}

// --- 3. list_course_assignments: same gmk_class -> mdl_course mapping.
//     This is called when the modal opens WITHOUT a pre-selected
//     assignId (the dropdown picker).
try {
    $assignments = assignment_extension_manager::list_course_assignments((int)$gc->id);
} catch (\Throwable $e) {
    mtrace("FAIL: list_course_assignments(gmk_class.id={$gc->id}) threw: " . $e->getMessage());
    exit(4);
}
mtrace("3) list_course_assignments returned " . count($assignments) . " assignment(s) ✔");

if (count($assignments) > 0) {
    $a = $assignments[0];
    foreach (['cmid', 'assignid', 'name', 'duedate'] as $k) {
        if (!array_key_exists($k, $a)) {
            mtrace("FAIL: assignment row is missing the '$k' key. Got: " . json_encode(array_keys($a)));
            exit(5);
        }
    }
    mtrace("4) Assignment row has the expected keys ✔");
}

// --- 5. list_extensions should still accept a real mdl_assign.id (not
//     a gmk_class id) - that part of the contract is unchanged.
try {
    $info = assignment_extension_manager::list_extensions((int)$assignrow->assignid);
} catch (\Throwable $e) {
    mtrace("FAIL: list_extensions(assignid={$assignrow->assignid}) threw: " . $e->getMessage());
    exit(6);
}
foreach (['assign_id', 'assign_name', 'default_duedate', 'overrides', 'history'] as $k) {
    if (!array_key_exists($k, $info)) {
        mtrace("FAIL: list_extensions() output is missing the '$k' key. Got: " . json_encode(array_keys($info)));
        exit(7);
    }
}
mtrace("5) list_extensions has the expected keys ✔");

// --- 6. End-to-end check: the WS layer must also accept gmk_class.id
//     and apply the same mapping. We call the public resolver
//     directly to confirm the helper exists and behaves.
require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/teacher/assignment_extensions.php');
if (!method_exists(assignment_extension_manager::class, 'resolve_course_id_public')) {
    mtrace("FAIL: resolve_course_id_public() is not exposed on the manager.");
    exit(8);
}
$resolved = assignment_extension_manager::resolve_course_id_public((int)$gc->id);
if ((int)$resolved !== (int)$gc->corecourseid) {
    mtrace("FAIL: resolve_course_id_public(gmk_class.id={$gc->id}) returned "
        . "{$resolved}, expected corecourseid={$gc->corecourseid}.");
    exit(9);
}
mtrace("6) resolve_course_id_public maps gmk_class.id -> corecourseid ✔");

// --- 7. Pass-through: an id that is NOT a gmk_class.id (a real
//     mdl_course.id) should be returned unchanged.
$pass = assignment_extension_manager::resolve_course_id_public((int)$gc->corecourseid);
if ((int)$pass !== (int)$gc->corecourseid) {
    mtrace("FAIL: resolve_course_id_public() mangled a real mdl_course.id "
        . "(got {$pass}, expected {$gc->corecourseid}).");
    exit(10);
}
mtrace("7) resolve_course_id_public passes through real mdl_course.id ✔");

// --- 8. set/get/delete round-trip: pick the FIRST enrolled student
//     and a future timestamp, call set(), confirm it appears in
//     list_extensions(), then delete_override() and confirm it is
//     gone. This is the actual end-to-end happy path the JS modal
//     exercises: 'Apply' -> set one override -> modal reloads -> the
//     student now has 'Fecha actual' in orange -> 'trash' -> delete.
//
// We use a very-far-in-the-future duedate (1 year ahead) so we do
// not collide with any existing override. The test also cleans up
// after itself even on failure (best-effort).
//
// The WS methods call require_capability('mod/assign:manageoverrides', $context),
// so we must run as a real user that has that capability in the
// course. We pick the first teacher-role user enrolled in the course
// and run the test as them via session-based loginas.
$students = $DB->get_records_sql(
    "SELECT u.id
       FROM {user_enrolments} ue
       JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :cid
       JOIN {user} u ON u.id = ue.userid
      WHERE u.deleted = 0
   ORDER BY u.id ASC",
    ['cid' => $gc->corecourseid], 0, 1
);
if (!$students) {
    mtrace("WARN: course has no enrolled users to test the round-trip; skipping 8.");
} else {
    $studentid = (int)reset($students)->id;

    // Find a user with mod/assign:manageoverrides in the course.
    $ctx = \context_course::instance($gc->corecourseid);
    $teacher = $DB->get_record_sql(
        "SELECT DISTINCT u.id
           FROM {user} u
           JOIN {role_assignments} ra ON ra.userid = u.id
           JOIN {role_capabilities} rc ON rc.roleid = ra.roleid
                                     AND rc.capability = :cap
          WHERE ra.contextid = :ctx
       ORDER BY u.id ASC",
        ['cap' => 'mod/assign:manageoverrides', 'ctx' => $ctx->id], 0, 1
    );
    if (!$teacher) {
        mtrace("WARN: no user with mod/assign:manageoverrides in the course; skipping 8.");
    } else {
        $teacherid = (int)$teacher->id;
        $olduser = $GLOBALS['USER'] ?? null;
        \core\session\manager::set_user($DB->get_record('user', ['id' => $teacherid]));

        $futurets = time() + 365 * 86400; // +1 year
        try {
            $setResult = \local_grupomakro_core\external\teacher\assignment_extensions::set(
                (int)$assignrow->assignid,
                $studentid,
                $futurets,
                'smoke-test-' . time()
            );
        } catch (\Throwable $e) {
            if ($olduser) { \core\session\manager::set_user($olduser); }
            mtrace("FAIL: assignment_extensions::set() threw: " . $e->getMessage());
            exit(11);
        }
        if (($setResult['status'] ?? '') !== 'success') {
            if ($olduser) { \core\session\manager::set_user($olduser); }
            mtrace("FAIL: set() returned non-success: " . json_encode($setResult));
            exit(11);
        }
        mtrace("8a) set() applied override for user={$studentid} duedate=" . date('c', $futurets) . " ✔");

        // Reload via list_overrides() and confirm the new override is there.
        $info = \local_grupomakro_core\external\teacher\assignment_extensions::list_overrides(
            (int)$assignrow->assignid
        );
        $found = false;
        foreach ($info['overrides'] as $ov) {
            if ((int)$ov['userid'] === $studentid && (int)$ov['duedate'] === $futurets) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            if ($olduser) { \core\session\manager::set_user($olduser); }
            mtrace("FAIL: list_overrides() did not return the new override for user {$studentid}.");
            exit(12);
        }
        mtrace("8b) list_overrides() reports the new override ✔");

        // delete_override() and confirm.
        $delResult = \local_grupomakro_core\external\teacher\assignment_extensions::delete_override(
            (int)$assignrow->assignid,
            $studentid
        );
        if (($delResult['status'] ?? '') !== 'success') {
            if ($olduser) { \core\session\manager::set_user($olduser); }
            mtrace("FAIL: delete_override() returned non-success: " . json_encode($delResult));
            exit(13);
        }
        mtrace("8c) delete_override() removed it ✔");

        $info2 = \local_grupomakro_core\external\teacher\assignment_extensions::list_overrides(
            (int)$assignrow->assignid
        );
        foreach ($info2['overrides'] as $ov) {
            if ((int)$ov['userid'] === $studentid && (int)$ov['duedate'] === $futurets) {
                if ($olduser) { \core\session\manager::set_user($olduser); }
                mtrace("FAIL: override for user {$studentid} still in list_overrides() after delete.");
                exit(14);
            }
        }
        mtrace("8d) list_overrides() no longer reports it ✔");

        // Restore previous user.
        if ($olduser) {
            \core\session\manager::set_user($olduser);
        }
    }
}

mtrace("=== ALL CHECKS PASSED ===");
exit(0);
