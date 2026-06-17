<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * This page is responsible of managing everything related to the orders.
 *
 * @package    local_grupomakro_core
 * @copyright  2022 Solutto Consulting <dev@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once $CFG->dirroot. '/local/grupomakro_core/locallib.php';
$plugin_name = 'local_grupomakro_core';

require_login();

$PAGE->set_url($CFG->wwwroot . '/local/grupomakro_core/pages/classmanagement.php');

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_title(get_string('class_management', $plugin_name));
$PAGE->set_heading(get_string('class_management', $plugin_name));
$PAGE->set_pagelayout('base');

$shiftfeedback = '';
$shifterror = '';

$action = optional_param('action', '', PARAM_ALPHANUMEXT);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update_shift') {
    require_sesskey();

    $classid = required_param('classid', PARAM_INT);
    $rawshift = optional_param('shift', '', PARAM_RAW_TRIMMED);
    $newshift = trim((string)$rawshift);
    if ($newshift === '' || core_text::strtolower($newshift) === 'null') {
        $newshift = null;
    } else {
        $newshift = core_text::substr($newshift, 0, 50);
    }

    $class = $DB->get_record('gmk_class', ['id' => $classid], 'id, name', IGNORE_MISSING);
    if (!$class) {
        $shifterror = 'Clase no encontrada.';
    } else {
        $record = new stdClass();
        $record->id = (int)$classid;
        $record->shift = $newshift;
        $record->timemodified = time();
        $record->usermodified = (int)$USER->id;
        $DB->update_record('gmk_class', $record);
        $shiftfeedback = 'Jornada actualizada.';
    }
}

$classes = array_values(list_classes([]));
$sectionNumberById = [];
$sectionIds = array_values(array_unique(array_filter(array_map(
    fn($c) => (int)($c->coursesectionid ?? 0),
    $classes
))));
if (!empty($sectionIds)) {
    list($insql, $inparams) = $DB->get_in_or_equal($sectionIds, SQL_PARAMS_NAMED, 'sid');
    $sectionNumberById = $DB->get_records_sql_menu(
        "SELECT id, section FROM {course_sections} WHERE id $insql",
        $inparams
    );
}
$courseCache = [];
foreach ($classes as &$class) {
    $shiftvalue = isset($class->shift) ? trim((string)$class->shift) : '';
    $class->shiftvalue = $shiftvalue;
    $class->shiftdisplay = ($shiftvalue !== '') ? $shiftvalue : 'Sin jornada';
    // Direct link to the course section that hosts the class's group activities
    // (attendance + BBB). Use Moodle's course_get_url() so the URL matches
    // whatever the course format / user display preference expects
    // (anchor #section-N for single-page, ?section=N for multipage).
    $cid = isset($class->corecourseid) ? (int)$class->corecourseid : 0;
    $sid = isset($class->coursesectionid) ? (int)$class->coursesectionid : 0;
    if ($cid > 0 && $sid > 0 && isset($sectionNumberById[$sid])) {
        if (!isset($courseCache[$cid])) {
            $courseCache[$cid] = $DB->get_record('course', ['id' => $cid], '*', IGNORE_MISSING);
        }
        $course = $courseCache[$cid];
        $class->groupurl = $course
            ? course_get_url($course, $sectionNumberById[$sid])->out()
            : '';
    } else {
        $class->groupurl = '';
    }
}
unset($class);

echo $OUTPUT->header();

$templatedata = [
    'createurl' => $CFG->wwwroot.'/local/grupomakro_core/pages/createcontract.php',
    'url' => $CFG->wwwroot.'/local/grupomakro_core/pages/contractmanagement.php',
    'allClasses' => $classes,
    'createclass_url' => $CFG->wwwroot . '/local/grupomakro_core/pages/createclass.php',
    'sesskey' => sesskey(),
    'shiftfeedback' => $shiftfeedback,
    'shifterror' => $shifterror
]; 

echo $OUTPUT->render_from_template('local_grupomakro_core/class_management', $templatedata);
$PAGE->requires->js_call_amd('local_grupomakro_core/delete_class', 'init', []);
echo $OUTPUT->footer();
