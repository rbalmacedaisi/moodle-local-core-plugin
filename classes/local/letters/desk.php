<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Cartas (20261001130): bandeja del personal (pages/letter_desk.php).
 *
 * Pestanas: mine, unassigned, overdue, pickup, legacy, open, closed, all.
 * Las acciones solo se ofrecen si son validas para el estado. Las masivas
 * procesan cada carta por separado (un fallo no tumba las demas) y devuelven
 * el resultado de cada una.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\letters;

use context_system;
use moodle_exception;
use stdClass;

defined('MOODLE_INTERNAL') || die();

class desk {

    const TABS = ['mine', 'unassigned', 'overdue', 'pickup', 'legacy', 'open', 'closed', 'all'];

    const BULK_ACTIONS = ['take', 'assign', 'ready_pickup', 'delivered', 'handled', 'keep', 'cancel'];

    /** Frequent reasons offered when rejecting / cancelling. */
    const REASONS = [
        'Solicitud duplicada',
        'No cumple los requisitos para esta carta',
        'Datos personales incompletos o desactualizados',
        'Tiene saldo pendiente con Finanzas',
        'El estudiante pidió anularla',
    ];

    public static function require_manager(int $userid): void {
        require_capability('local/grupomakro_core:managerequests', context_system::instance(), $userid);
    }

    /**
     * @return array [where, params] for $tab and filters
     */
    private static function where(int $userid, string $tab, array $f): array {
        global $DB;
        $params = [];
        [$open, $oparams] = $DB->get_in_or_equal(manager::OPEN_STATUSES, SQL_PARAMS_NAMED, 'op');
        [$closed, $cparams] = $DB->get_in_or_equal(manager::OPEN_STATUSES, SQL_PARAMS_NAMED, 'cl', false);
        switch ($tab) {
            case 'mine':
                $w = ["r.status $open", 'r.assignedto = :me'];
                $params = $oparams + ['me' => $userid];
                break;
            case 'unassigned':
                $w = ["r.status $open", 'r.assignedto = 0', "r.status <> 'pendiente_pago'"];
                $params = $oparams;
                break;
            case 'overdue':
                $w = ["r.status $open", 'r.sla_level >= 2'];
                $params = $oparams;
                break;
            case 'pickup':
                $w = ["r.status = 'pendiente_recoleccion'"];
                break;
            case 'legacy':
                $w = ["r.status $open", 'r.legacy = 1'];
                $params = $oparams;
                break;
            case 'closed':
                $w = ["r.status $closed"];
                $params = $cparams;
                break;
            case 'all':
                $w = ['1 = 1'];
                break;
            default:
                $w = ["r.status $open"];
                $params = $oparams;
        }
        if (!empty($f['lettertypeid'])) {
            $w[] = 'r.lettertypeid = :flt';
            $params['flt'] = (int)$f['lettertypeid'];
        }
        if (!empty($f['status'])) {
            $w[] = 'r.status = :fst';
            $params['fst'] = (string)$f['status'];
        }
        // assignedto: -1 = todos, 0 = sin asignar, >0 = esa persona.
        if (isset($f['assignedto']) && $f['assignedto'] !== '' && (int)$f['assignedto'] >= 0) {
            $w[] = 'r.assignedto = :fas';
            $params['fas'] = (int)$f['assignedto'];
        }
        if (!empty($f['from'])) {
            $w[] = 'r.timecreated >= :ffrom';
            $params['ffrom'] = (int)$f['from'];
        }
        if (!empty($f['to'])) {
            $w[] = 'r.timecreated <= :fto';
            $params['fto'] = (int)$f['to'];
        }
        $search = trim((string)($f['search'] ?? ''));
        if ($search !== '') {
            $s = [];
            if (preg_match('/^#?(\d+)$/', $search, $m)) {
                $s[] = 'r.id = :sid';
                $params['sid'] = (int)$m[1];
            }
            $s[] = $DB->sql_like($DB->sql_concat('u.firstname', "' '", 'u.lastname'), ':sname', false, false);
            $params['sname'] = '%' . $DB->sql_like_escape($search) . '%';
            $s[] = $DB->sql_like('u.email', ':smail', false, false);
            $params['smail'] = '%' . $DB->sql_like_escape($search) . '%';
            $fieldid = (int)$DB->get_field('user_info_field', 'id', ['shortname' => 'documentnumber']);
            if ($fieldid > 0) {
                $s[] = "EXISTS (SELECT 1 FROM {user_info_data} d WHERE d.userid = u.id AND d.fieldid = :sfid AND "
                    . $DB->sql_like('d.data', ':sdoc', false, false) . ')';
                $params['sfid'] = $fieldid;
                $params['sdoc'] = '%' . $DB->sql_like_escape($search) . '%';
            }
            $w[] = '(' . implode(' OR ', $s) . ')';
        }
        return [implode(' AND ', $w), $params];
    }

    /**
     * Listing with tab counts.
     */
    public static function listing(int $userid, string $tab, array $filters = [], int $page = 0, int $perpage = 30): array {
        global $DB;
        self::require_manager($userid);
        if (!in_array($tab, self::TABS, true)) {
            $tab = 'mine';
        }
        $counts = [];
        foreach (self::TABS as $t) {
            if (in_array($t, ['closed', 'all'], true)) {
                continue;
            }
            [$w, $p] = self::where($userid, $t, []);
            $counts[$t] = (int)$DB->count_records_sql("SELECT COUNT(1) FROM {gmk_letter_request} r
                JOIN {user} u ON u.id = r.userid WHERE $w", $p);
        }
        [$where, $params] = self::where($userid, $tab, $filters);
        $total = (int)$DB->count_records_sql("SELECT COUNT(1) FROM {gmk_letter_request} r
            JOIN {user} u ON u.id = r.userid WHERE $where", $params);
        $order = in_array($tab, ['closed', 'all'], true)
            ? 'r.timemodified DESC, r.id DESC'
            : 'r.sla_level DESC, CASE WHEN r.due_at = 0 THEN 1 ELSE 0 END, r.due_at ASC, r.id ASC';
        $perpage = max(1, min(100, $perpage));
        $rows = $DB->get_records_sql("SELECT r.*, t.name AS typename, t.pickup_note
                  FROM {gmk_letter_request} r
                  JOIN {user} u ON u.id = r.userid
                  JOIN {gmk_letter_type} t ON t.id = r.lettertypeid
                 WHERE $where
              ORDER BY $order", $params, max(0, $page) * $perpage, $perpage);
        return ['items' => self::export_rows($rows, $userid), 'total' => $total, 'counts' => $counts, 'tab' => $tab,
            'page' => max(0, $page), 'perpage' => $perpage];
    }

    /**
     * @return array exported rows
     */
    public static function export_rows(array $rows, int $userid): array {
        $ids = array_keys($rows);
        $users = [];
        foreach ($rows as $r) {
            $users[] = (int)$r->userid;
            $users[] = (int)$r->assignedto;
        }
        $names = manager::user_names($users);
        $docs = manager::latest_documents($ids);
        $tickets = tickets::summaries($ids, $userid, false);
        $docnumbers = self::document_numbers(array_map(function($r) {
            return (int)$r->userid;
        }, $rows));
        $labels = manager::get_status_labels();
        $now = time();
        $out = [];
        foreach ($rows as $r) {
            $doc = $docs[(int)$r->id] ?? null;
            $since = (int)$r->status_since ?: (int)$r->timemodified;
            $isopen = in_array((string)$r->status, manager::OPEN_STATUSES, true);
            $out[] = [
                'id' => (int)$r->id,
                'userid' => (int)$r->userid,
                'studentname' => $names[(int)$r->userid] ?? '',
                'documentnumber' => $docnumbers[(int)$r->userid] ?? '',
                'lettertypeid' => (int)$r->lettertypeid,
                'typename' => format_string((string)$r->typename),
                'status' => (string)$r->status,
                'statuslabel' => (string)($labels[$r->status] ?? $r->status),
                'deliverymode' => (string)$r->deliverymode_snapshot,
                'cost' => (float)$r->cost_snapshot,
                'observation' => (string)($r->observation ?? ''),
                'assignedto' => (int)$r->assignedto,
                'assignedname' => (int)$r->assignedto > 0 ? ($names[(int)$r->assignedto] ?? '') : '',
                'status_since' => $since,
                'waiting' => $isopen ? sla::format_business(\local_grupomakro_core\local\support\business_hours::elapsed(
                    $since, $now, team::area())) : '',
                'due_at' => $isopen ? (int)$r->due_at : 0,
                'sla_level' => $isopen ? (int)$r->sla_level : 0,
                'legacy' => (int)$r->legacy,
                'document_available' => $doc ? 1 : 0,
                'document_filename' => $doc ? (string)$doc->filename : '',
                'document_source' => $doc ? (string)($doc->source ?? 'generated') : '',
                'ticketid' => (int)($tickets[(int)$r->id]['id'] ?? 0),
                'ticketnumber' => (string)($tickets[(int)$r->id]['number'] ?? ''),
                'ticketstatus' => (string)($tickets[(int)$r->id]['statuslabel'] ?? ''),
                'ticketopen' => !empty($tickets[(int)$r->id]['isopen']),
                'ticketunread' => (int)($tickets[(int)$r->id]['unread'] ?? 0),
                'actions' => self::actions($r, $userid, $doc !== null),
                'timecreated' => (int)$r->timecreated,
                'timemodified' => (int)$r->timemodified,
            ];
        }
        return $out;
    }

    /**
     * Actions valid now for $r.
     *
     * @return string[]
     */
    public static function actions(stdClass $r, int $userid, bool $hasdoc): array {
        $status = (string)$r->status;
        $next = manager::allowed_next($status);
        $out = ['note', 'message'];
        $open = in_array($status, manager::OPEN_STATUSES, true);
        if ($open && $status !== manager::STATUS_PENDIENTE_PAGO) {
            if ((int)$r->assignedto !== $userid) {
                $out[] = 'take';
            }
            $out[] = 'assign';
        }
        if (!empty($r->legacy) && $open) {
            $out[] = 'handled';
            $out[] = 'keep';
        }
        if (array_intersect($next, [manager::STATUS_GENERADA_DIGITAL, manager::STATUS_PENDIENTE_RECOLECCION,
                manager::STATUS_ENTREGADA]) || $status === manager::STATUS_GENERADA_DIGITAL) {
            $out[] = 'upload';
        }
        if (in_array($status, [manager::STATUS_PENDIENTE_GESTION, manager::STATUS_PAGADA], true)) {
            $out[] = 'generate';
        }
        if (in_array(manager::STATUS_PENDIENTE_RECOLECCION, $next, true)) {
            $out[] = 'ready_pickup';
        }
        if (in_array(manager::STATUS_ENTREGADA, $next, true)) {
            $out[] = 'delivered';
        }
        if (in_array(manager::STATUS_RECHAZADA, $next, true)) {
            $out[] = 'reject';
        }
        if (in_array(manager::STATUS_CANCELADA, $next, true)) {
            $out[] = 'cancel';
        }
        if ($hasdoc) {
            $out[] = 'download';
        }
        if (is_siteadmin($userid)) {
            $out[] = 'delete';
        }
        return $out;
    }

    /**
     * Detail: row + student context + history + documents.
     */
    public static function detail(int $requestid, int $userid): array {
        global $DB;
        self::require_manager($userid);
        $r = $DB->get_record_sql("SELECT r.*, t.name AS typename, t.pickup_note
                                    FROM {gmk_letter_request} r JOIN {gmk_letter_type} t ON t.id = r.lettertypeid
                                   WHERE r.id = :id", ['id' => $requestid], MUST_EXIST);
        $row = self::export_rows([(int)$r->id => $r], $userid)[0];
        $user = \core_user::get_user((int)$r->userid);
        $plans = $DB->get_records_sql("SELECT llu.id, lp.name AS plan, llu.status, p.name AS period
                  FROM {local_learning_users} llu
                  JOIN {local_learning_plans} lp ON lp.id = llu.learningplanid
             LEFT JOIN {local_learning_periods} p ON p.id = llu.currentperiodid
                 WHERE llu.userid = :u AND llu.userrolename = 'student'", ['u' => (int)$r->userid]);
        $careers = [];
        foreach ($plans as $p) {
            $careers[] = trim(format_string((string)$p->plan) . ($p->period ? ' · ' . format_string($p->period) : '')
                . ($p->status ? ' · ' . $p->status : ''));
        }
        $financial = (string)$DB->get_field('gmk_financial_status', 'status', ['userid' => (int)$r->userid],
            IGNORE_MULTIPLE);
        $events = $DB->get_records('gmk_letter_request_event', ['requestid' => $requestid], 'id DESC', '*', 0, 100);
        $names = manager::user_names(array_map(function($e) {
            return (int)$e->usermodified;
        }, $events));
        $labels = manager::get_status_labels();
        $history = [];
        foreach ($events as $e) {
            $history[] = [
                'type' => (string)$e->eventtype,
                'text' => self::event_text($e, $labels),
                'actor' => (int)$e->usermodified > 0 ? ($names[(int)$e->usermodified] ?? '') : 'Sistema',
                'time' => (int)$e->timecreated,
            ];
        }
        $documents = [];
        foreach ($DB->get_records('gmk_letter_document', ['requestid' => $requestid], 'versionno DESC') as $d) {
            $documents[] = ['id' => (int)$d->id, 'version' => (int)$d->versionno, 'filename' => (string)$d->filename,
                'source' => (string)($d->source ?? 'generated'), 'size' => (int)$d->filesize, 'time' => (int)$d->timecreated,
                'odoo' => !empty($d->odoo_attachment_id)];
        }
        return $row + [
            'email' => $user ? (string)$user->email : '',
            'careers' => implode("\n", $careers),
            'financial' => $financial,
            'rejection_reason' => (string)($r->rejection_reason ?? ''),
            'cancel_reason' => (string)($r->cancel_reason ?? ''),
            'pickup_note' => (string)($r->pickup_note ?? ''),
            'invoice_number' => (string)($r->invoice_number ?? ''),
            'history' => $history,
            'documents' => $documents,
            'reasons' => self::REASONS,
        ];
    }

    private static function event_text(stdClass $e, array $labels): string {
        $msg = trim((string)$e->message);
        switch ((string)$e->eventtype) {
            case 'status_change':
                return ($e->oldstatus ? ($labels[$e->oldstatus] ?? $e->oldstatus) . ' → ' : '')
                    . ($labels[$e->newstatus] ?? $e->newstatus) . ($msg !== '' ? ': ' . $msg : '');
            case 'request_created':
                return 'Solicitud creada';
            case 'note':
                return 'Nota interna: ' . $msg;
            default:
                return $msg !== '' ? $msg : (string)$e->eventtype;
        }
    }

    /**
     * One action. $data: targetuserid, reason, note, body.
     *
     * @return array ['request' => row, 'ticketid' => int]
     */
    public static function action(int $requestid, int $userid, string $action, array $data = []): array {
        global $DB;
        self::require_manager($userid);
        $request = $DB->get_record('gmk_letter_request', ['id' => $requestid], '*', MUST_EXIST);
        $hasdoc = $DB->record_exists('gmk_letter_document', ['requestid' => $requestid]);
        if (!in_array($action, self::actions($request, $userid, $hasdoc), true)) {
            throw new moodle_exception('letter_err_action', 'local_grupomakro_core', '', $action);
        }
        $reason = \core_text::substr(trim(clean_param((string)($data['reason'] ?? ''), PARAM_TEXT)), 0, 1000);
        $ticketid = 0;
        switch ($action) {
            case 'take':
                manager::assign($requestid, $userid, $userid, 'take');
                break;
            case 'assign':
                manager::assign($requestid, (int)($data['targetuserid'] ?? 0), $userid, 'manual');
                break;
            case 'note':
                $note = \core_text::substr(trim(clean_param((string)($data['note'] ?? ''), PARAM_TEXT)), 0, 2000);
                if ($note === '') {
                    throw new moodle_exception('letter_err_reason', 'local_grupomakro_core');
                }
                manager::log('note', $requestid, (string)$request->status, (string)$request->status, $note, $userid);
                break;
            case 'message':
                $body = trim((string)($data['body'] ?? ''));
                if ($body === '') {
                    throw new moodle_exception('tkt_err_empty', 'local_grupomakro_core');
                }
                $ticketid = (int)tickets::write_as_staff($requestid, $userid, $body)->id;
                break;
            case 'generate':
                // Como la bandeja vieja: digital -> lista para descargar; fisica -> sigue en gestion
                // (despues "Lista para retirar").
                self::ensure_owner($request, $userid);
                manager::generate_document_for_request($requestid, $userid);
                if ((string)$request->deliverymode_snapshot === manager::DELIVERY_DIGITAL) {
                    manager::set_request_status($requestid, manager::STATUS_GENERADA_DIGITAL, $userid,
                        'Documento generado desde la plantilla');
                } else if ((string)$request->status === manager::STATUS_PAGADA) {
                    manager::set_request_status($requestid, manager::STATUS_PENDIENTE_GESTION, $userid,
                        'Documento generado desde la plantilla', [], ['notify' => false]);
                }
                break;
            case 'ready_pickup':
                self::ensure_owner($request, $userid);
                manager::set_request_status($requestid, manager::STATUS_PENDIENTE_RECOLECCION, $userid,
                    $reason !== '' ? $reason : 'Lista para retirar');
                break;
            case 'delivered':
                self::ensure_owner($request, $userid);
                manager::set_request_status($requestid, manager::STATUS_ENTREGADA, $userid,
                    $reason !== '' ? 'Entregada: ' . $reason : 'Entregada');
                break;
            case 'handled':
                // Anterior a la bandeja y ya gestionada por fuera (pedido de Ramiro, 19-nov-2026).
                self::ensure_owner($request, $userid);
                $target = in_array(manager::STATUS_ENTREGADA, manager::allowed_next((string)$request->status), true)
                    ? manager::STATUS_ENTREGADA : manager::STATUS_GENERADA_DIGITAL;
                manager::set_request_status($requestid, $target, $userid,
                    'Ya se había gestionado fuera del sistema' . ($reason !== '' ? ': ' . $reason : ''), ['legacy' => 1]);
                break;
            case 'keep':
                $DB->set_field('gmk_letter_request', 'legacy', 0, ['id' => $requestid]);
                self::ensure_owner($request, $userid);
                manager::log('legacy_keep', $requestid, (string)$request->status, (string)$request->status,
                    'No se había gestionado: se gestiona ahora', $userid);
                break;
            case 'reject':
            case 'cancel':
                if ($reason === '') {
                    throw new moodle_exception('letter_err_reason', 'local_grupomakro_core');
                }
                manager::set_request_status($requestid,
                    $action === 'reject' ? manager::STATUS_RECHAZADA : manager::STATUS_CANCELADA, $userid, $reason);
                break;
            case 'delete':
                manager::delete_request_permanently($requestid);
                return ['request' => null, 'ticketid' => 0];
            default:
                throw new moodle_exception('letter_err_action', 'local_grupomakro_core', '', $action);
        }
        return ['request' => self::row($requestid, $userid), 'ticketid' => $ticketid];
    }

    /**
     * Whoever acts on an unassigned letter takes it.
     */
    private static function ensure_owner(stdClass $request, int $userid): void {
        if ((int)$request->assignedto === 0) {
            manager::assign((int)$request->id, $userid, $userid, 'action', false);
        }
    }

    public static function row(int $requestid, int $userid): ?array {
        global $DB;
        $r = $DB->get_record_sql("SELECT r.*, t.name AS typename, t.pickup_note
                                    FROM {gmk_letter_request} r JOIN {gmk_letter_type} t ON t.id = r.lettertypeid
                                   WHERE r.id = :id", ['id' => $requestid]);
        return $r ? self::export_rows([(int)$r->id => $r], $userid)[0] : null;
    }

    /**
     * Bulk action. With $dryrun nothing is written: returns what would change
     * and what would be skipped.
     *
     * @return array ['done' => [ids], 'skipped' => [[id, reason]], 'failed' => [[id, error]]]
     */
    public static function bulk(array $ids, int $userid, string $action, array $data = [], bool $dryrun = false): array {
        global $DB;
        self::require_manager($userid);
        if (!in_array($action, self::BULK_ACTIONS, true)) {
            throw new moodle_exception('letter_err_action', 'local_grupomakro_core', '', $action);
        }
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids)))), 0, 200);
        $out = ['done' => [], 'skipped' => [], 'failed' => []];
        foreach ($ids as $id) {
            $request = $DB->get_record('gmk_letter_request', ['id' => $id]);
            if (!$request) {
                $out['skipped'][] = ['id' => $id, 'reason' => 'No existe'];
                continue;
            }
            $hasdoc = $DB->record_exists('gmk_letter_document', ['requestid' => $id]);
            if (!in_array($action, self::actions($request, $userid, $hasdoc), true)) {
                $out['skipped'][] = ['id' => $id, 'reason' => 'No aplica en "' .
                    (manager::get_status_labels()[$request->status] ?? $request->status) . '"'];
                continue;
            }
            if ($dryrun) {
                $out['done'][] = $id;
                continue;
            }
            try {
                self::action($id, $userid, $action, $data);
                $out['done'][] = $id;
            } catch (\Throwable $e) {
                $out['failed'][] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }
        return $out;
    }

    /**
     * People a letter can be assigned to: team members, else managers.
     *
     * @return array [[id, fullname, load]]
     */
    public static function assignable(int $userid): array {
        self::require_manager($userid);
        $ids = team::agents() ?: team::managers();
        $names = manager::user_names($ids);
        $loads = team::loads($ids);
        $out = [];
        foreach ($ids as $id) {
            $out[] = ['id' => $id, 'fullname' => $names[$id] ?? '', 'load' => (int)($loads[$id] ?? 0)];
        }
        usort($out, function($a, $b) {
            return strcmp($a['fullname'], $b['fullname']);
        });
        return $out;
    }

    /**
     * @return array userid => document number
     */
    private static function document_numbers(array $userids): array {
        global $DB;
        $userids = array_values(array_unique(array_filter(array_map('intval', $userids))));
        $fieldid = (int)$DB->get_field('user_info_field', 'id', ['shortname' => 'documentnumber']);
        if (!$userids || $fieldid <= 0) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'dn');
        $params['f'] = $fieldid;
        $out = [];
        foreach ($DB->get_records_sql("SELECT userid, data FROM {user_info_data} WHERE fieldid = :f AND userid $in",
                $params) as $r) {
            $out[(int)$r->userid] = trim((string)$r->data);
        }
        return $out;
    }
}
