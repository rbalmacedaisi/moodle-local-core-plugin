<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Retiros (20261001135): flujo de gestion de una solicitud de retiro.
 *
 * Etapas (gmk_wdr.status):
 *   solicitada     Nueva. Llega del portal del estudiante o la crea Bienestar.
 *   en_gestion     Bienestar la tomo: gestiona la retencion, registra cada
 *                  gestion en la bitacora, programa seguimientos, escala a
 *                  otras areas e informa la deuda al estudiante.
 *   retiro_formal  El estudiante confirma que se va: Secretaria Academica y
 *                  Registros completan el checklist RET-01 (firma del
 *                  estudiante, recibido Dir. Academica, recibido Dir.
 *                  Administrativa, copia escaneada) y ejecutan el retiro.
 *   procesada      Retirado: contrato cancelado en Odoo, asignaturas en curso
 *                  retiradas, estado Retirado, cuenta suspendida y solicitudes
 *                  pendientes anuladas en Moodle.
 *   retenida       El estudiante se queda (cierre de la gestion de Bienestar).
 *   rechazada      Anulada (solicitud duplicada, por error, etc.).
 *
 * Con deuda en Odoo el retiro formal no se ejecuta (sin excepciones).
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local;

defined('MOODLE_INTERNAL') || die();

class wdr_flow {

    const ST_NEW = 'solicitada';
    const ST_MANAGE = 'en_gestion';
    const ST_FORMAL = 'retiro_formal';
    const ST_DONE = 'procesada';
    const ST_RETAINED = 'retenida';
    const ST_VOID = 'rechazada';

    /** Estados del RET-01 anterior que se tratan como retiro formal. */
    const LEGACY_FORMAL = ['pendiente_firma_presencial', 'firmada_digital',
        'recibida_direccion_academica', 'recibida_direccion_administrativa'];

    const STAGE_LABELS = [
        'solicitada' => 'Nueva',
        'en_gestion' => 'En gestión de retención',
        'retiro_formal' => 'Retiro formal',
        'procesada' => 'Retirado',
        'retenida' => 'Retenido (se queda)',
        'rechazada' => 'Anulada',
        'cancelada' => 'Cancelada por el estudiante',
    ];

    const CHANNELS = ['portal' => 'Portal del estudiante', 'presencial' => 'Presencial', 'telefono' => 'Llamada',
        'correo' => 'Correo', 'whatsapp' => 'WhatsApp'];

    /** Tipos de gestion que Bienestar registra en la bitacora. */
    const LOG_TYPES = ['llamada' => 'Llamada', 'reunion' => 'Reunión', 'mensaje' => 'Mensaje / WhatsApp',
        'correo' => 'Correo', 'nota' => 'Nota interna'];

    /** Areas a las que Bienestar puede escalar (rol sugerido para elegir responsable). */
    const AREAS = [
        'direccion_academica' => ['label' => 'Dirección Académica', 'roles' => ['gmk_director_academico']],
        'psicologia' => ['label' => 'Psicología', 'roles' => ['gmk_psicologo']],
        'cobros' => ['label' => 'Cobros / Administración', 'roles' => []],
        'coordinacion' => ['label' => 'Coordinación del programa', 'roles' => []],
    ];

    const REASONS = ['A' => 'Económico', 'B' => 'Laboral', 'C' => 'Personal / familiar', 'D' => 'Salud',
        'E' => 'Cambio de residencia', 'F' => 'Otro'];

    // ── Permisos ────────────────────────────────────────────────────────────

    public static function can_manage(int $userid = 0): bool {
        return has_capability('local/grupomakro_core:manage_wdr_requests', \context_system::instance(),
            $userid ?: null);
    }

    public static function can_formal(int $userid = 0): bool {
        return has_capability('local/grupomakro_core:process_wdr_formal', \context_system::instance(),
            $userid ?: null);
    }

    public static function can_view(int $userid = 0): bool {
        return has_capability('local/grupomakro_core:view_wdr_requests', \context_system::instance(),
            $userid ?: null);
    }

    /** Responsable de un escalamiento abierto de la solicitud (puede verla y responder). */
    public static function is_escalation_assignee(int $wdrid, int $userid): bool {
        global $DB;
        return $DB->record_exists('gmk_wdr_log', ['wdrid' => $wdrid, 'type' => 'escalamiento', 'assignee' => $userid]);
    }

    public static function require_view(int $wdrid, int $userid): void {
        if (!self::can_view($userid) && !self::is_escalation_assignee($wdrid, $userid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/grupomakro_core:view_wdr_requests', 'nopermissions', '');
        }
    }

    public static function stage(string $status): string {
        return in_array($status, self::LEGACY_FORMAL, true) ? self::ST_FORMAL : $status;
    }

    // ── Creacion manual ─────────────────────────────────────────────────────

    /**
     * Bienestar registra una solicitud recibida por otro canal (presencial,
     * llamada, correo, WhatsApp). Queda en gestion y asignada a quien la crea.
     */
    public static function create_manual(int $studentid, string $channel, string $reason, string $observations,
            int $actorid): \stdClass {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/user/profile/lib.php');
        if (!isset(self::CHANNELS[$channel]) || $channel === 'portal') {
            throw new \moodle_exception('wdr_flow_error', 'local_grupomakro_core', '', 'Canal no válido');
        }
        if (!isset(self::REASONS[$reason])) {
            throw new \moodle_exception('wdr_flow_error', 'local_grupomakro_core', '', 'Motivo no válido');
        }
        $user = $DB->get_record('user', ['id' => $studentid, 'deleted' => 0], '*', MUST_EXIST);
        $open = $DB->get_record_select('gmk_wdr', "userid = ? AND status IN ('solicitada','en_gestion','retiro_formal',
                'pendiente_firma_presencial','firmada_digital','recibida_direccion_academica','recibida_direccion_administrativa')",
            [$studentid], 'id, request_number', IGNORE_MULTIPLE);
        if ($open) {
            throw new \moodle_exception('wdr_flow_error', 'local_grupomakro_core', '',
                'El estudiante ya tiene una solicitud de retiro abierta: ' . $open->request_number);
        }
        $profile = profile_user_record($studentid, false);
        $program = '';
        $llu = $DB->get_record_sql("SELECT lp.name FROM {local_learning_users} lu JOIN {local_learning_plans} lp
                ON lp.id = lu.learningplanid WHERE lu.userid = ? ORDER BY lu.id DESC", [$studentid], IGNORE_MULTIPLE);
        if ($llu) {
            $program = (string)$llu->name;
        }
        $alloc = wdr_manager::generate_request_number();
        $now = time();
        $rec = (object)[
            'userid' => $studentid,
            'request_number' => $alloc['request_number'],
            'fullname' => fullname($user),
            'program' => $program,
            'current_period' => (string)($profile->periodo_ingreso ?? ''),
            'last_period' => '',
            'phone' => (string)($profile->custom_phone ?? $user->phone1 ?? ''),
            'id_number' => (string)($profile->documentnumber ?? $user->username),
            'email' => (string)$user->email,
            'payment_mode' => '',
            'reason' => $reason,
            'payment_option' => 'no_aplica',
            'payment_option_detail' => '',
            'observations' => clean_param($observations, PARAM_TEXT),
            'status' => self::ST_MANAGE,
            'channel' => $channel,
            'created_by' => $actorid,
            'assigned_to' => $actorid,
            'last_activity_at' => $now,
            'timecreated' => $now,
            'timemodified' => $now,
            'usermodified' => $actorid,
        ];
        $rec->id = $DB->insert_record('gmk_wdr', $rec);
        self::log($rec->id, 'etapa', 'Solicitud registrada por ' . self::CHANNELS[$channel] . ' y tomada en gestión.',
            $actorid);
        return $rec;
    }

    // ── Acciones de la etapa de gestion (Bienestar) ─────────────────────────

    public static function take(int $id, int $actorid): \stdClass {
        $row = self::require_stage($id, [self::ST_NEW]);
        return self::move($row, self::ST_MANAGE, $actorid, 'Caso tomado en gestión de retención.',
            ['assigned_to' => $actorid]);
    }

    /** Gestion realizada (llamada, reunion...) con proximo seguimiento opcional. */
    public static function add_activity(int $id, string $type, string $body, int $nextfollowup, int $actorid): \stdClass {
        global $DB;
        $row = self::require_stage($id, [self::ST_MANAGE, self::ST_FORMAL]);
        if (!isset(self::LOG_TYPES[$type])) {
            throw new \moodle_exception('wdr_flow_error', 'local_grupomakro_core', '', 'Tipo de gestión no válido');
        }
        $body = trim($body);
        if ($body === '') {
            throw new \moodle_exception('wdr_flow_error', 'local_grupomakro_core', '', 'Describe la gestión realizada');
        }
        self::log($id, $type, $body, $actorid, ['due' => $nextfollowup]);
        $upd = ['id' => $id, 'next_followup' => max(0, $nextfollowup), 'last_activity_at' => time(),
            'timemodified' => time(), 'usermodified' => $actorid];
        if (empty($row->assigned_to)) {
            $upd['assigned_to'] = $actorid;
        }
        $DB->update_record('gmk_wdr', (object)$upd);
        return self::row($id);
    }

    /** Escala el caso a un area con un responsable concreto y un plazo. */
    public static function escalate(int $id, string $area, int $assignee, string $body, int $due, int $actorid): \stdClass {
        global $DB;
        self::require_stage($id, [self::ST_MANAGE]);
        if (!isset(self::AREAS[$area])) {
            throw new \moodle_exception('wdr_flow_error', 'local_grupomakro_core', '', 'Área no válida');
        }
        if ($assignee <= 0 || !$DB->record_exists('user', ['id' => $assignee, 'deleted' => 0, 'suspended' => 0])) {
            throw new \moodle_exception('wdr_flow_error', 'local_grupomakro_core', '', 'Elige a la persona responsable');
        }
        if (trim($body) === '') {
            throw new \moodle_exception('wdr_flow_error', 'local_grupomakro_core', '', 'Explica qué se necesita del área');
        }
        self::log($id, 'escalamiento', trim($body), $actorid, ['area' => $area, 'assignee' => $assignee, 'due' => $due]);
        $DB->update_record('gmk_wdr', (object)['id' => $id, 'last_activity_at' => time(), 'timemodified' => time()]);
        \local_grupomakro_core\local\dashboard\dashboard::invalidate_all();
        return self::row($id);
    }

    /** El responsable del escalamiento (o Bienestar) registra la respuesta. */
    public static function resolve_escalation(int $logid, string $response, int $actorid): \stdClass {
        global $DB;
        $log = $DB->get_record('gmk_wdr_log', ['id' => $logid, 'type' => 'escalamiento'], '*', MUST_EXIST);
        if ((int)$log->resolved_at > 0) {
            throw new \moodle_exception('wdr_flow_error', 'local_grupomakro_core', '', 'Este escalamiento ya fue respondido.');
        }
        if ((int)$log->assignee !== $actorid && !self::can_manage($actorid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/grupomakro_core:manage_wdr_requests', 'nopermissions', '');
        }
        if (trim($response) === '') {
            throw new \moodle_exception('wdr_flow_error', 'local_grupomakro_core', '', 'Escribe la respuesta');
        }
        $DB->update_record('gmk_wdr_log', (object)['id' => $logid, 'resolved_at' => time(), 'resolved_by' => $actorid,
            'response' => trim($response)]);
        $DB->update_record('gmk_wdr', (object)['id' => $log->wdrid, 'last_activity_at' => time(), 'timemodified' => time()]);
        \local_grupomakro_core\local\dashboard\dashboard::invalidate_all();
        return self::row((int)$log->wdrid);
    }

    /** Bienestar deja constancia de que indico al estudiante ponerse al dia. */
    public static function record_payment_instruction(int $id, string $body, int $actorid): \stdClass {
        global $DB;
        self::require_stage($id, [self::ST_MANAGE, self::ST_FORMAL]);
        $body = trim($body) !== '' ? trim($body) : 'Se indicó al estudiante que debe ponerse al día para procesar el retiro.';
        self::log($id, 'instruccion_pago', $body, $actorid);
        $DB->update_record('gmk_wdr', (object)['id' => $id, 'payment_instructed_at' => time(),
            'last_activity_at' => time(), 'timemodified' => time()]);
        return self::row($id);
    }

    /** Cierre de la gestion: el estudiante se queda. */
    public static function close_retained(int $id, string $body, int $actorid): \stdClass {
        $row = self::require_stage($id, [self::ST_MANAGE]);
        if (trim($body) === '') {
            throw new \moodle_exception('wdr_flow_error', 'local_grupomakro_core', '', 'Describe el acuerdo con el estudiante');
        }
        return self::move($row, self::ST_RETAINED, $actorid, 'Retenido: ' . trim($body),
            ['closed_at' => time(), 'closed_by' => $actorid, 'next_followup' => 0]);
    }

    /** El estudiante confirma el retiro: pasa a Secretaria Academica y Registros. */
    public static function send_to_formal(int $id, string $body, int $actorid): \stdClass {
        $row = self::require_stage($id, [self::ST_MANAGE]);
        if (trim($body) === '') {
            throw new \moodle_exception('wdr_flow_error', 'local_grupomakro_core', '', 'Resume la gestión realizada y por qué se va');
        }
        return self::move($row, self::ST_FORMAL, $actorid, 'Enviado a retiro formal: ' . trim($body),
            ['formal_requested_at' => time(), 'formal_requested_by' => $actorid, 'next_followup' => 0]);
    }

    /** Secretaria devuelve el caso a gestion (p. ej. el estudiante lo reconsidera). */
    public static function return_to_management(int $id, string $body, int $actorid): \stdClass {
        $row = self::require_stage($id, [self::ST_FORMAL]);
        if (trim($body) === '') {
            throw new \moodle_exception('wdr_flow_error', 'local_grupomakro_core', '', 'Explica por qué vuelve a gestión');
        }
        return self::move($row, self::ST_MANAGE, $actorid, 'Devuelto a gestión de retención: ' . trim($body));
    }

    /** Anula una solicitud abierta (duplicada, por error...). */
    public static function void_request(int $id, string $body, int $actorid): \stdClass {
        $row = self::require_stage($id, [self::ST_NEW, self::ST_MANAGE, self::ST_FORMAL]);
        if (trim($body) === '') {
            throw new \moodle_exception('wdr_flow_error', 'local_grupomakro_core', '', 'Indica el motivo de la anulación');
        }
        return self::move($row, self::ST_VOID, $actorid, 'Anulada: ' . trim($body),
            ['rejection_reason' => trim($body), 'closed_at' => time(), 'closed_by' => $actorid, 'next_followup' => 0]);
    }

    // ── Etapa de retiro formal (Secretaria Academica / Registros) ──────────

    const CHECKLIST = [
        'signed' => 'Firma del estudiante en el RET-01',
        'da' => 'Recibido por Dirección Académica',
        'admin' => 'Recibido por Dirección Administrativa',
        'scan' => 'Copia escaneada con las firmas',
    ];

    public static function checklist(\stdClass $row): array {
        return [
            'signed' => ['done' => (int)$row->student_signed_at > 0, 'at' => (int)$row->student_signed_at,
                'by' => (int)$row->student_signed_by],
            'da' => ['done' => (int)$row->received_da_at > 0, 'at' => (int)$row->received_da_at,
                'by' => (int)$row->received_da_by],
            'admin' => ['done' => (int)$row->received_admin_at > 0, 'at' => (int)$row->received_admin_at,
                'by' => (int)$row->received_admin_by],
            'scan' => ['done' => !empty($row->scanned_pdf_path), 'at' => 0, 'by' => 0],
        ];
    }

    /** Marca un punto del checklist, en orden. */
    public static function check(int $id, string $item, int $actorid): \stdClass {
        global $DB;
        $row = self::require_stage($id, [self::ST_FORMAL]);
        $cl = self::checklist($row);
        $order = array_keys(self::CHECKLIST);
        $pos = array_search($item, $order, true);
        if ($pos === false || $item === 'scan') {
            throw new \moodle_exception('wdr_flow_error', 'local_grupomakro_core', '', 'Paso no válido');
        }
        for ($i = 0; $i < $pos; $i++) {
            if (!$cl[$order[$i]]['done']) {
                throw new \moodle_exception('wdr_flow_error', 'local_grupomakro_core', '', 'Primero: ' . self::CHECKLIST[$order[$i]]);
            }
        }
        $now = time();
        $map = ['signed' => ['student_signed_at', 'student_signed_by'], 'da' => ['received_da_at', 'received_da_by'],
            'admin' => ['received_admin_at', 'received_admin_by']];
        [$fa, $fb] = $map[$item];
        $DB->update_record('gmk_wdr', (object)['id' => $id, $fa => $now, $fb => $actorid, 'last_activity_at' => $now,
            'timemodified' => $now, 'usermodified' => $actorid]);
        self::log($id, 'etapa', 'Checklist: ' . self::CHECKLIST[$item], $actorid);
        return self::row($id);
    }

    public static function after_scan_upload(int $id, int $actorid): void {
        global $DB;
        $DB->update_record('gmk_wdr', (object)['id' => $id, 'last_activity_at' => time(), 'timemodified' => time()]);
        self::log($id, 'etapa', 'Checklist: ' . self::CHECKLIST['scan'], $actorid);
    }

    /**
     * Lo que hara el retiro formal en Moodle (para mostrarlo antes de confirmar).
     */
    public static function preview_formal(\stdClass $row): array {
        global $DB;
        $userid = (int)$row->userid;
        $courses = self::courses_in_progress($userid);
        $pendingmods = $DB->count_records('gmk_module_invoice_requests', ['userid' => $userid, 'status' => 'pending_payment']);
        $user = $DB->get_record('user', ['id' => $userid], 'id, suspended');
        return [
            'courses' => array_values($courses),
            'pending_module_requests' => $pendingmods,
            'already_suspended' => (int)$user->suspended === 1,
        ];
    }

    /** Asignaturas en curso: progreso Cursando o miembro de una clase abierta. */
    public static function courses_in_progress(int $userid): array {
        global $DB;
        $out = [];
        foreach ($DB->get_records_sql("SELECT DISTINCT p.courseid, p.coursename FROM {gmk_course_progre} p
                WHERE p.userid = ? AND p.status = 2", [$userid]) as $r) {
            $out[(int)$r->courseid] = ['courseid' => (int)$r->courseid, 'name' => (string)$r->coursename];
        }
        foreach ($DB->get_records_sql("SELECT DISTINCT c.corecourseid, co.fullname FROM {gmk_class} c
                JOIN {groups_members} gm ON gm.groupid = c.groupid AND gm.userid = ?
                JOIN {course} co ON co.id = c.corecourseid
               WHERE c.closed = 0", [$userid]) as $r) {
            if (!isset($out[(int)$r->corecourseid])) {
                $out[(int)$r->corecourseid] = ['courseid' => (int)$r->corecourseid, 'name' => (string)$r->fullname];
            }
        }
        return $out;
    }

    /**
     * Ejecuta el retiro formal. Requiere checklist completo y que no haya deuda
     * en Odoo. Orden: Odoo (contrato y estado) y luego Moodle; cada paso de
     * Moodle queda registrado y es idempotente, asi que si algo falla se puede
     * reintentar con el mismo boton.
     */
    public static function execute_formal(int $id, int $actorid): array {
        global $DB, $CFG;
        $row = self::require_stage($id, [self::ST_FORMAL]);
        foreach (self::checklist($row) as $k => $c) {
            if (!$c['done']) {
                throw new \moodle_exception('wdr_flow_error', 'local_grupomakro_core', '', 'Falta en el checklist: ' . self::CHECKLIST[$k]);
            }
        }
        $actions = json_decode((string)($row->moodle_actions ?? ''), true) ?: [];

        // 1) Odoo: deuda (bloquea siempre) y retiro contractual.
        if (empty($actions['odoo']['ok'])) {
            $bal = self::balance((int)$row->userid);
            if (!$bal['ok']) {
                throw new \moodle_exception('wdr_flow_error', 'local_grupomakro_core', '', 'No se pudo consultar el estado financiero en Odoo: ' . $bal['error']);
            }
            if ($bal['hasBalance']) {
                throw new \moodle_exception('wdr_flow_error', 'local_grupomakro_core', '', sprintf(
                    'El estudiante tiene deuda pendiente (%s %s en %d factura(s)). Debe ponerse al día antes del retiro.',
                    $bal['currency'], number_format($bal['total'], 2), $bal['invoiceCount']));
            }
            $actor = $DB->get_record('user', ['id' => $actorid], 'id, username, email', MUST_EXIST);
            $res = wdr_manager::call_odoo_proxy('POST', '/api/odoo/wdr/process-retirement', [], [
                'documentNumber' => wdr_manager::resolve_document_number((int)$row->userid),
                'wdrId' => (int)$row->id,
                'reason' => 'Retiro formal ' . $row->request_number . ' ejecutado por ' . $actor->username,
                'force' => false,
                'actor_username' => $actor->username,
                'actor_email' => $actor->email,
                'actor_moodle_id' => (int)$actor->id,
            ]);
            if ((int)$res['status'] !== 200 || empty($res['body']['success'])) {
                $err = is_array($res['body']) ? ($res['body']['message'] ?? $res['body']['error'] ?? '') : ($res['error'] ?? '');
                throw new \moodle_exception('wdr_flow_error', 'local_grupomakro_core', '', 'Odoo no pudo procesar el retiro: ' . $err);
            }
            $b = $res['body'];
            $actions['odoo'] = ['ok' => true, 'at' => time(), 'partner_id' => (int)($b['partner_id'] ?? 0),
                'contract' => (string)($b['odoo_result']['contract'] ?? ''), 'invoices_updated' => (int)($b['invoices_updated'] ?? 0),
                'subscriptions_updated' => (int)($b['subscriptions_updated'] ?? 0)];
            $DB->update_record('gmk_wdr', (object)['id' => $id, 'moodle_actions' => json_encode($actions),
                'process_odoo_partner_id' => $actions['odoo']['partner_id'], 'process_balance_total' => 0,
                'process_invoices_updated' => $actions['odoo']['invoices_updated'],
                'process_subs_updated' => $actions['odoo']['subscriptions_updated']]);
        }

        $userid = (int)$row->userid;
        // 2) Moodle: asignaturas en curso.
        if (empty($actions['courses']['ok'])) {
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/schedule/withdraw_from_course.php');
            $done = $actions['courses']['done'] ?? [];
            $errors = [];
            foreach (self::courses_in_progress($userid) as $c) {
                try {
                    \local_grupomakro_core\external\schedule\withdraw_from_course::execute($userid, $c['courseid'], 1);
                    $done[] = $c['name'];
                } catch (\Throwable $e) {
                    $errors[] = $c['name'] . ': ' . $e->getMessage();
                }
            }
            $actions['courses'] = ['ok' => !$errors, 'done' => $done, 'errors' => $errors];
        }
        // 3) Moodle: solicitudes de modulo por pagar.
        if (empty($actions['pending']['ok'])) {
            $n = 0;
            foreach ($DB->get_records('gmk_module_invoice_requests', ['userid' => $userid, 'status' => 'pending_payment'],
                    '', 'id') as $m) {
                $r = module_invoice_manager::cancel((int)$m->id, $actorid);
                if (!empty($r['ok'])) {
                    $n++;
                }
            }
            $actions['pending'] = ['ok' => true, 'cancelled' => $n];
        }
        // 4) Moodle: estado Retirado (perfil y plan de estudios).
        if (empty($actions['status']['ok'])) {
            $fid = $DB->get_field('user_info_field', 'id', ['shortname' => 'studentstatus']);
            if ($fid) {
                $d = $DB->get_record('user_info_data', ['userid' => $userid, 'fieldid' => $fid]);
                if ($d) {
                    $DB->set_field('user_info_data', 'data', 'retirado', ['id' => $d->id]);
                } else {
                    $DB->insert_record('user_info_data', (object)['userid' => $userid, 'fieldid' => $fid,
                        'data' => 'retirado', 'dataformat' => 0]);
                }
            }
            $DB->execute("UPDATE {local_learning_users} SET status = 'retirado', timemodified = ? WHERE userid = ?",
                [time(), $userid]);
            $actions['status'] = ['ok' => true];
        }
        // 5) Moodle: suspender la cuenta (conserva notas e historial).
        if (empty($actions['suspend']['ok'])) {
            $DB->set_field('user', 'suspended', 1, ['id' => $userid]);
            \core\session\manager::kill_user_sessions($userid);
            $actions['suspend'] = ['ok' => true];
        }

        $allok = !empty($actions['odoo']['ok']) && !empty($actions['courses']['ok']) && !empty($actions['pending']['ok'])
            && !empty($actions['status']['ok']) && !empty($actions['suspend']['ok']);
        $now = time();
        $upd = ['id' => $id, 'moodle_actions' => json_encode($actions), 'timemodified' => $now, 'usermodified' => $actorid,
            'last_activity_at' => $now];
        if ($allok) {
            $upd += ['status' => self::ST_DONE, 'processed_at' => $now, 'processed_by' => $actorid, 'closed_at' => $now,
                'closed_by' => $actorid];
        }
        $DB->update_record('gmk_wdr', (object)$upd);
        self::log($id, 'etapa', $allok ? 'Retiro formal ejecutado: contrato cancelado en Odoo, asignaturas en curso retiradas, '
            . 'estado Retirado y cuenta suspendida.'
            : 'Retiro formal incompleto: ' . implode('; ', $actions['courses']['errors'] ?? []), $actorid);
        \local_grupomakro_core\local\dashboard\dashboard::invalidate_all();
        return ['complete' => $allok, 'actions' => $actions];
    }

    // ── Estado financiero ───────────────────────────────────────────────────

    /**
     * Saldo pendiente en Odoo (facturas publicadas sin pagar).
     */
    public static function balance(int $userid): array {
        $doc = wdr_manager::resolve_document_number($userid);
        if ($doc === '') {
            return ['ok' => false, 'error' => 'El estudiante no tiene número de documento en su perfil.'];
        }
        $res = wdr_manager::call_odoo_proxy('GET', '/api/odoo/wdr/pending-balance', ['documentNumber' => $doc]);
        if ((int)$res['status'] !== 200 || !is_array($res['body']) || empty($res['body']['success'])) {
            $err = is_array($res['body']) ? ($res['body']['message'] ?? $res['body']['error'] ?? '') : ($res['error'] ?? '');
            return ['ok' => false, 'error' => $err ?: ('http ' . $res['status'])];
        }
        $b = $res['body'];
        $inv = [];
        foreach (($b['invoices'] ?? []) as $i) {
            $inv[] = ['name' => (string)($i['name'] ?? ''), 'due' => (string)($i['due'] ?? ''),
                'residual' => (float)($i['residual'] ?? 0), 'overdue' => !empty($i['overdue'])];
        }
        return ['ok' => true, 'hasBalance' => !empty($b['hasBalance']), 'total' => (float)($b['total'] ?? 0),
            'currency' => (string)($b['currency'] ?? 'USD'), 'invoiceCount' => (int)($b['invoiceCount'] ?? 0),
            'overdueCount' => (int)($b['overdueCount'] ?? 0), 'invoices' => $inv, 'error' => ''];
    }

    // ── Utilidades ──────────────────────────────────────────────────────────

    public static function row(int $id): \stdClass {
        global $DB;
        return $DB->get_record('gmk_wdr', ['id' => $id], '*', MUST_EXIST);
    }

    private static function require_stage(int $id, array $stages): \stdClass {
        $row = self::row($id);
        if (!in_array(self::stage((string)$row->status), $stages, true)) {
            throw new \moodle_exception('wdr_flow_error', 'local_grupomakro_core', '', 'La solicitud está en la etapa "'
                . (self::STAGE_LABELS[self::stage((string)$row->status)] ?? $row->status) . '" y no admite esta acción.');
        }
        return $row;
    }

    private static function move(\stdClass $row, string $to, int $actorid, string $note, array $extra = []): \stdClass {
        global $DB;
        $now = time();
        $DB->update_record('gmk_wdr', (object)(['id' => $row->id, 'status' => $to, 'last_activity_at' => $now,
            'timemodified' => $now, 'usermodified' => $actorid] + $extra));
        self::log((int)$row->id, 'etapa', $note, $actorid);
        \local_grupomakro_core\local\dashboard\dashboard::invalidate_all();
        return self::row((int)$row->id);
    }

    public static function log(int $wdrid, string $type, string $body, int $actorid, array $extra = []): int {
        global $DB;
        return (int)$DB->insert_record('gmk_wdr_log', (object)([
            'wdrid' => $wdrid, 'type' => $type, 'body' => $body, 'area' => '', 'assignee' => 0, 'due' => 0,
            'resolved_at' => 0, 'resolved_by' => 0, 'response' => null, 'userid' => $actorid, 'timecreated' => time(),
        ] + $extra));
    }
}
