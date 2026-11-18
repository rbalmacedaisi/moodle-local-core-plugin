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
 * Mesa de ayuda (fase 1): tickets, maquina de estados, acceso y asignacion.
 *
 * Ver docs/planning/PLAN_TICKETS_SOPORTE.md. Toda decision de acceso pasa por
 * esta clase; los WS solo le pasan $USER->id y los ids que manda el cliente.
 *
 * Reglas de acceso:
 *  - El solicitante (ticket.userid) ve su ticket y nunca las notas internas.
 *  - Personal, ticket NO confidencial: el asignado, los agentes (o respaldos)
 *    de cualquier tema del area y quien supervisa (support_supervise o
 *    gmk_tkt_person).
 *  - Personal, ticket confidencial: solo el asignado, los agentes (o
 *    respaldos) de ESE tema y quien tiene support_viewconfidential (sin el
 *    "doanything" del administrador) o es gerencia en gmk_tkt_person. Aplica
 *    aunque el lector sea supervisor.
 *
 * Maquina de estados (§5): cada cambio pasa por set_status(), que valida
 * contra TRANSITIONS y deja un registro en gmk_tkt_event.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\support;

use context_system;
use moodle_exception;
use stdClass;

defined('MOODLE_INTERNAL') || die();

class manager {

    const S_NEW = 'nuevo';
    const S_ASSIGNED = 'asignado';
    const S_INPROGRESS = 'en_atencion';
    const S_WAITING = 'esperando_estudiante';
    const S_RESOLVED = 'resuelto';
    const S_CLOSED = 'cerrado';
    const S_REOPENED = 'reabierto';
    const S_CANCELLED = 'cancelado';

    /** Allowed status changes. Same-status updates are not transitions. */
    const TRANSITIONS = [
        self::S_NEW        => [self::S_ASSIGNED, self::S_INPROGRESS, self::S_WAITING, self::S_CANCELLED],
        self::S_ASSIGNED   => [self::S_NEW, self::S_INPROGRESS, self::S_WAITING, self::S_RESOLVED, self::S_CLOSED,
            self::S_CANCELLED],
        self::S_INPROGRESS => [self::S_WAITING, self::S_RESOLVED, self::S_CLOSED],
        self::S_WAITING    => [self::S_INPROGRESS, self::S_RESOLVED, self::S_CLOSED],
        self::S_RESOLVED   => [self::S_CLOSED, self::S_REOPENED, self::S_INPROGRESS, self::S_WAITING],
        self::S_REOPENED   => [self::S_INPROGRESS, self::S_WAITING, self::S_RESOLVED, self::S_CLOSED],
        self::S_CLOSED     => [],
        self::S_CANCELLED  => [],
    ];

    const STAFF_LABELS = [
        self::S_NEW => 'Nuevo', self::S_ASSIGNED => 'Asignado', self::S_INPROGRESS => 'En atención',
        self::S_WAITING => 'Esperando estudiante', self::S_RESOLVED => 'Resuelto', self::S_REOPENED => 'Reabierto',
        self::S_CLOSED => 'Cerrado', self::S_CANCELLED => 'Cancelado',
    ];

    const REQUESTER_LABELS = [
        self::S_NEW => 'Recibida', self::S_ASSIGNED => 'Recibida', self::S_INPROGRESS => 'En atención',
        self::S_WAITING => 'Te pedimos información', self::S_RESOLVED => 'Resuelta', self::S_REOPENED => 'Reabierta',
        self::S_CLOSED => 'Cerrada', self::S_CANCELLED => 'Cancelada',
    ];

    const PRIORITY_LOW = 0;
    const PRIORITY_NORMAL = 1;
    const PRIORITY_HIGH = 2;

    const ROLE_REQUESTER = 'student';
    const ROLE_AGENT = 'agent';
    const ROLE_SUPERVISOR = 'supervisor';
    const ROLE_SYSTEM = 'system';

    const MAX_BODY = 4000;
    const MAX_SUBJECT = 200;
    const RATE_LIMIT = 30;
    const EXCERPT_LENGTH = 140;

    /** Minimum seconds between two "out of hours" automatic messages on one ticket. */
    const OUT_OF_HOURS_GAP = 12 * HOURSECS;

    /** @var array per-request cache of staff profiles */
    private static $profiles = [];

    /** @var array notifications queued until the transaction commits */
    private static $outbox = [];

    // ---------------------------------------------------------------------
    // Status helpers
    // ---------------------------------------------------------------------

    public static function open_statuses(): array {
        return [self::S_NEW, self::S_ASSIGNED, self::S_INPROGRESS, self::S_WAITING, self::S_RESOLVED, self::S_REOPENED];
    }

    public static function is_open_status(string $status): bool {
        return in_array($status, self::open_statuses(), true);
    }

    public static function can_transition(string $from, string $to): bool {
        return $from === $to || in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    // ---------------------------------------------------------------------
    // Staff profile and access
    // ---------------------------------------------------------------------

    /**
     * What $userid is in the help desk. Cached per request.
     *
     * @return stdClass {cats int[], areas int[], agentcats int[], supervisor bool, viewconf bool, manage bool, isstaff bool}
     */
    public static function profile(int $userid): stdClass {
        global $DB;
        if (isset(self::$profiles[$userid])) {
            return self::$profiles[$userid];
        }
        $context = context_system::instance();
        $agentcats = array_map('intval', $DB->get_fieldset_select('gmk_tkt_agent', 'categoryid', 'userid = ?', [$userid]));

        $backupcats = [];
        foreach ($DB->get_records('gmk_tkt_backup', ['backupuserid' => $userid]) as $b) {
            if ((int)$b->categoryid > 0) {
                $backupcats[] = (int)$b->categoryid;
            } else {
                $backupcats = array_merge($backupcats, array_map('intval',
                    $DB->get_fieldset_select('gmk_tkt_agent', 'categoryid', 'userid = ?', [(int)$b->userid])));
            }
        }
        $cats = array_values(array_unique(array_merge($agentcats, $backupcats)));
        $areas = [];
        if ($cats) {
            [$in, $params] = $DB->get_in_or_equal($cats);
            $areas = array_values(array_unique(array_map('intval',
                $DB->get_fieldset_select('gmk_tkt_category', 'areaid', "id $in", $params))));
        }
        $kinds = $DB->get_fieldset_select('gmk_tkt_person', 'kind', 'userid = ?', [$userid]);
        $isgerencia = in_array('gerencia', $kinds, true);

        $p = new stdClass();
        $p->agentcats = $agentcats;
        $p->cats = $cats;
        $p->areas = $areas;
        $p->supervisor = $isgerencia || in_array('supervisor', $kinds, true)
            || has_capability('local/grupomakro_core:support_supervise', $context, $userid);
        // No "doanything": being site admin does not open confidential tickets.
        $p->viewconf = $isgerencia
            || has_capability('local/grupomakro_core:support_viewconfidential', $context, $userid, false);
        $p->manage = has_capability('local/grupomakro_core:support_manage', $context, $userid);
        // Reasignar cualquier ticket no confidencial (supervisores, gerencia y TI).
        // No convierte en supervisor: no recibe alertas de 1 h y 3 h.
        $p->reassign = $p->supervisor
            || has_capability('local/grupomakro_core:support_reassign', $context, $userid);
        $p->isstaff = !empty($cats) || $p->supervisor || $p->viewconf || $p->reassign
            || has_capability('local/grupomakro_core:support_agent', $context, $userid);
        self::$profiles[$userid] = $p;
        return $p;
    }

    public static function reset_caches(): void {
        self::$profiles = [];
        self::$teachers = [];
        policy::reset_caches();
    }

    /**
     * Whether staff member $userid may read $ticket (requester rules aside).
     */
    public static function staff_can_view(stdClass $ticket, int $userid): bool {
        $p = self::profile($userid);
        if (!$p->isstaff) {
            return false;
        }
        if ((int)$ticket->assignedto === $userid) {
            return true;
        }
        if (!empty($ticket->confidential)) {
            return $p->viewconf || in_array((int)$ticket->categoryid, $p->cats, true);
        }
        return $p->supervisor || $p->reassign || in_array((int)$ticket->areaid, $p->areas, true);
    }

    /**
     * Whether $userid may (re)assign $ticket. Confidential tickets: only their
     * topic agents (Psicologia) and gerencia. Others: the assignee, supervisors
     * and whoever has support_reassign (gerencia, TI...).
     */
    public static function may_reassign(stdClass $ticket, int $userid): bool {
        $p = self::profile($userid);
        if (!empty($ticket->confidential)) {
            return $p->viewconf || in_array((int)$ticket->categoryid, $p->agentcats, true);
        }
        return $p->reassign || (int)$ticket->assignedto === $userid;
    }

    /**
     * Role of $userid on $ticket, or exception.
     */
    public static function resolve_role(stdClass $ticket, int $userid): string {
        if ($userid > 0 && (int)$ticket->userid === $userid) {
            return self::ROLE_REQUESTER;
        }
        if (self::staff_can_view($ticket, $userid)) {
            $p = self::profile($userid);
            $isagent = (int)$ticket->assignedto === $userid || in_array((int)$ticket->areaid, $p->areas, true);
            return $isagent ? self::ROLE_AGENT : self::ROLE_SUPERVISOR;
        }
        throw new moodle_exception('tkt_err_noaccess', 'local_grupomakro_core');
    }

    /**
     * @return array [ticket, role]
     */
    public static function require_ticket(int $ticketid, int $userid): array {
        global $DB;
        $ticket = $DB->get_record('gmk_tkt_ticket', ['id' => $ticketid]);
        if (!$ticket) {
            throw new moodle_exception('tkt_err_notfound', 'local_grupomakro_core');
        }
        return [$ticket, self::resolve_role($ticket, $userid)];
    }

    public static function require_staff(int $userid): stdClass {
        $p = self::profile($userid);
        if (!$p->isstaff) {
            throw new moodle_exception('tkt_err_nostaff', 'local_grupomakro_core');
        }
        return $p;
    }

    // ---------------------------------------------------------------------
    // Catalog
    // ---------------------------------------------------------------------

    /**
     * Active areas with their active topics, as the requester sees them.
     */
    public static function catalog(int $userid, string $audience = 'student'): array {
        global $DB;
        $conditions = ['active' => 1];
        if ($audience === 'teacher') {
            // Docentes (§8.4): solo las areas marcadas "disponible para docentes".
            $conditions['teacher_visible'] = 1;
        }
        $areas = $DB->get_records('gmk_tkt_area', $conditions, 'sortorder ASC, id ASC');
        $cats = $DB->get_records('gmk_tkt_category', ['active' => 1], 'sortorder ASC, id ASC');
        $user = \core_user::get_user($userid);
        $out = [];
        foreach ($areas as $area) {
            $topics = [];
            foreach ($cats as $c) {
                if ((int)$c->areaid !== (int)$area->id) {
                    continue;
                }
                $topics[] = [
                    'id' => (int)$c->id,
                    'name' => format_string($c->name),
                    'description' => (string)$c->description,
                    'confidential' => (bool)$c->confidential,
                    'requires_attachment' => (bool)$c->requires_attachment,
                ];
            }
            $external = trim((string)$area->external_url) !== '';
            if (!$external && empty($topics)) {
                continue;
            }
            $message = '';
            if ($external && $user) {
                $message = strtr((string)$area->external_message, [
                    '{estudiante}' => fullname($user),
                    '{cedula}' => self::document_number($user),
                ]);
            }
            $out[] = [
                'id' => (int)$area->id,
                'code' => (string)$area->code,
                'name' => format_string($area->name),
                'description' => (string)$area->description,
                'icon' => (string)$area->icon,
                'hours' => business_hours::describe($area),
                'isopen' => business_hours::is_open($area, time()),
                'external' => $external,
                'external_url' => $external ? self::external_link($area, $message) : '',
                'external_label' => (string)$area->external_label,
                'topics' => $topics,
            ];
        }
        return $out;
    }

    /**
     * Cedula of the user: custom profile field documentnumber, else idnumber.
     */
    public static function document_number(stdClass $user): string {
        global $DB;
        $value = $DB->get_field_sql(
            "SELECT d.data
               FROM {user_info_data} d
               JOIN {user_info_field} f ON f.id = d.fieldid
              WHERE f.shortname = :sn AND d.userid = :u",
            ['sn' => 'documentnumber', 'u' => (int)$user->id]);
        $value = trim((string)$value);
        return $value !== '' ? $value : (string)($user->idnumber ?? '');
    }

    /**
     * @return array userid => non-empty documentnumber
     */
    private static function document_numbers(array $userids): array {
        global $DB;
        $userids = array_values(array_unique(array_filter($userids)));
        if (!$userids) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'dn');
        $params['sn'] = 'documentnumber';
        $out = [];
        $rows = $DB->get_records_sql("SELECT d.userid, d.data
                                        FROM {user_info_data} d
                                        JOIN {user_info_field} f ON f.id = d.fieldid
                                       WHERE f.shortname = :sn AND d.userid $in", $params);
        foreach ($rows as $r) {
            if (trim((string)$r->data) !== '') {
                $out[(int)$r->userid] = trim((string)$r->data);
            }
        }
        return $out;
    }

    /**
     * External URL with the initial message appended (wa.me understands ?text=).
     */
    private static function external_link(stdClass $area, string $message): string {
        $url = trim((string)$area->external_url);
        if ($message === '') {
            return $url;
        }
        return $url . (strpos($url, '?') === false ? '?' : '&') . 'text=' . rawurlencode($message);
    }

    // ---------------------------------------------------------------------
    // Requester operations
    // ---------------------------------------------------------------------

    /**
     * Creates a ticket for $userid, assigns it and posts the automatic messages.
     */
    public static function create(int $userid, int $categoryid, string $subject, string $body, int $classid = 0,
            string $audience = 'student'): stdClass {
        global $DB;
        require_capability('local/grupomakro_core:support_create', context_system::instance(), $userid);

        $category = $DB->get_record('gmk_tkt_category', ['id' => $categoryid, 'active' => 1]);
        $area = $category ? $DB->get_record('gmk_tkt_area', ['id' => (int)$category->areaid, 'active' => 1]) : null;
        if (!$category || !$area || trim((string)$area->external_url) !== '') {
            throw new moodle_exception('tkt_err_badcategory', 'local_grupomakro_core');
        }
        if ($audience === 'teacher') {
            // Docentes (§8.4): solo areas "disponible para docentes" y solo quien es profesor.
            if (empty($area->teacher_visible) || !self::is_teacher($userid)) {
                throw new moodle_exception('tkt_err_badcategory', 'local_grupomakro_core');
            }
        } else {
            $audience = 'student';
        }

        $body = self::clean_body($body);
        if ($body === '') {
            throw new moodle_exception('tkt_err_empty', 'local_grupomakro_core');
        }
        if (\core_text::strlen($body) > self::MAX_BODY) {
            throw new moodle_exception('tkt_err_toolong', 'local_grupomakro_core', '', self::MAX_BODY);
        }
        $subject = trim(preg_replace('/\s+/u', ' ', strip_tags($subject)) ?? '');
        if ($subject === '') {
            $subject = (string)$category->name;
        }
        $subject = \core_text::substr($subject, 0, self::MAX_SUBJECT);

        $now = time();
        [$inopen, $openparams] = $DB->get_in_or_equal(self::open_statuses(), SQL_PARAMS_NAMED, 'st');
        $open = $DB->count_records_select('gmk_tkt_ticket', "userid = :u AND status $inopen",
            ['u' => $userid] + $openparams);
        if ($open >= config::get_int('tkt_max_open')) {
            throw new moodle_exception('tkt_err_maxopen', 'local_grupomakro_core', '', config::get_int('tkt_max_open'));
        }
        $today = $DB->count_records_select('gmk_tkt_ticket', 'userid = :u AND timecreated > :since',
            ['u' => $userid, 'since' => $now - DAYSECS]);
        if ($today >= config::get_int('tkt_max_daily')) {
            throw new moodle_exception('tkt_err_maxdaily', 'local_grupomakro_core', '', config::get_int('tkt_max_daily'));
        }
        if ($classid > 0 && !$DB->record_exists('gmk_class', ['id' => $classid])) {
            $classid = 0;
        }

        $year = (int)self::local_datetime($now)->format('Y');
        self::ensure_seq_row($year);

        $transaction = $DB->start_delegated_transaction();
        $ticket = (object)[
            'number' => self::next_number($year),
            'userid' => $userid,
            'requesterrole' => $audience,
            'areaid' => (int)$area->id,
            'categoryid' => (int)$category->id,
            'subject' => $subject,
            'status' => self::S_NEW,
            'priority' => (int)$category->priority_default,
            'confidential' => (int)!empty($category->confidential),
            'assignedto' => 0,
            'classid' => $classid,
            'learningplanid' => 0,
            'first_response_at' => 0,
            'first_response_due' => 0,
            'resolution_due' => 0,
            'waiting_since' => $now,
            'resolved_at' => 0,
            'closed_at' => 0,
            'reopened_count' => 0,
            'escalation_level' => 0,
            'escalated_at' => 0,
            'assigned_at' => 0,
            'last_student_msg_at' => $now,
            'last_staff_msg_at' => 0,
            'lastmessageid' => 0,
            'lastmessageat' => 0,
            'rating' => 0,
            'rating_comment' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $ticket->id = $DB->insert_record('gmk_tkt_ticket', $ticket);
        self::log_event((int)$ticket->id, 'created', '', self::S_NEW, $userid,
            ['categoryid' => (int)$category->id, 'areaid' => (int)$area->id]);

        $message = self::insert_message($ticket, $userid, self::ROLE_REQUESTER, $body, false);
        self::set_read_pointer((int)$ticket->id, $userid, (int)$message->id);

        $agentid = self::pick_agent($category);
        if ($agentid > 0) {
            self::apply_assignment($ticket, $agentid, 0, 'auto');
        }

        $vars = self::template_vars($ticket, $area, $category);
        self::system_message($ticket, 'created', $vars);
        if (!business_hours::is_open($area, $now)) {
            self::out_of_hours_message($ticket, $area, $vars);
        }
        $transaction->allow_commit();

        if ($agentid > 0) {
            self::queue_notification('support_assigned', [$agentid], $ticket, $message);
        } else {
            self::queue_notification('support_new_ticket', self::category_staff($category), $ticket, $message);
        }
        self::flush_notifications();
        return $DB->get_record('gmk_tkt_ticket', ['id' => (int)$ticket->id]);
    }

    /**
     * Posts a message. Staff may mark it internal (note) or ask for information (waiting).
     *
     * @return array [message, ticket]
     */
    public static function send(int $ticketid, int $userid, string $body, bool $internal = false,
            bool $askinfo = false): array {
        global $DB;
        [$ticket, $role] = self::require_ticket($ticketid, $userid);
        if (!self::is_open_status((string)$ticket->status)) {
            throw new moodle_exception('tkt_err_closed', 'local_grupomakro_core');
        }
        $isrequester = $role === self::ROLE_REQUESTER;
        if ($isrequester) {
            $internal = false;
            $askinfo = false;
        }

        $body = self::clean_body($body);
        if ($body === '') {
            throw new moodle_exception('tkt_err_empty', 'local_grupomakro_core');
        }
        if (\core_text::strlen($body) > self::MAX_BODY) {
            throw new moodle_exception('tkt_err_toolong', 'local_grupomakro_core', '', self::MAX_BODY);
        }
        $now = time();
        $recent = $DB->count_records_select('gmk_tkt_message', 'senderid = :u AND timecreated > :since',
            ['u' => $userid, 'since' => $now - HOURSECS]);
        if ($recent >= self::RATE_LIMIT) {
            throw new moodle_exception('tkt_err_ratelimit', 'local_grupomakro_core');
        }

        $area = $DB->get_record('gmk_tkt_area', ['id' => (int)$ticket->areaid]);
        $category = $DB->get_record('gmk_tkt_category', ['id' => (int)$ticket->categoryid]);
        $notify = null;

        $transaction = $DB->start_delegated_transaction();
        $message = self::insert_message($ticket, $userid, $role, $body, $internal);
        self::set_read_pointer((int)$ticket->id, $userid, (int)$message->id);

        if ($isrequester) {
            $update = ['last_student_msg_at' => $now];
            if ((int)$ticket->waiting_since === 0) {
                // A new wait starts: the escalation ladder starts over too.
                $update['waiting_since'] = $now;
                $update['escalation_level'] = 0;
                $update['escalated_at'] = 0;
            }
            if ($ticket->status === self::S_RESOLVED) {
                // Writing on a resolved ticket means "not solved": reopen it.
                self::reopen($ticket, $userid, $update);
            } else if ($ticket->status === self::S_WAITING) {
                self::set_status($ticket, self::S_INPROGRESS, $userid, $update);
            } else {
                self::update_ticket($ticket, $update);
            }
            if ($area && !business_hours::is_open($area, $now)) {
                self::out_of_hours_message($ticket, $area, self::template_vars($ticket, $area, $category));
            }
            $recipients = (int)$ticket->assignedto > 0 ? [(int)$ticket->assignedto]
                : ($category ? self::category_staff($category) : []);
            $notify = ['support_student_message', $recipients];
        } else if (!$internal) {
            $update = ['last_staff_msg_at' => $now, 'waiting_since' => 0, 'escalation_level' => 0, 'escalated_at' => 0];
            if ((int)$ticket->first_response_at === 0) {
                $update['first_response_at'] = $now;
            }
            if ((int)$ticket->assignedto === 0) {
                // Answering from the queue takes the ticket.
                self::apply_assignment($ticket, $userid, $userid, 'reply');
            }
            self::set_status($ticket, $askinfo ? self::S_WAITING : self::S_INPROGRESS, $userid, $update);
            $notify = ['support_reply', [(int)$ticket->userid]];
        }
        if (!$isrequester) {
            // Answering (or writing a note) also acknowledges the alert.
            self::ack((int)$ticket->id, $userid, 'reply');
        }
        $transaction->allow_commit();

        if ($notify) {
            self::queue_notification($notify[0], $notify[1], $ticket, $message);
            self::flush_notifications();
        }
        return [$message, $DB->get_record('gmk_tkt_ticket', ['id' => (int)$ticket->id])];
    }

    /**
     * Requester answers "¿Se resolvió tu caso?".
     */
    public static function confirm_resolution(int $ticketid, int $userid, bool $resolved): stdClass {
        global $DB;
        [$ticket, $role] = self::require_ticket($ticketid, $userid);
        if ($role !== self::ROLE_REQUESTER) {
            throw new moodle_exception('tkt_err_noaccess', 'local_grupomakro_core');
        }
        if ($ticket->status !== self::S_RESOLVED) {
            throw new moodle_exception('tkt_err_badstatus', 'local_grupomakro_core');
        }
        $transaction = $DB->start_delegated_transaction();
        self::log_event((int)$ticket->id, 'confirmation', '', $resolved ? 'si' : 'no', $userid);
        if ($resolved) {
            self::close($ticket, $userid, 'confirmed');
        } else {
            self::reopen($ticket, $userid, []);
        }
        $transaction->allow_commit();
        if (!$resolved && (int)$ticket->assignedto > 0) {
            self::queue_notification('support_student_message', [(int)$ticket->assignedto], $ticket, null);
            self::flush_notifications();
        }
        return $DB->get_record('gmk_tkt_ticket', ['id' => (int)$ticket->id]);
    }

    /**
     * Requester withdraws a ticket nobody has answered yet.
     */
    public static function cancel(int $ticketid, int $userid): stdClass {
        global $DB;
        [$ticket, $role] = self::require_ticket($ticketid, $userid);
        if ($role !== self::ROLE_REQUESTER) {
            throw new moodle_exception('tkt_err_noaccess', 'local_grupomakro_core');
        }
        if ((int)$ticket->first_response_at > 0 || !self::can_transition($ticket->status, self::S_CANCELLED)) {
            throw new moodle_exception('tkt_err_badstatus', 'local_grupomakro_core');
        }
        $transaction = $DB->start_delegated_transaction();
        self::set_status($ticket, self::S_CANCELLED, $userid, ['closed_at' => time(), 'waiting_since' => 0]);
        $transaction->allow_commit();
        return $DB->get_record('gmk_tkt_ticket', ['id' => (int)$ticket->id]);
    }

    // ---------------------------------------------------------------------
    // Staff operations
    // ---------------------------------------------------------------------

    /**
     * Desk actions: take, assign, unassign, resolve, close, set_priority, set_category.
     */
    public static function staff_action(int $ticketid, int $userid, string $action, int $targetuserid = 0,
            int $priority = -1, int $categoryid = 0): stdClass {
        global $DB;
        [$ticket, $role] = self::require_ticket($ticketid, $userid);
        if ($role === self::ROLE_REQUESTER) {
            throw new moodle_exception('tkt_err_noaccess', 'local_grupomakro_core');
        }
        if (!self::is_open_status((string)$ticket->status)) {
            throw new moodle_exception('tkt_err_closed', 'local_grupomakro_core');
        }
        $p = self::profile($userid);
        $notify = null;

        // Every check that can refuse the action runs here, before the
        // transaction: an exception inside a nested delegated transaction
        // would roll back the caller's whole transaction too.
        $mayassign = self::may_reassign($ticket, $userid);
        $transfer = null;
        switch ($action) {
            case 'take':
                if ((int)$ticket->assignedto > 0 && (int)$ticket->assignedto !== $userid && !$p->supervisor) {
                    throw new moodle_exception('tkt_err_alreadyassigned', 'local_grupomakro_core');
                }
                break;
            case 'assign':
                if (!$mayassign) {
                    throw new moodle_exception('tkt_err_cannotassign', 'local_grupomakro_core');
                }
                if ($categoryid > 0 && $categoryid !== (int)$ticket->categoryid) {
                    // Pasarlo a otro tema (y quiza otra area): el destino se elige al reasignar.
                    $transfer = self::transfer_target($ticket, $categoryid);
                    if (!$transfer) {
                        throw new moodle_exception('tkt_err_badcategory', 'local_grupomakro_core');
                    }
                    if (!empty($ticket->confidential) && empty($transfer->category->confidential)
                            && !self::profile($userid)->viewconf) {
                        // A confidential conversation is never made public by moving it.
                        throw new moodle_exception('tkt_err_cannotassign', 'local_grupomakro_core');
                    }
                    if ($targetuserid <= 0 || !array_key_exists($targetuserid, self::assignable_staff($transfer->ticket))) {
                        throw new moodle_exception('tkt_err_badagent', 'local_grupomakro_core');
                    }
                } else if ($targetuserid <= 0 || !self::can_be_assigned($ticket, $targetuserid)) {
                    throw new moodle_exception('tkt_err_badagent', 'local_grupomakro_core');
                }
                break;
            case 'unassign':
                if (!$mayassign) {
                    throw new moodle_exception('tkt_err_cannotassign', 'local_grupomakro_core');
                }
                break;
            case 'resolve':
            case 'close':
                if ((int)$ticket->first_response_at === 0) {
                    throw new moodle_exception('tkt_err_noresponse', 'local_grupomakro_core');
                }
                break;
            case 'set_priority':
                if (!in_array($priority, [self::PRIORITY_LOW, self::PRIORITY_NORMAL, self::PRIORITY_HIGH], true)) {
                    throw new \invalid_parameter_exception('priority');
                }
                break;
            case 'set_category':
                $newcategory = $DB->get_record('gmk_tkt_category', ['id' => $categoryid, 'active' => 1]);
                $newarea = $newcategory ? $DB->get_record('gmk_tkt_area', ['id' => (int)$newcategory->areaid]) : null;
                if (!$newcategory || !$newarea || trim((string)$newarea->external_url) !== '') {
                    throw new moodle_exception('tkt_err_badcategory', 'local_grupomakro_core');
                }
                break;
            default:
                throw new \invalid_parameter_exception('Unknown action ' . $action);
        }

        $transaction = $DB->start_delegated_transaction();
        switch ($action) {
            case 'take':
                self::ack((int)$ticket->id, $userid, 'take');
                if ((int)$ticket->assignedto === $userid) {
                    break;
                }
                self::apply_assignment($ticket, $userid, $userid, 'take');
                self::system_message($ticket, 'assigned', self::template_vars($ticket));
                break;

            case 'assign':
                if ($transfer) {
                    self::log_event((int)$ticket->id, 'category', (string)$ticket->categoryid, (string)$categoryid, $userid,
                        ['oldareaid' => (int)$ticket->areaid, 'newareaid' => (int)$transfer->ticket->areaid, 'how' => 'transfer']);
                    self::update_ticket($ticket, [
                        'categoryid' => (int)$transfer->ticket->categoryid,
                        'areaid' => (int)$transfer->ticket->areaid,
                        'confidential' => (int)$transfer->ticket->confidential,
                    ]);
                    self::apply_assignment($ticket, $targetuserid, $userid, 'transfer');
                    $vars = self::template_vars($ticket);
                    if (!self::system_message($ticket, 'transferred', $vars)) {
                        self::system_message($ticket, 'assigned', $vars);
                    }
                    if ($targetuserid !== $userid) {
                        $notify = ['support_assigned', [$targetuserid]];
                    }
                    break;
                }
                if ((int)$ticket->assignedto === $targetuserid) {
                    break;
                }
                self::apply_assignment($ticket, $targetuserid, $userid, 'reassign');
                self::system_message($ticket, 'assigned', self::template_vars($ticket));
                if ($targetuserid !== $userid) {
                    $notify = ['support_assigned', [$targetuserid]];
                }
                break;

            case 'unassign':
                if ((int)$ticket->assignedto === 0) {
                    break;
                }
                self::log_event((int)$ticket->id, 'unassigned', (string)$ticket->assignedto, '0', $userid);
                $update = ['assignedto' => 0];
                if ($ticket->status === self::S_ASSIGNED) {
                    self::set_status($ticket, self::S_NEW, $userid, $update);
                } else {
                    self::update_ticket($ticket, $update);
                }
                $category = $DB->get_record('gmk_tkt_category', ['id' => (int)$ticket->categoryid]);
                $notify = ['support_new_ticket', $category ? self::category_staff($category) : []];
                break;

            case 'resolve':
                self::set_status($ticket, self::S_RESOLVED, $userid, ['resolved_at' => time(), 'waiting_since' => 0]);
                self::system_message($ticket, 'resolved', self::template_vars($ticket));
                $notify = ['support_resolved', [(int)$ticket->userid]];
                break;

            case 'close':
                self::close($ticket, $userid, 'staff');
                break;

            case 'set_priority':
                if ((int)$ticket->priority !== $priority) {
                    self::log_event((int)$ticket->id, 'priority', (string)$ticket->priority, (string)$priority, $userid);
                    self::update_ticket($ticket, ['priority' => $priority]);
                }
                break;

            case 'set_category':
                if ((int)$ticket->categoryid === $categoryid) {
                    break;
                }
                self::log_event((int)$ticket->id, 'category', (string)$ticket->categoryid, (string)$categoryid, $userid,
                    ['oldareaid' => (int)$ticket->areaid, 'newareaid' => (int)$newarea->id]);
                self::update_ticket($ticket, [
                    'categoryid' => $categoryid,
                    'areaid' => (int)$newarea->id,
                    // Sticky: a confidential ticket never becomes public by moving it.
                    'confidential' => (int)(!empty($ticket->confidential) || !empty($newcategory->confidential)),
                ]);
                break;
        }
        $transaction->allow_commit();

        if ($notify) {
            self::queue_notification($notify[0], $notify[1], $ticket, null);
            self::flush_notifications();
        }
        return $DB->get_record('gmk_tkt_ticket', ['id' => (int)$ticket->id]);
    }

    /**
     * The ticket as it would look in topic $categoryid (another area included).
     *
     * @return stdClass|null {ticket, category, area}
     */
    public static function transfer_target(stdClass $ticket, int $categoryid): ?stdClass {
        global $DB;
        $category = $DB->get_record('gmk_tkt_category', ['id' => $categoryid, 'active' => 1]);
        $area = $category ? $DB->get_record('gmk_tkt_area', ['id' => (int)$category->areaid, 'active' => 1]) : null;
        if (!$category || !$area || trim((string)$area->external_url) !== '') {
            return null;
        }
        $clone = clone $ticket;
        $clone->categoryid = (int)$category->id;
        $clone->areaid = (int)$area->id;
        // Sticky: a confidential ticket stays confidential wherever it goes.
        $clone->confidential = (int)(!empty($ticket->confidential) || !empty($category->confidential));
        return (object)['ticket' => $clone, 'category' => $category, 'area' => $area];
    }

    /**
     * Staff that may receive $ticket by (re)assignment.
     *
     * @return stdClass[] users keyed by id, each with ->openload
     */
    public static function assignable_staff(stdClass $ticket): array {
        global $DB;
        $sql = "SELECT DISTINCT a.userid
                  FROM {gmk_tkt_agent} a
                  JOIN {gmk_tkt_category} c ON c.id = a.categoryid
                 WHERE " . (!empty($ticket->confidential) ? 'a.categoryid = :id' : 'c.areaid = :id');
        $ids = array_map('intval', $DB->get_fieldset_sql($sql,
            ['id' => !empty($ticket->confidential) ? (int)$ticket->categoryid : (int)$ticket->areaid]));
        $agentids = $ids;
        if ($agentids) {
            [$in, $params] = $DB->get_in_or_equal($agentids);
            $ids = array_merge($ids, array_map('intval',
                $DB->get_fieldset_select('gmk_tkt_backup', 'backupuserid', "userid $in", $params)));
        }
        $ids = array_merge($ids, array_map('intval', $DB->get_fieldset_select('gmk_tkt_person', 'userid',
            !empty($ticket->confidential) ? "kind = 'gerencia'" : '1 = 1')));
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal($ids);
        $users = $DB->get_records_select('user', "id $in AND deleted = 0 AND suspended = 0", $params, 'lastname, firstname',
            'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename, email');
        $loads = self::open_loads(array_keys($users));
        $presence = self::presence_map(array_keys($users));
        foreach ($users as $u) {
            $u->openload = $loads[(int)$u->id] ?? 0;
            $u->available = $presence[(int)$u->id] ?? true;
        }
        return $users;
    }

    public static function can_be_assigned(stdClass $ticket, int $userid): bool {
        return array_key_exists($userid, self::assignable_staff($ticket));
    }

    public static function set_availability(int $userid, bool $available, int $actorid): void {
        global $DB;
        $now = time();
        $row = $DB->get_record('gmk_tkt_presence', ['userid' => $userid]);
        $old = $row ? (bool)$row->available : true;
        if ($row) {
            $DB->update_record('gmk_tkt_presence', (object)['id' => $row->id, 'available' => (int)$available,
                'modifiedby' => $actorid, 'timemodified' => $now]);
        } else {
            $DB->insert_record('gmk_tkt_presence', (object)['userid' => $userid, 'available' => (int)$available,
                'modifiedby' => $actorid, 'timemodified' => $now]);
        }
        if ($old !== $available) {
            self::log_event(0, 'availability', $old ? '1' : '0', $available ? '1' : '0', $actorid, ['userid' => $userid]);
        }
        badge::invalidate($userid);
    }

    public static function is_available(int $userid): bool {
        return self::presence_map([$userid])[$userid] ?? true;
    }

    /**
     * Tickets waiting for the requester (esperando estudiante or resuelto)
     * whose last staff message is at least $hours BUSINESS hours old, on the
     * same clock as the semaphore: the assigned agent's hours, else the area's
     * (decision de Ramiro, 18-nov-2026). An area without hours counts wall time.
     *
     * @param int[] $ids restrict to these tickets (tests); empty = all
     * @return stdClass[]
     */
    public static function waiting_requester_for(int $hours, int $now, array $ids = []): array {
        global $DB;
        // Prefilter on wall time: business hours never exceed wall hours.
        $params = ['s1' => self::S_WAITING, 's2' => self::S_RESOLVED, 'lim' => $now - $hours * HOURSECS];
        $where = "status IN (:s1, :s2) AND first_response_at > 0 AND last_staff_msg_at > 0
                  AND last_staff_msg_at < :lim AND last_student_msg_at < last_staff_msg_at";
        if ($ids) {
            [$in, $inparams] = $DB->get_in_or_equal(array_map('intval', $ids), SQL_PARAMS_NAMED, 'wr');
            $where .= " AND id $in";
            $params += $inparams;
        }
        $rows = $DB->get_records_select('gmk_tkt_ticket', $where, $params, 'id ASC', '*', 0, 2000);
        $out = [];
        foreach ($rows as $t) {
            if (business_hours::elapsed((int)$t->last_staff_msg_at, $now, policy::clock($t)) >= $hours * 60) {
                $out[] = $t;
            }
            if (count($out) >= 500) {
                break;
            }
        }
        return $out;
    }

    /**
     * Closes tickets whose requester did not answer within tkt_close_hours
     * BUSINESS hours after the last staff message. Idempotent; scheduled task.
     *
     * @param int[] $ids restrict to these tickets (tests)
     * @return int tickets closed
     */
    public static function autoclose_stale(int $now = 0, array $ids = []): int {
        global $DB;
        if (!config::get_int('tkt_autoclose_enabled')) {
            return 0;
        }
        $now = $now ?: time();
        $rows = self::waiting_requester_for(config::get_int('tkt_close_hours'), $now, $ids);
        $closed = 0;
        foreach ($rows as $ticket) {
            $transaction = $DB->start_delegated_transaction();
            self::close($ticket, 0, 'timeout');
            $transaction->allow_commit();
            $closed++;
        }
        return $closed;
    }

    /**
     * Sends the reminder ("¿Sigues necesitando ayuda?") tkt_remind_hours
     * BUSINESS hours after the last staff message, once per staff message, to
     * requesters who did not answer. Idempotent (auto_message event).
     *
     * @param int[] $ids restrict to these tickets (tests)
     * @return int reminders sent
     */
    public static function remind_stale(int $now = 0, array $ids = []): int {
        global $DB;
        $hours = config::get_int('tkt_remind_hours');
        if ($hours <= 0) {
            return 0;
        }
        $now = $now ?: time();
        $close = config::get_int('tkt_close_hours');
        if (config::get_int('tkt_autoclose_enabled') && $close > 0 && $close <= $hours) {
            // The ticket closes before (or when) the reminder would be due: nothing to remind.
            return 0;
        }
        $rows = self::waiting_requester_for($hours, $now, $ids);
        $sent = 0;
        foreach ($rows as $ticket) {
            $done = $DB->record_exists_select('gmk_tkt_event',
                "ticketid = :t AND eventtype = 'auto_message' AND " . $DB->sql_compare_text('newvalue') . " = :c
                 AND timecreated >= :since",
                ['t' => (int)$ticket->id, 'c' => 'reminder', 'since' => (int)$ticket->last_staff_msg_at]);
            if ($done) {
                continue;
            }
            $transaction = $DB->start_delegated_transaction();
            $message = self::system_message($ticket, 'reminder', self::template_vars($ticket));
            if (!$message) {
                // Template disabled: remember it anyway so we do not retry every pass.
                self::log_event((int)$ticket->id, 'auto_message', '', 'reminder', 0, ['disabled' => 1]);
            }
            $transaction->allow_commit();
            if ($message) {
                self::queue_notification('support_reply', [(int)$ticket->userid], $ticket, $message);
                $sent++;
            }
        }
        self::flush_notifications();
        return $sent;
    }

    // ---------------------------------------------------------------------
    // Acknowledgement (§8.5: the alert only goes away when the agent opens or takes)
    // ---------------------------------------------------------------------

    public static function ack(int $ticketid, int $userid, string $how): void {
        global $DB;
        if ($ticketid <= 0 || $userid <= 0) {
            return;
        }
        $now = time();
        $row = $DB->get_record('gmk_tkt_ack', ['ticketid' => $ticketid, 'userid' => $userid]);
        if ($row) {
            $DB->update_record('gmk_tkt_ack', (object)['id' => $row->id, 'timeack' => $now, 'how' => $how]);
        } else {
            $DB->insert_record('gmk_tkt_ack', (object)['ticketid' => $ticketid, 'userid' => $userid, 'timeack' => $now,
                'how' => $how, 'snoozekey' => 0, 'snoozes' => 0, 'snoozeduntil' => 0]);
        }
        badge::invalidate($userid);
    }

    /**
     * Moment the current alert of $ticket started for its responsible people:
     * the last requester message, the assignment, or reaching a new escalation
     * level, whichever is later. 0 = nothing to acknowledge.
     */
    public static function alert_since(stdClass $ticket): int {
        if (!self::is_open_status((string)$ticket->status) || (int)$ticket->waiting_since <= 0) {
            return 0;
        }
        return max((int)$ticket->last_student_msg_at, (int)($ticket->assigned_at ?? 0), (int)($ticket->escalated_at ?? 0));
    }

    // ---------------------------------------------------------------------
    // Teachers (§8.4)
    // ---------------------------------------------------------------------

    /** @var array per-request cache userid => bool */
    private static $teachers = [];

    /**
     * Whether $userid holds a teacher role (editingteacher or teacher) in some context.
     */
    public static function is_teacher(int $userid): bool {
        global $DB;
        if (!array_key_exists($userid, self::$teachers)) {
            self::$teachers[$userid] = $userid > 0 && $DB->record_exists_sql(
                "SELECT 1
                   FROM {role_assignments} ra
                   JOIN {role} r ON r.id = ra.roleid
                  WHERE ra.userid = :u AND r.shortname IN ('editingteacher', 'teacher')",
                ['u' => $userid]);
        }
        return self::$teachers[$userid];
    }

    // ---------------------------------------------------------------------
    // Recipients for escalations
    // ---------------------------------------------------------------------

    /**
     * Supervisors (gmk_tkt_person 'supervisor' + support_supervise) that may see $ticket.
     *
     * @return int[]
     */
    public static function supervisors_for(stdClass $ticket): array {
        global $DB;
        $ids = array_map('intval', $DB->get_fieldset_select('gmk_tkt_person', 'userid', "kind = 'supervisor'"));
        $users = get_users_by_capability(context_system::instance(), 'local/grupomakro_core:support_supervise', 'u.id');
        $ids = array_merge($ids, array_map('intval', array_keys($users)));
        return self::visible_to(array_values(array_unique($ids)), $ticket);
    }

    /**
     * Gerencia (gmk_tkt_person 'gerencia' + support_receive_escalations) that may see $ticket.
     *
     * @return int[]
     */
    public static function gerencia_for(?stdClass $ticket): array {
        global $DB;
        $ids = array_map('intval', $DB->get_fieldset_select('gmk_tkt_person', 'userid', "kind = 'gerencia'"));
        $users = get_users_by_capability(context_system::instance(), 'local/grupomakro_core:support_receive_escalations', 'u.id');
        $ids = array_values(array_unique(array_merge($ids, array_map('intval', array_keys($users)))));
        return $ticket ? self::visible_to($ids, $ticket) : $ids;
    }

    /**
     * @return int[] those of $ids that are active users allowed to read $ticket
     */
    public static function visible_to(array $ids, stdClass $ticket): array {
        global $DB;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal($ids);
        $active = array_map('intval', $DB->get_fieldset_select('user', 'id', "id $in AND deleted = 0 AND suspended = 0",
            $params));
        return array_values(array_filter($active, function($uid) use ($ticket) {
            return (int)$ticket->userid !== $uid && self::staff_can_view($ticket, $uid);
        }));
    }

    // ---------------------------------------------------------------------
    // Rating (§7.1)
    // ---------------------------------------------------------------------

    public static function rate(int $ticketid, int $userid, int $stars, bool $solved, string $comment): stdClass {
        global $DB;
        [$ticket, $role] = self::require_ticket($ticketid, $userid);
        if ($role !== self::ROLE_REQUESTER) {
            throw new moodle_exception('tkt_err_noaccess', 'local_grupomakro_core');
        }
        if ($ticket->status !== self::S_CLOSED || (int)$ticket->rating > 0 || (int)$ticket->first_response_at === 0
                || !config::get_int('tkt_rating_enabled')) {
            throw new moodle_exception('tkt_err_badstatus', 'local_grupomakro_core');
        }
        if ($stars < 1 || $stars > 5) {
            throw new \invalid_parameter_exception('rating');
        }
        $comment = \core_text::substr(self::clean_body($comment), 0, 1000);
        $transaction = $DB->start_delegated_transaction();
        self::update_ticket($ticket, ['rating' => $stars, 'rating_solved' => $solved ? 1 : 2,
            'rating_comment' => $comment === '' ? null : $comment, 'rated_at' => time()]);
        self::log_event((int)$ticket->id, 'rating', '', (string)$stars, $userid, ['solved' => $solved ? 1 : 0]);
        $transaction->allow_commit();
        if ($stars <= config::get_int('tkt_rating_low')) {
            $recipients = self::supervisors_for($ticket);
            if (!empty($ticket->confidential)) {
                $recipients = array_merge($recipients, self::gerencia_for($ticket));
            }
            self::queue_notification('support_low_rating', $recipients, $ticket, null, [
                'rating' => $stars . ' de 5' . ($solved ? '' : ', no se resolvió'),
            ]);
            self::flush_notifications();
        }
        return $DB->get_record('gmk_tkt_ticket', ['id' => (int)$ticket->id]);
    }

    /**
     * "Ahora no": the dialog comes back once, the next day.
     */
    public static function dismiss_rating(int $ticketid, int $userid): void {
        [$ticket, $role] = self::require_ticket($ticketid, $userid);
        if ($role !== self::ROLE_REQUESTER || (int)$ticket->rating > 0) {
            return;
        }
        self::update_ticket($ticket, ['rating_dismissed' => (int)$ticket->rating_dismissed + 1, 'rating_dismissed_at' => time()]);
        self::log_event((int)$ticket->id, 'rating_dismissed', '', (string)((int)$ticket->rating_dismissed), $userid);
    }

    /**
     * Closed tickets of $userid waiting for a rating.
     */
    public static function pending_ratings(int $userid, int $now = 0, string $requesterrole = ''): array {
        global $DB;
        if (!config::get_int('tkt_rating_enabled')) {
            return [];
        }
        $now = $now ?: time();
        $rows = $DB->get_records_sql(
            "SELECT t.id, t.number, t.subject, t.closed_at, t.requesterrole, a.name AS areaname, c.name AS categoryname
               FROM {gmk_tkt_ticket} t
          LEFT JOIN {gmk_tkt_area} a ON a.id = t.areaid
          LEFT JOIN {gmk_tkt_category} c ON c.id = t.categoryid
              WHERE t.userid = :u AND t.status = :st AND t.rating = 0 AND t.first_response_at > 0
                AND t.closed_at > :since AND t.rating_dismissed < 2
                AND (t.rating_dismissed = 0 OR t.rating_dismissed_at < :again)
                " . ($requesterrole !== '' ? 'AND t.requesterrole = :rrole' : '') . "
           ORDER BY t.closed_at DESC",
            ['u' => $userid, 'st' => self::S_CLOSED, 'since' => $now - config::get_int('tkt_rating_days') * DAYSECS,
                'again' => $now - DAYSECS] + ($requesterrole !== '' ? ['rrole' => $requesterrole] : []), 0, 5);
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['id' => (int)$r->id, 'number' => (string)$r->number, 'subject' => (string)$r->subject,
                'areaname' => format_string((string)$r->areaname), 'categoryname' => format_string((string)$r->categoryname),
                'closed_at' => (int)$r->closed_at];
        }
        return $out;
    }

    /**
     * Requester tickets with unread staff replies, or waiting for their answer
     * (persistent notice in the portal and for teachers in Moodle, §7.5).
     */
    public static function requester_replies(int $userid, string $requesterrole = ''): array {
        global $DB;
        [$in, $params] = $DB->get_in_or_equal(self::open_statuses(), SQL_PARAMS_NAMED, 'rr');
        $params += ['u0' => $userid, 'u1' => $userid, 'u2' => $userid];
        $rolesql = '';
        if ($requesterrole !== '') {
            $rolesql = 'AND t.requesterrole = :rrole';
            $params['rrole'] = $requesterrole;
        }
        $rows = $DB->get_records_sql(
            "SELECT t.id, t.number, t.status, t.subject, a.name AS areaname,
                    (SELECT COUNT(1) FROM {gmk_tkt_message} m
                      WHERE m.ticketid = t.id AND m.internal = 0 AND m.deleted = 0 AND m.senderid > 0
                        AND m.senderid <> :u0 AND m.id > COALESCE(r.lastreadmessageid, 0)) AS unread
               FROM {gmk_tkt_ticket} t
          LEFT JOIN {gmk_tkt_area} a ON a.id = t.areaid
          LEFT JOIN {gmk_tkt_read} r ON r.ticketid = t.id AND r.userid = :u1
              WHERE t.userid = :u2 AND t.status $in $rolesql
           ORDER BY t.lastmessageat DESC", $params, 0, 20);
        $out = [];
        foreach ($rows as $r) {
            $waiting = $r->status === self::S_WAITING;
            if ((int)$r->unread === 0 && !$waiting) {
                continue;
            }
            $out[] = ['id' => (int)$r->id, 'number' => (string)$r->number, 'subject' => (string)$r->subject,
                'areaname' => format_string((string)$r->areaname), 'unread' => (int)$r->unread, 'waiting' => $waiting];
        }
        return $out;
    }

    // ---------------------------------------------------------------------
    // Reads
    // ---------------------------------------------------------------------

    /**
     * Messages of a ticket for $userid (requesters never get internal notes).
     *
     * @return array ['messages' => exported, 'hasmore' => bool]
     */
    public static function messages(stdClass $ticket, int $userid, string $role, int $beforeid = 0, int $limit = 50): array {
        global $DB;
        $limit = max(1, min(100, $limit));
        $params = ['ticketid' => (int)$ticket->id];
        $select = 'ticketid = :ticketid AND deleted = 0';
        if ($role === self::ROLE_REQUESTER) {
            $select .= ' AND internal = 0';
        }
        if ($beforeid > 0) {
            $select .= ' AND id < :beforeid';
            $params['beforeid'] = $beforeid;
        }
        $rows = $DB->get_records_select('gmk_tkt_message', $select, $params, 'id DESC', '*', 0, $limit + 1);
        $hasmore = count($rows) > $limit;
        $rows = array_reverse(array_slice(array_values($rows), 0, $limit));
        $users = self::load_users(array_map(function($m) {
            return (int)$m->senderid;
        }, $rows));
        $out = [];
        foreach ($rows as $m) {
            $out[] = self::export_message($m, $userid, $users);
        }
        return ['messages' => $out, 'hasmore' => $hasmore];
    }

    public static function events(stdClass $ticket): array {
        global $DB;
        $rows = $DB->get_records('gmk_tkt_event', ['ticketid' => (int)$ticket->id], 'id ASC');
        $users = [];
        foreach ($rows as $r) {
            $users[] = (int)$r->actorid;
            if (in_array($r->eventtype, ['assigned', 'unassigned'], true)) {
                $users[] = (int)$r->oldvalue;
                $users[] = (int)$r->newvalue;
            }
        }
        $users = self::load_users($users);
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int)$r->id,
                'eventtype' => (string)$r->eventtype,
                'description' => self::describe_event($r, $users),
                'actorname' => isset($users[(int)$r->actorid]) ? fullname($users[(int)$r->actorid]) : 'Sistema',
                'timecreated' => (int)$r->timecreated,
            ];
        }
        return $out;
    }

    /**
     * Requester's tickets, newest activity first.
     */
    public static function my_tickets(int $userid, string $filter = 'all'): array {
        global $DB;
        $params = ['u' => $userid];
        $where = 't.userid = :u';
        if ($filter === 'open' || $filter === 'closed') {
            [$in, $inparams] = $DB->get_in_or_equal(self::open_statuses(), SQL_PARAMS_NAMED, 'st', $filter === 'open');
            $where .= " AND t.status $in";
            $params += $inparams;
        }
        $rows = self::ticket_rows($where, $params, $userid, self::ROLE_REQUESTER,
            'CASE WHEN t.lastmessageat > 0 THEN t.lastmessageat ELSE t.timecreated END DESC, t.id DESC');
        return self::export_tickets($rows, $userid, self::ROLE_REQUESTER);
    }

    /**
     * Unread messages for the requester (badge of the portal button).
     */
    public static function requester_unread(int $userid): int {
        global $DB;
        return (int)$DB->count_records_sql(
            "SELECT COUNT(m.id)
               FROM {gmk_tkt_message} m
               JOIN {gmk_tkt_ticket} t ON t.id = m.ticketid
          LEFT JOIN {gmk_tkt_read} r ON r.ticketid = t.id AND r.userid = :u0
              WHERE t.userid = :u1 AND m.senderid <> :u2 AND m.internal = 0 AND m.deleted = 0
                AND m.id > COALESCE(r.lastreadmessageid, 0)",
            ['u0' => $userid, 'u1' => $userid, 'u2' => $userid]);
    }

    /**
     * Desk listing for staff.
     *
     * @return array ['tickets' => exported, 'total' => int, 'counts' => [tab => int]]
     */
    public static function desk_list(int $userid, string $tab, string $search = '', int $page = 0, int $perpage = 50): array {
        global $DB;
        $p = self::require_staff($userid);
        $tabs = ['unassigned', 'mine', 'area', 'overdue', 'all', 'closed'];
        if (!in_array($tab, $tabs, true)) {
            $tab = 'mine';
        }
        $counts = [];
        foreach ($tabs as $t) {
            if ($t === 'all' && !$p->reassign) {
                continue;
            }
            if ($t === 'overdue') {
                $counts[$t] = count(self::overdue_ids($userid, $p));
                continue;
            }
            [$w, $params] = self::desk_where($userid, $p, $t, '');
            $counts[$t] = (int)$DB->count_records_sql("SELECT COUNT(1) FROM {gmk_tkt_ticket} t
                JOIN {user} s ON s.id = t.userid WHERE $w", $params);
        }
        if ($tab === 'all' && !$p->reassign) {
            $tab = 'area';
        }
        [$where, $params] = self::desk_where($userid, $p, $tab, $search);
        $order = $tab === 'closed'
            ? 't.closed_at DESC, t.id DESC'
            : 't.priority DESC, CASE WHEN t.waiting_since = 0 THEN 1 ELSE 0 END, t.waiting_since ASC, t.lastmessageat DESC';
        if ($tab === 'overdue') {
            // Vencido = rojo en minutos habiles: se filtra en PHP tras el prefiltro en reloj.
            $rows = array_values(array_filter(self::ticket_rows($where, $params, $userid, self::ROLE_AGENT, $order),
                function($r) {
                    return policy::level_for(policy::waiting_minutes($r)) >= policy::LEVEL_RED;
                }));
            $total = count($rows);
            $rows = array_slice($rows, max(0, $page) * $perpage, $perpage);
            return ['tickets' => self::export_tickets($rows, $userid, self::ROLE_AGENT), 'total' => $total,
                'counts' => $counts, 'tab' => $tab];
        }
        $total = (int)$DB->count_records_sql("SELECT COUNT(1) FROM {gmk_tkt_ticket} t
            JOIN {user} s ON s.id = t.userid WHERE $where", $params);
        $rows = self::ticket_rows($where, $params, $userid, self::ROLE_AGENT, $order, max(0, $page) * $perpage, $perpage);
        return ['tickets' => self::export_tickets($rows, $userid, self::ROLE_AGENT), 'total' => $total,
            'counts' => $counts, 'tab' => $tab];
    }

    /**
     * Ids of open tickets visible to $userid that are red in business minutes.
     *
     * @return int[]
     */
    public static function overdue_ids(int $userid, ?stdClass $p = null): array {
        global $DB;
        $p = $p ?: self::profile($userid);
        if (!$p->isstaff) {
            return [];
        }
        [$where, $params] = self::desk_where($userid, $p, 'overdue', '');
        $rows = $DB->get_records_sql("SELECT t.id, t.areaid, t.assignedto, t.status, t.waiting_since
                                        FROM {gmk_tkt_ticket} t
                                        JOIN {user} s ON s.id = t.userid
                                       WHERE $where", $params);
        $out = [];
        foreach ($rows as $r) {
            if (policy::level_for(policy::waiting_minutes($r)) >= policy::LEVEL_RED) {
                $out[] = (int)$r->id;
            }
        }
        return $out;
    }

    /**
     * Visibility + tab filter for desk queries (alias t = ticket, s = requester).
     */
    public static function desk_where(int $userid, stdClass $p, string $tab, string $search): array {
        global $DB;
        // Moodle rejects named params that are unused or repeated, so every
        // fragment below adds exactly the params it uses, with fresh names.
        $params = [];
        $uid = function() use (&$params, $userid): string {
            static $n = 0;
            $name = 'dwu' . (++$n);
            $params[$name] = $userid;
            return ':' . $name;
        };
        $in = function(string $column, array $values, bool $equal = true) use (&$params, $DB): string {
            if (!$values) {
                return $equal ? '1 = 0' : '1 = 1';
            }
            [$sql, $inparams] = $DB->get_in_or_equal($values, SQL_PARAMS_NAMED, 'dw', $equal);
            $params += $inparams;
            return "$column $sql";
        };

        $public = ($p->supervisor || $p->reassign) ? '1 = 1' : '(t.assignedto = ' . $uid() . ' OR ' . $in('t.areaid', $p->areas) . ')';
        $private = $p->viewconf ? '1 = 1' : '(t.assignedto = ' . $uid() . ' OR ' . $in('t.categoryid', $p->cats) . ')';
        $where = ["((t.confidential = 0 AND $public) OR (t.confidential = 1 AND $private))"];

        switch ($tab) {
            case 'unassigned':
                $where[] = $in('t.status', self::open_statuses()) . ' AND t.assignedto = 0';
                break;
            case 'mine':
                $where[] = $in('t.status', self::open_statuses()) . ' AND t.assignedto = ' . $uid();
                break;
            case 'area':
                $where[] = $in('t.status', self::open_statuses()) . ' AND ' . $in('t.areaid', $p->areas);
                break;
            case 'overdue':
                // Prefiltro en reloj: los minutos habiles nunca superan a los de
                // reloj. desk_list() y overdue_ids() terminan en minutos habiles.
                $where[] = $in('t.status', self::open_statuses()) . ' AND t.waiting_since > 0 AND t.waiting_since < :dwlim';
                $params['dwlim'] = time() - config::get_int('tkt_sla_red') * MINSECS;
                break;
            case 'all':
                $where[] = $in('t.status', self::open_statuses());
                break;
            case 'closed':
                $where[] = $in('t.status', self::open_statuses(), false);
                break;
        }
        $search = trim($search);
        if ($search !== '') {
            $like = [];
            foreach (['t.number', 't.subject', 's.firstname', 's.lastname', 's.idnumber'] as $i => $field) {
                $like[] = $DB->sql_like($field, ':dwq' . $i, false, false);
                $params['dwq' . $i] = '%' . $DB->sql_like_escape($search) . '%';
            }
            $where[] = '(' . implode(' OR ', $like) . ')';
        }
        return [implode(' AND ', $where), $params];
    }

    private static function ticket_rows(string $where, array $params, int $userid, string $role, string $order,
            int $from = 0, int $limit = 0): array {
        global $DB;
        $internal = $role === self::ROLE_REQUESTER ? 'AND um.internal = 0' : '';
        $params['rd0'] = $userid;
        $params['rd1'] = $userid;
        $sql = "SELECT t.*, a.name AS areaname, c.name AS categoryname, m.body AS lastbody, m.internal AS lastinternal,
                       (SELECT COUNT(1) FROM {gmk_tkt_message} um
                         WHERE um.ticketid = t.id AND um.deleted = 0 AND um.senderid <> :rd1 $internal
                           AND um.id > COALESCE(r.lastreadmessageid, 0)) AS unread
                  FROM {gmk_tkt_ticket} t
                  JOIN {user} s ON s.id = t.userid
             LEFT JOIN {gmk_tkt_area} a ON a.id = t.areaid
             LEFT JOIN {gmk_tkt_category} c ON c.id = t.categoryid
             LEFT JOIN {gmk_tkt_message} m ON m.id = t.lastmessageid
             LEFT JOIN {gmk_tkt_read} r ON r.ticketid = t.id AND r.userid = :rd0
                 WHERE $where
              ORDER BY $order";
        return array_values($DB->get_records_sql($sql, $params, $from, $limit));
    }

    public static function export_ticket(stdClass $ticket, int $userid, string $role): array {
        $where = 't.id = :tid';
        $rows = self::ticket_rows($where, ['tid' => (int)$ticket->id], $userid, $role, 't.id');
        $out = self::export_tickets($rows, $userid, $role);
        return reset($out);
    }

    private static function export_tickets(array $rows, int $userid, string $role): array {
        $ids = [];
        foreach ($rows as $r) {
            $ids[] = (int)$r->userid;
            $ids[] = (int)$r->assignedto;
        }
        $users = self::load_users($ids, true);
        $now = time();
        $isrequester = $role === self::ROLE_REQUESTER;
        $docs = $isrequester ? [] : self::document_numbers(array_map(function($r) {
            return (int)$r->userid;
        }, $rows));
        $out = [];
        foreach ($rows as $r) {
            $requester = $users[(int)$r->userid] ?? null;
            $agent = $users[(int)$r->assignedto] ?? null;
            $myrole = $isrequester ? self::ROLE_REQUESTER : self::resolve_role($r, $userid);
            $iswaiting = (int)$r->waiting_since > 0 && self::is_open_status((string)$r->status);
            // Semaforo en minutos habiles del area (fase 2, §6.2).
            $waiting = $iswaiting ? policy::waiting_minutes($r, $now) : 0;
            $lastbody = (!$isrequester || empty($r->lastinternal)) ? (string)($r->lastbody ?? '') : '';
            $out[] = [
                'id' => (int)$r->id,
                'number' => (string)$r->number,
                'subject' => (string)$r->subject,
                'status' => (string)$r->status,
                'statuslabel' => ($myrole === self::ROLE_REQUESTER ? self::REQUESTER_LABELS : self::STAFF_LABELS)[$r->status]
                    ?? (string)$r->status,
                'isopen' => self::is_open_status((string)$r->status),
                'areaid' => (int)$r->areaid,
                'areaname' => format_string((string)($r->areaname ?? '')),
                'categoryid' => (int)$r->categoryid,
                'categoryname' => format_string((string)($r->categoryname ?? '')),
                'priority' => (int)$r->priority,
                'confidential' => (bool)$r->confidential,
                'userid' => (int)$r->userid,
                'requestername' => $requester ? fullname($requester) : '',
                'requesteridnumber' => (!$isrequester && $requester)
                    ? ($docs[(int)$requester->id] ?? (string)$requester->idnumber) : '',
                'requesteremail' => (!$isrequester && $requester) ? (string)$requester->email : '',
                'assignedto' => (int)$r->assignedto,
                'assignedname' => $agent ? fullname($agent) : '',
                'myrole' => $myrole,
                'canwrite' => self::is_open_status((string)$r->status),
                'actions' => self::allowed_actions($r, $userid, $myrole),
                'waitingminutes' => $waiting,
                'waitingclock' => $iswaiting ? (int)floor(($now - (int)$r->waiting_since) / MINSECS) : 0,
                'semaphore' => policy::semaphore_for($waiting, $iswaiting),
                'escalationlevel' => (int)$r->escalation_level,
                'requesterrole' => (string)$r->requesterrole,
                'rating' => (int)$r->rating,
                'unread' => (int)($r->unread ?? 0),
                'lastmessage' => self::excerpt($lastbody),
                'lastmessageat' => (int)$r->lastmessageat,
                'first_response_at' => (int)$r->first_response_at,
                'resolved_at' => (int)$r->resolved_at,
                'closed_at' => (int)$r->closed_at,
                'reopened_count' => (int)$r->reopened_count,
                'timecreated' => (int)$r->timecreated,
                'timemodified' => (int)$r->timemodified,
            ];
        }
        return $out;
    }

    /**
     * @return string[] actions the caller may perform now
     */
    private static function allowed_actions(stdClass $t, int $userid, string $role): array {
        $open = self::is_open_status((string)$t->status);
        if ($role === self::ROLE_REQUESTER) {
            $out = [];
            if ($t->status === self::S_RESOLVED) {
                $out[] = 'confirm';
            }
            if ((int)$t->first_response_at === 0 && self::can_transition($t->status, self::S_CANCELLED)) {
                $out[] = 'cancel';
            }
            return $out;
        }
        if (!$open) {
            return [];
        }
        $p = self::profile($userid);
        $out = ['note', 'set_priority', 'set_category'];
        if ((int)$t->assignedto !== $userid && ((int)$t->assignedto === 0 || $p->supervisor)) {
            $out[] = 'take';
        }
        if (self::may_reassign($t, $userid)) {
            $out[] = 'assign';
            if ((int)$t->assignedto > 0) {
                $out[] = 'unassign';
            }
        }
        if ((int)$t->first_response_at > 0) {
            if ($t->status !== self::S_RESOLVED) {
                $out[] = 'resolve';
            }
            $out[] = 'close';
        }
        return $out;
    }

    public static function export_message(stdClass $m, int $userid, array $users = null): array {
        if ($users === null) {
            $users = self::load_users([(int)$m->senderid]);
        }
        $sender = $users[(int)$m->senderid] ?? null;
        return [
            'id' => (int)$m->id,
            'ticketid' => (int)$m->ticketid,
            'senderid' => (int)$m->senderid,
            'sendername' => $sender ? fullname($sender) : 'Mesa de ayuda',
            'senderrole' => (string)$m->senderrole,
            'body' => (string)$m->body,
            'internal' => (bool)$m->internal,
            'timecreated' => (int)$m->timecreated,
            'mine' => (int)$m->senderid === $userid && $userid > 0,
        ];
    }

    public static function mark_read(int $ticketid, int $userid): int {
        global $DB;
        [$ticket, $role] = self::require_ticket($ticketid, $userid);
        $select = 'ticketid = ? AND deleted = 0' . ($role === self::ROLE_REQUESTER ? ' AND internal = 0' : '');
        $last = (int)$DB->get_field_select('gmk_tkt_message', 'MAX(id)', $select, [(int)$ticket->id]);
        return self::set_read_pointer((int)$ticket->id, $userid, $last);
    }

    // ---------------------------------------------------------------------
    // Internals: state, assignment, messages
    // ---------------------------------------------------------------------

    /**
     * Validated status change + event. $extra holds other columns to update.
     */
    public static function set_status(stdClass $ticket, string $to, int $actorid, array $extra = []): void {
        $from = (string)$ticket->status;
        if (!self::can_transition($from, $to)) {
            throw new moodle_exception('tkt_err_badtransition', 'local_grupomakro_core', '', (object)['from' => $from, 'to' => $to]);
        }
        if ($from !== $to) {
            $extra['status'] = $to;
            self::log_event((int)$ticket->id, 'status', $from, $to, $actorid);
        }
        self::update_ticket($ticket, $extra);
    }

    public static function update_ticket(stdClass $ticket, array $fields): void {
        global $DB;
        if (empty($fields)) {
            return;
        }
        $fields['timemodified'] = time();
        $DB->update_record('gmk_tkt_ticket', (object)(['id' => (int)$ticket->id] + $fields));
        foreach ($fields as $k => $v) {
            $ticket->$k = $v;
        }
    }

    private static function close(stdClass $ticket, int $actorid, string $reason): void {
        self::set_status($ticket, self::S_CLOSED, $actorid, ['closed_at' => time(), 'waiting_since' => 0]);
        self::log_event((int)$ticket->id, 'closed', '', $reason, $actorid);
        self::system_message($ticket, 'closed', self::template_vars($ticket));
    }

    private static function reopen(stdClass $ticket, int $actorid, array $extra): void {
        $now = time();
        $extra += [
            'reopened_count' => (int)$ticket->reopened_count + 1,
            'priority' => self::PRIORITY_HIGH,
            'resolved_at' => 0,
            'waiting_since' => $now,
            'escalation_level' => 0,
            'escalated_at' => 0,
        ];
        self::set_status($ticket, self::S_REOPENED, $actorid, $extra);
        self::system_message($ticket, 'reopened', self::template_vars($ticket));
    }

    /**
     * Sets assignedto (+ status asignado when still new) and logs it.
     */
    public static function apply_assignment(stdClass $ticket, int $agentid, int $actorid, string $how): void {
        $old = (int)$ticket->assignedto;
        self::log_event((int)$ticket->id, 'assigned', (string)$old, (string)$agentid, $actorid, ['how' => $how]);
        $update = ['assignedto' => $agentid, 'assigned_at' => time()];
        if ($ticket->status === self::S_NEW) {
            self::set_status($ticket, self::S_ASSIGNED, $actorid, $update);
        } else {
            self::update_ticket($ticket, $update);
        }
    }

    /**
     * Balanced auto-assignment: available topic agent with fewer open tickets
     * (respecting max_open), else the backups of the topic agents, else 0 (queue).
     */
    public static function pick_agent(stdClass $category, bool $force = false): int {
        global $DB;
        if (empty($category->autoassign) && !$force) {
            return 0;
        }
        $agents = $DB->get_records_sql(
            "SELECT a.id, a.userid, a.max_open, a.can_auto_assign
               FROM {gmk_tkt_agent} a
               JOIN {user} u ON u.id = a.userid AND u.deleted = 0 AND u.suspended = 0
              WHERE a.categoryid = :cat
           ORDER BY a.id", ['cat' => (int)$category->id]);
        if (!$agents) {
            return 0;
        }
        $candidates = [];
        foreach ($agents as $a) {
            if (!empty($a->can_auto_assign) || $force) {
                $candidates[(int)$a->userid] = (int)$a->max_open;
            }
        }
        $best = self::least_loaded($candidates, (int)$category->areaid);
        if ($best > 0) {
            return $best;
        }

        // Backups of the topic agents (any agent of the topic, not only absent
        // ones: if every primary is unavailable or full the backups take over).
        $agentids = array_values(array_unique(array_map(function($a) {
            return (int)$a->userid;
        }, $agents)));
        [$in, $params] = $DB->get_in_or_equal($agentids, SQL_PARAMS_NAMED, 'ag');
        $params['cat'] = (int)$category->id;
        $backups = $DB->get_fieldset_sql(
            "SELECT b.backupuserid
               FROM {gmk_tkt_backup} b
               JOIN {user} u ON u.id = b.backupuserid AND u.deleted = 0 AND u.suspended = 0
              WHERE b.userid $in AND (b.categoryid = 0 OR b.categoryid = :cat)
           ORDER BY b.sortorder, b.id", $params);
        $candidates = [];
        foreach ($backups as $uid) {
            $candidates[(int)$uid] = 0;
        }
        return self::least_loaded($candidates, (int)$category->areaid);
    }

    /**
     * Available candidate with fewer open tickets, preferring those within
     * their own business hours now (fase 2: horario por agente). With
     * $onlyinhours, nobody outside their hours is returned.
     *
     * @param array $candidates userid => max_open (0 = no limit)
     */
    public static function least_loaded(array $candidates, int $areaid = 0, bool $onlyinhours = false,
            int $now = 0): int {
        if (!$candidates) {
            return 0;
        }
        $now = $now ?: time();
        $loads = self::open_loads(array_keys($candidates));
        $presence = self::presence_map(array_keys($candidates));
        $best = [0, 0];
        $bestload = [PHP_INT_MAX, PHP_INT_MAX];
        foreach ($candidates as $uid => $max) {
            if (!($presence[$uid] ?? true)) {
                continue;
            }
            $load = $loads[$uid] ?? 0;
            if ($max > 0 && $load >= $max) {
                continue;
            }
            $inhours = $areaid <= 0 || business_hours::is_open(policy::user_clock((int)$uid, $areaid), $now);
            $slot = $inhours ? 0 : 1;
            if ($load < $bestload[$slot]) {
                $best[$slot] = $uid;
                $bestload[$slot] = $load;
            }
        }
        return $best[0] ?: ($onlyinhours ? 0 : $best[1]);
    }

    /**
     * @return array userid => open tickets assigned
     */
    public static function open_loads(array $userids): array {
        global $DB;
        $userids = array_values(array_filter(array_map('intval', $userids)));
        if (!$userids) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'ld');
        [$sin, $sparams] = $DB->get_in_or_equal(self::open_statuses(), SQL_PARAMS_NAMED, 'ls');
        $rows = $DB->get_records_sql("SELECT assignedto, COUNT(1) AS n FROM {gmk_tkt_ticket}
            WHERE assignedto $in AND status $sin GROUP BY assignedto", $params + $sparams);
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r->assignedto] = (int)$r->n;
        }
        return $out;
    }

    /**
     * @return array userid => bool (missing row = available)
     */
    public static function presence_map(array $userids): array {
        global $DB;
        $userids = array_values(array_filter(array_map('intval', $userids)));
        $out = [];
        foreach ($userids as $u) {
            $out[$u] = true;
        }
        if (!$userids) {
            return $out;
        }
        [$in, $params] = $DB->get_in_or_equal($userids);
        foreach ($DB->get_records_select('gmk_tkt_presence', "userid $in", $params) as $r) {
            $out[(int)$r->userid] = (bool)$r->available;
        }
        return $out;
    }

    /**
     * People to tell about an unassigned ticket of $category: its agents and
     * their backups (available ones; everyone when nobody is available).
     *
     * @return int[]
     */
    public static function category_staff(stdClass $category): array {
        global $DB;
        $agents = array_map('intval', $DB->get_fieldset_select('gmk_tkt_agent', 'userid', 'categoryid = ?',
            [(int)$category->id]));
        $ids = $agents;
        if ($agents) {
            [$in, $params] = $DB->get_in_or_equal($agents, SQL_PARAMS_NAMED, 'cs');
            $params['cat'] = (int)$category->id;
            $ids = array_merge($ids, array_map('intval', $DB->get_fieldset_select('gmk_tkt_backup', 'backupuserid',
                "userid $in AND (categoryid = 0 OR categoryid = :cat)", $params)));
        }
        $ids = array_values(array_unique(array_filter($ids)));
        $presence = self::presence_map($ids);
        $available = array_values(array_filter($ids, function($u) use ($presence) {
            return $presence[$u] ?? true;
        }));
        return $available ?: $ids;
    }

    private static function insert_message(stdClass $ticket, int $senderid, string $role, string $body,
            bool $internal): stdClass {
        global $DB;
        $now = time();
        $message = (object)[
            'ticketid' => (int)$ticket->id,
            'senderid' => $senderid,
            'senderrole' => $role,
            'body' => $body,
            'internal' => (int)$internal,
            'hasattachments' => 0,
            'deleted' => 0,
            'timecreated' => $now,
        ];
        $message->id = $DB->insert_record('gmk_tkt_message', $message);
        // Exports hide the preview of an internal last message from the requester.
        self::update_ticket($ticket, ['lastmessageid' => (int)$message->id, 'lastmessageat' => $now]);
        return $message;
    }

    /**
     * Posts the automatic message $code (when enabled) as "system".
     */
    public static function system_message(stdClass $ticket, string $code, array $vars): ?stdClass {
        $body = self::render_template($code, $vars);
        if ($body === null || $body === '') {
            return null;
        }
        $m = self::insert_message($ticket, 0, self::ROLE_SYSTEM, $body, false);
        self::log_event((int)$ticket->id, 'auto_message', '', $code, 0);
        return $m;
    }

    private static function out_of_hours_message(stdClass $ticket, stdClass $area, array $vars): void {
        global $DB;
        $recent = $DB->record_exists_select('gmk_tkt_event',
            "ticketid = :t AND eventtype = 'auto_message' AND " . $DB->sql_compare_text('newvalue') . " = :c AND timecreated > :since",
            ['t' => (int)$ticket->id, 'c' => 'out_of_hours', 'since' => time() - self::OUT_OF_HOURS_GAP]);
        if (!$recent) {
            self::system_message($ticket, 'out_of_hours', $vars);
        }
    }

    public static function render_template(string $code, array $vars): ?string {
        global $DB;
        $tpl = $DB->get_record('gmk_tkt_template', ['code' => $code]);
        if (!$tpl || empty($tpl->enabled)) {
            return null;
        }
        return trim(strtr((string)$tpl->body, $vars));
    }

    public static function template_vars(stdClass $ticket, ?stdClass $area = null, ?stdClass $category = null): array {
        global $DB;
        $area = $area ?: $DB->get_record('gmk_tkt_area', ['id' => (int)$ticket->areaid]);
        $category = $category ?: $DB->get_record('gmk_tkt_category', ['id' => (int)$ticket->categoryid]);
        $agent = (int)$ticket->assignedto > 0 ? \core_user::get_user((int)$ticket->assignedto) : null;
        $student = \core_user::get_user((int)$ticket->userid);
        $areaname = $area ? format_string($area->name) : '';
        return [
            '{numero}' => (string)$ticket->number,
            '{categoria}' => $category ? format_string($category->name) : '',
            '{area}' => $areaname,
            '{agente}' => $agent ? fullname($agent) : ('el equipo de ' . $areaname),
            '{horario}' => $area ? business_hours::describe($area) : '',
            '{estudiante}' => $student ? fullname($student) : '',
            // Hours left between the reminder and the automatic close (business hours).
            '{horas_cierre}' => (string)max(1, config::get_int('tkt_close_hours') - config::get_int('tkt_remind_hours')),
        ];
    }

    public static function log_event(int $ticketid, string $type, string $old, string $new, int $actorid,
            array $metadata = []): void {
        global $DB;
        $DB->insert_record('gmk_tkt_event', (object)[
            'ticketid' => $ticketid,
            'eventtype' => $type,
            'oldvalue' => $old,
            'newvalue' => $new,
            'actorid' => $actorid,
            'metadata' => $metadata ? json_encode($metadata) : null,
            'timecreated' => time(),
        ]);
    }

    private static function describe_event(stdClass $e, array $users): string {
        $name = function($id) use ($users) {
            $id = (int)$id;
            return $id > 0 && isset($users[$id]) ? fullname($users[$id]) : 'cola del área';
        };
        switch ($e->eventtype) {
            case 'created':
                return 'Ticket creado';
            case 'status':
                return 'Estado: ' . (self::STAFF_LABELS[$e->oldvalue] ?? $e->oldvalue) . ' → '
                    . (self::STAFF_LABELS[$e->newvalue] ?? $e->newvalue);
            case 'assigned':
                return 'Asignado: ' . $name($e->oldvalue) . ' → ' . $name($e->newvalue);
            case 'unassigned':
                return 'Devuelto a la cola (antes: ' . $name($e->oldvalue) . ')';
            case 'priority':
                $labels = ['Baja', 'Normal', 'Alta'];
                return 'Prioridad: ' . ($labels[(int)$e->oldvalue] ?? $e->oldvalue) . ' → ' . ($labels[(int)$e->newvalue] ?? $e->newvalue);
            case 'category':
                return 'Cambio de tema';
            case 'confirmation':
                return 'El solicitante respondió "' . ($e->newvalue === 'si' ? 'Sí, se resolvió' : 'No se resolvió') . '"';
            case 'closed':
                $reasons = ['confirmed' => 'confirmado por el solicitante', 'staff' => 'por el personal',
                    'timeout' => 'sin respuesta del solicitante'];
                return 'Cerrado (' . ($reasons[$e->newvalue] ?? $e->newvalue) . ')';
            case 'auto_message':
                return 'Mensaje automático: ' . $e->newvalue;
            case 'escalation':
                $meta = json_decode((string)$e->metadata, true) ?: [];
                $colors = [1 => 'verde con aviso', 2 => 'amarillo', 3 => 'naranja', 4 => 'rojo'];
                return 'Escalamiento ' . ($meta['repeat'] ?? false ? '(repetición) ' : '') . 'a ' . ($colors[(int)$e->newvalue] ?? $e->newvalue)
                    . (isset($meta['minutes']) ? ' · ' . (int)$meta['minutes'] . ' min hábiles de espera' : '')
                    . (!empty($meta['actions']) ? ' · ' . implode(', ', (array)$meta['actions']) : '');
            case 'noack':
                $meta = json_decode((string)$e->metadata, true) ?: [];
                return 'Sin acuse del responsable: aviso a ' . (int)($meta['recipients'] ?? 0) . ' persona(s) de respaldo';
            case 'rating':
                return 'Calificación: ' . (int)$e->newvalue . ' de 5';
            case 'rating_dismissed':
                return 'El solicitante pospuso la calificación';
            default:
                return (string)$e->eventtype;
        }
    }

    private static function ensure_seq_row(int $year): void {
        global $DB;
        if ($DB->record_exists('gmk_tkt_seq', ['year' => $year])) {
            return;
        }
        try {
            $DB->insert_record('gmk_tkt_seq', (object)['year' => $year, 'lastnumber' => 0]);
        } catch (\dml_write_exception $e) {
            // Created concurrently: fine.
        }
    }

    /**
     * Next TKT-AAAA-NNNN. Must run inside the create transaction: the row lock
     * serialises concurrent creations.
     */
    private static function next_number(int $year): string {
        global $DB;
        $row = $DB->get_record_sql('SELECT id, lastnumber FROM {gmk_tkt_seq} WHERE year = ? FOR UPDATE', [$year]);
        if (!$row) {
            throw new moodle_exception('tkt_err_sequence', 'local_grupomakro_core');
        }
        $next = (int)$row->lastnumber + 1;
        $DB->update_record('gmk_tkt_seq', (object)['id' => $row->id, 'lastnumber' => $next]);
        return sprintf('TKT-%04d-%04d', $year, $next);
    }

    private static function set_read_pointer(int $ticketid, int $userid, int $messageid): int {
        global $DB;
        $now = time();
        $existing = $DB->get_record('gmk_tkt_read', ['ticketid' => $ticketid, 'userid' => $userid]);
        if ($existing) {
            if ($messageid > (int)$existing->lastreadmessageid) {
                $DB->update_record('gmk_tkt_read', (object)['id' => $existing->id, 'lastreadmessageid' => $messageid,
                    'timeread' => $now]);
                return $messageid;
            }
            return (int)$existing->lastreadmessageid;
        }
        $DB->insert_record('gmk_tkt_read', (object)['ticketid' => $ticketid, 'userid' => $userid,
            'lastreadmessageid' => $messageid, 'timeread' => $now]);
        return $messageid;
    }

    public static function clean_body(string $body): string {
        return \local_grupomakro_core\local\messaging\manager::clean_body($body);
    }

    public static function excerpt(string $body): string {
        $body = trim(preg_replace('/\s+/u', ' ', $body) ?? '');
        if (\core_text::strlen($body) > self::EXCERPT_LENGTH) {
            $body = \core_text::substr($body, 0, self::EXCERPT_LENGTH - 1) . '…';
        }
        return $body;
    }

    private static function local_datetime(int $time): \DateTimeImmutable {
        return (new \DateTimeImmutable('@' . $time))->setTimezone(\core_date::get_server_timezone_object());
    }

    /**
     * @return stdClass[] keyed by id
     */
    public static function load_users(array $ids, bool $withcontact = false): array {
        global $DB;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal($ids);
        $fields = 'id, firstname, lastname, firstnamephonetic, lastnamephonetic, middlename, alternatename'
            . ($withcontact ? ', idnumber, email' : '');
        return $DB->get_records_select('user', "id $in", $params, '', $fields);
    }

    // ---------------------------------------------------------------------
    // Notifications
    // ---------------------------------------------------------------------

    public static function outbox_size(): int {
        return count(self::$outbox);
    }

    public static function outbox_truncate(int $size): void {
        self::$outbox = array_slice(self::$outbox, 0, max(0, $size));
    }

    public static function queue_notification(string $provider, array $recipients, stdClass $ticket, ?stdClass $message,
            array $extra = []): void {
        self::$outbox[] = [$provider, $recipients, clone $ticket, $message, $extra];
    }

    /**
     * Sends queued notifications. Never throws: a failed notification must not
     * lose what was already stored.
     */
    public static function flush_notifications(): void {
        $queue = self::$outbox;
        self::$outbox = [];
        foreach ($queue as [$provider, $recipients, $ticket, $message, $extra]) {
            try {
                self::notify($provider, $recipients, $ticket, $message, $extra);
            } catch (\Throwable $e) {
                if (function_exists('gmk_log')) {
                    gmk_log('support notify ' . $provider . ' failed for ticket ' . $ticket->id . ': ' . $e->getMessage());
                }
            }
        }
    }

    /**
     * Requester-facing link: the portal for students, Moodle for teachers (§8.4).
     */
    public static function requester_url(stdClass $ticket): string {
        if ((string)($ticket->requesterrole ?? 'student') === 'teacher') {
            return (new \moodle_url('/local/grupomakro_core/pages/support_my.php', ['ticket' => (int)$ticket->id]))->out(false);
        }
        return config::portal_url('/soporte?ticket=' . (int)$ticket->id);
    }

    /**
     * Whether $userid may get another e-mail about $ticketid now (tkt_mail_gap
     * minutes per ticket and person, §10). Records the send when allowed.
     */
    public static function mail_gate(int $ticketid, int $userid, int $now = 0): bool {
        global $DB;
        $gap = config::get_int('tkt_mail_gap') * MINSECS;
        if ($gap <= 0 || $ticketid <= 0) {
            return true;
        }
        $now = $now ?: time();
        $row = $DB->get_record('gmk_tkt_mailgate', ['ticketid' => $ticketid, 'userid' => $userid]);
        if ($row && (int)$row->timesent > $now - $gap) {
            return false;
        }
        if ($row) {
            $DB->update_record('gmk_tkt_mailgate', (object)['id' => $row->id, 'timesent' => $now]);
        } else {
            $DB->insert_record('gmk_tkt_mailgate', (object)['ticketid' => $ticketid, 'userid' => $userid, 'timesent' => $now]);
        }
        return true;
    }

    private static function notify(string $provider, array $recipients, stdClass $ticket, ?stdClass $message,
            array $extra = []): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/message/lib.php');
        $recipients = array_values(array_unique(array_filter(array_map('intval', $recipients))));
        $actor = $message && (int)$message->senderid > 0 ? (int)$message->senderid : 0;
        $recipients = array_diff($recipients, [$actor]);
        if (!$recipients) {
            return;
        }
        $category = $DB->get_record('gmk_tkt_category', ['id' => (int)$ticket->categoryid]);
        $area = $DB->get_record('gmk_tkt_area', ['id' => (int)$ticket->areaid]);
        $torequester = in_array($provider, ['support_reply', 'support_resolved'], true);
        $url = $torequester
            ? self::requester_url($ticket)
            : (new \moodle_url('/local/grupomakro_core/pages/support_desk.php', ['ticket' => (int)$ticket->id]))->out(false);
        $agent = (int)$ticket->assignedto > 0 ? \core_user::get_user((int)$ticket->assignedto) : null;
        $student = \core_user::get_user((int)$ticket->userid);
        $a = (object)[
            'number' => (string)$ticket->number,
            'area' => $area ? format_string($area->name) : '',
            'category' => $category ? format_string($category->name) : '',
            // Confidential tickets never carry names or text in notifications.
            'student' => !empty($ticket->confidential) ? 'Ticket confidencial' : ($student ? fullname($student) : ''),
            'excerpt' => (!empty($ticket->confidential) || !$message || !empty($message->internal))
                ? '' : self::excerpt((string)$message->body),
            'url' => $url,
            'agent' => $agent ? fullname($agent) : 'sin asignar',
            'minutes' => '',
            'level' => '',
            'rating' => '',
        ];
        foreach ($extra as $k => $v) {
            $a->$k = (string)$v;
        }
        $subject = get_string('tkt_notif_' . $provider . '_subject', 'local_grupomakro_core', $a);
        $body = get_string('tkt_notif_' . $provider . '_body', 'local_grupomakro_core', $a);
        $from = $actor > 0 ? \core_user::get_user($actor) : \core_user::get_noreply_user();

        foreach ($recipients as $rid) {
            $to = \core_user::get_user($rid);
            if (!$to || !empty($to->deleted) || !empty($to->suspended)) {
                continue;
            }
            $msg = new \core\message\message();
            $msg->component = 'local_grupomakro_core';
            // Same ticket and person within tkt_mail_gap: popup only (support_live has no e-mail).
            $msg->name = self::mail_gate((int)$ticket->id, (int)$to->id) ? $provider : 'support_live';
            $msg->userfrom = $from ?: \core_user::get_noreply_user();
            $msg->userto = $to;
            $msg->subject = $subject;
            $msg->fullmessage = $body;
            $msg->fullmessageformat = FORMAT_PLAIN;
            $msg->fullmessagehtml = nl2br(s($body));
            $msg->smallmessage = $subject;
            $msg->notification = 1;
            $msg->contexturl = $url;
            $msg->contexturlname = (string)$ticket->number;
            $msg->courseid = SITEID;
            message_send($msg);
        }
    }
}
