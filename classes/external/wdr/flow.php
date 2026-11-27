<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Retiros (20261001135): web services del flujo de gestion de la bandeja.
 * Los permisos se validan aqui y en local\wdr_flow.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\external\wdr;

use external_api;
use external_function_parameters;
use external_value;
use local_grupomakro_core\local\wdr_flow;
use local_grupomakro_core\local\wdr_manager;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');

class flow extends external_api {

    // ── Listado ─────────────────────────────────────────────────────────────

    public static function list_parameters(): external_function_parameters {
        return new external_function_parameters([
            'stage' => new external_value(PARAM_ALPHANUMEXT, 'nuevas|gestion|formal|cerradas|escalamientos|mias', VALUE_DEFAULT, ''),
            'search' => new external_value(PARAM_TEXT, '', VALUE_DEFAULT, ''),
        ]);
    }

    public static function list(string $stage = '', string $search = ''): array {
        global $DB, $USER;
        $p = self::validate_parameters(self::list_parameters(), ['stage' => $stage, 'search' => $search]);
        self::validate_context(\context_system::instance());
        $uid = (int)$USER->id;
        $canview = wdr_flow::can_view($uid);
        $myesc = $DB->get_fieldset_sql("SELECT DISTINCT wdrid FROM {gmk_wdr_log}
            WHERE type = 'escalamiento' AND assignee = ? AND resolved_at = 0", [$uid]);
        if (!$canview && !$myesc) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/grupomakro_core:view_wdr_requests', 'nopermissions', '');
        }
        $legacy = "'" . implode("','", wdr_flow::LEGACY_FORMAL) . "'";
        $stages = [
            'nuevas' => "status = 'solicitada'",
            'gestion' => "status = 'en_gestion'",
            'formal' => "status IN ('retiro_formal', $legacy)",
            'cerradas' => "status IN ('procesada','retenida','rechazada','cancelada')",
        ];
        $counts = [];
        if ($canview) {
            foreach ($stages as $k => $w) {
                $counts[$k] = $DB->count_records_select('gmk_wdr', $w);
            }
            $counts['mias'] = $DB->count_records_select('gmk_wdr', "status = 'en_gestion' AND assigned_to = ?", [$uid]);
        }
        $counts['escalamientos'] = count($myesc);

        $where = [];
        $params = [];
        $st = $p['stage'] ?: ($canview ? 'nuevas' : 'escalamientos');
        if ($st === 'escalamientos') {
            if (!$myesc) {
                $where[] = '1 = 0';
            } else {
                [$in, $ip] = $DB->get_in_or_equal($myesc, SQL_PARAMS_NAMED, 'esc');
                $where[] = "w.id $in";
                $params += $ip;
            }
        } else if (!$canview) {
            $where[] = '1 = 0';
        } else if ($st === 'mias') {
            $where[] = "w.status = 'en_gestion' AND w.assigned_to = :me";
            $params['me'] = $uid;
        } else if (isset($stages[$st])) {
            $where[] = 'w.' . $stages[$st];
        }
        if (trim($p['search']) !== '') {
            $q = '%' . trim($p['search']) . '%';
            $where[] = '(' . $DB->sql_like('w.fullname', ':s1', false) . ' OR ' . $DB->sql_like('w.id_number', ':s2', false)
                . ' OR ' . $DB->sql_like('w.request_number', ':s3', false) . ')';
            $params += ['s1' => $q, 's2' => $q, 's3' => $q];
        }
        $rows = $DB->get_records_sql("SELECT w.*, fs.status AS finstatus,
                (SELECT COUNT(1) FROM {gmk_wdr_log} l WHERE l.wdrid = w.id AND l.type = 'escalamiento' AND l.resolved_at = 0) openesc
              FROM {gmk_wdr} w
         LEFT JOIN {gmk_financial_status} fs ON fs.userid = w.userid
             WHERE " . ($where ? implode(' AND ', $where) : '1=1') . "
          ORDER BY CASE WHEN w.next_followup > 0 THEN w.next_followup ELSE w.timecreated END ASC", $params, 0, 300);
        $out = [];
        foreach ($rows as $r) {
            $out[] = self::row_summary($r);
        }
        return ['stage' => $st, 'counts' => $counts, 'rows' => $out,
            'canmanage' => wdr_flow::can_manage($uid), 'canformal' => wdr_flow::can_formal($uid), 'canview' => $canview];
    }

    public static function list_returns() {
        return null;
    }

    // ── Detalle ─────────────────────────────────────────────────────────────

    public static function detail_parameters(): external_function_parameters {
        return new external_function_parameters(['id' => new external_value(PARAM_INT, '')]);
    }

    public static function detail(int $id): array {
        global $USER;
        $p = self::validate_parameters(self::detail_parameters(), ['id' => $id]);
        self::validate_context(\context_system::instance());
        wdr_flow::require_view($p['id'], (int)$USER->id);
        return self::build_detail($p['id']);
    }

    public static function detail_returns() {
        return null;
    }

    // ── Estado financiero (Odoo, en vivo) ───────────────────────────────────

    public static function balance_parameters(): external_function_parameters {
        return new external_function_parameters(['id' => new external_value(PARAM_INT, '')]);
    }

    public static function balance(int $id): array {
        global $USER;
        $p = self::validate_parameters(self::balance_parameters(), ['id' => $id]);
        self::validate_context(\context_system::instance());
        wdr_flow::require_view($p['id'], (int)$USER->id);
        $row = wdr_flow::row($p['id']);
        return wdr_flow::balance((int)$row->userid);
    }

    public static function balance_returns() {
        return null;
    }

    // ── Acciones ────────────────────────────────────────────────────────────

    public static function action_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Solicitud (o id del escalamiento en resolve_escalation)'),
            'action' => new external_value(PARAM_ALPHANUMEXT, ''),
            'payload' => new external_value(PARAM_RAW, 'JSON', VALUE_DEFAULT, '{}'),
        ]);
    }

    public static function action(int $id, string $action, string $payload = '{}'): array {
        global $USER;
        $p = self::validate_parameters(self::action_parameters(), ['id' => $id, 'action' => $action, 'payload' => $payload]);
        self::validate_context(\context_system::instance());
        $d = json_decode($p['payload'], true) ?: [];
        $uid = (int)$USER->id;
        $text = (string)($d['text'] ?? '');
        $manage = ['take', 'activity', 'escalate', 'payment_instruction', 'retained', 'to_formal', 'void'];
        $formal = ['check', 'scan_uploaded', 'execute', 'return_to_management'];
        if (in_array($p['action'], $manage, true) && !wdr_flow::can_manage($uid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/grupomakro_core:manage_wdr_requests', 'nopermissions', '');
        }
        if (in_array($p['action'], $formal, true) && !wdr_flow::can_formal($uid)) {
            throw new \required_capability_exception(\context_system::instance(),
                'local/grupomakro_core:process_wdr_formal', 'nopermissions', '');
        }
        $wdrid = $p['id'];
        $result = null;
        switch ($p['action']) {
            case 'take':
                wdr_flow::take($wdrid, $uid);
                break;
            case 'activity':
                wdr_flow::add_activity($wdrid, (string)($d['type'] ?? ''), $text, (int)($d['next_followup'] ?? 0), $uid);
                break;
            case 'escalate':
                wdr_flow::escalate($wdrid, (string)($d['area'] ?? ''), (int)($d['assignee'] ?? 0), $text,
                    (int)($d['due'] ?? 0), $uid);
                break;
            case 'resolve_escalation':
                $row = wdr_flow::resolve_escalation($wdrid, $text, $uid);
                $wdrid = (int)$row->id;
                break;
            case 'payment_instruction':
                wdr_flow::record_payment_instruction($wdrid, $text, $uid);
                break;
            case 'retained':
                wdr_flow::close_retained($wdrid, $text, $uid);
                break;
            case 'to_formal':
                wdr_flow::send_to_formal($wdrid, $text, $uid);
                break;
            case 'void':
                if (!wdr_flow::can_manage($uid) && !wdr_flow::can_formal($uid)) {
                    throw new \required_capability_exception(\context_system::instance(),
                        'local/grupomakro_core:manage_wdr_requests', 'nopermissions', '');
                }
                wdr_flow::void_request($wdrid, $text, $uid);
                break;
            case 'check':
                wdr_flow::check($wdrid, (string)($d['item'] ?? ''), $uid);
                break;
            case 'scan_uploaded':
                wdr_flow::after_scan_upload($wdrid, $uid);
                break;
            case 'return_to_management':
                wdr_flow::return_to_management($wdrid, $text, $uid);
                break;
            case 'execute':
                $result = wdr_flow::execute_formal($wdrid, $uid);
                break;
            default:
                throw new \moodle_exception('wdr_flow_error', 'local_grupomakro_core', '', 'Acción no válida');
        }
        $out = self::build_detail($wdrid);
        if ($result !== null) {
            $out['execution'] = $result;
        }
        return $out;
    }

    public static function action_returns() {
        return null;
    }

    // ── Crear solicitud (Bienestar) ─────────────────────────────────────────

    public static function create_parameters(): external_function_parameters {
        return new external_function_parameters([
            'studentid' => new external_value(PARAM_INT, ''),
            'channel' => new external_value(PARAM_ALPHA, ''),
            'reason' => new external_value(PARAM_ALPHA, ''),
            'observations' => new external_value(PARAM_TEXT, '', VALUE_DEFAULT, ''),
        ]);
    }

    public static function create(int $studentid, string $channel, string $reason, string $observations = ''): array {
        global $USER;
        $p = self::validate_parameters(self::create_parameters(), ['studentid' => $studentid, 'channel' => $channel,
            'reason' => $reason, 'observations' => $observations]);
        self::validate_context(\context_system::instance());
        require_capability('local/grupomakro_core:manage_wdr_requests', \context_system::instance());
        $rec = wdr_flow::create_manual($p['studentid'], $p['channel'], $p['reason'], $p['observations'], (int)$USER->id);
        \local_grupomakro_core\local\dashboard\dashboard::invalidate_all();
        return self::build_detail((int)$rec->id);
    }

    public static function create_returns() {
        return null;
    }

    // ── Busqueda de estudiantes y personal ──────────────────────────────────

    public static function students_parameters(): external_function_parameters {
        return new external_function_parameters(['query' => new external_value(PARAM_TEXT, '')]);
    }

    public static function students(string $query): array {
        global $DB;
        $p = self::validate_parameters(self::students_parameters(), ['query' => $query]);
        self::validate_context(\context_system::instance());
        require_capability('local/grupomakro_core:manage_wdr_requests', \context_system::instance());
        $q = trim($p['query']);
        if (\core_text::strlen($q) < 3) {
            return [];
        }
        $like = '%' . $q . '%';
        $rows = $DB->get_records_sql("SELECT u.id, u.firstname, u.lastname, u.email, u.username, u.suspended,
                    doc.data AS doc,
                    (SELECT lp.name FROM {local_learning_users} lu JOIN {local_learning_plans} lp ON lp.id = lu.learningplanid
                      WHERE lu.userid = u.id ORDER BY lu.id DESC LIMIT 1) AS program,
                    (SELECT w.request_number FROM {gmk_wdr} w WHERE w.userid = u.id AND w.status IN
                      ('solicitada','en_gestion','retiro_formal') LIMIT 1) AS openrequest
               FROM {user} u
               JOIN {user_info_data} ut ON ut.userid = u.id
               JOIN {user_info_field} ft ON ft.id = ut.fieldid AND ft.shortname = 'usertype'
          LEFT JOIN {user_info_data} doc ON doc.userid = u.id
                    AND doc.fieldid = (SELECT id FROM {user_info_field} WHERE shortname = 'documentnumber')
              WHERE u.deleted = 0 AND ut.data = 'Estudiante'
                AND (" . $DB->sql_like($DB->sql_fullname('u.firstname', 'u.lastname'), ':q1', false) . "
                     OR " . $DB->sql_like('u.username', ':q2', false) . "
                     OR " . $DB->sql_like('doc.data', ':q3', false) . ")
           ORDER BY u.firstname, u.lastname", ['q1' => $like, 'q2' => $like, 'q3' => $like], 0, 15);
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['id' => (int)$r->id, 'fullname' => trim($r->firstname . ' ' . $r->lastname),
                'document' => (string)($r->doc ?: $r->username), 'email' => (string)$r->email,
                'program' => (string)$r->program, 'suspended' => (int)$r->suspended === 1,
                'openrequest' => (string)$r->openrequest];
        }
        return $out;
    }

    public static function students_returns() {
        return null;
    }

    public static function staff_parameters(): external_function_parameters {
        return new external_function_parameters(['area' => new external_value(PARAM_ALPHANUMEXT, '')]);
    }

    /** Personas para escalar: primero las del rol sugerido del area, luego el resto del personal. */
    public static function staff(string $area): array {
        global $DB;
        $p = self::validate_parameters(self::staff_parameters(), ['area' => $area]);
        self::validate_context(\context_system::instance());
        require_capability('local/grupomakro_core:manage_wdr_requests', \context_system::instance());
        $suggested = wdr_flow::AREAS[$p['area']]['roles'] ?? [];
        $rows = $DB->get_records_sql("SELECT DISTINCT u.id, u.firstname, u.lastname, r.shortname, r.name AS rolename
               FROM {role_assignments} ra
               JOIN {role} r ON r.id = ra.roleid AND (r.shortname LIKE 'gmk\\_%' OR r.shortname = 'manager')
               JOIN {user} u ON u.id = ra.userid AND u.deleted = 0 AND u.suspended = 0
              WHERE ra.contextid = :ctx", ['ctx' => \context_system::instance()->id]);
        $out = [];
        foreach ($rows as $r) {
            $k = (int)$r->id;
            $sug = in_array($r->shortname, $suggested, true);
            if (!isset($out[$k]) || $sug) {
                $out[$k] = ['id' => $k, 'fullname' => trim($r->firstname . ' ' . $r->lastname),
                    'role' => (string)($r->rolename ?: $r->shortname), 'suggested' => $sug];
            }
        }
        $out = array_values($out);
        usort($out, function($a, $b) {
            return [$b['suggested'], $a['fullname']] <=> [$a['suggested'], $b['fullname']];
        });
        return $out;
    }

    public static function staff_returns() {
        return null;
    }

    // ── PDF RET-01 ──────────────────────────────────────────────────────────

    public static function pdf_parameters(): external_function_parameters {
        return new external_function_parameters(['id' => new external_value(PARAM_INT, '')]);
    }

    public static function pdf(int $id): array {
        global $USER;
        $p = self::validate_parameters(self::pdf_parameters(), ['id' => $id]);
        self::validate_context(\context_system::instance());
        wdr_flow::require_view($p['id'], (int)$USER->id);
        $row = wdr_flow::row($p['id']);
        return ['filename' => 'RET-01_' . $row->request_number . '.pdf',
            'base64' => base64_encode(wdr_manager::render_pdf($p['id']))];
    }

    public static function pdf_returns() {
        return null;
    }

    // ── Construccion de respuestas ──────────────────────────────────────────

    private static function name(int $userid): string {
        global $DB;
        static $cache = [];
        if ($userid <= 0) {
            return '';
        }
        if (!isset($cache[$userid])) {
            $u = $DB->get_record('user', ['id' => $userid], 'id, firstname, lastname');
            $cache[$userid] = $u ? trim($u->firstname . ' ' . $u->lastname) : '';
        }
        return $cache[$userid];
    }

    private static function row_summary(\stdClass $r): array {
        $stage = wdr_flow::stage((string)$r->status);
        $now = time();
        $next = '';
        switch ($stage) {
            case 'solicitada':
                $next = 'Tomar el caso y contactar al estudiante';
                break;
            case 'en_gestion':
                if ((int)$r->next_followup > 0 && (int)$r->next_followup <= $now) {
                    $next = 'Seguimiento vencido';
                } else if ((int)$r->next_followup > 0) {
                    $next = 'Seguimiento el ' . userdate((int)$r->next_followup, '%d/%m %H:%M');
                } else {
                    $next = 'Registrar la gestión o decidir';
                }
                break;
            case 'retiro_formal':
                $next = 'Completar el checklist y ejecutar el retiro';
                break;
        }
        return [
            'id' => (int)$r->id, 'request_number' => (string)$r->request_number, 'fullname' => (string)$r->fullname,
            'id_number' => (string)$r->id_number, 'program' => (string)$r->program, 'status' => (string)$r->status,
            'stage' => $stage, 'stage_label' => wdr_flow::STAGE_LABELS[$stage] ?? $stage,
            'channel' => (string)($r->channel ?? 'portal'),
            'channel_label' => wdr_flow::CHANNELS[$r->channel ?? 'portal'] ?? 'Portal del estudiante',
            'reason_label' => wdr_flow::REASONS[$r->reason] ?? (string)$r->reason,
            'assigned_to' => (int)($r->assigned_to ?? 0), 'assigned_name' => self::name((int)($r->assigned_to ?? 0)),
            'next_followup' => (int)($r->next_followup ?? 0),
            'followup_overdue' => (int)($r->next_followup ?? 0) > 0 && (int)$r->next_followup <= $now,
            'open_escalations' => (int)($r->openesc ?? 0),
            'finstatus' => (string)($r->finstatus ?? ''),
            'next_step' => $next, 'timecreated' => (int)$r->timecreated,
            'last_activity_at' => (int)($r->last_activity_at ?: $r->timemodified),
        ];
    }

    public static function build_detail(int $id): array {
        global $DB, $USER;
        $r = wdr_flow::row($id);
        $r->openesc = $DB->count_records('gmk_wdr_log', ['wdrid' => $id, 'type' => 'escalamiento', 'resolved_at' => 0]);
        $r->finstatus = (string)$DB->get_field('gmk_financial_status', 'status', ['userid' => $r->userid]);
        $out = self::row_summary($r);
        $uid = (int)$USER->id;
        $out += [
            'email' => (string)$r->email, 'phone' => (string)$r->phone, 'current_period' => (string)$r->current_period,
            'observations' => (string)$r->observations, 'payment_option' => (string)$r->payment_option,
            'payment_option_detail' => (string)$r->payment_option_detail,
            'rejection_reason' => (string)$r->rejection_reason,
            'created_by_name' => self::name((int)$r->created_by),
            'payment_instructed_at' => (int)$r->payment_instructed_at,
            'formal_requested_at' => (int)$r->formal_requested_at,
            'formal_requested_by_name' => self::name((int)$r->formal_requested_by),
            'closed_at' => (int)$r->closed_at, 'closed_by_name' => self::name((int)$r->closed_by),
            'has_scanned' => !empty($r->scanned_pdf_path),
            'moodle_actions' => json_decode((string)($r->moodle_actions ?? ''), true) ?: new \stdClass(),
            'student_suspended' => (int)$DB->get_field('user', 'suspended', ['id' => $r->userid]) === 1,
            'profile_url' => (new \moodle_url('/user/profile.php', ['id' => $r->userid]))->out(false),
            'canmanage' => wdr_flow::can_manage($uid), 'canformal' => wdr_flow::can_formal($uid),
            'canview' => wdr_flow::can_view($uid), 'me' => $uid,
        ];
        $cl = [];
        foreach (wdr_flow::checklist($r) as $k => $c) {
            $cl[] = ['key' => $k, 'label' => wdr_flow::CHECKLIST[$k], 'done' => $c['done'], 'at' => $c['at'],
                'by' => self::name($c['by'])];
        }
        $out['checklist'] = $cl;
        if ($out['stage'] === 'retiro_formal') {
            $out['preview'] = wdr_flow::preview_formal($r);
        }
        $log = [];
        foreach ($DB->get_records('gmk_wdr_log', ['wdrid' => $id], 'timecreated DESC, id DESC') as $l) {
            $log[] = [
                'id' => (int)$l->id, 'type' => (string)$l->type,
                'type_label' => wdr_flow::LOG_TYPES[$l->type] ?? ['escalamiento' => 'Escalamiento',
                    'instruccion_pago' => 'Instrucción de pago', 'etapa' => 'Cambio de etapa'][$l->type] ?? $l->type,
                'body' => (string)$l->body, 'author' => self::name((int)$l->userid), 'at' => (int)$l->timecreated,
                'area' => (string)$l->area, 'area_label' => wdr_flow::AREAS[$l->area]['label'] ?? '',
                'assignee' => (int)$l->assignee, 'assignee_name' => self::name((int)$l->assignee),
                'due' => (int)$l->due, 'resolved_at' => (int)$l->resolved_at,
                'resolved_by_name' => self::name((int)$l->resolved_by), 'response' => (string)($l->response ?? ''),
            ];
        }
        $out['log'] = $log;
        return $out;
    }

    // ── Catalogos para la UI ────────────────────────────────────────────────

    public static function catalogs(): array {
        $f = function($arr) {
            $o = [];
            foreach ($arr as $k => $v) {
                $o[] = ['value' => $k, 'text' => is_array($v) ? $v['label'] : $v];
            }
            return $o;
        };
        $channels = wdr_flow::CHANNELS;
        unset($channels['portal']);
        return ['channels' => $f($channels), 'reasons' => $f(wdr_flow::REASONS), 'logtypes' => $f(wdr_flow::LOG_TYPES),
            'areas' => $f(wdr_flow::AREAS), 'checklist' => $f(wdr_flow::CHECKLIST)];
    }
}
