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
 * Mesa de ayuda: prueba de los flujos de conversación autónomos, tal como los
 * recorre un estudiante o un docente, SIN dejar rastro.
 *
 *   php support_flows_selftest.php --student=<id> [--teacher=<id>] [--check-links]
 *
 * Todo corre dentro de UNA transacción que al final se revierte siempre
 * (tickets, mensajes, eventos, contadores de problemas comunes, estado
 * financiero de prueba). Las notificaciones quedan en cola hasta el commit, así
 * que no sale ningún correo ni campanita.
 *
 * Flujos:
 *  A. "No me puedo conectar a mi clase virtual" (Chrome): el catálogo de
 *     Soporte Tecnológico lo trae primero, con la explicación y los 3 botones de
 *     descarga (App Store, Play Store, computadora), solo https.
 *     A1 "Sí, se solucionó": cuenta y NO abre solicitud.
 *     A2 "No, sigo con el problema" + captura: abre la solicitud en TI, asignada a
 *        un agente de TI, con el aviso automático al estudiante, la captura y la
 *        nota interna "Ya probó la solución sugerida" (que el estudiante no ve).
 *     A3 Cancelar: se puede mientras nadie respondió; después de la respuesta
 *        del agente ya no. "Resuelto" -> "Sí" cierra.
 *  B. Docentes: ven la guía de Chrome (pública) y la suya; no las de estudiantes.
 *  C. Mora (20261001132, caso TKT-2026-0007): con el estado financiero en mora,
 *     el catálogo trae el botón de Cobros (WhatsApp con nombre y cédula), y si
 *     abre igual la solicitud recibe el aviso con el enlace, el agente la nota
 *     interna y queda el evento. Al día, apagado o docente: nada de eso.
 *  --check-links: además consulta por HTTP cada enlace de los problemas comunes
 *     activos y el WhatsApp de Finanzas (fuera de la transacción; solo lee).
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/filelib.php');

use local_grupomakro_core\local\support\business_hours;
use local_grupomakro_core\local\support\config;
use local_grupomakro_core\local\support\faq;
use local_grupomakro_core\local\support\manager;
use local_grupomakro_core\local\support\restriction;

list($options, $unrecognized) = cli_get_params(
    ['student' => 0, 'teacher' => 0, 'check-links' => false, 'help' => false], ['h' => 'help']);
if ($options['help'] || (int)$options['student'] <= 0) {
    cli_writeln("Uso: php support_flows_selftest.php --student=<id> [--teacher=<id>] [--check-links]\n"
        . "Recorre los flujos autónomos de la mesa de ayuda dentro de una transacción y la revierte.");
    exit(0);
}
$studentid = (int)$options['student'];
$teacherid = (int)$options['teacher'];
if (!$DB->record_exists('user', ['id' => $studentid, 'deleted' => 0])) {
    cli_error('El estudiante no existe.');
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
$areaof = function(array $catalog, string $code) {
    foreach ($catalog as $a) {
        if ($a['code'] === $code) {
            return $a;
        }
    }
    return null;
};
$chromeof = function(?array $area) {
    foreach ($area['faqs'] ?? [] as $f) {
        foreach ($f['links'] as $l) {
            if (stripos($l['url'], 'chrome') !== false) {
                return $f;
            }
        }
    }
    return null;
};
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
$mkdraft = function(int $userid, string $name, string $content) use ($DB) {
    // Not file_get_unused_draft_itemid(): it needs a logged-in $USER.
    $ctx = \context_user::instance($userid);
    do {
        $itemid = rand(1, 999999999);
    } while ($DB->record_exists('files', ['contextid' => $ctx->id, 'component' => 'user', 'filearea' => 'draft',
        'itemid' => $itemid]));
    get_file_storage()->create_file_from_string(['contextid' => $ctx->id, 'component' => 'user', 'filearea' => 'draft',
        'itemid' => $itemid, 'filepath' => '/', 'filename' => $name, 'userid' => $userid], $content);
    return $itemid;
};
// Room for one more ticket: the tests close what they open, but the student may
// already have open ones (the limits are per student).
$room = function() use ($DB, $studentid) {
    $open = $DB->count_records_select('gmk_tkt_ticket', 'userid = ? AND status NOT IN (?, ?)',
        [$studentid, 'cerrado', 'cancelado']);
    $today = $DB->count_records_select('gmk_tkt_ticket', 'userid = ? AND timecreated > ?', [$studentid, time() - DAYSECS]);
    return $open < config::get_int('tkt_max_open') && $today < config::get_int('tkt_max_daily');
};
$publicbodies = function(stdClass $t) use ($studentid) {
    return array_column(manager::messages($t, $studentid, manager::ROLE_REQUESTER)['messages'], 'body');
};

cli_writeln('Mesa de ayuda - flujos autónomos (rollback). Estudiante: ' . $fullname($studentid)
    . ($teacherid ? '; docente: ' . $fullname($teacherid) : ''));
$before = $DB->count_records('gmk_tkt_ticket', ['userid' => $studentid]);
$faqbefore = $DB->get_records_menu('gmk_tkt_faq', null, 'id', "id, views + solved + notsolved AS c");
$moraoriginal = config::get_int('tkt_mora_redirect');

$transaction = $DB->start_delegated_transaction();
try {
    // Same starting point for every run: the student is up to date.
    $DB->delete_records('gmk_financial_status', ['userid' => $studentid]);
    $now = time();
    $DB->insert_record('gmk_financial_status', (object)['userid' => $studentid, 'status' => 'al_dia', 'reason' => 'al_dia',
        'json_data' => '{"allowed":true,"reason":"al_dia"}', 'lastupdated' => $now, 'timemodified' => $now]);
    set_config('tkt_mora_redirect', 1, 'local_grupomakro_core');

    // ------------------------------------------------------------------
    cli_writeln('');
    cli_writeln('A. "No me puedo conectar a mi clase virtual" (Chrome) - estudiante');
    $catalog = manager::catalog($studentid, 'student');
    $ti = $areaof($catalog, 'ti');
    $check('Soporte Tecnológico aparece en Ayuda', $ti !== null && !$ti['external']);
    $chrome = $chromeof($ti);
    $check('trae el problema común de Chrome', $chrome !== null, $chrome['problem'] ?? '');
    if (!$chrome) {
        throw new \coding_exception('Sin el problema común de Chrome no se puede seguir el flujo A.');
    }
    $check('Chrome es el primero de la lista', ($ti['faqs'][0]['id'] ?? 0) === $chrome['id']);
    $kinds = array_column($chrome['links'], 'kind');
    sort($kinds);
    $check('3 botones: App Store, Play Store y computadora', $kinds === ['appstore', 'playstore', 'web'],
        implode(' | ', array_column($chrome['links'], 'label')));
    $check('todos los enlaces son https', count(array_filter($chrome['links'], function($l) {
        return strpos($l['url'], 'https://') === 0;
    })) === count($chrome['links']));
    $check('la explicación menciona Google Chrome y trae pasos numerados',
        stripos($chrome['answerhtml'], 'Chrome') !== false && strpos($chrome['answerhtml'], '<ol>') !== false);
    $check('la explicación es HTML seguro (sin <script> ni atributos)',
        !preg_match('/<script|<a |on\w+=|javascript:/i', $chrome['answerhtml']));
    $check('el estudiante no ve guías solo para docentes', !array_filter($ti['faqs'], function($f) use ($DB) {
        return $DB->get_field('gmk_tkt_faq', 'audience', ['id' => $f['id']]) === 'teacher';
    }));

    // A1. "Sí, se solucionó": counts, no ticket.
    $c0 = $DB->get_record('gmk_tkt_faq', ['id' => $chrome['id']]);
    faq::record($chrome['id'], 'view', $studentid, 'student');
    faq::record($chrome['id'], 'solved', $studentid, 'student');
    $c1 = $DB->get_record('gmk_tkt_faq', ['id' => $chrome['id']]);
    $check('A1 "Sí, se solucionó": suma una vista y una solución', (int)$c1->views === (int)$c0->views + 1
        && (int)$c1->solved === (int)$c0->solved + 1 && (int)$c1->notsolved === (int)$c0->notsolved);
    $check('A1 no abre ninguna solicitud', $DB->count_records('gmk_tkt_ticket', ['userid' => $studentid]) === $before);
    $check('A1 queda el evento faq_solved (para el informe semanal)', $DB->record_exists_select('gmk_tkt_event',
        'ticketid = 0 AND eventtype = ? AND actorid = ? AND ' . $DB->sql_compare_text('newvalue') . ' = ?',
        ['faq_solved', $studentid, (string)$chrome['id']]));

    // A2. "No, sigo con el problema" + screenshot.
    if (!$room()) {
        cli_writeln('  (omito A2/A3/C: el estudiante llegó al máximo de solicitudes abiertas o del día)');
    } else {
        faq::record($chrome['id'], 'notsolved', $studentid, 'student');
        $topic = (int)($chrome['categoryid'] ?: $ti['topics'][0]['id']);
        $draft = $mkdraft($studentid, 'captura.png', $png);
        $t = manager::create($studentid, $topic, '', 'Sigo sin poder entrar a la clase', 0, 'student', $draft, $chrome['id']);
        $tiagents = array_map('intval', $DB->get_fieldset_select('gmk_tkt_agent', 'userid', 'categoryid = ?', [$topic]));
        $check('A2 abre la solicitud en Soporte Tecnológico', (int)$t->areaid === (int)$ti['id'], $t->number);
        $check('A2 queda asignada a un agente de TI', in_array((int)$t->assignedto, $tiagents, true),
            $fullname((int)$t->assignedto));
        $pub = manager::messages($t, $studentid, manager::ROLE_REQUESTER)['messages'];
        $first = $pub[0] ?? [];
        $check('A2 la captura llega con el primer mensaje', !empty($first['attachments'])
            && $first['attachments'][0]['filename'] === 'captura.png');
        $auto = array_values(array_filter($pub, function($m) {
            return $m['senderrole'] === 'system';
        }));
        $agentname = $DB->get_record('user', ['id' => (int)$t->assignedto]);
        $check('A2 aviso automático con número y agente', $auto && strpos($auto[0]['body'], $t->number) !== false
            && $agentname && strpos($auto[0]['body'], fullname($agentname)) !== false, $auto[0]['body'] ?? '');
        $tiarea = $DB->get_record('gmk_tkt_area', ['id' => (int)$t->areaid]);
        $offhours = !business_hours::is_open($tiarea, (int)$t->timecreated);
        $hasoff = (bool)array_filter($auto, function($m) {
            return stripos($m['body'], 'fuera del horario') !== false;
        });
        $check('A2 aviso de fuera de horario solo cuando corresponde', $hasoff === $offhours,
            $offhours ? 'fuera de horario' : 'en horario');
        $notes = $DB->get_records('gmk_tkt_message', ['ticketid' => $t->id, 'internal' => 1]);
        $note = $notes ? reset($notes) : null;
        $check('A2 nota interna para el agente: "Ya probó la solución sugerida"', $note
            && strpos($note->body, 'Ya probó la solución sugerida: ' . $chrome['problem']) === 0);
        $check('A2 esa nota no la ve el estudiante', !in_array((int)($note->id ?? 0), array_column($pub, 'id'), true));
        $check('A2 el agente sí la ve', in_array((int)($note->id ?? 0), array_column(
            manager::messages($t, (int)$t->assignedto, manager::ROLE_AGENT)['messages'], 'id'), true));
        $check('A2 sin mora: no hay aviso de Cobros', !$DB->record_exists('gmk_tkt_event',
            ['ticketid' => $t->id, 'eventtype' => 'financial_block']));

        // A3. Cancel only while unanswered; then answer -> resolve -> "Sí" closes.
        $exp = manager::export_ticket($DB->get_record('gmk_tkt_ticket', ['id' => $t->id]), $studentid, manager::ROLE_REQUESTER);
        $check('A3 sin respuesta: el estudiante puede cancelar', in_array('cancel', $exp['actions'], true));
        manager::send((int)$t->id, (int)$t->assignedto, 'Hola, ¿ya instalaste Chrome? Prueba de nuevo desde Chrome.');
        $exp = manager::export_ticket($DB->get_record('gmk_tkt_ticket', ['id' => $t->id]), $studentid, manager::ROLE_REQUESTER);
        $check('A3 con respuesta del agente: ya no aparece Cancelar', !in_array('cancel', $exp['actions'], true));
        $check('A3 y el servidor tampoco lo permite', $throws(function() use ($t, $studentid) {
            manager::cancel((int)$t->id, $studentid);
        }, 'tkt_err_badstatus'));
        manager::staff_action((int)$t->id, (int)$t->assignedto, 'resolve');
        $exp = manager::export_ticket($DB->get_record('gmk_tkt_ticket', ['id' => $t->id]), $studentid, manager::ROLE_REQUESTER);
        $check('A3 resuelta: el estudiante ve "¿Se resolvió?"', in_array('confirm', $exp['actions'], true));
        $closed = manager::confirm_resolution((int)$t->id, $studentid, true);
        $check('A3 "Sí" la cierra', $closed->status === manager::S_CLOSED, $closed->status);

        // Cancel right away (what happened in TKT-2026-0007).
        if ($room()) {
            $tc = manager::create($studentid, $topic, '', 'Prueba: cancelar enseguida');
            $tc = manager::cancel((int)$tc->id, $studentid);
            $ev = $DB->get_record_select('gmk_tkt_event', "ticketid = ? AND eventtype = 'status' AND "
                . $DB->sql_compare_text('newvalue') . " = 'cancelado'", [$tc->id], '*', IGNORE_MULTIPLE);
            $check('A3 cancelar enseguida: queda cancelada y el evento dice quién fue',
                $tc->status === 'cancelado' && $ev && (int)$ev->actorid === $studentid);
        }
    }

    // ------------------------------------------------------------------
    cli_writeln('');
    cli_writeln('B. Docentes');
    if ($teacherid <= 0) {
        cli_writeln('  (omitido: pasa --teacher=<id> de un docente)');
    } else if (!manager::is_teacher($teacherid)) {
        $check('el usuario de --teacher es docente', false, $fullname($teacherid));
    } else {
        $tcat = manager::catalog($teacherid, 'teacher');
        $tti = $areaof($tcat, 'ti');
        $check('el docente ve Soporte Tecnológico', $tti !== null);
        $check('el docente ve la guía de Chrome', $chromeof($tti) !== null);
        $auds = array_map(function($f) use ($DB) {
            return $DB->get_field('gmk_tkt_faq', 'audience', ['id' => $f['id']]);
        }, $tti['faqs'] ?? []);
        $check('el docente no ve guías solo para estudiantes', !in_array('student', $auds, true), implode(',', $auds));
        $check('el docente no ve Psicología ni áreas no habilitadas', $areaof($tcat, 'psicologia') === null);
        $tf = array_values(array_filter($tti['faqs'] ?? [], function($f) use ($DB) {
            return $DB->get_field('gmk_tkt_faq', 'audience', ['id' => $f['id']]) === 'teacher';
        }));
        if ($tf) {
            $check('un estudiante no puede contar la guía de docentes', $throws(function() use ($tf, $studentid) {
                faq::record($tf[0]['id'], 'view', $studentid, 'student');
            }, 'tkt_err_faq'));
        }
    }

    // ------------------------------------------------------------------
    cli_writeln('');
    cli_writeln('C. Acceso restringido por mora -> Cobros por WhatsApp (caso TKT-2026-0007)');
    $check('al día: el catálogo no trae aviso de Cobros', restriction::for_user($studentid) === null);
    $DB->set_field('gmk_financial_status', 'status', 'mora', ['userid' => $studentid]);
    $DB->set_field('gmk_financial_status', 'lastupdated', time(), ['userid' => $studentid]);
    $r = restriction::for_user($studentid);
    $user = \core_user::get_user($studentid);
    $cedula = manager::document_number($user);
    $check('en mora: el catálogo trae el botón de Cobros', $r !== null && $r['reason'] === 'mora', $r['label'] ?? '');
    $finurl = (string)$DB->get_field('gmk_tkt_area', 'external_url', ['code' => 'finanzas']);
    $check('el botón abre el WhatsApp de Finanzas', $r && $finurl !== '' && strpos($r['url'], $finurl) === 0, $finurl);
    $text = $r ? rawurldecode((string)parse_url($r['url'], PHP_URL_QUERY)) : '';
    $check('el mensaje de WhatsApp lleva nombre y cédula', strpos($text, fullname($user)) !== false
        && ($cedula === '' || strpos($text, $cedula) !== false), \core_text::substr($text, 0, 90));
    // Web service view (what the portal receives).
    $catws = manager::catalog($studentid, 'student');
    $check('Finanzas sigue disponible en Ayuda', ($fa = $areaof($catws, 'finanzas')) && $fa['external']);

    if ($room()) {
        $ticat = (int)$DB->get_field('gmk_tkt_category', 'id', ['areaid' => (int)$ti['id'], 'name' => 'Acceso y contraseña'])
            ?: (int)$ti['topics'][0]['id'];
        $draft = $mkdraft($studentid, 'IMG_3785.png', $png);
        $tm = manager::create($studentid, $ticat, 'No puedo ingresar a clases', 'Tengo clases en breve', 0, 'student', $draft);
        $pub = $publicbodies($tm);
        $notice = array_values(array_filter($pub, function($b) {
            return strpos($b, 'facturas vencidas') !== false;
        }));
        $check('si abre igual: aviso automático de mora en el chat', count($notice) === 1, \core_text::substr($notice[0] ?? '', 0, 80));
        $check('el aviso trae el enlace de WhatsApp (clicable en el portal)', $notice && strpos($notice[0], $finurl) !== false);
        $check('sin variables sin reemplazar', $notice && strpos($notice[0], '{') === false);
        $notes = array_values(array_filter($DB->get_records('gmk_tkt_message', ['ticketid' => $tm->id, 'internal' => 1]),
            function($m) {
                return strpos($m->body, 'acceso restringido por mora') !== false;
            }));
        $check('nota interna para el agente', count($notes) === 1);
        $check('el estudiante no ve la nota interna', $notes && !in_array((int)$notes[0]->id,
            array_column(manager::messages($tm, $studentid, manager::ROLE_REQUESTER)['messages'], 'id'), true));
        $check('evento financial_block', $DB->record_exists('gmk_tkt_event', ['ticketid' => $tm->id, 'eventtype' => 'financial_block']));
        $check('la solicitud sigue abierta y asignada (por si es otro tema)', manager::is_open_status($tm->status)
            && (int)$tm->assignedto > 0, $tm->status);
        $tmx = manager::export_ticket($DB->get_record('gmk_tkt_ticket', ['id' => $tm->id]), $studentid, manager::ROLE_REQUESTER);
        $check('la vista previa del estudiante no muestra la nota interna',
            strpos((string)($tmx['lastmessage'] ?? $tmx['preview'] ?? ''), 'restringido por mora') === false);
        manager::cancel((int)$tm->id, $studentid);
    } else {
        cli_writeln('  (omito la solicitud en mora: el estudiante llegó al máximo de abiertas o del día)');
    }

    set_config('tkt_mora_redirect', 0, 'local_grupomakro_core');
    $check('con el ajuste apagado: sin aviso de Cobros', restriction::for_user($studentid) === null);
    set_config('tkt_mora_redirect', 1, 'local_grupomakro_core');
    $DB->set_field('gmk_financial_status', 'status', 'sincontrato', ['userid' => $studentid]);
    $check('sin contrato (no es mora): sin aviso de Cobros', restriction::for_user($studentid) === null);
    $DB->set_field('gmk_financial_status', 'status', 'al_dia', ['userid' => $studentid]);
    $check('pagó (al día): el aviso desaparece', restriction::for_user($studentid) === null);
    $DB->delete_records('gmk_financial_status', ['userid' => $studentid]);
    $check('sin registro financiero: sin aviso', restriction::for_user($studentid) === null);
} catch (\Throwable $e) {
    $check('excepción inesperada', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':'
        . $e->getLine());
}

// Restore the setting explicitly (config cache) and then roll everything back.
set_config('tkt_mora_redirect', $moraoriginal, 'local_grupomakro_core');
try {
    $transaction->rollback(new \moodle_exception('support_flows_selftest_rollback'));
} catch (\moodle_exception $e) {
    // Expected: rollback() rethrows.
}
manager::reset_caches();
cache_helper::purge_by_event('changesinconfig');

// ----------------------------------------------------------------------
if (!empty($options['check-links'])) {
    cli_writeln('');
    cli_writeln('Enlaces (consulta HTTP, solo lectura)');
    $urls = [];
    foreach ($DB->get_records('gmk_tkt_faq', ['active' => 1], 'areaid, sortorder, id') as $f) {
        foreach (faq::links((string)$f->links) as $l) {
            $urls[$l['url']] = 'Problema común #' . $f->id . ': ' . $l['label'];
        }
    }
    $fin = (string)$DB->get_field('gmk_tkt_area', 'external_url', ['code' => 'finanzas']);
    if ($fin !== '') {
        $urls[$fin] = 'WhatsApp de Finanzas / Cobros';
    }
    foreach ($urls as $url => $label) {
        if (strpos($url, 'https://') !== 0) {
            $check($label . ' (ruta del portal, no se consulta)', true, $url);
            continue;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_NOBODY => false, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => 20, CURLOPT_RANGE => '0-2048',
            CURLOPT_USERAGENT => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148']);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $final = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        $check($label . ' responde', $code >= 200 && $code < 400 || $code === 206, $code . ' ' . $final);
    }
}

$failed = count(array_filter($results, function($r) {
    return !$r[0];
}));
$faqafter = $DB->get_records_menu('gmk_tkt_faq', null, 'id', "id, views + solved + notsolved AS c");
$clean = $DB->count_records('gmk_tkt_ticket', ['userid' => $studentid]) === $before && $faqafter == $faqbefore
    && config::get_int('tkt_mora_redirect') === $moraoriginal;
cli_writeln('');
cli_writeln(sprintf('%d pruebas, %d fallas. Revertido: %s (tickets del estudiante %d, contadores de problemas comunes '
    . 'y ajuste de mora sin cambios).', count($results), $failed, $clean ? 'sí' : 'NO', $before));
exit($failed || !$clean ? 1 : 0);
