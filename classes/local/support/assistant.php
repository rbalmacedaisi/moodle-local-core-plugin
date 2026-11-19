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
 * Mesa de ayuda: asistente semanal de mejoras (20261001126).
 *
 * Pedido de Ramiro (19-nov-2026): un agente que cada semana lea como se
 * cerraron los tickets, los tipifique y cuantifique, y proponga problemas
 * comunes, mejoras a respuestas y respuestas rapidas que se apliquen con un
 * clic por area. Por decision de Ramiro no hay proveedor de IA, API key, token
 * ni servicio web para esto en Moodle: el analisis es una tarea programada
 * LOCAL de la app de Claude (lunes 7:00) que entra por SSH y usa los CLI
 * (prompt en docs/agents/analisis_semanal_tickets.md). 20261001127 quito el
 * servicio gmk_support_ai, sus tokens y el usuario tecnico de la version anterior.
 *
 *   1. export() (cli/support_ai_export.php): tickets resueltos/cerrados aun
 *      no analizados, YA anonimizados en el servidor (sin nombres, correos,
 *      telefonos ni cedulas), sin notas internas y sin nada confidencial
 *      (Psicologia y temas confidenciales se excluyen por completo, decision
 *      de Ramiro). Ningun dato personal sale del servidor.
 *   2. El agente analiza ese JSON y arma la ejecucion.
 *   3. import() (cli/support_ai_import.php): entrada no confiable; valida y sanea
 *      todo (tickets existentes, cerrados, no confidenciales, no analizados;
 *      textos sin etiquetas; botones solo https), guarda tipificaciones y
 *      sugerencias. La evidencia la calcula el servidor. Idempotente por
 *      runkey y por ticket.
 *   4. action(): Anadir / Editar y anadir / Descartar desde la configuracion.
 *      Anadir pasa por settings::apply (misma validacion y auditoria que a mano).
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\support;

use invalid_parameter_exception;
use stdClass;

defined('MOODLE_INTERNAL') || die();

class assistant {

    const KINDS = ['faq_new', 'faq_improve', 'canned'];
    const KIND_LABELS = ['faq_new' => 'Nuevo problema común', 'faq_improve' => 'Mejorar respuesta', 'canned' => 'Respuesta rápida'];
    const DONE = [manager::S_RESOLVED, manager::S_CLOSED];
    /** Areas never analysed, whatever their topics say. */
    const EXCLUDED_AREAS = ['psicologia'];
    const MAX_MESSAGES = 40;
    const MAX_MESSAGE_CHARS = 1500;
    const MAX_EXAMPLES = 3;
    const MAX_EXAMPLE_CHARS = 300;
    /** A dismissed suggestion with the same key is not proposed again for this long. */
    const DISMISS_QUIET_DAYS = 60;
    const MAX_CLASSIFICATIONS = 600;
    const MAX_SUGGESTIONS = 60;

    /** Capitalised words that are also first/last names but appear in normal support text. */
    const NAME_ALLOW = ['moodle', 'chrome', 'google', 'teams', 'zoom', 'windows', 'android', 'iphone', 'apple', 'mesa', 'ayuda',
        'soporte', 'plataforma', 'clase', 'campus', 'horario', 'inicio', 'activo', 'virtual', 'cursos', 'gracias', 'hola',
        'buenas', 'buenos', 'saludos', 'profesor', 'profesora', 'docente', 'estudiante', 'lunes', 'martes', 'miercoles',
        'jueves', 'viernes', 'sabado', 'domingo', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto',
        'septiembre', 'octubre', 'noviembre', 'diciembre', 'isi', 'panama', 'academico', 'bienestar', 'finanzas', 'tecnologico',
        'instituto', 'superior', 'ingenieria', 'sesiones', 'sesion', 'videoconferencia', 'actividad', 'planes', 'aprendizaje',
        'safari', 'firefox', 'edge', 'store', 'play', 'whatsapp', 'correo', 'usuario', 'clave', 'contrasena', 'nota', 'notas',
        'modulo', 'materia', 'asignatura', 'examen', 'tarea', 'gracia', 'dios', 'santa', 'santo', 'san'];

    /** @var array|null normalised first/last name tokens of every user */
    private static $nameset = null;

    public static function enabled(): bool {
        global $DB;
        return $DB->get_manager()->table_exists('gmk_tkt_ai_sugg');
    }

    // ---------------------------------------------------------------------
    // 1. Export (anonymised, runs on the server)
    // ---------------------------------------------------------------------

    /**
     * @param array $opts limit (default 200), since (unix, default 0 = any), ids (only these ticket ids; tests)
     * @return array JSON-ready payload for the agent
     */
    public static function export(array $opts = []): array {
        global $DB;
        $limit = max(1, min(500, (int)($opts['limit'] ?? 200)));
        $since = max(0, (int)($opts['since'] ?? 0));
        [$where, $params] = self::analysable_where('t');
        $where .= ' AND NOT EXISTS (SELECT 1 FROM {gmk_tkt_ai_class} k WHERE k.ticketid = t.id)';
        if ($since > 0) {
            $where .= ' AND GREATEST(t.resolved_at, t.closed_at) >= :since';
            $params['since'] = $since;
        }
        if (!empty($opts['ids'])) {
            [$in, $inparams] = $DB->get_in_or_equal(array_map('intval', $opts['ids']), SQL_PARAMS_NAMED, 'only');
            $where .= " AND t.id $in";
            $params += $inparams;
        }
        $tickets = $DB->get_records_sql("SELECT t.*
                                           FROM {gmk_tkt_ticket} t
                                           JOIN {gmk_tkt_area} a ON a.id = t.areaid
                                      LEFT JOIN {gmk_tkt_category} c ON c.id = t.categoryid
                                          WHERE $where
                                       ORDER BY t.id ASC", $params, 0, $limit);
        $areas = $DB->get_records('gmk_tkt_area');
        $cats = $DB->get_records('gmk_tkt_category');
        $faqtried = self::faq_tried(array_keys($tickets));
        $faqs = $DB->get_records('gmk_tkt_faq');
        $out = [];
        $from = 0;
        $to = 0;
        foreach ($tickets as $t) {
            $msgs = $DB->get_records_select('gmk_tkt_message', 'ticketid = :t AND internal = 0 AND deleted = 0',
                ['t' => $t->id], 'id ASC');
            $people = [(int)$t->userid => '[SOLICITANTE]'];
            foreach ($msgs as $m) {
                if ((int)$m->senderid > 0 && !isset($people[(int)$m->senderid])) {
                    $people[(int)$m->senderid] = '[AGENTE]';
                }
            }
            if ((int)$t->assignedto > 0 && !isset($people[(int)$t->assignedto])) {
                $people[(int)$t->assignedto] = '[AGENTE]';
            }
            $map = self::people_map($people);
            $conversation = [];
            $truncated = false;
            foreach ($msgs as $m) {
                if ((int)$m->senderid <= 0 || $m->senderrole === manager::ROLE_SYSTEM) {
                    continue; // Automatic texts carry no information about the problem.
                }
                if (count($conversation) >= self::MAX_MESSAGES) {
                    $truncated = true;
                    break;
                }
                $text = self::anonymize((string)$m->body, $map);
                if (\core_text::strlen($text) > self::MAX_MESSAGE_CHARS) {
                    $text = \core_text::substr($text, 0, self::MAX_MESSAGE_CHARS) . ' […]';
                }
                $conversation[] = ['from' => (int)$m->senderid === (int)$t->userid ? 'solicitante' : 'agente',
                    'text' => $text, 'attachments' => (int)$m->hasattachments === 1];
            }
            $done = max((int)$t->resolved_at, (int)$t->closed_at);
            $from = $from ? min($from, $done) : $done;
            $to = max($to, $done);
            $faqid = $faqtried[(int)$t->id] ?? 0;
            $out[] = [
                'id' => (int)$t->id,
                'areaid' => (int)$t->areaid,
                'area' => isset($areas[$t->areaid]) ? (string)$areas[$t->areaid]->name : '',
                'categoryid' => (int)$t->categoryid,
                'category' => isset($cats[$t->categoryid]) ? (string)$cats[$t->categoryid]->name : '',
                'requester' => $t->requesterrole === 'teacher' ? 'docente' : 'estudiante',
                'status' => (string)$t->status,
                'subject' => self::anonymize((string)$t->subject, $map),
                'created' => userdate((int)$t->timecreated, '%Y-%m-%d'),
                'hours_to_first_response' => $t->first_response_at > 0
                    ? round(((int)$t->first_response_at - (int)$t->timecreated) / 3600, 1) : null,
                'hours_to_resolution' => $done > 0 ? round(($done - (int)$t->timecreated) / 3600, 1) : null,
                'reopened' => (int)$t->reopened_count,
                'rating' => (int)$t->rating,
                'rating_solved' => (int)$t->rating_solved === 1 ? 'si' : ((int)$t->rating_solved === 2 ? 'no' : 'sin respuesta'),
                'rating_comment' => self::anonymize((string)$t->rating_comment, $map),
                'faq_tried' => $faqid && isset($faqs[$faqid]) ? ['id' => $faqid, 'problem' => (string)$faqs[$faqid]->problem] : null,
                'messages' => $conversation,
                'truncated' => $truncated,
            ];
        }
        // Pointer for the screen and the log: what the agent last took. The real
        // idempotency is "not classified yet" (a ticket exported but never
        // submitted comes again next week).
        $lastid = $out ? max(array_column($out, 'id')) : 0;
        set_config('tkt_ai_last_export', json_encode(['time' => time(), 'tickets' => count($out), 'lastticketid' => $lastid,
            'from' => $from, 'to' => $to]), 'local_grupomakro_core');
        manager::log_event(0, 'ai_export', '', (string)count($out), 0,
            ['tickets' => count($out), 'from' => $from, 'to' => $to, 'lastticketid' => $lastid]);
        return [
            'format' => 'gmk-support-ai-export/1',
            'generated' => time(),
            'period' => ['from' => $from, 'to' => $to],
            'tickets' => $out,
            'context' => self::context(),
        ];
    }

    /**
     * What the agent needs to propose things that fit: areas and topics it may
     * use, existing common problems with their counters, quick replies, the
     * taxonomy it used before (to stay consistent) and suggestions already
     * pending or dismissed (to avoid repeating them).
     */
    public static function context(): array {
        global $DB;
        $allowed = self::allowed_areas();
        $areas = [];
        foreach ($allowed as $a) {
            $areas[] = ['id' => (int)$a->id, 'code' => $a->code, 'name' => $a->name,
                'topics' => array_values(array_map(function($c) {
                    return ['id' => (int)$c->id, 'name' => $c->name];
                }, $DB->get_records_select('gmk_tkt_category', 'areaid = ? AND confidential = 0 AND active = 1', [$a->id],
                    'sortorder, id')))];
        }
        $ids = array_keys($allowed);
        $faqs = [];
        $canned = [];
        $taxonomy = [];
        $previous = [];
        if ($ids) {
            [$in, $params] = $DB->get_in_or_equal($ids);
            foreach ($DB->get_records_select('gmk_tkt_faq', "areaid $in", $params, 'areaid, sortorder, id') as $f) {
                $faqs[] = ['id' => (int)$f->id, 'areaid' => (int)$f->areaid, 'categoryid' => (int)$f->categoryid,
                    'problem' => $f->problem, 'answer' => (string)$f->answer, 'links' => faq::links((string)$f->links),
                    'audience' => $f->audience, 'active' => (int)$f->active,
                    'views' => (int)$f->views, 'solved' => (int)$f->solved, 'notsolved' => (int)$f->notsolved];
            }
            foreach ($DB->get_records_select('gmk_tkt_canned', "areaid $in", $params, 'areaid, id') as $c) {
                $canned[] = ['id' => (int)$c->id, 'areaid' => (int)$c->areaid, 'title' => $c->title];
            }
            foreach ($DB->get_records_sql("SELECT MIN(id) AS id, areaid, problemtype, MAX(problemlabel) AS problemlabel,
                                                  COUNT(1) AS n
                                             FROM {gmk_tkt_ai_class}
                                            WHERE areaid $in
                                         GROUP BY areaid, problemtype", $params) as $r) {
                $taxonomy[] = ['areaid' => (int)$r->areaid, 'problemtype' => $r->problemtype,
                    'problemlabel' => $r->problemlabel, 'tickets' => (int)$r->n];
            }
            foreach ($DB->get_records_select('gmk_tkt_ai_sugg', "areaid $in AND (status = 'pending' OR timeresolved > ?)",
                    array_merge($params, [time() - self::DISMISS_QUIET_DAYS * DAYSECS]), 'id') as $s) {
                $previous[] = ['kind' => $s->kind, 'areaid' => (int)$s->areaid, 'problemtype' => $s->problemtype,
                    'faqid' => (int)$s->faqid, 'title' => $s->title, 'status' => $s->status];
            }
        }
        return ['areas' => $areas, 'faqs' => $faqs, 'canned' => $canned, 'taxonomy' => $taxonomy,
            'previous_suggestions' => $previous];
    }

    /**
     * SQL condition (on ticket alias $t joined to {gmk_tkt_area} a and LEFT
     * {gmk_tkt_category} c) for tickets the assistant may read.
     */
    private static function analysable_where(string $t): array {
        global $DB;
        [$st, $params] = $DB->get_in_or_equal(self::DONE, SQL_PARAMS_NAMED, 'st');
        [$ex, $exparams] = $DB->get_in_or_equal(self::EXCLUDED_AREAS, SQL_PARAMS_NAMED, 'ex', false);
        return ["$t.status $st AND $t.confidential = 0 AND a.code $ex AND COALESCE(c.confidential, 0) = 0",
            $params + $exparams];
    }

    /**
     * @return stdClass[] areas keyed by id the assistant may read and propose for
     */
    public static function allowed_areas(): array {
        global $DB;
        $out = [];
        foreach ($DB->get_records('gmk_tkt_area', null, 'sortorder, id') as $a) {
            if (in_array($a->code, self::EXCLUDED_AREAS, true) || trim((string)$a->external_url) !== '') {
                continue;
            }
            // An area whose every topic is confidential is confidential as a whole.
            $open = $DB->count_records('gmk_tkt_category', ['areaid' => $a->id, 'confidential' => 0]);
            if ($open === 0 && $DB->record_exists('gmk_tkt_category', ['areaid' => $a->id])) {
                continue;
            }
            $out[(int)$a->id] = $a;
        }
        return $out;
    }

    /**
     * @return array ticketid => faqid tried before opening (event faq_tried)
     */
    private static function faq_tried(array $ticketids): array {
        global $DB;
        if (!$ticketids) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal($ticketids);
        $out = [];
        foreach ($DB->get_records_select('gmk_tkt_event', "eventtype = 'faq_tried' AND ticketid $in", $params, 'id',
                'id, ticketid, newvalue') as $e) {
            $out[(int)$e->ticketid] = (int)$e->newvalue;
        }
        return $out;
    }

    // ---------------------------------------------------------------------
    // Anonymisation
    // ---------------------------------------------------------------------

    /**
     * @param array $people userid => marker
     * @return array [needle => marker], longest first
     */
    public static function people_map(array $people): array {
        global $DB;
        $map = [];
        if (!$people) {
            return $map;
        }
        [$in, $params] = $DB->get_in_or_equal(array_keys($people));
        $users = $DB->get_records_select('user', "id $in", $params, '',
            'id, username, firstname, lastname, middlename, alternatename, email, idnumber, phone1, phone2');
        foreach ($users as $u) {
            $marker = $people[(int)$u->id];
            $doc = '';
            try {
                $doc = manager::document_number($u);
            } catch (\Throwable $e) {
                $doc = (string)$u->idnumber;
            }
            $full = trim($u->firstname . ' ' . $u->lastname);
            foreach ([$full, $u->firstname, $u->lastname, $u->middlename, $u->alternatename] as $n) {
                $n = trim((string)$n);
                if (\core_text::strlen($n) >= 3) {
                    $map[$n] = $marker;
                }
                foreach (preg_split('/\s+/u', $n) as $part) {
                    if (\core_text::strlen($part) >= 3) {
                        $map[$part] = $marker;
                    }
                }
            }
            foreach ([$u->email => '[CORREO]', $u->username => $marker, $doc => '[CEDULA]', $u->idnumber => '[CEDULA]',
                    $u->phone1 => '[TELEFONO]', $u->phone2 => '[TELEFONO]'] as $needle => $m) {
                if (\core_text::strlen(trim((string)$needle)) >= 3) {
                    $map[trim((string)$needle)] = $m;
                }
            }
        }
        uksort($map, function($a, $b) {
            return \core_text::strlen($b) <=> \core_text::strlen($a);
        });
        return $map;
    }

    /**
     * Removes personal data from $text: the people of the ticket by name,
     * username, e-mail, document and phone; any e-mail, phone, Panamanian
     * cedula or long number; query strings of links; and any capitalised word
     * that is the first or last name of a platform user (third parties).
     */
    public static function anonymize(string $text, array $map = []): string {
        $text = trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($text === '') {
            return '';
        }
        // Links: keep the site and path (useful for answers), drop query and fragment (tokens, ids).
        $text = preg_replace('#\b(https?://[^\s?\#<>"]+)[?\#][^\s<>"]*#iu', '$1', $text) ?? $text;
        // Ticket numbers (TKT-2026-0012) and institutional links (*.isi.edu.pa, already
        // without query) are not personal data: park them so that no rule below
        // (names, usernames, phones, long numbers) can mangle them. Placeholders
        // are control characters only, which no rule matches.
        $kept = [];
        $text = preg_replace_callback('#\bTKT-\d{4}-\d{3,6}\b|\bhttps?://(?:[a-z0-9-]+\.)*isi\.edu\.pa(?:[/:][^\s<>"]*)?#iu',
            function($m) use (&$kept) {
                $kept[] = $m[0];
                return "\x01" . str_repeat("\x02", count($kept)) . "\x03";
            }, $text) ?? $text;
        foreach ($map as $needle => $marker) {
            $text = preg_replace('/(?<![\p{L}\p{N}])' . preg_quote((string)$needle, '/') . '(?![\p{L}\p{N}])/iu', $marker, $text)
                ?? $text;
        }
        $text = preg_replace('/[\p{L}\p{N}._%+-]+@[\p{L}\p{N}.-]+\.[\p{L}]{2,}/u', '[CORREO]', $text) ?? $text;
        // Panama cedula: 8-123-4567, PE-12-345, E-8-12345, N-19-123, 1AV-12-345, 3PI-1-123.
        $text = preg_replace('/(?<![\p{L}\p{N}])(?:\d{1,2}|PE|E|N|\d{1,2}AV|\d{1,2}PI)-\d{1,4}-\d{1,6}(?![\p{N}])/iu', '[CEDULA]',
            $text) ?? $text;
        // Phones: +507 6123-4567, 6123 4567, 61234567, 507-123-4567.
        // Ticket numbers (TKT-2026-0012) are not personal data and must survive.
        $text = preg_replace('/(?<![\p{N}])(?<!TKT-)(?:\+?\d{3}[\s.-]?)?\d{3,4}[\s.-]?\d{4}(?![\p{N}])/u', '[TELEFONO]',
            $text) ?? $text;
        $text = preg_replace('/(?<![\p{N}])(?<!TKT-)\d{6,}(?![\p{N}])/u', '[NUMERO]', $text) ?? $text;
        $names = self::nameset();
        if ($names) {
            $text = preg_replace_callback('/(?<![\p{L}\[])\p{Lu}[\p{L}\']{2,}(?![\p{L}\]])/u', function($m) use ($names) {
                $key = self::fold($m[0]);
                return isset($names[$key]) ? '[NOMBRE]' : $m[0];
            }, $text) ?? $text;
            $text = preg_replace('/\[NOMBRE\](?:\s+(?:de\s+(?:la\s+|los\s+)?|del\s+)?\[NOMBRE\])+/u', '[NOMBRE]', $text) ?? $text;
        }
        // Put back what was parked, longest placeholder first (n=10 contains n=1).
        for ($i = count($kept); $i >= 1; $i--) {
            $text = str_replace("\x01" . str_repeat("\x02", $i) . "\x03", $kept[$i - 1], $text);
        }
        return trim($text);
    }

    private static function fold(string $s): string {
        $s = \core_text::strtolower($s);
        return strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    }

    private static function nameset(): array {
        global $DB;
        if (self::$nameset === null) {
            $set = [];
            $rs = $DB->get_recordset_select('user', 'deleted = 0', [], '', 'id, firstname, lastname');
            foreach ($rs as $u) {
                foreach (preg_split('/\s+/u', trim($u->firstname . ' ' . $u->lastname)) as $part) {
                    if (\core_text::strlen($part) >= 3) {
                        $set[self::fold($part)] = true;
                    }
                }
            }
            $rs->close();
            foreach (self::NAME_ALLOW as $w) {
                unset($set[$w]);
            }
            self::$nameset = $set;
        }
        return self::$nameset;
    }

    public static function reset_caches(): void {
        self::$nameset = null;
    }

    // ---------------------------------------------------------------------
    // 3. Import (validated, idempotent)
    // ---------------------------------------------------------------------

    /**
     * @param array $data decoded run JSON (see docs/agents/analisis_semanal_tickets.md)
     * @return array report {status, runid, classified, skipped[], suggestions_new, suggestions_updated, suggestions_skipped[]}
     */
    public static function import(array $data, int $actorid, bool $dryrun = false): array {
        global $DB;
        // Untrusted input (cloud agent): shapes and sizes first.
        foreach (['classifications', 'suggestions'] as $list) {
            if (isset($data[$list]) && !is_array($data[$list])) {
                throw new invalid_parameter_exception($list . ' debe ser una lista');
            }
        }
        $data['classifications'] = array_values(array_filter((array)($data['classifications'] ?? []), 'is_array'));
        $data['suggestions'] = array_values(array_filter((array)($data['suggestions'] ?? []), 'is_array'));
        $data['period'] = is_array($data['period'] ?? null) ? $data['period'] : [];
        $data['usage'] = is_array($data['usage'] ?? null) ? $data['usage'] : [];
        if (count($data['classifications']) > self::MAX_CLASSIFICATIONS || count($data['suggestions']) > self::MAX_SUGGESTIONS) {
            throw new invalid_parameter_exception('Demasiadas tipificaciones (máx. ' . self::MAX_CLASSIFICATIONS
                . ') o sugerencias (máx. ' . self::MAX_SUGGESTIONS . ')');
        }
        $runkey = is_scalar($data['runkey'] ?? '') ? clean_param((string)($data['runkey'] ?? ''), PARAM_ALPHANUMEXT) : '';
        if ($runkey === '' || \core_text::strlen($runkey) > 64) {
            throw new invalid_parameter_exception('runkey vacío o inválido (letras, números, guiones; máx. 64)');
        }
        if ($existing = $DB->get_record('gmk_tkt_ai_run', ['runkey' => $runkey])) {
            return ['status' => 'already_imported', 'runid' => (int)$existing->id, 'classified' => 0, 'skipped' => [],
                'suggestions_new' => 0, 'suggestions_updated' => 0, 'suggestions_skipped' => []];
        }
        $usage = (array)($data['usage'] ?? []);
        // A dry run validates and counts WITHOUT writing anything: no
        // transaction to roll back, so it is safe inside a caller's transaction
        // (in Moodle a rollback at any level rolls back everything).
        $transaction = $dryrun ? null : $DB->start_delegated_transaction();
        $now = time();
        $run = (object)[
            'runkey' => $runkey,
            'model' => self::plain($data['model'] ?? '', 80),
            'periodfrom' => max(0, (int)($data['period']['from'] ?? 0)),
            'periodto' => max(0, (int)($data['period']['to'] ?? 0)),
            'tickets' => 0, 'classified' => 0, 'suggestions' => 0, 'skipped' => 0,
            'tokensin' => max(0, (int)($usage['input_tokens'] ?? 0)),
            'tokensout' => max(0, (int)($usage['output_tokens'] ?? 0)),
            'costusd' => isset($usage['cost_usd']) && is_numeric($usage['cost_usd']) ? round((float)$usage['cost_usd'], 4) : null,
            'summary' => self::plain(($data['summary'] ?? ''), 4000),
            'report' => '',
            'status' => 'ok',
            'importedby' => $actorid,
            'timecreated' => $now,
        ];
        $run->id = $dryrun ? 0 : $DB->insert_record('gmk_tkt_ai_run', $run);
        $allowed = self::allowed_areas();
        $report = ['status' => $dryrun ? 'dry_run' : 'ok', 'runid' => (int)$run->id, 'classified' => 0, 'skipped' => [],
            'suggestions_new' => 0, 'suggestions_updated' => 0, 'suggestions_skipped' => []];

        // Classifications.
        $classes = (array)($data['classifications'] ?? []);
        $run->tickets = count($classes);
        $ids = array_values(array_filter(array_map(function($c) {
            return (int)($c['ticketid'] ?? 0);
        }, $classes)));
        $valid = self::analysable_tickets($ids);
        $faqtried = self::faq_tried($ids);
        $seen = [];
        foreach ($classes as $c) {
            $tid = (int)($c['ticketid'] ?? 0);
            $why = '';
            if (!isset($valid[$tid])) {
                $why = 'no existe, no está resuelto/cerrado o es confidencial';
            } else if (isset($seen[$tid]) || $DB->record_exists('gmk_tkt_ai_class', ['ticketid' => $tid])) {
                $why = 'ya analizado';
            }
            $ptype = self::slug($c['problemtype'] ?? '');
            $rtype = self::slug($c['resolutiontype'] ?? '');
            if ($why === '' && ($ptype === '' || $rtype === '')) {
                $why = 'falta problemtype o resolutiontype';
            }
            if ($why !== '') {
                $report['skipped'][] = ['ticketid' => $tid, 'reason' => $why];
                continue;
            }
            $t = $valid[$tid];
            $resolved = is_scalar($c['resolved'] ?? '') ? (string)($c['resolved'] ?? '') : '';
            $seen[$tid] = (int)$t->areaid;
            $report['classified']++;
            if ($dryrun) {
                continue;
            }
            $DB->insert_record('gmk_tkt_ai_class', (object)[
                'runid' => $run->id, 'ticketid' => $tid, 'areaid' => (int)$t->areaid, 'categoryid' => (int)$t->categoryid,
                'problemtype' => $ptype, 'problemlabel' => self::plain(($c['problemlabel'] ?? $ptype), 255),
                'resolutiontype' => $rtype, 'resolutionlabel' => self::plain(($c['resolutionlabel'] ?? $rtype), 255),
                'resolved' => $resolved === 'si' || $resolved === '1' || $resolved === 'yes' ? 1
                    : ($resolved === 'no' || $resolved === '0' ? 0 : 2),
                'rating' => (int)$t->rating, 'ratingsolved' => (int)$t->rating_solved,
                'faqid' => (int)($faqtried[$tid] ?? 0),
                'timeclosed' => max((int)$t->resolved_at, (int)$t->closed_at), 'timecreated' => $now,
            ]);
        }

        // Suggestions.
        foreach ((array)($data['suggestions'] ?? []) as $i => $s) {
            try {
                $r = self::import_suggestion((array)$s, (int)$run->id, $allowed, $seen, $dryrun);
                $report[$r === 'updated' ? 'suggestions_updated' : 'suggestions_new']++;
            } catch (invalid_parameter_exception $e) {
                $report['suggestions_skipped'][] = ['index' => $i, 'title' => self::plain(($s['title'] ?? $s['problem'] ?? ''), 80),
                    'reason' => $e->debuginfo ?: $e->getMessage()];
            }
        }
        $run->classified = $report['classified'];
        $run->skipped = count($report['skipped']);
        $run->suggestions = $report['suggestions_new'] + $report['suggestions_updated'];
        $run->report = json_encode($report);
        if ($dryrun) {
            return $report;
        }
        $DB->update_record('gmk_tkt_ai_run', $run);
        manager::log_event(0, 'ai_import', '', $runkey, $actorid, ['classified' => $run->classified,
            'suggestions' => $run->suggestions, 'skipped' => $run->skipped]);
        $transaction->allow_commit();
        return $report;
    }

    /**
     * @return stdClass[] tickets keyed by id among $ids that the assistant may read
     */
    private static function analysable_tickets(array $ids): array {
        global $DB;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        [$where, $params] = self::analysable_where('t');
        [$in, $inparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'tk');
        return $DB->get_records_sql("SELECT t.*
                                       FROM {gmk_tkt_ticket} t
                                       JOIN {gmk_tkt_area} a ON a.id = t.areaid
                                  LEFT JOIN {gmk_tkt_category} c ON c.id = t.categoryid
                                      WHERE $where AND t.id $in", $params + $inparams);
    }

    /**
     * @return string 'new' | 'updated'
     */
    /**
     * @param array $current ticketid => areaid classified in this same run
     * @param bool $dryrun validate and answer new/updated without writing
     */
    private static function import_suggestion(array $s, int $runid, array $allowed, array $current = [],
            bool $dryrun = false): string {
        global $DB;
        $kind = is_scalar($s['kind'] ?? '') ? (string)($s['kind'] ?? '') : '';
        if (!in_array($kind, self::KINDS, true)) {
            throw new invalid_parameter_exception('tipo de sugerencia desconocido');
        }
        $faqid = 0;
        $areaid = (int)($s['areaid'] ?? 0);
        if ($kind === 'faq_improve') {
            $faqid = (int)($s['faqid'] ?? 0);
            $f = $DB->get_record('gmk_tkt_faq', ['id' => $faqid]);
            if (!$f) {
                throw new invalid_parameter_exception('el problema común a mejorar no existe');
            }
            $areaid = (int)$f->areaid;
        }
        if (!isset($allowed[$areaid])) {
            throw new invalid_parameter_exception('área inválida o confidencial');
        }
        $categoryid = (int)($s['categoryid'] ?? 0);
        if ($categoryid > 0 && !$DB->record_exists('gmk_tkt_category', ['id' => $categoryid, 'areaid' => $areaid,
                'confidential' => 0])) {
            $categoryid = 0;
        }
        $ptype = self::slug($s['problemtype'] ?? '');
        $title = self::plain(($kind === 'canned' ? ($s['title'] ?? '') : ($s['problem'] ?? $s['title'] ?? '')), 255);
        $body = self::plain(($kind === 'canned' ? ($s['body'] ?? '') : ($s['answer'] ?? '')), 8000, true);
        if ($kind === 'faq_improve' && $title === '') {
            $title = (string)$f->problem;
        }
        if ($title === '' || $body === '') {
            throw new invalid_parameter_exception('faltan el título/problema o el texto de la respuesta');
        }
        $links = [];
        foreach ((array)($s['links'] ?? []) as $l) {
            if (!is_array($l)) {
                continue;
            }
            $url = trim(is_scalar($l['url'] ?? '') ? (string)($l['url'] ?? '') : '');
            $label = self::plain(($l['label'] ?? ''), 80);
            $lkind = $l['kind'] ?? 'web';
            if ($label !== '' && preg_match('#^https://#i', $url) && clean_param($url, PARAM_URL) === $url) {
                $links[] = ['label' => $label, 'url' => \core_text::substr($url, 0, 500),
                    'kind' => in_array($lkind, faq::LINK_KINDS, true) ? $lkind : 'web'];
            }
        }
        $links = array_slice($links, 0, faq::MAX_LINKS);
        $audience = in_array($s['audience'] ?? 'all', faq::AUDIENCES, true) ? (string)($s['audience'] ?? 'all') : 'all';

        // Evidence: only tickets of this area that were analysed (now or before).
        $tids = array_values(array_unique(array_filter(array_map('intval', (array)($s['ticketids'] ?? [])))));
        if ($tids) {
            [$in, $params] = $DB->get_in_or_equal($tids);
            $params[] = $areaid;
            $before = array_map('intval', $DB->get_fieldset_select('gmk_tkt_ai_class', 'ticketid', "ticketid $in AND areaid = ?",
                $params));
            // Plus the ones classified in this same run (not written yet in a dry run).
            $thisrun = array_keys(array_filter($current, function($a) use ($areaid) {
                return $a === $areaid;
            }));
            $tids = array_values(array_intersect($tids, array_unique(array_merge($before, $thisrun))));
        }
        $examples = [];
        foreach (array_slice((array)($s['examples'] ?? []), 0, self::MAX_EXAMPLES) as $ex) {
            $ex = self::plain(self::anonymize(is_scalar($ex) ? (string)$ex : ''), self::MAX_EXAMPLE_CHARS);
            if ($ex !== '') {
                $examples[] = $ex;
            }
        }
        $now = time();
        $key = ['kind' => $kind, 'areaid' => $areaid, 'problemtype' => $ptype, 'faqid' => $faqid];
        $pending = $DB->get_record('gmk_tkt_ai_sugg', $key + ['status' => 'pending'], '*', IGNORE_MULTIPLE);
        if (!$pending) {
            $recent = $DB->get_records_select('gmk_tkt_ai_sugg',
                'kind = :kind AND areaid = :areaid AND problemtype = :problemtype AND faqid = :faqid AND timeresolved > :since',
                $key + ['since' => $now - self::DISMISS_QUIET_DAYS * DAYSECS], 'id DESC', 'id, status', 0, 1);
            $last = reset($recent);
            if ($last && $last->status === 'dismissed') {
                throw new invalid_parameter_exception('descartada hace menos de ' . self::DISMISS_QUIET_DAYS . ' días');
            }
            if ($last && $last->status === 'added' && $kind === 'faq_new') {
                throw new invalid_parameter_exception('ya se añadió un problema común para ese tipo');
            }
        }
        if ($pending) {
            $tids = array_values(array_unique(array_merge(array_map('intval', (array)json_decode((string)$pending->ticketids, true)),
                $tids)));
            $examples = array_slice(array_values(array_unique(array_merge($examples,
                (array)json_decode((string)$pending->examples, true)))), 0, self::MAX_EXAMPLES);
        }
        $row = (object)[
            'runid' => $runid, 'kind' => $kind, 'areaid' => $areaid, 'categoryid' => $categoryid, 'faqid' => $faqid,
            'problemtype' => $ptype, 'title' => $title, 'body' => $body, 'links' => json_encode($links), 'audience' => $audience,
            'rationale' => self::plain(($s['rationale'] ?? ''), 2000),
            'ticketids' => json_encode($tids), 'evidence' => count($tids), 'examples' => json_encode($examples),
            'stats' => json_encode(self::evidence_stats($tids, $faqid)),
            'timemodified' => $now,
        ];
        if ($dryrun) {
            return $pending ? 'updated' : 'new';
        }
        if ($pending) {
            $row->id = $pending->id;
            $DB->update_record('gmk_tkt_ai_sugg', $row);
            return 'updated';
        }
        $row->status = 'pending';
        $row->resultid = 0;
        $row->resolvedby = 0;
        $row->timeresolved = 0;
        $row->timecreated = $now;
        $DB->insert_record('gmk_tkt_ai_sugg', $row);
        return 'new';
    }

    private static function evidence_stats(array $tids, int $faqid): array {
        global $DB;
        $out = ['rated' => 0, 'avgrating' => null, 'solvedpct' => null, 'reopened' => 0];
        if ($tids) {
            [$in, $params] = $DB->get_in_or_equal($tids);
            $r = $DB->get_record_sql("SELECT SUM(CASE WHEN rating > 0 THEN 1 ELSE 0 END) AS rated,
                                             AVG(CASE WHEN rating > 0 THEN rating END) AS avgrating,
                                             SUM(CASE WHEN rating_solved = 1 THEN 1 ELSE 0 END) AS yes,
                                             SUM(CASE WHEN rating_solved IN (1, 2) THEN 1 ELSE 0 END) AS answered,
                                             SUM(CASE WHEN reopened_count > 0 THEN 1 ELSE 0 END) AS reopened
                                        FROM {gmk_tkt_ticket} WHERE id $in", $params);
            $out['rated'] = (int)$r->rated;
            $out['avgrating'] = $r->avgrating !== null ? round((float)$r->avgrating, 1) : null;
            $out['solvedpct'] = (int)$r->answered > 0 ? (int)round(100 * (int)$r->yes / (int)$r->answered) : null;
            $out['reopened'] = (int)$r->reopened;
        }
        if ($faqid && ($f = $DB->get_record('gmk_tkt_faq', ['id' => $faqid]))) {
            $out['faq'] = ['views' => (int)$f->views, 'solved' => (int)$f->solved, 'notsolved' => (int)$f->notsolved];
        }
        return $out;
    }

    private static function slug($value): string {
        $value = self::fold(trim(is_scalar($value) ? (string)$value : ''));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
        return \core_text::substr(trim($value, '_'), 0, 100);
    }

    /**
     * Untrusted value to plain text: non-scalars become '', tags are stripped, length capped.
     */
    private static function plain($value, int $max, bool $multiline = false): string {
        $value = is_scalar($value) ? (string)$value : '';
        $value = clean_param(str_replace("\r\n", "\n", $value), PARAM_TEXT);
        $value = $multiline ? trim($value) : trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        return \core_text::substr($value, 0, $max);
    }

    // ---------------------------------------------------------------------
    // 4. Settings page: list and one-click actions
    // ---------------------------------------------------------------------

    public static function export_ui(): array {
        global $DB;
        $allowed = self::allowed_areas();
        $faqs = $DB->get_records('gmk_tkt_faq');
        $sugg = [];
        $rows = $DB->get_records_select('gmk_tkt_ai_sugg', "status = 'pending' OR timeresolved > ?", [time() - 30 * DAYSECS],
            'evidence DESC, id DESC', '*', 0, 300);
        $users = manager::load_users(array_map(function($r) {
            return (int)$r->resolvedby;
        }, $rows));
        foreach ($rows as $r) {
            $f = $r->faqid && isset($faqs[$r->faqid]) ? $faqs[$r->faqid] : null;
            $sugg[] = [
                'id' => (int)$r->id, 'kind' => $r->kind, 'kindlabel' => self::KIND_LABELS[$r->kind] ?? $r->kind,
                'areaid' => (int)$r->areaid, 'categoryid' => (int)$r->categoryid, 'faqid' => (int)$r->faqid,
                'problemtype' => $r->problemtype, 'title' => $r->title, 'body' => (string)$r->body,
                'bodyhtml' => faq::render((string)$r->body), 'links' => faq::links((string)$r->links), 'audience' => $r->audience,
                'rationale' => (string)$r->rationale, 'evidence' => (int)$r->evidence,
                'examples' => (array)json_decode((string)$r->examples, true),
                'stats' => json_decode((string)$r->stats, true) ?: new stdClass(),
                'current' => $f ? ['problem' => $f->problem, 'answer' => (string)$f->answer,
                    'answerhtml' => faq::render((string)$f->answer), 'links' => faq::links((string)$f->links),
                    'views' => (int)$f->views, 'solved' => (int)$f->solved, 'notsolved' => (int)$f->notsolved] : null,
                'status' => $r->status, 'resultid' => (int)$r->resultid,
                'resolvedby' => isset($users[(int)$r->resolvedby]) ? fullname($users[(int)$r->resolvedby]) : '',
                'timeresolved' => (int)$r->timeresolved, 'timecreated' => (int)$r->timecreated,
            ];
        }
        $runs = [];
        foreach ($DB->get_records('gmk_tkt_ai_run', null, 'id DESC', '*', 0, 20) as $r) {
            $runs[] = ['id' => (int)$r->id, 'runkey' => $r->runkey, 'model' => $r->model, 'periodfrom' => (int)$r->periodfrom,
                'periodto' => (int)$r->periodto, 'tickets' => (int)$r->tickets, 'classified' => (int)$r->classified,
                'skipped' => (int)$r->skipped, 'suggestions' => (int)$r->suggestions, 'tokensin' => (int)$r->tokensin,
                'tokensout' => (int)$r->tokensout, 'costusd' => $r->costusd !== null ? (float)$r->costusd : null,
                'summary' => (string)$r->summary, 'status' => $r->status, 'timecreated' => (int)$r->timecreated];
        }
        return [
            'areas' => array_values(array_map(function($a) {
                return ['id' => (int)$a->id, 'name' => $a->name];
            }, $allowed)),
            'suggestions' => $sugg,
            'runs' => $runs,
            'taxonomy' => self::taxonomy(),
            'lastexport' => json_decode((string)get_config('local_grupomakro_core', 'tkt_ai_last_export'), true) ?: null,
            'canned' => array_values(array_map(function($c) {
                return ['id' => (int)$c->id, 'areaid' => (int)$c->areaid, 'title' => $c->title, 'body' => (string)$c->body];
            }, $DB->get_records('gmk_tkt_canned', null, 'areaid, title'))),
        ];
    }

    /**
     * Frequency by area and problem type: last 4 weeks and total, how they
     * were resolved, the agent's "resolved" judgement and the student's rating.
     */
    public static function taxonomy(): array {
        global $DB;
        $since = time() - 28 * DAYSECS;
        $rows = $DB->get_records_sql("SELECT MIN(id) AS id, areaid, problemtype, MAX(problemlabel) AS problemlabel,
                                             COUNT(1) AS total,
                                             SUM(CASE WHEN timeclosed >= :since THEN 1 ELSE 0 END) AS recent,
                                             SUM(CASE WHEN resolved = 1 THEN 1 ELSE 0 END) AS resolved,
                                             AVG(CASE WHEN rating > 0 THEN rating END) AS avgrating,
                                             SUM(CASE WHEN faqid > 0 THEN 1 ELSE 0 END) AS triedfaq
                                        FROM {gmk_tkt_ai_class}
                                    GROUP BY areaid, problemtype
                                    ORDER BY areaid, total DESC", ['since' => $since]);
        // Last 8 weeks (index 0 = the current week), counted by close date.
        $weeks = [];
        $monday = strtotime('monday this week', time());
        $rs = $DB->get_recordset_select('gmk_tkt_ai_class', 'timeclosed >= ?', [$monday - 7 * WEEKSECS], '',
            'id, areaid, problemtype, timeclosed');
        foreach ($rs as $c) {
            $i = (int)$c->timeclosed >= $monday ? 0 : (int)floor(($monday - (int)$c->timeclosed) / WEEKSECS) + 1;
            if ($i < 8) {
                $key = $c->areaid . '|' . $c->problemtype;
                $weeks[$key] = $weeks[$key] ?? array_fill(0, 8, 0);
                $weeks[$key][$i]++;
            }
        }
        $rs->close();
        $res = [];
        foreach ($DB->get_records_sql("SELECT MIN(id) AS id, areaid, problemtype, resolutiontype, MAX(resolutionlabel) AS label,
                                              COUNT(1) AS n
                                         FROM {gmk_tkt_ai_class}
                                     GROUP BY areaid, problemtype, resolutiontype") as $r) {
            $res[$r->areaid . '|' . $r->problemtype][] = ['label' => $r->label, 'n' => (int)$r->n];
        }
        $out = [];
        foreach ($rows as $r) {
            $how = $res[$r->areaid . '|' . $r->problemtype] ?? [];
            usort($how, function($a, $b) {
                return $b['n'] <=> $a['n'];
            });
            $out[] = ['areaid' => (int)$r->areaid, 'problemtype' => $r->problemtype, 'problemlabel' => $r->problemlabel,
                'weeks' => $weeks[$r->areaid . '|' . $r->problemtype] ?? array_fill(0, 8, 0),
                'total' => (int)$r->total, 'recent' => (int)$r->recent,
                'resolvedpct' => (int)$r->total ? (int)round(100 * (int)$r->resolved / (int)$r->total) : 0,
                'avgrating' => $r->avgrating !== null ? round((float)$r->avgrating, 1) : null,
                'triedfaq' => (int)$r->triedfaq, 'resolutions' => array_slice($how, 0, 3)];
        }
        return $out;
    }

    /**
     * @param string $op add | dismiss | restore
     * @param array|null $edited fields changed by the person before adding ("Editar y añadir")
     * @return int id of the created/updated item (faq or canned), 0 otherwise
     */
    public static function action(int $id, string $op, ?array $edited, int $actorid): int {
        global $DB;
        $s = $DB->get_record('gmk_tkt_ai_sugg', ['id' => $id], '*', MUST_EXIST);
        if (!in_array($op, ['add', 'dismiss', 'restore'], true)) {
            throw new invalid_parameter_exception('op');
        }
        if ($op === 'restore') {
            if ($s->status !== 'dismissed') {
                throw new invalid_parameter_exception('Solo se recupera una sugerencia descartada');
            }
            self::resolve($s, 'pending', 0, 0);
            manager::log_event(0, 'ai_suggestion_restore', 'dismissed', (string)$s->id, $actorid);
            return 0;
        }
        if ($s->status !== 'pending') {
            throw new invalid_parameter_exception('Esa sugerencia ya fue atendida');
        }
        if ($op === 'dismiss') {
            self::resolve($s, 'dismissed', 0, $actorid);
            manager::log_event(0, 'ai_suggestion_dismiss', '', (string)$s->id, $actorid, ['kind' => $s->kind]);
            return 0;
        }
        $transaction = $DB->start_delegated_transaction();
        $e = $edited ?: [];
        if ($s->kind === 'canned') {
            $new = settings::apply('canned', 'save', [
                'areaid' => (int)$s->areaid,
                'title' => $e['title'] ?? $s->title,
                'body' => $e['body'] ?? $s->body,
            ], $actorid);
        } else {
            $data = [
                'areaid' => (int)$s->areaid, 'categoryid' => (int)($e['categoryid'] ?? $s->categoryid),
                'problem' => $e['problem'] ?? $s->title, 'answer' => $e['answer'] ?? $s->body,
                'links' => $e['links'] ?? faq::links((string)$s->links), 'audience' => $e['audience'] ?? $s->audience,
                'sortorder' => (int)($e['sortorder'] ?? 50), 'active' => array_key_exists('active', $e) ? (int)!empty($e['active']) : 1,
            ];
            if ($s->kind === 'faq_improve') {
                $f = $DB->get_record('gmk_tkt_faq', ['id' => $s->faqid], '*', MUST_EXIST);
                $data['id'] = (int)$f->id;
                $data['areaid'] = (int)$f->areaid;
                $data['sortorder'] = (int)($e['sortorder'] ?? $f->sortorder);
                $data['active'] = array_key_exists('active', $e) ? (int)!empty($e['active']) : (int)$f->active;
                $data['categoryid'] = (int)($e['categoryid'] ?? $f->categoryid);
            }
            $new = settings::apply('faq', 'save', $data, $actorid);
        }
        $resultid = is_object($new) && isset($new->id) ? (int)$new->id : 0;
        self::resolve($s, 'added', $resultid, $actorid);
        manager::log_event(0, 'ai_suggestion_add', '', (string)$s->id, $actorid,
            ['kind' => $s->kind, 'resultid' => $resultid, 'edited' => $edited ? 1 : 0]);
        $transaction->allow_commit();
        return $resultid;
    }

    private static function resolve(stdClass $s, string $status, int $resultid, int $actorid): void {
        global $DB;
        $DB->update_record('gmk_tkt_ai_sugg', (object)['id' => $s->id, 'status' => $status, 'resultid' => $resultid,
            'resolvedby' => $actorid, 'timeresolved' => $status === 'pending' ? 0 : time(), 'timemodified' => time()]);
    }
}
