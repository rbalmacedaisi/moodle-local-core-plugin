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

function ov_withdraw($classid, $userid, $learningplanid) {
    try {
        $r = \local_grupomakro_core\external\schedule\withdraw_student::execute((int)$classid, (int)$userid, (int)$learningplanid);
        return is_array($r) ? $r : ['status' => 'error', 'message' => 'Respuesta invalida'];
    } catch (Throwable $e) {
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
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

$periodid = optional_param('periodid', 0, PARAM_INT);
$studentq = optional_param('studentq', '', PARAM_TEXT);
$runningonly = optional_param('runningonly', 1, PARAM_INT);
$maxconflicts = min(max(optional_param('maxconflicts', 600, PARAM_INT), 50), 5000);

$periods = $DB->get_records('gmk_academic_periods', [], 'startdate DESC, id DESC', 'id,name,startdate,enddate,status');
if ($periodid <= 0) {
    foreach ($periods as $p) {
        if ((int)$p->status === 1) {
            $periodid = (int)$p->id;
            break;
        }
    }
    if ($periodid <= 0 && !empty($periods)) {
        $periodid = (int)reset($periods)->id;
    }
}

$base = ['periodid' => $periodid, 'studentq' => $studentq, 'runningonly' => $runningonly, 'maxconflicts' => $maxconflicts];

if (data_submitted() && confirm_sesskey()) {
    $op = optional_param('op', '', PARAM_ALPHA);
    if ($op !== '') {
        $targets = [];
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
            foreach (optional_param_array('selected', [], PARAM_RAW_TRIMMED) as $sel) {
                $parts = explode('|', (string)$sel);
                if (count($parts) !== 6) {
                    continue;
                }
                $uid = (int)$parts[0];
                $ca = (int)$parts[1];
                $lpa = (int)$parts[2];
                $cb = (int)$parts[3];
                $lpb = (int)$parts[4];
                $suggested = (int)$parts[5];
                if ($uid <= 0) {
                    continue;
                }
                $targetclass = $ca;
                $targetlp = $lpa;
                if ($op === 'bulk_b') {
                    $targetclass = $cb;
                    $targetlp = $lpb;
                } else if ($op === 'bulk_suggested' && $suggested > 0) {
                    $targetclass = $suggested;
                    $targetlp = ($targetclass === $ca) ? $lpa : $lpb;
                }
                $targets[] = ['userid' => $uid, 'classid' => $targetclass, 'learningplanid' => $targetlp];
            }
        }

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
$stats = ['classes' => 0, 'students' => 0, 'studentswithconflicts' => 0, 'conflicts' => 0];
$selectedperiod = $periods[$periodid] ?? null;

if ($selectedperiod) {
    $now = time();
    $sql = "SELECT c.id,c.name,c.periodid,c.learningplanid,c.shift,c.initdate,c.enddate,c.groupid,c.approved,c.closed,c.instructorid,
                   lp.name AS learningplanname,u.firstname AS instructorfirstname,u.lastname AS instructorlastname
              FROM {gmk_class} c
         LEFT JOIN {local_learning_plans} lp ON lp.id=c.learningplanid
         LEFT JOIN {user} u ON u.id=c.instructorid
             WHERE c.periodid=:p AND c.approved=1 AND c.closed=0";
    $params = ['p' => $periodid];
    if ((int)$runningonly === 1) {
        $sql .= " AND c.initdate<=:n1 AND c.enddate>=:n2";
        $params['n1'] = $now;
        $params['n2'] = $now;
    }
    $classes = $DB->get_records_sql($sql, $params);
    $stats['classes'] = count($classes);
    if (!empty($classes)) {
        $classids = array_map('intval', array_keys($classes));
        list($ins1, $par1) = $DB->get_in_or_equal($classids, SQL_PARAMS_NAMED, 'c1');
        $schedules = $DB->get_records_sql("SELECT classid,day,starttime,endtime FROM {gmk_class_schedules} WHERE classid $ins1 ORDER BY classid,day,starttime", $par1);
        $sched = [];
        foreach ($schedules as $s) {
            $start = ov_tmin($s->starttime);
            $end = ov_tmin($s->endtime);
            if ($start < 0 || $end <= $start) {
                continue;
            }
            $sched[(int)$s->classid][] = ['day' => (int)$s->day, 'start' => $start, 'end' => $end];
        }

        list($ins2a, $par2a) = $DB->get_in_or_equal($classids, SQL_PARAMS_NAMED, 'ca');
        list($ins2b, $par2b) = $DB->get_in_or_equal($classids, SQL_PARAMS_NAMED, 'cb');
        $links = $DB->get_records_sql(
            "SELECT x.classid,x.userid,MAX(x.learningplanid) AS learningplanid,MAX(x.fromgroup) AS fromgroup,MAX(x.fromprogre) AS fromprogre
               FROM (
                    SELECT c.id AS classid,gm.userid,0 AS learningplanid,1 AS fromgroup,0 AS fromprogre FROM {gmk_class} c JOIN {groups_members} gm ON gm.groupid=c.groupid WHERE c.id $ins2a
                    UNION ALL
                    SELECT cp.classid,cp.userid,cp.learningplanid,0 AS fromgroup,1 AS fromprogre FROM {gmk_course_progre} cp WHERE cp.classid $ins2b AND cp.status=:st
               ) x
              GROUP BY x.classid,x.userid",
            $par2a + $par2b + ['st' => COURSE_IN_PROGRESS]
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
            $meta[$uid . ':' . $cid] = ['learningplanid' => (int)$l->learningplanid, 'fromgroup' => (int)$l->fromgroup, 'fromprogre' => (int)$l->fromprogre];
        }
        $stats['students'] = count($studentclasses);

        $users = [];
        $activeplans = [];
        $progress = [];
        if (!empty($userset)) {
            list($uins, $upar) = $DB->get_in_or_equal(array_values($userset), SQL_PARAMS_NAMED, 'u');
            $users = $DB->get_records_sql("SELECT id,firstname,lastname,idnumber,email,username FROM {user} WHERE deleted=0 AND id $uins", $upar);
            $lp = $DB->get_records_sql("SELECT userid,learningplanid FROM {local_learning_users} WHERE userid $uins AND status=:active", $upar + ['active' => 'activo']);
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
                            $wins[ov_day((int)$sa['day']) . ' ' . ov_fmin($st) . '-' . ov_fmin($en)] = true;
                        }
                    }
                    if (empty($wins)) {
                        continue;
                    }
                    $k = $uid . '|' . $a . '|' . $b;
                    if (!isset($conflicts[$k])) {
                        $ma = $meta[$uid . ':' . $a] ?? ['learningplanid' => 0, 'fromgroup' => 0, 'fromprogre' => 0];
                        $mb = $meta[$uid . ':' . $b] ?? ['learningplanid' => 0, 'fromgroup' => 0, 'fromprogre' => 0];
                        $pa = $progress[$uid . ':' . $a] ?? null;
                        $pb = $progress[$uid . ':' . $b] ?? null;
                        $conflicts[$k] = [
                            'userid' => (int)$uid, 'user' => $users[$uid], 'classa' => $classes[$a], 'classb' => $classes[$b], 'proga' => $pa, 'progb' => $pb,
                            'fromgroupa' => (int)$ma['fromgroup'], 'fromprogrea' => (int)$ma['fromprogre'], 'fromgroupb' => (int)$mb['fromgroup'], 'fromprogreb' => (int)$mb['fromprogre'],
                            'lpa' => (int)($ma['learningplanid'] ?: ($pa->learningplanid ?? 0) ?: ($classes[$a]->learningplanid ?? 0)),
                            'lpb' => (int)($mb['learningplanid'] ?: ($pb->learningplanid ?? 0) ?: ($classes[$b]->learningplanid ?? 0)),
                            'windows' => []
                        ];
                    }
                    foreach (array_keys($wins) as $w) {
                        $conflicts[$k]['windows'][$w] = true;
                    }
                    if (count($conflicts) >= $maxconflicts) {
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

echo $OUTPUT->header();
?>
<style>
.ov-wrap{background:#f6f8fc;border:1px solid #d7e1f1;border-radius:10px;padding:14px}
.ov-head{margin:0 0 10px;font-size:24px;font-weight:800;color:#1a355b}
.ov-grid{display:grid;grid-template-columns:1.2fr 1.2fr .6fr .6fr auto;gap:8px;align-items:end}.ov-grid label{font-size:12px;color:#4b6385;font-weight:700;display:block;margin-bottom:4px}
.ov-grid input,.ov-grid select{width:100%;border:1px solid #c6d4ea;border-radius:7px;padding:7px 9px}.ov-btn{border:0;border-radius:7px;padding:8px 10px;background:#1f65dc;color:#fff;font-weight:700;cursor:pointer}.ov-btn.warn{background:#9e6100}.ov-btn.err{background:#b72a2a}.ov-btn.gray{background:#566b8b}
.ov-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin:10px 0}.ov-card{background:#fff;border:1px solid #d7e1f1;border-radius:8px;padding:8px}.ov-l{font-size:11px;text-transform:uppercase;color:#587093;font-weight:700}.ov-v{font-size:22px;font-weight:800;color:#18375e}
.ov-alert{margin:8px 0;padding:8px;border-radius:8px;border:1px solid #cde4d5;background:#ecf8ef;color:#1b6b3e}.ov-alert.err{border-color:#f0cccc;background:#fff1f1;color:#8a2626}
.ov-table-wrap{background:#fff;border:1px solid #d7e1f1;border-radius:8px;overflow:auto}.ov-bulk{padding:8px;border-bottom:1px solid #d7e1f1;background:#f5f8ff;display:flex;flex-wrap:wrap;gap:6px;align-items:center}.ov-table{width:100%;min-width:1180px;border-collapse:collapse}.ov-table th{background:#edf3ff;border-bottom:1px solid #d7e1f1;padding:8px;font-size:11px;color:#2d4b72;text-transform:uppercase;text-align:left;position:sticky;top:0}.ov-table td{border-bottom:1px solid #edf2fa;padding:8px;vertical-align:top;font-size:13px}
.ov-tag{display:inline-block;border-radius:999px;padding:2px 7px;font-size:11px;background:#ecf2fb;color:#365475;font-weight:700;margin:3px 4px 0 0}.ov-win{display:inline-block;border-radius:999px;padding:2px 8px;font-size:11px;background:#fff2dc;border:1px solid #f1ddb9;color:#8a5400;font-weight:700;margin:2px 3px 2px 0}.ov-actions{display:flex;flex-wrap:wrap;gap:6px}.ov-actions .ov-btn{padding:6px 8px;font-size:12px}
@media (max-width:1200px){.ov-grid{grid-template-columns:1fr 1fr}.ov-stats{grid-template-columns:1fr 1fr}}
</style>
<div class="ov-wrap">
    <h2 class="ov-head">Analitica de Solapamientos</h2>
    <form method="get" class="ov-grid">
        <div><label>Periodo</label><select name="periodid"><?php foreach ($periods as $p): ?><option value="<?php echo (int)$p->id; ?>" <?php echo ((int)$p->id === (int)$periodid ? 'selected' : ''); ?>><?php echo s((string)$p->name) . ' (' . userdate((int)$p->startdate, '%d/%m/%Y') . ' - ' . userdate((int)$p->enddate, '%d/%m/%Y') . ')'; ?></option><?php endforeach; ?></select></div>
        <div><label>Estudiante (nombre/cedula/correo/usuario)</label><input type="text" name="studentq" value="<?php echo s($studentq); ?>" /></div>
        <div><label>Solo en curso</label><select name="runningonly"><option value="1" <?php echo ((int)$runningonly === 1 ? 'selected' : ''); ?>>Si</option><option value="0" <?php echo ((int)$runningonly === 0 ? 'selected' : ''); ?>>No</option></select></div>
        <div><label>Max conflictos</label><input type="number" min="50" max="5000" name="maxconflicts" value="<?php echo (int)$maxconflicts; ?>" /></div>
        <div><button class="ov-btn" type="submit">Analizar</button> <a class="ov-btn gray" style="text-decoration:none;display:inline-block" href="<?php echo (new moodle_url('/local/grupomakro_core/pages/overlap_analytics.php'))->out(false); ?>">Limpiar</a></div>
    </form>

    <?php if ($flash): ?><div class="ov-alert<?php echo ((int)($flash['error'] ?? 0) > 0 ? ' err' : ''); ?>">Resultado: <?php echo (int)($flash['ok'] ?? 0); ?> exitoso(s), <?php echo (int)($flash['error'] ?? 0); ?> error(es).<?php if (!empty($flash['messages'])): ?><ul style="margin:6px 0 0 18px"><?php foreach ($flash['messages'] as $m): ?><li><?php echo s((string)$m); ?></li><?php endforeach; ?></ul><?php endif; ?></div><?php endif; ?>
    <?php if ($truncated): ?><div class="ov-alert err">Se alcanzo el limite de <?php echo (int)$maxconflicts; ?> conflictos. Ajusta filtros.</div><?php endif; ?>

    <div class="ov-stats">
        <div class="ov-card"><div class="ov-l">Clases analizadas</div><div class="ov-v"><?php echo (int)$stats['classes']; ?></div></div>
        <div class="ov-card"><div class="ov-l">Estudiantes detectados</div><div class="ov-v"><?php echo (int)$stats['students']; ?></div></div>
        <div class="ov-card"><div class="ov-l">Estudiantes con choque</div><div class="ov-v"><?php echo (int)$stats['studentswithconflicts']; ?></div></div>
        <div class="ov-card"><div class="ov-l">Conflictos</div><div class="ov-v"><?php echo (int)$stats['conflicts']; ?></div></div>
    </div>

    <?php if (empty($rows)): ?>
        <div class="ov-alert">No se detectaron solapamientos para los filtros actuales.</div>
    <?php else: ?>
        <form method="post" class="ov-table-wrap">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="periodid" value="<?php echo (int)$periodid; ?>">
            <input type="hidden" name="studentq" value="<?php echo s($studentq); ?>">
            <input type="hidden" name="runningonly" value="<?php echo (int)$runningonly; ?>">
            <input type="hidden" name="maxconflicts" value="<?php echo (int)$maxconflicts; ?>">
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
                        <td><input type="checkbox" class="ov-check-item" name="selected[]" value="<?php echo s($sel); ?>"></td>
                        <td><strong><?php echo s(trim((string)$u->firstname . ' ' . (string)$u->lastname)); ?></strong><br><small>uid=<?php echo (int)$row['userid']; ?> | idnumber=<?php echo s((string)($u->idnumber ?? '-')); ?><br><?php echo s((string)($u->email ?? '-')); ?></small></td>
                        <td><?php foreach ($row['windows'] as $w): ?><span class="ov-win"><?php echo s((string)$w); ?></span><?php endforeach; ?></td>
                        <td><strong>#<?php echo (int)$a->id; ?> <?php echo s((string)$a->name); ?></strong><br><small>Plan: <?php echo s((string)($a->learningplanname ?? '-')); ?><br>Docente: <?php echo s(trim((string)($a->instructorfirstname ?? '') . ' ' . (string)($a->instructorlastname ?? '')) ?: '-'); ?></small><br><span class="ov-tag">Score <?php echo (int)$s['scorea']; ?></span><span class="ov-tag">Avance <?php echo (float)($pa->progress ?? 0); ?>%</span><span class="ov-tag">Nota <?php echo (float)($pa->grade ?? 0); ?></span></td>
                        <td><strong>#<?php echo (int)$b->id; ?> <?php echo s((string)$b->name); ?></strong><br><small>Plan: <?php echo s((string)($b->learningplanname ?? '-')); ?><br>Docente: <?php echo s(trim((string)($b->instructorfirstname ?? '') . ' ' . (string)($b->instructorlastname ?? '')) ?: '-'); ?></small><br><span class="ov-tag">Score <?php echo (int)$s['scoreb']; ?></span><span class="ov-tag">Avance <?php echo (float)($pb->progress ?? 0); ?>%</span><span class="ov-tag">Nota <?php echo (float)($pb->grade ?? 0); ?></span></td>
                        <td><span class="ov-tag">Retirar #<?php echo (int)$s['classid']; ?></span><br><small><?php echo s((string)$s['reason']); ?></small></td>
                        <td>
                            <div class="ov-actions">
                                <button class="ov-btn ov-row-action" type="button" data-op="withdraw_suggested" data-confirm="Retirar sugerida para este estudiante?"
                                    data-userid="<?php echo (int)$row['userid']; ?>" data-classida="<?php echo (int)$a->id; ?>" data-classidb="<?php echo (int)$b->id; ?>"
                                    data-lpa="<?php echo (int)$row['lpa']; ?>" data-lpb="<?php echo (int)$row['lpb']; ?>" data-suggested="<?php echo (int)$s['classid']; ?>">Sugerida</button>
                                <button class="ov-btn warn ov-row-action" type="button" data-op="withdraw_a" data-confirm="Retirar Clase A para este estudiante?"
                                    data-userid="<?php echo (int)$row['userid']; ?>" data-classida="<?php echo (int)$a->id; ?>" data-classidb="<?php echo (int)$b->id; ?>"
                                    data-lpa="<?php echo (int)$row['lpa']; ?>" data-lpb="<?php echo (int)$row['lpb']; ?>" data-suggested="<?php echo (int)$s['classid']; ?>">Retirar A</button>
                                <button class="ov-btn err ov-row-action" type="button" data-op="withdraw_b" data-confirm="Retirar Clase B para este estudiante?"
                                    data-userid="<?php echo (int)$row['userid']; ?>" data-classida="<?php echo (int)$a->id; ?>" data-classidb="<?php echo (int)$b->id; ?>"
                                    data-lpa="<?php echo (int)$row['lpa']; ?>" data-lpb="<?php echo (int)$row['lpb']; ?>" data-suggested="<?php echo (int)$s['classid']; ?>">Retirar B</button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </form>
        <form method="post" id="ov-row-form" style="display:none">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="periodid" value="<?php echo (int)$periodid; ?>">
            <input type="hidden" name="studentq" value="<?php echo s($studentq); ?>">
            <input type="hidden" name="runningonly" value="<?php echo (int)$runningonly; ?>">
            <input type="hidden" name="maxconflicts" value="<?php echo (int)$maxconflicts; ?>">
            <input type="hidden" name="op" value="">
            <input type="hidden" name="userid" value="">
            <input type="hidden" name="classida" value="">
            <input type="hidden" name="classidb" value="">
            <input type="hidden" name="lpa" value="">
            <input type="hidden" name="lpb" value="">
            <input type="hidden" name="suggested" value="">
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
                if (!selected.length) {
                    e.preventDefault();
                    window.alert('Selecciona al menos un conflicto.');
                    return;
                }
                if (!window.confirm('Ejecutar accion masiva sobre ' + selected.length + ' conflicto(s)?')) {
                    e.preventDefault();
                }
            }
        });
    });

    var rowForm = document.getElementById('ov-row-form');
    document.querySelectorAll('.ov-row-action').forEach(function(btn) {
        btn.addEventListener('click', function() {
            if (!rowForm) { return; }
            var msg = btn.getAttribute('data-confirm');
            if (msg && !window.confirm(msg)) {
                return;
            }
            rowForm.querySelector('input[name="op"]').value = btn.getAttribute('data-op') || '';
            rowForm.querySelector('input[name="userid"]').value = btn.getAttribute('data-userid') || '';
            rowForm.querySelector('input[name="classida"]').value = btn.getAttribute('data-classida') || '';
            rowForm.querySelector('input[name="classidb"]').value = btn.getAttribute('data-classidb') || '';
            rowForm.querySelector('input[name="lpa"]').value = btn.getAttribute('data-lpa') || '';
            rowForm.querySelector('input[name="lpb"]').value = btn.getAttribute('data-lpb') || '';
            rowForm.querySelector('input[name="suggested"]').value = btn.getAttribute('data-suggested') || '';
            rowForm.submit();
        });
    });
})();
</script>
<?php
echo $OUTPUT->footer();
