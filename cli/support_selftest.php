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
 * Fase 2 (20261001121): minutos habiles (tramos, medianoche, sabado, domingo,
 * feriado de prueba, cambio de horario, inversa add_minutes), umbrales del
 * semaforo, escalera completa 20 min / 1 h / 3 h / 5 h y repeticion en rojo
 * (con un horario 24/7 temporal en el area academica), idempotencia, Vencidos
 * en minutos habiles, indicador (contadores, alertas, sin texto de mensajes,
 * acuse), recordatorio de 12 h, falta de acuse, posponer, tope de correos,
 * calificacion ("Ahora no" y calificacion baja) y tickets de docentes. El
 * feriado y el horario temporal tambien se revierten.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_grupomakro_core\local\support\business_hours;
use local_grupomakro_core\local\support\escalation;
use local_grupomakro_core\local\support\manager;
use local_grupomakro_core\local\support\policy;

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

    // =====================================================================
    // FASE 2: horas habiles, semaforo, escalamiento, acuse, indicador,
    // calificacion, recordatorio, tope de correos y docentes.
    // =====================================================================
    cli_writeln('');
    cli_writeln('Fase 2');
    manager::reset_caches();
    $tz = \core_date::get_server_timezone_object();
    $at = function(string $local) use ($tz) {
        return (new \DateTimeImmutable($local, $tz))->getTimestamp();
    };

    // 11. business_hours::elapsed / add_minutes on a synthetic area (fixed dates in 2030).
    $holidaydates = [];
    foreach ($DB->get_fieldset_select('gmk_holidays', 'date', 'date > 0') as $ts) {
        $holidaydates[(new \DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('Y-m-d')] = true;
    }
    // 2030-01-07 is a Monday; 2030-01-08 is used as the test holiday.
    $free = !isset($holidaydates['2030-01-07']) && !isset($holidaydates['2030-01-08']) && !isset($holidaydates['2030-01-09'])
        && !isset($holidaydates['2030-01-12']) && !isset($holidaydates['2030-01-14']);
    if ($free) {
        $area = (object)['hours' => json_encode(['1' => [['08:00', '17:00']], '2' => [['08:00', '17:00']],
            '3' => [['08:00', '17:00']], '4' => [['08:00', '17:00']], '5' => [['08:00', '17:00']], '6' => [['08:00', '12:00']]])];
        $split = (object)['hours' => json_encode(['1' => [['08:00', '12:00'], ['14:00', '18:00']]])];
        $nohours = (object)['hours' => ''];
        $e = function($from, $to, $a) use ($at) {
            return business_hours::elapsed($at($from), $at($to), $a);
        };
        $check('horas habiles: dentro del mismo tramo', $e('2030-01-07 09:00', '2030-01-07 10:30', $area) === 90);
        $check('horas habiles: antes de abrir no cuenta', $e('2030-01-07 06:00', '2030-01-07 08:30', $area) === 30);
        $check('horas habiles: cruce de medianoche (16:30 lun -> 08:30 mar)',
            $e('2030-01-07 16:30', '2030-01-08 08:30', $area) === 60, (string)$e('2030-01-07 16:30', '2030-01-08 08:30', $area));
        $check('horas habiles: sabado corto y domingo cerrado (sab 11:00 -> lun 09:00)',
            $e('2030-01-12 11:00', '2030-01-14 09:00', $area) === 120, (string)$e('2030-01-12 11:00', '2030-01-14 09:00', $area));
        $check('horas habiles: dos tramos con almuerzo (11:00 -> 15:00)',
            $e('2030-01-07 11:00', '2030-01-07 15:00', $split) === 120);
        $check('horas habiles: cambio de horario (mismo intervalo, otra area)',
            $e('2030-01-07 16:00', '2030-01-07 18:00', $area) === 60 && $e('2030-01-07 16:00', '2030-01-07 18:00', $split) === 120);
        $check('horas habiles: area sin horario = reloj', $e('2030-01-07 22:00', '2030-01-08 01:00', $nohours) === 180);
        $check('horas habiles: add_minutes es la inversa (60 min desde lun 16:30 = mar 08:30)',
            business_hours::add_minutes($at('2030-01-07 16:30'), 60, $area) === $at('2030-01-08 08:30'));
        $DB->insert_record('gmk_holidays', (object)['academicperiodid' => 0, 'date' => $at('2030-01-08 00:00'),
            'name' => 'Feriado de prueba (selftest)', 'type' => 'feriado', 'usermodified' => 0, 'timecreated' => time(),
            'timemodified' => time()]);
        business_hours::reset_cache();
        $check('horas habiles: feriado de gmk_holidays no cuenta (lun 16:00 -> mie 09:00)',
            $e('2030-01-07 16:00', '2030-01-09 09:00', $area) === 120, (string)$e('2030-01-07 16:00', '2030-01-09 09:00', $area));
        $check('horas habiles: add_minutes salta el feriado',
            business_hours::add_minutes($at('2030-01-07 16:30'), 60, $area) === $at('2030-01-09 08:30'));
    } else {
        cli_writeln('  (omito las pruebas de calendario: hay feriados cargados entre el 7 y el 14 de enero de 2030)');
    }

    // 12. Semaphore thresholds from config.
    [$tw, $ty, $to, $tr] = policy::thresholds();
    $check('semaforo: niveles por umbral configurado', policy::level_for($tw - 1) === 0 && policy::level_for($tw) === 1
        && policy::level_for($ty) === 2 && policy::level_for($to) === 3 && policy::level_for($tr) === 4,
        "$tw/$ty/$to/$tr");

    // Test area open 24/7 (rolled back) so the ladder can be checked with "now".
    foreach ($agents as $a) {
        manager::set_availability($a, true, 0);
    }
    $todayholiday = isset($holidaydates[(new \DateTimeImmutable('@' . time()))->setTimezone($tz)->format('Y-m-d')]);
    $alwaysopen = json_encode(array_fill_keys(['1', '2', '3', '4', '5', '6', '7'], [['00:00', '24:00']]));
    $DB->set_field('gmk_tkt_area', 'hours', $alwaysopen, ['id' => (int)$academic->areaid]);
    manager::reset_caches();
    if ($todayholiday) {
        cli_writeln('  (hoy es feriado en gmk_holidays: el reloj habil no corre y omito escalamiento y acuse)');
    } else {
        $now = time();
        $e1 = manager::create($studentid, (int)$academic->id, 'Prueba escalamiento', 'Necesito ayuda (fase 2)');
        $agent1 = (int)$e1->assignedto;
        $set = function(stdClass $t, array $fields) use ($DB) {
            $DB->update_record('gmk_tkt_ticket', (object)(['id' => (int)$t->id] + $fields));
            return $DB->get_record('gmk_tkt_ticket', ['id' => (int)$t->id]);
        };
        $events = function(stdClass $t, string $type) use ($DB) {
            return $DB->count_records('gmk_tkt_event', ['ticketid' => (int)$t->id, 'eventtype' => $type]);
        };

        // 13. Business minutes in the desk export.
        $e1 = $set($e1, ['waiting_since' => $now - 25 * MINSECS]);
        $row = manager::export_ticket($e1, $agent1, manager::ROLE_AGENT);
        $check('bandeja: espera en minutos habiles y semaforo verde con aviso',
            $row['waitingminutes'] >= 24 && $row['waitingminutes'] <= 26 && $row['semaphore'] === 'green_warn',
            $row['waitingminutes'] . ' min, ' . $row['semaphore']);

        // 14. Escalation ladder.
        $r = escalation::process_ticket($e1, $now);
        $e1 = $DB->get_record('gmk_tkt_ticket', ['id' => (int)$e1->id]);
        $warned = $DB->record_exists_select('gmk_tkt_event', "ticketid = :t AND eventtype = 'auto_message' AND "
            . $DB->sql_compare_text('newvalue') . " = 'sla_warning'", ['t' => (int)$e1->id]);
        $check('20 min: nivel 1, evento y mensaje automatico al solicitante',
            $r === 'escalated' && (int)$e1->escalation_level === 1 && $warned, $r . ' nivel ' . $e1->escalation_level);
        $before = $events($e1, 'escalation');
        $r = escalation::process_ticket($e1, $now);
        $check('idempotente: otra pasada no repite', $r === 'none' && $events($e1, 'escalation') === $before);

        $e1 = $set($e1, ['waiting_since' => $now - 65 * MINSECS, 'assignedto' => 0, 'status' => 'nuevo']);
        escalation::process_ticket($e1, $now);
        $e1 = $DB->get_record('gmk_tkt_ticket', ['id' => (int)$e1->id]);
        $check('1 h: nivel 2 y, si estaba en cola, se asigna al de menor carga',
            (int)$e1->escalation_level === 2 && in_array((int)$e1->assignedto, $agents, true),
            'nivel ' . $e1->escalation_level . ', ' . $fullname((int)$e1->assignedto));
        $agent1 = (int)$e1->assignedto;

        manager::set_availability($agent1, false, 0);
        $e1 = $set($e1, ['waiting_since' => $now - 185 * MINSECS, 'priority' => 1]);
        escalation::process_ticket($e1, $now);
        $e1 = $DB->get_record('gmk_tkt_ticket', ['id' => (int)$e1->id]);
        $check('3 h: nivel 3, prioridad alta y vuelve a la cola si el agente esta ausente',
            (int)$e1->escalation_level === 3 && (int)$e1->priority === 2 && (int)$e1->assignedto === 0,
            'nivel ' . $e1->escalation_level . ', prioridad ' . $e1->priority . ', asignado ' . $e1->assignedto);
        manager::set_availability($agent1, true, 0);
        $e1 = manager::staff_action((int)$e1->id, $agent1, 'take');

        $e1 = $set($e1, ['waiting_since' => $now - 305 * MINSECS]);
        $mark = manager::outbox_size();
        escalation::process_ticket($e1, $now);
        $e1 = $DB->get_record('gmk_tkt_ticket', ['id' => (int)$e1->id]);
        $gerencia = manager::gerencia_for($e1);
        $check('5 h: nivel 4 (rojo) y aviso a gerencia', (int)$e1->escalation_level === 4
            && (!$gerencia || manager::outbox_size() > $mark), 'gerencia: ' . implode(', ', array_map($fullname, $gerencia)));
        $repeat = \local_grupomakro_core\local\support\config::get_int('tkt_red_repeat');
        $check('rojo: no se repite antes de tkt_red_repeat', escalation::process_ticket($e1, $now) === 'none');
        if ($repeat > 0) {
            $e1 = $set($e1, ['escalated_at' => $now - ($repeat + 1) * MINSECS]);
            $check('rojo: se repite cada ' . $repeat . ' min habiles', escalation::process_ticket($e1, $now) === 'repeated');
        }

        // 15. Overdue tab and badge.
        $check('Vencidos: el ticket en rojo aparece (minutos habiles)', in_array((int)$e1->id, manager::overdue_ids($agent1), true));
        $DB->set_field('gmk_tkt_area', 'hours', json_encode(['1' => [['00:00', '00:01']]]), ['id' => (int)$academic->areaid]);
        manager::reset_caches();
        $check('Vencidos: con el area cerrada esas horas no cuentan', !in_array((int)$e1->id, manager::overdue_ids($agent1), true));
        $DB->set_field('gmk_tkt_area', 'hours', $alwaysopen, ['id' => (int)$academic->areaid]);
        manager::reset_caches();

        $e1 = $set($e1, ['last_student_msg_at' => $now - 30, 'escalated_at' => $now - 60, 'assigned_at' => $now - 120]);
        $DB->delete_records('gmk_tkt_ack', ['ticketid' => (int)$e1->id]);
        $b = \local_grupomakro_core\local\support\badge::build($agent1, time());
        $keys = array_column($b['staff']['alerts'] ?? [], 'ticketid');
        $check('indicador: el agente ve contadores y la alerta por acusar', isset($b['staff']['mine'])
            && in_array((int)$e1->id, $keys, true), json_encode(['mine' => $b['staff']['mine'] ?? null, 'overdue' => $b['staff']['overdue'] ?? null]));
        $check('indicador: nunca lleva el texto de los mensajes', strpos(json_encode($b), 'Necesito ayuda (fase 2)') === false);
        manager::ack((int)$e1->id, $agent1, 'open');
        $b = \local_grupomakro_core\local\support\badge::build($agent1, time());
        $check('acuse: al abrir el ticket la alerta se apaga',
            !in_array((int)$e1->id, array_column($b['staff']['alerts'], 'ticketid'), true));
        $sb = \local_grupomakro_core\local\support\badge::build($studentid, time());
        $check('indicador: un estudiante no recibe datos del personal', $sb['staff'] === null || manager::profile($studentid)->isstaff);

        // 16. Reply resets the ladder; 12 h reminder.
        [, $e1] = manager::send((int)$e1->id, $agent1, '¿Puedes enviarme una captura?', false, true);
        $check('respuesta del personal: el escalamiento vuelve a 0', (int)$e1->escalation_level === 0 && (int)$e1->waiting_since === 0);
        $remind = \local_grupomakro_core\local\support\config::get_int('tkt_remind_hours');
        if ($remind > 0) {
            $e1 = $set($e1, ['last_staff_msg_at' => $now - ($remind * HOURSECS + 60), 'last_student_msg_at' => $now - ($remind * HOURSECS + 120)]);
            $sent = manager::remind_stale($now);
            $check('recordatorio de ' . $remind . ' h al solicitante', $sent >= 1);
            $check('recordatorio: no se repite', manager::remind_stale($now) === 0);
        }

        // 17. No acknowledgement within tkt_ack_minutes.
        $ackmin = \local_grupomakro_core\local\support\config::get_int('tkt_ack_minutes');
        $e2 = manager::create($studentid, (int)$academic->id, 'Prueba acuse', 'Segunda de fase 2');
        if ($ackmin > 0 && (int)$e2->assignedto > 0) {
            $past = $now - ($ackmin + 1) * MINSECS;
            $e2 = $set($e2, ['waiting_since' => $past, 'last_student_msg_at' => $past, 'assigned_at' => $past, 'escalated_at' => 0]);
            $check('sin acuse en ' . $ackmin . ' min: aviso al respaldo/administradores', escalation::check_ack($e2, $now));
            $check('sin acuse: no se repite para la misma alerta', !escalation::check_ack($e2, $now));
            $e2 = $set($e2, ['last_student_msg_at' => $past + 1]);
            manager::ack((int)$e2->id, (int)$e2->assignedto, 'open');
            $check('con acuse no hay aviso', !escalation::check_ack($e2, $now));

            // Snooze: at most tkt_snooze_max times per alert.
            $e2 = $set($e2, ['last_student_msg_at' => time() + 5]);
            $max = \local_grupomakro_core\local\support\config::get_int('tkt_snooze_max');
            for ($i = 0; $i < $max; $i++) {
                \local_grupomakro_core\local\support\badge::snooze((int)$e2->id, (int)$e2->assignedto);
            }
            $check('posponer: maximo ' . $max . ' veces', $throws(function() use ($e2) {
                \local_grupomakro_core\local\support\badge::snooze((int)$e2->id, (int)$e2->assignedto);
            }, 'tkt_err_nosnooze'));
        }

        // 18. E-mail gate: 1 per tkt_mail_gap minutes per ticket and person.
        if (\local_grupomakro_core\local\support\config::get_int('tkt_mail_gap') > 0) {
            $first = manager::mail_gate((int)$e2->id, $studentid, $now);
            $second = manager::mail_gate((int)$e2->id, $studentid, $now + 60);
            $third = manager::mail_gate((int)$e2->id, $studentid, $now + 3600);
            $check('correo: uno cada tkt_mail_gap min por ticket y persona', $first && !$second && $third);
        }
    }

    // 19. Rating of a closed ticket (t1 closed by the student above).
    if (\local_grupomakro_core\local\support\config::get_int('tkt_rating_enabled')) {
        $pending = array_column(manager::pending_ratings($studentid), 'id');
        $check('calificacion: el ticket cerrado queda pendiente de calificar', in_array((int)$t1->id, $pending, true));
        manager::dismiss_rating((int)$t1->id, $studentid);
        $check('"Ahora no": no reaparece el mismo dia', !in_array((int)$t1->id, array_column(manager::pending_ratings($studentid), 'id'), true));
        $check('"Ahora no": reaparece al dia siguiente', in_array((int)$t1->id,
            array_column(manager::pending_ratings($studentid, time() + DAYSECS + 60), 'id'), true));
        $low = \local_grupomakro_core\local\support\config::get_int('tkt_rating_low');
        $rated = manager::rate((int)$t1->id, $studentid, max(1, $low), false, 'Prueba de calificación baja');
        $check('calificacion guardada con "no se resolvio"', (int)$rated->rating === max(1, $low) && (int)$rated->rating_solved === 2);
        $check('calificacion: no se califica dos veces', $throws(function() use ($t1, $studentid) {
            manager::rate((int)$t1->id, $studentid, 5, true, '');
        }, 'tkt_err_badstatus'));
    }

    // 20. Teachers (§8.4).
    $teacherareas = $DB->get_fieldset_select('gmk_tkt_area', 'id', 'active = 1 AND teacher_visible = 1');
    $catalog = manager::catalog($studentid, 'teacher');
    $check('docentes: el catalogo solo trae areas para docentes', count(array_filter($catalog, function($a) use ($teacherareas) {
        return !in_array($a['id'], array_map('intval', $teacherareas), true);
    })) === 0, implode(', ', array_column($catalog, 'name')));
    $titopic = $topicbyarea('ti');
    if ($titopic && !manager::is_teacher($studentid)) {
        $check('docentes: quien no es profesor no abre ticket como docente', $throws(function() use ($studentid, $titopic) {
            manager::create($studentid, (int)$titopic->id, '', 'x', 0, 'teacher');
        }, 'tkt_err_badcategory'));
    }
    $teacherid = (int)$DB->get_field_sql("SELECT MIN(ra.userid) FROM {role_assignments} ra JOIN {role} r ON r.id = ra.roleid
        JOIN {user} u ON u.id = ra.userid AND u.deleted = 0 AND u.suspended = 0
        WHERE r.shortname IN ('editingteacher', 'teacher')");
    if ($titopic && $teacherid > 0 && !manager::profile($teacherid)->isstaff) {
        $tt = manager::create($teacherid, (int)$titopic->id, 'Prueba docente', 'El aula no carga (selftest)', 0, 'teacher');
        $check('docentes: ticket con requesterrole=teacher y enlace a Moodle',
            $tt->requesterrole === 'teacher' && strpos(manager::requester_url($tt), 'support_my.php') !== false,
            $fullname($teacherid));
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
