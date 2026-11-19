<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Cartas (20261001130): prueba de punta a punta SIN dejar rastro.
 *
 *   php letters_selftest.php --student=<id de un estudiante de prueba> [--staff=<id del equipo de cartas>]
 *
 * Todo corre dentro de UNA transaccion que al final se revierte siempre
 * (solicitudes, eventos, documentos, tickets, tipos de prueba, horario del
 * area). Las notificaciones que Moodle envia dentro de una transaccion quedan
 * en cola hasta el commit, asi que al revertir no sale ningun aviso. No llama a
 * Odoo: el pago se prueba con el webhook y una factura ficticia; la copia a
 * Odoo queda como tarea en segundo plano, que tambien se revierte.
 *
 * Recorre: alta con responsable y plazo, duplicada rechazada, "Hablar de esta
 * carta" (ticket ligado, asignado al responsable, reutilizado), "Escribir al
 * estudiante", carta en la bandeja de tickets, bandeja de cartas (pestanas,
 * detalle, acciones validas), niveles del ANS sin duplicar (horario 24/7
 * temporal), carta cargada (PDF; Word rechazado si no es PDF/Word), lista para
 * retirar y entregada (ticket resuelto con mensaje automatico), "ya se
 * gestiono" de las anteriores, anulacion por el estudiante, recordatorio y
 * anulacion por falta de pago, pago tardio que reactiva, metricas del
 * dashboard, accion masiva simulada y limpieza de duplicadas.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_grupomakro_core\local\letters\desk;
use local_grupomakro_core\local\letters\manager;
use local_grupomakro_core\local\letters\metrics;
use local_grupomakro_core\local\letters\setup;
use local_grupomakro_core\local\letters\sla;
use local_grupomakro_core\local\letters\team;
use local_grupomakro_core\local\letters\tickets;
use local_grupomakro_core\local\support\business_hours;
use local_grupomakro_core\local\support\manager as support;
use local_grupomakro_core\local\support\policy;

list($options, $unrecognized) = cli_get_params(['student' => 0, 'staff' => 0, 'help' => false], ['h' => 'help']);
if ($options['help'] || (int)$options['student'] <= 0) {
    cli_writeln("Uso: php letters_selftest.php --student=<userid de un estudiante de prueba> [--staff=<userid>]\n"
        . "Corre el flujo completo de cartas dentro de una transaccion y la revierte.");
    exit(0);
}
$studentid = (int)$options['student'];
if (!$DB->record_exists('user', ['id' => $studentid, 'deleted' => 0])) {
    cli_error('El estudiante no existe.');
}
if (!$DB->get_manager()->field_exists('gmk_letter_request', 'assignedto')) {
    cli_error('Falta el paso 20261001130: corre primero admin/cli/upgrade.php.');
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
$get = function(int $id) use ($DB) {
    return $DB->get_record('gmk_letter_request', ['id' => $id], '*', MUST_EXIST);
};
$snapshot = function() use ($DB, $studentid) {
    return [
        'requests' => $DB->count_records('gmk_letter_request'),
        'tickets' => $DB->count_records('gmk_tkt_ticket'),
        'student_requests' => $DB->count_records('gmk_letter_request', ['userid' => $studentid]),
    ];
};
$before = $snapshot();

cli_writeln('Cartas - prueba con rollback. Estudiante: ' . $studentid);
$transaction = $DB->start_delegated_transaction();
try {
    $now = time();
    // 0) Tema y equipo (si el upgrade aun no los dejo, se crean aqui y se revierten).
    if (!team::category()) {
        setup::seed($now);
    }
    $check('existe el tema "Cartas y constancias"', team::category() !== null);
    $staffid = (int)$options['staff'] ?: (team::agents()[0] ?? 0);
    if ($staffid <= 0) {
        throw new \coding_exception('No hay nadie en el equipo de cartas: pasa --staff=<id>.');
    }
    $check('el personal de prueba gestiona cartas',
        has_capability('local/grupomakro_core:managerequests', context_system::instance(), $staffid), (string)$staffid);
    if (!in_array($staffid, team::agents(), true)) {
        $DB->insert_record('gmk_tkt_agent', (object)['categoryid' => (int)team::category()->id, 'userid' => $staffid,
            'can_auto_assign' => 1, 'max_open' => 0, 'timecreated' => $now, 'timemodified' => $now]);
    }
    // Solo este agente disponible (reparto determinista) y horario 24/7 (reloj = pared).
    foreach (team::agents() as $uid) {
        if ($uid !== $staffid) {
            support::set_availability($uid, false, $uid);
        }
    }
    support::set_availability($staffid, true, $staffid);
    $DB->set_field('gmk_tkt_area', 'hours', '', ['id' => (int)team::category()->areaid]);
    support::reset_caches();
    policy::reset_caches();
    business_hours::reset_cache();
    team::reset_caches();

    // Tipos de prueba (fisica manual sin costo; digital automatica con costo).
    $mk = function(string $code, string $delivery, string $gen, float $cost) use ($DB, $now) {
        return (int)$DB->insert_record('gmk_letter_type', (object)['code' => $code, 'name' => 'Prueba ' . $code,
            'warningtext' => '', 'cost' => $cost, 'active' => 1, 'deliverymode' => $delivery, 'generationmode' => $gen,
            'autostamp' => 0, 'autosignature' => 0, 'stampimageurl' => '', 'signatureimageurl' => '', 'odoo_product_id' => 0,
            'template_html' => '<p>{{student.fullname}}</p>', 'sla_json' => null,
            'pickup_note' => 'Secretaría, 8:00 a 17:00', 'usermodified' => 0, 'timecreated' => $now, 'timemodified' => $now]);
    };
    $tm = $mk('selftest_fisica_' . $now, 'fisica', 'manual', 0);
    $td = $mk('selftest_digital_' . $now, 'digital', 'auto', 10);

    // Punto de partida: en una base real el personal ya tiene cartas (p. ej. las 14
    // anteriores repartidas el 19-nov). Todo conteo se mide relativo a esto.
    $ownercritical = function() use ($staffid, $now) {
        foreach (metrics::pending_by_owner([], $now) as $o) {
            if ($o['userid'] === $staffid) {
                return (int)$o['critical'];
            }
        }
        return 0;
    };
    $base = [
        'desk' => desk::listing($staffid, 'mine', [], 0, 1)['counts'],
        'mine' => metrics::pending_for_staff($staffid, 'mine', 0)['counts'],
        'team' => metrics::pending_for_staff($staffid, 'team', 0)['counts'],
        'owner_critical' => $ownercritical(),
    ];

    // 1) Alta: responsable, plazo y aviso.
    $detail = manager::create_request($studentid, $tm, 'Prueba automática');
    $r1 = $get((int)$detail['id']);
    $check('carta manual queda "En gestión"', $r1->status === 'pendiente_gestion', $r1->status);
    $check('se asigna al equipo de cartas', (int)$r1->assignedto === $staffid, (string)$r1->assignedto);
    $check('tiene plazo (2 días hábiles por defecto)', (int)$r1->due_at > $now && (int)$r1->status_since >= $now,
        userdate((int)$r1->due_at));
    $check('una segunda solicitud del mismo tipo se rechaza', $throws(function() use ($studentid, $tm) {
        manager::create_request($studentid, $tm, 'otra');
    }, 'letter_err_duplicate'));

    // 2) "Hablar de esta carta" y "Escribir al estudiante".
    $tk = tickets::open_for_student((int)$r1->id, $studentid, '¿Cuándo estará lista mi carta?');
    $check('el ticket queda ligado a la carta', (int)$tk->letterrequestid === (int)$r1->id, $tk->number);
    $check('el ticket va al responsable de la carta', (int)$tk->assignedto === $staffid, (string)$tk->assignedto);
    $check('el ticket está en "Cartas y constancias"', (int)$tk->categoryid === (int)team::category()->id);
    $tk2 = tickets::open_for_student((int)$r1->id, $studentid, 'Sigo esperando');
    $check('"Hablar de esta carta" otra vez reutiliza el ticket abierto', (int)$tk2->id === (int)$tk->id);
    $check('sin mensaje y sin ticket abierto no se crea nada', $throws(function() use ($studentid, $tm, $DB) {
        $other = (int)$DB->insert_record('gmk_letter_request', (object)['userid' => $studentid, 'lettertypeid' => $tm,
            'status' => 'entregada', 'cost_snapshot' => 0, 'deliverymode_snapshot' => 'fisica',
            'generationmode_snapshot' => 'manual', 'usermodified' => 0, 'timecreated' => time(), 'timemodified' => time()]);
        tickets::open_for_student($other, $studentid, '');
    }, 'tkt_err_empty'));
    $otheruser = (int)$DB->get_field_sql("SELECT MIN(id) FROM {user} WHERE id <> :a AND id <> :b AND deleted = 0 AND id > 2",
        ['a' => $studentid, 'b' => $staffid]);
    $check('otro usuario no puede abrir un ticket de esta carta', $throws(function() use ($r1, $otheruser) {
        tickets::open_for_student((int)$r1->id, $otheruser, 'hola');
    }, 'nopermissions'));
    $t3 = tickets::write_as_staff((int)$r1->id, $staffid, 'Hola, la estamos preparando.');
    $check('"Escribir al estudiante" escribe en el mismo ticket', (int)$t3->id === (int)$tk->id);
    $exp = support::export_ticket($tk, $staffid, support::ROLE_AGENT);
    $check('la bandeja de tickets muestra la carta ligada', (int)($exp['letter']['id'] ?? 0) === (int)$r1->id,
        ($exp['letter']['statuslabel'] ?? ''));
    $exps = support::export_ticket($tk, $studentid, support::ROLE_REQUESTER);
    $check('el estudiante ve el estado de su carta en palabras', ($exps['letter']['statuslabel'] ?? '') !== ''
        && ($exps['letter']['sla_level'] ?? -1) === 0, $exps['letter']['statuslabel'] ?? '');

    // 3) Bandeja de cartas.
    $list = desk::listing($staffid, 'mine', ['search' => '#' . $r1->id]);
    $check('la carta aparece en "Mías" y el contador sube en 1',
        in_array((int)$r1->id, array_column($list['items'], 'id'), true)
        && $list['counts']['mine'] === $base['desk']['mine'] + 1,
        'mías ' . $base['desk']['mine'] . ' -> ' . $list['counts']['mine']);
    $row = $list['items'][array_search((int)$r1->id, array_column($list['items'], 'id'))];
    $check('la bandeja muestra el ticket ligado', (int)$row['ticketid'] === (int)$tk->id);
    $check('acciones válidas: cargar, lista para retirar, entregada, rechazar; no "eliminar" salvo admin',
        in_array('upload', $row['actions'], true) && in_array('ready_pickup', $row['actions'], true)
        && in_array('reject', $row['actions'], true) && (in_array('delete', $row['actions'], true) === is_siteadmin($staffid)),
        implode(',', $row['actions']));
    $found = desk::listing($staffid, 'all', ['search' => '#' . $r1->id]);
    $check('búsqueda por #número', count($found['items']) === 1);
    $det = desk::detail((int)$r1->id, $staffid);
    $check('el detalle trae historial', count($det['history']) >= 3, count($det['history']) . ' eventos');
    $check('el estudiante no entra a la bandeja', $throws(function() use ($studentid) {
        desk::listing($studentid, 'mine');
    }) || has_capability('local/grupomakro_core:managerequests', context_system::instance(), $studentid));
    $check('rechazar sin motivo no se permite', $throws(function() use ($r1, $staffid) {
        desk::action((int)$r1->id, $staffid, 'reject', ['reason' => '']);
    }, 'letter_err_reason'));
    desk::action((int)$r1->id, $staffid, 'note', ['note' => 'Nota de prueba']);
    $check('nota interna en el historial', $DB->record_exists('gmk_letter_request_event',
        ['requestid' => (int)$r1->id, 'eventtype' => 'note']));
    $sim = desk::bulk([(int)$r1->id], $staffid, 'delivered', [], true);
    $check('acción masiva simulada no cambia nada', $sim['done'] === [(int)$r1->id] && $get((int)$r1->id)->status
        === 'pendiente_gestion');

    // 4) ANS: niveles una vez por estado.
    $DB->set_field('gmk_letter_request', 'status_since', $now - 1200 * MINSECS, ['id' => (int)$r1->id]);
    $s1 = sla::run($now, [(int)$r1->id]);
    $check('77 % del plazo: "por vencer"', (int)$get((int)$r1->id)->sla_level === 1, json_encode($s1));
    $DB->set_field('gmk_letter_request', 'status_since', $now - 2000 * MINSECS, ['id' => (int)$r1->id]);
    $s2 = sla::run($now, [(int)$r1->id]);
    $check('128 % del plazo: "vencida" y una alerta', (int)$get((int)$r1->id)->sla_level === 2 && $s2['raised'] === 1
        && $s2['alerts'] >= 1, json_encode($s2));
    $s3 = sla::run($now, [(int)$r1->id]);
    $check('otra pasada no repite la alerta', $s3['raised'] === 0 && $s3['alerts'] === 0);
    $DB->set_field('gmk_letter_request', 'status_since', $now - 3200 * MINSECS, ['id' => (int)$r1->id]);
    $s4 = sla::run($now, [(int)$r1->id]);
    $check('205 % del plazo: "crítica" (una vez)', (int)$get((int)$r1->id)->sla_level === 3 && $s4['raised'] === 1);
    // Sin tope de items: con cartas reales criticas la de prueba puede no estar entre las 3 primeras.
    $pf = metrics::pending_for_staff($staffid, 'mine', 1000);
    $check('dashboard: "mías" suma 1 crítica y la incluye',
        $pf['counts']['critical'] === $base['mine']['critical'] + 1
        && $pf['counts']['total'] === $base['mine']['total'] + 1
        && in_array('CARTA-' . $r1->id, array_column($pf['items'], 'ref'), true),
        json_encode($base['mine']) . ' -> ' . json_encode($pf['counts']));
    $team = metrics::pending_for_staff($staffid, 'team', 0);
    $check('dashboard: "equipo" suma 1 y cuenta al menos las mías',
        $team['counts']['total'] === $base['team']['total'] + 1 && $team['counts']['total'] >= $pf['counts']['total'],
        $base['team']['total'] . ' -> ' . $team['counts']['total']);
    $check('dashboard: el estudiante no ve el equipo', metrics::pending_for_staff($studentid, 'team')['counts']['total'] === 0
        || has_capability('local/grupomakro_core:managerequests', context_system::instance(), $studentid));
    $check('pendientes por responsable: suma 1 crítica', $ownercritical() === $base['owner_critical'] + 1,
        $base['owner_critical'] . ' -> ' . $ownercritical());

    // 5) Carta cargada: PDF ok, otro tipo no; lista para retirar reinicia el plazo.
    $check('un archivo que no es PDF/Word se rechaza', $throws(function() use ($r1, $staffid) {
        manager::upload_document((int)$r1->id, $staffid, 'virus.exe', 'MZ', 'pickup');
    }, 'letter_err_filetype'));
    manager::upload_document((int)$r1->id, $staffid, 'Carta firmada.pdf', "%PDF-1.4\n% selftest\n", 'pickup');
    $r1 = $get((int)$r1->id);
    $doc = manager::get_latest_document((int)$r1->id);
    $check('cargada la carta: lista para retirar', $r1->status === 'pendiente_recoleccion', $r1->status);
    $check('el documento queda como "cargado" en PDF', $doc && $doc->source === 'uploaded'
        && $doc->mimetype === 'application/pdf', $doc ? $doc->filename : '');
    $check('el nuevo estado reinicia el ANS', (int)$r1->sla_level === 0 && (int)$r1->due_at > $now);
    $check('la copia a Odoo queda en segundo plano', $DB->record_exists_select('task_adhoc', 'classname = ?',
        ['\\local_grupomakro_core\\task\\letters_odoo_copy']));
    $msgs = $DB->get_records('gmk_tkt_message', ['ticketid' => (int)$tk->id], 'id');
    $last = end($msgs);
    $check('mensaje automático en el ticket: lista para retirar', $last && $last->senderrole === 'system'
        && strpos((string)$last->body, 'retirar') !== false, $last ? \core_text::substr($last->body, 0, 80) : '');
    $payload = manager::download_document_payload((int)$r1->id, $studentid, false);
    $check('el estudiante descarga su carta', base64_decode($payload['contentbase64']) === "%PDF-1.4\n% selftest\n");
    desk::action((int)$r1->id, $staffid, 'delivered', ['reason' => 'Recibida por el estudiante']);
    $check('entregada', $get((int)$r1->id)->status === 'entregada');
    $check('el ticket pasa a resuelto', $DB->get_field('gmk_tkt_ticket', 'status', ['id' => (int)$tk->id])
        === support::S_RESOLVED);

    // 6) Anteriores a la bandeja: "Sí, ya se entregó".
    $legacy = (int)$DB->insert_record('gmk_letter_request', (object)['userid' => $studentid, 'lettertypeid' => $td,
        'status' => 'pendiente_gestion', 'cost_snapshot' => 0, 'deliverymode_snapshot' => 'fisica',
        'generationmode_snapshot' => 'manual', 'legacy' => 1, 'status_since' => $now - 90 * DAYSECS,
        'usermodified' => 0, 'timecreated' => $now - 90 * DAYSECS, 'timemodified' => $now - 90 * DAYSECS]);
    $s5 = sla::run($now, [$legacy]);
    $check('una anterior sin responsable se asigna y alerta como crítica', (int)$get($legacy)->assignedto === $staffid
        && (int)$get($legacy)->sla_level === 3, json_encode($s5));
    $leg = desk::listing($staffid, 'legacy', ['search' => '#' . $legacy]);
    $check('aparece en la pestaña "Anteriores" y el contador sube en 1',
        in_array($legacy, array_column($leg['items'], 'id'), true)
        && $leg['counts']['legacy'] === $base['desk']['legacy'] + 1,
        'anteriores ' . $base['desk']['legacy'] . ' -> ' . $leg['counts']['legacy']);
    desk::action($legacy, $staffid, 'handled');
    $check('"Sí, ya se entregó" la cierra sin documento', $get($legacy)->status === 'entregada'
        && (int)$get($legacy)->legacy === 0);

    // 7) El estudiante anula la suya.
    $d2 = manager::create_request($studentid, $tm, '');
    $check('puede anular mientras nadie la trabaja', manager::student_cancel((int)$d2['id'], $studentid)->status === 'cancelada');
    $check('otro no puede anularla', $throws(function() use ($d2, $otheruser) {
        manager::student_cancel((int)$d2['id'], $otheruser);
    }, 'nopermissions'));

    // 8) Pago: recordatorio, anulacion a los 15 dias y pago tardio que reactiva.
    $pay = (int)$DB->insert_record('gmk_letter_request', (object)['userid' => $studentid, 'lettertypeid' => $td,
        'status' => 'pendiente_pago', 'cost_snapshot' => 10, 'deliverymode_snapshot' => 'digital',
        'generationmode_snapshot' => 'auto', 'invoice_id' => 'SELFTEST-' . $now, 'payment_link' => 'https://example.invalid/pay',
        'status_since' => $now - 4 * DAYSECS, 'usermodified' => 0, 'timecreated' => $now - 4 * DAYSECS,
        'timemodified' => $now - 4 * DAYSECS]);
    $s6 = sla::run($now, [$pay]);
    $check('a los 3 días: recordatorio de pago (una vez)', $s6['payreminders'] === 1
        && ((int)$get($pay)->reminders & sla::REMINDED_PAYMENT) && sla::run($now, [$pay])['payreminders'] === 0);
    $DB->set_field('gmk_letter_request', 'status_since', $now - 16 * DAYSECS, ['id' => $pay]);
    $s7 = sla::run($now, [$pay]);
    $check('a los 15 días sin pago: anulada', $s7['cancelled'] === 1 && $get($pay)->status === 'cancelada');
    $wh = manager::handle_payment_webhook(['external_request_id' => (string)$pay, 'invoice_id' => 'SELFTEST-' . $now,
        'payment_state' => 'paid']);
    $check('si paga después, se reactiva y se genera', !empty($wh['success']) && $get($pay)->status === 'generada_digital',
        json_encode($wh));
    $wh2 = manager::handle_payment_webhook(['external_request_id' => (string)$pay, 'payment_state' => 'paid']);
    $check('el webhook repetido es idempotente', !empty($wh2['idempotent']));

    // 9) Duplicadas: se conserva la mas reciente, las demas se anulan en silencio.
    $dupa = (int)$DB->insert_record('gmk_letter_request', (object)['userid' => $studentid, 'lettertypeid' => $tm,
        'status' => 'pendiente_gestion', 'cost_snapshot' => 0, 'deliverymode_snapshot' => 'fisica',
        'generationmode_snapshot' => 'manual', 'usermodified' => 0, 'timecreated' => $now - 3 * DAYSECS,
        'timemodified' => $now - 3 * DAYSECS]);
    $dupb = (int)$DB->insert_record('gmk_letter_request', (object)['userid' => $studentid, 'lettertypeid' => $tm,
        'status' => 'pendiente_gestion', 'cost_snapshot' => 0, 'deliverymode_snapshot' => 'fisica',
        'generationmode_snapshot' => 'manual', 'usermodified' => 0, 'timecreated' => $now - DAYSECS,
        'timemodified' => $now - DAYSECS]);
    $groups = array_values(array_filter(setup::duplicates(), function($g) use ($dupb) {
        return $g['keep'] === $dupb;
    }));
    $check('detecta la duplicada y conserva la más reciente', $groups && in_array($dupa, $groups[0]['cancel'], true));
    $simn = setup::dedupe(false);
    $check('la simulación no cambia nada', $simn >= 1 && $get($dupa)->status === 'pendiente_gestion');
    setup::dedupe(true);
    $ev = $DB->get_record_sql("SELECT * FROM {gmk_letter_request_event} WHERE requestid = ? AND newstatus = 'cancelada'",
        [$dupa], IGNORE_MULTIPLE);
    $check('anulada con motivo y estado anterior guardado (reversible)', $get($dupa)->status === 'cancelada'
        && $get($dupb)->status === 'pendiente_gestion' && $ev && $ev->oldstatus === 'pendiente_gestion');

    // 10) Resumen por tipo.
    $sum = metrics::summary($now - 120 * DAYSECS, $now + DAYSECS);
    $mrow = array_values(array_filter($sum, function($s) use ($tm) {
        return $s['lettertypeid'] === $tm;
    }));
    $check('resumen por tipo (creadas, terminadas, ciclo)', $mrow && $mrow[0]['created'] >= 2 && $mrow[0]['finished'] >= 1,
        json_encode($mrow[0] ?? []));
} catch (\Throwable $e) {
    $check('excepcion inesperada', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':'
        . $e->getLine());
}

try {
    $transaction->rollback(new \moodle_exception('letters_selftest_rollback'));
} catch (\moodle_exception $e) {
    // Expected: rollback() rethrows.
}
policy::reset_caches();
business_hours::reset_cache();
team::reset_caches();

$after = $snapshot();
$failed = count(array_filter($results, function($r) {
    return !$r[0];
}));
cli_writeln('');
cli_writeln(sprintf('%d pruebas, %d fallas. Revertido: %s', count($results), $failed,
    $before === $after ? 'sí (mismos conteos antes y después)' : 'NO: ' . json_encode([$before, $after])));
exit($failed || $before !== $after ? 1 : 0);
