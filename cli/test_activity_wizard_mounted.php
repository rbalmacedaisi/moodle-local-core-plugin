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
 * Static checks for the ActivityCreationWizard.js mounted() hook and
 * the <activity-groups-panel> prop binding.
 *
 * Two regressions were reported by the user after the previous fix
 * (group-grading panel mount) shipped:
 *   A) "this.fetchGradeCategories is not a function" - a leftover
 *      call to a function that was deleted months ago. The mounted()
 *      hook blew up on every open of the wizard, which made the
 *      edit dialog unusable in practice.
 *   B) "Invalid prop: type check failed for prop cmid. Expected
 *      Number, got String" - the parent passed editData.id (which
 *      can come through as string under Vue 2's prop binding rules)
 *      to a child prop declared as Number.
 *
 * This test asserts both that the leftover call is gone and that
 * the cmid binding is wrapped in parseInt.
 *
 * Run with:
 *   php local/grupomakro_core/cli/test_activity_wizard_mounted.php
 *
 * Exit code 0 on success, non-zero on the first failure.
 *
 * @package local_grupomakro_core
 * @category   cli
 */

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');

mtrace("=== Static check: ActivityCreationWizard.js mounted() (20261001057) ===");

$path = $CFG->dirroot . '/local/grupomakro_core/js/components/ActivityCreationWizard.js';
$src = file_get_contents($path);
mtrace("Loaded " . strlen($src) . " bytes from $path");

// 1. No call to a non-existent fetchGradeCategories() in the
//    mounted() hook. The function was deleted in commit 3942ce9
//    but the call site was left behind, which made the wizard
//    throw on every open.
$mountedBlock = '';
if (preg_match('/mounted\s*\(\s*\)\s*\{(.*?)\n\s*\},/s', $src, $m)) {
    $mountedBlock = $m[1];
} else {
    mtrace("FAIL: cannot locate the mounted() hook block in the file.");
    exit(2);
}
if (strpos($mountedBlock, 'fetchGradeCategories') !== false) {
    mtrace("FAIL: mounted() still calls this.fetchGradeCategories() which does not exist. "
        . "This is the 'TypeError: this.fetchGradeCategories is not a function' from the user.");
    exit(3);
}
mtrace("1) mounted() no longer calls the missing fetchGradeCategories() ✔");

// 2. The mounted() hook still calls the two functions that DO exist.
$requiredCalls = ['fetchActivityDetails', 'fetchCourseTags'];
foreach ($requiredCalls as $fn) {
    if (strpos($mountedBlock, $this_fn = "this.{$fn}(") === false) {
        mtrace("FAIL: mounted() lost its call to $this_fn. That would break "
            . "the edit flow.");
        exit(4);
    }
}
mtrace("2) mounted() still calls fetchActivityDetails and fetchCourseTags ✔");

// 3. The <activity-groups-panel> binding must wrap cmid in
//    parseInt() so the child prop (declared as Number) does not
//    get a String from Vue 2's prop coercion.
if (!preg_match('/<activity-groups-panel[\s\S]{0,600}:cmid="parseInt\(\s*editData\.id\s*,\s*10\s*\)"/', $src)) {
    mtrace("FAIL: <activity-groups-panel> does not bind :cmid via parseInt. "
        . "Without this, Vue 2 may pass a String and the child's "
        . "type:Number prop check fails.");
    exit(5);
}
mtrace("3) <activity-groups-panel :cmid> is wrapped in parseInt() ✔");

// 4. The activity-groups-panel must be guarded by editMode +
//    editData + enableGroupGrading. Otherwise it could try to
//    load groups for an activity that has not been created yet.
if (!preg_match('/<activity-groups-panel\s+v-if="editMode\s*&&\s*editData\s*&&\s*editData\.id\s*&&\s*formData\.enableGroupGrading"/', $src)) {
    mtrace("FAIL: <activity-groups-panel> v-if is missing the editMode + "
        . "editData + enableGroupGrading guard. It would try to load "
        . "groups for a brand-new activity that has no cmid yet.");
    exit(6);
}
mtrace("4) <activity-groups-panel> is properly guarded by editMode + editData + enableGroupGrading ✔");

mtrace("=== ALL CHECKS PASSED ===");
exit(0);
