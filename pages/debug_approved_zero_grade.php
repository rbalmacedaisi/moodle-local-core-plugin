<?php
// Debug page: approved/completed records with grade 0 in gmk_course_progre.

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/progress_manager.php');

global $DB, $OUTPUT, $PAGE;

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

admin_externalpage_setup('grupomakro_core_debug_approved_zero_grade');

$q = trim(optional_param('q', '', PARAM_RAW_TRIMMED));
$planid = optional_param('planid', 0, PARAM_INT);
$periodid = optional_param('periodid', 0, PARAM_INT);
$statusscope = optional_param('statusscope', 'approved', PARAM_ALPHA);
$onlyactive = optional_param('onlyactive', 1, PARAM_INT);
$maxrows = optional_param('maxrows', 500, PARAM_INT);
$maxrepair = optional_param('maxrepair', 300, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHAEXT);
$singleid = optional_param('singleid', 0, PARAM_INT);

if ($statusscope !== 'approved' && $statusscope !== 'approvedcompleted') {
    $statusscope = 'approved';
}
if ($maxrows < 50) {
    $maxrows = 50;
}
if ($maxrows > 3000) {
    $maxrows = 3000;
}
if ($maxrepair < 1) {
    $maxrepair = 1;
}
if ($maxrepair > 2000) {
    $maxrepair = 2000;
}

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_approved_zero_grade.php', [
    'q' => $q,
    'planid' => $planid,
    'periodid' => $periodid,
    'statusscope' => $statusscope,
    'onlyactive' => $onlyactive,
    'maxrows' => $maxrows,
    'maxrepair' => $maxrepair,
]));
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Debug approved grade zero');
$PAGE->set_heading('Debug approved records with grade zero');

/**
 * Build where clause for current filters.
 *
 * @param moodle_database $DB
 * @param string $statusscope
 * @param int $planid
 * @param int $periodid
 * @param string $q
 * @param int $onlyactive
 * @return array
 */
function dzg_build_filter_sql($DB, $statusscope, $planid, $periodid, $q, $onlyactive) {
    $conditions = [];
    $params = [];

    if ($statusscope === 'approvedcompleted') {
        $conditions[] = 'cp.status IN (3, 4)';
    } else {
        $conditions[] = 'cp.status = 4';
    }
    $conditions[] = '(cp.grade IS NULL OR cp.grade <= 0)';

    if ($onlyactive) {
        $conditions[] = 'u.deleted = 0';
        $conditions[] = 'u.suspended = 0';
    } else {
        $conditions[] = 'u.deleted = 0';
    }

    if ($planid > 0) {
        $conditions[] = 'cp.learningplanid = :f_planid';
        $params['f_planid'] = (int)$planid;
    }
    if ($periodid > 0) {
        $conditions[] = 'cp.periodid = :f_periodid';
        $params['f_periodid'] = (int)$periodid;
    }

    if ($q !== '') {
        $like = '%' . $DB->sql_like_escape($q) . '%';
        $conditions[] = '('
            . $DB->sql_like('u.firstname', ':q1', false) . ' OR '
            . $DB->sql_like('u.lastname', ':q2', false) . ' OR '
            . $DB->sql_like('u.username', ':q3', false) . ' OR '
            . $DB->sql_like('u.idnumber', ':q4', false) . ' OR '
            . $DB->sql_like('u.email', ':q5', false) . ' OR '
            . $DB->sql_like('c.fullname', ':q6', false) . ' OR '
            . $DB->sql_like('c.shortname', ':q7', false)
            . ')';
        $params['q1'] = $like;
        $params['q2'] = $like;
        $params['q3'] = $like;
        $params['q4'] = $like;
        $params['q5'] = $like;
        $params['q6'] = $like;
        $params['q7'] = $like;
    }

    return [implode(' AND ', $conditions), $params];
}

/**
 * Fetch cases according to filters.
 *
 * @param moodle_database $DB
 * @param string $where
 * @param array $params
 * @param int $limit
 * @return array
 */
function dzg_fetch_cases($DB, $where, array $params, $limit) {
    $nfisub = "
        SELECT gg.userid AS userid, gi.courseid AS courseid,
               MAX(COALESCE(gg.finalgrade, gg.rawgrade)) AS nfi_grade
          FROM {grade_items} gi
          JOIN {grade_grades} gg ON gg.itemid = gi.id
         WHERE " . $DB->sql_like('gi.itemname', ':nfi1', false) . "
            OR " . $DB->sql_like('gi.itemname', ':nfi2', false) . "
      GROUP BY gg.userid, gi.courseid
    ";
    $coursesub = "
        SELECT gg.userid AS userid, gi.courseid AS courseid,
               MAX(COALESCE(gg.finalgrade, gg.rawgrade)) AS course_total_raw,
               MAX(CASE
                   WHEN gi.grademax > 0 THEN ROUND(COALESCE(gg.finalgrade, gg.rawgrade) / gi.grademax * 100, 2)
                   ELSE NULL
               END) AS course_total_pct
          FROM {grade_items} gi
          JOIN {grade_grades} gg ON gg.itemid = gi.id
         WHERE gi.itemtype = 'course'
      GROUP BY gg.userid, gi.courseid
    ";

    $sql = "
        SELECT cp.id AS rid,
               cp.id AS progreid,
               cp.userid,
               cp.courseid,
               cp.learningplanid,
               cp.periodid,
               cp.status,
               cp.grade,
               cp.progress,
               cp.classid,
               cp.groupid,
               cp.timemodified,
               u.firstname,
               u.lastname,
               u.username,
               u.idnumber,
               u.email,
               c.fullname AS coursename,
               c.shortname AS courseshort,
               lp.name AS planname,
               per.name AS periodname,
               ct.course_total_raw,
               ct.course_total_pct,
               nfi.nfi_grade
          FROM {gmk_course_progre} cp
          JOIN {user} u ON u.id = cp.userid
          JOIN {course} c ON c.id = cp.courseid
     LEFT JOIN {local_learning_plans} lp ON lp.id = cp.learningplanid
     LEFT JOIN {local_learning_periods} per ON per.id = cp.periodid
     LEFT JOIN ($coursesub) ct ON ct.userid = cp.userid AND ct.courseid = cp.courseid
     LEFT JOIN ($nfisub) nfi ON nfi.userid = cp.userid AND nfi.courseid = cp.courseid
         WHERE $where
      ORDER BY u.lastname ASC, u.firstname ASC, c.fullname ASC, cp.id DESC
    ";

    $fullparams = $params;
    $fullparams['nfi1'] = '%Nota Final Integrada%';
    $fullparams['nfi2'] = '%Final Integrada%';

    $records = $DB->get_records_sql($sql, $fullparams, 0, $limit + 1);
    $rows = array_values($records);
    $truncated = false;
    if (count($rows) > $limit) {
        array_pop($rows);
        $truncated = true;
    }
    return [$rows, $truncated];
}

/**
 * Extract best grade evidence from gradebook-derived fields.
 *
 * @param stdClass $row
 * @return float|null
 */
function dzg_grade_evidence($row) {
    $candidates = [];
    if (isset($row->course_total_pct) && $row->course_total_pct !== null) {
        $candidates[] = (float)$row->course_total_pct;
    }
    if (isset($row->nfi_grade) && $row->nfi_grade !== null) {
        $candidates[] = (float)$row->nfi_grade;
    }
    if (empty($candidates)) {
        return null;
    }
    return max($candidates);
}

/**
 * Recalculate one progress record via progress manager.
 *
 * @param int $progreid
 * @param array $logs
 * @return bool
 */
function dzg_recalc_one($progreid, array &$logs) {
    global $DB;

    $record = $DB->get_record('gmk_course_progre', ['id' => (int)$progreid], '*', IGNORE_MISSING);
    if (!$record) {
        $logs[] = '[ERR] progreid=' . (int)$progreid . ' not found';
        return false;
    }

    $before = 'status=' . (int)$record->status
        . ' grade=' . (is_null($record->grade) ? 'NULL' : (string)$record->grade)
        . ' progress=' . (is_null($record->progress) ? 'NULL' : (string)$record->progress);

    try {
        $ok = local_grupomakro_progress_manager::update_course_progress(
            (int)$record->courseid,
            (int)$record->userid,
            null,
            null,
            false
        );
    } catch (Throwable $ex) {
        $logs[] = '[ERR] progreid=' . (int)$progreid . ' exception=' . $ex->getMessage();
        return false;
    }

    $afterrec = $DB->get_record('gmk_course_progre', ['id' => (int)$progreid], '*', IGNORE_MISSING);
    if (!$afterrec) {
        $logs[] = '[ERR] progreid=' . (int)$progreid . ' disappeared after recalc';
        return false;
    }

    $after = 'status=' . (int)$afterrec->status
        . ' grade=' . (is_null($afterrec->grade) ? 'NULL' : (string)$afterrec->grade)
        . ' progress=' . (is_null($afterrec->progress) ? 'NULL' : (string)$afterrec->progress);

    $logs[] = '[OK] progreid=' . (int)$progreid
        . ' user=' . (int)$record->userid
        . ' course=' . (int)$record->courseid
        . ' before{' . $before . '} after{' . $after . '}'
        . ' update_course_progress=' . ($ok ? 'true' : 'false');

    return true;
}

list($where, $params) = dzg_build_filter_sql($DB, $statusscope, $planid, $periodid, $q, $onlyactive);
$actionlogs = [];
$actionsummary = '';

if ($action === 'recalcone' && $singleid > 0) {
    require_sesskey();
    $ok = dzg_recalc_one((int)$singleid, $actionlogs);
    $actionsummary = $ok ? 'Recalc one done.' : 'Recalc one failed.';
}

if ($action === 'recalcselected') {
    require_sesskey();
    $selectedids = optional_param_array('selids', [], PARAM_INT);
    $selectedids = array_values(array_unique(array_filter(array_map('intval', $selectedids))));
    if (empty($selectedids)) {
        $actionsummary = 'No records selected.';
    } else {
        $okcount = 0;
        $failcount = 0;
        foreach ($selectedids as $pid) {
            if (dzg_recalc_one((int)$pid, $actionlogs)) {
                $okcount++;
            } else {
                $failcount++;
            }
        }
        $actionsummary = 'Recalc selected done. ok=' . $okcount . ' fail=' . $failcount . '.';
    }
}

if ($action === 'recalcall') {
    require_sesskey();
    $caseids = [];
    list($rowsforids) = dzg_fetch_cases($DB, $where, $params, $maxrepair);
    foreach ($rowsforids as $r) {
        $caseids[] = (int)$r->progreid;
    }
    $caseids = array_values(array_unique(array_filter($caseids)));
    if (empty($caseids)) {
        $actionsummary = 'No matching records to recalc.';
    } else {
        $okcount = 0;
        $failcount = 0;
        foreach ($caseids as $pid) {
            if (dzg_recalc_one((int)$pid, $actionlogs)) {
                $okcount++;
            } else {
                $failcount++;
            }
        }
        $actionsummary = 'Recalc all done. processed=' . count($caseids)
            . ' ok=' . $okcount . ' fail=' . $failcount . '.';
    }
}

list($rows, $truncated) = dzg_fetch_cases($DB, $where, $params, $maxrows);

$planoptions = [0 => 'All'];
$plans = $DB->get_records('local_learning_plans', null, 'name ASC', 'id,name');
foreach ($plans as $p) {
    $planoptions[(int)$p->id] = $p->name;
}

$periodoptions = [0 => 'All'];
$periods = $DB->get_records('local_learning_periods', null, 'name ASC', 'id,name');
foreach ($periods as $p) {
    $periodoptions[(int)$p->id] = $p->name;
}

$statuslabel = [
    0 => 'No disponible',
    1 => 'Disponible',
    2 => 'Cursando',
    3 => 'Completada',
    4 => 'Aprobada',
    5 => 'Reprobada',
    6 => 'Pendiente Revalida',
    7 => 'Revalidando',
];

$uniquestudents = [];
$uniquecourses = [];
$evidencepass = 0;
$evidencemissing = 0;
foreach ($rows as $r) {
    $uniquestudents[(int)$r->userid] = 1;
    $uniquecourses[(int)$r->courseid] = 1;
    $evidence = dzg_grade_evidence($r);
    if ($evidence !== null && $evidence >= 70.0) {
        $evidencepass++;
    } else {
        $evidencemissing++;
    }
}

echo $OUTPUT->header();
?>
<style>
.dzg-wrap { max-width: 100%; margin: 0 auto; }
.dzg-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 14px; margin-bottom: 14px; }
.dzg-row { display: flex; flex-wrap: wrap; gap: 10px; align-items: end; }
.dzg-row > div { min-width: 180px; }
.dzg-input, .dzg-select { width: 100%; padding: 7px 8px; border: 1px solid #cbd5e1; border-radius: 6px; }
.dzg-btn { border: 1px solid #1d4ed8; background: #1d4ed8; color: #fff; padding: 8px 10px; border-radius: 6px; cursor: pointer; text-decoration: none; display: inline-block; }
.dzg-btn-gray { border-color: #64748b; background: #64748b; }
.dzg-btn-warn { border-color: #b45309; background: #b45309; }
.dzg-stat { display: inline-block; margin-right: 14px; font-size: 13px; }
.dzg-tablewrap { overflow: auto; border: 1px solid #e5e7eb; border-radius: 8px; background: #fff; }
.dzg-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.dzg-table th, .dzg-table td { border-bottom: 1px solid #e5e7eb; padding: 7px; text-align: left; vertical-align: top; }
.dzg-table th { background: #f8fafc; position: sticky; top: 0; z-index: 1; }
.dzg-badge { display: inline-block; border-radius: 999px; padding: 2px 8px; font-size: 11px; font-weight: 700; }
.dzg-ok { background: #dcfce7; color: #166534; }
.dzg-warn { background: #ffedd5; color: #9a3412; }
.dzg-muted { color: #64748b; }
.dzg-pre { background: #0f172a; color: #e2e8f0; border-radius: 8px; padding: 10px; font-size: 12px; max-height: 260px; overflow: auto; }
</style>

<div class="dzg-wrap">
  <div class="dzg-card">
    <h3 style="margin: 0 0 8px 0;">Debug: approved/completed with grade zero</h3>
    <div class="dzg-muted" style="font-size: 13px;">
      Detect records in gmk_course_progre where status is approved/completed but grade is zero or null.
    </div>
  </div>

  <div class="dzg-card">
    <form method="get">
      <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
      <div class="dzg-row">
        <div>
          <label>Search (student or course)</label>
          <input class="dzg-input" type="text" name="q" value="<?php echo s($q); ?>">
        </div>
        <div>
          <label>Plan</label>
          <select class="dzg-select" name="planid">
            <?php foreach ($planoptions as $pid => $pname): ?>
              <option value="<?php echo (int)$pid; ?>" <?php echo ((int)$pid === (int)$planid ? 'selected' : ''); ?>><?php echo s($pname); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Period</label>
          <select class="dzg-select" name="periodid">
            <?php foreach ($periodoptions as $pid => $pname): ?>
              <option value="<?php echo (int)$pid; ?>" <?php echo ((int)$pid === (int)$periodid ? 'selected' : ''); ?>><?php echo s($pname); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Status scope</label>
          <select class="dzg-select" name="statusscope">
            <option value="approved" <?php echo ($statusscope === 'approved' ? 'selected' : ''); ?>>Approved only (4)</option>
            <option value="approvedcompleted" <?php echo ($statusscope === 'approvedcompleted' ? 'selected' : ''); ?>>Approved + Completed (3,4)</option>
          </select>
        </div>
        <div>
          <label>Only active users</label>
          <select class="dzg-select" name="onlyactive">
            <option value="1" <?php echo ((int)$onlyactive === 1 ? 'selected' : ''); ?>>Yes</option>
            <option value="0" <?php echo ((int)$onlyactive === 0 ? 'selected' : ''); ?>>No</option>
          </select>
        </div>
        <div>
          <label>Max rows</label>
          <input class="dzg-input" type="number" min="50" max="3000" name="maxrows" value="<?php echo (int)$maxrows; ?>">
        </div>
        <div>
          <label>Max recalc all</label>
          <input class="dzg-input" type="number" min="1" max="2000" name="maxrepair" value="<?php echo (int)$maxrepair; ?>">
        </div>
        <div>
          <button class="dzg-btn" type="submit">Diagnose</button>
        </div>
      </div>
    </form>
  </div>

  <?php if ($actionsummary !== ''): ?>
    <div class="dzg-card">
      <strong><?php echo s($actionsummary); ?></strong>
      <?php if (!empty($actionlogs)): ?>
        <div style="margin-top: 8px;" class="dzg-pre"><?php echo s(implode(PHP_EOL, $actionlogs)); ?></div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="dzg-card">
    <span class="dzg-stat"><strong>Rows:</strong> <?php echo count($rows); ?><?php echo $truncated ? ' (truncated)' : ''; ?></span>
    <span class="dzg-stat"><strong>Students:</strong> <?php echo count($uniquestudents); ?></span>
    <span class="dzg-stat"><strong>Courses:</strong> <?php echo count($uniquecourses); ?></span>
    <span class="dzg-stat"><strong>Grade evidence >=70:</strong> <?php echo (int)$evidencepass; ?></span>
    <span class="dzg-stat"><strong>No grade evidence >=70:</strong> <?php echo (int)$evidencemissing; ?></span>
  </div>

  <form method="post" id="dzg-main-form">
    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
    <input type="hidden" name="q" value="<?php echo s($q); ?>">
    <input type="hidden" name="planid" value="<?php echo (int)$planid; ?>">
    <input type="hidden" name="periodid" value="<?php echo (int)$periodid; ?>">
    <input type="hidden" name="statusscope" value="<?php echo s($statusscope); ?>">
    <input type="hidden" name="onlyactive" value="<?php echo (int)$onlyactive; ?>">
    <input type="hidden" name="maxrows" value="<?php echo (int)$maxrows; ?>">
    <input type="hidden" name="maxrepair" value="<?php echo (int)$maxrepair; ?>">

    <div class="dzg-card">
      <button class="dzg-btn dzg-btn-gray" type="submit" name="action" value="recalcselected">Recalc selected</button>
      <button class="dzg-btn dzg-btn-warn" type="submit" name="action" value="recalcall" onclick="return confirm('Run recalc for current filtered records (limited by Max recalc all)?');">Recalc all filtered</button>
    </div>

    <div class="dzg-tablewrap">
      <table class="dzg-table">
        <thead>
          <tr>
            <th><input type="checkbox" id="dzg-check-all"></th>
            <th>progre id</th>
            <th>Student</th>
            <th>Course</th>
            <th>Plan / Period</th>
            <th>Status</th>
            <th>Local grade</th>
            <th>Course total %</th>
            <th>NFI</th>
            <th>Progress</th>
            <th>Class/Group</th>
            <th>Diagnosis</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
            <tr><td colspan="13" class="dzg-muted">No cases found for current filter.</td></tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <?php
                $st = (int)$r->status;
                $stlabel = isset($statuslabel[$st]) ? $statuslabel[$st] : ('status=' . $st);
                $evidence = dzg_grade_evidence($r);
                $diagok = ($evidence !== null && $evidence >= 70.0);
                $diag = $diagok
                    ? 'Approved with zero local grade. Gradebook evidence exists.'
                    : 'Approved with zero local grade and no pass evidence in gradebook.';
                $diagcls = $diagok ? 'dzg-ok' : 'dzg-warn';
              ?>
              <tr>
                <td><input type="checkbox" name="selids[]" value="<?php echo (int)$r->progreid; ?>" class="dzg-row-check"></td>
                <td style="font-family: monospace;"><?php echo (int)$r->progreid; ?></td>
                <td>
                  <strong><?php echo s(trim($r->firstname . ' ' . $r->lastname)); ?></strong><br>
                  <span class="dzg-muted">uid=<?php echo (int)$r->userid; ?> | user=<?php echo s($r->username); ?></span><br>
                  <span class="dzg-muted">id=<?php echo s((string)$r->idnumber); ?> | <?php echo s((string)$r->email); ?></span>
                </td>
                <td>
                  <strong><?php echo s($r->coursename); ?></strong><br>
                  <span class="dzg-muted">cid=<?php echo (int)$r->courseid; ?> | <?php echo s($r->courseshort); ?></span>
                </td>
                <td>
                  <?php echo s($r->planname !== null ? $r->planname : ('Plan ID ' . (int)$r->learningplanid)); ?><br>
                  <span class="dzg-muted"><?php echo s($r->periodname !== null ? $r->periodname : ('Period ID ' . (int)$r->periodid)); ?></span>
                </td>
                <td><span class="dzg-badge"><?php echo s($stlabel); ?></span></td>
                <td><?php echo is_null($r->grade) ? 'NULL' : s((string)$r->grade); ?></td>
                <td><?php echo is_null($r->course_total_pct) ? '-' : s(number_format((float)$r->course_total_pct, 2)); ?></td>
                <td><?php echo is_null($r->nfi_grade) ? '-' : s(number_format((float)$r->nfi_grade, 2)); ?></td>
                <td><?php echo is_null($r->progress) ? '-' : s((string)$r->progress); ?></td>
                <td>class=<?php echo (int)$r->classid; ?><br>group=<?php echo (int)$r->groupid; ?></td>
                <td><span class="dzg-badge <?php echo $diagcls; ?>"><?php echo s($diag); ?></span></td>
                <td>
                  <button class="dzg-btn dzg-btn-gray" type="submit" name="action" value="recalcone" onclick="document.getElementById('singleid-input').value='<?php echo (int)$r->progreid; ?>';">Recalc one</button>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <input type="hidden" id="singleid-input" name="singleid" value="0">
  </form>
</div>

<script>
(function() {
  var checkAll = document.getElementById('dzg-check-all');
  if (!checkAll) {
    return;
  }
  checkAll.addEventListener('change', function() {
    var checks = document.querySelectorAll('.dzg-row-check');
    checks.forEach(function(chk) {
      chk.checked = checkAll.checked;
    });
  });
})();
</script>

<?php
echo $OUTPUT->footer();

