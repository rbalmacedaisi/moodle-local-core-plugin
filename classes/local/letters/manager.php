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

        $now = time();
        $request = (object)[
            'userid' => $userid,
            'lettertypeid' => $lettertypeid,
            'status' => self::STATUS_SOLICITADA,
            'observation' => trim($observation),
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
        ];
        $requestid = $DB->insert_record('gmk_letter_request', $request);

        self::add_event($requestid, null, self::STATUS_SOLICITADA, 'request_created', 'Request created by student', $userid);

        $request = $DB->get_record('gmk_letter_request', ['id' => $requestid], '*', MUST_EXIST);

        if ((float)$request->cost_snapshot > 0) {
            $invoice = self::create_invoice_for_request($request, $lettertype, $userid);
            $request->invoice_id = (string)($invoice['invoice_id'] ?? '');
            $request->invoice_number = (string)($invoice['invoice_number'] ?? '');
            $request->payment_link = (string)($invoice['payment_link'] ?? '');
            $DB->update_record('gmk_letter_request', $request);
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
            self::generate_document_for_request((int)$request->id, $userid);
            $request = self::set_request_status(
                (int)$request->id,
                self::STATUS_GENERADA_DIGITAL,
                $userid,
                'Carta digital sin costo generada automáticamente'
            );
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
            'Pago confirmado por webhook',
            $payload
        );

        if ($request->deliverymode_snapshot === self::DELIVERY_DIGITAL
            && $request->generationmode_snapshot === self::GENERATION_AUTO) {
            self::generate_document_for_request((int)$request->id, 0);
            $request = self::set_request_status(
                (int)$request->id,
                self::STATUS_GENERADA_DIGITAL,
                0,
                'Carta digital generada automáticamente tras pago',
                $payload
            );
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
        array $metadata = []
    ): stdClass {
        global $DB;
        $request = $DB->get_record('gmk_letter_request', ['id' => $requestid], '*', MUST_EXIST);
        $oldstatus = (string)$request->status;
        if ($oldstatus === $newstatus) {
            return $request;
        }
        self::assert_valid_transition($oldstatus, $newstatus);

        $request->status = $newstatus;
        if ($newstatus === self::STATUS_RECHAZADA) {
            $request->rejection_reason = $message;
        }
        if ($newstatus === self::STATUS_CANCELADA) {
            $request->cancel_reason = $message;
        }
        $request->usermodified = $actorid;
        $request->timemodified = time();
        $DB->update_record('gmk_letter_request', $request);

        self::add_event($requestid, $oldstatus, $newstatus, 'status_change', $message, $actorid, $metadata);

        $type = $DB->get_record('gmk_letter_type', ['id' => $request->lettertypeid], '*', IGNORE_MISSING);
        if ($newstatus === self::STATUS_PENDIENTE_RECOLECCION) {
            self::send_ready_for_pickup_notification((int)$request->userid, $request, $type);
        } else if ($newstatus === self::STATUS_GENERADA_DIGITAL) {
            self::send_generated_notification((int)$request->userid, $request, $type);
        } else if ($newstatus !== self::STATUS_PENDIENTE_PAGO) {
            self::send_status_changed_notification((int)$request->userid, $request, $type);
        }

        return $DB->get_record('gmk_letter_request', ['id' => $requestid], '*', MUST_EXIST);
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
        $sql = "SELECT r.*, t.name AS lettertypename, t.code AS lettertypecode
                  FROM {gmk_letter_request} r
                  JOIN {gmk_letter_type} t ON t.id = r.lettertypeid
                  $wheresql
              ORDER BY r.timecreated DESC";
        $records = $DB->get_records_sql($sql, $params);
        $labels = self::get_status_labels();
        $result = [];
        foreach ($records as $record) {
            $document = self::get_latest_document((int)$record->id);
            $result[] = [
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
                'timecreated' => (int)$record->timecreated,
                'timemodified' => (int)$record->timemodified,
            ];
        }
        return $result;
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

        $rendered = self::render_letter_html($request, $type, $user);
        $pdfcontent = self::render_pdf($rendered);

        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string)$type->code);
        $filename = 'carta_' . $safe . '_req_' . $requestid . '_v' . (self::get_next_document_version($requestid)) . '.pdf';
        $docrecord = self::save_document($requestid, $filename, $pdfcontent, $actorid);

        self::duplicate_document_to_odoo($request, $docrecord, base64_encode($pdfcontent));
        self::add_event($requestid, $request->status, $request->status, 'document_generated', 'Documento de carta generado', $actorid);

        return [
            'documentid' => (int)$docrecord->id,
            'filename' => (string)$docrecord->filename,
            'version' => (int)$docrecord->versionno,
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
    private static function assert_valid_transition(string $oldstatus, string $newstatus): void {
        $allowed = [
            self::STATUS_SOLICITADA => [self::STATUS_PENDIENTE_PAGO, self::STATUS_GENERADA_DIGITAL, self::STATUS_PENDIENTE_GESTION, self::STATUS_CANCELADA, self::STATUS_RECHAZADA],
            self::STATUS_PENDIENTE_PAGO => [self::STATUS_PAGADA, self::STATUS_CANCELADA, self::STATUS_RECHAZADA],
            self::STATUS_PAGADA => [self::STATUS_GENERADA_DIGITAL, self::STATUS_PENDIENTE_GESTION, self::STATUS_CANCELADA, self::STATUS_RECHAZADA],
            self::STATUS_GENERADA_DIGITAL => [self::STATUS_ENTREGADA, self::STATUS_CANCELADA],
            self::STATUS_PENDIENTE_GESTION => [self::STATUS_PENDIENTE_RECOLECCION, self::STATUS_RECHAZADA, self::STATUS_CANCELADA, self::STATUS_GENERADA_DIGITAL],
            self::STATUS_PENDIENTE_RECOLECCION => [self::STATUS_ENTREGADA, self::STATUS_RECHAZADA, self::STATUS_CANCELADA],
            self::STATUS_ENTREGADA => [],
            self::STATUS_RECHAZADA => [],
            self::STATUS_CANCELADA => [],
        ];
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
    private static function render_letter_html(stdClass $request, stdClass $type, stdClass $user): string {
        $template = trim((string)($type->template_html ?? ''));
        if ($template === '') {
            $template = '<h1>{{request.letter_name}}</h1><p>Estudiante: {{student.fullname}}</p><p>Documento: {{student.document_number}}</p><p>Fecha: {{date.today}}</p><p>{{request.observation}}</p>{{DATASET:resumen_creditos}}';
        }

        $vars = self::build_scalar_variables($request, $type, $user);
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
    private static function build_scalar_variables(stdClass $request, stdClass $type, stdClass $user): array {
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
                $rows[] = [
                    (string)$r->coursename,
                    (string)$r->periodname,
                    (string)$r->grade,
                    (string)$r->credits,
                    (string)$r->progress . '%',
                ];
            }
            return [
                'columns' => ['Asignatura', 'Periodo', 'Nota', 'Créditos', 'Progreso'],
                'rows' => $rows,
            ];
        }

        if ($code === 'resumen_creditos') {
            $sql = "SELECT COALESCE(SUM(credits),0) AS total_credits,
                           COALESCE(SUM(CASE WHEN status = 4 THEN credits ELSE 0 END),0) AS approved_credits,
                           COUNT(1) AS total_courses
                      FROM {gmk_course_progre}
                     WHERE userid = :userid";
            $agg = $DB->get_record_sql($sql, ['userid' => $userid]);
            return [
                'columns' => ['Métrica', 'Valor'],
                'rows' => [
                    ['Total créditos cursados', (string)($agg->total_credits ?? 0)],
                    ['Total créditos aprobados', (string)($agg->approved_credits ?? 0)],
                    ['Total asignaturas', (string)($agg->total_courses ?? 0)],
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
     * @return string
     */
    private static function render_pdf(string $html): string {
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
        return $pdf->Output('', 'S');
    }

    /**
     * Saves generated document and file record.
     *
     * @param int $requestid
     * @param string $filename
     * @param string $content
     * @param int $actorid
     * @return stdClass
     * @throws dml_exception
     */
    private static function save_document(int $requestid, string $filename, string $content, int $actorid): stdClass {
        global $DB;
        $now = time();
        $doc = (object)[
            'requestid' => $requestid,
            'versionno' => self::get_next_document_version($requestid),
            'filename' => $filename,
            'mimetype' => 'application/pdf',
            'filesize' => strlen($content),
            'fileitemid' => 0,
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
    private static function get_latest_document(int $requestid): ?stdClass {
        global $DB;
        $records = $DB->get_records('gmk_letter_document', ['requestid' => $requestid], 'versionno DESC', '*', 0, 1);
        if (empty($records)) {
            return null;
        }
        return reset($records);
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
