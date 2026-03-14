<?php
/**
 * Debug classroom mapping from planning publish to student schedule payload.
 *
 * This page helps diagnose where classroom data is missing:
 * - gmk_class.classroomid
 * - gmk_class_schedules.classroomid
 * - resolved classroom (fallback logic)
 * - draft room token (optional, when period is selected)
 *
 * @package local_grupomakro_core
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_classroom_resolution.php'));
$PAGE->set_context($context);
$PAGE->set_title('Debug Classroom Resolution');
$PAGE->set_heading('Debug Classroom Resolution');

$periodid = optional_param('periodid', 0, PARAM_INT);
$classid = optional_param('classid', 0, PARAM_INT);
$onlymissing = optional_param('onlymissing', 1, PARAM_INT);
$limit = optional_param('limit', 500, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHAEXT);
$targetclassid = optional_param('targetclassid', 0, PARAM_INT);

if ($limit < 20) {
    $limit = 20;
}
if ($limit > 5000) {
    $limit = 5000;
}

/**
 * Convert payload value to bool.
 *
 * @param mixed $value
 * @return bool
 */
function gmk_dbg_room_to_bool($value): bool {
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return ((int)$value) === 1;
    }
    $text = trim(core_text::strtolower((string)$value));
    return in_array($text, ['1', 'true', 'yes', 'y', 'si', 'on'], true);
}

/**
 * Build room token from draft row.
 *
 * @param array $item
 * @return string
 */
function gmk_dbg_room_draft_room_token(array $item): string {
    if (!empty($item['sessions']) && is_array($item['sessions'])) {
        foreach ($item['sessions'] as $sess) {
            if (!is_array($sess)) {
                continue;
            }
            if (!empty($sess['classroomid'])) {
                return trim((string)$sess['classroomid']);
            }
        }
    }
    if (!empty($item['room'])) {
        return trim((string)$item['room']);
    }
    return '';
}

/**
 * Return first non-zero int from list.
 *
 * @param array $values
 * @return int
 */
function gmk_dbg_room_first_positive(array $values): int {
    foreach ($values as $v) {
        $n = (int)$v;
        if ($n > 0) {
            return $n;
        }
    }
    return 0;
}

/**
 * Apply a safe classroom repair for one class.
 *
 * - If class.classroomid is empty and schedule has a classroom, copy first schedule classroom to class.
 * - If class.classroomid exists, fill empty schedule classroomid rows with class.classroomid.
 *
 * @param int $classid
 * @return array
 */
function gmk_dbg_room_repair_class(int $classid): array {
    global $DB, $USER;

    $class = $DB->get_record('gmk_class', ['id' => $classid], '*', IGNORE_MISSING);
    if (!$class) {
        return ['ok' => false, 'message' => "Class {$classid} not found."];
    }

    $schedules = $DB->get_records('gmk_class_schedules', ['classid' => $classid], 'id ASC', 'id,classroomid');
    $scheduleids = [];
    foreach ($schedules as $s) {
        $sid = (int)($s->classroomid ?? 0);
        if ($sid > 0) {
            $scheduleids[$sid] = $sid;
        }
    }
    $scheduleids = array_values($scheduleids);

    $updatedclass = 0;
    $updatedschedules = 0;
    $classroomid = (int)($class->classroomid ?? 0);

    if ($classroomid <= 0) {
        $fromschedule = gmk_dbg_room_first_positive($scheduleids);
        if ($fromschedule > 0) {
            $class->classroomid = $fromschedule;
            $class->timemodified = time();
            $class->usermodified = (int)($USER->id ?? 0);
            $DB->update_record('gmk_class', $class);
            $classroomid = $fromschedule;
            $updatedclass = 1;
        }
    }

    if ($classroomid > 0) {
        $DB->execute(
            "UPDATE {gmk_class_schedules}
                SET classroomid = :rid
              WHERE classid = :cid
                AND (classroomid IS NULL OR classroomid = 0)",
            ['rid' => $classroomid, 'cid' => $classid]
        );
        $updatedschedules = (int)$DB->count_records_select(
            'gmk_class_schedules',
            'classid = :cid AND classroomid = :rid',
            ['cid' => $classid, 'rid' => $classroomid]
        );
    }

    return [
        'ok' => true,
        'message' => "Class {$classid} repaired. class_updated={$updatedclass} schedule_rows_with_room={$updatedschedules}",
    ];
}

$messages = [];
if ($action !== '' && confirm_sesskey()) {
    if ($action === 'repair_class' && $targetclassid > 0) {
        $messages[] = gmk_dbg_room_repair_class($targetclassid);
    } else if ($action === 'repair_period' && $periodid > 0) {
        $classes = $DB->get_records('gmk_class', ['periodid' => $periodid], 'id ASC', 'id');
        $count = 0;
        foreach ($classes as $c) {
            $messages[] = gmk_dbg_room_repair_class((int)$c->id);
            $count++;
        }
        $messages[] = ['ok' => true, 'message' => "Bulk repair done for {$count} classes in period {$periodid}."];
    }
}

// 1) Fetch classes.
$where = [];
$params = [];

if ($periodid > 0) {
    $where[] = 'c.periodid = :periodid';
    $params['periodid'] = $periodid;
}
if ($classid > 0) {
    $where[] = 'c.id = :classid';
    $params['classid'] = $classid;
}

$wheresql = '';
if (!empty($where)) {
    $wheresql = 'WHERE ' . implode(' AND ', $where);
}

$classsql = "SELECT c.id, c.name, c.periodid, c.approved, c.closed, c.classroomid, c.corecourseid,
                    c.learningplanid, c.inittime, c.endtime, c.classdays
               FROM {gmk_class} c
               {$wheresql}
           ORDER BY c.id DESC";
$classes = $DB->get_records_sql($classsql, $params, 0, $limit);

$classids = array_keys($classes);

// 2) Fetch schedules for all classes.
$schedulesbyclass = [];
if (!empty($classids)) {
    list($insql, $inparams) = $DB->get_in_or_equal($classids, SQL_PARAMS_NAMED, 'cid');
    $schedules = $DB->get_records_sql(
        "SELECT id, classid, day, start_time, end_time, classroomid
           FROM {gmk_class_schedules}
          WHERE classid {$insql}
       ORDER BY classid ASC, id ASC",
        $inparams
    );
    foreach ($schedules as $s) {
        $cid = (int)$s->classid;
        if (!isset($schedulesbyclass[$cid])) {
            $schedulesbyclass[$cid] = [];
        }
        $schedulesbyclass[$cid][] = $s;
    }
}

// 3) Load room names.
$roomids = [];
foreach ($classes as $c) {
    $rid = (int)($c->classroomid ?? 0);
    if ($rid > 0) {
        $roomids[$rid] = $rid;
    }
    $rows = $schedulesbyclass[(int)$c->id] ?? [];
    foreach ($rows as $s) {
        $srid = (int)($s->classroomid ?? 0);
        if ($srid > 0) {
            $roomids[$srid] = $srid;
        }
    }
}

$roomnames = [];
if (!empty($roomids)) {
    $roomrecords = $DB->get_records_list('gmk_classrooms', 'id', array_values($roomids), '', 'id,name');
    foreach ($roomrecords as $r) {
        $roomnames[(int)$r->id] = (string)$r->name;
    }
}

// 4) Optional draft map (only if period is selected).
$draftmap = [];
if ($periodid > 0) {
    $draftraw = $DB->get_field('gmk_academic_periods', 'draft_schedules', ['id' => $periodid]);
    if (!empty($draftraw)) {
        $draft = json_decode($draftraw, true);
        if (is_array($draft)) {
            foreach ($draft as $item) {
                if (!is_array($item)) {
                    continue;
                }
                if (!empty($item['isExternal']) && gmk_dbg_room_to_bool($item['isExternal'])) {
                    continue;
                }
                $did = (int)($item['id'] ?? 0);
                if ($did <= 0) {
                    continue;
                }
                $draftmap[$did] = gmk_dbg_room_draft_room_token($item);
            }
        }
    }
}

echo $OUTPUT->header();
?>
<style>
table.gmk-debug {
    border-collapse: collapse;
    width: 100%;
    font-size: 12px;
}
table.gmk-debug th, table.gmk-debug td {
    border: 1px solid #d9d9d9;
    padding: 6px 8px;
    vertical-align: top;
}
table.gmk-debug th {
    background: #f4f6f8;
    text-align: left;
}
.gmk-ok { color: #137333; font-weight: 600; }
.gmk-warn { color: #b26a00; font-weight: 600; }
.gmk-err { color: #b00020; font-weight: 600; }
.gmk-muted { color: #667085; }
.gmk-toolbar {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
    margin-bottom: 12px;
}
.gmk-toolbar input { min-width: 90px; }
.gmk-msg {
    margin: 8px 0;
    padding: 8px 10px;
    border-radius: 6px;
}
.gmk-msg.ok { background: #e8f5e9; border: 1px solid #b7dfbc; }
.gmk-msg.err { background: #ffecef; border: 1px solid #f3b9c0; }
</style>

<h2>Debug Classroom Resolution</h2>
<p class="gmk-muted">
Shows classroom values from <code>gmk_class</code>, <code>gmk_class_schedules</code>,
resolved classroom used by payload, and optional draft room token.
</p>

<form method="get" class="gmk-toolbar">
    <label>periodid <input type="number" name="periodid" value="<?php echo (int)$periodid; ?>"></label>
    <label>classid <input type="number" name="classid" value="<?php echo (int)$classid; ?>"></label>
    <label>limit <input type="number" name="limit" value="<?php echo (int)$limit; ?>"></label>
    <label><input type="checkbox" name="onlymissing" value="1" <?php echo !empty($onlymissing) ? 'checked' : ''; ?>> only missing/mismatch</label>
    <button type="submit" class="btn btn-primary">Diagnose</button>
    <?php if ($periodid > 0) { ?>
        <a class="btn btn-secondary"
           href="<?php echo new moodle_url('/local/grupomakro_core/pages/debug_classroom_resolution.php', [
               'periodid' => $periodid,
               'classid' => $classid,
               'limit' => $limit,
               'onlymissing' => $onlymissing,
               'action' => 'repair_period',
               'sesskey' => sesskey(),
           ]); ?>"
           onclick="return confirm('Repair classroom mapping for all classes in this period?');">
            Repair period
        </a>
    <?php } ?>
</form>

<?php
foreach ($messages as $msg) {
    $isok = !empty($msg['ok']);
    echo '<div class="gmk-msg ' . ($isok ? 'ok' : 'err') . '">' . s((string)$msg['message']) . '</div>';
}

echo '<p><b>Classes loaded:</b> ' . count($classes) . '</p>';

echo '<table class="gmk-debug">';
echo '<thead><tr>';
echo '<th>ID</th>';
echo '<th>Name</th>';
echo '<th>Period</th>';
echo '<th>Approved/Closed</th>';
echo '<th>Class classroom</th>';
echo '<th>Schedule classroom ids</th>';
echo '<th>Schedule sample</th>';
echo '<th>Resolved classroom</th>';
echo '<th>Draft room token</th>';
echo '<th>Status</th>';
echo '<th>Action</th>';
echo '</tr></thead><tbody>';

$shown = 0;
foreach ($classes as $c) {
    $cid = (int)$c->id;
    $rows = $schedulesbyclass[$cid] ?? [];

    $classroomid = (int)($c->classroomid ?? 0);
    $classroomname = ($classroomid > 0 && isset($roomnames[$classroomid])) ? $roomnames[$classroomid] : 'Sin aula';

    $schedids = [];
    $schedsample = [];
    foreach ($rows as $r) {
        $srid = (int)($r->classroomid ?? 0);
        if ($srid > 0) {
            $schedids[$srid] = $srid;
        }
        if (count($schedsample) < 2) {
            $schedsample[] = trim((string)$r->day) . ' ' . trim((string)$r->start_time) . '-' . trim((string)$r->end_time);
        }
    }
    $schedids = array_values($schedids);
    $schedlabels = [];
    foreach ($schedids as $rid) {
        $schedlabels[] = $rid . ':' . ($roomnames[$rid] ?? 'unknown');
    }

    $resolvedid = $classroomid > 0 ? $classroomid : gmk_dbg_room_first_positive($schedids);
    $resolvedname = $resolvedid > 0 ? ($roomnames[$resolvedid] ?? 'unknown') : 'Sin aula';

    $status = 'OK';
    $statusclass = 'gmk-ok';
    if ($resolvedid <= 0) {
        $status = 'MISSING';
        $statusclass = 'gmk-err';
    } else if ($classroomid > 0 && !empty($schedids) && !in_array($classroomid, $schedids, true)) {
        $status = 'MISMATCH';
        $statusclass = 'gmk-warn';
    } else if ($classroomid <= 0 && !empty($schedids)) {
        $status = 'ONLY_IN_SCHEDULE';
        $statusclass = 'gmk-warn';
    } else if ($classroomid > 0 && empty($schedids)) {
        $status = 'ONLY_IN_CLASS';
        $statusclass = 'gmk-warn';
    }

    if (!empty($onlymissing) && $status === 'OK') {
        continue;
    }

    $drafttoken = $draftmap[$cid] ?? '';

    echo '<tr>';
    echo '<td>' . $cid . '</td>';
    echo '<td>' . s((string)$c->name) . '</td>';
    echo '<td>' . (int)($c->periodid ?? 0) . '</td>';
    echo '<td>' . (int)($c->approved ?? 0) . '/' . (int)($c->closed ?? 0) . '</td>';
    echo '<td>' . ($classroomid > 0 ? ($classroomid . ':' . s($classroomname)) : '0:Sin aula') . '</td>';
    echo '<td>' . (!empty($schedlabels) ? s(implode(', ', $schedlabels)) : '-') . '</td>';
    echo '<td>' . (!empty($schedsample) ? s(implode(' | ', $schedsample)) : '-') . '</td>';
    echo '<td>' . ($resolvedid > 0 ? ($resolvedid . ':' . s($resolvedname)) : '0:Sin aula') . '</td>';
    echo '<td>' . ($drafttoken !== '' ? s($drafttoken) : '-') . '</td>';
    echo '<td><span class="' . $statusclass . '">' . s($status) . '</span></td>';

    $repairurl = new moodle_url('/local/grupomakro_core/pages/debug_classroom_resolution.php', [
        'periodid' => $periodid,
        'classid' => $classid,
        'limit' => $limit,
        'onlymissing' => $onlymissing,
        'action' => 'repair_class',
        'targetclassid' => $cid,
        'sesskey' => sesskey(),
    ]);
    echo '<td><a class="btn btn-secondary btn-sm" href="' . $repairurl . '"';
    echo ' onclick="return confirm(\'Repair classroom mapping for class ' . $cid . '?\');">Repair</a></td>';

    echo '</tr>';
    $shown++;
}

if ($shown === 0) {
    echo '<tr><td colspan="11" class="gmk-muted">No rows to display with current filters.</td></tr>';
}

echo '</tbody></table>';

echo $OUTPUT->footer();

