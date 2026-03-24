<?php
/**
 * Dashboard de Inasistencias y Deserciones
 *
 * Muestra, por carrera y jornada, las estadísticas de inasistencias de cada
 * clase activa. Solo considera sesiones de asistencia ya realizadas (passadas).
 *
 * @package    local_grupomakro_core
 * @copyright  2025 Antigravity
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

// ── Inline AJAX ───────────────────────────────────────────────────────────────
if (optional_param('abs_ajax', 0, PARAM_INT)) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        require_sesskey();
        $abs_action = required_param('abs_action', PARAM_ALPHANUMEXT);
        if ($abs_action === 'suspend') {
            $userid = required_param('userid', PARAM_INT);
            $u      = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], 'id, suspended', MUST_EXIST);
            $newval = $u->suspended ? 0 : 1;
            $DB->set_field('user', 'suspended', $newval, ['id' => $userid]);
            echo json_encode(['ok' => true, 'suspended' => (bool)$newval]);
        } elseif ($abs_action === 'academic') {
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/student/update_student_status.php');
            $userid = required_param('userid', PARAM_INT);
            $academic_status = trim((string)optional_param('academic_status', '', PARAM_TEXT));

            // Backward compatibility with older action values.
            if ($academic_status === '') {
                $academic_action = required_param('academic_action', PARAM_ALPHA);
                $academic_status = [
                    'reingreso' => 'activo',
                    'aplazo' => 'aplazado',
                    'retiro' => 'retirado',
                ][$academic_action] ?? 'activo';
            }

            $res = \local_grupomakro_core\external\student\update_student_status::execute(
                $userid,
                'academicstatus',
                strtolower($academic_status)
            );
            echo json_encode($res);
        } elseif ($abs_action === 'userstatus') {
            require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/student/update_student_status.php');
            $userid = required_param('userid', PARAM_INT);
            $user_status = trim((string)required_param('user_status', PARAM_TEXT));
            $res = \local_grupomakro_core\external\student\update_student_status::execute(
                $userid,
                'studentstatus',
                $user_status
            );
            echo json_encode($res);
        } elseif ($abs_action === 'get_students') {
            // Load student details for one class on-demand (avoids embedding large JSON in HTML).
            $classid = required_param('classid', PARAM_INT);
            $class   = $DB->get_record('gmk_class', ['id' => $classid],
                'id, learningplanid, attendancemoduleid, groupid, courseid, corecourseid, initdate, enddate', MUST_EXIST);
            $nowts   = time();
            $pastsessionids = absd_get_class_past_session_ids($class, $nowts);
            $takensessionids = absd_get_taken_session_ids($pastsessionids);
            $past_sessions = count($takensessionids);

            // Enrolled students
            $students_raw = [];
            $rs = $DB->get_recordset_sql(
                "SELECT gcp.userid, u.firstname, u.lastname, u.idnumber, u.email,
                        u.phone1, u.phone2, u.suspended,
                        COALESCE(llu.status, 'activo') AS academic_status
                   FROM {gmk_course_progre} gcp
                   JOIN {user} u ON u.id = gcp.userid AND u.deleted = 0
              LEFT JOIN {local_learning_users} llu ON llu.userid = gcp.userid
                        AND llu.learningplanid = :planid AND llu.userroleid = 5
                  WHERE gcp.classid = :classid AND gcp.status IN (1,2,3)
                  ORDER BY u.lastname, u.firstname",
                ['classid' => $classid, 'planid' => $class->learningplanid]
            );
            foreach ($rs as $row) { $students_raw[(int)$row->userid] = $row; }
            $rs->close();

            if (empty($students_raw)) {
                echo json_encode(['ok' => true, 'students' => [], 'past_sessions' => $past_sessions]);
                exit;
            }
            $userids = array_keys($students_raw);

            // Canonical statuses from the same source used by studenttable.
            $status_by_user = [];
            try {
                require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/student/get_student_info.php');
                $resultsperpage = max(200, count($userids) + 20);
                $ws = \local_grupomakro_core\external\student\get_student_info::execute(
                    1,
                    $resultsperpage,
                    '',
                    '',
                    '',
                    '',
                    $classid,
                    ''
                );

                $wsusers = [];
                if (!empty($ws['dataUsers'])) {
                    $decoded = json_decode((string)$ws['dataUsers'], true);
                    if (is_array($decoded)) {
                        $wsusers = $decoded;
                    }
                }

                foreach ($wsusers as $wsuser) {
                    $uid = (int)($wsuser['userid'] ?? 0);
                    if ($uid <= 0) {
                        continue;
                    }
                    $status_by_user[$uid] = [
                        'user_status' => trim((string)($wsuser['status'] ?? 'Activo')) ?: 'Activo',
                        'academic_status' => strtolower(trim((string)($wsuser['academicstatus'] ?? 'activo'))) ?: 'activo',
                    ];
                }
            } catch (Throwable $e) {
                // Fallback to local values below.
            }

            // Per-student absences from sessions where attendance was actually taken.
            $student_abs = absd_get_student_absences($takensessionids, $userids);

            // Document numbers
            $doc_map_s = [];
            $doc_fid   = (int)($DB->get_field('user_info_field', 'id', ['shortname' => 'documentnumber']) ?: 0);
            if ($doc_fid) {
                [$uinsql, $uinp] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'docu');
                $uinp['docf'] = $doc_fid;
                $rs = $DB->get_recordset_sql(
                    "SELECT userid, data FROM {user_info_data} WHERE fieldid = :docf AND userid $uinsql",
                    $uinp
                );
                foreach ($rs as $dr) {
                    $v = trim((string)$dr->data);
                    if ($v !== '') $doc_map_s[(int)$dr->userid] = $v;
                }
                $rs->close();
            }

            // Custom phone fields
            $pf_ids = [];
            $pf_names = [];
            foreach ($DB->get_records('user_info_field', null, 'id ASC') as $cf) {
                if (absd_is_phone_field($cf)) {
                    $pf_ids[]              = (int)$cf->id;
                    $pf_names[(int)$cf->id] = trim((string)$cf->name) ?: trim((string)$cf->shortname);
                }
            }
            $custom_pm = [];
            if (!empty($pf_ids)) {
                [$finsql, $finp] = $DB->get_in_or_equal($pf_ids, SQL_PARAMS_NAMED, 'phf');
                [$uinsql, $uinp] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'phu');
                $rs = $DB->get_recordset_sql(
                    "SELECT userid, fieldid, data FROM {user_info_data}
                      WHERE fieldid $finsql AND userid $uinsql",
                    array_merge($finp, $uinp)
                );
                foreach ($rs as $cr) {
                    $v = trim((string)$cr->data);
                    if ($v !== '') $custom_pm[(int)$cr->userid][(int)$cr->fieldid] = $v;
                }
                $rs->close();
            }

            // Build result
            $out = [];
            foreach ($students_raw as $uid => $row) {
                $canonical = $status_by_user[$uid] ?? null;
                $academic_status = $canonical['academic_status'] ?? strtolower(trim((string)$row->academic_status));
                if ($academic_status === '') {
                    $academic_status = 'activo';
                }
                $cedula = $doc_map_s[$uid] ?? trim((string)$row->idnumber);
                $phones = [];
                if (($v = trim((string)$row->phone1)) !== '') {
                    $phones[] = ['label' => 'Teléfono', 'value' => $v, 'wa' => absd_phone_for_wa($v)];
                }
                if (($v = trim((string)$row->phone2)) !== '') {
                    $phones[] = ['label' => 'Móvil', 'value' => $v, 'wa' => absd_phone_for_wa($v)];
                }
                foreach ($pf_ids as $fid) {
                    $v = $custom_pm[$uid][$fid] ?? '';
                    if ($v !== '') {
                        $phones[] = ['label' => $pf_names[$fid], 'value' => $v, 'wa' => absd_phone_for_wa($v)];
                    }
                }
                $out[] = [
                    'userid'          => $uid,
                    'name'            => mb_convert_encoding(trim($row->firstname . ' ' . $row->lastname), 'UTF-8', 'UTF-8'),
                    'cedula'          => $cedula ?: '—',
                    'email'           => (string)$row->email,
                    'phones'          => $phones,
                    'absences'        => $student_abs[$uid] ?? 0,
                    'suspended'       => (bool)$row->suspended,
                    'user_status'     => $canonical['user_status'] ?? 'Activo',
                    'academic_status' => $academic_status,
                ];
            }
            usort($out, fn($a, $b) => $b['absences'] <=> $a['absences']);
            echo json_encode(['ok' => true, 'students' => $out, 'past_sessions' => $past_sessions],
                JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
        } else {
            echo json_encode(['ok' => false, 'message' => 'Unknown action']);
        }
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── Page setup ─────────────────────────────────────────────────────────────────
$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/absence_dashboard.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Inasistencias y Deserciones');
$PAGE->set_heading('Inasistencias y Deserciones');
$PAGE->set_pagelayout('admin');

// ── Helpers ─────────────────────────────────────────────────────────────────────

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

/**
 * Returns the canonical date window used to resolve class attendance sessions.
 *
 * @param stdClass $class
 * @param int $nowts
 * @return array{start:int,end:int}
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
 *
 * @param stdClass $class
 * @return int
 */
function absd_resolve_class_attendanceid(stdClass $class): int {
    global $DB;

    // 1) class.attendancemoduleid.
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

    // 2) relation.attendanceid.
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

    // 3) relation.attendancemoduleid -> cm.instance.
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

    // 4) attendance by class course.
    if (!empty($class->courseid)) {
        $attid = (int)$DB->get_field('attendance', 'id', ['course' => (int)$class->courseid], IGNORE_MULTIPLE);
        if ($attid > 0) {
            return $attid;
        }
    }

    // 5) attendance by core/template course.
    if (!empty($class->corecourseid) && (int)$class->corecourseid !== (int)$class->courseid) {
        $attid = (int)$DB->get_field('attendance', 'id', ['course' => (int)$class->corecourseid], IGNORE_MULTIPLE);
        if ($attid > 0) {
            return $attid;
        }
    }

    return 0;
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

    // Strict: attendance + group + date window + past.
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
                'attid' => $attendanceid,
                'groupid' => (int)$class->groupid,
                'start' => $window['start'],
                'end' => $window['end'],
                'nowts' => $nowts,
            ]
        );
        $sessionids = array_values(array_unique(array_merge($sessionids, array_map('intval', $strictids))));
    }

    // Class relation session ids (authoritative for mixed/legacy mappings).
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

    // If we already have sessions from strict/relation paths, use them.
    if (!empty($sessionids)) {
        sort($sessionids);
        return $sessionids;
    }

    // Fallback: attendance + date window (group-aware).
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
                'attid' => $attendanceid,
                'groupid' => (int)$class->groupid,
                'start' => $window['start'],
                'end' => $window['end'],
                'nowts' => $nowts,
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
 * A session is considered taken when:
 * - it has at least one row in attendance_log, or
 * - attendance_sessions.lasttaken > 0 (when available in schema).
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

    // 1) Sessions with any attendance log.
    $withlogs = $DB->get_fieldset_sql(
        "SELECT DISTINCT l.sessionid
           FROM {attendance_log} l
          WHERE l.sessionid $sessinsql",
        $sessparams
    );
    foreach ($withlogs as $sid) {
        $sid = (int)$sid;
        if ($sid > 0) {
            $taken[$sid] = $sid;
        }
    }

    // 2) Sessions explicitly marked as taken.
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
            if ($sid > 0) {
                $taken[$sid] = $sid;
            }
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
 * Absence is computed over sessions where attendance was taken:
 * - present when latest grade > 0
 * - absence when no latest present mark exists for that session/student
 *
 * @param int[] $sessionids
 * @param int[] $userids
 * @return array<int,int> studentid => absences
 */
function absd_get_student_absences(array $sessionids, array $userids): array {
    global $DB;
    if (empty($sessionids) || empty($userids)) {
        return [];
    }

    list($sessinsql, $sessparams) = $DB->get_in_or_equal($sessionids, SQL_PARAMS_NAMED, 'sess');
    list($uinsql, $uinparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'usr');
    $params = array_merge($sessparams, $uinparams);
    $totalsessions = count($sessionids);

    // Count per student only sessions whose latest mark is present.
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

    // For taken sessions, any missing/non-present latest mark is treated as absence.
    $map = [];
    foreach ($userids as $uid) {
        $uid = (int)$uid;
        $present = $presentbyuser[$uid] ?? 0;
        $map[$uid] = max(0, $totalsessions - $present);
    }
    return $map;
}

function absd_house_svg(): string {
    return '<svg viewBox="0 0 64 64" width="36" height="36" fill="none" xmlns="http://www.w3.org/2000/svg">
        <polygon points="32,6 60,30 56,30 56,58 36,58 36,40 28,40 28,58 8,58 8,30 4,30"
            stroke="currentColor" stroke-width="3" fill="currentColor" fill-opacity="0.12"/>
        <polygon points="32,6 60,30 4,30"
            stroke="currentColor" stroke-width="3" fill="currentColor" fill-opacity="0.25"/>
    </svg>';
}

// ── Field IDs ──────────────────────────────────────────────────────────────────
$tc_fieldid  = (int)($DB->get_field('customfield_field', 'id', ['shortname' => 'tc'])            ?: 0);
$doc_fieldid = (int)($DB->get_field('user_info_field',   'id', ['shortname' => 'documentnumber']) ?: 0);

// ── Active classes (same filter as student_population) ─────────────────────────
$now = time();
$selected_shift = trim((string)optional_param('shift', '', PARAM_TEXT));

$tc_join  = $tc_fieldid ? "LEFT JOIN {customfield_data} _cfd ON _cfd.instanceid = gc.corecourseid AND _cfd.fieldid = $tc_fieldid" : '';
$tc_where = $tc_fieldid ? "AND (_cfd.value IS NULL OR _cfd.value <> '1')" : '';
$tc_join2 = $tc_fieldid ? "JOIN {customfield_data} _cfd ON _cfd.instanceid = gc.corecourseid AND _cfd.fieldid = $tc_fieldid AND _cfd.value = '1'" : '';

$class_sel = "SELECT gc.id,
        gc.name           AS classname,
        gc.shift          AS classshift,
        gc.career_label,
        gc.learningplanid,
        gc.corecourseid,
        gc.attendancemoduleid,
        gc.groupid,
        c.fullname        AS coursefullname,
        CONCAT(u.firstname,' ',u.lastname) AS teachername,
        COUNT(DISTINCT gcp.userid) AS student_count
   FROM {gmk_class} gc
   LEFT JOIN {course} c              ON c.id    = gc.corecourseid
   LEFT JOIN {user} u                ON u.id    = gc.instructorid
   LEFT JOIN {gmk_course_progre} gcp ON gcp.classid = gc.id AND gcp.status IN (1,2,3)";

$regular_classes = $DB->get_records_sql(
    "$class_sel $tc_join
      WHERE gc.approved=1 AND gc.closed=0 AND gc.enddate>:now $tc_where
      GROUP BY gc.id,gc.name,gc.shift,gc.career_label,gc.learningplanid,
               gc.corecourseid,gc.attendancemoduleid,gc.groupid,c.fullname,u.firstname,u.lastname
      ORDER BY gc.career_label, gc.shift, c.fullname",
    ['now' => $now]
);

$tc_classes = $tc_fieldid ? $DB->get_records_sql(
    "$class_sel $tc_join2
      WHERE gc.approved=1 AND gc.closed=0 AND gc.enddate>:now
      GROUP BY gc.id,gc.name,gc.shift,gc.career_label,gc.learningplanid,
               gc.corecourseid,gc.attendancemoduleid,gc.groupid,c.fullname,u.firstname,u.lastname
      ORDER BY c.fullname, gc.shift",
    ['now' => $now]
) : [];

// Shift filter options from available classes.
$available_shifts = [];
foreach ([$regular_classes, $tc_classes] as $classgroup) {
    foreach ($classgroup as $cls) {
        $shift = absd_normalize_shift((string)($cls->classshift ?? ''));
        $available_shifts[$shift] = $shift;
    }
}
uksort($available_shifts, function(string $a, string $b): int {
    return absd_shift_sort_key($a) <=> absd_shift_sort_key($b);
});

// Apply selected shift filter.
if ($selected_shift !== '' && !isset($available_shifts[$selected_shift])) {
    $selected_shift = '';
}
if ($selected_shift !== '') {
    $regular_classes = array_filter($regular_classes, function($cls) use ($selected_shift) {
        return absd_normalize_shift((string)($cls->classshift ?? '')) === $selected_shift;
    });
    $tc_classes = array_filter($tc_classes, function($cls) use ($selected_shift) {
        return absd_normalize_shift((string)($cls->classshift ?? '')) === $selected_shift;
    });
}

// ── Schedules ──────────────────────────────────────────────────────────────────
$all_ids = array_values(array_unique(array_merge(array_keys($regular_classes), array_keys($tc_classes))));
$schedules_by_class = [];
if (!empty($all_ids)) {
    [$insql, $inparams] = $DB->get_in_or_equal($all_ids);
    try {
        $rs = $DB->get_recordset_sql(
            "SELECT id, classid, day, start_time, end_time
               FROM {gmk_class_schedules} WHERE classid $insql ORDER BY classid, day, start_time",
            $inparams
        );
        foreach ($rs as $sr) {
            $schedules_by_class[(int)$sr->classid][] = $sr;
        }
        $rs->close();
    } catch (Exception $e) {}
}

// ── Attendance stats (past sessions + absences per class) ──────────────────────
$class_past_sessions  = []; // classid => int
$class_total_absences = []; // classid => int

if (!empty($all_ids)) {
    [$insql, $inparams] = $DB->get_in_or_equal($all_ids, SQL_PARAMS_NAMED, 'cidbase');
    $classbase = $DB->get_records_sql(
        "SELECT id, courseid, corecourseid, groupid, attendancemoduleid, initdate, enddate
           FROM {gmk_class}
          WHERE id $insql",
        $inparams
    );

    foreach ($all_ids as $cid) {
        $base = $classbase[$cid] ?? null;
        if (!$base) {
            continue;
        }

        $pastsessionids = absd_get_class_past_session_ids($base, $now);
        $takensessionids = absd_get_taken_session_ids($pastsessionids);
        $class_past_sessions[$cid] = count($takensessionids);
        if (empty($takensessionids)) {
            continue;
        }

        $enrolleduserids = absd_get_class_enrolled_userids((int)$cid);
        if (empty($enrolleduserids)) {
            continue;
        }

        $studentabs = absd_get_student_absences($takensessionids, $enrolleduserids);
        $class_total_absences[$cid] = array_sum($studentabs);
    }
}

// ── Plan names & career tree ────────────────────────────────────────────────────
$plan_rows = $DB->get_records_sql(
    "SELECT DISTINCT llu.learningplanid AS planid, lp.name AS planname
       FROM {user} u
       JOIN {local_learning_users} llu ON llu.userid = u.id AND llu.userroleid = 5 AND llu.status = 'activo'
       JOIN {local_learning_plans} lp  ON lp.id = llu.learningplanid
      WHERE u.deleted = 0 AND u.suspended = 0 AND u.id > 2
      ORDER BY lp.name"
);
$available_plans = [];
$career_tree    = [];
$planid_to_name = [];
foreach ($plan_rows as $row) {
    $pid = (int)$row->planid;
    $car = trim((string)$row->planname);
    $available_plans[$pid] = $car;
    if (!isset($career_tree[$car])) {
        $career_tree[$car] = ['planid' => $pid, 'planids' => [$pid], 'is_group' => false, 'shifts' => []];
        $planid_to_name[$pid] = $car;
    }
}

foreach ($regular_classes as $cls) {
    $planid  = (int)$cls->learningplanid;
    $shift   = absd_normalize_shift((string)($cls->classshift ?? ''));
    $treeKey = $planid_to_name[$planid] ?? null;
    if (!$treeKey && !empty($cls->career_label)) {
        foreach ($career_tree as $cn => $_) {
            if (stripos($cn, $cls->career_label) !== false || stripos($cls->career_label, $cn) !== false) {
                $treeKey = $cn; break;
            }
        }
    }
    if (!$treeKey) continue;
    if (!isset($career_tree[$treeKey]['shifts'][$shift])) {
        $career_tree[$treeKey]['shifts'][$shift] = ['classes' => []];
    }
    $career_tree[$treeKey]['shifts'][$shift]['classes'][] = $cls;
}

foreach ($career_tree as &$cdata) {
    uksort($cdata['shifts'], fn($a, $b) => absd_shift_sort_key((string)$a) <=> absd_shift_sort_key((string)$b));
}
unset($cdata);

// Compute absence % per class
$class_absence_pct = [];
foreach ($all_ids as $cid) {
    $cls      = $regular_classes[$cid] ?? ($tc_classes[$cid] ?? null);
    $sessions = $class_past_sessions[$cid] ?? 0;
    $enrolled = $cls ? (int)$cls->student_count : 0;
    $absences = $class_total_absences[$cid] ?? 0;
    $expected = $sessions * $enrolled;
    $class_absence_pct[$cid] = $expected > 0 ? round($absences / $expected * 100, 1) : 0.0;
}

// ── Output ─────────────────────────────────────────────────────────────────────
echo $OUTPUT->header();
$sesskey = sesskey();
$ajax_url = (new moodle_url('/local/grupomakro_core/pages/absence_dashboard.php'))->out(false);
?>
<style>
/* ── Layout ─────────────────────────────────────────────────────── */
.absd-page { max-width: 1400px; margin: 0 auto; padding: 16px 20px; font-family: 'Segoe UI', Arial, sans-serif; }

/* ── Career section ──────────────────────────────────────────────── */
.absd-career-section { margin-bottom: 28px; }
.absd-career-title {
    font-size: 12px; font-weight: 800; letter-spacing: 1px; color: #2d3748;
    text-transform: uppercase; margin: 0 0 10px; padding-bottom: 5px;
    border-bottom: 2px solid #e2e8f0;
    display: flex; align-items: center; justify-content: space-between;
}

/* ── House card ──────────────────────────────────────────────────── */
.absd-houses-row { display: flex; flex-direction: column; gap: 12px; }
.absd-house-card {
    background: #fff; border: 1.5px solid #e2e8f0; border-radius: 10px;
    padding: 14px 16px; box-sizing: border-box;
    box-shadow: 0 1px 4px rgba(0,0,0,0.06);
}
.absd-house-header {
    display: flex; align-items: center; gap: 12px; margin-bottom: 12px; flex-wrap: wrap;
}
.absd-house-icon { flex-shrink: 0; color: #475569; }
.absd-house-meta { flex: 1; min-width: 0; }
.absd-house-shift {
    font-size: 13px; font-weight: 800; text-transform: uppercase;
    letter-spacing: 0.5px; color: #1e293b; line-height: 1.2;
}
.absd-house-stats {
    display: flex; align-items: center; gap: 10px; margin-top: 4px; flex-wrap: wrap;
}
.absd-house-pct-badge {
    font-size: 22px; font-weight: 900; line-height: 1; padding: 3px 12px;
    border-radius: 8px; border: 1.5px solid; display: inline-flex; align-items: center; gap: 6px;
}
.absd-house-pct-label {
    font-size: 9px; font-weight: 800; letter-spacing: 1px; text-transform: uppercase;
    border-radius: 3px; padding: 2px 5px; vertical-align: middle;
}

/* ── Class chips ─────────────────────────────────────────────────── */
.absd-classes-inner {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 8px;
}
.absd-class-chip {
    background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;
    padding: 9px 11px; font-size: 11px;
}
.absd-class-chip-name {
    font-weight: 700; color: #1a3a5c; font-size: 11.5px;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.absd-class-chip-teacher { color: #475569; font-size: 10px; margin-top: 2px; }
.absd-class-chip-row {
    display: flex; justify-content: space-between; align-items: center; gap: 6px; margin-top: 6px;
}
.absd-class-chip-sched { color: #374151; font-size: 10px; line-height: 1.35; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

/* ── Absence mini-bar ────────────────────────────────────────────── */
.absd-bar-wrap { margin: 7px 0 0; }
.absd-bar-label { font-size: 9.5px; color: #64748b; display: flex; justify-content: space-between; margin-bottom: 2px; }
.absd-bar-track { background: #e2e8f0; border-radius: 4px; height: 8px; width: 100%; overflow: hidden; }
.absd-bar-fill  { height: 100%; border-radius: 4px; transition: width 0.3s ease; }

/* ── Student count badge ─────────────────────────────────────────── */
.absd-open-modal-btn {
    background: #1a56a4; color: #fff; border: none; border-radius: 5px;
    padding: 3px 9px; font-size: 10px; font-weight: 700; cursor: pointer;
    white-space: nowrap; flex-shrink: 0;
}
.absd-open-modal-btn:hover { background: #144280; }

/* ── No-data chip ─────────────────────────────────────────────────── */
.absd-no-data { color: #94a3b8; font-size: 10px; font-style: italic; margin-top: 5px; }

/* ── TC section ──────────────────────────────────────────────────── */
.absd-tc-section { margin-bottom: 28px; }
.absd-tc-title {
    font-size: 12px; font-weight: 800; letter-spacing: 1px; color: #2d3748;
    text-transform: uppercase; margin: 0 0 10px; padding-bottom: 5px;
    border-bottom: 2px solid #e2e8f0;
}
.absd-tc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px; }
.absd-tc-card { background: #fff; border: 1.5px solid #e2e8f0; border-radius: 10px; overflow: hidden; }
.absd-tc-header {
    background: linear-gradient(135deg,#e3f2fd,#bbdefb); border-bottom: 1.5px solid #90caf9;
    padding: 10px 12px; display: flex; align-items: center; gap: 8px; color: #0d3c6b;
    font-size: 12px; font-weight: 700;
}
.absd-tc-body { padding: 8px; display: flex; flex-direction: column; gap: 6px; }

/* ── Modal ─────────────────────────────────────────────────────────── */
.absd-modal-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,0.45); z-index: 9000;
    align-items: center; justify-content: center;
}
.absd-modal-overlay.absd-modal-open { display: flex; }
.absd-modal {
    background: #fff; border-radius: 12px; width: 96%; max-width: 1100px;
    max-height: 90vh; display: flex; flex-direction: column;
    box-shadow: 0 20px 60px rgba(0,0,0,0.25);
}
.absd-modal-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 20px; border-bottom: 1.5px solid #e2e8f0; flex-shrink: 0;
}
.absd-modal-header h2 { font-size: 14px; font-weight: 800; color: #1e293b; margin: 0; }
.absd-modal-close {
    background: none; border: none; font-size: 20px; cursor: pointer;
    color: #64748b; padding: 2px 6px; border-radius: 4px;
}
.absd-modal-close:hover { background: #f1f5f9; }
.absd-modal-toolbar {
    padding: 10px 20px; border-bottom: 1px solid #f1f5f9; flex-shrink: 0;
    display: flex; gap: 10px; align-items: center;
}
.absd-modal-search {
    flex: 1; border: 1.5px solid #e2e8f0; border-radius: 7px; padding: 7px 12px; font-size: 13px;
}
.absd-modal-search:focus { outline: none; border-color: #93c5fd; }
.absd-modal-body { overflow-y: auto; flex: 1; }
.absd-modal-footer {
    padding: 10px 20px; border-top: 1.5px solid #e2e8f0; flex-shrink: 0;
    display: flex; justify-content: space-between; align-items: center;
    font-size: 12px; color: #64748b;
}

/* ── Student table ─────────────────────────────────────────────────── */
.absd-student-table { width: 100%; border-collapse: collapse; font-size: 11.5px; }
.absd-student-table thead th {
    background: #f8fafc; color: #374151; font-weight: 700; font-size: 10.5px;
    text-transform: uppercase; letter-spacing: 0.5px; padding: 8px 10px; text-align: left;
    position: sticky; top: 0; border-bottom: 1.5px solid #e2e8f0;
}
.absd-student-table tbody tr:nth-child(even) { background: #fafafa; }
.absd-student-table tbody tr:hover { background: #eff6ff; }
.absd-student-table tbody td { padding: 7px 10px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
.absd-badge-abs {
    display: inline-block; background: #fee2e2; color: #991b1b; font-weight: 700;
    border-radius: 4px; padding: 1px 7px; font-size: 10.5px; min-width: 28px; text-align: center;
}
.absd-badge-abs.zero { background: #dcfce7; color: #166534; }
.absd-wa-link {
    display: inline-flex; align-items: center; gap: 3px;
    background: #d1fae5; color: #065f46; border-radius: 4px;
    padding: 2px 7px; font-size: 10px; font-weight: 600; text-decoration: none; margin: 1px 2px;
    white-space: nowrap;
}
.absd-wa-link:hover { background: #a7f3d0; color: #064e3b; }
.absd-status-select {
    border: 1px solid #e2e8f0; border-radius: 5px; padding: 3px 6px;
    font-size: 10.5px; cursor: pointer; background: #f8fafc;
}
.absd-status-select:focus { outline: none; border-color: #93c5fd; }
.absd-suspend-btn {
    border: 1.5px solid; border-radius: 5px; padding: 3px 8px;
    font-size: 10px; font-weight: 600; cursor: pointer; white-space: nowrap;
}
.absd-suspend-btn.active   { background: #fee2e2; color: #991b1b; border-color: #fca5a5; }
.absd-suspend-btn.inactive { background: #dcfce7; color: #166534; border-color: #86efac; }
.absd-empty { color: #94a3b8; font-size: 12px; font-style: italic; padding: 2px 0; }
</style>

<div class="absd-page">

    <!-- Top bar ─────────────────────────────────────────────────────── -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:8px">
        <div>
            <h1 style="margin:0;font-size:20px;font-weight:900;color:#1e293b">Inasistencias y Deserciones</h1>
            <div style="font-size:11px;color:#64748b;margin-top:2px">
                Solo sesiones ya realizadas (pasadas). Porcentaje = inasistencias / (sesiones × estudiantes).
            </div>
        </div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <form method="get" action="" style="display:flex;gap:6px;align-items:center">
                <label for="absd-shift-filter" style="font-size:12px;color:#334155;font-weight:700">Jornada</label>
                <select id="absd-shift-filter" name="shift" class="absd-status-select" onchange="this.form.submit()">
                    <option value="">Todas</option>
                    <?php foreach ($available_shifts as $shiftopt): ?>
                        <option value="<?php echo s($shiftopt); ?>" <?php echo $selected_shift === $shiftopt ? 'selected' : ''; ?>>
                            <?php echo s($shiftopt); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($selected_shift !== ''): ?>
                    <a href="<?php echo (new moodle_url('/local/grupomakro_core/pages/absence_dashboard.php'))->out(false); ?>"
                       style="font-size:11px;color:#1d4ed8;text-decoration:none;font-weight:600">Limpiar</a>
                <?php endif; ?>
            </form>
            <a href="<?php echo (new moodle_url('/local/grupomakro_core/pages/student_population.php'))->out(false); ?>"
               style="background:#475569;color:#fff;border-radius:8px;padding:8px 14px;font-size:12px;font-weight:600;text-decoration:none">
               &#128100;&nbsp; Población estudiantil
            </a>
        </div>
    </div>

    <!-- Legend ──────────────────────────────────────────────────────── -->
    <div style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap">
        <?php foreach ([['OK', '#16a34a', '#f0fdf4', '#86efac', '< 10%'], ['ALERTA', '#f97316', '#fff7ed', '#fdba74', '10 – 40%'], ['CRÍTICO', '#dc2626', '#fef2f2', '#fca5a5', '> 40%']] as [$lbl, $c, $bg, $brd, $range]): ?>
        <span style="display:inline-flex;align-items:center;gap:6px;background:<?php echo $bg; ?>;border:1.5px solid <?php echo $brd; ?>;color:<?php echo $c; ?>;border-radius:6px;padding:4px 12px;font-size:11px;font-weight:700">
            <?php echo $lbl; ?> <span style="font-weight:400;color:#374151"><?php echo $range; ?> inasistencias</span>
        </span>
        <?php endforeach; ?>
    </div>

    <!-- Career sections ──────────────────────────────────────────────── -->
    <?php if (empty($career_tree)): ?>
        <div class="alert alert-warning">No hay estudiantes activos registrados.</div>
    <?php else: foreach ($career_tree as $careerKey => $careerData): if (empty($careerData['shifts'])) continue; ?>

    <div class="absd-career-section">
        <h2 class="absd-career-title">
            <span><?php echo s(strtoupper($careerKey)); ?></span>
        </h2>

        <div class="absd-houses-row">
        <?php foreach ($careerData['shifts'] as $shiftName => $shiftData):
            // Aggregate absence % across this shift
            $sh_total_abs = 0; $sh_total_exp = 0;
            foreach ($shiftData['classes'] as $cls) {
                $cid      = (int)$cls->id;
                $sessions = $class_past_sessions[$cid] ?? 0;
                $enrolled = (int)$cls->student_count;
                $sh_total_abs += $class_total_absences[$cid] ?? 0;
                $sh_total_exp += $sessions * $enrolled;
            }
            $shift_pct    = $sh_total_exp > 0 ? round($sh_total_abs / $sh_total_exp * 100, 1) : -1;
            $pct_color    = $shift_pct >= 0 ? absd_pct_color($shift_pct) : '#64748b';
            $pct_bg       = $shift_pct >= 0 ? absd_pct_bg($shift_pct) : '#f8fafc';
            $pct_border   = $shift_pct >= 0 ? absd_pct_border($shift_pct) : '#e2e8f0';
        ?>
            <div class="absd-house-card">
                <div class="absd-house-header">
                    <div class="absd-house-icon"><?php echo absd_house_svg(); ?></div>
                    <div class="absd-house-meta">
                        <div class="absd-house-shift"><?php echo s($shiftName); ?></div>
                        <div class="absd-house-stats">
                            <?php if ($shift_pct >= 0): ?>
                            <div class="absd-house-pct-badge" style="color:<?php echo $pct_color; ?>;background:<?php echo $pct_bg; ?>;border-color:<?php echo $pct_border; ?>">
                                <?php echo $shift_pct; ?>%
                                <span class="absd-house-pct-label" style="background:<?php echo $pct_color; ?>;color:#fff">
                                    <?php echo absd_pct_badge($shift_pct); ?>
                                </span>
                            </div>
                            <span style="font-size:11px;color:#64748b">
                                <?php echo $sh_total_abs; ?> aus. /
                                <?php echo $sh_total_exp; ?> esperadas
                            </span>
                            <?php else: ?>
                            <span style="font-size:11px;color:#94a3b8;font-style:italic">Sin datos de asistencia</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if (!empty($shiftData['classes'])): ?>
                <div class="absd-classes-inner">
                    <?php foreach ($shiftData['classes'] as $cls):
                        $cid      = (int)$cls->id;
                        $cname    = trim((string)($cls->coursefullname ?: $cls->classname));
                        $schedHtml = absd_format_schedule($schedules_by_class[$cid] ?? []);
                        $sessions = $class_past_sessions[$cid]  ?? 0;
                        $enrolled = (int)$cls->student_count;
                        $absences = $class_total_absences[$cid] ?? 0;
                        $expected = $sessions * $enrolled;
                        $cpct     = $expected > 0 ? round($absences / $expected * 100, 1) : 0.0;
                        $bar_pct  = min(100, $cpct);
                        $c_color  = $sessions > 0 ? absd_pct_color($cpct)  : '#94a3b8';
                        $c_bg     = $sessions > 0 ? absd_pct_bg($cpct)     : '#f8fafc';
                        $c_border = $sessions > 0 ? absd_pct_border($cpct) : '#e2e8f0';
                    ?>
                    <div class="absd-class-chip" style="border-color:<?php echo $c_border; ?>;background:<?php echo $c_bg; ?>">
                        <div class="absd-class-chip-name" title="<?php echo s($cname); ?>"><?php echo s($cname); ?></div>
                        <div class="absd-class-chip-teacher"><?php echo s(trim($cls->teachername)); ?></div>

                        <?php if ($sessions > 0): ?>
                        <div class="absd-bar-wrap">
                            <div class="absd-bar-label">
                                <span><?php echo $sessions; ?> ses. &middot; <?php echo $enrolled; ?> est.</span>
                                <span style="font-weight:700;color:<?php echo $c_color; ?>"><?php echo $cpct; ?>% aus.</span>
                            </div>
                            <div class="absd-bar-track">
                                <div class="absd-bar-fill" style="width:<?php echo $bar_pct; ?>%;background:<?php echo $c_color; ?>"></div>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="absd-no-data">Sin sesiones pasadas</div>
                        <?php endif; ?>

                        <div class="absd-class-chip-row">
                            <div class="absd-class-chip-sched" title="<?php echo $schedHtml; ?>">
                                <?php echo $schedHtml ?: '<span style="color:#94a3b8">Sin horario</span>'; ?>
                            </div>
                            <button class="absd-open-modal-btn"
                                    onclick="absdOpenModal(<?php echo $cid; ?>, <?php echo htmlspecialchars(json_encode(mb_convert_encoding($cname, 'UTF-8', 'UTF-8')) ?: '""'); ?>)">
                                <?php echo $enrolled; ?> est.
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                    <div class="absd-empty">Sin clases activas</div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
    </div>

    <?php endforeach; endif; ?>

    <!-- TRONCO COMÚN ─────────────────────────────────────────────────── -->
    <?php if (!empty($tc_classes)):
        $tc_by_course = [];
        foreach ($tc_classes as $cls) {
            $cname = trim((string)($cls->coursefullname ?: $cls->classname));
            $tc_by_course[$cname][] = $cls;
        }
    ?>
    <div class="absd-tc-section">
        <h2 class="absd-tc-title">Tronco Común</h2>
        <div class="absd-tc-grid">
        <?php foreach ($tc_by_course as $courseName => $groups): ?>
            <div class="absd-tc-card">
                <div class="absd-tc-header">
                    <svg viewBox="0 0 64 64" width="24" height="24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <polygon points="32,6 60,30 56,30 56,58 36,58 36,40 28,40 28,58 8,58 8,30 4,30"
                            stroke="currentColor" stroke-width="3" fill="currentColor" fill-opacity="0.2"/>
                    </svg>
                    <?php echo s($courseName); ?>
                </div>
                <div class="absd-tc-body">
                <?php foreach ($groups as $cls):
                    $cid      = (int)$cls->id;
                    $shift    = absd_normalize_shift((string)($cls->classshift ?? ''));
                    $sessions = $class_past_sessions[$cid] ?? 0;
                    $enrolled = (int)$cls->student_count;
                    $absences = $class_total_absences[$cid] ?? 0;
                    $expected = $sessions * $enrolled;
                    $cpct     = $expected > 0 ? round($absences / $expected * 100, 1) : 0.0;
                    $c_color  = $sessions > 0 ? absd_pct_color($cpct) : '#94a3b8';
                    $c_bg     = $sessions > 0 ? absd_pct_bg($cpct)    : '#f8fafc';
                    $c_border = $sessions > 0 ? absd_pct_border($cpct): '#e2e8f0';
                ?>
                    <div style="border:1px solid <?php echo $c_border; ?>;background:<?php echo $c_bg; ?>;border-radius:6px;padding:7px 9px;font-size:11px">
                        <div style="display:flex;justify-content:space-between;align-items:center;gap:6px">
                            <span style="background:#dbeafe;color:#1e40af;border-radius:3px;padding:1px 5px;font-size:9px;font-weight:700"><?php echo s($shift); ?></span>
                            <span style="color:<?php echo $c_color; ?>;font-weight:700;font-size:11px"><?php echo $sessions > 0 ? $cpct . '%' : '—'; ?></span>
                        </div>
                        <div style="margin-top:4px;color:#475569;font-size:10px"><?php echo s(trim($cls->teachername)); ?></div>
                        <?php if ($sessions > 0): ?>
                        <div class="absd-bar-wrap" style="margin-top:5px">
                            <div class="absd-bar-track"><div class="absd-bar-fill" style="width:<?php echo min(100,$cpct); ?>%;background:<?php echo $c_color; ?>"></div></div>
                        </div>
                        <?php endif; ?>
                        <div style="margin-top:5px">
                            <button class="absd-open-modal-btn" onclick="absdOpenModal(<?php echo $cid; ?>, <?php echo htmlspecialchars(json_encode(mb_convert_encoding($courseName . ' — ' . $shift, 'UTF-8', 'UTF-8')) ?: '""'); ?>)">
                                <?php echo $enrolled; ?> est.
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /absd-page -->

<!-- ── Student modal ─────────────────────────────────────────────────── -->
<div id="absdModal" class="absd-modal-overlay" onclick="if(event.target===this)absdCloseModal()">
    <div class="absd-modal">
        <div class="absd-modal-header">
            <h2 id="absdModalTitle">Estudiantes</h2>
            <button class="absd-modal-close" onclick="absdCloseModal()">&#10005;</button>
        </div>
        <div class="absd-modal-toolbar">
            <input type="text" class="absd-modal-search" id="absdSearch"
                placeholder="Buscar por nombre, cédula o correo..."
                oninput="absdFilterTable()">
            <span id="absdCount" style="font-size:12px;color:#64748b;white-space:nowrap"></span>
        </div>
        <div class="absd-modal-body">
            <table class="absd-student-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Cédula / ID</th>
                        <th>Nombre</th>
                        <th>Teléfonos</th>
                        <th style="text-align:center">Inasistencias</th>
                        <th>Estado usuario</th>
                        <th>Estado académico</th>
                        <th>Cuenta Moodle</th>
                    </tr>
                </thead>
                <tbody id="absdTbody"></tbody>
            </table>
        </div>
        <div class="absd-modal-footer">
            <span>* Inasistencias en sesiones pasadas de esta clase</span>
            <span id="absdFooterCount"></span>
        </div>
    </div>
</div>

<script>
(function() {
    var SESSKEY = <?php echo json_encode($sesskey); ?>;
    var AJAX_URL = <?php echo json_encode($ajax_url); ?>;

    var USER_STATUS_OPTIONS = ['Activo', 'Inactivo'];
    var ACADEMIC_STATUS_OPTIONS = ['activo', 'aplazado', 'retirado', 'suspendido', 'desertor', 'graduado', 'egresado'];
    var ACADEMIC_STATUS_LABELS = {
        activo: 'Activo',
        aplazado: 'Aplazado',
        retirado: 'Retirado',
        suspendido: 'Suspendido',
        desertor: 'Desertor',
        graduado: 'Graduado',
        egresado: 'Egresado'
    };

    var currentStudents = [];
    var filteredStudents = [];

    function esc(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function normalizeAcademic(value) {
        return String(value || 'activo').trim().toLowerCase();
    }

    function optionList(options, selected, labelFn) {
        return options.map(function(opt) {
            var val = String(opt);
            var label = labelFn ? labelFn(val) : val;
            return '<option value="' + esc(val) + '"' + (val === selected ? ' selected' : '') + '>' + esc(label) + '</option>';
        }).join('');
    }

    function renderTable(students) {
        var tbody = document.getElementById('absdTbody');

        if (!students.length) {
            tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:20px;color:#94a3b8;font-style:italic">No hay estudiantes que coincidan</td></tr>';
            document.getElementById('absdFooterCount').textContent = 'Mostrando 0 de ' + currentStudents.length;
            return;
        }

        var html = '';
        students.forEach(function(s, i) {
            var absClass = s.absences === 0 ? 'zero' : '';
            var phones = (s.phones || []).map(function(p) {
                var waHref = p.wa ? 'https://wa.me/' + esc(p.wa) : '';
                return waHref
                    ? '<a href="' + waHref + '" target="_blank" class="absd-wa-link" title="' + esc(p.label) + '">&#128241; ' + esc(p.value) + '</a>'
                    : '<span style="font-size:10px;color:#374151">' + esc(p.label) + ': ' + esc(p.value) + '</span>';
            }).join(' ');

            var userStatus = String(s.user_status || 'Activo').trim() || 'Activo';
            var userOpts = USER_STATUS_OPTIONS.slice();
            if (userOpts.indexOf(userStatus) === -1) {
                userOpts.unshift(userStatus);
            }

            var academicStatus = normalizeAcademic(s.academic_status);
            var academicOpts = ACADEMIC_STATUS_OPTIONS.slice();
            if (academicOpts.indexOf(academicStatus) === -1) {
                academicOpts.unshift(academicStatus);
            }

            var moodleLabel = s.suspended ? '\u2717 Suspendido' : '\u2713 Activo';
            var moodleTitle = s.suspended ? 'Reactivar cuenta Moodle' : 'Suspender cuenta Moodle';
            var moodleClass = s.suspended ? 'active' : 'inactive';

            html += '<tr id="absd-row-' + s.userid + '">' +
                '<td>' + (i + 1) + '</td>' +
                '<td style="font-weight:700;color:#1a56a4;white-space:nowrap">' + esc(s.cedula) + '</td>' +
                '<td><strong>' + esc(s.name) + '</strong><br><span style="color:#94a3b8;font-size:10px">' + esc(s.email) + '</span></td>' +
                '<td>' + (phones || '<span style="color:#94a3b8;font-style:italic">-</span>') + '</td>' +
                '<td style="text-align:center"><span class="absd-badge-abs ' + absClass + '">' + s.absences + '</span></td>' +
                '<td>' +
                    '<select class="absd-status-select" data-uid="' + s.userid + '" onchange="absdUpdateUserStatus(this)">' +
                        optionList(userOpts, userStatus) +
                    '</select>' +
                '</td>' +
                '<td>' +
                    '<select class="absd-status-select" data-uid="' + s.userid + '" onchange="absdUpdateAcademic(this)">' +
                        optionList(academicOpts, academicStatus, function(v) { return ACADEMIC_STATUS_LABELS[v] || v; }) +
                    '</select>' +
                '</td>' +
                '<td><button class="absd-suspend-btn ' + moodleClass + '" title="' + esc(moodleTitle) + '" onclick="absdToggleSuspend(' + s.userid + ', this)">' +
                    moodleLabel + '</button></td>' +
                '</tr>';
        });

        tbody.innerHTML = html;
        document.getElementById('absdFooterCount').textContent =
            'Mostrando ' + students.length + ' de ' + currentStudents.length;
    }

    window.absdOpenModal = function(classId, className) {
        currentStudents = [];
        filteredStudents = [];
        document.getElementById('absdModalTitle').textContent = className + ' - Estudiantes';
        document.getElementById('absdSearch').value = '';
        document.getElementById('absdCount').textContent = 'Cargando...';
        document.getElementById('absdTbody').innerHTML =
            '<tr><td colspan="8" style="text-align:center;padding:24px;color:#64748b">Cargando estudiantes...</td></tr>';
        document.getElementById('absdModal').classList.add('absd-modal-open');

        var params = new URLSearchParams({ abs_ajax: 1, abs_action: 'get_students', classid: classId, sesskey: SESSKEY });
        fetch(AJAX_URL + '?' + params.toString())
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.ok) {
                    throw new Error(data.message || 'Error desconocido');
                }
                currentStudents = data.students || [];
                filteredStudents = currentStudents.slice();
                document.getElementById('absdCount').textContent = currentStudents.length + ' estudiantes';
                renderTable(filteredStudents);
                document.getElementById('absdSearch').focus();
            })
            .catch(function(err) {
                document.getElementById('absdTbody').innerHTML =
                    '<tr><td colspan="8" style="text-align:center;padding:24px;color:#dc2626">Error al cargar: ' + esc(err.message) + '</td></tr>';
                document.getElementById('absdCount').textContent = '';
            });
    };

    window.absdCloseModal = function() {
        document.getElementById('absdModal').classList.remove('absd-modal-open');
    };

    window.absdFilterTable = function() {
        var q = document.getElementById('absdSearch').value.toLowerCase().trim();
        filteredStudents = q
            ? currentStudents.filter(function(s) {
                return String(s.name || '').toLowerCase().includes(q) ||
                    String(s.cedula || '').toLowerCase().includes(q) ||
                    String(s.email || '').toLowerCase().includes(q) ||
                    String(s.user_status || '').toLowerCase().includes(q) ||
                    String(s.academic_status || '').toLowerCase().includes(q) ||
                    (s.phones || []).some(function(p) { return String(p.value || '').toLowerCase().includes(q); });
            })
            : currentStudents.slice();
        renderTable(filteredStudents);
        document.getElementById('absdCount').textContent = currentStudents.length + ' estudiantes';
    };

    window.absdToggleSuspend = function(userid, btn) {
        btn.disabled = true;
        btn.textContent = '...';
        var params = new URLSearchParams({
            abs_ajax: 1,
            abs_action: 'suspend',
            userid: userid,
            sesskey: SESSKEY
        });
        fetch(AJAX_URL + '?' + params.toString(), { method: 'POST' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                btn.disabled = false;
                if (!data.ok) {
                    btn.textContent = '!Error';
                    alert('Error: ' + (data.message || 'No se pudo actualizar'));
                    return;
                }

                var suspended = !!data.suspended;
                btn.className = 'absd-suspend-btn ' + (suspended ? 'active' : 'inactive');
                btn.textContent = suspended ? '\u2717 Suspendido' : '\u2713 Activo';
                btn.title = suspended ? 'Reactivar cuenta Moodle' : 'Suspender cuenta Moodle';

                var s = currentStudents.find(function(x) { return x.userid === userid; });
                if (s) {
                    s.suspended = suspended;
                }
            })
            .catch(function() {
                btn.disabled = false;
                btn.textContent = '!Error';
            });
    };

    window.absdUpdateUserStatus = function(select) {
        var uid = parseInt(select.getAttribute('data-uid'), 10);
        var nextValue = select.value;
        var student = currentStudents.find(function(x) { return x.userid === uid; });
        var prevValue = student ? student.user_status : '';
        select.disabled = true;

        var params = new URLSearchParams({
            abs_ajax: 1,
            abs_action: 'userstatus',
            userid: uid,
            user_status: nextValue,
            sesskey: SESSKEY
        });

        fetch(AJAX_URL + '?' + params.toString(), { method: 'POST' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                select.disabled = false;
                if (data.status !== 'success' && data.ok !== true) {
                    alert('Error: ' + (data.message || 'No se pudo actualizar'));
                    select.value = prevValue || 'Activo';
                    return;
                }
                if (student) {
                    student.user_status = nextValue;
                }
            })
            .catch(function() {
                select.disabled = false;
                select.value = prevValue || 'Activo';
                alert('Error de conexion.');
            });
    };

    window.absdUpdateAcademic = function(select) {
        var uid = parseInt(select.getAttribute('data-uid'), 10);
        var nextValue = normalizeAcademic(select.value);
        var student = currentStudents.find(function(x) { return x.userid === uid; });
        var prevValue = normalizeAcademic(student ? student.academic_status : 'activo');
        select.disabled = true;

        var params = new URLSearchParams({
            abs_ajax: 1,
            abs_action: 'academic',
            userid: uid,
            academic_status: nextValue,
            sesskey: SESSKEY
        });

        fetch(AJAX_URL + '?' + params.toString(), { method: 'POST' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                select.disabled = false;
                if (data.status !== 'success' && data.ok !== true) {
                    alert('Error: ' + (data.message || 'No se pudo actualizar'));
                    select.value = prevValue;
                    return;
                }
                if (student) {
                    student.academic_status = nextValue;
                }
            })
            .catch(function() {
                select.disabled = false;
                select.value = prevValue;
                alert('Error de conexion.');
            });
    };

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            absdCloseModal();
        }
    });
})();
</script>
<?php echo $OUTPUT->footer(); ?>
