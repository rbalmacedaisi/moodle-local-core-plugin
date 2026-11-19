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
 * Seguimiento de inasistencias (20261001131): un caso por estudiante bloqueado
 * por inasistencia en una clase en curso, con un historial de gestiones de
 * contacto que no se edita ni se borra (auditable).
 *
 * - sync(): abre un caso por cada bloqueo activo (gmk_class_absence_state
 *   alert_level 3 sin desbloqueo posterior, clase abierta y sin terminar) y
 *   cierra automaticamente los casos cuyo bloqueo ya no esta activo.
 * - Un caso esta "al dia" si tiene al menos una gestion y su proximo
 *   seguimiento no ha vencido; si no, es pendiente (dashboard "Mis pendientes").
 *
 * Pedido de Ramiro, 19-nov-2026.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\absence;

use context_system;
use moodle_url;
use stdClass;

defined('MOODLE_INTERNAL') || die();

class cases {

    const S_NEW = 'nuevo';
    const S_WORKING = 'en_gestion';
    const S_FOLLOWUP = 'en_seguimiento';
    const S_CLOSED = 'cerrado';

    const STATUSES = [
        self::S_NEW => 'Nuevo',
        self::S_WORKING => 'En gestión',
        self::S_FOLLOWUP => 'En seguimiento',
        self::S_CLOSED => 'Cerrado',
    ];

    const CHANNELS = [
        'llamada' => 'Llamada',
        'whatsapp' => 'WhatsApp',
        'correo' => 'Correo',
        'presencial' => 'Presencial',
        'otro' => 'Otro',
    ];

    const RESULTS = [
        'contactado' => 'Contactado',
        'no_contesta' => 'No contesta',
        'numero_errado' => 'Número errado o sin datos',
        'se_reincorpora' => 'Se reincorpora',
        'justifico' => 'Justificó las faltas',
        'se_retira' => 'Se retira',
        'otro' => 'Otro',
        'correccion' => 'Corrección de un registro anterior',
    ];

    const CLOSE_REASONS = [
        'reincorporado' => 'Se reincorporó',
        'justificado' => 'Faltas justificadas / desbloqueado',
        'retiro' => 'Se retira',
        'pierde_materia' => 'Pierde la materia',
        'sin_contacto' => 'Sin contacto posible',
        'otro' => 'Otro',
        'auto_desbloqueado' => 'Automático: el bloqueo ya no está activo',
    ];

    /** Business days for the first contact (dashboard SLA absence.block uses the same). */
    const FIRST_CONTACT_DAYS = 2;

    public static function can_view(int $userid): bool {
        return has_capability('local/grupomakro_core:viewabsencedashboard', context_system::instance(), $userid)
            || self::can_manage($userid) || self::can_supervise($userid);
    }

    public static function can_manage(int $userid): bool {
        return has_capability('local/grupomakro_core:manage_absence_cases', context_system::instance(), $userid);
    }

    public static function can_supervise(int $userid): bool {
        return has_capability('local/grupomakro_core:supervise_absence_cases', context_system::instance(), $userid);
    }

    /**
     * Opens a case per active block and auto-closes cases whose block is gone.
     *
     * @return array ['opened' => n, 'closed' => n]
     */
    public static function sync(int $now = 0): array {
        global $DB;
        $now = $now ?: time();
        $active = $DB->get_records_sql("SELECT a.id, a.userid, a.classid, a.blocked_at
                                          FROM {gmk_class_absence_state} a
                                          JOIN {gmk_class} c ON c.id = a.classid AND c.closed = 0
                                               AND (c.enddate IS NULL OR c.enddate = 0 OR c.enddate > :now)
                                          JOIN {user} u ON u.id = a.userid AND u.deleted = 0
                                         WHERE a.alert_level = 3 AND a.blocked_at > 0
                                           AND (a.unblocked_at IS NULL OR a.unblocked_at < a.blocked_at)",
            ['now' => $now]);
        $open = $DB->get_records_select('gmk_abs_case', 'status <> :c', ['c' => self::S_CLOSED], '',
            'id, userid, classid, blocked_at');
        $openkeys = [];
        foreach ($open as $c) {
            $openkeys[$c->userid . '_' . $c->classid . '_' . $c->blocked_at] = (int)$c->id;
        }
        $activekeys = [];
        $opened = 0;
        foreach ($active as $a) {
            $key = $a->userid . '_' . $a->classid . '_' . $a->blocked_at;
            $activekeys[$key] = true;
            if (isset($openkeys[$key])) {
                continue;
            }
            if ($DB->record_exists('gmk_abs_case', ['userid' => $a->userid, 'classid' => $a->classid,
                    'blocked_at' => $a->blocked_at])) {
                // Same block episode, closed by hand: do not reopen.
                continue;
            }
            $DB->insert_record('gmk_abs_case', (object)[
                'userid' => (int)$a->userid, 'classid' => (int)$a->classid, 'blocked_at' => (int)$a->blocked_at,
                'status' => self::S_NEW, 'next_followup' => 0, 'first_contact_at' => 0, 'last_action_at' => 0,
                'last_result' => '', 'actions' => 0, 'closed_reason' => '', 'closedat' => 0, 'closedby' => 0,
                'timecreated' => $now, 'timemodified' => $now,
            ]);
            $opened++;
        }
        $closed = 0;
        foreach ($openkeys as $key => $id) {
            if (!isset($activekeys[$key])) {
                $DB->update_record('gmk_abs_case', (object)['id' => $id, 'status' => self::S_CLOSED,
                    'closed_reason' => 'auto_desbloqueado', 'closedat' => $now, 'closedby' => 0, 'timemodified' => $now]);
                $closed++;
            }
        }
        return ['opened' => $opened, 'closed' => $closed];
    }

    /** Whether an open case needs action now: never managed, or follow-up date reached. */
    public static function is_due(stdClass $c, int $now): bool {
        if ($c->status === self::S_CLOSED) {
            return false;
        }
        if ((int)$c->actions === 0) {
            return true;
        }
        return (int)$c->next_followup > 0 && (int)$c->next_followup <= $now;
    }

    /**
     * Contact data of a student (only what exists).
     */
    public static function contact(stdClass $u, array $profile): array {
        $phones = [];
        foreach ([(string)($profile['custom_phone'] ?? ''), (string)$u->phone1, (string)$u->phone2] as $p) {
            $p = trim($p);
            if ($p !== '' && !in_array($p, $phones, true)) {
                $phones[] = $p;
            }
        }
        $out = [];
        foreach ($phones as $p) {
            $digits = preg_replace('/\D+/', '', $p);
            // Panama: 8-digit mobiles get the 507 prefix for WhatsApp.
            $wa = strlen($digits) === 8 ? '507' . $digits : $digits;
            $out[] = ['type' => 'phone', 'value' => $p, 'tel' => 'tel:' . ($digits ?: $p),
                'whatsapp' => $digits !== '' ? 'https://wa.me/' . $wa : ''];
        }
        if (trim((string)$u->email) !== '') {
            $out[] = ['type' => 'email', 'label' => 'Institucional', 'value' => (string)$u->email];
        }
        $personal = trim((string)($profile['personalemail'] ?? ''));
        if ($personal !== '' && strcasecmp($personal, (string)$u->email) !== 0) {
            $out[] = ['type' => 'email', 'label' => 'Personal', 'value' => $personal];
        }
        return $out;
    }

    /**
     * Cases with student, class and contact data.
     *
     * @param array $filters status (open|due|unmanaged|overdue|closed|all), search, ids
     * @return array list of case arrays
     */
    public static function list(array $filters, int $now = 0): array {
        global $DB;
        $now = $now ?: time();
        $where = [];
        $params = [];
        $status = (string)($filters['status'] ?? 'open');
        if ($status === 'closed') {
            $where[] = 'k.status = :closed';
            $params['closed'] = self::S_CLOSED;
        } else if ($status !== 'all') {
            $where[] = 'k.status <> :closed';
            $params['closed'] = self::S_CLOSED;
        }
        if (!empty($filters['ids'])) {
            [$in, $inparams] = $DB->get_in_or_equal(array_map('intval', $filters['ids']), SQL_PARAMS_NAMED, 'ci');
            $where[] = "k.id $in";
            $params += $inparams;
        }
        $search = trim((string)($filters['search'] ?? ''));
        if ($search !== '') {
            $like = [];
            foreach (['u.firstname', 'u.lastname', 'u.username', 'c.name'] as $i => $f) {
                $like[] = $DB->sql_like($f, ':q' . $i, false, false);
                $params['q' . $i] = '%' . $DB->sql_like_escape($search) . '%';
            }
            $where[] = '(' . implode(' OR ', $like) . ')';
        }
        $sql = "SELECT k.*, u.firstname, u.lastname, u.username, u.email, u.phone1, u.phone2, u.suspended,
                       c.name AS classname, c.enddate AS classend, c.shift, a.absence_count,
                       t.firstname AS tfirst, t.lastname AS tlast
                  FROM {gmk_abs_case} k
                  JOIN {user} u ON u.id = k.userid
                  JOIN {gmk_class} c ON c.id = k.classid
             LEFT JOIN {gmk_class_absence_state} a ON a.userid = k.userid AND a.classid = k.classid
             LEFT JOIN {user} t ON t.id = c.instructorid
                 " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
              ORDER BY k.blocked_at ASC";
        $rows = $DB->get_records_sql($sql, $params, 0, 2000);
        if (!$rows) {
            return [];
        }
        $userids = array_unique(array_map(function($r) {
            return (int)$r->userid;
        }, $rows));
        $profile = self::profile_fields($userids);
        $plans = self::plans($userids);
        $out = [];
        foreach ($rows as $r) {
            $due = self::is_due($r, $now);
            if ($status === 'due' && !$due) {
                continue;
            }
            if ($status === 'unmanaged' && ((int)$r->actions > 0 || $r->status === self::S_CLOSED)) {
                continue;
            }
            if ($status === 'overdue' && !($due && (int)$r->actions > 0)) {
                continue;
            }
            $p = $profile[(int)$r->userid] ?? [];
            $out[] = [
                'id' => (int)$r->id,
                'userid' => (int)$r->userid,
                'student' => trim($r->firstname . ' ' . $r->lastname),
                'document' => (string)($p['documentnumber'] ?? $r->username),
                'journey' => (string)($p['gmkjourney'] ?? ''),
                'plan' => $plans[(int)$r->userid] ?? '',
                'suspended' => (int)$r->suspended,
                'classid' => (int)$r->classid,
                'classname' => format_string($r->classname),
                'teacher' => trim((string)$r->tfirst . ' ' . (string)$r->tlast),
                'classend' => (int)$r->classend,
                'absences' => (int)$r->absence_count,
                'blocked_at' => (int)$r->blocked_at,
                'status' => (string)$r->status,
                'statuslabel' => self::STATUSES[$r->status] ?? $r->status,
                'actions' => (int)$r->actions,
                'first_contact_at' => (int)$r->first_contact_at,
                'last_action_at' => (int)$r->last_action_at,
                'last_result' => (string)$r->last_result,
                'last_resultlabel' => self::RESULTS[$r->last_result] ?? '',
                'next_followup' => (int)$r->next_followup,
                'closed_reason' => (string)$r->closed_reason,
                'closed_reasonlabel' => self::CLOSE_REASONS[$r->closed_reason] ?? '',
                'closedat' => (int)$r->closedat,
                'due' => $due,
                'contact' => self::contact($r, $p),
            ];
        }
        return $out;
    }

    private static function profile_fields(array $userids): array {
        global $DB;
        if (!$userids) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'pu');
        $rows = $DB->get_recordset_sql("SELECT d.id, d.userid, f.shortname, d.data
                                          FROM {user_info_data} d
                                          JOIN {user_info_field} f ON f.id = d.fieldid
                                         WHERE d.userid $in
                                           AND f.shortname IN ('custom_phone', 'personalemail', 'documentnumber', 'gmkjourney')",
            $params);
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r->userid][$r->shortname] = (string)$r->data;
        }
        $rows->close();
        return $out;
    }

    private static function plans(array $userids): array {
        global $DB;
        if (!$userids) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'lu');
        $out = [];
        try {
            $rows = $DB->get_records_sql("SELECT lu.id, lu.userid, lp.name
                                            FROM {local_learning_users} lu
                                            JOIN {local_learning_plans} lp ON lp.id = lu.learningplanid
                                           WHERE lu.userid $in AND lu.status = 'activo'
                                             AND (lu.userroleid = 5 OR lu.userrolename = 'student')", $params);
            foreach ($rows as $r) {
                $out[(int)$r->userid] = format_string($r->name);
            }
        } catch (\dml_exception $e) {
            return [];
        }
        return $out;
    }

    /**
     * One case with its full, immutable history.
     */
    public static function get(int $caseid, int $now = 0): array {
        global $DB;
        $list = self::list(['status' => 'all', 'ids' => [$caseid]], $now);
        if (!$list) {
            throw new \moodle_exception('invalidrecord', 'error', '', 'gmk_abs_case');
        }
        $case = $list[0];
        $logs = $DB->get_records_sql("SELECT l.*, u.firstname, u.lastname
                                        FROM {gmk_abs_case_log} l
                                        JOIN {user} u ON u.id = l.userid
                                       WHERE l.caseid = :c
                                    ORDER BY l.timecreated DESC, l.id DESC", ['c' => $caseid]);
        $case['log'] = [];
        foreach ($logs as $l) {
            $case['log'][] = [
                'id' => (int)$l->id,
                'author' => trim($l->firstname . ' ' . $l->lastname),
                'channel' => (string)$l->channel,
                'channellabel' => self::CHANNELS[$l->channel] ?? $l->channel,
                'result' => (string)$l->result,
                'resultlabel' => self::RESULTS[$l->result] ?? $l->result,
                'note' => (string)$l->note,
                'next_followup' => (int)$l->next_followup,
                'newstatus' => (string)$l->newstatus,
                'newstatuslabel' => self::STATUSES[$l->newstatus] ?? '',
                'closed_reason' => (string)$l->closed_reason,
                'closed_reasonlabel' => self::CLOSE_REASONS[$l->closed_reason] ?? '',
                'corrects' => (int)$l->correctsid,
                'time' => (int)$l->timecreated,
            ];
        }
        return $case;
    }

    /**
     * Registers one management action (append-only).
     *
     * @param array $d channel, result, note, next_followup (timestamp), status, closed_reason, correctsid
     */
    public static function add_log(int $caseid, int $userid, array $d, int $now = 0): array {
        global $DB;
        $now = $now ?: time();
        $case = $DB->get_record('gmk_abs_case', ['id' => $caseid], '*', MUST_EXIST);
        $channel = (string)($d['channel'] ?? '');
        $result = (string)($d['result'] ?? '');
        $note = trim((string)($d['note'] ?? ''));
        $status = (string)($d['status'] ?? '');
        $reason = (string)($d['closed_reason'] ?? '');
        $next = (int)($d['next_followup'] ?? 0);
        $corrects = (int)($d['correctsid'] ?? 0);
        if (!isset(self::CHANNELS[$channel]) || !isset(self::RESULTS[$result])) {
            throw new \invalid_parameter_exception('Canal o resultado no válido.');
        }
        if ($result === 'correccion' && ($note === '' || $corrects <= 0
                || !$DB->record_exists('gmk_abs_case_log', ['id' => $corrects, 'caseid' => $caseid]))) {
            throw new \invalid_parameter_exception('Una corrección necesita la gestión que corrige y una nota.');
        }
        if ($status === '') {
            $status = $case->status === self::S_NEW ? self::S_WORKING : $case->status;
        }
        if (!isset(self::STATUSES[$status])) {
            throw new \invalid_parameter_exception('Estado no válido.');
        }
        if ($status === self::S_CLOSED) {
            if (!isset(self::CLOSE_REASONS[$reason]) || $reason === 'auto_desbloqueado') {
                throw new \invalid_parameter_exception('Indica el motivo del cierre.');
            }
            $next = 0;
        } else {
            $reason = '';
            if ($next > 0 && $next < $now - DAYSECS) {
                throw new \invalid_parameter_exception('La fecha del próximo seguimiento ya pasó.');
            }
            if ($next <= 0 && $result !== 'correccion') {
                throw new \invalid_parameter_exception('Indica la fecha del próximo seguimiento (o cierra el caso).');
            }
        }

        $transaction = $DB->start_delegated_transaction();
        $logid = $DB->insert_record('gmk_abs_case_log', (object)[
            'caseid' => $caseid, 'userid' => $userid, 'channel' => $channel, 'result' => $result,
            'note' => $note, 'next_followup' => $next, 'newstatus' => $status, 'closed_reason' => $reason,
            'correctsid' => $corrects, 'timecreated' => $now,
        ]);
        $update = (object)['id' => $caseid, 'status' => $status, 'timemodified' => $now,
            'actions' => (int)$case->actions + 1];
        if ($result !== 'correccion') {
            // A correction fixes the record; it is not a new contact.
            $update->last_action_at = $now;
            $update->last_result = $result;
        }
        if ($result !== 'correccion' || $next > 0) {
            $update->next_followup = $next;
        }
        if ((int)$case->first_contact_at === 0 && $result !== 'correccion') {
            $update->first_contact_at = $now;
        }
        if ($status === self::S_CLOSED) {
            $update->closed_reason = $reason;
            $update->closedat = $now;
            $update->closedby = $userid;
        } else if ($case->status === self::S_CLOSED) {
            // Reopened by hand.
            $update->closed_reason = '';
            $update->closedat = 0;
            $update->closedby = 0;
        }
        $DB->update_record('gmk_abs_case', $update);
        $transaction->allow_commit();

        \local_grupomakro_core\event\absence_case_logged::create([
            'objectid' => $logid,
            'context' => context_system::instance(),
            'relateduserid' => (int)$case->userid,
            'other' => ['caseid' => $caseid, 'result' => $result, 'status' => $status],
        ])->trigger();
        \local_grupomakro_core\local\dashboard\dashboard::invalidate_all();
        return self::get($caseid, $now);
    }

    /**
     * Supervision indicators. Each indicator carries the ids of its cases (drill-down).
     */
    public static function stats(int $now = 0, int $weeks = 8): array {
        global $DB;
        $now = $now ?: time();
        $all = $DB->get_records('gmk_abs_case', null, 'id');
        $area = \local_grupomakro_core\local\dashboard\sla::days_area();
        $ind = [
            'open' => [], 'unmanaged' => [], 'overdue' => [], 'ontime' => [], 'late' => [],
            'closed' => [], 'auto_closed' => [],
        ];
        $firsthours = [];
        $results = [];
        $reasons = [];
        foreach ($all as $c) {
            $id = (int)$c->id;
            if ($c->status !== self::S_CLOSED) {
                $ind['open'][] = $id;
                if ((int)$c->actions === 0) {
                    $ind['unmanaged'][] = $id;
                } else if ((int)$c->next_followup > 0 && (int)$c->next_followup <= $now) {
                    $ind['overdue'][] = $id;
                }
            } else {
                $ind[$c->closed_reason === 'auto_desbloqueado' ? 'auto_closed' : 'closed'][] = $id;
                $reasons[$c->closed_reason] = ($reasons[$c->closed_reason] ?? 0) + 1;
            }
            // First contact within FIRST_CONTACT_DAYS business days of the block, or of the
            // case opening for blocks that existed before this feature (clock starts at the later).
            $limit = \local_grupomakro_core\local\support\business_hours::add_minutes(self::clock_start($c),
                self::FIRST_CONTACT_DAYS * 1440, $area);
            if ((int)$c->first_contact_at > 0) {
                $ind[(int)$c->first_contact_at <= $limit ? 'ontime' : 'late'][] = $id;
                $firsthours[] = max(0, (int)$c->first_contact_at - self::clock_start($c)) / HOURSECS;
            } else if ($now > $limit && $c->closed_reason !== 'auto_desbloqueado') {
                $ind['late'][] = $id;
            }
        }
        foreach ($DB->get_records_sql("SELECT result, COUNT(1) AS n FROM {gmk_abs_case_log}
                                        WHERE result <> 'correccion' GROUP BY result") as $r) {
            $results[] = ['key' => $r->result, 'label' => self::RESULTS[$r->result] ?? $r->result, 'n' => (int)$r->n];
        }
        // Weekly series: actions logged and cases opened.
        $series = [];
        $monday = strtotime('monday this week', $now);
        for ($i = $weeks - 1; $i >= 0; $i--) {
            $from = $monday - $i * WEEKSECS;
            $to = $from + WEEKSECS;
            $series[] = [
                'label' => userdate($from, '%d/%m'),
                'actions' => $DB->count_records_select('gmk_abs_case_log',
                    "timecreated >= :f AND timecreated < :t AND result <> 'correccion'", ['f' => $from, 't' => $to]),
                'opened' => $DB->count_records_select('gmk_abs_case', 'timecreated >= :f AND timecreated < :t',
                    ['f' => $from, 't' => $to]),
                'closed' => $DB->count_records_select('gmk_abs_case',
                    "closedat >= :f AND closedat < :t AND closed_reason <> 'auto_desbloqueado'", ['f' => $from, 't' => $to]),
            ];
        }
        $byauthor = [];
        foreach ($DB->get_records_sql("SELECT l.userid, u.firstname, u.lastname, COUNT(1) AS n, MAX(l.timecreated) AS last
                                         FROM {gmk_abs_case_log} l JOIN {user} u ON u.id = l.userid
                                        WHERE l.result <> 'correccion'
                                     GROUP BY l.userid, u.firstname, u.lastname ORDER BY n DESC") as $r) {
            $byauthor[] = ['name' => trim($r->firstname . ' ' . $r->lastname), 'n' => (int)$r->n, 'last' => (int)$r->last];
        }
        $measured = count($ind['ontime']) + count($ind['late']);
        $reasonlist = [];
        foreach ($reasons as $k => $n) {
            $reasonlist[] = ['key' => $k, 'label' => self::CLOSE_REASONS[$k] ?? $k, 'n' => $n];
        }
        return [
            'indicators' => array_map(function($ids) {
                return ['n' => count($ids), 'ids' => $ids];
            }, $ind),
            'ontime_pct' => $measured ? round(100 * count($ind['ontime']) / $measured) : null,
            'avg_first_hours' => $firsthours ? round(array_sum($firsthours) / count($firsthours), 1) : null,
            'results' => $results,
            'reasons' => $reasonlist,
            'series' => $series,
            'byauthor' => $byauthor,
            'first_contact_days' => self::FIRST_CONTACT_DAYS,
        ];
    }

    /** Start of the first-contact clock: the block, or the case opening if later. */
    public static function clock_start(stdClass $c): int {
        return max((int)$c->blocked_at, (int)$c->timecreated);
    }

    public static function url(int $caseid = 0): string {
        return (new moodle_url('/local/grupomakro_core/pages/absence_cases.php', $caseid ? ['case' => $caseid] : []))->out(false);
    }
}
