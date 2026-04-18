<?php
/**
 * Pure helper functions for absence calculations.
 *
 * Shared by absence_dashboard.php and the mark_absent_students_inactive task.
 * Contains NO output or HTML – pure data-processing utilities only.
 *
 * @package    local_grupomakro_core
 * @copyright  2025 Antigravity
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// ── Shift / presentation helpers ──────────────────────────────────────────────

function absd_normalize_shift(string $s): string {
    $s = strtolower(trim($s));
    if (in_array($s, ['d', 'diurno', 'diurna', 'dia', 'mañana', 'manana'])) return 'Diurno';
    if (in_array($s, ['n', 'nocturno', 'nocturna', 'noche']))               return 'Nocturno';
    if (in_array($s, ['s', 'sabatino', 'sabatina', 'sabado', 'sábado']))    return 'Sabatino';
    return $s !== '' ? ucfirst($s) : 'Sin jornada';
}

function absd_shift_sort_key(string $shift): int {
    static $order = ['Diurno' => 1, 'Nocturno' => 2, 'Sabatino' => 3, 'Sin jornada' => 9];
    return $order[$shift] ?? 8;
}

function absd_format_schedule(array $rows): string {
    if (empty($rows)) return '';
    $dayMap = [
        'Lunes' => 'Lun', 'Martes' => 'Mar', 'Miercoles' => 'Mié', 'Miércoles' => 'Mié',
        'Jueves' => 'Jue', 'Viernes' => 'Vie', 'Sabado' => 'Sáb', 'Sábado' => 'Sáb', 'Domingo' => 'Dom',
    ];
    $grouped = [];
    foreach ($rows as $r) {
        $start   = substr((string)($r->start_time ?? ''), 0, 5);
        $end     = substr((string)($r->end_time   ?? ''), 0, 5);
        $key     = "{$start}–{$end}";
        $dl      = $dayMap[(string)($r->day ?? '')] ?? (string)($r->day ?? '');
        $grouped[$key][$dl] = true;
    }
    $parts = [];
    foreach ($grouped as $t => $days) {
        $parts[] = implode('/', array_keys($days)) . ' ' . $t;
    }
    return implode(', ', $parts);
}

function absd_pct_color(float $pct): string {
    if ($pct > 40) return '#dc2626';
    if ($pct >= 10) return '#f97316';
    return '#16a34a';
}

function absd_pct_bg(float $pct): string {
    if ($pct > 40) return '#fef2f2';
    if ($pct >= 10) return '#fff7ed';
    return '#f0fdf4';
}

function absd_pct_border(float $pct): string {
    if ($pct > 40) return '#fca5a5';
    if ($pct >= 10) return '#fdba74';
    return '#86efac';
}

function absd_pct_badge(float $pct): string {
    if ($pct > 40) return 'CRÍTICO';
    if ($pct >= 10) return 'ALERTA';
    return 'OK';
}

function absd_phone_for_wa(string $phone): string {
    $d = preg_replace('/[^0-9]/', '', $phone);
    if ($d === '') return '';
    if (strlen($d) >= 10 && substr($d, 0, 3) === '507') return $d;
    if (strlen($d) === 7 || strlen($d) === 8) return '507' . $d;
    return $d;
}

function absd_is_phone_field(stdClass $f): bool {
    $t = strtolower((string)$f->shortname . ' ' . (string)$f->name);
    foreach (['phone', 'telefono', 'movil', 'mobile', 'celular', 'whatsapp', 'customphone'] as $kw) {
        if (strpos($t, $kw) !== false) return true;
    }
    return false;
}

function absd_house_svg(): string {
    return '<svg viewBox="0 0 64 64" width="36" height="36" fill="none" xmlns="http://www.w3.org/2000/svg">
        <polygon points="32,6 60,30 56,30 56,58 36,58 36,40 28,40 28,58 8,58 8,30 4,30"
            stroke="currentColor" stroke-width="3" fill="currentColor" fill-opacity="0.12"/>
        <polygon points="32,6 60,30 4,30"
            stroke="currentColor" stroke-width="3" fill="currentColor" fill-opacity="0.25"/>
    </svg>';
}

// ── Attendance data helpers ────────────────────────────────────────────────────

/**
 * Returns the canonical date window used to resolve class attendance sessions.
 */
function absd_get_class_session_window(stdClass $class, int $nowts): array {
    $start = !empty($class->initdate)
        ? max(0, (int)$class->initdate - (30 * DAYSECS))
        : max(0, $nowts - (365 * DAYSECS));
    $end = !empty($class->enddate)
        ? ((int)$class->enddate + (60 * DAYSECS))
        : ($nowts + (365 * DAYSECS));
    return ['start' => $start, 'end' => $end];
}

/**
 * Resolve attendance instance id for a class using strict and fallback strategies.
 */
function absd_resolve_class_attendanceid(stdClass $class): int {
    global $DB;

    if (!empty($class->attendancemoduleid)) {
        $attcm = $DB->get_record_sql(
            "SELECT cm.instance, m.name AS modulename
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.id = :cmid",
            ['cmid' => (int)$class->attendancemoduleid],
            IGNORE_MISSING
        );
        if ($attcm && (string)$attcm->modulename === 'attendance') {
            return (int)$attcm->instance;
        }
    }

    $mappedattid = (int)$DB->get_field_sql(
        "SELECT attendanceid
           FROM {gmk_bbb_attendance_relation}
          WHERE classid = :classid
            AND attendanceid > 0
       ORDER BY id DESC",
        ['classid' => (int)$class->id],
        IGNORE_MULTIPLE
    );
    if ($mappedattid > 0 && $DB->record_exists('attendance', ['id' => $mappedattid])) {
        return $mappedattid;
    }

    $mappedattcmid = (int)$DB->get_field_sql(
        "SELECT attendancemoduleid
           FROM {gmk_bbb_attendance_relation}
          WHERE classid = :classid
            AND attendancemoduleid > 0
       ORDER BY id DESC",
        ['classid' => (int)$class->id],
        IGNORE_MULTIPLE
    );
    if ($mappedattcmid > 0) {
        $attcm2 = $DB->get_record_sql(
            "SELECT cm.instance, m.name AS modulename
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.id = :cmid",
            ['cmid' => $mappedattcmid],
            IGNORE_MISSING
        );
        if ($attcm2 && (string)$attcm2->modulename === 'attendance') {
            return (int)$attcm2->instance;
        }
    }

    if (!empty($class->courseid)) {
        $attid = (int)$DB->get_field('attendance', 'id', ['course' => (int)$class->courseid], IGNORE_MULTIPLE);
        if ($attid > 0) {
            return $attid;
        }
    }

    if (!empty($class->corecourseid) && (int)$class->corecourseid !== (int)$class->courseid) {
        $attid = (int)$DB->get_field('attendance', 'id', ['course' => (int)$class->corecourseid], IGNORE_MULTIPLE);
        if ($attid > 0) {
            return $attid;
        }
    }

    return 0;
}

/**
 * Resolve ALL attendance session ids for a class (past + future/planned).
 * Used for PDF generation where we want the full schedule grid.
 *
 * @param stdClass $class
 * @return int[]
 */
function absd_get_class_all_session_ids(stdClass $class): array {
    global $DB;

    $nowts = PHP_INT_MAX; // include future sessions
    $window = absd_get_class_session_window($class, time());
    $attendanceid = absd_resolve_class_attendanceid($class);
    $sessionids = [];

    if ($attendanceid > 0) {
        $ids = $DB->get_fieldset_sql(
            "SELECT s.id
               FROM {attendance_sessions} s
              WHERE s.attendanceid = :attid
                AND (s.groupid = :groupid OR s.groupid = 0)
                AND s.sessdate >= :start
                AND s.sessdate <= :end
           ORDER BY s.sessdate ASC",
            [
                'attid'   => $attendanceid,
                'groupid' => (int)$class->groupid,
                'start'   => $window['start'],
                'end'     => $window['end'],
            ]
        );
        $sessionids = array_values(array_unique(array_merge($sessionids, array_map('intval', $ids))));
    }

    // Relation table (authoritative for mixed/legacy).
    $relsessionids = $DB->get_fieldset_select(
        'gmk_bbb_attendance_relation',
        'attendancesessionid',
        'classid = :classid AND attendancesessionid > 0',
        ['classid' => (int)$class->id]
    );
    $relsessionids = array_values(array_unique(array_filter(array_map('intval', $relsessionids))));
    if (!empty($relsessionids)) {
        list($sessinsql, $sessparams) = $DB->get_in_or_equal($relsessionids, SQL_PARAMS_NAMED, 'sidrel');
        $rowids = $DB->get_fieldset_sql(
            "SELECT s.id FROM {attendance_sessions} s WHERE s.id $sessinsql ORDER BY s.sessdate ASC",
            $sessparams
        );
        $sessionids = array_values(array_unique(array_merge($sessionids, array_map('intval', $rowids))));
    }

    sort($sessionids);
    return $sessionids;
}

/**
 * Resolve past attendance session ids for a class (robust mode).
 *
 * @param stdClass $class
 * @param int $nowts
 * @return int[]
 */
function absd_get_class_past_session_ids(stdClass $class, int $nowts): array {
    global $DB;

    $window = absd_get_class_session_window($class, $nowts);
    $attendanceid = absd_resolve_class_attendanceid($class);
    $sessionids = [];

    if ($attendanceid > 0) {
        $strictids = $DB->get_fieldset_sql(
            "SELECT s.id
               FROM {attendance_sessions} s
              WHERE s.attendanceid = :attid
                AND (s.groupid = :groupid OR s.groupid = 0)
                AND s.sessdate >= :start
                AND s.sessdate <= :end
                AND s.sessdate < :nowts
           ORDER BY s.sessdate ASC",
            [
                'attid'   => $attendanceid,
                'groupid' => (int)$class->groupid,
                'start'   => $window['start'],
                'end'     => $window['end'],
                'nowts'   => $nowts,
            ]
        );
        $sessionids = array_values(array_unique(array_merge($sessionids, array_map('intval', $strictids))));
    }

    $relsessionids = $DB->get_fieldset_select(
        'gmk_bbb_attendance_relation',
        'attendancesessionid',
        'classid = :classid AND attendancesessionid > 0',
        ['classid' => (int)$class->id]
    );
    $relsessionids = array_values(array_unique(array_filter(array_map('intval', $relsessionids))));
    if (!empty($relsessionids)) {
        list($sessinsql, $sessparams) = $DB->get_in_or_equal($relsessionids, SQL_PARAMS_NAMED, 'sidrel');
        $rowids = $DB->get_fieldset_sql(
            "SELECT s.id
               FROM {attendance_sessions} s
              WHERE s.id $sessinsql
                AND s.sessdate < :nowts
           ORDER BY s.sessdate ASC",
            array_merge($sessparams, ['nowts' => $nowts])
        );
        $sessionids = array_values(array_unique(array_merge($sessionids, array_map('intval', $rowids))));
    }

    if (!empty($sessionids)) {
        sort($sessionids);
        return $sessionids;
    }

    if ($attendanceid > 0) {
        $fallbackids = $DB->get_fieldset_sql(
            "SELECT s.id
               FROM {attendance_sessions} s
              WHERE s.attendanceid = :attid
                AND (s.groupid = :groupid OR s.groupid = 0)
                AND s.sessdate >= :start
                AND s.sessdate <= :end
                AND s.sessdate < :nowts
           ORDER BY s.sessdate ASC",
            [
                'attid'   => $attendanceid,
                'groupid' => (int)$class->groupid,
                'start'   => $window['start'],
                'end'     => $window['end'],
                'nowts'   => $nowts,
            ]
        );
        $fallbackids = array_values(array_unique(array_map('intval', $fallbackids)));
        if (!empty($fallbackids)) {
            sort($fallbackids);
            return $fallbackids;
        }
    }

    return [];
}

/**
 * Returns enrolled user ids for a class (active statuses only).
 *
 * @param int $classid
 * @return int[]
 */
function absd_get_class_enrolled_userids(int $classid): array {
    global $DB;
    $userids = $DB->get_fieldset_sql(
        "SELECT DISTINCT userid
           FROM {gmk_course_progre}
          WHERE classid = :classid
            AND status IN (1,2,3)",
        ['classid' => $classid]
    );
    return array_values(array_unique(array_filter(array_map('intval', $userids))));
}

/**
 * Filters session ids to sessions where attendance was actually taken.
 *
 * @param int[] $sessionids
 * @return int[]
 */
function absd_get_taken_session_ids(array $sessionids): array {
    global $DB;
    if (empty($sessionids)) {
        return [];
    }

    list($sessinsql, $sessparams) = $DB->get_in_or_equal($sessionids, SQL_PARAMS_NAMED, 'tsess');
    $taken = [];

    $withlogs = $DB->get_fieldset_sql(
        "SELECT DISTINCT l.sessionid
           FROM {attendance_log} l
          WHERE l.sessionid $sessinsql",
        $sessparams
    );
    foreach ($withlogs as $sid) {
        $sid = (int)$sid;
        if ($sid > 0) $taken[$sid] = $sid;
    }

    try {
        $lasttaken = $DB->get_fieldset_sql(
            "SELECT s.id
               FROM {attendance_sessions} s
              WHERE s.id $sessinsql
                AND COALESCE(s.lasttaken, 0) > 0",
            $sessparams
        );
        foreach ($lasttaken as $sid) {
            $sid = (int)$sid;
            if ($sid > 0) $taken[$sid] = $sid;
        }
    } catch (Exception $e) {
        // Compatibility fallback for schemas without lasttaken column.
    }

    $out = array_values($taken);
    sort($out);
    return $out;
}

/**
 * Returns per-student absence counters from attendance logs.
 *
 * @param int[] $sessionids  Sessions where attendance was taken.
 * @param int[] $userids
 * @return array<int,int>  studentid => absences
 */
function absd_get_student_absences(array $sessionids, array $userids): array {
    global $DB;
    if (empty($sessionids) || empty($userids)) {
        return [];
    }

    list($sessinsql, $sessparams) = $DB->get_in_or_equal($sessionids, SQL_PARAMS_NAMED, 'sess');
    list($uinsql, $uinparams)     = $DB->get_in_or_equal($userids,   SQL_PARAMS_NAMED, 'usr');
    $params        = array_merge($sessparams, $uinparams);
    $totalsessions = count($sessionids);

    $sql = "SELECT l.studentid, COUNT(1) AS presentcount
              FROM {attendance_log} l
              JOIN (
                    SELECT studentid, sessionid, MAX(id) AS maxid
                      FROM {attendance_log}
                     WHERE sessionid $sessinsql
                       AND studentid $uinsql
                   GROUP BY studentid, sessionid
                ) ll ON ll.maxid = l.id
         LEFT JOIN {attendance_statuses} ast ON ast.id = l.statusid
             WHERE COALESCE(ast.grade, 0) > 0
           GROUP BY l.studentid";

    $presentbyuser = [];
    $rs = $DB->get_recordset_sql($sql, $params);
    foreach ($rs as $row) {
        $presentbyuser[(int)$row->studentid] = (int)$row->presentcount;
    }
    $rs->close();

    $map = [];
    foreach ($userids as $uid) {
        $uid     = (int)$uid;
        $present = $presentbyuser[$uid] ?? 0;
        $map[$uid] = max(0, $totalsessions - $present);
    }
    return $map;
}

/**
 * Returns per-student per-session attendance status.
 * Used by the PDF generator.
 *
 * @param int[] $sessionids
 * @param int[] $userids
 * @return array<int, array<int, array{present:bool, has_log:bool}>>
 *         [userid][sessionid] => status
 */
function absd_get_student_session_matrix(array $sessionids, array $userids): array {
    global $DB;
    if (empty($sessionids) || empty($userids)) {
        return [];
    }

    list($sessinsql, $sessparams) = $DB->get_in_or_equal($sessionids, SQL_PARAMS_NAMED, 'mat_sess');
    list($uinsql, $uinparams)     = $DB->get_in_or_equal($userids,   SQL_PARAMS_NAMED, 'mat_usr');
    $params = array_merge($sessparams, $uinparams);

    $sql = "SELECT l.studentid, l.sessionid, COALESCE(ast.grade, 0) AS grade
              FROM {attendance_log} l
              JOIN (
                    SELECT studentid, sessionid, MAX(id) AS maxid
                      FROM {attendance_log}
                     WHERE sessionid $sessinsql
                       AND studentid $uinsql
                   GROUP BY studentid, sessionid
                ) ll ON ll.maxid = l.id
         LEFT JOIN {attendance_statuses} ast ON ast.id = l.statusid";

    $matrix = [];
    $rs = $DB->get_recordset_sql($sql, $params);
    foreach ($rs as $row) {
        $uid = (int)$row->studentid;
        $sid = (int)$row->sessionid;
        $matrix[$uid][$sid] = ['present' => ((float)$row->grade > 0), 'has_log' => true];
    }
    $rs->close();

    return $matrix;
}

/**
 * Apply the three-field inactivation to a single user.
 *
 * Updates:
 *  1. user.suspended = 1
 *  2. user_info_data studentstatus = 'Inactivo'
 *  3. local_learning_users.status = 'suspendido'
 *
 * @param int $userid
 * @param int $studentstatus_fieldid  Field ID of the `studentstatus` custom profile field.
 * @return void
 */
function absd_mark_user_inactive(int $userid, int $studentstatus_fieldid): void {
    global $DB;

    // 1. Suspend Moodle account.
    $DB->set_field('user', 'suspended', 1, ['id' => $userid]);

    // 2. Custom profile field studentstatus → 'Inactivo'.
    if ($studentstatus_fieldid > 0) {
        $existing = $DB->get_record('user_info_data', ['userid' => $userid, 'fieldid' => $studentstatus_fieldid]);
        if ($existing) {
            $DB->set_field('user_info_data', 'data', 'Inactivo', ['id' => $existing->id]);
        } else {
            $rec = new stdClass();
            $rec->userid   = $userid;
            $rec->fieldid  = $studentstatus_fieldid;
            $rec->data     = 'Inactivo';
            $rec->dataformat = 0;
            $DB->insert_record('user_info_data', $rec);
        }
    }

    // 3. Academic status → 'suspendido' for all learning plan enrollments.
    $DB->set_field('local_learning_users', 'status', 'suspendido', ['userid' => $userid]);
}

/**
 * Reverse the three-field inactivation for a single user.
 * Only restores learning-plan rows that were set to 'suspendido'; other
 * statuses (retirado, aplazado, desertor…) are left untouched.
 *
 * @param int $userid
 * @param int $studentstatus_fieldid  Field ID of the `studentstatus` custom profile field.
 * @return void
 */
function absd_mark_user_active(int $userid, int $studentstatus_fieldid): void {
    global $DB;

    // 1. Unsuspend Moodle account.
    $DB->set_field('user', 'suspended', 0, ['id' => $userid]);

    // 2. Custom profile field studentstatus → 'Activo'.
    if ($studentstatus_fieldid > 0) {
        $existing = $DB->get_record('user_info_data', ['userid' => $userid, 'fieldid' => $studentstatus_fieldid]);
        if ($existing) {
            $DB->set_field('user_info_data', 'data', 'Activo', ['id' => $existing->id]);
        } else {
            $rec = new stdClass();
            $rec->userid     = $userid;
            $rec->fieldid    = $studentstatus_fieldid;
            $rec->data       = 'Activo';
            $rec->dataformat = 0;
            $DB->insert_record('user_info_data', $rec);
        }
    }

    // 3. Restore only the entries the absence-check suspended.
    $DB->execute(
        "UPDATE {local_learning_users} SET status = 'activo' WHERE userid = :uid AND status = 'suspendido'",
        ['uid' => $userid]
    );
}

/**
 * Check if a user has an absence exemption (either global or for a specific class).
 *
 * @param int $userid
 * @param int $classid  Pass 0 to check only global exemption.
 * @return bool
 */
function absd_is_user_exempt(int $userid, int $classid = 0): bool {
    $val = get_config('local_grupomakro_core', 'absence_exempt_' . $userid);
    if ($val === false || $val === '') {
        return false;
    }
    // 'all' = global exemption.
    if (trim($val) === 'all') {
        return true;
    }
    // JSON array of class ids.
    $classes = json_decode($val, true);
    if (!is_array($classes)) {
        return false;
    }
    if (in_array('all', $classes, true)) {
        return true;
    }
    if ($classid > 0 && in_array($classid, $classes, false)) {
        return true;
    }
    return false;
}

/**
 * Toggle absence exemption for a user (optionally scoped to a class).
 * If classid = 0 toggles the global ('all') exemption.
 *
 * @param int $userid
 * @param int $classid  0 = global
 * @return bool  New exemption state.
 */
function absd_toggle_user_exempt(int $userid, int $classid = 0): bool {
    $val = get_config('local_grupomakro_core', 'absence_exempt_' . $userid);
    $classes = [];
    if ($val !== false && $val !== '') {
        if (trim($val) === 'all') {
            $classes = ['all'];
        } else {
            $decoded = json_decode($val, true);
            if (is_array($decoded)) {
                $classes = $decoded;
            }
        }
    }

    $key = $classid > 0 ? $classid : 'all';
    $pos = array_search($key, $classes, false);
    if ($pos !== false) {
        // Remove exemption.
        array_splice($classes, $pos, 1);
        $exempt = false;
    } else {
        // Add exemption.
        $classes[] = $key;
        $exempt = true;
    }

    if (empty($classes)) {
        unset_config('absence_exempt_' . $userid, 'local_grupomakro_core');
    } else {
        set_config('absence_exempt_' . $userid, json_encode(array_values($classes)), 'local_grupomakro_core');
    }

    return $exempt;
}

/**
 * Run the full absence-inactivation check across all active classes.
 * Returns a summary array.
 *
 * @return array{processed:int, marked_inactive:int, skipped_exempt:int, errors:string[]}
 */
function absd_run_absence_inactivation_check(): array {
    global $DB;

    $summary = [
        'processed'         => 0,
        'marked_inactive'   => 0,
        'skipped_exempt'    => 0,
        'skipped_financial' => 0,
        'reactivated'       => 0,
        'errors'            => [],
    ];
    $reactivated_uids = []; // track cross-class to avoid double-counting
    $nowts = time();

    // Studentstatus field id.
    $studentstatus_fieldid = (int)($DB->get_field('user_info_field', 'id', ['shortname' => 'studentstatus']) ?: 0);

    // All active classes.
    $classes = $DB->get_records_sql(
        "SELECT id, courseid, corecourseid, groupid, attendancemoduleid, initdate, enddate
           FROM {gmk_class}
          WHERE approved = 1 AND closed = 0 AND enddate > :now",
        ['now' => $nowts]
    );

    foreach ($classes as $class) {
        $cid = (int)$class->id;
        try {
            $pastsessionids  = absd_get_class_past_session_ids($class, $nowts);
            $takensessionids = absd_get_taken_session_ids($pastsessionids);

            if (empty($takensessionids)) {
                continue;
            }

            $enrolleduserids = absd_get_class_enrolled_userids($cid);
            if (empty($enrolleduserids)) {
                continue;
            }

            $studentabs = absd_get_student_absences($takensessionids, $enrolleduserids);

            // Pre-fetch financial status for all enrolled students in this class.
            $enrolled_array = array_values($enrolleduserids);
            [$_fs_insql, $_fs_params] = $DB->get_in_or_equal($enrolled_array, SQL_PARAMS_NAMED, 'fsuid');
            $_financial_map = [];
            foreach ($DB->get_records_sql(
                "SELECT userid, status FROM {gmk_financial_status} WHERE userid $_fs_insql",
                $_fs_params
            ) as $_fr) {
                $_financial_map[(int)$_fr->userid] = (string)$_fr->status;
            }

            // Pre-fetch which of these students are currently suspended.
            [$_sus_insql, $_sus_params] = $DB->get_in_or_equal($enrolled_array, SQL_PARAMS_NAMED, 'susuid');
            $_suspended_uids = [];
            foreach ($DB->get_records_sql(
                "SELECT id FROM {user} WHERE suspended = 1 AND id $_sus_insql",
                $_sus_params
            ) as $_ur) {
                $_suspended_uids[(int)$_ur->id] = true;
            }

            foreach ($enrolleduserids as $uid) {
                $uid = (int)$uid;
                $fin_status = $_financial_map[$uid] ?? 'none';
                $is_financially_current = ($fin_status === 'al_dia' || $fin_status === 'becado');

                // Reactivate: student is suspended but is now financially current.
                if ($is_financially_current && !empty($_suspended_uids[$uid]) && !isset($reactivated_uids[$uid])) {
                    absd_mark_user_active($uid, $studentstatus_fieldid);
                    $reactivated_uids[$uid] = true;
                    $summary['reactivated']++;
                    // Also remove from suspended map so the inactivation block below
                    // won't try to mark them inactive again in this same pass.
                    unset($_suspended_uids[$uid]);
                }

                $abs = $studentabs[$uid] ?? 0;
                if ($abs <= 2) {
                    continue;
                }
                $summary['processed']++;

                if (absd_is_user_exempt($uid, $cid)) {
                    $summary['skipped_exempt']++;
                    continue;
                }

                // Skip inactivation if the student is financially up to date.
                if ($is_financially_current) {
                    $summary['skipped_financial']++;
                    continue;
                }

                // Verify the user record still exists before writing.
                if (!$DB->record_exists('user', ['id' => $uid, 'deleted' => 0])) {
                    continue;
                }

                absd_mark_user_inactive($uid, $studentstatus_fieldid);
                $summary['marked_inactive']++;
            }
        } catch (Throwable $e) {
            $summary['errors'][] = "Clase {$cid}: " . $e->getMessage();
        }
    }

    return $summary;
}
