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
 * Static checks for the "Gestionar grupos" button + dialog wiring.
 *
 * The previous fix (commit 741339f) mounted the ActivityGroupsPanel
 * INSIDE the ActivityCreationWizard edit dialog. The user said
 * "no se donde configuro los grupos" - the panel was technically
 * present but the entry point (an obscure pencil icon deep in a
 * form with 20+ fields) was not discoverable.
 *
 * This commit adds a dedicated "Gestionar grupos" button right on
 * the activity card in the Actividades tab, plus a standalone
 * dialog that opens the ActivityGroupsPanel directly. The user
 * no longer has to go through the edit wizard.
 *
 * This test pins:
 *   1) The button is rendered with v-if on enableGroupGrading
 *   2) The button calls openGroupsFor(activity)
 *   3) The method exists and sets groupsActivity + groupsOpen
 *   4) The dialog v-if checks groupsActivity and uses parseInt
 *   5) The backend payload includes enableGroupGrading per
 *      activity
 *
 * Run with:
 *   php local/grupomakro_core/cli/test_groups_button_wiring.php
 *
 * Exit code 0 on success, non-zero on the first failure.
 *
 * @package local_grupomakro_core
 * @category   cli
 */

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');

mtrace("=== Static check: groups-management button + dialog wiring (20261001080) ===");

$manageclass = file_get_contents(
    $CFG->dirroot . '/local/grupomakro_core/js/components/ManageClass.js'
);
$ajax = file_get_contents($CFG->dirroot . '/local/grupomakro_core/ajax.php');
$locallib = file_get_contents($CFG->dirroot . '/local/grupomakro_core/locallib.php');
mtrace("Loaded ManageClass.js (" . strlen($manageclass) . " bytes) + ajax.php (" . strlen($ajax) . ") + locallib.php (" . strlen($locallib) . ")");

// 1. The "Gestionar grupos" button is rendered and guarded by
//    enableGroupGrading. Without the v-if the button would show
//    on every activity card and the user would click it only to
//    get an empty alert from the panel ("actividad no fue creada
//    con la opcion de calificacion grupal").
if (!preg_match(
    '/<v-tooltip\s+v-if="\(\s*activity\.modname\s*===\s*[\'"]assign[\'"]\s*\|\|\s*activity\.modname\s*===\s*[\'"]quiz[\'"]\s*\)\s*&&\s*activity\.enableGroupGrading"/',
    $manageclass
)) {
    mtrace("FAIL: the 'Gestionar grupos' button is missing the v-if guard. "
        . "It must only appear when the activity supports group grading AND "
        . "has enableGroupGrading=1.");
    exit(2);
}
mtrace("1) 'Gestionar grupos' button is guarded by enableGroupGrading ✔");

// 2. The button calls openGroupsFor(activity).
if (!preg_match('/@click\.stop\.prevent="openGroupsFor\(activity\)"/', $manageclass)) {
    mtrace("FAIL: the 'Gestionar grupos' button does not call openGroupsFor(activity).");
    exit(3);
}
mtrace("2) The button calls openGroupsFor(activity) ✔");

// 3. The openGroupsFor method exists and updates the two reactive
//    data properties groupsActivity and groupsOpen.
if (!preg_match('/openGroupsFor\s*\(\s*activity\s*\)\s*\{[\s\S]*?this\.groupsActivity\s*=\s*activity[\s\S]*?this\.groupsOpen\s*=\s*true/s', $manageclass)) {
    mtrace("FAIL: openGroupsFor is missing or does not set the required "
        . "data properties (groupsActivity, groupsOpen).");
    exit(4);
}
mtrace("3) openGroupsFor sets groupsActivity + groupsOpen ✔");

// 4. The standalone dialog is mounted with v-if=groupsActivity and
//    wraps the cmid in parseInt (the prop validation is Number).
if (!preg_match(
    '/<v-dialog\s+v-if="groupsActivity"[^>]*v-model="groupsOpen"/',
    $manageclass
)) {
    mtrace("FAIL: the standalone 'Gestionar grupos' dialog is missing "
        . "or its v-model is not bound to groupsOpen.");
    exit(5);
}
if (!preg_match(
    '/<activity-groups-panel\s+[^>]*:cmid="parseInt\(groupsActivity\.id,\s*10\)"/',
    $manageclass
)) {
    mtrace("FAIL: the standalone dialog does not wrap the cmid binding in parseInt. "
        . "Without this, the Number prop check will fail with a Vue warn.");
    exit(6);
}
mtrace("4) Dialog uses parseInt(cmids) for the cmid binding ✔");

// 5. The backend payload includes enableGroupGrading per activity
//    so the v-if on the button can decide whether to render.
if (strpos($ajax, "'enableGroupGrading'") === false) {
    mtrace("FAIL: the activities list endpoint does not return "
        . "enableGroupGrading per activity. The button's v-if cannot decide "
        . "when to render.");
    exit(7);
}
mtrace("5) activities list endpoint returns enableGroupGrading per activity ✔");

// 6. The helper function gmk_get_activity_grading_flag_is_enabled
//    exists in locallib.php.
if (strpos($locallib, 'function gmk_get_activity_grading_flag_is_enabled(') === false) {
    mtrace("FAIL: the helper gmk_get_activity_grading_flag_is_enabled() is missing from locallib.php. "
        . "The activities list endpoint will fatal.");
    exit(8);
}
mtrace("6) gmk_get_activity_grading_flag_is_enabled() helper exists ✔");

mtrace("=== ALL CHECKS PASSED ===");
exit(0);
