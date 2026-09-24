<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Repara la numeracion de secciones de curso que quedo fuera del limite
 * moodlecourse|maxsections.
 *
 * Los scripts cli/repair_orphan_bbb_*.php pasaban $class->coursesectionid (el
 * ID de la seccion) a create_big_blue_button_activity(), que espera el NUMERO.
 * add_moduleinfo() -> course_create_sections_if_missing() creaba entonces una
 * seccion vacia con ese numero (p.ej. 1299) y desde ahi cada clase nueva caia
 * en max+1 (1300, 1301...). course/modedit.php rechaza cualquier seccion por
 * encima de maxsections con "maxsectionslimit", asi que en esas clases no se
 * podia crear ninguna actividad.
 *
 * Por cada curso con secciones por encima del limite:
 *   1. Borra las secciones fuera de rango vacias (sin nombre, sin resumen,
 *      sin actividades y sin clase gmk que las use).
 *   2. Renumera todas las secciones 0..n conservando el orden.
 * Las actividades (course_modules.section) y las clases (gmk_class.coursesectionid)
 * apuntan al ID de la seccion, no al numero, asi que no cambian.
 *
 * Uso:
 *   php local/grupomakro_core/cli/fix_section_numbering.php            (simulacion)
 *   php local/grupomakro_core/cli/fix_section_numbering.php --apply
 *
 * @package    local_grupomakro_core
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');

list($options, $unrecognized) = cli_get_params(['apply' => false, 'help' => false], ['h' => 'help']);
if ($options['help']) {
    echo "Uso: php fix_section_numbering.php [--apply]\n";
    exit(0);
}
$apply = !empty($options['apply']);

$max = (int)get_config('moodlecourse', 'maxsections');
if ($max <= 0) {
    $max = 52;
}

$courseids = $DB->get_fieldset_sql(
    "SELECT DISTINCT course FROM {course_sections} WHERE section > :max ORDER BY course",
    ['max' => $max]
);
if (!$courseids) {
    cli_writeln("Sin secciones por encima de {$max}. Nada que hacer.");
    exit(0);
}

cli_writeln(($apply ? 'APLICANDO' : 'SIMULACION') . " - limite maxsections={$max}, cursos afectados: " . count($courseids));

foreach ($courseids as $courseid) {
    $sections = $DB->get_records('course_sections', ['course' => $courseid], 'section ASC');

    $todelete = [];
    foreach ($sections as $sid => $s) {
        if ($s->section <= $max) {
            continue;
        }
        $empty = ($s->name === null || $s->name === '')
            && trim(strip_tags((string)$s->summary)) === ''
            && trim((string)$s->sequence) === ''
            && !$DB->record_exists('course_modules', ['section' => $sid])
            && !$DB->record_exists('gmk_class', ['coursesectionid' => $sid]);
        if ($empty) {
            $todelete[$sid] = "{$sid}(#{$s->section})";
            unset($sections[$sid]);
        }
    }

    // Ascending order: each target number is <= the current one and is free,
    // so the (course, section) unique index never collides.
    $torenumber = [];
    $next = 0;
    foreach ($sections as $sid => $s) {
        if ((int)$s->section !== $next) {
            $torenumber[$sid] = [(int)$s->section, $next];
        }
        $next++;
    }

    $desc = [];
    foreach ($torenumber as $sid => [$from, $to]) {
        $desc[] = "{$sid}: #{$from} -> #{$to}";
    }
    cli_writeln("curso {$courseid}: borrar " . count($todelete) . ' [' . implode(', ', $todelete) . '] | renumerar '
        . count($torenumber) . ($desc ? ' [' . implode('; ', $desc) . ']' : '') . " | total secciones {$next}");

    if (!$apply) {
        continue;
    }
    $transaction = $DB->start_delegated_transaction();
    foreach (array_keys($todelete) as $sid) {
        $DB->delete_records('course_format_options', ['sectionid' => $sid]);
        $DB->delete_records('course_sections', ['id' => $sid]);
    }
    foreach ($torenumber as $sid => [$from, $to]) {
        $DB->set_field('course_sections', 'section', $to, ['id' => $sid]);
    }
    $transaction->allow_commit();
    rebuild_course_cache($courseid, true);
}
