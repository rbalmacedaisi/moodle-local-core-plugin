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
 * Static checks for the "create then immediately manage groups"
 * flow (20261001080, rounds 2 and 3).
 *
 * Round 1: emit 'created-with-groups' + parent flips isEditing.
 * Round 2: backend confirm — the wizard must read the
 *          groupgrading.enabled value from the create response
 *          (the source of truth for whether the flag was actually
 *          persisted in the DB) instead of trusting only the form
 *          switch. If the backend says enabled=0 (e.g. the user
 *          toggled it off between the time they pressed Submit and
 *          the response came back), we must NOT enter the
 *          create-then-manage flow because the panel would render
 *          with the alert "Esta actividad no fue creada con la
 *          opcion de calificacion grupal".
 * Round 3: panel must render on the FIRST render after re-mount
 *          (not just after fetchActivityDetails resolves). The
 *          parent now passes enableGroupGrading=true inside
 *          editActivityData, and the v-if uses editData.enableGroupGrading
 *          OR formData.enableGroupGrading. This avoids the
 *          "wizard stays open but panel is missing" symptom the
 *          user kept seeing.
 *
 * This test pins:
 *  1) The submit() handler validates the backend response
 *     (groupgrading.enabled) and emits the new event ONLY if the
 *     backend confirms the flag was persisted.
 *  2) The submit() button text becomes "Crear y gestionar grupos"
 *     when the activity supports grading and the switch is on.
 *  3) The ManageClass parent listens for the new event.
 *  4) The parent handler flips isEditing + editData (WITH
 *     enableGroupGrading=true so the panel renders on first
 *     render) without closing the dialog.
 *  5) The wizard's fetchActivityDetails() reads the group-grading
 *     flags from the backend response and applies them to formData
 *     (defense in depth: if the user later re-edits the activity
 *     and the DB is the source of truth, formData gets restored).
 *  6) The backend's get_activity_details returns the group-grading
 *     flags so the wizard can restore them on re-mount.
 *  7) The v-if of the <activity-groups-panel> consults
 *     editData.enableGroupGrading (not just formData).
 *
 * Run with:
 *   php local/grupomakro_core/cli/test_created_with_groups.php
 *
 * Exit code 0 on success, non-zero on the first failure.
 *
 * @package local_grupomakro_core
 * @category   cli
 */

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');

mtrace("=== Static check: create-then-manage-groups flow (20261001080) ===");

$wizard = file_get_contents(
    $CFG->dirroot . '/local/grupomakro_core/js/components/ActivityCreationWizard.js'
);
$parent = file_get_contents(
    $CFG->dirroot . '/local/grupomakro_core/js/components/ManageClass.js'
);
mtrace("Loaded wizard (" . strlen($wizard) . ") and parent (" . strlen($parent) . ")");

// 1. The wizard submit() path must validate the backend response
//    (groupgrading.enabled) before deciding to enter the
//    create-then-manage flow.
if (!preg_match(
    '/groupgrading[\s\S]{0,200}?backendEnabled[\s\S]{0,200}?createdWithGroups/',
    $wizard
)) {
    mtrace("FAIL: the wizard does not read the backend's groupgrading.enabled "
        . "to decide createdWithGroups. Without that check, the create-then-manage "
        . "flow would enter even when the flag was not persisted (e.g. the user "
        . "toggled it off between submit and response).");
    exit(2);
}
mtrace("1) submit() reads backend groupgrading.enabled to validate createdWithGroups ✔");

if (!preg_match("/\\\$emit\(['\"]created-with-groups['\"]/", $wizard)) {
    mtrace("FAIL: the wizard never emits 'created-with-groups'.");
    exit(3);
}
mtrace("2) Wizard emits 'created-with-groups' with cmid/modname/name ✔");

// 2. The submit() button text must say 'Crear y gestionar grupos'
//    when the activity supports grading and the switch is on.
if (!preg_match(
    "/Crear y gestionar grupos/",
    $wizard
)) {
    mtrace("FAIL: the submit button text is missing the 'Crear y gestionar grupos' "
        . "variant for the create-then-manage flow.");
    exit(4);
}
mtrace("3) Submit button text changes to 'Crear y gestionar grupos' when the flag is on ✔");

// 3. The ManageClass parent listens for the event.
if (!preg_match(
    '/@created-with-groups="onActivityCreatedWithGroups"/',
    $parent
)) {
    mtrace("FAIL: the ManageClass parent does not listen for 'created-with-groups'.");
    exit(5);
}
mtrace("4) Parent listens for @created-with-groups ✔");

// 4. The parent handler flips isEditing + editData with the new cmid
//    AND passes enableGroupGrading=true so the panel mounts on the
//    FIRST render after the re-mount.
if (!preg_match(
    '/onActivityCreatedWithGroups\s*\(\s*payload\s*\)\s*\{[\s\S]*?this\.isEditing\s*=\s*true[\s\S]*?this\.editActivityData\s*=\s*\{[\s\S]*?id:\s*payload\.cmid[\s\S]*?enableGroupGrading:\s*true/s',
    $parent
)) {
    mtrace("FAIL: onActivityCreatedWithGroups does not flip isEditing + editData "
        . "with the new cmid AND enableGroupGrading=true. Without the flag in "
        . "editData, the panel's v-if would evaluate to false on the first render.");
    exit(6);
}
mtrace("5) onActivityCreatedWithGroups flips isEditing + editData with cmid AND enableGroupGrading=true ✔");

// 5. The wizard's fetchActivityDetails() must read the group-grading
//    flags from the backend response and apply them to formData.
if (!preg_match(
    '/act\.enableGroupGrading\s*===\s*true\s*\|\|\s*act\.enableGroupGrading\s*===\s*1/',
    $wizard
)) {
    mtrace("FAIL: fetchActivityDetails() does not read enableGroupGrading from the backend. "
        . "The create-then-manage flow would re-mount the wizard with the flag reset to false.");
    exit(7);
}
mtrace("6) fetchActivityDetails() applies enableGroupGrading from the backend response ✔");

// 6. The backend's get_activity_details must return the group-grading
//    flags so the wizard can restore them on re-mount.
$ajax = file_get_contents($CFG->dirroot . '/local/grupomakro_core/ajax.php');
foreach (["'enableGroupGrading'", "'groupMode'", "'groupMaxmembers'"] as $key) {
    $caseStart = strpos($ajax, "case 'local_grupomakro_get_activity_details':");
    $nextCase = strpos($ajax, "\n        case ", $caseStart + 10);
    $caseBlock = substr($ajax, $caseStart, $nextCase - $caseStart);
    if (strpos($caseBlock, $key) === false) {
        mtrace("FAIL: get_activity_details does not return $key. The wizard cannot restore the flag.");
        exit(8);
    }
}
mtrace("7) get_activity_details returns enableGroupGrading + groupMode + groupMaxmembers ✔");

// 7. The v-if of the <activity-groups-panel> must consult
//    editData.enableGroupGrading, not only formData.enableGroupGrading,
//    because formData resets to false on each re-mount.
if (!preg_match(
    '/v-if="editMode\s*&&\s*editData\s*&&\s*editData\.id\s*&&\s*\(\s*editData\.enableGroupGrading\s*\|\|\s*formData\.enableGroupGrading\s*\)"/',
    $wizard
)) {
    mtrace("FAIL: the <activity-groups-panel> v-if does not consult "
        . "editData.enableGroupGrading. It would never render on the first "
        . "render after a re-mount because formData is reset to false.");
    exit(9);
}
mtrace("8) Panel v-if consults editData.enableGroupGrading AND formData.enableGroupGrading ✔");

// 8. The ajax.php dispatch for create_express_activity must extract the
//    group-grading parameters from $_POST and forward them to the WS
//    execute() call. Without this, the flag is silently dropped and the
//    activity is created without the group-grading flag, even though
//    the wizard sent enableGroupGrading=1. This is the regression that
//    the user reported in the browser (Martha's "T" activity, cmid=18427).
$ajaxContents = file_get_contents($CFG->dirroot . '/local/grupomakro_core/ajax.php');
$caseStart = strpos($ajaxContents, "case 'local_grupomakro_create_express_activity':");
$nextCase = strpos($ajaxContents, "\n        case ", $caseStart + 10);
$caseBlock = substr($ajaxContents, $caseStart, $nextCase - $caseStart);
foreach ([
    "optional_param\\('enableGroupGrading'",
    "optional_param\\('groupMode'",
    "optional_param\\('groupMaxmembers'",
    '\\$enableGroupGrading',
    '\\$groupMode',
    '\\$groupMaxmembers',
] as $needle) {
    if (!preg_match('/' . $needle . '/', $caseBlock)) {
        mtrace("FAIL: ajax.php case 'local_grupomakro_create_express_activity' "
            . "is missing the group-grading param: $needle. The wizard's "
            . "enableGroupGrading switch would not be persisted.");
        exit(10);
    }
}
mtrace("9) ajax.php create_express_activity extracts and forwards enableGroupGrading/groupMode/groupMaxmembers to the WS ✔");

mtrace("=== ALL CHECKS PASSED ===");
exit(0);
