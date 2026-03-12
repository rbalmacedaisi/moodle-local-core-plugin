<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/teacher/get_dashboard_data.php');

global $DB, $PAGE, $OUTPUT, $USER;

require_login();
$syscontext = context_system::instance();
require_capability('moodle/site:config', $syscontext);

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_teacher_class_visibility.php'));
$PAGE->set_context($syscontext);
$PAGE->set_title('Debug Teacher Class Visibility');
$PAGE->set_heading('Debug Teacher Class Visibility');
$PAGE->set_pagelayout('admin');

$defaultclassname = '2026-I (D) INGLES I (PRESENCIAL) AULA AUDITORIO';
$classname = trim(optional_param('classname', $defaultclassname, PARAM_TEXT));
$classid = optional_param('classid', 0, PARAM_INT);
$doca = trim(optional_param('doca', '2-741-1217', PARAM_TEXT));
$docb = trim(optional_param('docb', '8-757-608', PARAM_TEXT));
$usera = optional_param('usera', 0, PARAM_INT);
$userb = optional_param('userb', 0, PARAM_INT);

function gmk_dbg_h($value): string {
    return s((string)$value);
}

function gmk_dbg_arr_get($src, string $key, $default = null) {
    if (is_array($src)) {
        return array_key_exists($key, $src) ? $src[$key] : $default;
    }
    if (is_object($src)) {
        return property_exists($src, $key) ? $src->{$key} : $default;
    }
    return $default;
}

function gmk_dbg_get_document_field_id(): int {
    global $DB;
    $candidates = ['documentnumber', 'document_number', 'documento', 'cedula'];
    foreach ($candidates as $shortname) {
        $field = $DB->get_record('user_info_field', ['shortname' => $shortname], 'id', IGNORE_MISSING);
        if ($field) {
            return (int)$field->id;
        }
    }
    return 0;
}

function gmk_dbg_find_users_by_document(string $doc, int $fieldid): array {
    global $DB;

    if ($doc === '') {
        return [];
    }

    $rows = [];

    if ($fieldid > 0) {
        $sql = "SELECT u.id, u.username, u.firstname, u.lastname, u.email, u.idnumber, u.suspended, u.deleted
                  FROM {user} u
                  JOIN {user_info_data} d
                    ON d.userid = u.id
                   AND d.fieldid = :fieldid
                 WHERE u.deleted = 0
                   AND TRIM(d.data) = :doc";
        $records = $DB->get_records_sql($sql, ['fieldid' => $fieldid, 'doc' => $doc]);
        foreach ($records as $r) {
            $rows[(int)$r->id] = $r;
        }
    }

    $records2 = $DB->get_records_sql(
        "SELECT id, username, firstname, lastname, email, idnumber, suspended, deleted
           FROM {user}
          WHERE deleted = 0
            AND TRIM(idnumber) = :doc",
        ['doc' => $doc]
    );
    foreach ($records2 as $r) {
        $rows[(int)$r->id] = $r;
    }

    return array_values($rows);
}

function gmk_dbg_get_user_document(int $userid, int $fieldid): string {
    global $DB;
    if ($fieldid <= 0) {
        return '';
    }
    $value = $DB->get_field('user_info_data', 'data', ['userid' => $userid, 'fieldid' => $fieldid]);
    return $value ? (string)$value : '';
}

function gmk_dbg_get_user_roles(int $userid): array {
    global $DB;
    $sql = "SELECT DISTINCT r.shortname
              FROM {role_assignments} ra
              JOIN {role} r ON r.id = ra.roleid
             WHERE ra.userid = :userid
          ORDER BY r.shortname";
    $rows = $DB->get_records_sql($sql, ['userid' => $userid]);
    return array_values(array_map(function($x) {
        return $x->shortname;
    }, $rows));
}

function gmk_dbg_get_dashboard_class_ids(int $userid): array {
    global $DB;

    $isadmin = is_siteadmin($userid);
    $nowwithbuffer = time() - (7 * 24 * 60 * 60);
    $whereinstructor = $isadmin ? '' : ' AND c.instructorid = :instructorid';

    $sql = "SELECT c.id
              FROM {gmk_class} c
             WHERE c.closed = 0
               AND c.enddate >= :now
               $whereinstructor
               AND EXISTS (
                    SELECT 1 FROM {gmk_bbb_attendance_relation} r
                     WHERE r.classid = c.id
               )";

    $params = ['now' => $nowwithbuffer];
    if (!$isadmin) {
        $params['instructorid'] = $userid;
    }

    $rows = $DB->get_records_sql($sql, $params);
    return array_map('intval', array_keys($rows));
}

$docfieldid = gmk_dbg_get_document_field_id();
$usersa = gmk_dbg_find_users_by_document($doca, $docfieldid);
$usersb = gmk_dbg_find_users_by_document($docb, $docfieldid);

$selecteda = null;
if (!empty($usersa)) {
    if ($usera > 0) {
        foreach ($usersa as $u) {
            if ((int)$u->id === (int)$usera) {
                $selecteda = $u;
                break;
            }
        }
    }
    if (!$selecteda) {
        $selecteda = $usersa[0];
    }
}

$selectedb = null;
if (!empty($usersb)) {
    if ($userb > 0) {
        foreach ($usersb as $u) {
            if ((int)$u->id === (int)$userb) {
                $selectedb = $u;
                break;
            }
        }
    }
    if (!$selectedb) {
        $selectedb = $usersb[0];
    }
}

$targetclasses = [];
if ($classid > 0) {
    $targetclasses = $DB->get_records_sql(
        "SELECT c.id, c.name, c.corecourseid, c.courseid, c.instructorid, c.approved, c.closed,
                c.groupid, c.initdate, c.enddate, c.typelabel, c.classdays,
                u.username AS instructor_username, u.firstname AS instructor_firstname, u.lastname AS instructor_lastname,
                cr.fullname AS corecourse_fullname
           FROM {gmk_class} c
      LEFT JOIN {user} u ON u.id = c.instructorid
      LEFT JOIN {course} cr ON cr.id = c.corecourseid
          WHERE c.id = :classid
       ORDER BY c.id DESC",
        ['classid' => $classid]
    );
} else if ($classname !== '') {
    $targetclasses = $DB->get_records_sql(
        "SELECT c.id, c.name, c.corecourseid, c.courseid, c.instructorid, c.approved, c.closed,
                c.groupid, c.initdate, c.enddate, c.typelabel, c.classdays,
                u.username AS instructor_username, u.firstname AS instructor_firstname, u.lastname AS instructor_lastname,
                cr.fullname AS corecourse_fullname
           FROM {gmk_class} c
      LEFT JOIN {user} u ON u.id = c.instructorid
      LEFT JOIN {course} cr ON cr.id = c.corecourseid
          WHERE c.name LIKE :needle
       ORDER BY c.id DESC",
        ['needle' => '%' . $classname . '%']
    );
}

$diagusers = [];
foreach ([$selecteda, $selectedb] as $selected) {
    if (!$selected) {
        continue;
    }

    $uid = (int)$selected->id;
    $dashclassids = gmk_dbg_get_dashboard_class_ids($uid);
    $dashclassset = array_fill_keys($dashclassids, true);

    $dashdata = \local_grupomakro_core\external\teacher\get_dashboard_data::execute($uid);
    $calendarevents = (array)gmk_dbg_arr_get($dashdata, 'calendar_events', []);

    $calendarbyclassid = [];
    foreach ($calendarevents as $ev) {
        $ecid = (int)gmk_dbg_arr_get($ev, 'classid', 0);
        if ($ecid <= 0) {
            continue;
        }
        if (!isset($calendarbyclassid[$ecid])) {
            $calendarbyclassid[$ecid] = 0;
        }
        $calendarbyclassid[$ecid]++;
    }

    $diagusers[$uid] = [
        'user' => $selected,
        'isadmin' => is_siteadmin($uid),
        'roles' => gmk_dbg_get_user_roles($uid),
        'doc' => gmk_dbg_get_user_document($uid, $docfieldid),
        'dashboardclassset' => $dashclassset,
        'dashboardclasscount' => count($dashclassids),
        'calendarbyclassid' => $calendarbyclassid,
        'calendarcount' => count($calendarevents),
    ];
}

$bufferseconds = 7 * 24 * 60 * 60;
$nowwithbuffer = time() - $bufferseconds;

$casesummary = [];
foreach ($targetclasses as $class) {
    $classidkey = (int)$class->id;
    $relcount = (int)$DB->count_records('gmk_bbb_attendance_relation', ['classid' => $classidkey]);
    $instructordoc = gmk_dbg_get_user_document((int)$class->instructorid, $docfieldid);

    $row = [
        'classid' => $classidkey,
        'classname' => (string)$class->name,
        'instructorid' => (int)$class->instructorid,
        'instructordoc' => $instructordoc,
        'approved' => (int)$class->approved,
        'closed' => (int)$class->closed,
        'enddate' => (int)$class->enddate,
        'hasrelation' => ($relcount > 0),
        'users' => [],
    ];

    foreach ($diagusers as $uid => $diag) {
        $expectedvisible = (
            ((int)$class->closed === 0) &&
            ((int)$class->enddate >= $nowwithbuffer) &&
            ($relcount > 0) &&
            ($diag['isadmin'] || ((int)$class->instructorid === (int)$uid))
        );

        $actualcards = isset($diag['dashboardclassset'][$classidkey]);
        $actualcalendar = (int)($diag['calendarbyclassid'][$classidkey] ?? 0) > 0;

        $row['users'][$uid] = [
            'expectedvisible' => $expectedvisible,
            'actualcards' => $actualcards,
            'actualcalendar' => $actualcalendar,
            'isadmin' => (bool)$diag['isadmin'],
        ];
    }

    $casesummary[] = $row;
}

echo $OUTPUT->header();
?>
<style>
    .wrap { max-width: 1600px; margin: 16px auto; }
    .box { background: #fff; border: 1px solid #ddd; border-radius: 6px; padding: 12px; margin-bottom: 14px; }
    .ok { color: #0f7a0f; font-weight: 700; }
    .bad { color: #b20000; font-weight: 700; }
    .warn { color: #9a6a00; font-weight: 700; }
    table { width: 100%; border-collapse: collapse; font-size: 12px; }
    th, td { border: 1px solid #ddd; padding: 6px; vertical-align: top; }
    th { background: #f0f0f0; text-align: left; }
    .mono { font-family: monospace; }
    .small { font-size: 11px; color: #666; }
</style>

<div class="wrap">
    <h2>Debug teacher class visibility</h2>

    <div class="box">
        <form method="get">
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
                <div>
                    <label>Class name filter</label><br>
                    <input type="text" name="classname" value="<?php echo gmk_dbg_h($classname); ?>" size="70">
                </div>
                <div>
                    <label>Class ID (optional)</label><br>
                    <input type="number" name="classid" value="<?php echo (int)$classid; ?>" min="0" step="1" style="width:120px;">
                </div>
                <div>
                    <label>Teacher A doc (should NOT see)</label><br>
                    <input type="text" name="doca" value="<?php echo gmk_dbg_h($doca); ?>" size="18">
                </div>
                <div>
                    <label>Teacher B doc (should see)</label><br>
                    <input type="text" name="docb" value="<?php echo gmk_dbg_h($docb); ?>" size="18">
                </div>
                <div>
                    <button type="submit">Diagnose</button>
                </div>
            </div>
            <div class="small mono">Document field: <?php echo $docfieldid > 0 ? ('user_info_field.id=' . $docfieldid) : 'NOT FOUND (fallback only by user.idnumber)'; ?></div>
        </form>
    </div>

    <div class="box">
        <h3>Teacher resolution by document</h3>
        <table>
            <thead>
                <tr>
                    <th>Document</th>
                    <th>Matches</th>
                    <th>Selected</th>
                    <th>Admin</th>
                    <th>Roles</th>
                    <th>Dashboard class count</th>
                    <th>Calendar event count</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $pairs = [
                    ['doc' => $doca, 'list' => $usersa, 'selected' => $selecteda],
                    ['doc' => $docb, 'list' => $usersb, 'selected' => $selectedb],
                ];
                foreach ($pairs as $pair):
                    $sel = $pair['selected'];
                    $uid = $sel ? (int)$sel->id : 0;
                    $diag = $uid > 0 && isset($diagusers[$uid]) ? $diagusers[$uid] : null;
                ?>
                    <tr>
                        <td class="mono"><?php echo gmk_dbg_h($pair['doc']); ?></td>
                        <td>
                            <?php if (empty($pair['list'])): ?>
                                <span class="bad">No matches</span>
                            <?php else: ?>
                                <?php foreach ($pair['list'] as $u): ?>
                                    <div class="mono">id=<?php echo (int)$u->id; ?> | <?php echo gmk_dbg_h($u->firstname . ' ' . $u->lastname); ?> | <?php echo gmk_dbg_h($u->username); ?></div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!$sel): ?>
                                <span class="bad">N/A</span>
                            <?php else: ?>
                                <div class="mono">id=<?php echo (int)$sel->id; ?> | <?php echo gmk_dbg_h($sel->firstname . ' ' . $sel->lastname); ?></div>
                                <div class="small">email: <?php echo gmk_dbg_h($sel->email); ?></div>
                                <div class="small">idnumber: <?php echo gmk_dbg_h($sel->idnumber); ?></div>
                                <div class="small">document: <?php echo gmk_dbg_h($diag['doc'] ?? ''); ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $diag ? ($diag['isadmin'] ? '<span class="warn">YES</span>' : '<span class="ok">NO</span>') : 'N/A'; ?></td>
                        <td class="mono"><?php echo $diag ? gmk_dbg_h(implode(', ', $diag['roles'])) : 'N/A'; ?></td>
                        <td><?php echo $diag ? (int)$diag['dashboardclasscount'] : 0; ?></td>
                        <td><?php echo $diag ? (int)$diag['calendarcount'] : 0; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="box">
        <h3>Case summary: expected vs actual visibility</h3>
        <?php if (empty($casesummary)): ?>
            <div class="bad">No classes found with current filter.</div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Class ID</th>
                        <th>Class</th>
                        <th>Instructor in gmk_class</th>
                        <th>Base rule check</th>
                        <?php foreach ($diagusers as $diag): ?>
                            <th><?php echo gmk_dbg_h($diag['user']->firstname . ' ' . $diag['user']->lastname); ?><br><span class="small">doc <?php echo gmk_dbg_h($diag['doc']); ?></span></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($casesummary as $row): ?>
                        <tr>
                            <td class="mono"><?php echo (int)$row['classid']; ?></td>
                            <td><?php echo gmk_dbg_h($row['classname']); ?></td>
                            <td class="mono">
                                instructorid=<?php echo (int)$row['instructorid']; ?><br>
                                document=<?php echo gmk_dbg_h($row['instructordoc'] ?: '-'); ?>
                            </td>
                            <td class="mono">
                                closed=<?php echo (int)$row['closed']; ?><br>
                                enddate>=now-7d=<?php echo ((int)$row['enddate'] >= $nowwithbuffer) ? 'YES' : 'NO'; ?><br>
                                relation=<?php echo $row['hasrelation'] ? 'YES' : 'NO'; ?>
                            </td>
                            <?php foreach ($diagusers as $uid => $diag): ?>
                                <?php $u = $row['users'][$uid] ?? null; ?>
                                <td class="mono">
                                    <?php if (!$u): ?>
                                        N/A
                                    <?php else: ?>
                                        expected=<?php echo $u['expectedvisible'] ? '<span class="ok">YES</span>' : '<span class="bad">NO</span>'; ?><br>
                                        cards=<?php echo $u['actualcards'] ? '<span class="ok">YES</span>' : '<span class="bad">NO</span>'; ?><br>
                                        calendar=<?php echo $u['actualcalendar'] ? '<span class="ok">YES</span>' : '<span class="bad">NO</span>'; ?><br>
                                        admin=<?php echo $u['isadmin'] ? '<span class="warn">YES</span>' : '<span class="ok">NO</span>'; ?>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <div class="small mono" style="margin-top:8px;">
            Cards rule = closed=0 AND enddate>=now-7days AND exists gmk_bbb_attendance_relation AND (instructorid=user OR siteadmin).
        </div>
    </div>

    <div class="small mono">Debug run at <?php echo gmk_dbg_h(userdate(time())); ?> by user id=<?php echo (int)$USER->id; ?></div>
</div>

<?php
echo $OUTPUT->footer();
