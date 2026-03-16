<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/schedule/withdraw_student.php');

admin_externalpage_setup('grupomakro_core_overlap_analytics');
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/overlap_analytics.php'));
$PAGE->set_title('Analitica de solapamientos');
$PAGE->set_heading('Analitica de solapamientos');
$PAGE->set_pagelayout('admin');

function ov_norm($v) {
    $v = trim(core_text::strtolower((string)$v));
    if ($v === '') {
        return '';
    }
    if (class_exists('Normalizer')) {
        $n = @Normalizer::normalize($v, Normalizer::FORM_D);
        if (is_string($n) && $n !== '') {
            $v = preg_replace('/\p{Mn}+/u', '', $n);
        }
    }
    return preg_replace('/\s+/', ' ', $v);
}

function ov_tmin($t) {
    if (!preg_match('/^(\d{1,2}):(\d{2})/', trim((string)$t), $m)) {
        return -1;
    }
    return ((int)$m[1] * 60) + (int)$m[2];
}

function ov_fmin($m) {
    return sprintf('%02d:%02d', (int)floor($m / 60), $m % 60);
}

function ov_day($d) {
    static $days = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miercoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sabado', 7 => 'Domingo'];
    return $days[(int)$d] ?? ('Dia ' . (int)$d);
}

function ov_day_to_int($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return 0;
    }
    if (is_numeric($value)) {
        $day = (int)$value;
        return ($day >= 1 && $day <= 7) ? $day : 0;
    }
    $n = ov_norm($value);
    $map = [
        'lunes' => 1, 'lun' => 1,
        'martes' => 2, 'mar' => 2,
        'miercoles' => 3, 'mier' => 3, 'mie' => 3,
        'jueves' => 4, 'jue' => 4,
        'viernes' => 5, 'vie' => 5,
        'sabado' => 6, 'sab' => 6,
        'domingo' => 7, 'dom' => 7
    ];
    if (isset($map[$n])) {
        return (int)$map[$n];
    }
    foreach ($map as $k => $v) {
        if (strpos($n, $k) === 0) {
            return (int)$v;
        }
    }
    return 0;
}

function ov_get_schedule_columns() {
    global $DB;
    $cols = $DB->get_columns('gmk_class_schedules');
    if (empty($cols)) {
        return [null, null, null, null, null];
    }
    $keys = array_map('strtolower', array_keys($cols));
    $find = function(array $candidates) use ($keys) {
        foreach ($candidates as $candidate) {
            $lc = strtolower($candidate);
            if (in_array($lc, $keys, true)) {
                return $candidate;
            }
        }
        return null;
    };
    $daycol = $find(['day', 'weekday']);
    $startcol = $find(['start_time', 'starttime', 'inittime', 'start']);
    $endcol = $find(['end_time', 'endtime', 'end']);
    $assignedcol = $find(['assigned_dates', 'assigneddates']);
    $excludedcol = $find(['excluded_dates', 'excludeddates']);
    return [$daycol, $startcol, $endcol, $assignedcol, $excludedcol];
}

function ov_parse_date_json($value) {
    if ($value === null || $value === '') {
        return [];
    }
    if (is_array($value)) {
        $arr = $value;
    } else {
        $arr = json_decode((string)$value, true);
        if (!is_array($arr)) {
            return [];
        }
    }
    $out = [];
    foreach ($arr as $d) {
        $d = trim((string)$d);
        if ($d === '') {
            continue;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            $out[$d] = $d;
        }
    }
    return array_values($out);
}

function ov_effective_assigned_dates(array $session, $class) {
    $assigned = $session['assigned'] ?? [];
    if (empty($assigned)) {
        return [];
    }
    $excluded = array_flip($session['excluded'] ?? []);
    $start = (int)($class->initdate ?? 0);
    $end = (int)($class->enddate ?? 0);
    $out = [];
    foreach ($assigned as $d) {
        if (isset($excluded[$d])) {
            continue;
        }
        $ts = strtotime($d . ' 00:00:00');
        if ($ts === false) {
            continue;
        }
        if ($start > 0 && $ts < $start) {
            continue;
        }
        if ($end > 0 && $ts > $end) {
            continue;
        }
        $out[$d] = $d;
    }
    return array_values($out);
}

function ov_schedule_date_overlap(array $sa, array $sb, $classa, $classb) {
    $da = ov_effective_assigned_dates($sa, $classa);
    $db = ov_effective_assigned_dates($sb, $classb);
    if (empty($da) && empty($db)) {
        return true;
    }
    if (!empty($da) && !empty($db)) {
        return (count(array_intersect($da, $db)) > 0);
    }
    if (!empty($da)) {
        $bstart = (int)($classb->initdate ?? 0);
        $bend = (int)($classb->enddate ?? 0);
        foreach ($da as $d) {
            $ts = strtotime($d . ' 00:00:00');
            if ($ts === false) {
                continue;
            }
            if (($bstart <= 0 || $ts >= $bstart) && ($bend <= 0 || $ts <= $bend)) {
                return true;
            }
        }
        return false;
    }
    if (!empty($db)) {
        $astart = (int)($classa->initdate ?? 0);
        $aend = (int)($classa->enddate ?? 0);
        foreach ($db as $d) {
            $ts = strtotime($d . ' 00:00:00');
            if ($ts === false) {
                continue;
            }
            if (($astart <= 0 || $ts >= $astart) && ($aend <= 0 || $ts <= $aend)) {
                return true;
            }
        }
        return false;
    }
    return true;
}

function ov_load_attendance_fallback_sessions(array $classes) {
    global $DB;

    $out = [];
    if (empty($classes)) {
        return $out;
    }

    $classids = array_values(array_map('intval', array_keys($classes)));
    list($cinsql, $cparams) = $DB->get_in_or_equal($classids, SQL_PARAMS_NAMED, 'afc');
    $relrows = $DB->get_records_sql(
        "SELECT id, classid, attendanceid, attendancesessionid, attendancemoduleid
           FROM {gmk_bbb_attendance_relation}
          WHERE classid $cinsql",
        $cparams
    );

    $sessiontoclass = [];
    $attendancebyclass = [];
    $cmidsbyclass = [];
    foreach ($relrows as $rel) {
        $cid = (int)$rel->classid;
        if (!isset($classes[$cid])) {
            continue;
        }
        $sessid = (int)($rel->attendancesessionid ?? 0);
        if ($sessid > 0) {
            if (!isset($sessiontoclass[$sessid])) {
                $sessiontoclass[$sessid] = [];
            }
            $sessiontoclass[$sessid][$cid] = $cid;
        }
        $attid = (int)($rel->attendanceid ?? 0);
        if ($attid > 0) {
            if (!isset($attendancebyclass[$cid])) {
                $attendancebyclass[$cid] = [];
            }
            $attendancebyclass[$cid][$attid] = $attid;
        }
        $attcmid = (int)($rel->attendancemoduleid ?? 0);
        if ($attcmid > 0) {
            if (!isset($cmidsbyclass[$cid])) {
                $cmidsbyclass[$cid] = [];
            }
            $cmidsbyclass[$cid][$attcmid] = $attcmid;
        }
    }

    foreach ($classes as $cid => $class) {
        $attcmid = (int)($class->attendancemoduleid ?? 0);
        if ($attcmid > 0) {
            if (!isset($cmidsbyclass[$cid])) {
                $cmidsbyclass[$cid] = [];
            }
            $cmidsbyclass[$cid][$attcmid] = $attcmid;
        }
    }

    $allcmids = [];
    foreach ($cmidsbyclass as $cid => $set) {
        foreach ($set as $cmid) {
            $allcmids[$cmid] = (int)$cmid;
        }
    }
    if (!empty($allcmids)) {
        list($mins, $mpar) = $DB->get_in_or_equal(array_values($allcmids), SQL_PARAMS_NAMED, 'afm');
        $cmrows = $DB->get_records_sql(
            "SELECT cm.id, cm.instance, m.name AS modulename
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.id $mins",
            $mpar
        );
        $attbycm = [];
        foreach ($cmrows as $cmr) {
            if ((string)$cmr->modulename !== 'attendance') {
                continue;
            }
            $attbycm[(int)$cmr->id] = (int)$cmr->instance;
        }
        foreach ($cmidsbyclass as $cid => $set) {
            foreach ($set as $cmid) {
                $attid = (int)($attbycm[(int)$cmid] ?? 0);
                if ($attid <= 0) {
                    continue;
                }
                if (!isset($attendancebyclass[$cid])) {
                    $attendancebyclass[$cid] = [];
                }
                $attendancebyclass[$cid][$attid] = $attid;
            }
        }
    }

    $dedup = [];
    $addsession = function($cid, $sess) use (&$out, &$dedup, $classes) {
        if (!isset($classes[$cid])) {
            return;
        }
        $class = $classes[$cid];
        $ts = (int)($sess->sessdate ?? 0);
        if ($ts <= 0) {
            return;
        }
        $day = (int)date('N', $ts);
        if ($day < 1 || $day > 7) {
            return;
        }
        $start = ((int)date('G', $ts) * 60) + (int)date('i', $ts);
        $duration = (int)($sess->duration ?? 0);
        if ($duration <= 0) {
            $duration = (int)($class->classduration ?? 0);
        }
        if ($duration <= 0) {
            $duration = 3600;
        }
        $end = $start + max(1, (int)ceil($duration / 60));
        if ($end <= $start) {
            return;
        }
        $date = date('Y-m-d', $ts);
        $key = $date . '|' . $start . '|' . $end;
        if (isset($dedup[$cid][$key])) {
            return;
        }
        $dedup[$cid][$key] = true;
        if (!isset($out[$cid])) {
            $out[$cid] = [];
        }
        $out[$cid][] = [
            'day' => $day,
            'start' => $start,
            'end' => $end,
            'assigned' => [$date],
            'excluded' => []
        ];
    };

    if (!empty($sessiontoclass)) {
        $sessionids = array_values(array_map('intval', array_keys($sessiontoclass)));
        list($sins, $spar) = $DB->get_in_or_equal($sessionids, SQL_PARAMS_NAMED, 'afs');
        $sessrows = $DB->get_records_sql(
            "SELECT id, attendanceid, groupid, sessdate, duration
               FROM {attendance_sessions}
              WHERE id $sins",
            $spar
        );
        foreach ($sessrows as $sess) {
            $sid = (int)$sess->id;
            foreach (array_values($sessiontoclass[$sid] ?? []) as $cid) {
                $classgroup = (int)($classes[$cid]->groupid ?? 0);
                $sessiongroup = (int)($sess->groupid ?? 0);
                if ($classgroup > 0 && $sessiongroup > 0 && $classgroup !== $sessiongroup) {
                    continue;
                }
                $addsession((int)$cid, $sess);
            }
        }
    }

    $atttoclass = [];
    foreach ($attendancebyclass as $cid => $set) {
        foreach ($set as $attid) {
            if (!isset($atttoclass[$attid])) {
                $atttoclass[$attid] = [];
            }
            $atttoclass[$attid][$cid] = $cid;
        }
    }

    if (!empty($atttoclass)) {
        $attids = array_values(array_map('intval', array_keys($atttoclass)));
        list($ains, $apar) = $DB->get_in_or_equal($attids, SQL_PARAMS_NAMED, 'afa');
        $attsessions = $DB->get_records_sql(
            "SELECT id, attendanceid, groupid, sessdate, duration
               FROM {attendance_sessions}
              WHERE attendanceid $ains",
            $apar
        );
        foreach ($attsessions as $sess) {
            $attid = (int)$sess->attendanceid;
            foreach (array_values($atttoclass[$attid] ?? []) as $cid) {
                $classgroup = (int)($classes[$cid]->groupid ?? 0);
                $sessiongroup = (int)($sess->groupid ?? 0);
                if ($classgroup > 0 && $sessiongroup > 0 && $classgroup !== $sessiongroup) {
                    continue;
                }
                $addsession((int)$cid, $sess);
            }
        }
    }

    return $out;
}

function ov_load_attendance_course_fallback_sessions(array $classes, array $existing = []) {
    global $DB;

    $out = [];
    if (empty($classes)) {
        return $out;
    }

    $classesbycourse = [];
    foreach ($classes as $cid => $class) {
        $cid = (int)$cid;
        if (!empty($existing[$cid])) {
            continue;
        }
        $courseid = (int)($class->corecourseid ?? 0);
        if ($courseid <= 0) {
            continue;
        }
        if (!isset($classesbycourse[$courseid])) {
            $classesbycourse[$courseid] = [];
        }
        $classesbycourse[$courseid][$cid] = $class;
    }
    if (empty($classesbycourse)) {
        return $out;
    }

    $courseids = array_values(array_map('intval', array_keys($classesbycourse)));
    list($cinsql, $cparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'cfa');
    $sessions = $DB->get_records_sql(
        "SELECT s.id, s.attendanceid, s.groupid, s.sessdate, s.duration, a.course
           FROM {attendance_sessions} s
           JOIN {attendance} a ON a.id = s.attendanceid
          WHERE a.course $cinsql",
        $cparams
    );

    $dedup = [];
    foreach ($sessions as $sess) {
        $courseid = (int)($sess->course ?? 0);
        if ($courseid <= 0 || empty($classesbycourse[$courseid])) {
            continue;
        }
        $ts = (int)($sess->sessdate ?? 0);
        if ($ts <= 0) {
            continue;
        }
        $day = (int)date('N', $ts);
        $start = ((int)date('G', $ts) * 60) + (int)date('i', $ts);
        $duration = (int)($sess->duration ?? 0);
        if ($duration <= 0) {
            $duration = 3600;
        }
        $end = $start + max(1, (int)ceil($duration / 60));
        if ($end <= $start) {
            continue;
        }
        $date = date('Y-m-d', $ts);
        $sessiongroup = (int)($sess->groupid ?? 0);

        $candidates = [];
        foreach ($classesbycourse[$courseid] as $cid => $class) {
            $classstart = (int)($class->initdate ?? 0);
            $classend = (int)($class->enddate ?? 0);
            if ($classstart > 0 && $ts < $classstart) {
                continue;
            }
            if ($classend > 0 && $ts > $classend) {
                continue;
            }

            $classgroup = (int)($class->groupid ?? 0);
            if ($sessiongroup > 0) {
                if ($classgroup !== $sessiongroup) {
                    continue;
                }
                $candidates[$cid] = $class;
                continue;
            }

            if ($classgroup === 0) {
                $candidates[$cid] = $class;
                continue;
            }

            $cstart = ov_tmin($class->inittime ?? '');
            $cend = ov_tmin($class->endtime ?? '');
            if ($cstart >= 0 && $cend > $cstart) {
                $mask = explode('/', trim((string)($class->classdays ?? '')));
                $dayok = (isset($mask[$day - 1]) && (string)$mask[$day - 1] === '1');
                if ($dayok) {
                    $st = max($cstart, $start);
                    $en = min($cend, $end);
                    if ($st < $en) {
                        $candidates[$cid] = $class;
                    }
                }
            }
        }

        if (empty($candidates)) {
            continue;
        }

        foreach ($candidates as $cid => $class) {
            $key = $date . '|' . $start . '|' . $end;
            if (isset($dedup[(int)$cid][$key])) {
                continue;
            }
            $dedup[(int)$cid][$key] = true;
            if (!isset($out[(int)$cid])) {
                $out[(int)$cid] = [];
            }
            $out[(int)$cid][] = [
                'day' => $day,
                'start' => $start,
                'end' => $end,
                'assigned' => [$date],
                'excluded' => []
            ];
        }
    }

    return $out;
}

function ov_load_legacy_time_fallback_sessions(array $classes) {
    $out = [];
    foreach ($classes as $cid => $class) {
        $start = ov_tmin($class->inittime ?? '');
        $end = ov_tmin($class->endtime ?? '');
        if ($start < 0 || $end <= $start) {
            continue;
        }
        $mask = explode('/', trim((string)($class->classdays ?? '')));
        if (count($mask) < 7) {
            continue;
        }
        for ($i = 0; $i < 7; $i++) {
            if ((string)($mask[$i] ?? '0') !== '1') {
                continue;
            }
            if (!isset($out[(int)$cid])) {
                $out[(int)$cid] = [];
            }
            $out[(int)$cid][] = [
                'day' => $i + 1,
                'start' => $start,
                'end' => $end,
                'assigned' => [],
                'excluded' => []
            ];
        }
    }
    return $out;
}

function ov_overlap_dates($a, $b) {
    $as = (int)($a->initdate ?? 0);
    $ae = (int)($a->enddate ?? 0);
    $bs = (int)($b->initdate ?? 0);
    $be = (int)($b->enddate ?? 0);
    if ($as > 0 && $ae > 0 && $bs > 0 && $be > 0) {
        return $as <= $be && $ae >= $bs;
    }
    return true;
}

function ov_user_match($u, $q) {
    $q = ov_norm($q);
    if ($q === '') {
        return true;
    }
    $s = ov_norm(
        ($u->firstname ?? '') . ' ' .
        ($u->lastname ?? '') . ' ' .
        ($u->idnumber ?? '') . ' ' .
        ($u->email ?? '') . ' ' .
        ($u->username ?? '')
    );
    return $s !== '' && strpos($s, $q) !== false;
}

function ov_flash_enc(array $a) {
    return rtrim(strtr(base64_encode(json_encode($a)), '+/', '-_'), '=');
}

function ov_flash_dec($v) {
    if ($v === '') {
        return null;
    }
    $raw = base64_decode(strtr($v, '-_', '+/'), true);
    if ($raw === false) {
        return null;
    }
    $arr = json_decode($raw, true);
    return is_array($arr) ? $arr : null;
}

function ov_withdraw_plan_candidates($classid, $userid, $preferredplanid = 0) {
    global $DB;
    $candidates = [];
    if ((int)$preferredplanid > 0) {
        $candidates[(int)$preferredplanid] = (int)$preferredplanid;
    }

    $class = $DB->get_record('gmk_class', ['id' => (int)$classid], 'id,corecourseid,learningplanid,courseid', IGNORE_MISSING);
    if ($class) {
        if (!empty($class->learningplanid)) {
            $candidates[(int)$class->learningplanid] = (int)$class->learningplanid;
        }
        if (!empty($class->courseid)) {
            $mappedplan = (int)$DB->get_field('local_learning_courses', 'learningplanid', ['id' => (int)$class->courseid], IGNORE_MISSING);
            if ($mappedplan > 0) {
                $candidates[$mappedplan] = $mappedplan;
            }
        }

        if (!empty($class->corecourseid)) {
            $courseplanids = $DB->get_fieldset_sql(
                "SELECT DISTINCT learningplanid
                   FROM {gmk_course_progre}
                  WHERE userid = :uid
                    AND courseid = :courseid
                    AND learningplanid > 0",
                ['uid' => (int)$userid, 'courseid' => (int)$class->corecourseid]
            );
            foreach ($courseplanids as $pid) {
                $pid = (int)$pid;
                if ($pid > 0) {
                    $candidates[$pid] = $pid;
                }
            }

            $activeplans = $DB->get_fieldset_sql(
                "SELECT DISTINCT lu.learningplanid
                   FROM {local_learning_users} lu
                   JOIN {local_learning_courses} lpc ON lpc.learningplanid = lu.learningplanid
                  WHERE lu.userid = :uid
                    AND lu.status = :active
                    AND lpc.courseid = :courseid",
                ['uid' => (int)$userid, 'active' => 'activo', 'courseid' => (int)$class->corecourseid]
            );
            foreach ($activeplans as $pid) {
                $pid = (int)$pid;
                if ($pid > 0) {
                    $candidates[$pid] = $pid;
                }
            }
        }
    }

    return array_values($candidates);
}

function ov_withdraw_residuals($classid, $userid, array $planids = []) {
    global $DB;

    $class = $DB->get_record('gmk_class', ['id' => (int)$classid], 'id,groupid,corecourseid', IGNORE_MISSING);
    $ingroup = false;
    if ($class && !empty($class->groupid)) {
        $ingroup = groups_is_member((int)$class->groupid, (int)$userid);
    }

    $classrows = (int)$DB->count_records('gmk_course_progre', ['userid' => (int)$userid, 'classid' => (int)$classid]);
    $prereg = (int)$DB->count_records('gmk_class_pre_registration', ['userid' => (int)$userid, 'classid' => (int)$classid]);
    $queue = (int)$DB->count_records('gmk_class_queue', ['userid' => (int)$userid, 'classid' => (int)$classid]);

    $floating = 0;
    if ($class && !empty($class->corecourseid) && !empty($planids)) {
        list($insql, $inparams) = $DB->get_in_or_equal(array_values(array_unique(array_map('intval', $planids))), SQL_PARAMS_NAMED, 'ovlp');
        $floating = (int)$DB->count_records_sql(
            "SELECT COUNT(1)
               FROM {gmk_course_progre}
              WHERE userid = :uid
                AND courseid = :courseid
                AND learningplanid $insql
                AND status = :stinprogress
                AND classid = 0
                AND groupid = 0",
            ['uid' => (int)$userid, 'courseid' => (int)$class->corecourseid, 'stinprogress' => COURSE_IN_PROGRESS] + $inparams
        );
    }

    return [
        'ingroup' => $ingroup ? 1 : 0,
        'classrows' => $classrows,
        'prereg' => $prereg,
        'queue' => $queue,
        'floating' => $floating
    ];
}

function ov_withdraw($classid, $userid, $learningplanid) {
    $planids = ov_withdraw_plan_candidates((int)$classid, (int)$userid, (int)$learningplanid);
    if (!in_array(0, $planids, true)) {
        $planids[] = 0;
    }

    $lastr = null;
    foreach ($planids as $pid) {
        try {
            $r = \local_grupomakro_core\external\schedule\withdraw_student::execute((int)$classid, (int)$userid, (int)$pid);
            $lastr = is_array($r) ? $r : ['status' => 'error', 'message' => 'Respuesta invalida'];
        } catch (Throwable $e) {
            $lastr = ['status' => 'error', 'message' => $e->getMessage()];
        }

        $residual = ov_withdraw_residuals((int)$classid, (int)$userid, $planids);
        $complete = ((int)$residual['ingroup'] === 0)
            && ((int)$residual['classrows'] === 0)
            && ((int)$residual['prereg'] === 0)
            && ((int)$residual['queue'] === 0);

        if ($complete) {
            $msg = (string)($lastr['message'] ?? 'ok');
            if ((int)$pid > 0 && (int)$pid !== (int)$learningplanid) {
                $msg .= ' (reintento con learningplanid=' . (int)$pid . ')';
            }
            if ((int)$residual['floating'] > 0) {
                $msg .= ' | nota: filas floating=' . (int)$residual['floating'] . ' (no bloquean retiro de esta ficha)';
            }
            return ['status' => 'ok', 'message' => $msg];
        }
    }

    $residual = ov_withdraw_residuals((int)$classid, (int)$userid, $planids);
    $base = is_array($lastr) ? $lastr : ['status' => 'error', 'message' => 'No se pudo retirar'];
    $base['status'] = 'error';
    $base['message'] = (string)($base['message'] ?? 'No se pudo retirar')
        . ' | residuals group=' . (int)$residual['ingroup']
        . ' classrows=' . (int)$residual['classrows']
        . ' prereg=' . (int)$residual['prereg']
        . ' queue=' . (int)$residual['queue']
        . ' floating=' . (int)$residual['floating'];
    return $base;

}

function ov_keep_score($class, $progre, $planmatch, $group, $prog, $now) {
    $s = 0;
    $s += $planmatch ? 4 : -4;
    if ((int)$class->initdate <= $now && (int)$class->enddate >= $now) {
        $s += 2;
    }
    $s += $prog ? 2 : -1;
    $s += $group ? 1 : 0;
    if ($progre) {
        if ((int)($progre->status ?? 0) === COURSE_IN_PROGRESS) {
            $s += 2;
        }
        if ((float)($progre->progress ?? 0) > 0) {
            $s += 2;
        }
        if ((float)($progre->grade ?? 0) > 0) {
            $s += 1;
        }
    }
    return $s;
}

function ov_recommend($row, $activeplans, $now) {
    $uid = (int)$row['userid'];
    $a = $row['classa'];
    $b = $row['classb'];
    $plans = $activeplans[$uid] ?? [];
    $ap = in_array((int)$a->learningplanid, $plans, true);
    $bp = in_array((int)$b->learningplanid, $plans, true);
    $sa = ov_keep_score($a, $row['proga'], $ap, (bool)$row['fromgroupa'], (bool)$row['fromprogrea'], $now);
    $sb = ov_keep_score($b, $row['progb'], $bp, (bool)$row['fromgroupb'], (bool)$row['fromprogreb'], $now);
    $withdraw = (int)$a->id;
    $reason = 'Clase A tiene menor prioridad';
    if (!$ap && $bp) {
        $withdraw = (int)$a->id;
        $reason = 'Clase A no coincide con plan activo';
    } else if (!$bp && $ap) {
        $withdraw = (int)$b->id;
        $reason = 'Clase B no coincide con plan activo';
    } else if ($sb < $sa) {
        $withdraw = (int)$b->id;
        $reason = 'Clase B tiene menor puntaje';
    }
    $withdrawlp = $withdraw === (int)$a->id ? (int)$row['lpa'] : (int)$row['lpb'];
    return ['classid' => $withdraw, 'learningplanid' => $withdrawlp, 'reason' => $reason, 'scorea' => $sa, 'scoreb' => $sb];
}

// Global analytics without user filters: always review all published/open classes.
$periodid = 0;
$studentq = '';
$runningonly = 0;
$includepending = 1;
$maxconflicts = 0;

$base = [];

if (data_submitted() && confirm_sesskey()) {
    // Keep underscores in action names (bulk_suggested, withdraw_a, ...).
    $op = optional_param('op', '', PARAM_ALPHANUMEXT);
    $targets = [];
    $rowsel = optional_param('rowop', '', PARAM_RAW_TRIMMED);
    $parseSelection = function($sel) {
        $parts = explode('|', (string)$sel);
        if (count($parts) !== 6) {
            return null;
        }
        $uid = (int)$parts[0];
        $ca = (int)$parts[1];
        $lpa = (int)$parts[2];
        $cb = (int)$parts[3];
        $lpb = (int)$parts[4];
        $suggested = (int)$parts[5];
        if ($uid <= 0 || $ca <= 0 || $cb <= 0) {
            return null;
        }
        return [
            'uid' => $uid,
            'ca' => $ca,
            'lpa' => $lpa,
            'cb' => $cb,
            'lpb' => $lpb,
            'suggested' => $suggested
        ];
    };

    if ($rowsel !== '') {
        // rowop format: <op>|<uid>|<classa>|<lpa>|<classb>|<lpb>|<suggested>
        $rowparts = explode('|', (string)$rowsel, 2);
        if (count($rowparts) === 2) {
            $rowop = trim((string)$rowparts[0]);
            $parsed = $parseSelection((string)$rowparts[1]);
            if (in_array($rowop, ['withdraw_a', 'withdraw_b', 'withdraw_suggested'], true) && is_array($parsed)) {
                $targetclass = (int)$parsed['ca'];
                $targetlp = (int)$parsed['lpa'];
                if ($rowop === 'withdraw_b') {
                    $targetclass = (int)$parsed['cb'];
                    $targetlp = (int)$parsed['lpb'];
                } else if ($rowop === 'withdraw_suggested' && (int)$parsed['suggested'] > 0) {
                    $targetclass = (int)$parsed['suggested'];
                    $targetlp = ($targetclass === (int)$parsed['ca']) ? (int)$parsed['lpa'] : (int)$parsed['lpb'];
                }
                $targets[] = ['userid' => (int)$parsed['uid'], 'classid' => $targetclass, 'learningplanid' => $targetlp];
                $op = $rowop;
            }
        }
    } else if ($op !== '') {
        if (in_array($op, ['withdraw_a', 'withdraw_b', 'withdraw_suggested'], true)) {
            $userid = required_param('userid', PARAM_INT);
            $classida = required_param('classida', PARAM_INT);
            $classidb = required_param('classidb', PARAM_INT);
            $lpa = optional_param('lpa', 0, PARAM_INT);
            $lpb = optional_param('lpb', 0, PARAM_INT);
            $suggested = optional_param('suggested', 0, PARAM_INT);
            $targetclass = $classida;
            $targetlp = $lpa;
            if ($op === 'withdraw_b') {
                $targetclass = $classidb;
                $targetlp = $lpb;
            } else if ($op === 'withdraw_suggested' && $suggested > 0) {
                $targetclass = $suggested;
                $targetlp = ($targetclass === $classida) ? $lpa : $lpb;
            }
            $targets[] = ['userid' => $userid, 'classid' => $targetclass, 'learningplanid' => $targetlp];
        }
        if (in_array($op, ['bulk_suggested', 'bulk_a', 'bulk_b'], true)) {
            $selectedrows = optional_param_array('selected', [], PARAM_RAW_TRIMMED);
            if (empty($selectedrows)) {
                $selectedrows = optional_param_array('allrows', [], PARAM_RAW_TRIMMED);
            }
            foreach ($selectedrows as $sel) {
                $parsed = $parseSelection((string)$sel);
                if (!is_array($parsed)) {
                    continue;
                }
                $targetclass = (int)$parsed['ca'];
                $targetlp = (int)$parsed['lpa'];
                if ($op === 'bulk_b') {
                    $targetclass = (int)$parsed['cb'];
                    $targetlp = (int)$parsed['lpb'];
                } else if ($op === 'bulk_suggested' && (int)$parsed['suggested'] > 0) {
                    $targetclass = (int)$parsed['suggested'];
                    $targetlp = ($targetclass === (int)$parsed['ca']) ? (int)$parsed['lpa'] : (int)$parsed['lpb'];
                }
                $targets[] = ['userid' => (int)$parsed['uid'], 'classid' => $targetclass, 'learningplanid' => $targetlp];
            }
        }
    }

    if (($op !== '' || $rowsel !== '') && empty($targets)) {
        redirect(new moodle_url('/local/grupomakro_core/pages/overlap_analytics.php', $base + [
            'flash' => ov_flash_enc([
                'ok' => 0,
                'error' => 1,
                'messages' => ['No se encontraron filas validas para procesar la accion.'],
            ]),
        ]));
    }

    if (!empty($targets)) {

        $ok = 0;
        $err = 0;
        $msgs = [];
        $seen = [];
        foreach ($targets as $t) {
            $k = (int)$t['userid'] . ':' . (int)$t['classid'];
            if (isset($seen[$k]) || (int)$t['userid'] <= 0 || (int)$t['classid'] <= 0) {
                continue;
            }
            $seen[$k] = true;
            $r = ov_withdraw((int)$t['classid'], (int)$t['userid'], (int)$t['learningplanid']);
            if (($r['status'] ?? 'error') === 'ok') {
                $ok++;
            } else {
                $err++;
            }
            $msgs[] = 'uid=' . (int)$t['userid'] . ' class=' . (int)$t['classid'] . ': ' . ($r['message'] ?? '');
        }
        redirect(new moodle_url('/local/grupomakro_core/pages/overlap_analytics.php', $base + ['flash' => ov_flash_enc(['ok' => $ok, 'error' => $err, 'messages' => array_slice($msgs, 0, 10)])]));
    }
}

$flash = ov_flash_dec(optional_param('flash', '', PARAM_RAW_TRIMMED));
$rows = [];
$truncated = false;
$schemawarning = '';
$studentdiagnostic = null;
$stats = ['classes' => 0, 'students' => 0, 'studentswithconflicts' => 0, 'conflicts' => 0];
$selectedperiod = null;
if (true) {
    $now = time();
    $sql = "SELECT c.id,c.name,c.periodid,c.learningplanid,c.shift,c.initdate,c.enddate,c.groupid,c.approved,c.closed,c.instructorid,c.classduration,c.attendancemoduleid,
                   c.inittime,c.endtime,c.classdays,
                   lp.name AS learningplanname,u.firstname AS instructorfirstname,u.lastname AS instructorlastname,
                   ap.name AS periodname
              FROM {gmk_class} c
         LEFT JOIN {local_learning_plans} lp ON lp.id=c.learningplanid
         LEFT JOIN {user} u ON u.id=c.instructorid
         LEFT JOIN {gmk_academic_periods} ap ON ap.id=c.periodid
             WHERE c.approved=1 AND c.closed=0";
    $params = [];
    if ((int)$runningonly === 1) {
        $sql .= " AND c.initdate<=:n1 AND c.enddate>=:n2";
        $params['n1'] = $now;
        $params['n2'] = $now;
    }
    $classes = $DB->get_records_sql($sql, $params);
    $stats['classes'] = count($classes);
    if (!empty($classes)) {
        $classids = array_map('intval', array_keys($classes));
        list($daycol, $startcol, $endcol, $assignedcol, $excludedcol) = ov_get_schedule_columns();
        list($ins1, $par1) = $DB->get_in_or_equal($classids, SQL_PARAMS_NAMED, 'c1');
        $schedules = [];
        if (!empty($daycol) && !empty($startcol) && !empty($endcol)) {
            $assignedselect = !empty($assignedcol) ? "{$assignedcol} AS assignedvalue" : "'' AS assignedvalue";
            $excludedselect = !empty($excludedcol) ? "{$excludedcol} AS excludedvalue" : "'' AS excludedvalue";
            $schedules = $DB->get_records_sql(
                "SELECT id AS schedid, classid, {$daycol} AS dayvalue, {$startcol} AS startvalue, {$endcol} AS endvalue,
                        {$assignedselect}, {$excludedselect}
                   FROM {gmk_class_schedules}
                  WHERE classid $ins1
               ORDER BY classid, {$daycol}, {$startcol}",
                $par1
            );
        } else {
            $schemawarning = 'No se pudieron resolver columnas de horario en gmk_class_schedules. Verifica campos day/start/end en la tabla.';
        }
        $sched = [];
        foreach ($schedules as $s) {
            $day = ov_day_to_int($s->dayvalue ?? '');
            $start = ov_tmin($s->startvalue ?? '');
            $end = ov_tmin($s->endvalue ?? '');
            if ($day <= 0 || $start < 0 || $end <= $start) {
                continue;
            }
            $sched[(int)$s->classid][] = [
                'day' => $day,
                'start' => $start,
                'end' => $end,
                'assigned' => ov_parse_date_json($s->assignedvalue ?? ''),
                'excluded' => ov_parse_date_json($s->excludedvalue ?? '')
            ];
        }

        // Fallback for classes created/edited outside planning board that have no gmk_class_schedules rows.
        $attfallback = ov_load_attendance_fallback_sessions($classes);
        foreach ($attfallback as $cid => $rowsatt) {
            if (!empty($sched[(int)$cid])) {
                continue;
            }
            $sched[(int)$cid] = $rowsatt;
        }

        // Fallback by attendance.course + attendance_sessions.groupid/date.
        $attcoursefallback = ov_load_attendance_course_fallback_sessions($classes, $sched);
        foreach ($attcoursefallback as $cid => $rowsatt) {
            if (!empty($sched[(int)$cid])) {
                continue;
            }
            $sched[(int)$cid] = $rowsatt;
        }

        // Last fallback: classes created outside planner may only have classdays + inittime/endtime.
        $legacyfallback = ov_load_legacy_time_fallback_sessions($classes);
        foreach ($legacyfallback as $cid => $rowslegacy) {
            if (!empty($sched[(int)$cid])) {
                continue;
            }
            $sched[(int)$cid] = $rowslegacy;
        }

        list($ins2a, $par2a) = $DB->get_in_or_equal($classids, SQL_PARAMS_NAMED, 'ca');
        list($ins2b, $par2b) = $DB->get_in_or_equal($classids, SQL_PARAMS_NAMED, 'cb');
        $pendingunion = '';
        $linkparams = $par2a + $par2b + ['stinprogress' => COURSE_IN_PROGRESS];
        if ((int)$includepending === 1) {
            list($ins2c, $par2c) = $DB->get_in_or_equal($classids, SQL_PARAMS_NAMED, 'cc');
            list($ins2d, $par2d) = $DB->get_in_or_equal($classids, SQL_PARAMS_NAMED, 'cd');
            $pendingunion = "
                    UNION ALL
                    SELECT q.classid,q.userid,0 AS learningplanid,0 AS fromgroup,0 AS fromprogre,1 AS fromqueue,0 AS fromprereg
                      FROM {gmk_class_queue} q
                     WHERE q.classid $ins2c
                    UNION ALL
                    SELECT pr.classid,pr.userid,0 AS learningplanid,0 AS fromgroup,0 AS fromprogre,0 AS fromqueue,1 AS fromprereg
                      FROM {gmk_class_pre_registration} pr
                     WHERE pr.classid $ins2d";
            $linkparams = $linkparams + $par2c + $par2d;
        }
        $linkkeyexpr = $DB->sql_concat('x.classid', "'-'", 'x.userid');
        $links = $DB->get_records_sql(
            "SELECT {$linkkeyexpr} AS linkkey,
                    x.classid,x.userid,MAX(x.learningplanid) AS learningplanid,
                    MAX(x.fromgroup) AS fromgroup,MAX(x.fromprogre) AS fromprogre,
                    MAX(x.fromqueue) AS fromqueue,MAX(x.fromprereg) AS fromprereg
               FROM (
                    SELECT c.id AS classid,gm.userid,0 AS learningplanid,1 AS fromgroup,0 AS fromprogre,0 AS fromqueue,0 AS fromprereg
                      FROM {gmk_class} c
                      JOIN {groups_members} gm ON gm.groupid=c.groupid
                     WHERE c.id $ins2a
                    UNION ALL
                    SELECT cp.classid,cp.userid,cp.learningplanid,0 AS fromgroup,1 AS fromprogre,0 AS fromqueue,0 AS fromprereg
                      FROM {gmk_course_progre} cp
                      JOIN {gmk_class} ccp ON ccp.id = cp.classid
                     WHERE cp.classid $ins2b
                       AND cp.classid > 0
                       AND cp.status = :stinprogress
                    {$pendingunion}
               ) x
              GROUP BY x.classid,x.userid",
            $linkparams
        );

        $studentclasses = [];
        $userset = [];
        $meta = [];
        foreach ($links as $l) {
            $cid = (int)$l->classid;
            $uid = (int)$l->userid;
            if (!isset($classes[$cid]) || $uid <= 0) {
                continue;
            }
            $studentclasses[$uid][$cid] = true;
            $userset[$uid] = $uid;
            $meta[$uid . ':' . $cid] = [
                'learningplanid' => (int)$l->learningplanid,
                'fromgroup' => (int)$l->fromgroup,
                'fromprogre' => (int)$l->fromprogre,
                'fromqueue' => (int)($l->fromqueue ?? 0),
                'fromprereg' => (int)($l->fromprereg ?? 0)
            ];
        }
        $stats['students'] = count($studentclasses);

        $users = [];
        $activeplans = [];
        $progress = [];
        if (!empty($userset)) {
            list($uins, $upar) = $DB->get_in_or_equal(array_values($userset), SQL_PARAMS_NAMED, 'u');
            $users = $DB->get_records_sql("SELECT id,firstname,lastname,idnumber,email,username FROM {user} WHERE deleted=0 AND id $uins", $upar);
            $lp = $DB->get_records_sql("SELECT id,userid,learningplanid FROM {local_learning_users} WHERE userid $uins AND status=:active", $upar + ['active' => 'activo']);
            foreach ($lp as $r) {
                $activeplans[(int)$r->userid][] = (int)$r->learningplanid;
            }
            list($pins, $ppar) = $DB->get_in_or_equal($classids, SQL_PARAMS_NAMED, 'pc');
            $prows = $DB->get_records_sql("SELECT id,userid,classid,learningplanid,status,progress,grade,timemodified FROM {gmk_course_progre} WHERE classid $pins AND userid $uins", $ppar + $upar);
            foreach ($prows as $p) {
                $k = (int)$p->userid . ':' . (int)$p->classid;
                if (!isset($progress[$k])) {
                    $progress[$k] = $p;
                    continue;
                }
                $old = $progress[$k];
                if ((int)$p->status === COURSE_IN_PROGRESS && (int)$old->status !== COURSE_IN_PROGRESS) {
                    $progress[$k] = $p;
                } else if ((int)$p->timemodified > (int)$old->timemodified) {
                    $progress[$k] = $p;
                }
            }
        }

        $conflicts = [];
        foreach ($studentclasses as $uid => $cset) {
            if ($truncated || !isset($users[$uid]) || !ov_user_match($users[$uid], $studentq)) {
                continue;
            }
            $ids = array_values(array_map('intval', array_keys($cset)));
            sort($ids);
            $n = count($ids);
            if ($n < 2) {
                continue;
            }
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $a = $ids[$i];
                    $b = $ids[$j];
                    if (!isset($classes[$a], $classes[$b]) || empty($sched[$a]) || empty($sched[$b]) || !ov_overlap_dates($classes[$a], $classes[$b])) {
                        continue;
                    }
                    $wins = [];
                    foreach ($sched[$a] as $sa) {
                        foreach ($sched[$b] as $sb) {
                            if ((int)$sa['day'] !== (int)$sb['day']) {
                                continue;
                            }
                            $st = max((int)$sa['start'], (int)$sb['start']);
                            $en = min((int)$sa['end'], (int)$sb['end']);
                            if ($st >= $en) {
                                continue;
                            }
                            if (!ov_schedule_date_overlap($sa, $sb, $classes[$a], $classes[$b])) {
                                continue;
                            }
                            $wins[ov_day((int)$sa['day']) . ' ' . ov_fmin($st) . '-' . ov_fmin($en)] = true;
                        }
                    }
                    if (empty($wins)) {
                        continue;
                    }
                    $k = $uid . '|' . $a . '|' . $b;
                    if (!isset($conflicts[$k])) {
                        $ma = $meta[$uid . ':' . $a] ?? ['learningplanid' => 0, 'fromgroup' => 0, 'fromprogre' => 0, 'fromqueue' => 0, 'fromprereg' => 0];
                        $mb = $meta[$uid . ':' . $b] ?? ['learningplanid' => 0, 'fromgroup' => 0, 'fromprogre' => 0, 'fromqueue' => 0, 'fromprereg' => 0];
                        $pa = $progress[$uid . ':' . $a] ?? null;
                        $pb = $progress[$uid . ':' . $b] ?? null;
                        $conflicts[$k] = [
                            'userid' => (int)$uid, 'user' => $users[$uid], 'classa' => $classes[$a], 'classb' => $classes[$b], 'proga' => $pa, 'progb' => $pb,
                            'fromgroupa' => (int)$ma['fromgroup'], 'fromprogrea' => (int)$ma['fromprogre'], 'fromgroupb' => (int)$mb['fromgroup'], 'fromprogreb' => (int)$mb['fromprogre'],
                            'fromqueuea' => (int)$ma['fromqueue'], 'fromprerega' => (int)$ma['fromprereg'], 'fromqueueb' => (int)$mb['fromqueue'], 'frompreregb' => (int)$mb['fromprereg'],
                            'lpa' => (int)($ma['learningplanid'] ?: ($pa->learningplanid ?? 0) ?: ($classes[$a]->learningplanid ?? 0)),
                            'lpb' => (int)($mb['learningplanid'] ?: ($pb->learningplanid ?? 0) ?: ($classes[$b]->learningplanid ?? 0)),
                            'windows' => []
                        ];
                    }
                    foreach (array_keys($wins) as $w) {
                        $conflicts[$k]['windows'][$w] = true;
                    }
                    if ($maxconflicts > 0 && count($conflicts) >= $maxconflicts) {
                        $truncated = true;
                        break;
                    }
                }
                if ($truncated) {
                    break;
                }
            }
        }

        foreach ($conflicts as $r) {
            $r['windows'] = array_values(array_keys($r['windows']));
            sort($r['windows']);
            $r['suggested'] = ov_recommend($r, $activeplans, $now);
            $rows[] = $r;
        }
        usort($rows, function($a, $b) {
            $an = core_text::strtolower((string)$a['user']->firstname . ' ' . (string)$a['user']->lastname);
            $bn = core_text::strtolower((string)$b['user']->firstname . ' ' . (string)$b['user']->lastname);
            return $an === $bn ? ((int)$a['userid'] <=> (int)$b['userid']) : strcmp($an, $bn);
        });
    }
}

$stats['conflicts'] = count($rows);
$stats['studentswithconflicts'] = count(array_unique(array_map(function($r) { return (int)$r['userid']; }, $rows)));

if (trim((string)$studentq) !== '') {
    $like = '%' . trim((string)$studentq) . '%';
    $studentcandidate = $DB->get_record_sql(
        "SELECT id, firstname, lastname, idnumber, email, username
           FROM {user}
          WHERE deleted = 0
            AND (
                " . $DB->sql_like('firstname', ':q1', false) . "
                OR " . $DB->sql_like('lastname', ':q2', false) . "
                OR " . $DB->sql_like('idnumber', ':q3', false) . "
                OR " . $DB->sql_like('email', ':q4', false) . "
                OR " . $DB->sql_like('username', ':q5', false) . "
            )
       ORDER BY id ASC",
        ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'q5' => $like],
        IGNORE_MULTIPLE
    );

    if ($studentcandidate) {
        $uid = (int)$studentcandidate->id;
        $pendingdiagunion = '';
        $diagparams = ['uid1' => $uid, 'uid2' => $uid, 'stinprogress' => COURSE_IN_PROGRESS];
        if ((int)$includepending === 1) {
            $pendingdiagunion = "
                    UNION ALL
                    SELECT q.classid AS classid, 0 AS fromgroup, 0 AS fromprogre, 1 AS fromqueue, 0 AS fromprereg
                      FROM {gmk_class_queue} q
                     WHERE q.userid = :uid3
                    UNION ALL
                    SELECT pr.classid AS classid, 0 AS fromgroup, 0 AS fromprogre, 0 AS fromqueue, 1 AS fromprereg
                      FROM {gmk_class_pre_registration} pr
                     WHERE pr.userid = :uid4";
            $diagparams['uid3'] = $uid;
            $diagparams['uid4'] = $uid;
        }
        $srcrows = $DB->get_records_sql(
            "SELECT x.classid,
                    MAX(x.fromgroup) AS fromgroup,
                    MAX(x.fromprogre) AS fromprogre,
                    MAX(x.fromqueue) AS fromqueue,
                    MAX(x.fromprereg) AS fromprereg
               FROM (
                    SELECT c.id AS classid, 1 AS fromgroup, 0 AS fromprogre, 0 AS fromqueue, 0 AS fromprereg
                      FROM {gmk_class} c
                      JOIN {groups_members} gm ON gm.groupid = c.groupid
                     WHERE gm.userid = :uid1
                    UNION ALL
                    SELECT cp.classid AS classid, 0 AS fromgroup, 1 AS fromprogre, 0 AS fromqueue, 0 AS fromprereg
                      FROM {gmk_course_progre} cp
                      JOIN {gmk_class} ccp ON ccp.id = cp.classid
                     WHERE cp.userid = :uid2
                       AND cp.classid > 0
                       AND cp.status = :stinprogress
                    {$pendingdiagunion}
               ) x
              GROUP BY x.classid",
            $diagparams
        );

        $reasoncounts = ['status' => 0, 'window' => 0, 'schedule' => 0, 'ok' => 0];
        $sample = [];
        if (!empty($srcrows)) {
            $srcids = array_map('intval', array_keys($srcrows));
            $srcclasses = $DB->get_records_list('gmk_class', 'id', $srcids);

            list($diagdaycol, $diagstartcol, $diagendcol, $diagassignedcol, $diagexcludedcol) = ov_get_schedule_columns();
            $validschedule = [];
            $scheduletexts = [];
            if (!empty($diagdaycol) && !empty($diagstartcol) && !empty($diagendcol)) {
                list($diaginsql, $diagparams) = $DB->get_in_or_equal($srcids, SQL_PARAMS_NAMED, 'sd');
                $diagassignedselect = !empty($diagassignedcol) ? "{$diagassignedcol} AS assignedvalue" : "'' AS assignedvalue";
                $diagexcludedselect = !empty($diagexcludedcol) ? "{$diagexcludedcol} AS excludedvalue" : "'' AS excludedvalue";
                $diagschedules = $DB->get_records_sql(
                    "SELECT id, classid, {$diagdaycol} AS dayvalue, {$diagstartcol} AS startvalue, {$diagendcol} AS endvalue,
                            {$diagassignedselect}, {$diagexcludedselect}
                       FROM {gmk_class_schedules}
                      WHERE classid $diaginsql",
                    $diagparams
                );
                foreach ($diagschedules as $ds) {
                    $dd = ov_day_to_int($ds->dayvalue ?? '');
                    $dst = ov_tmin($ds->startvalue ?? '');
                    $den = ov_tmin($ds->endvalue ?? '');
                    if ($dd > 0 && $dst >= 0 && $den > $dst) {
                        $validschedule[(int)$ds->classid] = true;
                        if (!isset($scheduletexts[(int)$ds->classid])) {
                            $scheduletexts[(int)$ds->classid] = [];
                        }
                        $note = '';
                        $assigned = ov_parse_date_json($ds->assignedvalue ?? '');
                        if (!empty($assigned)) {
                            $note = ' assigned=' . count($assigned);
                        }
                        $scheduletexts[(int)$ds->classid][] = ov_day($dd) . ' ' . ov_fmin($dst) . '-' . ov_fmin($den) . $note;
                    }
                }
            }

            $diagfallback = ov_load_attendance_fallback_sessions($srcclasses);
            foreach ($diagfallback as $cid => $rowsatt) {
                if (empty($rowsatt) || !empty($validschedule[(int)$cid])) {
                    continue;
                }
                $validschedule[(int)$cid] = true;
                if (!isset($scheduletexts[(int)$cid])) {
                    $scheduletexts[(int)$cid] = [];
                }
                foreach ($rowsatt as $sa) {
                    $scheduletexts[(int)$cid][] = '[ATT] ' . ov_day((int)$sa['day']) . ' ' . ov_fmin((int)$sa['start']) . '-' . ov_fmin((int)$sa['end']) . ' assigned=' . count((array)($sa['assigned'] ?? []));
                }
            }

            $diagattcourse = ov_load_attendance_course_fallback_sessions($srcclasses, $validschedule);
            foreach ($diagattcourse as $cid => $rowsatt) {
                if (empty($rowsatt) || !empty($validschedule[(int)$cid])) {
                    continue;
                }
                $validschedule[(int)$cid] = true;
                if (!isset($scheduletexts[(int)$cid])) {
                    $scheduletexts[(int)$cid] = [];
                }
                foreach ($rowsatt as $sa) {
                    $scheduletexts[(int)$cid][] = '[ATT-COURSE] ' . ov_day((int)$sa['day']) . ' ' . ov_fmin((int)$sa['start']) . '-' . ov_fmin((int)$sa['end']) . ' assigned=' . count((array)($sa['assigned'] ?? []));
                }
            }

            $diaglegacy = ov_load_legacy_time_fallback_sessions($srcclasses);
            foreach ($diaglegacy as $cid => $rowslegacy) {
                if (empty($rowslegacy) || !empty($validschedule[(int)$cid])) {
                    continue;
                }
                $validschedule[(int)$cid] = true;
                if (!isset($scheduletexts[(int)$cid])) {
                    $scheduletexts[(int)$cid] = [];
                }
                foreach ($rowslegacy as $sl) {
                    $scheduletexts[(int)$cid][] = '[LEGACY] ' . ov_day((int)$sl['day']) . ' ' . ov_fmin((int)$sl['start']) . '-' . ov_fmin((int)$sl['end']);
                }
            }

            foreach ($srcrows as $classid => $src) {
                $class = $srcclasses[$classid] ?? null;
                if (!$class) {
                    continue;
                }
                $statusok = ((int)$class->approved === 1 && (int)$class->closed === 0);
                $windowok = ((int)$runningonly !== 1) || ((int)$class->initdate <= $now && (int)$class->enddate >= $now);
                $schedok = !empty($validschedule[(int)$classid]);

                $reason = [];
                if (!$statusok) {
                    $reasoncounts['status']++;
                    $reason[] = 'status';
                }
                if ($statusok && !$windowok) {
                    $reasoncounts['window']++;
                    $reason[] = 'window';
                }
                if ($statusok && $windowok && !$schedok) {
                    $reasoncounts['schedule']++;
                    $reason[] = 'schedule';
                }
                if ($statusok && $windowok && $schedok) {
                    $reasoncounts['ok']++;
                    $reason[] = 'ok';
                }

                if (count($sample) < 12) {
                    $sample[] = [
                        'id' => (int)$classid,
                        'name' => (string)$class->name,
                        'periodid' => (int)$class->periodid,
                        'approved' => (int)$class->approved,
                        'closed' => (int)$class->closed,
                        'fromgroup' => (int)$src->fromgroup,
                        'fromprogre' => (int)$src->fromprogre,
                        'fromqueue' => (int)$src->fromqueue,
                        'fromprereg' => (int)$src->fromprereg,
                        'schedules' => implode(' | ', $scheduletexts[(int)$classid] ?? []),
                        'reason' => implode(',', $reason),
                    ];
                }
            }
        }

        $studentdiagnostic = [
            'user' => $studentcandidate,
            'sourcecount' => count($srcrows),
            'reasons' => $reasoncounts,
            'sample' => $sample,
        ];
    }
}

echo $OUTPUT->header();
?>
<style>
.ov-wrap{background:#f6f8fc;border:1px solid #d7e1f1;border-radius:10px;padding:14px}
.ov-head{margin:0 0 10px;font-size:24px;font-weight:800;color:#1a355b}
.ov-grid{display:grid;grid-template-columns:1.4fr .7fr .9fr .6fr auto;gap:8px;align-items:end}.ov-grid label{font-size:12px;color:#4b6385;font-weight:700;display:block;margin-bottom:4px}
.ov-grid input,.ov-grid select{width:100%;border:1px solid #c6d4ea;border-radius:7px;padding:7px 9px}.ov-btn{border:0;border-radius:7px;padding:8px 10px;background:#1f65dc;color:#fff;font-weight:700;cursor:pointer}.ov-btn.warn{background:#9e6100}.ov-btn.err{background:#b72a2a}.ov-btn.gray{background:#566b8b}
.ov-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin:10px 0}.ov-card{background:#fff;border:1px solid #d7e1f1;border-radius:8px;padding:8px}.ov-l{font-size:11px;text-transform:uppercase;color:#587093;font-weight:700}.ov-v{font-size:22px;font-weight:800;color:#18375e}
.ov-alert{margin:8px 0;padding:8px;border-radius:8px;border:1px solid #cde4d5;background:#ecf8ef;color:#1b6b3e}.ov-alert.err{border-color:#f0cccc;background:#fff1f1;color:#8a2626}
.ov-table-wrap{background:#fff;border:1px solid #d7e1f1;border-radius:8px;overflow:auto}.ov-bulk{padding:8px;border-bottom:1px solid #d7e1f1;background:#f5f8ff;display:flex;flex-wrap:wrap;gap:6px;align-items:center}.ov-table{width:100%;min-width:1180px;border-collapse:collapse}.ov-table th{background:#edf3ff;border-bottom:1px solid #d7e1f1;padding:8px;font-size:11px;color:#2d4b72;text-transform:uppercase;text-align:left;position:sticky;top:0}.ov-table td{border-bottom:1px solid #edf2fa;padding:8px;vertical-align:top;font-size:13px}
.ov-tag{display:inline-block;border-radius:999px;padding:2px 7px;font-size:11px;background:#ecf2fb;color:#365475;font-weight:700;margin:3px 4px 0 0}.ov-win{display:inline-block;border-radius:999px;padding:2px 8px;font-size:11px;background:#fff2dc;border:1px solid #f1ddb9;color:#8a5400;font-weight:700;margin:2px 3px 2px 0}.ov-actions{display:flex;flex-wrap:wrap;gap:6px}.ov-actions .ov-btn{padding:6px 8px;font-size:12px}
@media (max-width:1200px){.ov-grid{grid-template-columns:1fr 1fr}.ov-stats{grid-template-columns:1fr 1fr}}
</style>
<div class="ov-wrap">
    <h2 class="ov-head">Analitica de Solapamientos</h2>
    <div class="ov-alert">Analisis global activo: sin filtros, todos los periodos, incluyendo pendientes. Build: global-attendance-fallback-v2</div>

    <?php if ($flash): ?><div class="ov-alert<?php echo ((int)($flash['error'] ?? 0) > 0 ? ' err' : ''); ?>">Resultado: <?php echo (int)($flash['ok'] ?? 0); ?> exitoso(s), <?php echo (int)($flash['error'] ?? 0); ?> error(es).<?php if (!empty($flash['messages'])): ?><ul style="margin:6px 0 0 18px"><?php foreach ($flash['messages'] as $m): ?><li><?php echo s((string)$m); ?></li><?php endforeach; ?></ul><?php endif; ?></div><?php endif; ?>
    <?php if (!empty($schemawarning)): ?><div class="ov-alert err"><?php echo s($schemawarning); ?></div><?php endif; ?>
    <?php if ($truncated): ?><div class="ov-alert err">Se alcanzo el limite de <?php echo (int)$maxconflicts; ?> conflictos.</div><?php endif; ?>

    <div class="ov-stats">
        <div class="ov-card"><div class="ov-l">Clases analizadas</div><div class="ov-v"><?php echo (int)$stats['classes']; ?></div></div>
        <div class="ov-card"><div class="ov-l">Estudiantes detectados</div><div class="ov-v"><?php echo (int)$stats['students']; ?></div></div>
        <div class="ov-card"><div class="ov-l">Estudiantes con choque</div><div class="ov-v"><?php echo (int)$stats['studentswithconflicts']; ?></div></div>
        <div class="ov-card"><div class="ov-l">Conflictos</div><div class="ov-v"><?php echo (int)$stats['conflicts']; ?></div></div>
    </div>

    <?php if (empty($rows)): ?>
        <div class="ov-alert">No se detectaron solapamientos.</div>
    <?php else: ?>
        <form method="post" class="ov-table-wrap">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <div class="ov-bulk">
                <strong>Accion masiva:</strong>
                <button class="ov-btn" type="submit" name="op" value="bulk_suggested">Retirar seleccionadas (Sugerida)</button>
                <button class="ov-btn warn" type="submit" name="op" value="bulk_a">Retirar seleccionadas (Clase A)</button>
                <button class="ov-btn err" type="submit" name="op" value="bulk_b">Retirar seleccionadas (Clase B)</button>
            </div>
            <table class="ov-table">
                <thead><tr><th><input type="checkbox" id="ov-check-all"></th><th>Estudiante</th><th>Choques</th><th>Clase A</th><th>Clase B</th><th>Sugerida</th><th>Acciones</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php
                        $u = $row['user']; $a = $row['classa']; $b = $row['classb']; $s = $row['suggested'];
                        $pa = $row['proga']; $pb = $row['progb'];
                        $sel = (int)$row['userid'] . '|' . (int)$a->id . '|' . (int)$row['lpa'] . '|' . (int)$b->id . '|' . (int)$row['lpb'] . '|' . (int)$s['classid'];
                    ?>
                    <tr>
                        <td>
                            <input type="checkbox" class="ov-check-item" name="selected[]" value="<?php echo s($sel); ?>">
                            <input type="hidden" name="allrows[]" value="<?php echo s($sel); ?>">
                        </td>
                        <td><strong><?php echo s(trim((string)$u->firstname . ' ' . (string)$u->lastname)); ?></strong><br><small>uid=<?php echo (int)$row['userid']; ?> | idnumber=<?php echo s((string)($u->idnumber ?? '-')); ?><br><?php echo s((string)($u->email ?? '-')); ?></small></td>
                        <td><?php foreach ($row['windows'] as $w): ?><span class="ov-win"><?php echo s((string)$w); ?></span><?php endforeach; ?></td>
                        <td><strong>#<?php echo (int)$a->id; ?> <?php echo s((string)$a->name); ?></strong><br><small>Periodo: <?php echo s((string)($a->periodname ?? ('ID ' . (int)$a->periodid))); ?><br>Plan: <?php echo s((string)($a->learningplanname ?? '-')); ?><br>Docente: <?php echo s(trim((string)($a->instructorfirstname ?? '') . ' ' . (string)($a->instructorlastname ?? '')) ?: '-'); ?></small><br><span class="ov-tag">Score <?php echo (int)$s['scorea']; ?></span><span class="ov-tag">Avance <?php echo (float)($pa->progress ?? 0); ?>%</span><span class="ov-tag">Nota <?php echo (float)($pa->grade ?? 0); ?></span><?php if (!empty($row['fromgroupa'])): ?><span class="ov-tag">grupo</span><?php endif; ?><?php if (!empty($row['fromprogrea'])): ?><span class="ov-tag">progreso</span><?php endif; ?><?php if (!empty($row['fromqueuea'])): ?><span class="ov-tag">queue</span><?php endif; ?><?php if (!empty($row['fromprerega'])): ?><span class="ov-tag">pre-reg</span><?php endif; ?></td>
                        <td><strong>#<?php echo (int)$b->id; ?> <?php echo s((string)$b->name); ?></strong><br><small>Periodo: <?php echo s((string)($b->periodname ?? ('ID ' . (int)$b->periodid))); ?><br>Plan: <?php echo s((string)($b->learningplanname ?? '-')); ?><br>Docente: <?php echo s(trim((string)($b->instructorfirstname ?? '') . ' ' . (string)($b->instructorlastname ?? '')) ?: '-'); ?></small><br><span class="ov-tag">Score <?php echo (int)$s['scoreb']; ?></span><span class="ov-tag">Avance <?php echo (float)($pb->progress ?? 0); ?>%</span><span class="ov-tag">Nota <?php echo (float)($pb->grade ?? 0); ?></span><?php if (!empty($row['fromgroupb'])): ?><span class="ov-tag">grupo</span><?php endif; ?><?php if (!empty($row['fromprogreb'])): ?><span class="ov-tag">progreso</span><?php endif; ?><?php if (!empty($row['fromqueueb'])): ?><span class="ov-tag">queue</span><?php endif; ?><?php if (!empty($row['frompreregb'])): ?><span class="ov-tag">pre-reg</span><?php endif; ?></td>
                        <td><span class="ov-tag">Retirar #<?php echo (int)$s['classid']; ?></span><br><small><?php echo s((string)$s['reason']); ?></small></td>
                        <td>
                            <div class="ov-actions">
                                <button class="ov-btn" type="submit" name="rowop" value="<?php echo s('withdraw_suggested|' . $sel); ?>" data-confirm="Retirar sugerida para este estudiante?">Sugerida</button>
                                <button class="ov-btn warn" type="submit" name="rowop" value="<?php echo s('withdraw_a|' . $sel); ?>" data-confirm="Retirar Clase A para este estudiante?">Retirar A</button>
                                <button class="ov-btn err" type="submit" name="rowop" value="<?php echo s('withdraw_b|' . $sel); ?>" data-confirm="Retirar Clase B para este estudiante?">Retirar B</button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </form>
    <?php endif; ?>
</div>
<script>
(function() {
    var all = document.getElementById('ov-check-all');
    if (all) {
        all.addEventListener('change', function() {
            document.querySelectorAll('.ov-check-item').forEach(function(it) { it.checked = !!all.checked; });
        });
    }
    document.querySelectorAll('form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            var btn = e.submitter || null;
            if (!btn) { return; }
            var msg = btn.getAttribute('data-confirm');
            if (msg && !window.confirm(msg)) {
                e.preventDefault();
                return;
            }
            if ((btn.value || '').indexOf('bulk_') === 0) {
                var selected = document.querySelectorAll('.ov-check-item:checked');
                var count = selected.length;
                if (!count) {
                    count = document.querySelectorAll('.ov-check-item').length;
                }
                if (!count) {
                    e.preventDefault();
                    window.alert('No hay conflictos para procesar.');
                    return;
                }
                if (!window.confirm('Ejecutar accion masiva sobre ' + count + ' conflicto(s)?')) {
                    e.preventDefault();
                }
            }
        });
    });

})();
</script>
<?php
echo $OUTPUT->footer();
