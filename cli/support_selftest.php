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
 * acuse), recordatorio en horas habiles, falta de acuse, posponer, tope de correos,
 * calificacion ("Ahora no" y calificacion baja) y tickets de docentes. El
 * feriado y el horario temporal tambien se revierten.
 *
 * 20261001122: horario propio del agente (reloj, sin asignar = area, modo
 * incisivo, herencia, reparto en horario, traspaso fuera de horario) y
 * reasignacion: TI reasigna un ticket academico (a Jorge) y a otra area, no ve
 * ni reasigna Psicologia, y un agente sin support_reassign no reasigna uno ajeno.
 *
 * 20261001123: recordatorio y cierre automatico en horas habiles (un ticket
 * esperando al estudiante desde el viernes 16:00 no se cierra el fin de semana;
 * recordatorio y cierre justo al cumplirse las horas habiles).
 *
 * 20261001124: adjuntos (tipos y contenido real, tamanos, cantidad, envio de
 * imagen y video sin texto, exportacion, marcador en avisos, acceso: el
 * solicitante y el agente si, otro usuario no, notas internas no al
 * solicitante, borrador ajeno no, Psicologia confidencial no). Los archivos
 * de prueba se revierten con la transaccion.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/filelib.php');

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
            $check('recordatorio de ' . $remind . ' horas habiles al solicitante (area 24/7)', $sent >= 1);
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

    // =====================================================================
    // 20261001122: horario por agente y reasignacion (supervisores, gerencia, TI).
    // =====================================================================
    cli_writeln('');
    cli_writeln('Horario por agente y reasignacion');
    // Free slots under the open-tickets limit: close / cancel the phase 2 tickets.
    if (isset($e1) && manager::is_open_status((string)$DB->get_field('gmk_tkt_ticket', 'status', ['id' => $e1->id]))) {
        manager::staff_action((int)$e1->id, (int)$DB->get_field('gmk_tkt_ticket', 'assignedto', ['id' => $e1->id]) ?: $agents[0], 'close');
    }
    if (isset($e2) && (int)$DB->get_field('gmk_tkt_ticket', 'first_response_at', ['id' => $e2->id]) === 0
            && manager::is_open_status((string)$DB->get_field('gmk_tkt_ticket', 'status', ['id' => $e2->id]))) {
        manager::cancel((int)$e2->id, $studentid);
    }
    foreach ($agents as $a) {
        manager::set_availability($a, true, 0);
    }
    $DB->set_field('gmk_tkt_area', 'hours', $alwaysopen, ['id' => (int)$academic->areaid]);
    manager::reset_caches();
    $now = time();
    $today = (int)(new \DateTimeImmutable('@' . $now))->setTimezone($tz)->format('N');
    $otherday = (string)($today % 7 + 1);
    $offhours = json_encode([$otherday => [['00:00', '24:00']]]);
    $sethours = function(int $userid, ?string $hours) use ($DB) {
        $row = $DB->get_record('gmk_tkt_presence', ['userid' => $userid]);
        if ($row) {
            $DB->set_field('gmk_tkt_presence', 'hours', $hours, ['id' => $row->id]);
        } else {
            $DB->insert_record('gmk_tkt_presence', (object)['userid' => $userid, 'available' => 1, 'hours' => $hours,
                'modifiedby' => 0, 'timemodified' => time()]);
        }
        manager::reset_caches();
    };

    if (!$todayholiday) {
        // 21. Own hours of the assigned agent rule the clock.
        $h1 = manager::create($studentid, (int)$academic->id, 'Prueba horario de agente', 'Horario del agente (selftest)');
        $agenta = (int)$h1->assignedto;
        if ($agenta > 0) {
            $sethours($agenta, $offhours);
            $DB->update_record('gmk_tkt_ticket', (object)['id' => $h1->id, 'waiting_since' => $now - 30 * MINSECS]);
            $h1 = $DB->get_record('gmk_tkt_ticket', ['id' => $h1->id]);
            $check('horario del agente: fuera de su horario el reloj no corre', policy::waiting_minutes($h1, $now) === 0,
                policy::waiting_minutes($h1, $now) . ' min');
            $queue = clone $h1;
            $queue->assignedto = 0;
            $w = policy::waiting_minutes($queue, $now);
            $check('sin asignar: cuenta el horario del area', $w >= 29 && $w <= 31, $w . ' min');
            $check('modo incisivo: apagado fuera del horario del agente',
                !\local_grupomakro_core\local\support\badge::in_hours($agenta, manager::profile($agenta), $now));
            $sethours($agenta, null);
            $check('sin horario propio hereda el del area', policy::waiting_minutes($h1, $now) >= 29);
            $sethours($agenta, $offhours);

            // Auto-assignment prefers someone within hours.
            $others = array_values(array_diff($agents, [$agenta]));
            if ($others) {
                $h2 = manager::create($studentid, (int)$academic->id, 'Prueba reparto en horario', 'Reparto (selftest)');
                $check('reparto: prefiere a un agente en horario', (int)$h2->assignedto > 0 && (int)$h2->assignedto !== $agenta,
                    $fullname((int)$h2->assignedto));
            }

            // Assignee outside their hours, area open: hand it to someone in hours.
            if (\local_grupomakro_core\local\support\config::get_int('tkt_offhours_handoff')) {
                $past = $now - (max(1, \local_grupomakro_core\local\support\config::get_int('tkt_ack_minutes')) + 2) * MINSECS;
                $DB->update_record('gmk_tkt_ticket', (object)['id' => $h1->id, 'waiting_since' => $past]);
                $h1 = $DB->get_record('gmk_tkt_ticket', ['id' => $h1->id]);
                $moved = escalation::handoff_off_hours($h1, $now);
                $h1 = $DB->get_record('gmk_tkt_ticket', ['id' => $h1->id]);
                $check('agente fuera de horario: el ticket pasa a quien esta en horario',
                    $others ? ($moved && (int)$h1->assignedto !== $agenta && (int)$h1->assignedto > 0)
                        : (!$moved || (int)$h1->assignedto !== $agenta),
                    $moved ? '-> ' . $fullname((int)$h1->assignedto) : 'nadie del tema ni respaldo en horario: aviso a supervisores');
                $check('traspaso o aviso queda en el historial', $DB->record_exists_select('gmk_tkt_event',
                    "ticketid = ? AND eventtype IN ('offhours_handoff', 'offhours_nohandoff')", [$h1->id]));
            }
            $sethours($agenta, null);
        }
    }

    // 22. Reassign: TI (gmk_soporte_ti) without being supervisor.
    $tiuser = $DB->record_exists('user', ['id' => 2756, 'deleted' => 0]) ? 2756
        : (int)$DB->get_field_sql("SELECT MIN(ra.userid) FROM {role_assignments} ra JOIN {role} r ON r.id = ra.roleid
                                    WHERE r.shortname = 'gmk_soporte_ti'");
    $tirole = $DB->get_record('role', ['shortname' => 'gmk_soporte_ti']);
    if ($tiuser > 0 && $tirole) {
        $sys = \context_system::instance();
        if (!has_capability('local/grupomakro_core:support_reassign', $sys, $tiuser)) {
            role_assign($tirole->id, $tiuser, $sys->id);
            accesslib_clear_all_caches(false);
            manager::reset_caches();
        }
        $tip = manager::profile($tiuser);
        $check('TI tiene support_reassign y ve la pestana Todos', $tip->reassign
            && isset(manager::desk_list($tiuser, 'all')['counts']['all']), $fullname($tiuser));
        $check('TI no es supervisor (no recibe alertas de 1 h y 3 h)', !$tip->supervisor
            || has_capability('local/grupomakro_core:support_supervise', $sys, $tiuser));

        $rt = isset($h2) ? $DB->get_record('gmk_tkt_ticket', ['id' => $h2->id])
            : manager::create($studentid, (int)$academic->id, 'Prueba reasignar', 'Reasignar (selftest)');
        $jorge = $DB->record_exists('user', ['id' => 2737, 'deleted' => 0, 'suspended' => 0]) ? 2737 : 0;
        $assignable = manager::assignable_staff($rt);
        if (!$jorge || !isset($assignable[$jorge]) || (int)$rt->assignedto === $jorge) {
            $jorge = 0;
            foreach (array_keys($assignable) as $uid) {
                if ((int)$uid !== (int)$rt->assignedto) {
                    $jorge = (int)$uid;
                    break;
                }
            }
        }
        if ($jorge) {
            $rt = manager::staff_action((int)$rt->id, $tiuser, 'assign', $jorge);
            $check('TI reasigna un ticket academico a ' . $fullname($jorge), (int)$rt->assignedto === $jorge);
        }
        $titopic2 = $topicbyarea('ti');
        if ($titopic2 && (int)$titopic2->areaid !== (int)$rt->areaid) {
            $dest = array_keys(manager::assignable_staff(manager::transfer_target($rt, (int)$titopic2->id)->ticket));
            if ($dest) {
                $before = $DB->count_records('gmk_tkt_message', ['ticketid' => $rt->id, 'senderrole' => 'system']);
                $rt = manager::staff_action((int)$rt->id, $tiuser, 'assign', (int)$dest[0], -1, (int)$titopic2->id);
                $check('reasignar a otra area con su tema: cambia area, asigna y avisa al estudiante',
                    (int)$rt->areaid === (int)$titopic2->areaid && (int)$rt->assignedto === (int)$dest[0]
                    && $DB->count_records('gmk_tkt_message', ['ticketid' => $rt->id, 'senderrole' => 'system']) > $before
                    && $DB->record_exists_select('gmk_tkt_event', "ticketid = ? AND eventtype = 'category'", [$rt->id]));
            }
        }
        if (isset($t4) && !empty($t4->confidential) && !manager::profile($tiuser)->viewconf) {
            $psyagent = (int)$DB->get_field('gmk_tkt_ticket', 'assignedto', ['id' => $t4->id]);
            $check('TI no ve un ticket de Psicologia', !manager::staff_can_view($t4, $tiuser));
            $check('TI no reasigna un ticket de Psicologia', $throws(function() use ($t4, $tiuser, $psyagent) {
                manager::staff_action((int)$t4->id, $tiuser, 'assign', $psyagent ?: $tiuser);
            }, 'tkt_err_noaccess'));
        }
    } else {
        cli_writeln('  (omito reasignacion por TI: no hay usuario 2756 ni con rol gmk_soporte_ti)');
    }
    // An agent without the capability cannot reassign someone else's ticket.
    $plain = 0;
    foreach ($agents as $a) {
        if (!manager::profile($a)->reassign) {
            $plain = $a;
            break;
        }
    }
    $foreign = $DB->get_record_select('gmk_tkt_ticket', 'userid = :u AND areaid = :a AND assignedto > 0 AND assignedto <> :p
        AND status NOT IN (:c1, :c2)', ['u' => $studentid, 'a' => (int)$academic->areaid, 'p' => $plain,
        'c1' => 'cerrado', 'c2' => 'cancelado'], '*', IGNORE_MULTIPLE);
    if ($plain && $foreign) {
        $check('un agente sin la capacidad no reasigna un ticket ajeno', $throws(function() use ($foreign, $plain) {
            manager::staff_action((int)$foreign->id, $plain, 'assign', $plain);
        }, 'tkt_err_cannotassign'), $fullname($plain));
    } else {
        cli_writeln('  (omito "agente sin capacidad": todos los agentes academicos pueden reasignar o no hay ticket ajeno)');
    }

    // 23. 20261001123: reminder and auto-close count BUSINESS hours (agent's or area's clock).
    $range = ['2030-01-11', '2030-01-12', '2030-01-13', '2030-01-14', '2030-01-15', '2030-01-16', '2030-01-17', '2030-01-18',
        '2030-01-21', '2030-01-22'];
    $busy = array_filter($range, function($d) use ($holidaydates) {
        return isset($holidaydates[$d]);
    });
    if ($busy) {
        cli_writeln('  (omito horas habiles del cierre: hay feriados cargados en ' . implode(', ', $busy) . ')');
    } else {
        $office = json_encode(array_fill_keys(['1', '2', '3', '4', '5'], [['08:00', '17:00']]));
        $DB->set_field('gmk_tkt_area', 'hours', $office, ['id' => (int)$academic->areaid]);
        manager::reset_caches();
        $wk = (isset($h1) && manager::is_open_status((string)$DB->get_field('gmk_tkt_ticket', 'status', ['id' => $h1->id])))
            ? $DB->get_record('gmk_tkt_ticket', ['id' => $h1->id])
            : manager::create($studentid, (int)$academic->id, 'Prueba cierre en horas habiles', 'Cierre (selftest)');
        if ((int)$wk->assignedto > 0) {
            $sethours((int)$wk->assignedto, null); // Agent without own hours: the area's clock.
        }
        $fri = $at('2030-01-11 16:00'); // Friday afternoon.
        $DB->update_record('gmk_tkt_ticket', (object)['id' => $wk->id, 'status' => manager::S_WAITING,
            'first_response_at' => $fri - HOURSECS, 'last_staff_msg_at' => $fri, 'last_student_msg_at' => $fri - 1800,
            'waiting_since' => 0]);
        $ids = [(int)$wk->id];
        $clockarea = (object)['hours' => $office];
        $closeh = \local_grupomakro_core\local\support\config::get_int('tkt_close_hours');
        $remindh = \local_grupomakro_core\local\support\config::get_int('tkt_remind_hours');
        if (\local_grupomakro_core\local\support\config::get_int('tkt_autoclose_enabled') && $closeh > 0) {
            $check('cierre en horas habiles: esperando desde el viernes 16:00 no se cierra el sabado ni el domingo',
                manager::autoclose_stale($at('2030-01-12 12:00'), $ids) === 0
                && manager::autoclose_stale($at('2030-01-13 20:00'), $ids) === 0
                && $DB->get_field('gmk_tkt_ticket', 'status', ['id' => $wk->id]) === manager::S_WAITING);
            if ($remindh > 0 && $remindh < $closeh) {
                $due = business_hours::add_minutes($fri, $remindh * 60, $clockarea);
                $check('recordatorio a las ' . $remindh . ' horas habiles (' . userdate($due, '%A %d %H:%M') . ')',
                    manager::remind_stale($due - 120, $ids) === 0 && manager::remind_stale($due + 60, $ids) === 1);
                $msg = $DB->get_field_sql("SELECT body FROM {gmk_tkt_message} WHERE ticketid = ? AND senderrole = 'system'
                                            ORDER BY id DESC", [$wk->id], IGNORE_MULTIPLE);
                $check('el recordatorio dice horas habiles', strpos((string)$msg, 'hábiles') !== false
                    || !$DB->record_exists_select('gmk_tkt_template', "code = 'reminder' AND " . $DB->sql_like('body', ':b'),
                        ['b' => '%{horas_cierre}%']), (string)$msg);
            }
            $closedue = business_hours::add_minutes($fri, $closeh * 60, $clockarea);
            $weekday = (int)(new \DateTimeImmutable('@' . $closedue))->setTimezone($tz)->format('N');
            $check('el cierre cae en dia habil (' . userdate($closedue, '%A %d %H:%M') . ')', $weekday <= 5);
            $before = manager::autoclose_stale($closedue - 120, $ids);
            $after = manager::autoclose_stale($closedue + 60, $ids);
            $check('cierre a las ' . $closeh . ' horas habiles exactas', $before === 0 && $after === 1
                && $DB->get_field('gmk_tkt_ticket', 'status', ['id' => $wk->id]) === manager::S_CLOSED);
        }
        $DB->set_field('gmk_tkt_area', 'hours', $alwaysopen, ['id' => (int)$academic->areaid]);
        manager::reset_caches();
    }

    // =====================================================================
    // 24. 20261001124: adjuntos del chat (imagenes, videos y PDF).
    // =====================================================================
    cli_writeln('');
    cli_writeln('Adjuntos');
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    $mp4 = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom" . str_repeat("\x00", 64);
    $pdf = "%PDF-1.4\n%selftest\n";
    $fsx = get_file_storage();
    $mkdraft = function(int $userid, array $files) use ($fsx, $DB) {
        // Not file_get_unused_draft_itemid(): it needs a logged-in $USER (CLI = no one -> "No guests here!").
        // Same logic, for the given user: a random itemid not used in that user's draft area.
        $ctx = \context_user::instance($userid);
        do {
            $itemid = rand(1, 999999999);
        } while ($DB->record_exists('files', ['contextid' => $ctx->id, 'component' => 'user', 'filearea' => 'draft',
            'itemid' => $itemid]));
        foreach ($files as $name => $content) {
            $fsx->create_file_from_string(['contextid' => $ctx->id, 'component' => 'user', 'filearea' => 'draft',
                'itemid' => $itemid, 'filepath' => '/', 'filename' => $name, 'userid' => $userid], $content);
        }
        return $itemid;
    };
    $att = '\local_grupomakro_core\local\support\attachments';

    // Type and content validation (explicit limits: no config changes).
    $lim = ['types' => ['jpg', 'png', 'mp4', 'mov', 'pdf'], 'maxfiles' => 2, 'imagebytes' => 1000, 'videobytes' => 5000,
        'serverbytes' => 0, 'accept' => ''];
    $ok = true;
    try {
        $att::validate('foto.png', strlen($png), substr($png, 0, 32), $lim);
        $att::validate('video.mp4', strlen($mp4), substr($mp4, 0, 32), $lim);
        $att::validate('doc.pdf', strlen($pdf), substr($pdf, 0, 32), $lim);
    } catch (\moodle_exception $e) {
        $ok = false;
    }
    $check('adjuntos: png, mp4 y pdf validos por su contenido', $ok);
    $check('adjuntos: extension no permitida (exe)', $throws(function() use ($att, $lim) {
        $att::validate('virus.exe', 10, 'MZ' . str_repeat("\0", 30), $lim);
    }, 'tkt_err_attachtype'));
    $check('adjuntos: un texto renombrado a .png se rechaza', $throws(function() use ($att, $lim) {
        $att::validate('falso.png', 10, 'hola mundo, no soy imagen', $lim);
    }, 'tkt_err_attachcontent'));
    $check('adjuntos: tipo no habilitado aunque sea imagen (webp fuera de la lista)', $throws(function() use ($att, $lim) {
        $att::validate('a.webp', 10, 'RIFF....WEBPVP8 ', $lim);
    }, 'tkt_err_attachtype'));
    $check('adjuntos: imagen mas grande que el maximo', $throws(function() use ($att, $lim, $png) {
        $att::validate('grande.png', 2000, substr($png, 0, 32), $lim);
    }, 'tkt_err_attachsize'));
    $check('adjuntos: video mas grande que el maximo', $throws(function() use ($att, $lim, $mp4) {
        $att::validate('largo.mp4', 6000, substr($mp4, 0, 32), $lim);
    }, 'tkt_err_attachsize'));
    $three = $mkdraft($studentid, ['a.png' => $png, 'b.png' => $png, 'c.png' => $png]);
    $check('adjuntos: mas archivos que el maximo por mensaje', $throws(function() use ($att, $lim, $three, $studentid) {
        $att::check_set($att::draft_files($studentid, $three), $lim);
    }, 'tkt_err_attachcount'));
    $check('adjuntos: sniff de mov, webm, gif, webp, heic y jpg',
        $att::sniff("\x00\x00\x00\x14ftypqt  ") === 'mov' && $att::sniff("\x1A\x45\xDF\xA3abc") === 'webm'
        && $att::sniff('GIF89a') === 'gif' && $att::sniff('RIFF1234WEBP') === 'webp'
        && $att::sniff("\x00\x00\x00\x18ftypheic") === 'heic' && $att::sniff("\xFF\xD8\xFF\xE0") === 'jpg');

    $real = $att::limits();
    $chat = isset($t3) ? $DB->get_record('gmk_tkt_ticket', ['id' => $t3->id]) : null;
    if ($real['maxfiles'] >= 2 && in_array('png', $real['types'], true) && in_array('mp4', $real['types'], true)
            && $chat && manager::is_open_status((string)$chat->status)) {
        // Student sends an image and a video without text.
        $item = $mkdraft($studentid, ['captura.png' => $png, 'pantalla.mp4' => $mp4]);
        [$m1] = manager::send((int)$chat->id, $studentid, '', false, false, $item);
        $files = $att::for_messages([(int)$m1->id])[(int)$m1->id] ?? [];
        $kinds = array_column($files, 'kind');
        sort($kinds);
        $check('adjuntos: el estudiante envia imagen y video sin texto', (int)$m1->hasattachments === 2
            && $kinds === ['image', 'video'] && !$att::draft_files($studentid, $item), implode(',', $kinds));
        $exported = manager::messages($chat, $studentid, manager::ROLE_REQUESTER)['messages'];
        $last = end($exported);
        $check('adjuntos: el chat los exporta con enlace a support_file.php', count($last['attachments']) === 2
            && strpos($last['attachments'][0]['url'], 'support_file.php?id=') !== false);
        $check('adjuntos: avisos y listas dicen "🎬 Video" / "📎 Adjunto", nunca el archivo',
            $att::marker((int)$m1->id) === '🎬 Video'
            && manager::export_ticket($chat, (int)$chat->assignedto ?: $agents[0], manager::ROLE_AGENT)['lastmessage'] === '📎 Adjunto');

        $fileid = (int)$files[0]['id'];
        $staffer = (int)$chat->assignedto ?: $agents[0];
        $check('adjuntos: lo descarga el solicitante', $att::file_for($fileid, $studentid) !== null);
        $check('adjuntos: lo descarga el agente', $att::file_for($fileid, $staffer) !== null, $fullname($staffer));
        if (isset($other) && $other && !manager::profile($other)->isstaff) {
            $check('adjuntos: otro usuario NO lo descarga', $att::file_for($fileid, $other) === null, $fullname($other));
        }
        // Internal note with a file: the requester never gets it.
        $noteitem = $mkdraft($staffer, ['interna.pdf' => $pdf]);
        [$m2] = manager::send((int)$chat->id, $staffer, 'Nota con adjunto', true, false, $noteitem);
        $notefile = (int)(($att::for_messages([(int)$m2->id])[(int)$m2->id] ?? [['id' => 0]])[0]['id']);
        $check('adjuntos: el de una nota interna no lo descarga el solicitante',
            $notefile > 0 && $att::file_for($notefile, $studentid) === null && $att::file_for($notefile, $staffer) !== null);
        // Someone else's draft cannot be attached.
        $foreign = $mkdraft($staffer, ['ajeno.png' => $png]);
        [$m3] = manager::send((int)$chat->id, $studentid, 'Intento con borrador ajeno', false, false, $foreign);
        $check('adjuntos: no se puede usar el borrador de otro usuario', (int)$m3->hasattachments === 0);
    } else {
        cli_writeln('  (omito el envio de adjuntos: tkt_attach_maxfiles < 2, png/mp4 no permitidos o no hay ticket abierto)');
    }
    // Confidential (Psychology): an academic agent cannot download its files.
    if (isset($t4) && !empty($t4->confidential) && $real['maxfiles'] >= 1 && in_array('png', $real['types'], true)
            && manager::is_open_status((string)$DB->get_field('gmk_tkt_ticket', 'status', ['id' => $t4->id]))) {
        $psyitem = $mkdraft($studentid, ['personal.png' => $png]);
        [$m4] = manager::send((int)$t4->id, $studentid, 'Adjunto confidencial', false, false, $psyitem);
        $psyfile = (int)(($att::for_messages([(int)$m4->id])[(int)$m4->id] ?? [['id' => 0]])[0]['id']);
        foreach ($agents as $a) {
            if (!manager::staff_can_view($t4, $a)) {
                $check('adjuntos: Psicologia confidencial no la descarga ' . $fullname($a), $att::file_for($psyfile, $a) === null);
                break;
            }
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
