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
 * flow (20261001080, round 2).
 *
 * The user said "necesito que me permita crear los grupos desde la
 * creacion de la actividad no despues". The previous fix had mounted
 * the ActivityGroupsPanel INSIDE the edit dialog, but the panel
 * only renders when editMode is true, so it was not available at
 * creation time. The user had to: create, close wizard, reopen as
 * edit, scroll down to the panel. That is the opposite of "during
 * creation".
 *
 * This fix adds a new emitted event 'created-with-groups' that the
 * wizard fires when a new activity was created with
 * enableGroupGrading=1, and the parent handles it by re-mounting
 * the same wizard as an edit dialog (without closing it). The
 * activity-groups-panel then renders automatically because the
 * mounted() hook reloads the activity details with the new cmid.
 *
 * This test pins:
 *  1) The submit() handler detects the created-with-groups case
 *     and emits the new event with cmid/modname/name.
 *  2) The submit() button text becomes "Crear y gestionar grupos"
 *     when the activity supports grading and the switch is on.
 *  3) The ManageClass parent listens for the new event.
 *  4) The parent handler flips isEditing and editData without
 *     closing the dialog.
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

// 1. The wizard submit() path must check enableGroupGrading and
//    emit 'created-with-groups' instead of 'success' + close()
//    when the new activity is assign/quiz with the switch on.
if (!preg_match(
    '/createdWithGroups\s*=\s*\(\s*this\.isAssignment\s*\|\|\s*this\.isQuiz\s*\)\s*&&\s*this\.formData\.enableGroupGrading\s*&&\s*!this\.editMode/',
    $wizard
)) {
    mtrace("FAIL: the submit() path does not compute the createdWithGroups flag "
        . "from (isAssignment||isQuiz) && enableGroupGrading && !editMode.");
    exit(2);
}
mtrace("1) submit() computes createdWithGroups from isAssignment/isQuiz + enableGroupGrading + !editMode ✔");

if (!preg_match("/\\\$emit\\(['\"]created-with-groups['\"]/", $wizard)) {
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

// 4. The parent handler flips isEditing + editData without closing
//    the dialog.
if (!preg_match(
    '/onActivityCreatedWithGroups\s*\(\s*payload\s*\)\s*\{[\s\S]*?this\.isEditing\s*=\s*true[\s\S]*?this\.editActivityData\s*=\s*\{[\s\S]*?cmid\s*:\s*payload\.cmid/s',
    $parent
)) {
    mtrace("FAIL: onActivityCreatedWithGroups does not flip isEditing + editData "
        . "with the new cmid.");
    exit(6);
}
mtrace("5) onActivityCreatedWithGroups flips isEditing + editData with the new cmid ✔");

mtrace("=== ALL CHECKS PASSED ===");
exit(0);
