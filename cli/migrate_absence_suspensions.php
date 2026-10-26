<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Turn the absence suspensions into the absence restriction.
 *
 * Until 2026-10 the staged absence check suspended the Moodle account of a
 * student whose classes were all blocked; the student only saw "invalid login".
 * It now keeps the account active and restricts it instead. This script lifts
 * the suspension of the students the process suspended (last global action
 * 'global_inactive' and still suspended). studentstatus = Inactivo and the
 * plan status stay as they are, and so does the restriction itself.
 *
 * Dry run by default.
 *
 * Usage:
 *   php local/grupomakro_core/cli/migrate_absence_suspensions.php [--apply]
 *
 * @package    local_grupomakro_core
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognized) = cli_get_params(['apply' => false, 'help' => false], ['h' => 'help']);
if ($options['help']) {
    echo "php migrate_absence_suspensions.php [--apply]\n";
    exit(0);
}
$apply = !empty($options['apply']);

$rows = $DB->get_records_sql(
    "SELECT u.id, u.username, u.firstname, u.lastname, h.timecreated
       FROM {gmk_class_absence_history} h
       JOIN {user} u ON u.id = h.userid AND u.deleted = 0 AND u.suspended = 1
      WHERE h.classid = 0
        AND h.action = 'global_inactive'
        AND h.id = (SELECT MAX(h2.id) FROM {gmk_class_absence_history} h2
                     WHERE h2.userid = h.userid AND h2.classid = 0
                       AND h2.action IN ('global_inactive', 'global_reactivate'))
   ORDER BY u.lastname, u.firstname");

echo ($apply ? 'APLICANDO' : 'SIMULACION') . ': ' . count($rows) . " cuentas suspendidas por inasistencias\n";
foreach ($rows as $r) {
    echo sprintf("  %-14s %-45s suspendida desde %s\n", $r->username, $r->firstname . ' ' . $r->lastname,
        userdate($r->timecreated, '%d/%m/%Y %H:%M'));
    if ($apply) {
        $DB->set_field('user', 'suspended', 0, ['id' => $r->id]);
        \core\event\user_updated::create_from_userid($r->id)->trigger();
    }
}
if (!$apply) {
    echo "Nada se escribio. Ejecuta con --apply para reactivar las cuentas (la restriccion se mantiene).\n";
}
