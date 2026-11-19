<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_grupomakro_core\local\letters;

defined('MOODLE_INTERNAL') || die();

use context_system;
use core\message\message;
use core_user;
use dml_exception;
use moodle_exception;
use stdClass;
use local_sc_learningplans\local\credit_resolver;

require_once($GLOBALS['CFG']->dirroot . '/local/sc_learningplans/classes/local/credit_resolver.php');

/**
 * Letter request manager.
 */
class manager {
    /** @var string */
    const STATUS_SOLICITADA = 'solicitada';
    /** @var string */
    const STATUS_PENDIENTE_PAGO = 'pendiente_pago';
    /** @var string */
    const STATUS_PAGADA = 'pagada';
    /** @var string */
    const STATUS_GENERADA_DIGITAL = 'generada_digital';
    /** @var string */
    const STATUS_PENDIENTE_GESTION = 'pendiente_gestion';
    /** @var string */
    const STATUS_PENDIENTE_RECOLECCION = 'pendiente_recoleccion';
    /** @var string */
    const STATUS_ENTREGADA = 'entregada';
    /** @var string */
    const STATUS_RECHAZADA = 'rechazada';
    /** @var string */
    const STATUS_CANCELADA = 'cancelada';

    /** @var string */
    const DELIVERY_DIGITAL = 'digital';
    /** @var string */
    const DELIVERY_FISICA = 'fisica';

    /** @var string */
    const GENERATION_AUTO = 'auto';
    /** @var string */
    const GENERATION_MANUAL = 'manual';

    /**
     * Status labels map.
     *
     * @return array
     */
    public static function get_status_labels(): array {
        return [
            self::STATUS_SOLICITADA => get_string('letter_status_solicitada', 'local_grupomakro_core'),
            self::STATUS_PENDIENTE_PAGO => get_string('letter_status_pendiente_pago', 'local_grupomakro_core'),
            self::STATUS_PAGADA => get_string('letter_status_pagada', 'local_grupomakro_core'),
            self::STATUS_GENERADA_DIGITAL => get_string('letter_status_generada_digital', 'local_grupomakro_core'),
            self::STATUS_PENDIENTE_GESTION => get_string('letter_status_pendiente_gestion', 'local_grupomakro_core'),
            self::STATUS_PENDIENTE_RECOLECCION => get_string('letter_status_pendiente_recoleccion', 'local_grupomakro_core'),
            self::STATUS_ENTREGADA => get_string('letter_status_entregada', 'local_grupomakro_core'),
            self::STATUS_RECHAZADA => get_string('letter_status_rechazada', 'local_grupomakro_core'),
            self::STATUS_CANCELADA => get_string('letter_status_cancelada', 'local_grupomakro_core'),
        ];
    }

    /**
     * Gets active letter types.
     *
     * @return array
     */
    public static function get_active_letter_types(): array {
        global $DB;
        $records = $DB->get_records('gmk_letter_type', ['active' => 1], 'name ASC');
        $result = [];
        foreach ($records as $record) {
            $datasets = self::get_datasets_for_letter_type((int)$record->id);
            $result[] = [
                'id' => (int)$record->id,
                'code' => (string)$record->code,
                'name' => (string)$record->name,
                'warningtext' => (string)($record->warningtext ?? ''),
                'cost' => (float)$record->cost,
                'active' => (int)$record->active,
                'deliverymode' => (string)$record->deliverymode,
                'generationmode' => (string)$record->generationmode,
                'autostamp' => (int)$record->autostamp,
                'autosignature' => (int)$record->autosignature,
                'odoo_product_id' => (int)($record->odoo_product_id ?? 0),
                'datasetcodes' => array_values(array_map(function($d) {
                    return $d['code'];
                }, $datasets)),
            ];
        }
        return $result;
    }

    /**
     * Gets datasets linked to a letter type.
     *
     * @param int $lettertypeid
     * @return array
     */
    public static function get_datasets_for_letter_type(int $lettertypeid): array {
        global $DB;
        $sql = "SELECT d.id, d.code, d.name, d.description, td.sortorder
                  FROM {gmk_letter_type_dataset} td
                  JOIN {gmk_letter_dataset_def} d ON d.id = td.datasetdefid
                 WHERE td.lettertypeid = :lettertypeid
                   AND d.enabled = 1
              ORDER BY td.sortorder ASC, d.name ASC";
        $records = $DB->get_records_sql($sql, ['lettertypeid' => $lettertypeid]);
        $result = [];
        foreach ($records as $record) {
            $result[] = [
                'id' => (int)$record->id,
                'code' => (string)$record->code,
                'name' => (string)$record->name,
                'description' => (string)($record->description ?? ''),
                'sortorder' => (int)$record->sortorder,
            ];
        }
        return $result;
    }

    /**
     * Creates a new letter request and executes automatic flow.
     *
     * @param int $userid
     * @param int $lettertypeid
     * @param string $observation
     * @return array
     * @throws dml_exception
     * @throws moodle_exception
     */
    public static function create_request(int $userid, int $lettertypeid, string $observation = ''): array {
        global $DB;

        $lettertype = $DB->get_record('gmk_letter_type', ['id' => $lettertypeid, 'active' => 1], '*', IGNORE_MISSING);
        if (!$lettertype) {
            throw new moodle_exception('letter_type_not_found', 'local_grupomakro_core');
        }
        // Una abierta por estudiante y tipo (20261001130): antes se repetian hasta 6
        // veces porque nadie las atendia.
        [$openin, $openparams] = $DB->get_in_or_equal(self::OPEN_STATUSES, SQL_PARAMS_NAMED, 'os');
        $existing = $DB->get_records_select('gmk_letter_request',
            "userid = :u AND lettertypeid = :t AND status $openin",
            ['u' => $userid, 't' => $lettertypeid] + $openparams, 'id DESC', 'id', 0, 1);
        if ($existing) {
            throw new moodle_exception('letter_err_duplicate', 'local_grupomakro_core', '', (int)reset($existing)->id);
        }
        $observation = \core_text::substr(trim($observation), 0, 2000);

        $now = time();
        $request = (object)([
            'userid' => $userid,
            'lettertypeid' => $lettertypeid,
            'status' => self::STATUS_SOLICITADA,
            'observation' => $observation,
            'warning_snapshot' => (string)($lettertype->warningtext ?? ''),
            'cost_snapshot' => (float)$lettertype->cost,
            'deliverymode_snapshot' => (string)$lettertype->deliverymode,
            'generationmode_snapshot' => (string)$lettertype->generationmode,
            'invoice_id' => null,
            'invoice_number' => null,
            'payment_link' => null,
            'paid_at' => null,
            'rejection_reason' => null,
            'cancel_reason' => null,
            'extra_data' => null,
            'usermodified' => $userid,
            'timecreated' => $now,
            'timemodified' => $now,
        ] + sla::enter_fields($lettertype, self::STATUS_SOLICITADA, $now));

        if ((float)$lettertype->cost > 0) {
            // T2: o queda en pendiente_pago con su factura, o no queda nada (si Odoo
            // falla, la transaccion se revierte y el estudiante ve el error).
            $transaction = $DB->start_delegated_transaction();
            try {
                $requestid = $DB->insert_record('gmk_letter_request', $request);
                self::add_event($requestid, null, self::STATUS_SOLICITADA, 'request_created', 'Request created by student',
                    $userid);
                $request = $DB->get_record('gmk_letter_request', ['id' => $requestid], '*', MUST_EXIST);
                $invoice = self::create_invoice_for_request($request, $lettertype, $userid);
                $request->invoice_id = (string)($invoice['invoice_id'] ?? '');
                $request->invoice_number = (string)($invoice['invoice_number'] ?? '');
                $request->payment_link = (string)($invoice['payment_link'] ?? '');
                $DB->update_record('gmk_letter_request', $request);
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }
        } else {
            $requestid = $DB->insert_record('gmk_letter_request', $request);
            self::add_event($requestid, null, self::STATUS_SOLICITADA, 'request_created', 'Request created by student', $userid);
            $request = $DB->get_record('gmk_letter_request', ['id' => $requestid], '*', MUST_EXIST);
        }

        if ((float)$request->cost_snapshot > 0) {
            $request = self::set_request_status(
                (int)$request->id,
                self::STATUS_PENDIENTE_PAGO,
                $userid,
                'Solicitud con costo: factura generada',
                ['invoice' => $invoice]
            );
            if (!empty($request->payment_link)) {
                self::send_payment_link_notification((int)$request->userid, $request, $lettertype);
            }
        } else if ($request->deliverymode_snapshot === self::DELIVERY_DIGITAL
            && $request->generationmode_snapshot === self::GENERATION_AUTO) {
            $request = self::auto_generate((int)$request->id, $userid, 'Carta digital sin costo generada automáticamente');
        } else {
            $request = self::set_request_status(
                (int)$request->id,
                self::STATUS_PENDIENTE_GESTION,
                $userid,
                'Solicitud enviada a gestión académica'
            );
        }

        return self::get_request_detail((int)$request->id, $userid, true);
    }

    /**
     * Handles payment webhook payload.
     *
     * @param array $payload
     * @return array
     * @throws dml_exception
     */
    public static function handle_payment_webhook(array $payload): array {
        global $DB;

        $externalrequestid = trim((string)($payload['external_request_id'] ?? ''));
        $invoiceid = trim((string)($payload['invoice_id'] ?? ''));
        $invoicenumber = trim((string)($payload['invoice_number'] ?? ''));
        $paymentstate = trim((string)($payload['payment_state'] ?? ''));
        if ($paymentstate !== 'paid') {
            return ['success' => true, 'ignored' => true, 'reason' => 'payment_state_not_paid'];
        }

        $request = null;
        if ($externalrequestid !== '' && ctype_digit($externalrequestid)) {
            $request = $DB->get_record('gmk_letter_request', ['id' => (int)$externalrequestid], '*', IGNORE_MISSING);
        }
        if (!$request && $invoiceid !== '') {
            $request = $DB->get_record('gmk_letter_request', ['invoice_id' => $invoiceid], '*', IGNORE_MISSING);
        }
        if (!$request) {
            return ['success' => false, 'error' => 'request_not_found'];
        }

        if ((string)$request->status === self::STATUS_PAGADA
            || (string)$request->status === self::STATUS_GENERADA_DIGITAL
            || (string)$request->status === self::STATUS_PENDIENTE_GESTION
            || (string)$request->status === self::STATUS_PENDIENTE_RECOLECCION
            || (string)$request->status === self::STATUS_ENTREGADA) {
            self::add_event(
                (int)$request->id,
                (string)$request->status,
                (string)$request->status,
                'payment_webhook_ignored',
                'Webhook already processed for this request',
                0,
                $payload
            );
            return ['success' => true, 'idempotent' => true, 'requestid' => (int)$request->id];
        }

        if ((string)$request->status === self::STATUS_RECHAZADA) {
            self::add_event((int)$request->id, self::STATUS_RECHAZADA, self::STATUS_RECHAZADA, 'payment_webhook_ignored',
                'Pago recibido de una solicitud rechazada: revisar con Finanzas', 0, $payload);
            return ['success' => true, 'ignored' => true, 'reason' => 'request_rejected', 'requestid' => (int)$request->id];
        }
        // Anulada por falta de pago y pagada despues: se reactiva (20261001130).
        $reactivate = (string)$request->status === self::STATUS_CANCELADA;

        $request->invoice_id = $invoiceid !== '' ? $invoiceid : (string)$request->invoice_id;
        $request->invoice_number = $invoicenumber !== '' ? $invoicenumber : (string)$request->invoice_number;
        $request->paid_at = time();
        $request->timemodified = time();
        $request->usermodified = 0;
        $DB->update_record('gmk_letter_request', $request);

        $request = self::set_request_status(
            (int)$request->id,
            self::STATUS_PAGADA,
            0,
            $reactivate ? 'Pago confirmado por webhook: se reactiva la solicitud anulada' : 'Pago confirmado por webhook',
            $payload,
            ['force' => $reactivate]
        );

        if ($request->deliverymode_snapshot === self::DELIVERY_DIGITAL
            && $request->generationmode_snapshot === self::GENERATION_AUTO) {
            $request = self::auto_generate((int)$request->id, 0, 'Carta digital generada automáticamente tras pago',
                $payload);
        } else {
            $request = self::set_request_status(
                (int)$request->id,
                self::STATUS_PENDIENTE_GESTION,
                0,
                'Pago recibido, pendiente gestión académica',
                $payload
            );
        }

        return ['success' => true, 'requestid' => (int)$request->id, 'status' => (string)$request->status];
    }

    /**
     * Updates request status with transition control and event audit.
     *
     * @param int $requestid
     * @param string $newstatus
     * @param int $actorid
     * @param string $message
     * @param array $metadata
     * @return stdClass
     * @throws dml_exception
     * @throws moodle_exception
     */
    public static function set_request_status(
        int $requestid,
        string $newstatus,
        int $actorid,
        string $message = '',
        array $metadata = [],
        array $opts = []
    ): stdClass {
        global $DB;
        $request = $DB->get_record('gmk_letter_request', ['id' => $requestid], '*', MUST_EXIST);
        $oldstatus = (string)$request->status;
        if ($oldstatus === $newstatus) {
            return $request;
        }
        if (empty($opts['force'])) {
            self::assert_valid_transition($oldstatus, $newstatus);
        }
        $notify = !array_key_exists('notify', $opts) || !empty($opts['notify']);
        $type = $DB->get_record('gmk_letter_type', ['id' => $request->lettertypeid], '*', IGNORE_MISSING);
        $now = time();

        $request->status = $newstatus;
        if ($newstatus === self::STATUS_RECHAZADA) {
            $request->rejection_reason = $message;
        }
        if ($newstatus === self::STATUS_CANCELADA) {
            $request->cancel_reason = $message;
        }
        // Plazo del nuevo estado (20261001130); "legacy" solo vale para la gestion pendiente.
        foreach (sla::enter_fields($type ?: null, $newstatus, $now) as $field => $value) {
            $request->$field = $value;
        }
        if (!in_array($newstatus, team::OWNED_STATUSES, true)) {
            $request->legacy = 0;
        }
        $request->usermodified = $actorid;
        $request->timemodified = $now;
        $DB->update_record('gmk_letter_request', $request);

        self::add_event($requestid, $oldstatus, $newstatus, 'status_change', $message, $actorid, $metadata);

        // Entra a gestion sin responsable: se asigna y se avisa al equipo (antes nadie se enteraba).
        if ($newstatus === self::STATUS_PENDIENTE_GESTION && (int)$request->assignedto === 0) {
            $agent = team::pick($now);
            if ($agent > 0) {
                $request = self::assign($requestid, $agent, 0, 'auto', false);
            }
            self::notify_staff_new($request, $type ?: null);
        }

        if ($notify) {
            if ($newstatus === self::STATUS_PENDIENTE_RECOLECCION) {
                self::send_ready_for_pickup_notification((int)$request->userid, $request, $type ?: null);
            } else if ($newstatus === self::STATUS_GENERADA_DIGITAL) {
                self::send_generated_notification((int)$request->userid, $request, $type ?: null);
            } else if ($newstatus !== self::STATUS_PENDIENTE_PAGO) {
                self::send_status_changed_notification((int)$request->userid, $request, $type ?: null);
            }
        }
        $request = $DB->get_record('gmk_letter_request', ['id' => $requestid], '*', MUST_EXIST);
        tickets::on_status_change($request, $oldstatus, $newstatus);
        return $request;
    }

    /** Statuses whose due_at is an estimated date the student may see. */
    const STUDENT_DUE_STATUSES = ['solicitada', 'pagada', 'pendiente_gestion'];

    /** Statuses still in progress (not finished). */
    const OPEN_STATUSES = ['solicitada', 'pendiente_pago', 'pagada', 'pendiente_gestion', 'pendiente_recoleccion'];

    /**
     * Sets the owner of a letter. $notify: tell the new owner.
     */
    public static function assign(int $requestid, int $agentid, int $actorid, string $how = 'manual',
            bool $notify = true): stdClass {
        global $DB;
        $request = $DB->get_record('gmk_letter_request', ['id' => $requestid], '*', MUST_EXIST);
        if ((int)$request->assignedto === $agentid) {
            return $request;
        }
        if ($agentid > 0 && !has_capability('local/grupomakro_core:managerequests', context_system::instance(), $agentid)
                && !in_array($agentid, team::agents(), true)) {
            throw new moodle_exception('letter_err_notmanager', 'local_grupomakro_core');
        }
        $old = (int)$request->assignedto;
        $DB->update_record('gmk_letter_request', (object)['id' => $requestid, 'assignedto' => $agentid,
            'assigned_at' => $agentid > 0 ? time() : 0, 'timemodified' => time(), 'usermodified' => $actorid]);
        self::add_event($requestid, (string)$request->status, (string)$request->status, 'assigned',
            $agentid > 0 ? 'Responsable: ' . (self::user_names([$agentid])[$agentid] ?? $agentid) : 'Sin responsable',
            $actorid, ['from' => $old, 'to' => $agentid, 'how' => $how]);
        $request = $DB->get_record('gmk_letter_request', ['id' => $requestid], '*', MUST_EXIST);
        if ($agentid > 0) {
            if ($notify && $agentid !== $actorid) {
                $type = $DB->get_record('gmk_letter_type', ['id' => (int)$request->lettertypeid]);
                self::notify_staff_new($request, $type ?: null, [$agentid], true);
            }
            tickets::on_assigned($request);
        }
        return $request;
    }

    /**
     * Auto generation; if the PDF fails the letter goes to manual management
     * instead of staying stuck (T3).
     */
    private static function auto_generate(int $requestid, int $actorid, string $message, array $metadata = []): stdClass {
        try {
            self::generate_document_for_request($requestid, $actorid);
        } catch (\Throwable $e) {
            self::add_event($requestid, null, null, 'generation_failed', 'No se pudo generar el PDF: ' . $e->getMessage(),
                $actorid);
            return self::set_request_status($requestid, self::STATUS_PENDIENTE_GESTION, $actorid,
                'La generación automática falló: pasa a gestión académica', $metadata);
        }
        return self::set_request_status($requestid, self::STATUS_GENERADA_DIGITAL, $actorid, $message, $metadata);
    }

    /**
     * Public event logger (desk notes, SLA, reminders).
     */
    public static function log(string $eventtype, int $requestid, ?string $oldstatus, ?string $newstatus, string $message,
            int $actorid, array $metadata = []): void {
        self::add_event($requestid, $oldstatus, $newstatus, $eventtype, $message, $actorid, $metadata);
    }

    /**
     * New letter to manage: to its owner, else to the team.
     */
    private static function notify_staff_new(stdClass $request, ?stdClass $type, ?array $to = null,
            bool $assigned = false): void {
        $recipients = $to ?? ((int)$request->assignedto > 0 ? [(int)$request->assignedto] : team::recipients_unassigned());
        $student = \core_user::get_user((int)$request->userid);
        $name = $type ? format_string($type->name) : ('#' . (int)$request->lettertypeid);
        $url = (new \moodle_url('/local/grupomakro_core/pages/letter_desk.php', ['request' => (int)$request->id]))->out(false);
        $subject = ($assigned ? 'Carta asignada: ' : 'Nueva solicitud de carta: ') . $name . ' (#' . (int)$request->id . ')';
        $body = ($assigned ? 'Tienes a tu cargo la solicitud de carta' : 'Llegó una solicitud de carta para gestionar')
            . ":\n\n• Carta #" . (int)$request->id . ' · ' . $name . "\n• Estudiante: " . ($student ? fullname($student) : '')
            . ((int)$request->due_at > 0 ? "\n• Plazo: " . userdate((int)$request->due_at, '%d/%m/%Y %H:%M') : '')
            . (trim((string)$request->observation) !== '' ? "\n• Observación: " . trim((string)$request->observation) : '')
            . "\n\nGestionarla: " . $url;
        foreach (team::active($recipients) as $uid) {
            $user = \core_user::get_user($uid);
            if ($user) {
                self::send_message('letter_new_request', $user, $subject, $body, $url, 'Bandeja de cartas');
            }
        }
    }

    /**
     * Plain-text staff notification through $provider. Never throws.
     */
    public static function send_message(string $provider, stdClass $to, string $subject, string $body, string $url,
            string $urlname): void {
        try {
            $msg = new message();
            $msg->component = 'local_grupomakro_core';
            $msg->name = $provider;
            $msg->userfrom = core_user::get_noreply_user();
            $msg->userto = $to;
            $msg->subject = $subject;
            $msg->fullmessage = $body;
            $msg->fullmessageformat = FORMAT_PLAIN;
            $msg->fullmessagehtml = nl2br(s($body));
            $msg->smallmessage = $subject;
            $msg->notification = 1;
            $msg->contexturl = $url;
            $msg->contexturlname = $urlname;
            $msg->courseid = SITEID;
            message_send($msg);
        } catch (\Throwable $e) {
            debugging('letters notify ' . $provider . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Reminders to the student: 'payment_reminder' | 'pickup_reminder'.
     */
    public static function notify_student(stdClass $request, string $kind): void {
        global $DB;
        $type = $DB->get_record('gmk_letter_type', ['id' => (int)$request->lettertypeid]);
        $name = $type ? format_string($type->name) : ('#' . (int)$request->lettertypeid);
        $a = (object)['lettername' => $name, 'requestid' => (int)$request->id,
            'amount' => number_format((float)$request->cost_snapshot, 2), 'paymentlink' => (string)$request->payment_link,
            'days' => sla::setting('letters_pay_cancel_days'),
            'pickup' => $type && trim((string)$type->pickup_note) !== '' ? trim((string)$type->pickup_note) : ''];
        $subject = get_string('letter_' . $kind . '_subject', 'local_grupomakro_core', $a);
        $body = get_string('letter_' . $kind . '_body', 'local_grupomakro_core', $a);
        self::send_notification((int)$request->userid, 'letter_student_reminder', $subject, $body,
            $kind === 'payment_reminder' ? (string)$request->payment_link : '');
    }

    /**
     * @return array userid => full name
     */
    public static function user_names(array $ids): array {
        global $DB;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal($ids);
        $out = [];
        foreach ($DB->get_records_select('user', "id $in", $params, '',
                'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename') as $u) {
            $out[(int)$u->id] = fullname($u);
        }
        return $out;
    }

    /**
     * Student-facing status words.
     */
    public static function student_status_label(string $status): string {
        $key = 'letter_student_status_' . $status;
        return get_string_manager()->string_exists($key, 'local_grupomakro_core')
            ? get_string($key, 'local_grupomakro_core') : (self::get_status_labels()[$status] ?? $status);
    }

    /**
     * Steps shown to the student: [label, state done|current|todo|failed].
     */
    public static function student_steps(stdClass $request): array {
        $status = (string)$request->status;
        $paid = (float)$request->cost_snapshot > 0;
        $physical = (string)$request->deliverymode_snapshot === self::DELIVERY_FISICA;
        $steps = ['solicitada' => 'Recibida'];
        if ($paid) {
            $steps['pago'] = 'Pago';
        }
        $steps['gestion'] = 'En preparación';
        if ($physical || in_array($status, [self::STATUS_PENDIENTE_RECOLECCION, self::STATUS_ENTREGADA], true)) {
            $steps['retiro'] = 'Lista para retirar';
            $steps['entregada'] = 'Entregada';
        } else {
            $steps['lista'] = 'Lista para descargar';
        }
        $position = [
            self::STATUS_SOLICITADA => 'solicitada', self::STATUS_PENDIENTE_PAGO => 'pago',
            self::STATUS_PAGADA => 'gestion', self::STATUS_PENDIENTE_GESTION => 'gestion',
            self::STATUS_GENERADA_DIGITAL => 'lista', self::STATUS_PENDIENTE_RECOLECCION => 'retiro',
            self::STATUS_ENTREGADA => 'entregada',
        ];
        $failed = in_array($status, [self::STATUS_RECHAZADA, self::STATUS_CANCELADA], true);
        $current = $position[$status] ?? null;
        $keys = array_keys($steps);
        $idx = $current !== null ? array_search($current, $keys, true) : false;
        $final = in_array($status, [self::STATUS_GENERADA_DIGITAL, self::STATUS_ENTREGADA], true);
        $out = [];
        foreach ($keys as $i => $k) {
            if ($failed) {
                $state = $i === 0 ? 'done' : 'todo';
            } else if ($idx === false) {
                $state = 'todo';
            } else if ($i < $idx || ($i === $idx && $final)) {
                $state = 'done';
            } else {
                $state = $i === $idx ? 'current' : 'todo';
            }
            $out[] = ['label' => $steps[$k], 'state' => $state];
        }
        if ($failed) {
            $out[] = ['label' => self::student_status_label($status), 'state' => 'failed'];
        }
        return $out;
    }

    /**
     * Student cancels their own request while nobody has worked on it.
     */
    public static function student_cancel(int $requestid, int $userid, string $reason = ''): stdClass {
        global $DB;
        $request = $DB->get_record('gmk_letter_request', ['id' => $requestid], '*', MUST_EXIST);
        if ((int)$request->userid !== $userid) {
            throw new moodle_exception('nopermissions', 'error', '', 'cancel letter request');
        }
        if (!self::student_can_cancel($request)) {
            throw new moodle_exception('letter_err_cannotcancel', 'local_grupomakro_core');
        }
        $reason = \core_text::substr(trim($reason), 0, 500);
        return self::set_request_status($requestid, self::STATUS_CANCELADA, $userid,
            'Anulada por el estudiante' . ($reason !== '' ? ': ' . $reason : ''), [], ['notify' => false]);
    }

    public static function student_can_cancel(stdClass $request): bool {
        global $DB;
        if (!in_array((string)$request->status, [self::STATUS_PENDIENTE_PAGO, self::STATUS_PENDIENTE_GESTION,
                self::STATUS_SOLICITADA], true)) {
            return false;
        }
        return !$DB->record_exists('gmk_letter_document', ['requestid' => (int)$request->id]);
    }

    /**
     * Staff loads the letter they made (PDF or Word) and closes the request:
     * $next = 'available' (el estudiante la descarga), 'pickup' (lista para
     * retirar) or 'delivered' (entregada). Pedido de Ramiro, 19-nov-2026.
     */
    public static function upload_document(int $requestid, int $actorid, string $filename, string $content,
            string $next = 'available'): stdClass {
        global $DB;
        $request = $DB->get_record('gmk_letter_request', ['id' => $requestid], '*', MUST_EXIST);
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!isset(self::UPLOAD_TYPES[$ext])) {
            throw new moodle_exception('letter_err_filetype', 'local_grupomakro_core');
        }
        if ($content === '' || strlen($content) > self::UPLOAD_MAX_BYTES) {
            throw new moodle_exception('letter_err_filesize', 'local_grupomakro_core', '', self::UPLOAD_MAX_BYTES / 1048576);
        }
        $targets = ['available' => self::STATUS_GENERADA_DIGITAL, 'pickup' => self::STATUS_PENDIENTE_RECOLECCION,
            'delivered' => self::STATUS_ENTREGADA];
        if (!isset($targets[$next])) {
            throw new \invalid_parameter_exception('next');
        }
        $target = $targets[$next];
        $status = (string)$request->status;
        $sametarget = $status === $target;
        if (!$sametarget && !in_array($target, self::allowed_next($status), true)) {
            throw new moodle_exception('letter_invalid_transition', 'local_grupomakro_core', '', $status . ' -> ' . $target);
        }
        $version = self::get_next_document_version($requestid);
        $base = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($filename, PATHINFO_FILENAME));
        $stored = 'carta_req_' . $requestid . '_v' . $version . '_' . \core_text::substr($base, 0, 60) . '.' . $ext;

        $transaction = $DB->start_delegated_transaction();
        try {
            $doc = self::save_document($requestid, $stored, $content, $actorid, '', '', self::UPLOAD_TYPES[$ext], 'uploaded');
            self::add_event($requestid, $status, $status, 'document_uploaded', 'Carta cargada: ' . $filename, $actorid,
                ['documentid' => (int)$doc->id, 'version' => (int)$doc->versionno]);
            if ($status === self::STATUS_PAGADA && $target !== self::STATUS_GENERADA_DIGITAL) {
                // pagada -> pendiente_gestion -> destino.
                self::set_request_status($requestid, self::STATUS_PENDIENTE_GESTION, $actorid, 'Carta cargada', [],
                    ['notify' => false]);
            }
            if (!$sametarget) {
                $request = self::set_request_status($requestid, $target, $actorid, 'Carta cargada por el personal');
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
        self::queue_odoo_copy((int)$doc->id);
        return $DB->get_record('gmk_letter_request', ['id' => $requestid], '*', MUST_EXIST);
    }

    /** Files staff may upload: extension => mimetype. */
    const UPLOAD_TYPES = [
        'pdf' => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'doc' => 'application/msword',
    ];

    const UPLOAD_MAX_BYTES = 10485760;

    /**
     * Valid next statuses from $status.
     *
     * @return string[]
     */
    public static function allowed_next(string $status): array {
        return self::TRANSITIONS[$status] ?? [];
    }

    /**
     * Pending letters with ANS for the admin dashboard (contract, see metrics).
     */
    public static function get_pending_for_staff(int $userid, string $scope = 'mine', int $limit = 3): array {
        return metrics::pending_for_staff($userid, $scope, $limit);
    }

    /**
     * Copies a document to Odoo in the background (T6).
     */
    public static function queue_odoo_copy(int $documentid): void {
        $task = new \local_grupomakro_core\task\letters_odoo_copy();
        $task->set_custom_data(['documentid' => $documentid]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Sends one stored document to Odoo (adhoc task). Returns true when Odoo
     * confirmed the attachment.
     */
    public static function copy_document_to_odoo(int $documentid): bool {
        global $DB;
        $document = $DB->get_record('gmk_letter_document', ['id' => $documentid]);
        if (!$document || !empty($document->odoo_attachment_id)) {
            return true;
        }
        $request = $DB->get_record('gmk_letter_request', ['id' => (int)$document->requestid]);
        if (!$request) {
            return true;
        }
        $fs = get_file_storage();
        $file = $fs->get_file(context_system::instance()->id, 'local_grupomakro_core', 'letter_document',
            (int)$document->fileitemid, '/', (string)$document->filename);
        if (!$file) {
            return true;
        }
        self::duplicate_document_to_odoo($request, $document, base64_encode($file->get_content()));
        return (bool)$DB->get_field('gmk_letter_document', 'odoo_attachment_id', ['id' => $documentid]);
    }

    /**
     * Deletes a letter request permanently, including events and generated documents.
     *
     * @param int $requestid
     * @return void
     * @throws dml_exception
     */
    public static function delete_request_permanently(int $requestid): void {
        global $DB;

        $DB->get_record('gmk_letter_request', ['id' => $requestid], 'id', MUST_EXIST);
        $context = context_system::instance();
        $fs = get_file_storage();

        $transaction = $DB->start_delegated_transaction();

        $documents = $DB->get_records('gmk_letter_document', ['requestid' => $requestid]);
        foreach ($documents as $document) {
            $itemid = (int)($document->fileitemid ?? 0);
            if ($itemid > 0) {
                $fs->delete_area_files($context->id, 'local_grupomakro_core', 'letter_document', $itemid);
            }
        }

        $DB->delete_records('gmk_letter_document', ['requestid' => $requestid]);
        $DB->delete_records('gmk_letter_request_event', ['requestid' => $requestid]);
        $DB->delete_records('gmk_letter_request', ['id' => $requestid]);

        $transaction->allow_commit();
    }

    /**
     * Returns request detail.
     *
     * @param int $requestid
     * @param int $viewerid
     * @param bool $canviewall
     * @return array
     * @throws dml_exception
     * @throws moodle_exception
     */
    public static function get_request_detail(int $requestid, int $viewerid, bool $canviewall = false): array {
        global $DB;
        $request = $DB->get_record('gmk_letter_request', ['id' => $requestid], '*', MUST_EXIST);
        if (!$canviewall && (int)$request->userid !== $viewerid) {
            throw new moodle_exception('nopermissions', 'error', '', 'view letter request');
        }

        $type = $DB->get_record('gmk_letter_type', ['id' => $request->lettertypeid], '*', MUST_EXIST);
        $document = self::get_latest_document($requestid);
        $labels = self::get_status_labels();

        return [
            'id' => (int)$request->id,
            'userid' => (int)$request->userid,
            'lettertypeid' => (int)$request->lettertypeid,
            'lettertypename' => (string)$type->name,
            'lettertypecode' => (string)$type->code,
            'status' => (string)$request->status,
            'statuslabel' => (string)($labels[$request->status] ?? $request->status),
            'observation' => (string)($request->observation ?? ''),
            'warning_snapshot' => (string)($request->warning_snapshot ?? ''),
            'cost_snapshot' => (float)$request->cost_snapshot,
            'deliverymode_snapshot' => (string)$request->deliverymode_snapshot,
            'generationmode_snapshot' => (string)$request->generationmode_snapshot,
            'invoice_id' => (string)($request->invoice_id ?? ''),
            'invoice_number' => (string)($request->invoice_number ?? ''),
            'payment_link' => (string)($request->payment_link ?? ''),
            'document_available' => $document ? 1 : 0,
            'document_version' => $document ? (int)$document->versionno : 0,
            'document_filename' => $document ? (string)$document->filename : '',
            'document_verification_url' => $document ? (string)($document->verificationurl ?? '') : '',
            'document_verification_token' => $document ? (string)($document->verificationtoken ?? '') : '',
            'timecreated' => (int)$request->timecreated,
            'timemodified' => (int)$request->timemodified,
        ];
    }

    /**
     * Returns requests for user.
     *
     * @param int $userid
     * @param bool $canviewall
     * @param string $statusfilter
     * @return array
     * @throws dml_exception
     */
    public static function get_requests(int $userid, bool $canviewall = false, string $statusfilter = ''): array {
        global $DB;
        $params = [];
        $where = [];
        if (!$canviewall) {
            $where[] = 'r.userid = :userid';
            $params['userid'] = $userid;
        } else if ($userid > 0) {
            $where[] = 'r.userid = :userid';
            $params['userid'] = $userid;
        }
        if ($statusfilter !== '') {
            $where[] = 'r.status = :status';
            $params['status'] = $statusfilter;
        }
        $wheresql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $sql = "SELECT r.*, t.name AS lettertypename, t.code AS lettertypecode, t.pickup_note
                  FROM {gmk_letter_request} r
                  JOIN {gmk_letter_type} t ON t.id = r.lettertypeid
                  $wheresql
              ORDER BY r.timecreated DESC";
        $records = $DB->get_records_sql($sql, $params);
        $labels = self::get_status_labels();
        // Una consulta para los documentos y otra para los tickets (antes, 1 por fila).
        $docs = self::latest_documents(array_keys($records));
        $tickets = tickets::summaries(array_keys($records), $userid, !$canviewall || $userid > 0);
        $result = [];
        foreach ($records as $record) {
            $document = $docs[(int)$record->id] ?? null;
            $ticket = $tickets[(int)$record->id] ?? null;
            $result[] = [
                'studentlabel' => self::student_status_label((string)$record->status),
                'steps' => self::student_steps($record),
                'observation' => (string)($record->observation ?? ''),
                'deliverymode' => (string)$record->deliverymode_snapshot,
                // Fecha estimada solo mientras se prepara: en "lista para retirar" el plazo
                // es el aviso interno al personal, no una fecha para el estudiante.
                'due_at' => in_array((string)$record->status, self::STUDENT_DUE_STATUSES, true) ? (int)$record->due_at : 0,
                'rejection_reason' => (string)($record->rejection_reason ?? ''),
                'cancel_reason' => (string)($record->cancel_reason ?? ''),
                'pickup_note' => (string)$record->status === self::STATUS_PENDIENTE_RECOLECCION
                    ? trim((string)($record->pickup_note ?? '')) : '',
                'can_cancel' => self::student_can_cancel($record) ? 1 : 0,
                'can_pay' => ((string)$record->status === self::STATUS_PENDIENTE_PAGO
                    && trim((string)$record->payment_link) !== '') ? 1 : 0,
                'document_mimetype' => $document ? (string)$document->mimetype : '',
                'ticket' => $ticket ?? ['id' => 0, 'number' => '', 'status' => '', 'statuslabel' => '', 'isopen' => false,
                    'unread' => 0],
                'timeupdated' => (int)$record->timemodified,
                'id' => (int)$record->id,
                'userid' => (int)$record->userid,
                'lettertypeid' => (int)$record->lettertypeid,
                'lettertypename' => (string)$record->lettertypename,
                'lettertypecode' => (string)$record->lettertypecode,
                'status' => (string)$record->status,
                'statuslabel' => (string)($labels[$record->status] ?? $record->status),
                'cost_snapshot' => (float)$record->cost_snapshot,
                'invoice_id' => (string)($record->invoice_id ?? ''),
                'invoice_number' => (string)($record->invoice_number ?? ''),
                'payment_link' => (string)($record->payment_link ?? ''),
                'document_available' => $document ? 1 : 0,
                'document_filename' => $document ? (string)$document->filename : '',
                'document_verification_url' => $document ? (string)($document->verificationurl ?? '') : '',
                'timecreated' => (int)$record->timecreated,
                'timemodified' => (int)$record->timemodified,
            ];
        }
        return $result;
    }

    /**
     * Resolves public verification data by document token.
     *
     * @param string $verificationtoken
     * @return array|null
     * @throws dml_exception
     */
    public static function get_verification_data(string $verificationtoken): ?array {
        global $DB;

        $token = trim($verificationtoken);
        if ($token === '') {
            return null;
        }

        $sql = "SELECT d.id AS documentid, d.requestid, d.versionno, d.filename, d.timecreated AS generatedat,
                       d.verificationtoken, d.verificationurl,
                       r.status, r.timecreated AS requestcreated, r.timemodified AS requestupdated,
                       t.name AS lettertypename, t.code AS lettertypecode,
                       u.id AS userid, u.firstname, u.lastname
                  FROM {gmk_letter_document} d
                  JOIN {gmk_letter_request} r ON r.id = d.requestid
                  JOIN {gmk_letter_type} t ON t.id = r.lettertypeid
                  JOIN {user} u ON u.id = r.userid
                 WHERE d.verificationtoken = :token";
        $record = $DB->get_record_sql($sql, ['token' => $token], IGNORE_MULTIPLE);
        if (!$record) {
            return null;
        }

        $labels = self::get_status_labels();
        return [
            'documentid' => (int)$record->documentid,
            'requestid' => (int)$record->requestid,
            'version' => (int)$record->versionno,
            'filename' => (string)$record->filename,
            'verificationtoken' => (string)$record->verificationtoken,
            'verificationurl' => (string)($record->verificationurl ?? ''),
            'status' => (string)$record->status,
            'statuslabel' => (string)($labels[$record->status] ?? $record->status),
            'lettertypename' => (string)$record->lettertypename,
            'lettertypecode' => (string)$record->lettertypecode,
            'studentname' => trim(fullname((object)[
                'id' => (int)$record->userid,
                'firstname' => (string)$record->firstname,
                'lastname' => (string)$record->lastname,
                'firstnamephonetic' => '',
                'lastnamephonetic' => '',
                'middlename' => '',
                'alternatename' => '',
            ])),
            'generatedat' => (int)$record->generatedat,
            'requestcreated' => (int)$record->requestcreated,
            'requestupdated' => (int)$record->requestupdated,
        ];
    }

    /**
     * Generates PDF document for a request.
     *
     * @param int $requestid
     * @param int $actorid
     * @return array
     * @throws dml_exception
     * @throws moodle_exception
     */
    public static function generate_document_for_request(int $requestid, int $actorid = 0): array {
        global $DB;
        $request = $DB->get_record('gmk_letter_request', ['id' => $requestid], '*', MUST_EXIST);
        $type = $DB->get_record('gmk_letter_type', ['id' => $request->lettertypeid], '*', MUST_EXIST);
        $user = $DB->get_record('user', ['id' => $request->userid, 'deleted' => 0], '*', MUST_EXIST);

        $verificationtoken = self::generate_verification_token();
        $verificationurl = self::build_verification_url($verificationtoken);

        $rendered = self::render_letter_html($request, $type, $user, $verificationurl, $verificationtoken);
        $pdfcontent = self::render_pdf($rendered, $verificationurl);

        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string)$type->code);
        $filename = 'carta_' . $safe . '_req_' . $requestid . '_v' . (self::get_next_document_version($requestid)) . '.pdf';
        $docrecord = self::save_document($requestid, $filename, $pdfcontent, $actorid, $verificationtoken, $verificationurl);

        // T6: la copia a Odoo va en segundo plano con reintentos (antes esperaba hasta 30 s).
        self::queue_odoo_copy((int)$docrecord->id);
        self::add_event($requestid, $request->status, $request->status, 'document_generated', 'Documento de carta generado', $actorid);

        return [
            'documentid' => (int)$docrecord->id,
            'filename' => (string)$docrecord->filename,
            'version' => (int)$docrecord->versionno,
            'verificationurl' => (string)($docrecord->verificationurl ?? $verificationurl),
        ];
    }

    /**
     * Downloads latest letter document as base64 payload.
     *
     * @param int $requestid
     * @param int $viewerid
     * @param bool $canviewall
     * @return array
     * @throws dml_exception
     * @throws moodle_exception
     */
    public static function download_document_payload(int $requestid, int $viewerid, bool $canviewall = false): array {
        global $DB;
        $request = $DB->get_record('gmk_letter_request', ['id' => $requestid], '*', MUST_EXIST);
        if (!$canviewall && (int)$request->userid !== $viewerid) {
            throw new moodle_exception('nopermissions', 'error', '', 'download letter document');
        }
        $document = self::get_latest_document($requestid);
        if (!$document) {
            throw new moodle_exception('letter_document_not_found', 'local_grupomakro_core');
        }

        $context = context_system::instance();
        $fs = get_file_storage();
        $file = $fs->get_file($context->id, 'local_grupomakro_core', 'letter_document', $document->fileitemid, '/', $document->filename);
        if (!$file) {
            throw new moodle_exception('letter_document_not_found', 'local_grupomakro_core');
        }

        return [
            'filename' => (string)$document->filename,
            'mimetype' => (string)$document->mimetype,
            'contentbase64' => base64_encode($file->get_content()),
        ];
    }

    /**
     * Returns all predefined datasets.
     *
     * @return array
     * @throws dml_exception
     */
    public static function get_all_dataset_definitions(): array {
        global $DB;
        $records = $DB->get_records('gmk_letter_dataset_def', [], 'name ASC');
        $result = [];
        foreach ($records as $record) {
            $result[] = [
                'id' => (int)$record->id,
                'code' => (string)$record->code,
                'name' => (string)$record->name,
                'description' => (string)($record->description ?? ''),
                'enabled' => (int)$record->enabled,
            ];
        }
        return $result;
    }

    /**
     * Validates status transition.
     *
     * @param string $oldstatus
     * @param string $newstatus
     * @return void
     * @throws moodle_exception
     */
    /**
     * Valid transitions. 20261001130: pendiente_gestion -> entregada (carta
     * cargada y entregada, o "ya se gestiono" de las anteriores a la bandeja).
     */
    const TRANSITIONS = [
        self::STATUS_SOLICITADA => [self::STATUS_PENDIENTE_PAGO, self::STATUS_GENERADA_DIGITAL, self::STATUS_PENDIENTE_GESTION, self::STATUS_CANCELADA, self::STATUS_RECHAZADA],
        self::STATUS_PENDIENTE_PAGO => [self::STATUS_PAGADA, self::STATUS_CANCELADA, self::STATUS_RECHAZADA],
        self::STATUS_PAGADA => [self::STATUS_GENERADA_DIGITAL, self::STATUS_PENDIENTE_GESTION, self::STATUS_CANCELADA, self::STATUS_RECHAZADA],
        self::STATUS_GENERADA_DIGITAL => [self::STATUS_ENTREGADA, self::STATUS_CANCELADA],
        self::STATUS_PENDIENTE_GESTION => [self::STATUS_PENDIENTE_RECOLECCION, self::STATUS_RECHAZADA, self::STATUS_CANCELADA, self::STATUS_GENERADA_DIGITAL, self::STATUS_ENTREGADA],
        self::STATUS_PENDIENTE_RECOLECCION => [self::STATUS_ENTREGADA, self::STATUS_RECHAZADA, self::STATUS_CANCELADA],
        self::STATUS_ENTREGADA => [],
        self::STATUS_RECHAZADA => [],
        self::STATUS_CANCELADA => [],
    ];

    private static function assert_valid_transition(string $oldstatus, string $newstatus): void {
        $allowed = self::TRANSITIONS;
        if (!array_key_exists($oldstatus, $allowed) || !in_array($newstatus, $allowed[$oldstatus], true)) {
            throw new moodle_exception('letter_invalid_transition', 'local_grupomakro_core', '', $oldstatus . ' -> ' . $newstatus);
        }
    }

    /**
     * Adds request event.
     *
     * @param int $requestid
     * @param string|null $oldstatus
     * @param string|null $newstatus
     * @param string $eventtype
     * @param string $message
     * @param int $actorid
     * @param array $metadata
     * @return void
     * @throws dml_exception
     */
    private static function add_event(
        int $requestid,
        ?string $oldstatus,
        ?string $newstatus,
        string $eventtype,
        string $message,
        int $actorid,
        array $metadata = []
    ): void {
        global $DB;
        $record = (object)[
            'requestid' => $requestid,
            'oldstatus' => $oldstatus,
            'newstatus' => $newstatus,
            'eventtype' => $eventtype,
            'message' => $message,
            'metadata' => !empty($metadata) ? json_encode($metadata) : null,
            'usermodified' => $actorid,
            'timecreated' => time(),
        ];
        $DB->insert_record('gmk_letter_request_event', $record);
    }

    /**
     * Creates invoice in Odoo via Express proxy.
     *
     * @param stdClass $request
     * @param stdClass $type
     * @param int $actorid
     * @return array
     * @throws moodle_exception
     */
    private static function create_invoice_for_request(stdClass $request, stdClass $type, int $actorid): array {
        global $DB;
        $user = $DB->get_record('user', ['id' => $request->userid], '*', MUST_EXIST);
        $documentnumber = self::get_user_document_number((int)$request->userid);
        if ($documentnumber === '') {
            throw new moodle_exception('letter_documentnumber_required', 'local_grupomakro_core');
        }

        $defaultproduct = (int)get_config('local_grupomakro_core', 'letters_default_odoo_product_id');
        $productid = (int)($type->odoo_product_id ?? 0);
        if ($productid <= 0) {
            $productid = $defaultproduct;
        }

        $payload = [
            'external_request_id' => (string)$request->id,
            'document_number' => $documentnumber,
            'student_email' => (string)$user->email,
            'amount' => (float)$request->cost_snapshot,
            'currency' => 'USD',
            'odoo_product_id' => $productid,
            'description' => 'Solicitud de carta: ' . $type->name . ' (#' . $request->id . ')',
            'letter_type_code' => (string)$type->code,
            'letter_type_name' => (string)$type->name,
        ];
        $response = self::call_odoo_proxy('/api/odoo/letters/invoice', $payload);
        if (empty($response['success'])) {
            throw new moodle_exception(
                'letter_invoice_error',
                'local_grupomakro_core',
                '',
                isset($response['error']) ? (string)$response['error'] : 'unknown'
            );
        }
        return [
            'invoice_id' => (string)($response['invoice_id'] ?? ''),
            'invoice_number' => (string)($response['invoice_number'] ?? ''),
            'payment_link' => (string)($response['payment_link'] ?? ''),
        ];
    }

    /**
     * Duplicates generated document to Odoo.
     *
     * @param stdClass $request
     * @param stdClass $document
     * @param string $contentbase64
     * @return void
     */
    private static function duplicate_document_to_odoo(stdClass $request, stdClass $document, string $contentbase64): void {
        global $DB;
        $documentnumber = self::get_user_document_number((int)$request->userid);
        $payload = [
            'external_request_id' => (string)$request->id,
            'invoice_id' => (string)($request->invoice_id ?? ''),
            'invoice_number' => (string)($request->invoice_number ?? ''),
            'document_number' => $documentnumber,
            'filename' => (string)$document->filename,
            'mimetype' => (string)$document->mimetype,
            'content_base64' => $contentbase64,
        ];
        $response = self::call_odoo_proxy('/api/odoo/letters/attach-document', $payload);
        if (!empty($response['success']) && !empty($response['attachment_id'])) {
            $document->odoo_attachment_id = (string)$response['attachment_id'];
            $document->timemodified = time();
            $DB->update_record('gmk_letter_document', $document);
        }
    }

    /**
     * Calls Odoo proxy endpoint.
     *
     * @param string $path
     * @param array $payload
     * @return array
     */
    private static function call_odoo_proxy(string $path, array $payload): array {
        $baseurl = get_config('local_grupomakro_core', 'odoo_proxy_url');
        if (empty($baseurl)) {
            $baseurl = 'https://lms.isi.edu.pa:4000';
        }
        $url = rtrim($baseurl, '/') . $path;
        $jsonpayload = json_encode($payload);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonpayload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        $raw = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return ['success' => false, 'error' => $error];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['success' => false, 'error' => 'invalid_json_response', 'httpcode' => $httpcode, 'raw' => $raw];
        }
        if ($httpcode < 200 || $httpcode >= 300) {
            if (!isset($decoded['success'])) {
                $decoded['success'] = false;
            }
        }
        return $decoded;
    }

    /**
     * Renders HTML template for request.
     *
     * @param stdClass $request
     * @param stdClass $type
     * @param stdClass $user
     * @return string
     */
    private static function render_letter_html(
        stdClass $request,
        stdClass $type,
        stdClass $user,
        string $verificationurl = '',
        string $verificationtoken = ''
    ): string {
        $template = trim((string)($type->template_html ?? ''));
        if ($template === '') {
            $template = '<h1>{{request.letter_name}}</h1><p>Estudiante: {{student.fullname}}</p><p>Documento: {{student.document_number}}</p><p>Fecha: {{date.today}}</p><p>{{request.observation}}</p>{{DATASET:resumen_creditos}}';
        }

        $vars = self::build_scalar_variables($request, $type, $user, $verificationurl, $verificationtoken);
        $rendered = preg_replace_callback('/\{\{\s*([a-zA-Z0-9\._]+)\s*\}\}/', function($matches) use ($vars) {
            $key = $matches[1];
            return array_key_exists($key, $vars) ? (string)$vars[$key] : '';
        }, $template);

        $datasetcodes = array_values(array_map(function($d) {
            return $d['code'];
        }, self::get_datasets_for_letter_type((int)$type->id)));
        $rendered = preg_replace_callback('/\{\{\s*DATASET:([a-zA-Z0-9_]+)\s*\}\}/', function($matches) use ($datasetcodes, $user) {
            $code = $matches[1];
            if (!in_array($code, $datasetcodes, true)) {
                return '';
            }
            return self::render_dataset_html($code, (int)$user->id);
        }, $rendered);

        $attachments = [];
        if (!empty($type->autostamp) && !empty($type->stampimageurl)) {
            $attachments[] = '<div style="margin-top:20px;"><img src="' . s($type->stampimageurl) . '" style="max-height:80px;" alt="Sello"></div>';
        }
        if (!empty($type->autosignature) && !empty($type->signatureimageurl)) {
            $attachments[] = '<div style="margin-top:20px;"><img src="' . s($type->signatureimageurl) . '" style="max-height:80px;" alt="Firma"></div>';
        }

        return '<html><body style="font-family: sans-serif;">' . $rendered . implode('', $attachments) . '</body></html>';
    }

    /**
     * Builds scalar template variables.
     *
     * @param stdClass $request
     * @param stdClass $type
     * @param stdClass $user
     * @return array
     */
    private static function build_scalar_variables(
        stdClass $request,
        stdClass $type,
        stdClass $user,
        string $verificationurl = '',
        string $verificationtoken = ''
    ): array {
        $doc = self::get_user_document_number((int)$user->id);
        $date = userdate(time(), '%Y-%m-%d');
        return [
            'student.id' => (string)$user->id,
            'student.username' => (string)$user->username,
            'student.firstname' => (string)$user->firstname,
            'student.lastname' => (string)$user->lastname,
            'student.fullname' => fullname($user),
            'student.email' => (string)$user->email,
            'student.document_number' => $doc,
            'request.id' => (string)$request->id,
            'request.status' => (string)$request->status,
            'request.letter_name' => (string)$type->name,
            'request.letter_code' => (string)$type->code,
            'request.cost' => number_format((float)$request->cost_snapshot, 2, '.', ''),
            'request.observation' => (string)($request->observation ?? ''),
            'request.verification_url' => (string)$verificationurl,
            'request.verification_token' => (string)$verificationtoken,
            'date.today' => (string)$date,
        ];
    }

    /**
     * Renders one dataset as HTML.
     *
     * @param string $code
     * @param int $userid
     * @return string
     */
    private static function render_dataset_html(string $code, int $userid): string {
        $dataset = self::build_dataset($code, $userid);
        if (empty($dataset['rows'])) {
            return '<p></p>';
        }
        $html = '<table border="1" cellpadding="6" cellspacing="0" width="100%" style="border-collapse:collapse;margin-top:10px;">';
        $html .= '<thead><tr>';
        foreach ($dataset['columns'] as $col) {
            $html .= '<th style="background:#efefef;">' . s($col) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($dataset['rows'] as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>' . s((string)$cell) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        return $html;
    }

    /**
     * Builds predefined dataset payload.
     *
     * @param string $code
     * @param int $userid
     * @return array
     */
    private static function build_dataset(string $code, int $userid): array {
        global $DB;

        if ($code === 'asignaturas_cursadas') {
            $records = $DB->get_records('gmk_course_progre', ['userid' => $userid], 'periodid ASC, coursename ASC');
            $rows = [];
            foreach ($records as $r) {
                // Re-resolve credits at print time so homologated and module rows
                // are not stuck at the historical 0 they were stamped with at insertion.
                $resolved = credit_resolver::resolve(
                    (int)($r->learningplanid ?? 0),
                    (int)($r->courseid ?? 0)
                );
                $rows[] = [
                    (string)$r->coursename,
                    (string)$r->periodname,
                    (string)$r->grade,
                    (string)$resolved,
                    (string)$r->progress . '%',
                ];
            }
            return [
                'columns' => ['Asignatura', 'Periodo', 'Nota', 'Créditos', 'Progreso'],
                'rows' => $rows,
            ];
        }

        if ($code === 'resumen_creditos') {
            // Aggregate by resolving credits at query time so homologations and
            // modules with stale 0 snapshots contribute to the total.
            $sql = "SELECT gcp.id, gcp.learningplanid, gcp.courseid, gcp.status
                      FROM {gmk_course_progre} gcp
                     WHERE gcp.userid = :userid";
            $rows = $DB->get_records_sql($sql, ['userid' => $userid]);
            $totalcredits = 0;
            $approvedcredits = 0;
            $totalcourses = 0;
            foreach ($rows as $r) {
                $resolved = credit_resolver::resolve(
                    (int)($r->learningplanid ?? 0),
                    (int)($r->courseid ?? 0)
                );
                if ($resolved <= 0) {
                    continue;
                }
                $totalcredits += $resolved;
                $totalcourses++;
                if ((int)$r->status === 4) {
                    $approvedcredits += $resolved;
                }
            }
            return [
                'columns' => ['Métrica', 'Valor'],
                'rows' => [
                    ['Total créditos cursados', (string)$totalcredits],
                    ['Total créditos aprobados', (string)$approvedcredits],
                    ['Total asignaturas', (string)$totalcourses],
                ],
            ];
        }

        if ($code === 'periodo_actual') {
            $record = $DB->get_record_sql(
                "SELECT periodname, periodid
                   FROM {gmk_course_progre}
                  WHERE userid = :userid
               ORDER BY periodid DESC, id DESC",
                ['userid' => $userid],
                IGNORE_MULTIPLE
            );
            $periodname = $record ? (string)$record->periodname : 'N/D';
            $periodid = $record ? (string)$record->periodid : 'N/D';
            return [
                'columns' => ['Métrica', 'Valor'],
                'rows' => [
                    ['Periodo académico actual', $periodname],
                    ['ID de periodo', $periodid],
                ],
            ];
        }

        return ['columns' => [], 'rows' => []];
    }

    /**
     * Renders PDF bytes from HTML.
     *
     * @param string $html
     * @param string $verificationurl
     * @return string
     */
    private static function render_pdf(string $html, string $verificationurl = ''): string {
        global $CFG;
        require_once($CFG->libdir . '/tcpdf/tcpdf.php');
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Moodle');
        $pdf->SetAuthor('ISI LMS');
        $pdf->SetTitle('Carta');
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->AddPage();
        $pdf->writeHTML($html, true, false, true, false, '');

        if ($verificationurl !== '') {
            $pdf->Ln(4);
            $pdf->SetFont('helvetica', '', 8);
            $pdf->MultiCell(0, 0, 'Codigo de verificacion: ' . $verificationurl, 0, 'L', false, 1);

            $y = (float)$pdf->GetY();
            $usablebottom = (float)$pdf->getPageHeight() - 18;
            if (($y + 30) > $usablebottom) {
                $pdf->AddPage();
                $y = (float)$pdf->GetY();
            }

            $style = [
                'border' => 0,
                'padding' => 0,
                'fgcolor' => [0, 0, 0],
                'bgcolor' => false,
            ];
            $pdf->write2DBarcode($verificationurl, 'QRCODE,H', 15, $y + 1, 28, 28, $style, 'N');
            $pdf->SetXY(46, $y + 4);
            $pdf->MultiCell(0, 0, 'Escanee el QR o abra el enlace para validar esta carta.', 0, 'L', false, 1);
        }

        return $pdf->Output('', 'S');
    }

    /**
     * Saves generated document and file record.
     *
     * @param int $requestid
     * @param string $filename
     * @param string $content
     * @param int $actorid
     * @param string $verificationtoken
     * @param string $verificationurl
     * @return stdClass
     * @throws dml_exception
     */
    private static function save_document(
        int $requestid,
        string $filename,
        string $content,
        int $actorid,
        string $verificationtoken = '',
        string $verificationurl = '',
        string $mimetype = 'application/pdf',
        string $source = 'generated'
    ): stdClass {
        global $DB;
        $now = time();
        $doc = (object)[
            'requestid' => $requestid,
            'versionno' => self::get_next_document_version($requestid),
            'filename' => $filename,
            'mimetype' => $mimetype,
            'source' => $source,
            'filesize' => strlen($content),
            'fileitemid' => 0,
            'verificationtoken' => $verificationtoken !== '' ? $verificationtoken : null,
            'verificationurl' => $verificationurl !== '' ? $verificationurl : null,
            'odoo_attachment_id' => null,
            'usermodified' => $actorid,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $docid = $DB->insert_record('gmk_letter_document', $doc);
        $doc->id = $docid;
        $doc->fileitemid = $docid;

        $context = context_system::instance();
        $fs = get_file_storage();
        $fileinfo = [
            'contextid' => $context->id,
            'component' => 'local_grupomakro_core',
            'filearea' => 'letter_document',
            'itemid' => $docid,
            'filepath' => '/',
            'filename' => $filename,
        ];
        if ($existing = $fs->get_file(
            $fileinfo['contextid'],
            $fileinfo['component'],
            $fileinfo['filearea'],
            $fileinfo['itemid'],
            $fileinfo['filepath'],
            $fileinfo['filename']
        )) {
            $existing->delete();
        }
        $fs->create_file_from_string($fileinfo, $content);

        $doc->timemodified = time();
        $DB->update_record('gmk_letter_document', $doc);

        return $DB->get_record('gmk_letter_document', ['id' => $docid], '*', MUST_EXIST);
    }

    /**
     * Gets latest document for request.
     *
     * @param int $requestid
     * @return stdClass|null
     */
    public static function get_latest_document(int $requestid): ?stdClass {
        global $DB;
        $records = $DB->get_records('gmk_letter_document', ['requestid' => $requestid], 'versionno DESC', '*', 0, 1);
        if (empty($records)) {
            return null;
        }
        return reset($records);
    }

    /**
     * Latest document of each request in one query.
     *
     * @return array requestid => document
     */
    public static function latest_documents(array $requestids): array {
        global $DB;
        $requestids = array_values(array_filter(array_map('intval', $requestids)));
        if (!$requestids) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal($requestids);
        $out = [];
        foreach ($DB->get_records_select('gmk_letter_document', "requestid $in", $params, 'requestid, versionno') as $d) {
            $out[(int)$d->requestid] = $d;
        }
        return $out;
    }

    /**
     * Gets next version number for request document.
     *
     * @param int $requestid
     * @return int
     */
    private static function get_next_document_version(int $requestid): int {
        global $DB;
        $sql = "SELECT MAX(versionno) FROM {gmk_letter_document} WHERE requestid = :requestid";
        $max = (int)$DB->get_field_sql($sql, ['requestid' => $requestid]);
        return $max + 1;
    }

    /**
     * Generates a unique token used for public document verification.
     *
     * @return string
     * @throws dml_exception
     */
    private static function generate_verification_token(): string {
        global $DB;

        do {
            try {
                $token = bin2hex(random_bytes(20));
            } catch (\Throwable $ex) {
                $token = sha1(uniqid((string)time(), true) . mt_rand());
            }
        } while ($DB->record_exists('gmk_letter_document', ['verificationtoken' => $token]));

        return $token;
    }

    /**
     * Builds the public verification URL for a document token.
     *
     * @param string $token
     * @return string
     */
    private static function build_verification_url(string $token): string {
        $url = new \moodle_url('/local/grupomakro_core/pages/letter_verify.php', ['t' => $token]);
        return $url->out(false);
    }

    /**
     * Reads student document number from custom profile field.
     *
     * @param int $userid
     * @return string
     */
    public static function get_user_document_number(int $userid): string {
        global $DB;
        static $fieldid = null;
        if ($fieldid === null) {
            $fieldid = (int)$DB->get_field('user_info_field', 'id', ['shortname' => 'documentnumber'], IGNORE_MISSING);
        }
        if (empty($fieldid)) {
            return '';
        }
        $value = $DB->get_field('user_info_data', 'data', ['userid' => $userid, 'fieldid' => $fieldid], IGNORE_MISSING);
        return trim((string)$value);
    }

    /**
     * Sends payment link notification.
     *
     * @param int $userid
     * @param stdClass $request
     * @param stdClass $type
     * @return void
     */
    private static function send_payment_link_notification(int $userid, stdClass $request, stdClass $type): void {
        $subject = get_string('letter_payment_subject', 'local_grupomakro_core', $type->name);
        $body = get_string('letter_payment_body', 'local_grupomakro_core', (object)[
            'lettername' => $type->name,
            'amount' => number_format((float)$request->cost_snapshot, 2),
            'paymentlink' => (string)$request->payment_link,
            'requestid' => (int)$request->id,
        ]);
        self::send_notification($userid, 'payment_link', $subject, $body, (string)$request->payment_link);
    }

    /**
     * Sends generated letter notification.
     *
     * @param int $userid
     * @param stdClass $request
     * @param stdClass|null $type
     * @return void
     */
    private static function send_generated_notification(int $userid, stdClass $request, ?stdClass $type): void {
        $lettername = $type ? $type->name : ('#' . $request->lettertypeid);
        $subject = get_string('letter_generated_subject', 'local_grupomakro_core', $lettername);
        $body = get_string('letter_generated_body', 'local_grupomakro_core', (object)[
            'lettername' => $lettername,
            'requestid' => (int)$request->id,
        ]);
        self::send_notification($userid, 'letter_generated', $subject, $body);
    }

    /**
     * Sends pickup ready notification.
     *
     * @param int $userid
     * @param stdClass $request
     * @param stdClass|null $type
     * @return void
     */
    private static function send_ready_for_pickup_notification(int $userid, stdClass $request, ?stdClass $type): void {
        $lettername = $type ? $type->name : ('#' . $request->lettertypeid);
        $subject = get_string('letter_pickup_subject', 'local_grupomakro_core', $lettername);
        $body = get_string('letter_pickup_body', 'local_grupomakro_core', (object)[
            'lettername' => $lettername,
            'requestid' => (int)$request->id,
        ]);
        self::send_notification($userid, 'ready_for_pickup', $subject, $body);
    }

    /**
     * Sends generic status changed notification.
     *
     * @param int $userid
     * @param stdClass $request
     * @param stdClass|null $type
     * @return void
     */
    private static function send_status_changed_notification(int $userid, stdClass $request, ?stdClass $type): void {
        $labels = self::get_status_labels();
        $lettername = $type ? $type->name : ('#' . $request->lettertypeid);
        $statuslabel = $labels[$request->status] ?? $request->status;
        $subject = get_string('letter_status_subject', 'local_grupomakro_core', $lettername);
        $body = get_string('letter_status_body', 'local_grupomakro_core', (object)[
            'lettername' => $lettername,
            'status' => $statuslabel,
            'requestid' => (int)$request->id,
        ]);
        self::send_notification($userid, 'status_changed', $subject, $body);
    }

    /**
     * Sends Moodle notification (popup + email by provider defaults).
     *
     * @param int $userid
     * @param string $providername
     * @param string $subject
     * @param string $htmlmessage
     * @param string $contexturl
     * @return void
     */
    private static function send_notification(
        int $userid,
        string $providername,
        string $subject,
        string $htmlmessage,
        string $contexturl = ''
    ): void {
        $eventdata = new message();
        $eventdata->component = 'local_grupomakro_core';
        $eventdata->name = $providername;
        $eventdata->userfrom = core_user::get_noreply_user();
        $eventdata->userto = $userid;
        $eventdata->subject = $subject;
        $eventdata->fullmessage = trim(html_to_text($htmlmessage));
        $eventdata->fullmessageformat = FORMAT_HTML;
        $eventdata->fullmessagehtml = $htmlmessage;
        $eventdata->notification = 1;
        if ($contexturl !== '') {
            $eventdata->contexturl = $contexturl;
            $eventdata->contexturlname = get_string('letter_context_url_name', 'local_grupomakro_core');
        }
        message_send($eventdata);
    }
}
