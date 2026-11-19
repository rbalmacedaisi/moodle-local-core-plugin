<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Cartas (20261001130): vinculo con la mesa de ayuda.
 *
 * gmk_tkt_ticket.letterrequestid dice de que carta habla un ticket. La
 * conversacion vigente de una carta es su ticket abierto mas reciente; al
 * terminar la carta (lista para descargar, entregada, rechazada o anulada) el
 * ticket pasa a "resuelto" con un mensaje automatico (decision de Ramiro,
 * 19-nov-2026). Los mensajes automaticos usan las plantillas gmk_tkt_template
 * letter_* (editables y desactivables en la configuracion de la mesa).
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\letters;

use local_grupomakro_core\local\support\manager as support;
use moodle_exception;
use stdClass;

defined('MOODLE_INTERNAL') || die();

class tickets {

    /** Letter statuses that end the conversation (ticket -> resolved). */
    const FINAL_STATUSES = ['generada_digital', 'entregada', 'rechazada', 'cancelada'];

    /** Letter status => automatic message template. */
    const TEMPLATES = [
        'pagada' => 'letter_paid',
        'pendiente_gestion' => 'letter_in_progress',
        'generada_digital' => 'letter_ready_download',
        'pendiente_recoleccion' => 'letter_ready_pickup',
        'entregada' => 'letter_delivered',
        'rechazada' => 'letter_rejected',
        'cancelada' => 'letter_cancelled',
    ];

    /** Seed of the templates (setup). */
    const TEMPLATE_SEED = [
        'letter_assigned' => ['Carta: responsable asignado', '{agente} está gestionando tu carta {carta}.'],
        'letter_paid' => ['Carta: pago recibido', 'Recibimos el pago de tu carta {carta}. Estará lista aprox. el {fecha_estimada}.'],
        'letter_in_progress' => ['Carta: en preparación', 'Tu carta {carta} está en preparación. Fecha estimada: {fecha_estimada}.'],
        'letter_ready_download' => ['Carta: lista para descargar', 'Tu carta {carta} está lista. Descárgala desde "Solicitudes de Cartas".'],
        'letter_ready_pickup' => ['Carta: lista para retirar', 'Tu carta {carta} está lista para retirar. {retiro}'],
        'letter_delivered' => ['Carta: entregada', 'Tu carta {carta} fue entregada.'],
        'letter_rejected' => ['Carta: rechazada', 'Tu solicitud de carta {carta} fue rechazada: {motivo}'],
        'letter_cancelled' => ['Carta: anulada', 'Tu solicitud de carta {carta} fue anulada: {motivo}'],
    ];

    public static function category_id(): int {
        $c = team::category();
        return $c ? (int)$c->id : 0;
    }

    /**
     * Latest ticket of the letter ($openonly: only an open one).
     */
    public static function current(int $requestid, bool $openonly = true): ?stdClass {
        global $DB;
        if ($requestid <= 0) {
            return null;
        }
        $params = ['r' => $requestid];
        $where = 'letterrequestid = :r';
        if ($openonly) {
            [$in, $inparams] = $DB->get_in_or_equal(support::open_statuses(), SQL_PARAMS_NAMED, 'st');
            $where .= " AND status $in";
            $params += $inparams;
        }
        $rows = $DB->get_records_select('gmk_tkt_ticket', $where, $params, 'id DESC', '*', 0, 1);
        return $rows ? reset($rows) : null;
    }

    /**
     * Summaries of the latest ticket of each letter, as $viewerid sees them.
     *
     * @return array requestid => [id, number, status, statuslabel, isopen, unread]
     */
    public static function summaries(array $requestids, int $viewerid, bool $asrequester): array {
        global $DB;
        $requestids = array_values(array_unique(array_filter(array_map('intval', $requestids))));
        if (!$requestids) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal($requestids, SQL_PARAMS_NAMED, 'lr');
        $internal = $asrequester ? 'AND um.internal = 0' : '';
        $params['v0'] = $viewerid;
        $params['v1'] = $viewerid;
        $rows = $DB->get_records_sql(
            "SELECT t.id, t.letterrequestid, t.number, t.status,
                    (SELECT COUNT(1) FROM {gmk_tkt_message} um
                      WHERE um.ticketid = t.id AND um.deleted = 0 AND um.senderid <> :v1 $internal
                        AND um.id > COALESCE(rd.lastreadmessageid, 0)) AS unread
               FROM {gmk_tkt_ticket} t
          LEFT JOIN {gmk_tkt_read} rd ON rd.ticketid = t.id AND rd.userid = :v0
              WHERE t.letterrequestid $in
           ORDER BY t.id ASC", $params);
        $labels = $asrequester ? support::REQUESTER_LABELS : support::STAFF_LABELS;
        $out = [];
        foreach ($rows as $t) {
            // Ascending: the last one wins (latest ticket).
            $out[(int)$t->letterrequestid] = [
                'id' => (int)$t->id,
                'number' => (string)$t->number,
                'status' => (string)$t->status,
                'statuslabel' => (string)($labels[$t->status] ?? $t->status),
                'isopen' => support::is_open_status((string)$t->status),
                'unread' => (int)$t->unread,
            ];
        }
        return $out;
    }

    /**
     * Letter summaries for the help desk export.
     *
     * @return array requestid => [id, typename, status, statuslabel, url]
     */
    public static function letters_for(array $requestids): array {
        global $DB;
        $requestids = array_values(array_unique(array_filter(array_map('intval', $requestids))));
        if (!$requestids) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal($requestids);
        $labels = manager::get_status_labels();
        $out = [];
        foreach ($DB->get_records_sql("SELECT r.id, r.status, r.due_at, r.sla_level, t.name
                                         FROM {gmk_letter_request} r
                                         JOIN {gmk_letter_type} t ON t.id = r.lettertypeid
                                        WHERE r.id $in", $params) as $r) {
            $out[(int)$r->id] = [
                'id' => (int)$r->id,
                'typename' => format_string((string)$r->name),
                'status' => (string)$r->status,
                'statuslabel' => (string)($labels[$r->status] ?? $r->status),
                'studentlabel' => manager::student_status_label((string)$r->status),
                'due_at' => (int)$r->due_at,
                'sla_level' => (int)$r->sla_level,
                'url' => (new \moodle_url('/local/grupomakro_core/pages/letter_desk.php', ['request' => (int)$r->id]))
                    ->out(false),
            ];
        }
        return $out;
    }

    /**
     * Agent a new ticket about $requestid should go to: the letter owner when
     * they are a team member, else 0 (normal pick).
     */
    public static function preferred_agent(int $requestid): int {
        global $DB;
        $owner = (int)$DB->get_field('gmk_letter_request', 'assignedto', ['id' => $requestid]);
        return ($owner > 0 && in_array($owner, team::agents(), true)) ? $owner : 0;
    }

    /**
     * Student: "Hablar de esta carta". Reuses the open ticket (posting $body when
     * given) or opens one in "Cartas y constancias".
     */
    public static function open_for_student(int $requestid, int $userid, string $body): stdClass {
        global $DB;
        $request = $DB->get_record('gmk_letter_request', ['id' => $requestid], '*', MUST_EXIST);
        if ((int)$request->userid !== $userid) {
            throw new moodle_exception('nopermissions', 'error', '', 'letter ticket');
        }
        $ticket = self::current($requestid);
        if ($ticket) {
            if (trim($body) !== '') {
                [, $ticket] = support::send((int)$ticket->id, $userid, $body);
            }
            return $ticket;
        }
        if (trim($body) === '') {
            throw new moodle_exception('tkt_err_empty', 'local_grupomakro_core');
        }
        $categoryid = self::category_id();
        if ($categoryid <= 0) {
            throw new moodle_exception('letter_err_noteam', 'local_grupomakro_core');
        }
        return support::create($userid, $categoryid, self::subject($request), $body, 0, 'student', 0, 0, $requestid);
    }

    /**
     * Staff: "Escribir al estudiante". Posts on the open ticket or opens one on
     * behalf of the student with this first message from staff.
     */
    public static function write_as_staff(int $requestid, int $staffid, string $body): stdClass {
        global $DB;
        $request = $DB->get_record('gmk_letter_request', ['id' => $requestid], '*', MUST_EXIST);
        $ticket = self::current($requestid);
        if ($ticket) {
            [, $ticket] = support::send((int)$ticket->id, $staffid, $body);
            return $ticket;
        }
        $categoryid = self::category_id();
        if ($categoryid <= 0) {
            throw new moodle_exception('letter_err_noteam', 'local_grupomakro_core');
        }
        return support::create_by_staff($staffid, (int)$request->userid, $categoryid, self::subject($request), $body,
            $requestid);
    }

    public static function subject(stdClass $request): string {
        global $DB;
        $name = (string)$DB->get_field('gmk_letter_type', 'name', ['id' => (int)$request->lettertypeid]);
        return 'Carta #' . (int)$request->id . ' · ' . format_string($name);
    }

    /**
     * Letter changed status: automatic message on its open ticket and, when the
     * letter is finished, the ticket goes to "resuelto". Never throws.
     */
    public static function on_status_change(stdClass $request, string $old, string $new): void {
        try {
            $ticket = self::current((int)$request->id);
            if (!$ticket) {
                return;
            }
            if (isset(self::TEMPLATES[$new])) {
                support::system_message($ticket, self::TEMPLATES[$new], self::vars($request, $ticket));
            }
            if (in_array($new, self::FINAL_STATUSES, true)
                    && support::can_transition((string)$ticket->status, support::S_RESOLVED)) {
                support::set_status($ticket, support::S_RESOLVED, 0, ['resolved_at' => time(), 'waiting_since' => 0]);
                support::log_event((int)$ticket->id, 'letter_finished', $old, $new, 0, ['letter' => (int)$request->id]);
            }
        } catch (\Throwable $e) {
            debugging('letters ticket hook: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Letter assigned: automatic message on its open ticket. Never throws.
     */
    public static function on_assigned(stdClass $request): void {
        try {
            $ticket = self::current((int)$request->id);
            if ($ticket) {
                support::system_message($ticket, 'letter_assigned', self::vars($request, $ticket));
            }
        } catch (\Throwable $e) {
            debugging('letters ticket hook: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    private static function vars(stdClass $request, stdClass $ticket): array {
        global $DB;
        $type = $DB->get_record('gmk_letter_type', ['id' => (int)$request->lettertypeid]);
        $agent = (int)$request->assignedto > 0 ? \core_user::get_user((int)$request->assignedto) : null;
        $reason = (string)($request->status === 'rechazada' ? $request->rejection_reason : $request->cancel_reason);
        // Left operand wins in "+": the letter values override the ticket ones ({agente}).
        return [
            '{carta}' => '#' . (int)$request->id . ($type ? ' (' . format_string($type->name) . ')' : ''),
            '{agente}' => $agent ? fullname($agent) : 'Secretaría Académica',
            '{fecha_estimada}' => (int)$request->due_at > 0
                ? userdate((int)$request->due_at, '%A %d de %B') : 'en los próximos días hábiles',
            '{retiro}' => $type && trim((string)$type->pickup_note) !== '' ? trim((string)$type->pickup_note) : '',
            '{motivo}' => trim($reason) !== '' ? trim($reason) : 'sin motivo indicado',
        ] + support::template_vars($ticket);
    }
}
