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
 * Adds an exemption entry to the per-user absence_exempt config without
 * touching the existing entries. Used by the bulk-exempt migration so we
 * can preserve any class-scoped exemptions the admin may have set
 * manually.
 *
 * @param int $userid
 * @param string|null $reason Optional audit detail.
 * @return bool True when the exemption was newly added.
 */
function absd_mark_user_globally_exempt(int $userid, ?string $reason = null): bool {
    $configname = 'absence_exempt_' . $userid;
    $val = get_config('local_grupomakro_core', $configname);
    $classes = [];
    if ($val !== false && $val !== '') {
        if (trim($val) === 'all') {
            // Already globally exempt.
            return false;
        }
        $decoded = json_decode($val, true);
        if (is_array($decoded)) {
            $classes = $decoded;
        }
    }
    $classes[] = 'all';
    set_config($configname, json_encode(array_values(array_unique($classes))), 'local_grupomakro_core');
    absd_log_history($userid, 0, 0, 0, 'bulk_exempt_legacy', $reason);
    return true;
}

/**
 * Removes all absence_exempt_<userid> config entries. Used at the start of
 * a new academic period to clear the bulk-exempt seed.
 *
 * @return int Number of entries removed.
 */
function absd_clear_all_period_exemptions(): int {
    global $DB;
    $count = 0;
    $rs = $DB->get_recordset_select(
        'config_plugins',
        "plugin = ? AND name LIKE ?",
        ['local_grupomakro_core', 'absence_exempt_%'],
        'id ASC'
    );
    $ids = [];
    foreach ($rs as $r) {
        $ids[] = (int)$r->id;
    }
    $rs->close();
    if (!empty($ids)) {
        [$insql, $inparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'cex');
        $DB->delete_records_select('config_plugins', "id $insql", $inparams);
        $count = count($ids);
    }
    return $count;
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

// ── Staged absence alert system (per-class) ───────────────────────────────────

/**
 * Returns the threshold that defines the global "inactive" outcome.
 * Default 3 absences; can be tuned via plugin config.
 *
 * @return int
 */
function absd_get_block_threshold(): int {
    $val = (int)get_config('local_grupomakro_core', 'absence_block_threshold');
    return $val >= 1 ? $val : 3;
}

/**
 * Whether the staged alert system is enabled. Defaults to off so that the
 * existing 3-absence auto-suspend behaviour remains in effect until the
 * feature flag is flipped on.
 *
 * @return bool
 */
function absd_is_staged_alerts_enabled(): bool {
    return (bool)get_config('local_grupomakro_core', 'enable_absence_alerts');
}

/**
 * Whether the per-class block action is enabled. This is a sub-setting of
 * enable_absence_alerts: when alerts are off, blocking is also off. The
 * soft-launch flow keeps this off so a mid-period deploy never blocks
 * students who already had 3+ absences before the rollout.
 *
 * @return bool
 */
function absd_is_blocking_enabled(): bool {
    if (!absd_is_staged_alerts_enabled()) {
        return false;
    }
    return (bool)get_config('local_grupomakro_core', 'enable_absence_blocking');
}

/**
 * Append a row to the absence history audit log.
 *
 * @param int $userid
 * @param int $classid
 * @param int $countafter
 * @param int $levelafter
 * @param string $action
 * @param string|null $details
 * @return void
 */
function absd_log_history(int $userid, int $classid, int $countafter, int $levelafter, string $action, ?string $details = null): void {
    global $DB;
    $rec = new stdClass();
    $rec->userid      = $userid;
    $rec->classid     = $classid;
    $rec->sessionid   = 0;
    $rec->count_after = $countafter;
    $rec->level_after = $levelafter;
    $rec->action      = $action;
    $rec->details     = $details;
    $rec->timecreated = time();
    $DB->insert_record('gmk_class_absence_history', $rec);
}

/**
 * Resolve the current (or next) alert level for a given absence count.
 *  0 = none, 1 = info shown, 2 = warning popup, 3 = blocked.
 *
 * @param int $absences
 * @return int
 */
function absd_level_for_count(int $absences): int {
    if ($absences >= absd_get_block_threshold()) {
        return 3;
    }
    if ($absences === 2) {
        return 2;
    }
    if ($absences === 1) {
        return 1;
    }
    return 0;
}

/**
 * Recompute the absence state for a single (class, user) pair. Updates
 * gmk_class_absence_state with the latest count, level, last_session_id
 * and last_calculated. Returns the transitions that occurred so callers
 * can dispatch notifications / apply blocking.
 *
 * The function NEVER blocks the class — that responsibility belongs to
 * absd_apply_class_block so callers (cron, observer, web service) can
 * decide whether to honour the new state.
 *
 * @param stdClass $class  Object with fields id, courseid, corecourseid, groupid, attendancemoduleid, initdate, enddate.
 * @param int $userid
 * @return array{
 *   previous_level:int,
 *   current_level:int,
 *   previous_count:int,
 *   current_count:int,
 *   transitions:array<int,string>,
 *   was_blocked:bool
 * }
 */
function absd_recompute_user_class_state(stdClass $class, int $userid): array {
    global $DB;
    $nowts = time();

    $pastsessionids  = absd_get_class_past_session_ids($class, $nowts);
    $takensessionids = absd_get_taken_session_ids($pastsessionids);

    $count = 0;
    $lastsessionid = 0;
    if (!empty($takensessionids)) {
        $absMap = absd_get_student_absences($takensessionids, [$userid]);
        $count = (int)($absMap[$userid] ?? 0);
        $lastsessionid = !empty($takensessionids) ? (int)end($takensessionids) : 0;
    }

    $currentlevel = absd_level_for_count($count);

    $existing = $DB->get_record('gmk_class_absence_state', [
        'userid'  => $userid,
        'classid' => (int)$class->id,
    ]);

    $previouslevel = $existing ? (int)$existing->alert_level : 0;
    $previouscount = $existing ? (int)$existing->absence_count : 0;
    $wasblocked    = $existing && !empty($existing->blocked_at) && empty($existing->unblocked_at);

    $transitions = [];
    if ($previouslevel !== $currentlevel) {
        if ($currentlevel >= $previouslevel + 1) {
            // Escalations.
            for ($l = $previouslevel + 1; $l <= $currentlevel; $l++) {
                if ($l === 1) { $transitions[] = 'info'; }
                if ($l === 2) { $transitions[] = 'warning'; }
                if ($l === 3) { $transitions[] = 'block'; }
            }
        } else {
            // De-escalation (e.g. attendance was corrected). Clear the block if
            // count dropped back below the threshold.
            if ($previouslevel === 3 && $currentlevel < 3) {
                $transitions[] = 'unblock';
            }
        }
    }

    $rec = new stdClass();
    $rec->userid             = $userid;
    $rec->classid            = (int)$class->id;
    $rec->courseid           = (int)($class->courseid ?: ($class->corecourseid ?? 0));
    $rec->absence_count      = $count;
    $rec->last_session_id    = $lastsessionid;
    $rec->last_calculated    = $nowts;
    $rec->alert_level        = $currentlevel;
    $rec->usermodified       = $userid;
    $rec->timemodified       = $nowts;

    if ($existing) {
        $rec->id = $existing->id;
        // Do NOT overwrite dismissal timestamps on a recompute — once dismissed,
        // the user shouldn't see the same alert pop up again until the count
        // goes back down and back up. The transitions array still reflects
        // level changes for notification purposes.
        $rec->info_dismissed_at    = $existing->info_dismissed_at;
        $rec->warning_dismissed_at = $existing->warning_dismissed_at;

        // If the level has dropped back below 3 we unblock here, mirroring
        // absd_apply_class_block/unblock_class_block semantics.
        if ($currentlevel < 3 && $existing->alert_level === 3) {
            $rec->blocked_at   = $existing->blocked_at;
            $rec->unblocked_at = $nowts;
            $rec->block_reason = '';
        }
        $DB->update_record('gmk_class_absence_state', $rec);
    } else {
        $rec->info_dismissed_at    = 0;
        $rec->warning_dismissed_at = 0;
        $rec->blocked_at           = 0;
        $rec->unblocked_at         = 0;
        $rec->block_reason         = '';
        $rec->timecreated          = $nowts;
        $DB->insert_record('gmk_class_absence_state', $rec);
    }

    return [
        'previous_level' => $previouslevel,
        'current_level'  => $currentlevel,
        'previous_count' => $previouscount,
        'current_count'  => $count,
        'transitions'    => $transitions,
        'was_blocked'    => $wasblocked,
    ];
}

/**
 * Apply a class-level access block. Marks the gmk_class_absence_state row,
 * sets the gmk_course_progre flag, and writes a history entry.
 *
 * When absd_is_blocking_enabled() is false (soft-launch / alerts-only mode)
 * the function is a no-op: the per-class block is not applied and no rows
 * are written. The function still returns so the cron pipeline is not
 * interrupted, and the recompute will keep emitting alert transitions
 * (info / warning) that drive the UI without affecting access.
 *
 * @param int $userid
 * @param int $classid
 * @param string $reason
 * @return void
 */
function absd_apply_class_block(int $userid, int $classid, string $reason = 'attendance_threshold'): void {
    global $DB;
    $nowts = time();

    if (!absd_is_blocking_enabled()) {
        // Soft-launch mode: emit a history row so the admin can see in the
        // audit log that the recompute would have applied a block.
        absd_log_history(
            $userid,
            $classid,
            0,
            3,
            'block_skipped_blocking_off',
            'absd_apply_class_block called with enable_absence_blocking=0'
        );
        return;
    }

    $state = $DB->get_record('gmk_class_absence_state', ['userid' => $userid, 'classid' => $classid]);
    if ($state) {
        $state->alert_level   = 3;
        $state->blocked_at    = $nowts;
        $state->unblocked_at  = 0;
        $state->block_reason  = $reason;
        $state->timemodified  = $nowts;
        $DB->update_record('gmk_class_absence_state', $state);
    }

    $DB->set_field('gmk_course_progre', 'blocked_by_absence', 1, ['userid' => $userid, 'classid' => $classid]);
    $DB->set_field('gmk_course_progre', 'blocked_by_absence_at', $nowts, ['userid' => $userid, 'classid' => $classid]);

    absd_log_history($userid, $classid, (int)($state->absence_count ?? 0), 3, 'block', $reason);
}

/**
 * Lift a class-level access block. Mirror of absd_apply_class_block.
 *
 * @param int $userid
 * @param int $classid
 * @param string $reason
 * @return void
 */
function absd_unblock_class_block(int $userid, int $classid, string $reason = 'manual_unblock'): void {
    global $DB;
    $nowts = time();

    $state = $DB->get_record('gmk_class_absence_state', ['userid' => $userid, 'classid' => $classid]);
    if ($state) {
        $state->unblocked_at = $nowts;
        $state->block_reason = $reason;
        $state->timemodified = $nowts;
        $DB->update_record('gmk_class_absence_state', $state);
    }

    $DB->set_field('gmk_course_progre', 'blocked_by_absence', 0, ['userid' => $userid, 'classid' => $classid]);
    $DB->set_field('gmk_course_progre', 'blocked_by_absence_at', 0, ['userid' => $userid, 'classid' => $classid]);

    absd_log_history($userid, $classid, (int)($state->absence_count ?? 0), (int)($state->alert_level ?? 0), 'unblock', $reason);
}

/**
 * Mark an alert as dismissed by the student (info or warning). Stores the
 * timestamp so we don't keep re-prompting them.
 *
 * @param int $userid
 * @param int $classid
 * @param int $level  1 = info, 2 = warning
 * @return void
 */
function absd_dismiss_user_alert(int $userid, int $classid, int $level): void {
    global $DB;
    if (!in_array($level, [1, 2], true)) {
        return;
    }
    $state = $DB->get_record('gmk_class_absence_state', ['userid' => $userid, 'classid' => $classid]);
    if (!$state) {
        return;
    }
    $nowts = time();
    $state->timemodified = $nowts;
    if ($level === 1) {
        $state->info_dismissed_at = $nowts;
    } else {
        $state->warning_dismissed_at = $nowts;
    }
    $DB->update_record('gmk_class_absence_state', $state);
    absd_log_history(
        $userid,
        $classid,
        (int)$state->absence_count,
        (int)$state->alert_level,
        $level === 1 ? 'dismiss_info' : 'dismiss_warning'
    );
}

/**
 * Send the appropriate Moodle messages for the given transition set.
 * Best-effort: failures are swallowed so they don't break the cron.
 *
 * Uses message_send() (the standard Moodle messaging API) so the configured
 * processors (popup, email, airnotifier) handle delivery according to each
 * user's notification preferences. Three message providers are declared in
 * db/messages.php so each transition type is independently togglable by
 * the user in their messaging preferences.
 *
 * @param int $userid
 * @param int $classid
 * @param string[] $transitions
 * @return void
 */
function absd_dispatch_alert_notifications(int $userid, int $classid, array $transitions): void {
    if (empty($transitions)) {
        return;
    }
    try {
        $user = core_user::get_user($userid);
        if (!$user || $user->deleted) {
            return;
        }
        $coursename = absd_get_class_display_name($classid);

        // Map transition token -> message provider / strings.
        $providermap = [
            'info'    => ['absence_info_alert',    'absence_info_subject',    'absence_info_body'],
            'warning' => ['absence_warning_alert', 'absence_warning_subject', 'absence_warning_body'],
            'block'   => ['absence_block_alert',   'absence_block_subject',   'absence_block_body'],
        ];

        foreach ($transitions as $t) {
            if (!isset($providermap[$t])) {
                continue;
            }
            [$provider, $subjectkey, $bodykey] = $providermap[$t];

            // Subject contains the course name (Moodle templating).
            $subject = get_string($subjectkey, 'local_grupomakro_core', $coursename);
            // Body uses the same templating key for consistency with the LXP.
            $bodyplain = get_string($bodykey, 'local_grupomakro_core', (object)['coursename' => $coursename]);
            $bodyhtml  = '<p>' . s($bodyplain) . '</p>';

            $message = new \core\message\message();
            $message->component = 'local_grupomakro_core';
            $message->name = $provider;
            $message->userfrom = core_user::get_noreply_user();
            $message->userto = $user;
            $message->subject = $subject;
            $message->fullmessage = $bodyplain;
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = $bodyhtml;
            $message->smallmessage = $subject;
            $message->notification = 1; // Force-popup + email per user preferences.
            $message->contexturl = (new moodle_url('/'))->out(false);
            $message->contexturlname = get_string('course');

            message_send($message);
        }
    } catch (Throwable $e) {
        mtrace('absence notification dispatch failed: ' . $e->getMessage());
    }
}

/**
 * Returns the human-readable name of a class for messaging purposes.
 *
 * @param int $classid
 * @return string
 */
function absd_get_class_display_name(int $classid): string {
    global $DB;
    $record = $DB->get_record_sql(
        "SELECT c.fullname
           FROM {gmk_class} gc
           JOIN {course}  c ON c.id = COALESCE(gc.courseid, gc.corecourseid)
          WHERE gc.id = :id",
        ['id' => $classid],
        IGNORE_MISSING
    );
    if ($record && !empty($record->fullname)) {
        return (string)$record->fullname;
    }
    $class = $DB->get_record('gmk_class', ['id' => $classid], 'name', IGNORE_MISSING);
    return $class ? (string)$class->name : ('class#' . $classid);
}

/**
 * Decide whether the student has any "cursando" (status 1/2/3) class that
 * is NOT blocked by absence. If none, the user is globally inactive.
 *
 * @param int $userid
 * @return array{has_open_class:bool, open_count:int, blocked_count:int}
 */
function absd_check_global_inactivity(int $userid): array {
    global $DB;
    $row = $DB->get_record_sql(
        "SELECT
            SUM(CASE WHEN COALESCE(gcp.blocked_by_absence, 0) = 0 THEN 1 ELSE 0 END) AS open_count,
            SUM(CASE WHEN COALESCE(gcp.blocked_by_absence, 0) = 1 THEN 1 ELSE 0 END) AS blocked_count,
            COUNT(1) AS total
           FROM {gmk_course_progre} gcp
           JOIN {gmk_class} gc ON gc.id = gcp.classid
          WHERE gcp.userid = :uid
            AND gcp.status IN (1, 2, 3)
            AND gc.approved = 1
            AND gc.closed = 0
            AND gc.enddate > :now",
        ['uid' => $userid, 'now' => time()]
    );

    $open     = (int)($row->open_count ?? 0);
    $blocked  = (int)($row->blocked_count ?? 0);
    $total    = (int)($row->total ?? 0);

    return [
        'has_open_class' => $open > 0,
        'open_count'     => $open,
        'blocked_count'  => $blocked,
        'total'          => $total,
    ];
}

/**
 * Build the absence summary payload returned by the web service to the
 * student LXP. Includes per-class state, alert flags and the global
 * inactivity flag derived from absd_check_global_inactivity.
 *
 * @param int $userid
 * @return array{classes:array, is_globally_inactive:bool, has_any_blocked_class:bool}
 */
function absd_build_absence_summary(int $userid): array {
    global $DB;

    $sql = "SELECT s.id, s.classid, s.courseid, s.absence_count, s.alert_level,
                   s.info_dismissed_at, s.warning_dismissed_at,
                   s.blocked_at, s.unblocked_at, s.block_reason, s.last_calculated,
                   COALESCE(c.fullname, gc.name) AS coursename,
                   gc.courseid AS classcourseid,
                   gc.corecourseid AS classcorecourseid
              FROM {gmk_class_absence_state} s
         LEFT JOIN {gmk_class} gc ON gc.id = s.classid
         LEFT JOIN {course}    c  ON c.id  = COALESCE(gc.courseid, gc.corecourseid)
             WHERE s.userid = :uid
          ORDER BY s.alert_level DESC, COALESCE(c.fullname, gc.name) ASC";

    $rows = $DB->get_records_sql($sql, ['uid' => $userid]);
    $classes = [];
    $anyblocked = false;
    foreach ($rows as $r) {
        $blocked = !empty($r->blocked_at) && empty($r->unblocked_at);
        if ($blocked) {
            $anyblocked = true;
        }
        $classes[] = [
            'classid'           => (int)$r->classid,
            'courseid'          => (int)($r->classcourseid ?: $r->classcorecourseid ?: $r->courseid),
            'coursename'        => (string)($r->coursename ?? ''),
            'absence_count'     => (int)$r->absence_count,
            'alert_level'       => (int)$r->alert_level,
            'info_dismissed'    => !empty($r->info_dismissed_at),
            'warning_dismissed' => !empty($r->warning_dismissed_at),
            'blocked'           => $blocked,
            'block_reason'      => (string)($r->block_reason ?? ''),
            'last_calculated'   => (int)$r->last_calculated,
        ];
    }

    $global = absd_check_global_inactivity($userid);

    return [
        'classes'               => $classes,
        'is_globally_inactive'  => !$global['has_open_class'] && $global['total'] > 0,
        'has_any_blocked_class' => $anyblocked,
        'open_class_count'      => $global['open_count'],
        'blocked_class_count'   => $global['blocked_count'],
        'is_blocking_enabled'   => absd_is_blocking_enabled(),
    ];
}

/**
 * True when the feature flag is enabled AND the per-class block is in
 * effect. Used by access guards. When the blocking sub-flag is off (soft
 * launch) we report false even if the gmk_class_absence_state row says
 * blocked, so the access guard does not redirect.
 *
 * @param int $userid
 * @param int $classid
 * @return bool
 */
function absd_is_class_blocked(int $userid, int $classid): bool {
    global $DB;
    if (!absd_is_staged_alerts_enabled() || !absd_is_blocking_enabled()) {
        return false;
    }
    $row = $DB->get_record('gmk_class_absence_state', [
        'userid' => $userid, 'classid' => $classid,
    ]);
    if (!$row) {
        return false;
    }
    return (int)$row->alert_level === 3 && !empty($row->blocked_at) && empty($row->unblocked_at);
}

/**
 * Returns the absence payload for a single course based on the class(es)
 * that map to that course for the given user. Used by web services that
 * enrich course listings.
 *
 * @param int $userid
 * @param int $courseid
 * @return array{count:int, level:int, blocked:bool, classid:int, info_dismissed:bool, warning_dismissed:bool}|null
 */
function absd_get_course_absence_for_user(int $userid, int $courseid): ?array {
    global $DB;
    $row = $DB->get_record_sql(
        "SELECT s.classid, s.absence_count, s.alert_level, s.blocked_at, s.unblocked_at,
                s.info_dismissed_at, s.warning_dismissed_at
           FROM {gmk_class_absence_state} s
           JOIN {gmk_class} gc ON gc.id = s.classid
          WHERE s.userid = :uid
            AND (gc.courseid = :cid OR gc.corecourseid = :cid2)
       ORDER BY s.alert_level DESC, s.absence_count DESC
          LIMIT 1",
        ['uid' => $userid, 'cid' => $courseid, 'cid2' => $courseid]
    );
    if (!$row) {
        return null;
    }
    $blocked = !empty($row->blocked_at) && empty($row->unblocked_at);
    // Soft-launch guard: don't surface a class as blocked to the LXP when
    // the blocking sub-flag is off. The state row may still have
    // blocked_at set if a previous deploy ran with the flag on; we
    // transparently mask it.
    if (!absd_is_blocking_enabled()) {
        $blocked = false;
    }
    return [
        'count'             => (int)$row->absence_count,
        'level'             => (int)$row->alert_level,
        'blocked'           => $blocked,
        'classid'           => (int)$row->classid,
        'info_dismissed'    => !empty($row->info_dismissed_at),
        'warning_dismissed' => !empty($row->warning_dismissed_at),
    ];
}

/**
 * Run the full absence check across all active classes using the staged
 * logic. Used by the cron job and the one-off migration script.
 *
 * @return array{
 *   classes_processed:int,
 *   users_processed:int,
 *   classes_blocked:int,
 *   users_globally_inactive:int,
 *   errors:string[]
 * }
 */
function absd_run_staged_absence_check(): array {
    global $DB;
    $summary = [
        'classes_processed'      => 0,
        'users_processed'        => 0,
        'classes_blocked'        => 0,
        'users_globally_inactive'=> 0,
        'errors'                 => [],
    ];
    $nowts = time();
    $studentstatus_fieldid = (int)($DB->get_field('user_info_field', 'id', ['shortname' => 'studentstatus']) ?: 0);

    $classes = $DB->get_records_sql(
        "SELECT id, courseid, corecourseid, groupid, attendancemoduleid, initdate, enddate
           FROM {gmk_class}
          WHERE approved = 1 AND closed = 0 AND enddate > :now",
        ['now' => $nowts]
    );

    foreach ($classes as $class) {
        $cid = (int)$class->id;
        try {
            $enrolled = absd_get_class_enrolled_userids($cid);
            if (empty($enrolled)) {
                continue;
            }
            $summary['classes_processed']++;
            foreach ($enrolled as $uid) {
                $uid = (int)$uid;
                $summary['users_processed']++;
                $result = absd_recompute_user_class_state($class, $uid);
                if (in_array('block', $result['transitions'], true)) {
                    $reason = 'attendance_threshold_reached';
                    if (absd_is_user_exempt($uid, $cid)) {
                        $reason .= ' (override-exempt)';
                    } else {
                        absd_apply_class_block($uid, $cid, $reason);
                        $summary['classes_blocked']++;
                    }
                }
                if (!empty($result['transitions'])) {
                    absd_dispatch_alert_notifications($uid, $cid, $result['transitions']);
                }
            }
        } catch (Throwable $e) {
            $summary['errors'][] = "Clase {$cid}: " . $e->getMessage();
        }
    }

    // Global inactivity roll-up: any user with no non-blocked cursando class
    // who is not already inactive gets the 3-field inactivation applied.
    // This is gated on the blocking flag so that during the soft-launch
    // (alerts-only) phase the global suspension is NOT applied.
    $candidates = [];
    if (absd_is_blocking_enabled()) {
        $candidates = $DB->get_records_sql(
            "SELECT DISTINCT gcp.userid
               FROM {gmk_course_progre} gcp
               JOIN {gmk_class} gc ON gc.id = gcp.classid
          LEFT JOIN {user} u ON u.id = gcp.userid
              WHERE gcp.status IN (1, 2, 3)
                AND gc.approved = 1
                AND gc.closed = 0
                AND gc.enddate > :now
                AND u.deleted = 0
                AND u.suspended = 0",
            ['now' => $nowts]
        );
    }

    foreach ($candidates as $c) {
        $uid = (int)$c->userid;
        $global = absd_check_global_inactivity($uid);
        if (!$global['has_open_class'] && $global['total'] > 0) {
            // Verify the user has at least one blocked class to justify the
            // global inactivation (avoids tagging a user with zero classes).
            if ($global['blocked_count'] > 0) {
                absd_mark_user_inactive($uid, $studentstatus_fieldid);
                $summary['users_globally_inactive']++;
                absd_log_history($uid, 0, $global['blocked_count'], 3, 'global_inactive');
            }
        }
    }

    return $summary;
}
