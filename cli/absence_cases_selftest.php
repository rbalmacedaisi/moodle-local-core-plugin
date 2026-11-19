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
 * Seguimiento de inasistencias: prueba de punta a punta SIN dejar rastro.
 *
 *   php absence_cases_selftest.php --staff=<userid de Bienestar>
 *
 * Todo corre dentro de UNA transaccion que siempre se revierte (casos,
 * gestiones y eventos). Usa los bloqueos reales de clases en curso.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_grupomakro_core\local\absence\cases;
use local_grupomakro_core\local\dashboard\dashboard;
use local_grupomakro_core\local\dashboard\registry;

list($options, $unrecognized) = cli_get_params(['staff' => 0, 'help' => false], ['h' => 'help']);
if ($options['help'] || (int)$options['staff'] <= 0) {
    cli_writeln("Uso: php absence_cases_selftest.php --staff=<userid de Bienestar>");
    exit(0);
}
$staffid = (int)$options['staff'];
if (!$DB->get_manager()->table_exists('gmk_abs_case')) {
    cli_error('Falta la tabla gmk_abs_case: corre primero admin/cli/upgrade.php.');
}
$results = [];
$check = function(string $label, bool $ok, string $detail = '') use (&$results) {
    $results[] = $ok;
    cli_writeln(($ok ? '  PASS ' : '  FAIL ') . $label . ($detail !== '' ? '  [' . $detail . ']' : ''));
};
$throws = function(callable $fn) {
    try {
        $fn();
        return false;
    } catch (\moodle_exception $e) {
        return true;
    }
};
$indash = function(int $caseid, int $now) {
    $src = registry::sources(true)['absence_blocks'];
    foreach (dashboard::source_items($src, 0, 'all', $now) as $it) {
        if ($it->ref === 'ABS-' . $caseid) {
            return $it;
        }
    }
    return null;
};

cli_writeln('Seguimiento de inasistencias - prueba con rollback. Personal: u' . $staffid
    . ' (gestiona: ' . (int)cases::can_manage($staffid) . ', supervisa: ' . (int)cases::can_supervise($staffid) . ')');
$transaction = $DB->start_delegated_transaction();
try {
    $now = time();
    $r = cases::sync($now);
    $open = $DB->count_records_select('gmk_abs_case', 'status <> ?', [cases::S_CLOSED]);
    $check('sync abre un caso por bloqueo activo', $open > 0, json_encode($r) . ", abiertos $open");
    $r2 = cases::sync($now);
    $check('sync es idempotente', $r2['opened'] === 0 && $r2['closed'] === 0, json_encode($r2));

    $case = $DB->get_record_select('gmk_abs_case', 'status = ? AND actions = 0', [cases::S_NEW], '*', IGNORE_MULTIPLE);
    $list = cases::list(['status' => 'all', 'ids' => [(int)$case->id]], $now)[0];
    $check('el caso trae estudiante, clase y contacto', $list['student'] !== '' && $list['classname'] !== '',
        $list['student'] . ' · ' . count($list['contact']) . ' contacto(s)');
    $it = $indash((int)$case->id, $now);
    $check('sin gestión: aparece en el dashboard (absence.block)', $it && $it->type === 'absence.block');

    $check('sin canal válido: rechazado', $throws(function() use ($case, $staffid) {
        cases::add_log((int)$case->id, $staffid, ['channel' => 'fax', 'result' => 'contactado', 'next_followup' => time() + DAYSECS]);
    }));
    $check('sin fecha de seguimiento ni cierre: rechazado', $throws(function() use ($case, $staffid) {
        cases::add_log((int)$case->id, $staffid, ['channel' => 'llamada', 'result' => 'no_contesta']);
    }));

    $next = $now + 3 * DAYSECS;
    $c = cases::add_log((int)$case->id, $staffid, ['channel' => 'llamada', 'result' => 'no_contesta',
        'note' => 'Prueba automática', 'next_followup' => $next, 'status' => cases::S_FOLLOWUP], $now);
    $check('gestión registrada: estado, contador y próximo seguimiento', $c['status'] === cases::S_FOLLOWUP
        && $c['actions'] === 1 && $c['next_followup'] === $next && count($c['log']) === 1);
    $check('primer contacto registrado', $c['first_contact_at'] === $now);
    $check('con seguimiento a futuro: desaparece del dashboard', $indash((int)$case->id, $now) === null);
    $it = $indash((int)$case->id, $next + HOURSECS);
    $check('al llegar la fecha: vuelve como seguimiento vencido', $it && $it->type === 'absence.followup'
        && (int)$it->since === $next);

    $logid = $c['log'][0]['id'];
    $check('corrección sin nota: rechazada', $throws(function() use ($case, $staffid, $logid) {
        cases::add_log((int)$case->id, $staffid, ['channel' => 'otro', 'result' => 'correccion', 'correctsid' => $logid]);
    }));
    $c = cases::add_log((int)$case->id, $staffid, ['channel' => 'otro', 'result' => 'correccion',
        'note' => 'Era WhatsApp, no llamada', 'correctsid' => $logid], $now + 60);
    $check('corrección agregada sin tocar la gestión original', count($c['log']) === 2
        && $DB->get_field('gmk_abs_case_log', 'channel', ['id' => $logid]) === 'llamada' && $c['next_followup'] === $next);

    $check('cierre sin motivo: rechazado', $throws(function() use ($case, $staffid) {
        cases::add_log((int)$case->id, $staffid, ['channel' => 'llamada', 'result' => 'se_reincorpora', 'status' => cases::S_CLOSED]);
    }));
    $c = cases::add_log((int)$case->id, $staffid, ['channel' => 'llamada', 'result' => 'se_reincorpora',
        'status' => cases::S_CLOSED, 'closed_reason' => 'reincorporado'], $now + 120);
    $check('caso cerrado con motivo', $c['status'] === cases::S_CLOSED && $c['closed_reason'] === 'reincorporado');
    $check('cerrado: no aparece en el dashboard aunque pase la fecha', $indash((int)$case->id, $next + DAYSECS) === null);
    $r3 = cases::sync($now + 180);
    $check('sync no reabre un caso cerrado a mano del mismo bloqueo', $DB->get_field('gmk_abs_case', 'status',
        ['id' => $case->id]) === cases::S_CLOSED, json_encode($r3));

    // Block gone -> auto-closed.
    $other = $DB->get_record_select('gmk_abs_case', 'status = ? AND id <> ?', [cases::S_NEW, $case->id], '*', IGNORE_MULTIPLE);
    if ($other) {
        $DB->set_field('gmk_class_absence_state', 'unblocked_at', $now + 200,
            ['userid' => $other->userid, 'classid' => $other->classid]);
        cases::sync($now + 300);
        $o = $DB->get_record('gmk_abs_case', ['id' => $other->id]);
        $check('desbloqueado: el caso se cierra solo', $o->status === cases::S_CLOSED && $o->closed_reason === 'auto_desbloqueado');
    }

    $s = cases::stats($now + 400);
    $check('indicadores: el caso cerrado cuenta como cerrado por gestión', in_array((int)$case->id, $s['indicators']['closed']['ids']));
    $check('indicadores: primer contacto a tiempo', in_array((int)$case->id, $s['indicators']['ontime']['ids']));
    $check('indicadores: resultados y serie semanal', count($s['series']) === 8 && count($s['results']) >= 2,
        'a tiempo ' . var_export($s['ontime_pct'], true) . '%');
    $check('el historial no tiene API de edición ni borrado', !method_exists(cases::class, 'update_log')
        && !method_exists(cases::class, 'delete_log'));
    // The event is triggered by add_log() above. It is NOT looked up in
    // logstore_standard_log: that table has no index on eventname and the scan
    // (millions of rows, inside this open transaction) hung in production. The
    // event is validated in memory instead: same data add_log() uses.
    $event = \local_grupomakro_core\event\absence_case_logged::create([
        'objectid' => (int)$logid, 'context' => context_system::instance(), 'relateduserid' => (int)$case->userid,
        'other' => ['caseid' => (int)$case->id, 'result' => 'no_contesta', 'status' => cases::S_FOLLOWUP],
    ]);
    $check('el evento de auditoría se arma bien (sin consultar el log)', $event->get_description() !== ''
        && strpos($event->get_url()->out(false), 'case=' . (int)$case->id) !== false);
} catch (\Throwable $e) {
    $check('excepción inesperada', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}
try {
    $transaction->rollback(new \moodle_exception('absence_cases_selftest_rollback'));
} catch (\moodle_exception $e) {
    // Expected.
}
dashboard::invalidate_all();
$failed = count(array_filter($results, function($r) {
    return !$r;
}));
cli_writeln(sprintf("\n%d pruebas, %d fallas. Todo revertido (casos: %d, gestiones: %d).", count($results), $failed,
    $DB->count_records('gmk_abs_case'), $DB->count_records('gmk_abs_case_log')));
exit($failed ? 1 : 0);
