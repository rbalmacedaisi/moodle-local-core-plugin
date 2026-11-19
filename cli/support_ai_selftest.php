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
 * Mesa de ayuda (20261001126): autotest del asistente semanal con un agente
 * SIMULADO (una funcion PHP que hace las veces de Claude) y rollback.
 *
 *   php support_ai_selftest.php --student=<id de un estudiante de prueba>
 *
 * Todo corre en UNA transaccion que siempre se revierte (tickets, mensajes,
 * tipificaciones, sugerencias, problemas comunes, respuestas rapidas, eventos).
 * Las notificaciones quedan en cola hasta el commit, asi que no sale nada.
 *
 * Recorre: anonimizacion (nombre, usuario, correo, telefono, cedula, terceros,
 * enlaces con token, numero TKT intacto), exclusion de notas internas, de
 * Psicologia y de lo confidencial, importacion en modo prueba (no deja nada),
 * importacion real, evidencia calculada por el servidor, idempotencia (mismo
 * runkey, ticket ya tipificado, la exportacion ya no lo trae), deduplicacion
 * de sugerencias, Anadir (problema comun nuevo con auditoria), Editar y anadir
 * (mejora con texto editado, contadores intactos), respuesta rapida,
 * Descartar (no se vuelve a proponer), Recuperar, doble accion rechazada,
 * pantalla (export_ui) y capacidad.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_grupomakro_core\local\support\assistant;
use local_grupomakro_core\local\support\manager;

list($options, $unrecognized) = cli_get_params(['student' => 0, 'help' => false], ['h' => 'help']);
if ($options['help'] || (int)$options['student'] <= 0) {
    cli_writeln("Uso: php support_ai_selftest.php --student=<userid de un estudiante de prueba>\n"
        . "Prueba el asistente semanal con un agente simulado dentro de una transaccion y la revierte.");
    exit(0);
}
$studentid = (int)$options['student'];
$student = $DB->get_record('user', ['id' => $studentid, 'deleted' => 0]);
if (!$student) {
    cli_error('El estudiante no existe.');
}
if (!assistant::enabled()) {
    cli_error('Faltan las tablas gmk_tkt_ai_*: corre primero admin/cli/upgrade.php.');
}
$adminid = (int)get_admin()->id;

$results = [];
$check = function(string $label, bool $ok, string $detail = '') use (&$results) {
    $results[] = [$ok, $label, $detail];
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
$topicbyarea = function(string $code) use ($DB) {
    return $DB->get_record_sql("SELECT c.*
                                  FROM {gmk_tkt_category} c
                                  JOIN {gmk_tkt_area} a ON a.id = c.areaid
                                 WHERE a.code = :code AND a.active = 1 AND c.active = 1 AND c.confidential = 0
                                   AND (SELECT COUNT(1) FROM {gmk_tkt_agent} g WHERE g.categoryid = c.id) > 0
                              ORDER BY c.sortorder", ['code' => $code], IGNORE_MULTIPLE);
};

/**
 * The simulated provider: reads the anonymised export and answers like the
 * real agent would (classifications + suggestions), with a few traps.
 */
$simulated = function(array $export, string $runkey, int $areaid, int $psychid, int $faqid, array $extraids) {
    $class = [];
    foreach ($export['tickets'] as $t) {
        $class[] = ['ticketid' => $t['id'], 'problemtype' => 'No encuentra la clase virtual',
            'problemlabel' => 'No encuentra la clase virtual', 'resolutiontype' => 'guia_pasos',
            'resolutionlabel' => 'Se le guió paso a paso', 'resolved' => 'si'];
    }
    foreach ($extraids as $id) {
        // Traps: a confidential ticket and a ticket that does not exist.
        $class[] = ['ticketid' => $id, 'problemtype' => 'x', 'resolutiontype' => 'y', 'resolved' => 'si'];
    }
    $ids = array_merge(array_column($export['tickets'], 'id'), $extraids);
    return [
        'runkey' => $runkey, 'model' => 'simulado', 'period' => $export['period'],
        'usage' => ['input_tokens' => 1234, 'output_tokens' => 321],
        'summary' => 'Prueba automática.',
        'classifications' => $class,
        'suggestions' => [
            ['kind' => 'faq_new', 'areaid' => $areaid, 'problemtype' => 'no_encuentra_la_clase_virtual',
                'problem' => 'Prueba IA: no encuentro mi clase virtual', 'answer' => "1. Entra a **Mi Horario**.\n2. Toca la clase.",
                'links' => [['label' => 'Plataforma', 'url' => 'https://students.isi.edu.pa/', 'kind' => 'web'],
                    ['label' => 'Malo', 'url' => 'http://inseguro.example/', 'kind' => 'web']],
                'audience' => 'student', 'rationale' => 'Se repite.', 'ticketids' => $ids,
                'examples' => ['Hola, soy Juan, mi correo es juan@example.com y mi cel 6123-4567']],
            ['kind' => 'faq_improve', 'faqid' => $faqid, 'problemtype' => 'faq_prueba',
                'answer' => "1. Paso nuevo.\n2. Otro paso.", 'rationale' => 'Mucho «sigo con el problema».', 'ticketids' => $ids],
            ['kind' => 'canned', 'areaid' => $areaid, 'problemtype' => 'pide_cedula', 'title' => 'Prueba IA: pedir captura',
                'body' => 'Para ayudarte, envíanos una captura de pantalla del error.', 'ticketids' => $ids],
            ['kind' => 'faq_new', 'areaid' => $psychid ?: -1, 'problemtype' => 'nunca', 'problem' => 'No debe entrar',
                'answer' => 'Nada', 'ticketids' => $ids],
        ],
    ];
};

cli_writeln('Asistente semanal - prueba con rollback. Estudiante: ' . fullname($student) . " ($studentid)");
$runsbefore = $DB->count_records('gmk_tkt_ai_run');
$faqsbefore = $DB->count_records('gmk_tkt_faq');
$transaction = $DB->start_delegated_transaction();
try {
    $academic = $topicbyarea('academico') ?: $topicbyarea('ti');
    if (!$academic) {
        throw new \coding_exception('No hay un tema académico o de TI con agentes configurados.');
    }
    $areaid = (int)$academic->areaid;
    foreach ($DB->get_fieldset_select('gmk_tkt_agent', 'userid', 'categoryid = ?', [$academic->id]) as $a) {
        manager::set_availability((int)$a, true, 0);
    }
    $third = $DB->get_record_sql("SELECT id, firstname, lastname FROM {user}
                                   WHERE deleted = 0 AND id <> :s AND id > 2 AND " . $DB->sql_length('lastname') . " >= 4
                                     AND " . $DB->sql_length('firstname') . " >= 4
                                ORDER BY id DESC", ['s' => $studentid], IGNORE_MULTIPLE);
    $thirdname = $third ? \core_text::strtotitle(trim($third->firstname . ' ' . $third->lastname)) : '';

    // 1. A real closed ticket full of personal data, plus an internal note.
    $first = trim($student->firstname);
    $body = "Hola, soy " . fullname($student) . " ({$student->username}), mi correo {$student->email}, "
        . "mi cédula 8-123-4567 y mi celular +507 6123-4567. No encuentro la clase de TKT-2026-0042."
        . ($thirdname !== '' ? " El profesor $thirdname me dijo que escribiera." : '');
    $t1 = manager::create($studentid, (int)$academic->id, 'No encuentro mi clase, ' . $first, $body);
    $agent = (int)$t1->assignedto;
    $check('ticket de prueba asignado', $agent > 0, $t1->number);
    $agentuser = \core_user::get_user($agent);
    manager::send((int)$t1->id, $agent, 'NOTA INTERNA SECRETA: revisar expediente', true);
    manager::send((int)$t1->id, $agent, 'Hola ' . $first . ', soy ' . fullname($agentuser)
        . '. Entra a https://lms.isi.edu.pa/mod/bigbluebuttonbn/view.php?id=123&token=abc123 y toca «Unirme».');
    $t1 = manager::staff_action((int)$t1->id, $agent, 'resolve');
    $t1 = manager::confirm_resolution((int)$t1->id, $studentid, true);
    manager::rate((int)$t1->id, $studentid, 2, false, 'Gracias ' . fullname($agentuser) . ', igual tardó.');
    $t1 = $DB->get_record('gmk_tkt_ticket', ['id' => $t1->id]);
    $check('ticket cerrado y calificado', $t1->status === 'cerrado' && (int)$t1->rating === 2, $t1->status);

    // 2. A Psychology ticket (closed) that must never leave the server.
    $psych = $DB->get_record_sql("SELECT c.* FROM {gmk_tkt_category} c JOIN {gmk_tkt_area} a ON a.id = c.areaid
                                   WHERE a.code = 'psicologia' ORDER BY c.id", [], IGNORE_MULTIPLE);
    $psychticket = 0;
    $psychareaid = 0;
    if ($psych) {
        $psychareaid = (int)$psych->areaid;
        $tp = manager::create($studentid, (int)$psych->id, 'Tema personal', 'Contenido confidencial de psicología');
        $DB->update_record('gmk_tkt_ticket', (object)['id' => $tp->id, 'status' => 'cerrado', 'closed_at' => time()]);
        $psychticket = (int)$tp->id;
    } else {
        cli_writeln('  (no hay área de Psicología: omito esa exclusión)');
    }

    // 3. Export.
    $ids = array_filter([(int)$t1->id, $psychticket]);
    $export = assistant::export(['ids' => $ids]);
    $json = json_encode($export, JSON_UNESCAPED_UNICODE);
    $exported = array_column($export['tickets'], 'id');
    $check('exporta el ticket cerrado', in_array((int)$t1->id, $exported, true));
    if ($psychticket) {
        $check('Psicología no se exporta', !in_array($psychticket, $exported, true)
            && strpos($json, 'Contenido confidencial') === false);
    }
    $check('sin notas internas', strpos($json, 'NOTA INTERNA') === false);
    $leaks = [];
    foreach ([fullname($student), $student->lastname, $student->username, $student->email, '8-123-4567', '6123-4567',
            fullname($agentuser), $agentuser->lastname, 'token=abc123'] as $needle) {
        if (\core_text::strlen(trim((string)$needle)) >= 3 && stripos($json, (string)$needle) !== false) {
            $leaks[] = $needle;
        }
    }
    $check('sin nombres, usuario, correo, cédula, teléfono ni tokens', !$leaks, implode(', ', $leaks));
    if ($thirdname !== '') {
        $check('nombre de un tercero (otro usuario) reemplazado', stripos($json, $thirdname) === false, $thirdname);
    }
    $check('marcadores presentes', strpos($json, '[SOLICITANTE]') !== false && strpos($json, '[AGENTE]') !== false
        && strpos($json, '[CORREO]') !== false && strpos($json, '[CEDULA]') !== false && strpos($json, '[TELEFONO]') !== false);
    $check('el número TKT y el enlace (sin token) sobreviven', strpos($json, 'TKT-2026-0042') !== false
        && strpos($json, 'https://lms.isi.edu.pa/mod/bigbluebuttonbn/view.php') !== false);
    $row = $export['tickets'][array_search((int)$t1->id, $exported, true)] ?? [];
    $check('trae calificación, "se resolvió" y mensajes', ($row['rating'] ?? 0) === 2 && ($row['rating_solved'] ?? '') === 'no'
        && count($row['messages'] ?? []) >= 2);
    $check('contexto sin Psicología', !in_array('psicologia', array_column($export['context']['areas'], 'code'), true));

    // A common problem to improve (in the same area).
    $faqid = (int)$DB->insert_record('gmk_tkt_faq', (object)['areaid' => $areaid, 'categoryid' => 0,
        'problem' => 'Prueba IA: problema existente', 'answer' => 'Respuesta vieja', 'links' => '[]', 'audience' => 'all',
        'sortorder' => 99, 'active' => 1, 'views' => 40, 'solved' => 5, 'notsolved' => 30, 'timecreated' => time(),
        'timemodified' => time()]);

    // 4. Dry run leaves nothing.
    $runkey = 'selftest-' . time();
    $results1 = $simulated($export, $runkey, $areaid, $psychareaid, $faqid, array_filter([$psychticket, 999999999]));
    $dry = assistant::import($results1, $adminid, true);
    $check('modo prueba: cuenta y no deja nada', $dry['status'] === 'dry_run' && $dry['classified'] === 1
        && !$DB->record_exists('gmk_tkt_ai_run', ['runkey' => $runkey])
        && !$DB->record_exists('gmk_tkt_ai_class', ['ticketid' => $t1->id]), json_encode($dry));

    // 5. Real import.
    $rep = assistant::import($results1, $adminid, false);
    $check('importa 1 tipificación y omite el confidencial y el inexistente',
        $rep['classified'] === 1 && count($rep['skipped']) === 2, json_encode($rep['skipped']));
    $check('3 sugerencias nuevas; la del área confidencial se rechaza',
        $rep['suggestions_new'] === 3 && count($rep['suggestions_skipped']) === 1, json_encode($rep['suggestions_skipped']));
    $new = $DB->get_record('gmk_tkt_ai_sugg', ['runid' => $rep['runid'], 'kind' => 'faq_new']);
    $check('la evidencia la calcula el servidor (1 ticket válido, no 3)', $new && (int)$new->evidence === 1,
        $new ? (string)$new->evidence : '-');
    $links = json_decode((string)$new->links, true);
    $check('enlace http descartado, https conservado', count($links) === 1 && $links[0]['url'] === 'https://students.isi.edu.pa/');
    $ex = implode(' ', (array)json_decode((string)$new->examples, true));
    $check('ejemplos re-anonimizados al importar', strpos($ex, 'juan@example.com') === false && strpos($ex, '6123-4567') === false,
        $ex);
    $cls = $DB->get_record('gmk_tkt_ai_class', ['ticketid' => $t1->id]);
    $check('tipificación con calificación del estudiante', $cls && (int)$cls->rating === 2 && (int)$cls->ratingsolved === 2
        && $cls->problemtype === 'no_encuentra_la_clase_virtual', $cls ? $cls->problemtype : '-');

    // 6. Idempotency.
    $again = assistant::import($results1, $adminid, false);
    $check('mismo runkey: no se importa dos veces', $again['status'] === 'already_imported');
    $results2 = $simulated($export, $runkey . '-b', $areaid, 0, $faqid, []);
    $rep2 = assistant::import($results2, $adminid, false);
    $check('ticket ya tipificado: se omite', $rep2['classified'] === 0 && ($rep2['skipped'][0]['reason'] ?? '') === 'ya analizado');
    $check('misma sugerencia pendiente: se actualiza, no se duplica', $rep2['suggestions_new'] === 0
        && $rep2['suggestions_updated'] === 3
        && $DB->count_records('gmk_tkt_ai_sugg', ['kind' => 'faq_new', 'areaid' => $areaid,
            'problemtype' => 'no_encuentra_la_clase_virtual', 'status' => 'pending']) === 1);
    $check('la exportación ya no trae el ticket analizado', !in_array((int)$t1->id,
        array_column(assistant::export(['ids' => $ids])['tickets'], 'id'), true));

    // 7. One-click actions.
    $sugg = function(string $kind) use ($DB, $areaid) {
        return $DB->get_record_select('gmk_tkt_ai_sugg', "kind = ? AND areaid = ? AND status = 'pending' AND title LIKE ?",
            [$kind, $areaid, $kind === 'faq_improve' ? 'Prueba IA%' : 'Prueba IA%'], '*', IGNORE_MULTIPLE);
    };
    $s = $sugg('faq_new');
    $newfaq = assistant::action((int)$s->id, 'add', null, $adminid);
    $f = $DB->get_record('gmk_tkt_faq', ['id' => $newfaq]);
    $check('Añadir crea el problema común activo', $f && (int)$f->active === 1 && $f->problem === $s->title
        && $f->audience === 'student', $f ? $f->problem : '-');
    $check('auditoría: config_faq y ai_suggestion_add', $DB->record_exists_select('gmk_tkt_event',
        "eventtype = 'config_faq' AND actorid = ? AND ticketid = 0", [$adminid])
        && $DB->record_exists('gmk_tkt_event', ['eventtype' => 'ai_suggestion_add', 'newvalue' => (string)$s->id]));
    $s = $DB->get_record('gmk_tkt_ai_sugg', ['id' => $s->id]);
    $check('la sugerencia queda añadida con el id del problema', $s->status === 'added' && (int)$s->resultid === $newfaq);
    $check('no se puede añadir dos veces', $throws(function() use ($s, $adminid) {
        assistant::action((int)$s->id, 'add', null, $adminid);
    }));

    $s = $DB->get_record('gmk_tkt_ai_sugg', ['kind' => 'faq_improve', 'faqid' => $faqid, 'status' => 'pending']);
    assistant::action((int)$s->id, 'add', ['answer' => "1. Texto editado por la persona.", 'problem' => 'Prueba IA: problema existente'],
        $adminid);
    $f = $DB->get_record('gmk_tkt_faq', ['id' => $faqid]);
    $check('Editar y añadir (mejora): usa el texto editado y no toca contadores', trim($f->answer) === '1. Texto editado por la persona.'
        && (int)$f->notsolved === 30 && (int)$f->views === 40 && (int)$f->sortorder === 99);

    $s = $DB->get_record('gmk_tkt_ai_sugg', ['kind' => 'canned', 'areaid' => $areaid, 'problemtype' => 'pide_cedula',
        'status' => 'pending']);
    $cid = assistant::action((int)$s->id, 'dismiss', null, $adminid);
    $check('Descartar', $DB->get_field('gmk_tkt_ai_sugg', 'status', ['id' => $s->id]) === 'dismissed' && $cid === 0);
    $results3 = $simulated(['tickets' => [], 'period' => []], $runkey . '-c', $areaid, 0, $faqid, []);
    $results3['suggestions'] = [$results1['suggestions'][2]];
    $rep3 = assistant::import($results3, $adminid, false);
    $check('lo descartado no se vuelve a proponer', $rep3['suggestions_new'] === 0 && count($rep3['suggestions_skipped']) === 1,
        json_encode($rep3['suggestions_skipped']));
    assistant::action((int)$s->id, 'restore', null, $adminid);
    $cid = assistant::action((int)$s->id, 'add', ['title' => 'Prueba IA: pedir captura (editada)'], $adminid);
    $c = $DB->get_record('gmk_tkt_canned', ['id' => $cid]);
    $check('Recuperar y añadir la respuesta rápida', $c && $c->title === 'Prueba IA: pedir captura (editada)'
        && (int)$c->areaid === $areaid);

    // 8. Screen and capability.
    $ui = assistant::export_ui();
    $check('pantalla: sugerencias, historial y tipificación', count($ui['runs']) >= 3
        && in_array('no_encuentra_la_clase_virtual', array_column($ui['taxonomy'], 'problemtype'), true)
        && !in_array($psychareaid, array_column($ui['areas'], 'id'), true));
    $check('el estudiante no tiene support_assistant', !has_capability('local/grupomakro_core:support_assistant',
        context_system::instance(), $studentid));

    // 9. Anonymiser edge cases.
    $a = assistant::anonymize('Llamar al 507-123-4567 o 66667777; ver TKT-2026-0012; id 12345678; https://x.isi.edu.pa/a?b=c#d');
    $check('anonimizador: teléfonos, números largos, TKT y query', strpos($a, '[TELEFONO]') !== false
        && strpos($a, '66667777') === false && strpos($a, 'TKT-2026-0012') !== false && strpos($a, '12345678') === false
        && strpos($a, '?b=c') === false && strpos($a, 'https://x.isi.edu.pa/a') !== false, $a);
} catch (\Throwable $e) {
    $check('excepcion inesperada', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

try {
    $transaction->rollback(new \moodle_exception('support_ai_selftest_rollback'));
} catch (\moodle_exception $e) {
    // Expected: rollback() rethrows.
}
manager::reset_caches();
assistant::reset_caches();

$failed = count(array_filter($results, function($r) {
    return !$r[0];
}));
cli_writeln('');
cli_writeln(sprintf('%d pruebas, %d fallas. Todo revertido (ejecuciones: %d antes, %d después; problemas comunes: %d / %d).',
    count($results), $failed, $runsbefore, $DB->count_records('gmk_tkt_ai_run'), $faqsbefore, $DB->count_records('gmk_tkt_faq')));
exit($failed ? 1 : 0);
