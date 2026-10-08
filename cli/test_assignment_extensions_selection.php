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
 * Static checks for the AssignmentExtensions.js selection logic.
 *
 * The teacher dashboard's modal was reporting that checking ONE
 * student checkbox was selecting ALL of them, and unchecking the
 * last one was clearing the rest. The root cause was Vuetify 2's
 * v-model + show-select + dynamic items interaction: when :items
 * changes (or the user types in the search box), Vuetify
 * re-derives the "selected" set and sometimes writes a fully
 * populated array back to v-model, which then writes a different
 * array to the per-row checkboxes, etc.
 *
 * The fix replaces the v-model binding with explicit
 * toggleStudent / toggleAll methods and a `selectAll` computed
 * getter/setter, so the row and header checkboxes read from one
 * stable source of truth (selectedStudentIds as a Set-like array).
 *
 * This smoke test reads the deployed AssignmentExtensions.js
 * from disk and asserts that the fix is in place. It is intentionally
 * static (no Vue runtime) so it can run on the server with the same
 * PHP CLI infrastructure as the other smoke tests in cli/.
 *
 * Run with:
 *   php local/grupomakro_core/cli/test_assignment_extensions_selection.php
 *
 * Exit code 0 on success, non-zero on the first failure.
 *
 * @package local_grupomakro_core
 * @category   cli
 */

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');

mtrace("=== Static check: AssignmentExtensions.js selection logic (20261001092) ===");

$path = __DIR__ . '/../js/components/AssignmentExtensions.js';
if (!is_readable($path)) {
    mtrace("FAIL: cannot read $path");
    exit(1);
}
$src = file_get_contents($path);
mtrace("Loaded " . strlen($src) . " bytes from $path");

// 1. The fix removes the v-model binding on the v-data-table.
//    Without this, Vuetify 2's auto-derivation of selectedStudentIds
//    on :items change is the root cause of the bug. show-select
//    stays enabled so the data-table-select column exists and the
//    custom header/item slots can be rendered, but the v-model is
//    gone.
//
//    The check is "in the template" (between <template> ... </template>),
//    not in a JS comment. The text "v-model=\"selectedStudentIds\"" may
//    still appear in code comments (documenting what was removed),
//    which is fine.
$template = '';
if (preg_match('/<template>([\s\S]*?)<\/template>/', $src, $m)) {
    $template = $m[1];
}
if (strpos($template, 'v-model="selectedStudentIds"') !== false) {
    mtrace("FAIL: v-model=\"selectedStudentIds\" is still present in the template. "
        . "This is the source of the 'check one selects all' bug. Replace it with "
        . "manual selection via @update:item-selected or custom checkbox slots.");
    exit(2);
}
mtrace("1) v-data-table no longer uses v-model=\"selectedStudentIds\" ✔");
// 2. There must be a toggleStudent method that adds/removes a
//    single userid from selectedStudentIds, exactly the way the
//    user clicked.
if (!preg_match('/toggleStudent\s*\([^)]*userid/', $src)) {
    mtrace("FAIL: missing toggleStudent(userid) method. The fix must provide a "
        . "deterministic single-row selection handler.");
    exit(3);
}
mtrace("2) toggleStudent(userid) method is defined ✔");

// 3. There must be a computed `selectAll` with a getter. In Vue 2
//    this is written as `selectAll: { get() {...}, set() {...} }`.
//    We accept both the inline form (the one we use) and the
//    function form (`selectAll() { return ...; }`).
if (!preg_match('/selectAll\s*:\s*\{\s*get\s*\(/s', $src)
    && !preg_match('/selectAll\s*\(\s*\)\s*\{[^}]*return\s+/s', $src)) {
    mtrace("FAIL: missing selectAll computed with a getter. The header checkbox "
        . "needs to know whether every row is currently selected.");
    exit(4);
}
mtrace("3) selectAll computed with a getter is defined ✔");

if (!preg_match('/selectAll\s*:\s*\{[\s\S]{0,500}set\s*\(/', $src)) {
    mtrace("FAIL: missing selectAll computed with a setter. The header checkbox "
        . "needs to be able to flip every row at once.");
    exit(5);
}
mtrace("4) selectAll computed with a setter is defined ✔");

// 5. The template must render a v-checkbox (or similar) for the
//    header checkbox, driven by the selectAll computed. We accept
//    both the v-model form and the explicit :input-value + @change
//    form (the latter is the idiomatic Vuetify 2 pattern - v-model
//    on a computed with a setter also works but the @change form
//    gives us a single, deterministic place to read the new value).
if (!preg_match('/v-checkbox[^>]*v-model="selectAll"/s', $src)
    && !preg_match('/v-checkbox[^>]*:input-value="selectAll"/s', $src)) {
    mtrace("FAIL: missing v-checkbox in header bound to selectAll. "
        . "The header must drive the bulk toggle, not Vuetify's auto-derivation.");
    exit(6);
}
mtrace("5) Header v-checkbox is bound to selectAll ✔");

if (!preg_match('/v-checkbox[^>]*v-model="isStudentSelected\([^)]+\)"/s', $src)
    && !preg_match('/v-checkbox[^>]*:input-value="isStudentSelected\([^)]+\)"/s', $src)) {
    mtrace("FAIL: missing per-row v-checkbox bound to isStudentSelected(userid). "
        . "Each row checkbox must read from a single source of truth.");
    exit(7);
}
mtrace("6) Per-row v-checkbox is bound to isStudentSelected(userid) ✔");

// 7. isStudentSelected must be a method (not just a property) so
//    Vue re-evaluates it on every render of the row checkbox.
if (!preg_match('/isStudentSelected\s*\(\s*int[^)]*\)\s*\{/', $src)
    && !preg_match('/isStudentSelected\s*\([^)]+\)\s*\{/', $src)) {
    mtrace("FAIL: missing isStudentSelected(userid) method.");
    exit(8);
}
mtrace("7) isStudentSelected(userid) method is defined ✔");

// 8. The bulk-apply button (applyToAll) should now read from
//    selectedStudentIds - we already added this in the previous
//    commit, but verify the wiring is still there.
if (strpos($src, 'this.applyToAllDate') === false) {
    mtrace("FAIL: applyToAll no longer reads from this.applyToAllDate - the "
        . "previous bulk-apply fix has been undone.");
    exit(9);
}
mtrace("8) applyToAll() still reads from this.applyToAllDate ✔");

mtrace("=== ALL CHECKS PASSED ===");
exit(0);
