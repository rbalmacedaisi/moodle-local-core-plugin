<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Cartas (20261001130): SIMULACION de lo que hara el paso de upgrade y la
 * primera pasada de la tarea letters_sla con las solicitudes que ya existen.
 * SOLO LECTURA: no escribe nada. Sirve antes y despues de desplegar.
 *
 *   php letters_backlog.php [--json]
 *
 * Informa: duplicadas que se anulan en silencio (y cual se conserva), abiertas
 * que quedan como "anteriores" (la bandeja pregunta si ya se gestionaron),
 * pagos pendientes que la tarea anulara por los 15 dias, y equipo de cartas
 * que recibira las alertas.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_grupomakro_core\local\letters\manager;
use local_grupomakro_core\local\letters\setup;

list($options, $unrecognized) = cli_get_params(['json' => false, 'help' => false], ['h' => 'help']);
if ($options['help']) {
    cli_writeln("Uso: php letters_backlog.php [--json]\nSimula (solo lectura) la limpieza del atraso de cartas.");
    exit(0);
}

$now = time();
$types = $DB->get_records('gmk_letter_type', null, '', 'id, name');
$tname = function(int $id) use ($types) {
    return isset($types[$id]) ? format_string($types[$id]->name) : ('#' . $id);
};

$dups = setup::duplicates();
$cancelids = [];
foreach ($dups as $g) {
    $cancelids = array_merge($cancelids, $g['cancel']);
}

[$in, $params] = $DB->get_in_or_equal(manager::OPEN_STATUSES, SQL_PARAMS_NAMED, 'st');
$open = $DB->get_records_select('gmk_letter_request', "status $in", $params, 'id', 'id, userid, lettertypeid, status, timecreated, timemodified');
$legacy = [];
$paycancel = [];
$payremind = [];
foreach ($open as $r) {
    if (in_array((int)$r->id, $cancelids, true)) {
        continue;
    }
    $since = (int)$DB->get_field_sql("SELECT MAX(timecreated) FROM {gmk_letter_request_event}
         WHERE requestid = :r AND eventtype = 'status_change' AND newstatus = :s",
        ['r' => (int)$r->id, 's' => (string)$r->status]) ?: (int)$r->timemodified;
    $days = round(($now - $since) / DAYSECS, 1);
    $row = ['id' => (int)$r->id, 'userid' => (int)$r->userid, 'type' => $tname((int)$r->lettertypeid),
        'status' => (string)$r->status, 'days_in_status' => $days];
    if ($r->status === 'pendiente_pago') {
        if ($days >= 15) {
            $paycancel[] = $row;
        } else if ($days >= 3) {
            $payremind[] = $row;
        }
    } else {
        $legacy[] = $row;
    }
}

$team = [];
$catid = (int)get_config('local_grupomakro_core', 'letters_tkt_categoryid');
if ($catid > 0) {
    $team = $DB->get_fieldset_select('gmk_tkt_agent', 'userid', 'categoryid = ?', [$catid]);
} else {
    [$rin, $rparams] = $DB->get_in_or_equal(setup::TEAM_ROLES, SQL_PARAMS_NAMED, 'r');
    $team = $DB->get_fieldset_sql("SELECT DISTINCT ra.userid FROM {role_assignments} ra
        JOIN {role} ro ON ro.id = ra.roleid JOIN {context} c ON c.id = ra.contextid AND c.contextlevel = " . CONTEXT_SYSTEM . "
        JOIN {user} u ON u.id = ra.userid AND u.deleted = 0 AND u.suspended = 0 WHERE ro.shortname $rin", $rparams);
}
$teamnames = manager::user_names(array_map('intval', $team));

$report = [
    'duplicadas_a_anular' => count($cancelids),
    'grupos_duplicados' => array_map(function($g) use ($tname) {
        return ['estudiante' => $g['userid'], 'tipo' => $tname($g['lettertypeid']), 'se_conserva' => $g['keep'],
            'se_anulan' => $g['cancel']];
    }, $dups),
    'anteriores_pregunta_ya_se_gestiono' => $legacy,
    'pagos_que_se_anulan_15_dias' => $paycancel,
    'pagos_con_recordatorio' => $payremind,
    'equipo_que_recibe_alertas' => array_values($teamnames),
];

if ($options['json']) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}
cli_writeln('Cartas: simulacion del atraso (solo lectura, ' . userdate($now) . ')');
cli_writeln('');
cli_writeln('Duplicadas que se anulan en silencio: ' . count($cancelids));
foreach ($report['grupos_duplicados'] as $g) {
    cli_writeln(sprintf('  estudiante %d · %s · se conserva #%d · se anulan #%s', $g['estudiante'], $g['tipo'],
        $g['se_conserva'], implode(', #', $g['se_anulan'])));
}
cli_writeln('');
cli_writeln('Anteriores a la bandeja (se pregunta "¿ya se gestionó?" y alertan desde la primera pasada): ' . count($legacy));
foreach ($legacy as $r) {
    cli_writeln(sprintf('  #%d · %s · %s · %s días en el estado', $r['id'], $r['type'], $r['status'], $r['days_in_status']));
}
cli_writeln('');
cli_writeln('Pagos pendientes que se anulan (15 días sin pago): ' . count($paycancel));
foreach ($paycancel as $r) {
    cli_writeln(sprintf('  #%d · %s · %s días', $r['id'], $r['type'], $r['days_in_status']));
}
cli_writeln('Pagos pendientes con recordatorio: ' . count($payremind));
cli_writeln('');
cli_writeln('Equipo de cartas (responsables y alertas): ' . (implode(', ', $teamnames) ?: 'NADIE: revisar'));
