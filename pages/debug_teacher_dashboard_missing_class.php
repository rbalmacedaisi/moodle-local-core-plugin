<?php
// Debug page: why a class assigned to a teacher is missing in Teacher Dashboard.

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/classes/external/teacher/get_dashboard_data.php');

global $DB, $PAGE, $OUTPUT, $USER;

require_login();
$syscontext = context_system::instance();
require_capability('moodle/site:config', $syscontext);

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_teacher_dashboard_missing_class.php'));
$PAGE->set_context($syscontext);
$PAGE->set_title('Debug Teacher Dashboard Missing Class');
$PAGE->set_heading('Debug Teacher Dashboard Missing Class');
$PAGE->set_pagelayout('admin');

$teacherid = optional_param('teacherid', 0, PARAM_INT);
$teacherquery = trim(optional_param('teacher', 'LORENZO GONZALEZ PALMA', PARAM_RAW_TRIMMED));
$classid = optional_param('classid', 0, PARAM_INT);
$classquery = trim(optional_param('classname', '2026-II (D) DESARROLLO DE LA PERSONALIDAD (PRESENCIAL) C', PARAM_RAW_TRIMMED));

/**
 * Escape text.
 * @param mixed $v
 * @return string
 */
function dbg_h($v): string {
    return s((string)$v);
}

/**
 * Normalize text for accent-insensitive matching.
 * @param string $text
 * @return string
 */
function dbg_norm(string $text): string {
    $t = trim($text);
    if ($t === '') {
        return '';
    }
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t);
    if ($ascii !== false && $ascii !== '') {
        $t = $ascii;
    }
    $t = core_text::strtolower($t);
    $t = preg_replace('/[^a-z0-9]+/', ' ', $t);
    $t = preg_replace('/\s+/', ' ', $t);
    return trim((string)$t);
}

/**
 * Resolve teacher users by query or explicit id.
 * @param int $teacherid
 * @param string $teacherquery
 * @return array{selected:?stdClass,candidates:array<int,stdClass>,warning:string}
 */
function dbg_resolve_teacher(int $teacherid, string $teacherquery): array {
    global $DB;

    $candidates = [];
    $selected = null;
    $warning = '';

    if ($teacherid > 0) {
        $selected = $DB->get_record('user', ['id' => $teacherid, 'deleted' => 0], 'id,username,firstname,lastname,email,idnumber,suspended,deleted', IGNORE_MISSING);
        if ($selected) {
            $candidates[$selected->id] = $selected;
        }
    }

    if (!$selected && $teacherquery !== '') {
        $sql = "SELECT u.id, u.username, u.firstname, u.lastname, u.email, u.idnumber, u.suspended, u.deleted
                  FROM {user} u
                 WHERE u.deleted = 0
                   AND (
                        " . $DB->sql_like("CONCAT(u.firstname, ' ', u.lastname)", ':q1', false, false) . "
                        OR " . $DB->sql_like('u.username', ':q2', false, false) . "
                        OR " . $DB->sql_like('u.email', ':q3', false, false) . "
                        OR " . $DB->sql_like('u.idnumber', ':q4', false, false) . "
                   )
              ORDER BY u.firstname ASC, u.lastname ASC";
        $rows = $DB->get_records_sql($sql, [
            'q1' => '%' . $teacherquery . '%',
            'q2' => '%' . $teacherquery . '%',
            'q3' => '%' . $teacherquery . '%',
            'q4' => '%' . $teacherquery . '%',
        ]);

        foreach ($rows as $r) {
            $candidates[(int)$r->id] = $r;
        }

        if (empty($candidates)) {
            $all = $DB->get_records_select('user', 'deleted = 0', null, 'firstname ASC,lastname ASC', 'id,username,firstname,lastname,email,idnumber,suspended,deleted');
            $qnorm = dbg_norm($teacherquery);
            foreach ($all as $u) {
                $fullname = trim((string)$u->firstname . ' ' . (string)$u->lastname);
                $hay = [
                    dbg_norm($fullname),
                    dbg_norm((string)$u->username),
                    dbg_norm((string)$u->email),
                    dbg_norm((string)$u->idnumber),
                ];
                foreach ($hay as $candidate) {
                    if ($candidate !== '' && $qnorm !== '' && strpos($candidate, $qnorm) !== false) {
                        $candidates[(int)$u->id] = $u;
                        break;
                    }
                }
            }
        }

        if (count($candidates) === 1) {
            $selected = reset($candidates);
        } else if (!empty($candidates)) {
            $qnorm = dbg_norm($teacherquery);
            $exact = [];
            foreach ($candidates as $u) {
                $fullname = dbg_norm(trim((string)$u->firstname . ' ' . (string)$u->lastname));
                if ($fullname === $qnorm && $qnorm !== '') {
                    $exact[(int)$u->id] = $u;
                }
            }
            if (count($exact) === 1) {
                $selected = reset($exact);
            } else {
                $selected = reset($candidates);
                $warning = 'Multiple teacher matches found. Using first match. Set teacherid to force one.';
            }
        }
    }

    return ['selected' => $selected, 'candidates' => $candidates, 'warning' => $warning];
}

/**
 * Get role shortnames assigned to user.
 * @param int $userid
 * @return string[]
 */
function dbg_user_roles(int $userid): array {
    global $DB;
    $rows = $DB->get_records_sql(
        "SELECT DISTINCT r.shortname
           FROM {role_assignments} ra
           JOIN {role} r ON r.id = ra.roleid
          WHERE ra.userid = :uid
       ORDER BY r.shortname ASC",
        ['uid' => $userid]
    );
    $out = [];
    foreach ($rows as $r) {
        $out[] = (string)$r->shortname;
    }
    return $out;
}

/**
 * Raw class ids using same where clause as get_dashboard_data.
 * @param int $userid
 * @return array<int,int>
 */
function dbg_dashboard_raw_class_set(int $userid): array {
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
                    SELECT 1
                      FROM {gmk_bbb_attendance_relation} r
                     WHERE r.classid = c.id
               )";
    $params = ['now' => $nowwithbuffer];
    if (!$isadmin) {
        $params['instructorid'] = $userid;
    }
    $rows = $DB->get_records_sql($sql, $params);
    $set = [];
    foreach (array_keys($rows) as $id) {
        $set[(int)$id] = (int)$id;
    }
    return $set;
}

/**
 * Find class rows by id or by class query.
 * @param int $classid
 * @param string $classquery
 * @return array<int,stdClass>
 */
function dbg_find_classes(int $classid, string $classquery): array {
    global $DB;

    if ($classid > 0) {
        return $DB->get_records_sql(
            "SELECT c.*, u.username AS instructor_username, u.firstname AS instructor_firstname, u.lastname AS instructor_lastname,
                    cc.fullname AS corecourse_fullname, lpc.learningplanid AS mapped_learningplanid
               FROM {gmk_class} c
          LEFT JOIN {user} u ON u.id = c.instructorid
          LEFT JOIN {course} cc ON cc.id = c.corecourseid
          LEFT JOIN {local_learning_courses} lpc ON lpc.id = c.courseid
              WHERE c.id = :id
           ORDER BY c.id DESC",
            ['id' => $classid]
        );
    }

    if ($classquery === '') {
        return [];
    }

    $rows = $DB->get_records_sql(
        "SELECT c.*, u.username AS instructor_username, u.firstname AS instructor_firstname, u.lastname AS instructor_lastname,
                cc.fullname AS corecourse_fullname, lpc.learningplanid AS mapped_learningplanid
           FROM {gmk_class} c
      LEFT JOIN {user} u ON u.id = c.instructorid
      LEFT JOIN {course} cc ON cc.id = c.corecourseid
      LEFT JOIN {local_learning_courses} lpc ON lpc.id = c.courseid
          WHERE " . $DB->sql_like('c.name', ':q1', false, false) . "
             OR " . $DB->sql_like('cc.fullname', ':q2', false, false) . "
       ORDER BY c.id DESC",
        [
            'q1' => '%' . $classquery . '%',
            'q2' => '%' . $classquery . '%',
        ]
    );

    if (!empty($rows)) {
        return $rows;
    }

    // Accent-insensitive fallback over recent classes.
    $recent = $DB->get_records_sql(
        "SELECT c.*, u.username AS instructor_username, u.firstname AS instructor_firstname, u.lastname AS instructor_lastname,
                cc.fullname AS corecourse_fullname, lpc.learningplanid AS mapped_learningplanid
           FROM {gmk_class} c
      LEFT JOIN {user} u ON u.id = c.instructorid
      LEFT JOIN {course} cc ON cc.id = c.corecourseid
      LEFT JOIN {local_learning_courses} lpc ON lpc.id = c.courseid
       ORDER BY c.id DESC",
        null,
        0,
        800
    );
    $qnorm = dbg_norm($classquery);
    $out = [];
    foreach ($recent as $r) {
        $hay = dbg_norm((string)$r->name . ' ' . (string)($r->corecourse_fullname ?? ''));
        if ($hay !== '' && $qnorm !== '' && strpos($hay, $qnorm) !== false) {
            $out[(int)$r->id] = $r;
        }
    }
    return $out;
}

$resolve = dbg_resolve_teacher($teacherid, $teacherquery);
$selectedteacher = $resolve['selected'];
$teachercandidates = $resolve['candidates'];
$teacherwarning = $resolve['warning'];

$dashboardrawset = [];
$dashboardactive = [];
$calendarbyclass = [];
$dasherror = '';
$roles = [];

if ($selectedteacher) {
    $tid = (int)$selectedteacher->id;
    $dashboardrawset = dbg_dashboard_raw_class_set($tid);
    $roles = dbg_user_roles($tid);

    try {
        $dashdata = \local_grupomakro_core\external\teacher\get_dashboard_data::execute($tid);
        $activeclasses = isset($dashdata['active_classes']) && is_array($dashdata['active_classes']) ? $dashdata['active_classes'] : [];
        $calendar = isset($dashdata['calendar_events']) && is_array($dashdata['calendar_events']) ? $dashdata['calendar_events'] : [];

        foreach ($activeclasses as $ac) {
            $acid = (int)($ac['id'] ?? 0);
            if ($acid > 0) {
                $dashboardactive[$acid] = $ac;
            }
        }
        foreach ($calendar as $ev) {
            $cid = (int)($ev['classid'] ?? 0);
            if ($cid <= 0) {
                continue;
            }
            if (!isset($calendarbyclass[$cid])) {
                $calendarbyclass[$cid] = 0;
            }
            $calendarbyclass[$cid]++;
        }
    } catch (\Throwable $t) {
        $dasherror = $t->getMessage();
    }
}

$classes = dbg_find_classes($classid, $classquery);

echo $OUTPUT->header();
?>
<style>
.dbg-wrap { max-width: 1700px; margin: 16px auto; }
.dbg-card { background: #fff; border: 1px solid #d7dce1; border-radius: 8px; padding: 12px; margin-bottom: 12px; }
.dbg-title { margin: 0 0 8px 0; font-size: 20px; font-weight: 700; }
.dbg-sub { margin: 0 0 8px 0; font-size: 15px; font-weight: 700; }
.dbg-note { color: #4b5563; font-size: 12px; }
.dbg-ok { color: #0f766e; font-weight: 700; }
.dbg-bad { color: #b91c1c; font-weight: 700; }
.dbg-warn { color: #92400e; font-weight: 700; }
table.dbg-table { width: 100%; border-collapse: collapse; font-size: 12px; }
table.dbg-table th, table.dbg-table td { border: 1px solid #d7dce1; padding: 6px; vertical-align: top; text-align: left; }
table.dbg-table th { background: #f3f4f6; }
.dbg-form-row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.dbg-form-row input { padding: 6px 8px; border: 1px solid #cbd5e1; border-radius: 6px; }
.dbg-form-row button { padding: 6px 10px; border: 1px solid #2563eb; border-radius: 6px; background: #2563eb; color: #fff; cursor: pointer; }
</style>

<div class="dbg-wrap">
    <div class="dbg-card">
        <h1 class="dbg-title">Debug Teacher Dashboard Missing Class</h1>
        <form method="get" class="dbg-form-row">
            <label for="teacher"><strong>Teacher</strong></label>
            <input id="teacher" name="teacher" type="text" size="36" value="<?php echo dbg_h($teacherquery); ?>" />
            <label for="teacherid"><strong>Teacher ID</strong></label>
            <input id="teacherid" name="teacherid" type="number" min="0" step="1" value="<?php echo (int)$teacherid; ?>" />
            <label for="classname"><strong>Class name</strong></label>
            <input id="classname" name="classname" type="text" size="56" value="<?php echo dbg_h($classquery); ?>" />
            <label for="classid"><strong>Class ID</strong></label>
            <input id="classid" name="classid" type="number" min="0" step="1" value="<?php echo (int)$classid; ?>" />
            <button type="submit">Diagnose</button>
        </form>
        <p class="dbg-note">Rules used here are the same as <code>get_dashboard_data.php</code>: <code>closed=0</code>, <code>enddate &gt;= now-7d</code>, <code>exists gmk_bbb_attendance_relation</code>, and instructor match unless admin.</p>
    </div>

    <div class="dbg-card">
        <h2 class="dbg-sub">Teacher resolution</h2>
        <?php if (empty($teachercandidates)): ?>
            <p class="dbg-bad">No teacher match found. Adjust teacher query or set teacherid.</p>
        <?php else: ?>
            <table class="dbg-table">
                <thead>
                    <tr>
                        <th>User ID</th>
                        <th>Name</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>ID Number</th>
                        <th>Suspended</th>
                        <th>Selected</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($teachercandidates as $u): ?>
                        <?php $isselected = $selectedteacher && ((int)$u->id === (int)$selectedteacher->id); ?>
                        <tr>
                            <td><?php echo (int)$u->id; ?></td>
                            <td><?php echo dbg_h(trim((string)$u->firstname . ' ' . (string)$u->lastname)); ?></td>
                            <td><?php echo dbg_h($u->username); ?></td>
                            <td><?php echo dbg_h($u->email); ?></td>
                            <td><?php echo dbg_h($u->idnumber); ?></td>
                            <td><?php echo ((int)$u->suspended === 1) ? '<span class="dbg-warn">YES</span>' : '<span class="dbg-ok">NO</span>'; ?></td>
                            <td><?php echo $isselected ? '<span class="dbg-ok">YES</span>' : '<span class="dbg-bad">NO</span>'; ?></td>
                            <td>
                                <a href="<?php
                                $pick = new moodle_url('/local/grupomakro_core/pages/debug_teacher_dashboard_missing_class.php', [
                                    'teacherid' => (int)$u->id,
                                    'teacher' => $teacherquery,
                                    'classname' => $classquery,
                                    'classid' => $classid,
                                ]);
                                echo dbg_h($pick->out(false));
                                ?>">Use</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($teacherwarning !== ''): ?>
                <p class="dbg-warn"><?php echo dbg_h($teacherwarning); ?></p>
            <?php endif; ?>
        <?php endif; ?>
    </div>

<?php if ($selectedteacher): ?>
    <?php
    $tid = (int)$selectedteacher->id;
    $isadmin = is_siteadmin($tid);
    $now = time();
    $nowwithbuffer = $now - (7 * 24 * 60 * 60);
    ?>
    <div class="dbg-card">
        <h2 class="dbg-sub">Selected teacher summary</h2>
        <p>
            <strong>User ID:</strong> <?php echo $tid; ?>
            | <strong>Name:</strong> <?php echo dbg_h(trim((string)$selectedteacher->firstname . ' ' . (string)$selectedteacher->lastname)); ?>
            | <strong>Username:</strong> <?php echo dbg_h($selectedteacher->username); ?>
            | <strong>Admin:</strong> <?php echo $isadmin ? '<span class="dbg-warn">YES</span>' : '<span class="dbg-ok">NO</span>'; ?>
            | <strong>Roles:</strong> <?php echo dbg_h(empty($roles) ? '-' : implode(', ', $roles)); ?>
        </p>
        <p>
            <strong>Dashboard raw classes:</strong> <?php echo count($dashboardrawset); ?>
            | <strong>Dashboard active_classes:</strong> <?php echo count($dashboardactive); ?>
            | <strong>Calendar class IDs:</strong> <?php echo count($calendarbyclass); ?>
        </p>
        <?php if ($dasherror !== ''): ?>
            <p class="dbg-bad"><strong>get_dashboard_data error:</strong> <?php echo dbg_h($dasherror); ?></p>
        <?php endif; ?>
        <p class="dbg-note">Time now: <?php echo dbg_h(userdate($now)); ?> | Buffer threshold (now-7d): <?php echo dbg_h(userdate($nowwithbuffer)); ?></p>
    </div>

    <div class="dbg-card">
        <h2 class="dbg-sub">Class diagnostics</h2>
        <?php if (empty($classes)): ?>
            <p class="dbg-bad">No classes found with current class filter.</p>
        <?php else: ?>
            <table class="dbg-table">
                <thead>
                    <tr>
                        <th>Class ID</th>
                        <th>Name</th>
                        <th>Instructor</th>
                        <th>Core Course</th>
                        <th>approved</th>
                        <th>closed</th>
                        <th>enddate</th>
                        <th>BBB relation rows</th>
                        <th>Expected visible?</th>
                        <th>Raw SQL visible?</th>
                        <th>API active_classes?</th>
                        <th>Calendar events</th>
                        <th>Fail reasons</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($classes as $c): ?>
                        <?php
                        $cid = (int)$c->id;
                        $relrows = $DB->get_records('gmk_bbb_attendance_relation', ['classid' => $cid], 'id ASC');
                        $hasrelation = !empty($relrows);
                        $instructormatch = $isadmin || ((int)$c->instructorid === $tid);
                        $closedok = ((int)$c->closed === 0);
                        $endok = ((int)$c->enddate >= $nowwithbuffer);
                        $expected = $instructormatch && $closedok && $endok && $hasrelation;
                        $inraw = isset($dashboardrawset[$cid]);
                        $inapi = isset($dashboardactive[$cid]);
                        $calcount = (int)($calendarbyclass[$cid] ?? 0);
                        $fails = [];
                        if (!$instructormatch) {
                            $fails[] = 'instructor_mismatch';
                        }
                        if (!$closedok) {
                            $fails[] = 'closed=1';
                        }
                        if (!$endok) {
                            $fails[] = 'enddate_before_now_minus_7d';
                        }
                        if (!$hasrelation) {
                            $fails[] = 'missing_gmk_bbb_attendance_relation';
                        }
                        if ($expected && !$inapi) {
                            $fails[] = 'unexpected_absence_in_get_dashboard_data';
                        }
                        ?>
                        <tr>
                            <td><?php echo $cid; ?></td>
                            <td><?php echo dbg_h($c->name); ?></td>
                            <td>
                                id=<?php echo (int)$c->instructorid; ?><br>
                                <?php echo dbg_h(trim((string)$c->instructor_firstname . ' ' . (string)$c->instructor_lastname)); ?>
                            </td>
                            <td>
                                core=<?php echo (int)$c->corecourseid; ?><br>
                                <?php echo dbg_h((string)($c->corecourse_fullname ?? '')); ?>
                            </td>
                            <td><?php echo (int)$c->approved; ?></td>
                            <td><?php echo (int)$c->closed; ?></td>
                            <td><?php echo dbg_h(userdate((int)$c->enddate)); ?></td>
                            <td><?php echo count($relrows); ?></td>
                            <td><?php echo $expected ? '<span class="dbg-ok">YES</span>' : '<span class="dbg-bad">NO</span>'; ?></td>
                            <td><?php echo $inraw ? '<span class="dbg-ok">YES</span>' : '<span class="dbg-bad">NO</span>'; ?></td>
                            <td><?php echo $inapi ? '<span class="dbg-ok">YES</span>' : '<span class="dbg-bad">NO</span>'; ?></td>
                            <td><?php echo $calcount; ?></td>
                            <td>
                                <?php
                                if (empty($fails)) {
                                    echo '<span class="dbg-ok">none</span>';
                                } else {
                                    echo dbg_h(implode(', ', $fails));
                                }
                                ?>
                            </td>
                        </tr>
                        <?php if (!empty($relrows)): ?>
                            <tr>
                                <td></td>
                                <td colspan="12">
                                    <span class="dbg-note">Relation rows (first 5): </span>
                                    <?php
                                    $i = 0;
                                    foreach ($relrows as $rr) {
                                        $i++;
                                        if ($i > 5) {
                                            break;
                                        }
                                        $raw = json_encode($rr, JSON_UNESCAPED_UNICODE);
                                        if ($raw === false) {
                                            $raw = '[json_encode_error]';
                                        }
                                        echo '<div class="dbg-note">' . dbg_h($raw) . '</div>';
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
<?php endif; ?>

    <div class="dbg-note">Debug run at <?php echo dbg_h(userdate(time())); ?> by user id=<?php echo (int)$USER->id; ?></div>
</div>

<?php
echo $OUTPUT->footer();

