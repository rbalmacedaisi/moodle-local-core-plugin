<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('grupomakro_core_debug_overlap_student');
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_overlap_student_trace.php'));
$PAGE->set_title('Debug overlap student trace');
$PAGE->set_heading('Debug overlap student trace');
$PAGE->set_pagelayout('admin');

function dbg_norm($v) {
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

function dbg_tmin($v) {
    if (!preg_match('/^(\d{1,2}):(\d{2})/', trim((string)$v), $m)) {
        return -1;
    }
    return ((int)$m[1] * 60) + (int)$m[2];
}

function dbg_fmin($v) {
    return sprintf('%02d:%02d', (int)floor($v / 60), (int)$v % 60);
}

function dbg_day_to_int($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return 0;
    }
    if (is_numeric($value)) {
        $d = (int)$value;
        return ($d >= 1 && $d <= 7) ? $d : 0;
    }
    $n = dbg_norm($value);
    $map = [
        'lunes' => 1, 'lun' => 1,
        'martes' => 2, 'mar' => 2,
        'miercoles' => 3, 'mier' => 3, 'mie' => 3,
        'jueves' => 4, 'jue' => 4,
        'viernes' => 5, 'vie' => 5,
        'sabado' => 6, 'sab' => 6,
        'domingo' => 7, 'dom' => 7
    ];
    return $map[$n] ?? 0;
}

function dbg_day_label($d) {
    static $days = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miercoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sabado', 7 => 'Domingo'];
    return $days[(int)$d] ?? ('Dia ' . (int)$d);
}

function dbg_date_overlap($a, $b) {
    $as = (int)($a->initdate ?? 0);
    $ae = (int)($a->enddate ?? 0);
    $bs = (int)($b->initdate ?? 0);
    $be = (int)($b->enddate ?? 0);
    if ($as > 0 && $ae > 0 && $bs > 0 && $be > 0) {
        return $as <= $be && $ae >= $bs;
    }
    return true;
}

function dbg_sched_cols() {
    global $DB;
    $cols = $DB->get_columns('gmk_class_schedules');
    if (empty($cols)) {
        return [null, null, null];
    }
    $keys = array_map('strtolower', array_keys($cols));
    $find = function(array $cands) use ($keys) {
        foreach ($cands as $c) {
            if (in_array(strtolower($c), $keys, true)) {
                return $c;
            }
        }
        return null;
    };
    return [
        $find(['day', 'weekday']),
        $find(['start_time', 'starttime', 'inittime', 'start']),
        $find(['end_time', 'endtime', 'end'])
    ];
}

function dbg_build_conflicts(array $classids, array $classes, array $sched) {
    $out = [];
    $classids = array_values(array_unique(array_map('intval', $classids)));
    sort($classids);
    $n = count($classids);
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $a = $classids[$i];
            $b = $classids[$j];
            if (!isset($classes[$a], $classes[$b])) {
                continue;
            }
            if (empty($sched[$a]) || empty($sched[$b])) {
                continue;
            }
            if (!dbg_date_overlap($classes[$a], $classes[$b])) {
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
                    $wins[] = dbg_day_label((int)$sa['day']) . ' ' . dbg_fmin($st) . '-' . dbg_fmin($en);
                }
            }
            if (empty($wins)) {
                continue;
            }
            $k = $a . '|' . $b;
            $out[$k] = ['a' => $a, 'b' => $b, 'windows' => array_values(array_unique($wins))];
        }
    }
    return $out;
}

$studentq = optional_param('studentq', '', PARAM_TEXT);
$userid = optional_param('userid', 0, PARAM_INT);
$runningonly = optional_param('runningonly', 1, PARAM_INT);
$periodid = optional_param('periodid', 0, PARAM_INT);

$periods = $DB->get_records('gmk_academic_periods', [], 'startdate DESC', 'id,name,startdate,enddate');
$matchingusers = [];
$selecteduser = null;

if (trim((string)$studentq) !== '') {
    $like = '%' . trim((string)$studentq) . '%';
    $matchingusers = $DB->get_records_sql(
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
       ORDER BY firstname ASC, lastname ASC",
        ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'q5' => $like],
        0,
        50
    );
}

if ($userid <= 0 && count($matchingusers) === 1) {
    $userid = (int)reset($matchingusers)->id;
}
if ($userid > 0) {
    $selecteduser = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], 'id,firstname,lastname,idnumber,email,username', IGNORE_MISSING);
}

$sourcebyclass = [];
$classes = [];
$classdiag = [];
$allconflicts = [];
$detectedconflicts = [];
$missedconflicts = [];
$schemawarning = '';

if ($selecteduser) {
    $now = time();

    $srcsql = "
        SELECT x.classid, x.userid, x.learningplanid, x.fromgroup, x.fromprogre, x.fromqueue, x.fromprereg
          FROM (
                SELECT c.id AS classid, gm.userid, 0 AS learningplanid, 1 AS fromgroup, 0 AS fromprogre, 0 AS fromqueue, 0 AS fromprereg
                  FROM {gmk_class} c
                  JOIN {groups_members} gm ON gm.groupid = c.groupid
                 WHERE gm.userid = :uid1
                UNION ALL
                SELECT cp.classid, cp.userid, cp.learningplanid, 0 AS fromgroup, 1 AS fromprogre, 0 AS fromqueue, 0 AS fromprereg
                  FROM {gmk_course_progre} cp
                 WHERE cp.userid = :uid2
                   AND cp.classid > 0
                UNION ALL
                SELECT q.classid, q.userid, 0 AS learningplanid, 0 AS fromgroup, 0 AS fromprogre, 1 AS fromqueue, 0 AS fromprereg
                  FROM {gmk_class_queue} q
                 WHERE q.userid = :uid3
                UNION ALL
                SELECT pr.classid, pr.userid, 0 AS learningplanid, 0 AS fromgroup, 0 AS fromprogre, 0 AS fromqueue, 1 AS fromprereg
                  FROM {gmk_class_pre_registration} pr
                 WHERE pr.userid = :uid4
          ) x";
    $srcset = $DB->get_recordset_sql($srcsql, ['uid1' => $selecteduser->id, 'uid2' => $selecteduser->id, 'uid3' => $selecteduser->id, 'uid4' => $selecteduser->id]);
    foreach ($srcset as $row) {
        $cid = (int)$row->classid;
        if ($cid <= 0) {
            continue;
        }
        if (!isset($sourcebyclass[$cid])) {
            $sourcebyclass[$cid] = [
                'fromgroup' => 0,
                'fromprogre' => 0,
                'fromqueue' => 0,
                'fromprereg' => 0,
                'learningplans' => []
            ];
        }
        $sourcebyclass[$cid]['fromgroup'] = max((int)$sourcebyclass[$cid]['fromgroup'], (int)$row->fromgroup);
        $sourcebyclass[$cid]['fromprogre'] = max((int)$sourcebyclass[$cid]['fromprogre'], (int)$row->fromprogre);
        $sourcebyclass[$cid]['fromqueue'] = max((int)$sourcebyclass[$cid]['fromqueue'], (int)$row->fromqueue);
        $sourcebyclass[$cid]['fromprereg'] = max((int)$sourcebyclass[$cid]['fromprereg'], (int)$row->fromprereg);
        $lp = (int)($row->learningplanid ?? 0);
        if ($lp > 0) {
            $sourcebyclass[$cid]['learningplans'][$lp] = $lp;
        }
    }
    $srcset->close();

    $classids = array_values(array_map('intval', array_keys($sourcebyclass)));
    if (!empty($classids)) {
        list($insql, $params) = $DB->get_in_or_equal($classids, SQL_PARAMS_NAMED, 'cid');
        $classes = $DB->get_records_sql(
            "SELECT c.id, c.name, c.periodid, c.learningplanid, c.initdate, c.enddate, c.approved, c.closed, c.shift,
                    ap.name AS periodname, lp.name AS learningplanname
               FROM {gmk_class} c
          LEFT JOIN {gmk_academic_periods} ap ON ap.id = c.periodid
          LEFT JOIN {local_learning_plans} lp ON lp.id = c.learningplanid
              WHERE c.id $insql",
            $params
        );

        list($daycol, $startcol, $endcol) = dbg_sched_cols();
        $sched = [];
        if (!empty($daycol) && !empty($startcol) && !empty($endcol)) {
            $schedset = $DB->get_recordset_sql(
                "SELECT classid, {$daycol} AS dayvalue, {$startcol} AS startvalue, {$endcol} AS endvalue
                   FROM {gmk_class_schedules}
                  WHERE classid $insql",
                $params
            );
            foreach ($schedset as $sr) {
                $day = dbg_day_to_int($sr->dayvalue ?? '');
                $start = dbg_tmin($sr->startvalue ?? '');
                $end = dbg_tmin($sr->endvalue ?? '');
                if ($day <= 0 || $start < 0 || $end <= $start) {
                    continue;
                }
                $sched[(int)$sr->classid][] = ['day' => $day, 'start' => $start, 'end' => $end];
            }
            $schedset->close();
        } else {
            $schemawarning = 'Schedule columns not resolved in gmk_class_schedules.';
        }

        $includedids = [];
        foreach ($sourcebyclass as $cid => $src) {
            $c = $classes[$cid] ?? null;
            $reasons = [];
            if (!$c) {
                $reasons[] = 'missing_class';
            } else {
                if (!((int)$c->approved === 1 && (int)$c->closed === 0)) {
                    $reasons[] = 'status';
                }
                if ((int)$periodid > 0 && (int)$c->periodid !== (int)$periodid) {
                    $reasons[] = 'period';
                }
                if ((int)$runningonly === 1 && !((int)$c->initdate <= $now && (int)$c->enddate >= $now)) {
                    $reasons[] = 'window';
                }
                if (empty($sched[$cid])) {
                    $reasons[] = 'schedule';
                }
            }
            $include = empty($reasons);
            if ($include) {
                $includedids[] = (int)$cid;
            }
            $classdiag[$cid] = ['include' => $include, 'reasons' => $reasons];
        }

        $allconflicts = dbg_build_conflicts($classids, $classes, $sched);
        $detectedconflicts = dbg_build_conflicts($includedids, $classes, $sched);
        foreach ($allconflicts as $k => $pair) {
            if (!isset($detectedconflicts[$k])) {
                $missedconflicts[$k] = $pair;
            }
        }
    }
}

echo $OUTPUT->header();
?>
<style>
.dbg-wrap{background:#f7f9fd;border:1px solid #dbe4f1;border-radius:10px;padding:14px}.dbg-grid{display:grid;grid-template-columns:1.3fr .8fr .8fr .8fr auto;gap:8px;align-items:end}.dbg-grid label{display:block;font-size:12px;color:#4c6384;font-weight:700;margin-bottom:4px}.dbg-grid input,.dbg-grid select{width:100%;border:1px solid #c7d5eb;border-radius:7px;padding:7px 9px}.dbg-btn{border:0;border-radius:7px;background:#1f65dc;color:#fff;padding:8px 11px;font-weight:700;cursor:pointer}.dbg-card{background:#fff;border:1px solid #dbe4f1;border-radius:8px;padding:10px;margin-top:10px}.dbg-card h4{margin:0 0 8px;font-size:14px}.dbg-table{width:100%;border-collapse:collapse;min-width:980px}.dbg-table th{background:#eef4ff;border-bottom:1px solid #dbe4f1;padding:8px;text-align:left;font-size:11px;text-transform:uppercase;color:#2f4c72}.dbg-table td{border-bottom:1px solid #edf2fa;padding:8px;font-size:13px;vertical-align:top}.tag{display:inline-block;border-radius:999px;padding:2px 7px;font-size:11px;background:#ecf2fb;color:#355173;font-weight:700;margin:2px 4px 2px 0}.ok{background:#e9f7ef;color:#1f6a44}.bad{background:#fdeaea;color:#962727}
</style>
<div class="dbg-wrap">
    <h2>Debug overlap student trace</h2>
    <form method="get" class="dbg-grid">
        <div><label>Student query</label><input type="text" name="studentq" value="<?php echo s($studentq); ?>" placeholder="Name, idnumber, email, username"></div>
        <div><label>User ID</label><input type="number" name="userid" value="<?php echo (int)$userid; ?>"></div>
        <div><label>Running only</label><select name="runningonly"><option value="1" <?php echo ((int)$runningonly === 1 ? 'selected' : ''); ?>>Yes</option><option value="0" <?php echo ((int)$runningonly === 0 ? 'selected' : ''); ?>>No</option></select></div>
        <div><label>Period filter</label><select name="periodid"><option value="0" <?php echo ((int)$periodid === 0 ? 'selected' : ''); ?>>All</option><?php foreach ($periods as $p): ?><option value="<?php echo (int)$p->id; ?>" <?php echo ((int)$periodid === (int)$p->id ? 'selected' : ''); ?>><?php echo s((string)$p->name); ?></option><?php endforeach; ?></select></div>
        <div><button class="dbg-btn" type="submit">Diagnose</button></div>
    </form>

    <?php if (!empty($matchingusers) && !$selecteduser): ?>
        <div class="dbg-card">
            <h4>Matching users (pick one)</h4>
            <div style="overflow:auto;">
                <table class="dbg-table"><thead><tr><th>ID</th><th>Name</th><th>ID Number</th><th>Email</th><th>Action</th></tr></thead><tbody>
                    <?php foreach ($matchingusers as $u): ?>
                        <?php $pick = new moodle_url('/local/grupomakro_core/pages/debug_overlap_student_trace.php', ['studentq' => $studentq, 'userid' => $u->id, 'runningonly' => $runningonly, 'periodid' => $periodid]); ?>
                        <tr><td><?php echo (int)$u->id; ?></td><td><?php echo s(trim((string)$u->firstname . ' ' . (string)$u->lastname)); ?></td><td><?php echo s((string)($u->idnumber ?? '-')); ?></td><td><?php echo s((string)($u->email ?? '-')); ?></td><td><a href="<?php echo $pick->out(false); ?>">Use</a></td></tr>
                    <?php endforeach; ?>
                </tbody></table>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($selecteduser): ?>
        <div class="dbg-card">
            <h4>Selected student</h4>
            <div><strong><?php echo s(trim((string)$selecteduser->firstname . ' ' . (string)$selecteduser->lastname)); ?></strong> | uid=<?php echo (int)$selecteduser->id; ?> | idnumber=<?php echo s((string)($selecteduser->idnumber ?? '-')); ?> | <?php echo s((string)($selecteduser->email ?? '-')); ?></div>
            <?php if ($schemawarning !== ''): ?><div style="margin-top:8px;" class="bad tag"><?php echo s($schemawarning); ?></div><?php endif; ?>
            <div style="margin-top:8px;">
                <span class="tag">Source classes: <?php echo count($sourcebyclass); ?></span>
                <span class="tag">All overlaps: <?php echo count($allconflicts); ?></span>
                <span class="tag ok">Detected by analytics filters: <?php echo count($detectedconflicts); ?></span>
                <span class="tag bad">Missed: <?php echo count($missedconflicts); ?></span>
            </div>
        </div>

        <div class="dbg-card">
            <h4>Class inclusion diagnostics</h4>
            <div style="overflow:auto;">
                <table class="dbg-table">
                    <thead><tr><th>ID</th><th>Class</th><th>Period</th><th>approved/closed</th><th>Date range</th><th>Sources</th><th>Included?</th><th>Reasons</th></tr></thead>
                    <tbody>
                    <?php foreach ($sourcebyclass as $cid => $src): ?>
                        <?php $c = $classes[$cid] ?? null; $d = $classdiag[$cid] ?? ['include' => false, 'reasons' => ['missing_diag']]; ?>
                        <tr>
                            <td><?php echo (int)$cid; ?></td>
                            <td><?php echo s((string)($c->name ?? 'Class not found')); ?></td>
                            <td><?php echo s((string)($c->periodname ?? ('ID ' . (int)($c->periodid ?? 0)))); ?></td>
                            <td><?php echo (int)($c->approved ?? 0); ?>/<?php echo (int)($c->closed ?? 0); ?></td>
                            <td><?php echo !empty($c->initdate) ? userdate((int)$c->initdate, '%d/%m/%Y') : '-'; ?> - <?php echo !empty($c->enddate) ? userdate((int)$c->enddate, '%d/%m/%Y') : '-'; ?></td>
                            <td>
                                <?php if (!empty($src['fromgroup'])): ?><span class="tag">group</span><?php endif; ?>
                                <?php if (!empty($src['fromprogre'])): ?><span class="tag">progre</span><?php endif; ?>
                                <?php if (!empty($src['fromqueue'])): ?><span class="tag">queue</span><?php endif; ?>
                                <?php if (!empty($src['fromprereg'])): ?><span class="tag">pre-reg</span><?php endif; ?>
                            </td>
                            <td><?php echo !empty($d['include']) ? '<span class="tag ok">YES</span>' : '<span class="tag bad">NO</span>'; ?></td>
                            <td><?php echo s(implode(', ', $d['reasons'] ?? [])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="dbg-card">
            <h4>Missed overlaps (exists but excluded by filters)</h4>
            <?php if (empty($missedconflicts)): ?>
                <div>No missed overlaps.</div>
            <?php else: ?>
                <div style="overflow:auto;">
                    <table class="dbg-table">
                        <thead><tr><th>Class A</th><th>Class B</th><th>Overlap windows</th><th>A reasons</th><th>B reasons</th></tr></thead>
                        <tbody>
                        <?php foreach ($missedconflicts as $m): ?>
                            <?php $a = $m['a']; $b = $m['b']; ?>
                            <tr>
                                <td>#<?php echo (int)$a; ?> <?php echo s((string)($classes[$a]->name ?? '-')); ?></td>
                                <td>#<?php echo (int)$b; ?> <?php echo s((string)($classes[$b]->name ?? '-')); ?></td>
                                <td><?php echo s(implode(' | ', $m['windows'])); ?></td>
                                <td><?php echo s(implode(', ', $classdiag[$a]['reasons'] ?? [])); ?></td>
                                <td><?php echo s(implode(', ', $classdiag[$b]['reasons'] ?? [])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<?php
echo $OUTPUT->footer();
