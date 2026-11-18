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
 * Mesa de ayuda: prueba de punta a punta SIN dejar rastro.
 *
 *   php support_selftest.php --student=<id de un estudiante de prueba>
 *
 * Todo corre dentro de UNA transaccion que al final se revierte siempre
 * (tickets, mensajes, eventos, numeracion, disponibilidad). Las notificaciones
 * que Moodle envia dentro de una transaccion quedan en cola hasta el commit, asi
 * que al revertir no sale ningun correo ni campanita. Lo unico que avanza son los
 * contadores AUTO_INCREMENT de las tablas gmk_tkt_*.
 *
 * Recorre: creacion y numeracion, reparto equilibrado, notas internas ocultas,
 * respuesta, pedir informacion, resolver, "No" (reabre), "Si" (cierra),
 * cancelar, reglas de la maquina de estados, cola sin asignar por ausencia,
 * confidencialidad de Psicologia, acceso cruzado y limite de abiertos.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_grupomakro_core\local\support\manager;

list($options, $unrecognized) = cli_get_params(['student' => 0, 'help' => false], ['h' => 'help']);
if ($options['help'] || (int)$options['student'] <= 0) {
    cli_writeln("Uso: php support_selftest.php --student=<userid de un estudiante de prueba>\n"
        . "Corre el flujo completo de la mesa de ayuda dentro de una transaccion y la revierte.");
    exit(0);
}
$studentid = (int)$options['student'];
if (!$DB->record_exists('user', ['id' => $studentid, 'deleted' => 0])) {
    cli_error('El estudiante no existe.');
}
$dbman = $DB->get_manager();
if (!$dbman->table_exists('gmk_tkt_ticket')) {
    cli_error('Faltan las tablas gmk_tkt_*: corre primero admin/cli/upgrade.php.');
}

$results = [];
$check = function(string $label, bool $ok, string $detail = '') use (&$results) {
    $results[] = [$ok, $label, $detail];
    cli_writeln(($ok ? '  PASS ' : '  FAIL ') . $label . ($detail !== '' ? '  [' . $detail . ']' : ''));
};
$throws = function(callable $fn, string $errorcode = '') {
    try {
        $fn();
        return false;
    } catch (\moodle_exception $e) {
        return $errorcode === '' || $e->errorcode === $errorcode;
    }
};
$fullname = function(int $id) {
    $u = \core_user::get_user($id);
    return $u ? fullname($u) . " ($id)" : "($id)";
};
$topicbyarea = function(string $code) use ($DB) {
    return $DB->get_record_sql("SELECT c.*
                                  FROM {gmk_tkt_category} c
                                  JOIN {gmk_tkt_area} a ON a.id = c.areaid
                                 WHERE a.code = :code AND a.active = 1 AND c.active = 1
                                   AND (SELECT COUNT(1) FROM {gmk_tkt_agent} g WHERE g.categoryid = c.id) > 0
                              ORDER BY (SELECT COUNT(1) FROM {gmk_tkt_agent} g2 WHERE g2.categoryid = c.id) DESC,
                                       c.sortorder", ['code' => $code], IGNORE_MULTIPLE);
};

cli_writeln('Mesa de ayuda - prueba con rollback. Estudiante: ' . $fullname($studentid));
$transaction = $DB->start_delegated_transaction();
try {
    $academic = $topicbyarea('academico');
    if (!$academic) {
        throw new \coding_exception('No hay un tema académico con agentes configurados.');
    }
    $agents = array_map('intval', $DB->get_fieldset_select('gmk_tkt_agent', 'userid', 'categoryid = ?', [$academic->id]));
    cli_writeln("Tema: {$academic->name}; agentes: " . implode(', ', array_map($fullname, $agents)));
    foreach ($agents as $a) {
        manager::set_availability($a, true, 0);
    }

    // 1. Creation, numbering and balanced assignment.
    $t1 = manager::create($studentid, (int)$academic->id, 'Prueba automática', "Hola,\nnecesito ayuda.");
    $check('crea con numero TKT-AAAA-NNNN', (bool)preg_match('/^TKT-\d{4}-\d{4}$/', $t1->number), $t1->number);
    $check('queda asignado a un agente del tema', in_array((int)$t1->assignedto, $agents, true) && $t1->status === 'asignado',
        $t1->status . ' -> ' . $fullname((int)$t1->assignedto));
    $msgs = manager::messages($t1, $studentid, manager::ROLE_REQUESTER)['messages'];
    $check('mensaje automatico de creacion', count(array_filter($msgs, function($m) {
        return $m['senderrole'] === 'system';
    })) >= 1);
    $t2 = manager::create($studentid, (int)$academic->id, '', 'Segunda solicitud');
    if (count($agents) > 1) {
        $check('reparto equilibrado: la segunda va a otro agente', (int)$t2->assignedto !== (int)$t1->assignedto,
            $fullname((int)$t2->assignedto));
    }
    $check('numeracion consecutiva', (int)substr($t2->number, -4) === (int)substr($t1->number, -4) + 1, $t2->number);
    $agent = (int)$t1->assignedto;

    // 2. Requester only sees their own; internal notes stay hidden.
    [$m] = manager::send((int)$t1->id, $agent, 'Nota para el equipo', true);
    $msgs = manager::messages($t1, $studentid, manager::ROLE_REQUESTER)['messages'];
    $check('la nota interna no la ve el estudiante', !in_array((int)$m->id, array_column($msgs, 'id'), true));
    $check('el agente si ve la nota', in_array((int)$m->id,
        array_column(manager::messages($t1, $agent, manager::ROLE_AGENT)['messages'], 'id'), true));
    $before = manager::requester_unread($studentid);

    // 3. Reply, ask for info, student answers.
    [, $t1] = manager::send((int)$t1->id, $agent, 'Ya lo estamos revisando.');
    $check('respuesta: en_atencion y primera respuesta registrada', $t1->status === 'en_atencion' && $t1->first_response_at > 0,
        $t1->status);
    $check('el estudiante tiene no leidos', manager::requester_unread($studentid) > $before);
    [, $t1] = manager::send((int)$t1->id, $agent, '¿Me confirmas tu cédula?', false, true);
    $check('pedir informacion: esperando_estudiante', $t1->status === 'esperando_estudiante', $t1->status);
    [, $t1] = manager::send((int)$t1->id, $studentid, 'Sí, es la de mi perfil.');
    $check('el estudiante responde: vuelve a en_atencion', $t1->status === 'en_atencion' && $t1->waiting_since > 0, $t1->status);

    // 4. Resolve -> "No" reopens -> resolve -> "Si" closes.
    $t1 = manager::staff_action((int)$t1->id, $agent, 'resolve');
    $check('resolver', $t1->status === 'resuelto', $t1->status);
    $t1 = manager::confirm_resolution((int)$t1->id, $studentid, false);
    $check('"No" reabre con prioridad alta', $t1->status === 'reabierto' && (int)$t1->priority === 2
        && (int)$t1->reopened_count === 1 && (int)$t1->assignedto === $agent, $t1->status);
    [, $t1] = manager::send((int)$t1->id, $agent, 'Corregido.');
    $t1 = manager::staff_action((int)$t1->id, $agent, 'resolve');
    $t1 = manager::confirm_resolution((int)$t1->id, $studentid, true);
    $check('"Si" cierra', $t1->status === 'cerrado' && $t1->closed_at > 0, $t1->status);
    $check('cerrado no admite mensajes', $throws(function() use ($t1, $studentid) {
        manager::send((int)$t1->id, $studentid, 'otra cosa');
    }, 'tkt_err_closed'));
    $events = $DB->count_records('gmk_tkt_event', ['ticketid' => $t1->id]);
    $check('historial de eventos', $events >= 8, $events . ' eventos');

    // 5. State-machine rules.
    $check('no se resuelve sin respuesta del personal', $throws(function() use ($t2) {
        manager::staff_action((int)$t2->id, (int)$t2->assignedto, 'resolve');
    }, 'tkt_err_noresponse'));
    $t2 = manager::cancel((int)$t2->id, $studentid);
    $check('el estudiante cancela antes de la primera respuesta', $t2->status === 'cancelado', $t2->status);
    $check('un agente no puede confirmar por el estudiante', $throws(function() use ($t1, $agent) {
        manager::confirm_resolution((int)$t1->id, $agent, true);
    }));

    // 6. Everyone away: the ticket stays in the queue (or goes to a backup).
    foreach ($agents as $a) {
        manager::set_availability($a, false, 0);
    }
    $backups = array_map('intval', $DB->get_fieldset_sql(
        "SELECT DISTINCT backupuserid FROM {gmk_tkt_backup} WHERE userid IN (" . implode(',', $agents) . ")"));
    $t3 = manager::create($studentid, (int)$academic->id, '', 'Con todos ausentes');
    if ($backups) {
        $check('agentes ausentes: va a un respaldo', in_array((int)$t3->assignedto, $backups, true),
            $fullname((int)$t3->assignedto));
    } else {
        $check('agentes ausentes y sin respaldo: queda sin asignar', (int)$t3->assignedto === 0 && $t3->status === 'nuevo');
        $taker = $agents[0];
        $t3 = manager::staff_action((int)$t3->id, $taker, 'take');
        $check('"Tomar" desde la cola', (int)$t3->assignedto === $taker && $t3->status === 'asignado');
    }

    // 7. Cross access: another student cannot read it.
    $other = (int)$DB->get_field_sql("SELECT MIN(id) FROM {user} WHERE id > 2 AND id <> ? AND deleted = 0 AND suspended = 0",
        [$studentid]);
    if ($other && !manager::profile($other)->isstaff) {
        $check('otro usuario sin rol no ve el ticket', $throws(function() use ($t3, $other) {
            manager::require_ticket((int)$t3->id, $other);
        }, 'tkt_err_noaccess'), $fullname($other));
    }

    // 8. Confidential topic (Psychology).
    $psy = $topicbyarea('psicologia');
    if ($psy && !empty($psy->confidential)) {
        $t4 = manager::create($studentid, (int)$psy->id, '', 'Tema personal');
        $check('el ticket de Psicologia queda confidencial', (int)$t4->confidential === 1);
        $psyagents = array_map('intval', $DB->get_fieldset_select('gmk_tkt_agent', 'userid', 'categoryid = ?', [$psy->id]));
        foreach ($psyagents as $a) {
            $check('lo ve su agente ' . $fullname($a), manager::staff_can_view($t4, $a));
        }
        foreach ($agents as $a) {
            if (in_array($a, $psyagents, true)) {
                continue;
            }
            $p = manager::profile($a);
            $expected = $p->viewconf || in_array((int)$psy->id, $p->cats, true);
            $check('agente academico ' . $fullname($a) . ($expected ? ' (respaldo o gerencia) si' : ' no') . ' lo ve',
                manager::staff_can_view($t4, $a) === $expected);
        }
        foreach ($DB->get_fieldset_select('gmk_tkt_person', 'userid', "kind = 'gerencia'") as $g) {
            $check('gerencia ' . $fullname((int)$g) . ' lo ve', manager::staff_can_view($t4, (int)$g));
        }
        $admin = get_admin();
        if ($admin && !manager::profile((int)$admin->id)->viewconf && !in_array((int)$psy->id, manager::profile((int)$admin->id)->cats, true)) {
            $check('el administrador del sitio sin rol de gerencia no lo ve', !manager::staff_can_view($t4, (int)$admin->id));
        }
    }

    // 9. Open-tickets limit.
    $max = \local_grupomakro_core\local\support\config::get_int('tkt_max_open');
    $refused = false;
    for ($i = 0; $i <= $max; $i++) {
        try {
            manager::create($studentid, (int)$academic->id, '', 'Limite ' . $i);
        } catch (\moodle_exception $e) {
            $refused = $e->errorcode === 'tkt_err_maxopen' || $e->errorcode === 'tkt_err_maxdaily';
            break;
        }
    }
    $check("limite de $max abiertos (o del dia)", $refused);

    // 10. Desk listing.
    $desk = manager::desk_list($agent, 'mine');
    $check('bandeja del agente responde', isset($desk['counts']['mine']), json_encode($desk['counts']));
    $check('el estudiante no entra a la bandeja', $throws(function() use ($studentid) {
        manager::desk_list($studentid, 'mine');
    }, 'tkt_err_nostaff') || manager::profile($studentid)->isstaff);
} catch (\Throwable $e) {
    $check('excepcion inesperada', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

try {
    $transaction->rollback(new \moodle_exception('support_selftest_rollback'));
} catch (\moodle_exception $e) {
    // Expected: rollback() rethrows.
}
manager::reset_caches();

$failed = count(array_filter($results, function($r) {
    return !$r[0];
}));
cli_writeln('');
cli_writeln(sprintf('%d pruebas, %d fallas. Todo revertido (tickets restantes del estudiante: %d).', count($results), $failed,
    $DB->count_records('gmk_tkt_ticket', ['userid' => $studentid])));
exit($failed ? 1 : 0);
