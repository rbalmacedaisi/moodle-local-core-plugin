<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * One-off repair of the 2026-IV module classes deleted by the period publish.
 *
 * Publishing 2026-IV deleted the module classes created by enroll_module
 * (fixed in f99c968). Three were recreated under new ids, Historia de Panama
 * was lost, and on 2026-10-02 the orphaned enrollments were re-pointed to the
 * 2026-III module of each subject instead of the 2026-IV one. Approved plan:
 *
 *   a. Recreate "HISTORIA DE PANAMA (MODULO) 2026-IV" on its surviving group
 *      (926) and section (1198), where the students' grades are.
 *   b. Re-point the misassigned enrollments to the module they took:
 *      Matematica II 2026-IV (9798), Informatica Aplicada 2026-IV (9801),
 *      Historia 2026-IV (new class); Yineth Fernandez's Matematica II goes to
 *      the 2026-V module (9889), where her 81 was graded, and joins its group.
 *   c. Invoice request #16 (Yineth) -> 9889.
 *   d. Delete the empty "FORMULACION Y EVALUACION DE PROYECTO (MODULO) 2026-IV"
 *      group (931) and its empty grade category (1192).
 *
 * Not touched by decision: Johanna Gil (#199), Kale Francis (group 930),
 * Jorge Argueta (teacher), Joseline Hernandez (#28), Kale's #157.
 *
 * Usage: php local/grupomakro_core/cli/repair_modules_2026iv.php [--apply]
 *
 * @package    local_grupomakro_core
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/group/lib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

list($options) = cli_get_params(['apply' => false]);
$apply = !empty($options['apply']);

const HISTORIA_GROUP = 926;
const HISTORIA_SECTION = 1198;
const HISTORIA_TEMPLATE = 9652;
const PERIOD_2026_IV = 5;

// enrollment id => target classid ('HISTORIA' = the class created in step a).
$repoint = [
    201 => 'HISTORIA', 203 => 'HISTORIA', 205 => 'HISTORIA', 208 => 'HISTORIA',
    202 => 9798, 206 => 9798, 207 => 9798,
    204 => 9889,
    198 => 9801, 200 => 9801, 209 => 9801,
];
$addtogroup = [[2315, 9889]]; // [userid, classid]: Yineth -> Matematica II 2026-V group.
$invoicerepoint = [16 => 9889];
$emptygroup = 931;
$emptycategory = 1192;

// --- Pre-checks: abort if the data is not what the plan was built on --------
$errors = [];
if ($DB->record_exists('gmk_class', ['groupid' => HISTORIA_GROUP])) {
    $errors[] = 'El grupo ' . HISTORIA_GROUP . ' ya tiene una clase: el paso a ya se aplico.';
}
if (!$DB->record_exists('course_sections', ['id' => HISTORIA_SECTION, 'course' => 68])) {
    $errors[] = 'La seccion ' . HISTORIA_SECTION . ' no existe en el curso 68.';
}
foreach ($repoint as $eid => $target) {
    if (!$DB->record_exists('gmk_module_enrollment', ['id' => $eid])) {
        $errors[] = "La inscripcion #$eid no existe.";
    }
    if (is_int($target) && !$DB->record_exists('gmk_class', ['id' => $target, 'is_module' => 1])) {
        $errors[] = "La clase destino $target no existe o no es modulo.";
    }
}
if ($DB->count_records('groups_members', ['groupid' => $emptygroup]) > 0) {
    $errors[] = "El grupo $emptygroup ya no esta vacio.";
}
if ($DB->count_records('grade_items', ['categoryid' => $emptycategory]) > 0) {
    $errors[] = "La categoria $emptycategory ya tiene items.";
}
if ($errors) {
    cli_error("No se aplica nada:\n - " . implode("\n - ", $errors));
}

// --- Backup of every row the script changes ---------------------------------
$backup = [
    'enrollments' => array_values($DB->get_records_list('gmk_module_enrollment', 'id', array_keys($repoint))),
    'invoice_requests' => array_values($DB->get_records_list('gmk_module_invoice_requests', 'id', array_keys($invoicerepoint))),
    'group' => $DB->get_record('groups', ['id' => $emptygroup]),
    'category' => $DB->get_record('grade_categories', ['id' => $emptycategory]),
];
$backupfile = '/tmp/repair_modules_2026iv_backup_' . date('Ymd_His') . '.json';
file_put_contents($backupfile, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

cli_writeln(($apply ? 'APLICANDO' : 'SIMULACION') . " - respaldo en $backupfile");

$transaction = $DB->start_delegated_transaction();

// a. Recreate Historia de Panama (MODULO) 2026-IV.
$template = $DB->get_record('gmk_class', ['id' => HISTORIA_TEMPLATE], '*', MUST_EXIST);
$group = $DB->get_record('groups', ['id' => HISTORIA_GROUP], '*', MUST_EXIST);
$new = clone $template;
unset($new->id);
$new->name = 'HISTORIA DE PANAMÁ (MÓDULO) 2026-IV';
$new->periodid = PERIOD_2026_IV;
$new->groupid = HISTORIA_GROUP;
$new->coursesectionid = HISTORIA_SECTION;
$new->gradecategoryid = 0;
$new->attendancemoduleid = 0;
$new->bbbmoduleids = '';
$new->closed = 1;
$new->initdate = (int)$group->timecreated;
$new->enddate = 0;
$new->timecreated = time();
$new->timemodified = time();
$historiaid = $DB->insert_record('gmk_class', $new);
cli_writeln("a. clase recreada: id $historiaid \"{$new->name}\" grupo " . HISTORIA_GROUP . ' seccion ' . HISTORIA_SECTION);

// b. Re-point enrollments.
foreach ($repoint as $eid => $target) {
    $target = $target === 'HISTORIA' ? (int)$historiaid : (int)$target;
    $e = $DB->get_record('gmk_module_enrollment', ['id' => $eid]);
    $u = $DB->get_field('user', 'username', ['id' => $e->userid]);
    $DB->update_record('gmk_module_enrollment', (object)[
        'id' => $eid, 'classid' => $target, 'timemodified' => time(), 'usermodified' => get_admin()->id,
    ]);
    cli_writeln("b. inscripcion #$eid ($u): clase {$e->classid} -> $target");
}
foreach ($addtogroup as [$uid, $classid]) {
    $gid = (int)$DB->get_field('gmk_class', 'groupid', ['id' => $classid]);
    if ($gid > 0 && !groups_is_member($gid, $uid)) {
        groups_add_member($gid, $uid);
        cli_writeln("b. usuario $uid agregado al grupo $gid (clase $classid)");
    }
}

// c. Invoice requests.
foreach ($invoicerepoint as $rid => $classid) {
    $old = $DB->get_field('gmk_module_invoice_requests', 'enrolled_classid', ['id' => $rid]);
    $DB->set_field('gmk_module_invoice_requests', 'enrolled_classid', $classid, ['id' => $rid]);
    $DB->set_field('gmk_module_invoice_requests', 'timemodified', time(), ['id' => $rid]);
    cli_writeln("c. solicitud #$rid: enrolled_classid $old -> $classid");
}

// d. Empty group and grade category.
groups_delete_group($emptygroup);
cli_writeln("d. grupo $emptygroup eliminado");
$cat = grade_category::fetch(['id' => $emptycategory]);
if ($cat) {
    $cat->delete('local_grupomakro_core');
    cli_writeln("d. categoria $emptycategory eliminada");
}

if ($apply) {
    $transaction->allow_commit();
    rebuild_course_cache(68, true);
    cli_writeln('Aplicado.');
} else {
    try {
        $transaction->rollback(new \moodle_exception('dryrun'));
    } catch (\moodle_exception $e) {
        cli_writeln('Simulacion: todo revertido.');
    }
}
