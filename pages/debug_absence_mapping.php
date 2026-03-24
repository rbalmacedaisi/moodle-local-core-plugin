<?php
/**
 * Debug page: absence mapping and attendance linkage diagnostics.
 *
 * This page is intentionally defensive across schema variants:
 * - Period table can be gmk_academic_periods or gmk_academic_period.
 * - gmk_class can reference period with periodid or academicperiodid.
 */

$configpath = __DIR__ . '/../../config.php';
if (!file_exists($configpath)) {
    $configpath = __DIR__ . '/../../../config.php';
}
if (!file_exists($configpath)) {
    $configpath = __DIR__ . '/../../../../config.php';
}
require_once($configpath);

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_absence_mapping.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Debug ausencia: mapeo de asistencia');
$PAGE->set_heading('Debug ausencia: mapeo de asistencia');
$PAGE->set_pagelayout('admin');

$classid = optional_param('classid', 0, PARAM_INT);
$periodid = optional_param('periodid', 0, PARAM_INT);

/**
 * Check if a table exists.
 *
 * @param string $tablename
 * @return bool
 */
function dbg_abs_table_exists(string $tablename): bool {
    global $DB;
    try {
        $DB->get_columns($tablename);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Check if a field exists in a table.
 *
 * @param string $tablename
 * @param string $fieldname
 * @return bool
 */
function dbg_abs_field_exists(string $tablename, string $fieldname): bool {
    global $DB;
    if (!dbg_abs_table_exists($tablename)) {
        return false;
    }
    $cols = $DB->get_columns($tablename);
    return isset($cols[$fieldname]);
}

/**
 * Detect institutional period table.
 *
 * @return string
 */
function dbg_abs_detect_period_table(): string {
    if (dbg_abs_table_exists('gmk_academic_periods')) {
        return 'gmk_academic_periods';
    }
    if (dbg_abs_table_exists('gmk_academic_period')) {
        return 'gmk_academic_period';
    }
    return '';
}

/**
 * Detect period field in gmk_class.
 *
 * @return string
 */
function dbg_abs_detect_class_period_field(): string {
    if (dbg_abs_field_exists('gmk_class', 'periodid')) {
        return 'periodid';
    }
    if (dbg_abs_field_exists('gmk_class', 'academicperiodid')) {
        return 'academicperiodid';
    }
    return '';
}

/**
 * Detect display name field for gmk_class.
 *
 * @return string
 */
function dbg_abs_detect_class_name_field(): string {
    if (dbg_abs_field_exists('gmk_class', 'coursename')) {
        return 'coursename';
    }
    return 'name';
}

/**
 * Load period dropdown options.
 *
 * @param string $periodtable
 * @return array<int,string>
 */
function dbg_abs_load_period_options(string $periodtable): array {
    global $DB;

    if ($periodtable === '') {
        return [];
    }

    $cols = $DB->get_columns($periodtable);
    $select = ['id'];
    foreach (['code', 'name', 'period', 'startdate'] as $field) {
        if (isset($cols[$field])) {
            $select[] = $field;
        }
    }

    $orderby = isset($cols['startdate']) ? 'startdate DESC, id DESC' : 'id DESC';
    $records = $DB->get_records($periodtable, null, $orderby, implode(',', $select), 0, 250);

    $options = [];
    foreach ($records as $row) {
        $parts = [];
        $code = property_exists($row, 'code') ? trim((string)$row->code) : '';
        $name = property_exists($row, 'name') ? trim((string)$row->name) : '';
        $period = property_exists($row, 'period') ? trim((string)$row->period) : '';

        if ($code !== '') {
            $parts[] = $code;
        }
        if ($name !== '') {
            $parts[] = $name;
        }
        if (empty($parts) && $period !== '') {
            $parts[] = $period;
        }

        $label = empty($parts) ? ('Periodo #' . (int)$row->id) : implode(' - ', array_unique($parts));
        $options[(int)$row->id] = $label;
    }

    return $options;
}

/**
 * Load classes for picker.
 *
 * @param string $periodfield
 * @param int $periodid
 * @return array<int,stdClass>
 */
function dbg_abs_load_classes(string $periodfield, int $periodid): array {
    global $DB;

    $params = [];
    $where = 'gc.attendancemoduleid > 0';
    $namefield = dbg_abs_detect_class_name_field();
    $approvedselect = dbg_abs_field_exists('gmk_class', 'approved') ? 'gc.approved' : '0 AS approved';
    $closedselect = dbg_abs_field_exists('gmk_class', 'closed') ? 'gc.closed' : '0 AS closed';

    if ($periodid > 0 && $periodfield !== '') {
        $where .= " AND gc.$periodfield = :periodid";
        $params['periodid'] = $periodid;
    }

    $periodselect = $periodfield !== '' ? ", gc.$periodfield AS periodrefid" : ', 0 AS periodrefid';
    $nameselect = $namefield === 'coursename'
        ? 'gc.name, gc.coursename'
        : 'gc.name, gc.name AS coursename';

    $sql = "SELECT gc.id, $nameselect, gc.groupid, gc.attendancemoduleid, $approvedselect, $closedselect
                   $periodselect
              FROM {gmk_class} gc
             WHERE $where
          ORDER BY gc.id DESC";

    return array_values($DB->get_records_sql($sql, $params, 0, 250));
}

/**
 * Build user-facing label for class picker.
 *
 * @param stdClass $class
 * @param array<int,string> $periodoptions
 * @return string
 */
function dbg_abs_class_label(stdClass $class, array $periodoptions): string {
    $name = trim((string)($class->coursename ?? $class->name ?? ''));
    $label = '#' . (int)$class->id . ' - ' . $name
        . ' | grp=' . (int)$class->groupid
        . ' | cm=' . (int)$class->attendancemoduleid;

    $prefid = (int)($class->periodrefid ?? 0);
    if ($prefid > 0) {
        $periodtxt = $periodoptions[$prefid] ?? ('Periodo #' . $prefid);
        $label .= ' | ' . $periodtxt;
    }

    return $label;
}

/**
 * Resolve attendance mapping for class.
 *
 * @param stdClass $class
 * @return array{cmid:int,attid:int,att:?stdClass,steps:array<int,string>}
 */
function dbg_abs_resolve_attendance_mapping(stdClass $class): array {
    global $DB;

    $steps = [];
    $cmid = (int)($class->attendancemoduleid ?? 0);
    $attid = 0;

    if ($cmid > 0) {
        $cm = $DB->get_record_sql(
            "SELECT cm.id, cm.instance, cm.course, m.name AS modulename
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.id = :cmid",
            ['cmid' => $cmid]
        );

        if ($cm) {
            if ($cm->modulename === 'attendance') {
                $attid = (int)$cm->instance;
                $steps[] = 'OK: gmk_class.attendancemoduleid apunta a un modulo attendance valido.';
            } else {
                $steps[] = 'WARN: attendancemoduleid existe, pero module=' . $cm->modulename . ' (no attendance).';
            }
        } else {
            $steps[] = 'ERROR: gmk_class.attendancemoduleid no existe en course_modules.';
        }
    } else {
        $steps[] = 'WARN: gmk_class.attendancemoduleid esta vacio o en 0.';
    }

    if ($attid <= 0 && dbg_abs_table_exists('gmk_bbb_attendance_relation')) {
        $relattid = (int)$DB->get_field('gmk_bbb_attendance_relation', 'attendanceid', ['classid' => (int)$class->id], IGNORE_MULTIPLE);
        if ($relattid > 0) {
            $attid = $relattid;
            $steps[] = 'OK (fallback): attendanceid resuelto desde gmk_bbb_attendance_relation.';
        }
    }

    if ($attid <= 0 && dbg_abs_table_exists('gmk_bbb_attendance_relation')) {
        $relcmid = (int)$DB->get_field('gmk_bbb_attendance_relation', 'attendancemoduleid', ['classid' => (int)$class->id], IGNORE_MULTIPLE);
        if ($relcmid > 0) {
            $cm = $DB->get_record_sql(
                "SELECT cm.id, cm.instance, cm.course, m.name AS modulename
                   FROM {course_modules} cm
                   JOIN {modules} m ON m.id = cm.module
                  WHERE cm.id = :cmid",
                ['cmid' => $relcmid]
            );
            if ($cm && $cm->modulename === 'attendance') {
                $cmid = $relcmid;
                $attid = (int)$cm->instance;
                $steps[] = 'OK (fallback): attendancemoduleid resuelto desde relation y convertido a attendanceid.';
            }
        }
    }

    if ($attid <= 0) {
        $coursecandidates = [];
        if (!empty($class->courseid)) {
            $coursecandidates[] = (int)$class->courseid;
        }
        if (!empty($class->corecourseid)) {
            $coursecandidates[] = (int)$class->corecourseid;
        }
        $coursecandidates = array_values(array_unique(array_filter($coursecandidates)));

        foreach ($coursecandidates as $cid) {
            $candidate = (int)$DB->get_field('attendance', 'id', ['course' => $cid], IGNORE_MULTIPLE);
            if ($candidate > 0) {
                $attid = $candidate;
                $steps[] = 'WARN (fallback amplio): attendanceid inferido por attendance.course=' . $cid . '.';
                break;
            }
        }
    }

    $att = null;
    if ($attid > 0) {
        $att = $DB->get_record('attendance', ['id' => $attid], 'id,course,name', IGNORE_MISSING);
        if (!$att) {
            $steps[] = 'ERROR: attendanceid resuelto, pero no existe el registro en tabla attendance.';
        }
    }

    return [
        'cmid' => $cmid,
        'attid' => $attid,
        'att' => $att,
        'steps' => $steps,
    ];
}

/**
 * Return class relation rows.
 *
 * @param int $classid
 * @return array<int,stdClass>
 */
function dbg_abs_get_relation_rows(int $classid): array {
    global $DB;

    if (!dbg_abs_table_exists('gmk_bbb_attendance_relation')) {
        return [];
    }

    $fields = 'id,classid,attendancemoduleid,attendanceid,attendancesessionid,bbbmoduleid,bbbid,sectionid,timecreated,timemodified';
    return array_values($DB->get_records('gmk_bbb_attendance_relation', ['classid' => $classid], 'id DESC', $fields, 0, 250));
}

/**
 * Unique and normalized integer list.
 *
 * @param array<int,mixed> $items
 * @return array<int,int>
 */
function dbg_abs_unique_ints(array $items): array {
    $out = [];
    foreach ($items as $item) {
        $v = (int)$item;
        if ($v > 0) {
            $out[$v] = $v;
        }
    }
    return array_values($out);
}

/**
 * Build candidate session sets for analysis.
 *
 * @param stdClass $class
 * @param int $attendanceid
 * @param int $nowts
 * @return array{strict:array<int,int>,groupor0:array<int,int>,relation:array<int,int>,union:array<int,int>}
 */
function dbg_abs_build_session_sets(stdClass $class, int $attendanceid, int $nowts): array {
    global $DB;

    $sets = [
        'strict' => [],
        'groupor0' => [],
        'relation' => [],
        'union' => [],
    ];

    if ($attendanceid <= 0) {
        return $sets;
    }

    $groupid = (int)($class->groupid ?? 0);

    $sets['strict'] = dbg_abs_unique_ints($DB->get_fieldset_sql(
        "SELECT id
           FROM {attendance_sessions}
          WHERE attendanceid = :attid
            AND groupid = :groupid
            AND sessdate < :nowts
          ORDER BY sessdate DESC",
        ['attid' => $attendanceid, 'groupid' => $groupid, 'nowts' => $nowts]
    ));

    $sets['groupor0'] = dbg_abs_unique_ints($DB->get_fieldset_sql(
        "SELECT id
           FROM {attendance_sessions}
          WHERE attendanceid = :attid
            AND (groupid = :groupid OR groupid = 0)
            AND sessdate < :nowts
          ORDER BY sessdate DESC",
        ['attid' => $attendanceid, 'groupid' => $groupid, 'nowts' => $nowts]
    ));

    if (dbg_abs_table_exists('gmk_bbb_attendance_relation')) {
        $relsessionids = dbg_abs_unique_ints($DB->get_fieldset_select(
            'gmk_bbb_attendance_relation',
            'attendancesessionid',
            'classid = :classid AND attendancesessionid > 0',
            ['classid' => (int)$class->id]
        ));

        if (!empty($relsessionids)) {
            [$insql, $params] = $DB->get_in_or_equal($relsessionids, SQL_PARAMS_NAMED, 'sid');
            $params['nowts'] = $nowts;
            $sets['relation'] = dbg_abs_unique_ints($DB->get_fieldset_sql(
                "SELECT id
                   FROM {attendance_sessions}
                  WHERE id $insql
                    AND sessdate < :nowts",
                $params
            ));
        }
    }

    $sets['union'] = dbg_abs_unique_ints(array_merge($sets['groupor0'], $sets['relation']));
    return $sets;
}

/**
 * Keep only sessions where attendance was actually taken.
 *
 * @param array<int,int> $sessionids
 * @return array<int,int>
 */
function dbg_abs_filter_taken_session_ids(array $sessionids): array {
    global $DB;

    if (empty($sessionids)) {
        return [];
    }

    [$insql, $params] = $DB->get_in_or_equal($sessionids, SQL_PARAMS_NAMED, 'tss');
    $taken = [];

    $fromlogs = $DB->get_fieldset_sql(
        "SELECT DISTINCT l.sessionid
           FROM {attendance_log} l
          WHERE l.sessionid $insql",
        $params
    );
    foreach ($fromlogs as $sid) {
        $sid = (int)$sid;
        if ($sid > 0) {
            $taken[$sid] = $sid;
        }
    }

    try {
        $fromlasttaken = $DB->get_fieldset_sql(
            "SELECT s.id
               FROM {attendance_sessions} s
              WHERE s.id $insql
                AND COALESCE(s.lasttaken, 0) > 0",
            $params
        );
        foreach ($fromlasttaken as $sid) {
            $sid = (int)$sid;
            if ($sid > 0) {
                $taken[$sid] = $sid;
            }
        }
    } catch (Throwable $e) {
        // Compatibility fallback for environments without lasttaken column.
    }

    $out = array_values($taken);
    sort($out);
    return $out;
}

/**
 * Compute absences per student using latest log per session.
 *
 * Absence rule:
 * - latest status grade <= 0 or null => absence
 * - no log for that session/student => not counted here
 *
 * @param array<int,int> $sessionids
 * @param array<int,int> $studentids
 * @return array<int,array{present:int,logged:int,absences:int}>
 */
function dbg_abs_compute_student_absences(array $sessionids, array $studentids): array {
    global $DB;

    $stats = [];
    foreach ($studentids as $uid) {
        $stats[(int)$uid] = ['present' => 0, 'logged' => 0, 'absences' => 0];
    }

    if (empty($sessionids) || empty($studentids)) {
        return $stats;
    }

    [$sessinsql, $sessparams] = $DB->get_in_or_equal($sessionids, SQL_PARAMS_NAMED, 'ss');
    [$userinsql, $userparams] = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED, 'uu');

    $params = array_merge($sessparams, $userparams);

    $sql = "SELECT l.studentid, l.sessionid, ast.grade
              FROM {attendance_log} l
              JOIN (
                    SELECT studentid, sessionid, MAX(id) AS maxid
                      FROM {attendance_log}
                     WHERE sessionid $sessinsql
                       AND studentid $userinsql
                  GROUP BY studentid, sessionid
                   ) lastlog ON lastlog.maxid = l.id
         LEFT JOIN {attendance_statuses} ast ON ast.id = l.statusid";

    $rows = $DB->get_records_sql($sql, $params);
    foreach ($rows as $row) {
        $uid = (int)$row->studentid;
        if (!isset($stats[$uid])) {
            continue;
        }
        $stats[$uid]['logged']++;
        if ($row->grade !== null && (float)$row->grade > 0) {
            $stats[$uid]['present']++;
        } else {
            $stats[$uid]['absences']++;
        }
    }

    return $stats;
}

$periodtable = dbg_abs_detect_period_table();
$classperiodfield = dbg_abs_detect_class_period_field();
$periodoptions = dbg_abs_load_period_options($periodtable);
$classlist = dbg_abs_load_classes($classperiodfield, $periodid);

if ($classid > 0) {
    $present = false;
    foreach ($classlist as $classrow) {
        if ((int)$classrow->id === $classid) {
            $present = true;
            break;
        }
    }

    if (!$present) {
        $namefield = dbg_abs_detect_class_name_field();
        $nameselect = $namefield === 'coursename'
            ? 'gc.name, gc.coursename'
            : 'gc.name, gc.name AS coursename';
        $approvedselect = dbg_abs_field_exists('gmk_class', 'approved') ? 'gc.approved' : '0 AS approved';
        $closedselect = dbg_abs_field_exists('gmk_class', 'closed') ? 'gc.closed' : '0 AS closed';
        $periodselect = $classperiodfield !== '' ? ", gc.$classperiodfield AS periodrefid" : ', 0 AS periodrefid';
        $extra = $DB->get_record_sql(
            "SELECT gc.id, $nameselect, gc.groupid, gc.attendancemoduleid, $approvedselect, $closedselect
                    $periodselect
               FROM {gmk_class} gc
              WHERE gc.id = :classid",
            ['classid' => $classid]
        );
        if ($extra) {
            array_unshift($classlist, $extra);
        }
    }
}

echo $OUTPUT->header();

echo '<style>
.dbg-section { background:#f8fafc; border:1px solid #cbd5e1; border-radius:6px; padding:16px; margin:16px 0; }
.dbg-section h3 { margin:0 0 10px; color:#1e40af; font-size:14px; }
.dbg-info { color:#1d4ed8; font-weight:bold; }
.dbg-ok { color:#15803d; font-weight:bold; }
.dbg-warn { color:#b45309; font-weight:bold; }
.dbg-err { color:#b91c1c; font-weight:bold; }
table.dbg { border-collapse:collapse; width:100%; margin-top:8px; }
table.dbg th { background:#e2e8f0; padding:6px 8px; text-align:left; border:1px solid #cbd5e1; white-space:nowrap; }
table.dbg td { padding:6px 8px; border:1px solid #e2e8f0; vertical-align:top; }
.form-row { display:flex; gap:12px; align-items:flex-end; flex-wrap:wrap; padding:12px; background:#fff; border:1px solid #cbd5e1; border-radius:6px; }
.chip { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; }
.chip-green { background:#dcfce7; color:#166534; }
.chip-red { background:#fee2e2; color:#991b1b; }
.chip-gray { background:#f1f5f9; color:#334155; }
</style>';

echo '<div class="dbg-section">';
echo '<h3>Filtros de depuracion</h3>';
echo '<form method="get" class="form-row">';

if ($periodtable !== '' && $classperiodfield !== '' && !empty($periodoptions)) {
    echo '<div><label>Periodo<br><select name="periodid" style="min-width:260px;padding:4px;">';
    echo '<option value="0">Todos</option>';
    foreach ($periodoptions as $pid => $plabel) {
        $sel = ($periodid === (int)$pid) ? ' selected' : '';
        echo '<option value="' . (int)$pid . '"' . $sel . '>' . s($plabel) . '</option>';
    }
    echo '</select></label></div>';
} else {
    echo '<input type="hidden" name="periodid" value="0">';
}

echo '<div><label>Clase<br><select name="classid" style="min-width:620px;padding:4px;">';
echo '<option value="0">Seleccione una clase</option>';
foreach ($classlist as $c) {
    $selected = ((int)$c->id === $classid) ? ' selected' : '';
    echo '<option value="' . (int)$c->id . '"' . $selected . '>' . s(dbg_abs_class_label($c, $periodoptions)) . '</option>';
}
echo '</select></label></div>';
echo '<div style="padding-bottom:4px;"><button type="submit" style="padding:6px 16px;">Inspeccionar</button></div>';
echo '</form>';

if ($periodtable === '') {
    echo '<p class="dbg-warn" style="margin-top:10px;">No existe tabla de periodos compatible (gmk_academic_periods / gmk_academic_period). El filtro por periodo queda deshabilitado.</p>';
} elseif ($classperiodfield === '') {
    echo '<p class="dbg-warn" style="margin-top:10px;">gmk_class no tiene campo periodid ni academicperiodid. El filtro por periodo queda deshabilitado.</p>';
} elseif (empty($periodoptions)) {
    echo '<p class="dbg-warn" style="margin-top:10px;">La tabla de periodos existe, pero no tiene datos para poblar el selector.</p>';
}

if (!empty($classlist)) {
    echo '<table class="dbg" style="margin-top:12px;">';
    echo '<tr><th>ID</th><th>Nombre</th><th>Periodo</th><th>groupid</th><th>attendancemoduleid</th><th></th></tr>';
    foreach ($classlist as $c) {
        $selrow = ((int)$c->id === $classid) ? ' style="background:#dbeafe;"' : '';
        $prefid = (int)($c->periodrefid ?? 0);
        $ptxt = $prefid > 0 ? ($periodoptions[$prefid] ?? ('Periodo #' . $prefid)) : '-';
        $url = (new moodle_url('/local/grupomakro_core/pages/debug_absence_mapping.php', ['classid' => (int)$c->id, 'periodid' => $periodid]))->out(false);
        $name = trim((string)($c->coursename ?? $c->name ?? ''));
        echo '<tr' . $selrow . '>'
            . '<td>' . (int)$c->id . '</td>'
            . '<td>' . s($name) . '</td>'
            . '<td>' . s($ptxt) . '</td>'
            . '<td>' . (int)$c->groupid . '</td>'
            . '<td>' . (int)$c->attendancemoduleid . '</td>'
            . '<td><a href="' . $url . '">Inspeccionar</a></td>'
            . '</tr>';
    }
    echo '</table>';
}

echo '</div>';

if ($classid <= 0) {
    echo $OUTPUT->footer();
    exit;
}

$class = $DB->get_record('gmk_class', ['id' => $classid], '*', MUST_EXIST);
$now = time();

// Section 1: class core data.
echo '<div class="dbg-section">';
echo '<h3>1) Registro base de gmk_class</h3>';
$allclasscols = $DB->get_columns('gmk_class');
$fields = ['id', 'name', 'coursename', 'learningplanid', 'periodid', 'academicperiodid', 'courseid', 'corecourseid', 'groupid', 'attendancemoduleid', 'approved', 'closed', 'initdate', 'enddate'];
echo '<table class="dbg"><tr><th>Campo</th><th>Valor</th></tr>';
foreach ($fields as $f) {
    if (!isset($allclasscols[$f])) {
        continue;
    }
    $v = $class->$f ?? '';
    if (($f === 'initdate' || $f === 'enddate') && is_numeric($v) && (int)$v > 0) {
        $v = (int)$v . ' (' . date('Y-m-d H:i', (int)$v) . ')';
    }
    echo '<tr><td><b>' . s($f) . '</b></td><td>' . s((string)$v) . '</td></tr>';
}
echo '</table>';
echo '</div>';

// Section 2: attendance mapping resolution.
$mapping = dbg_abs_resolve_attendance_mapping($class);
$attendanceid = (int)$mapping['attid'];
echo '<div class="dbg-section">';
echo '<h3>2) Resolucion de mapeo class -> attendance</h3>';
echo '<p><b>attendancemoduleid efectivo:</b> ' . (int)$mapping['cmid'] . '<br>';
echo '<b>attendanceid efectivo:</b> ' . $attendanceid . '</p>';

if (!empty($mapping['att'])) {
    $att = $mapping['att'];
    echo '<p><span class="dbg-ok">attendance encontrado:</span> id=' . (int)$att->id . ' | course=' . (int)$att->course . ' | name=' . s((string)$att->name) . '</p>';
} else {
    echo '<p><span class="dbg-err">No se pudo resolver attendanceid valido.</span></p>';
}

if (!empty($mapping['steps'])) {
    echo '<ul>';
    foreach ($mapping['steps'] as $step) {
        echo '<li>' . s($step) . '</li>';
    }
    echo '</ul>';
}
echo '</div>';

// Section 3: relation rows.
echo '<div class="dbg-section">';
echo '<h3>3) Relacion gmk_bbb_attendance_relation para la clase</h3>';
if (!dbg_abs_table_exists('gmk_bbb_attendance_relation')) {
    echo '<p class="dbg-warn">No existe la tabla gmk_bbb_attendance_relation en este entorno.</p>';
} else {
    $relrows = dbg_abs_get_relation_rows((int)$class->id);
    echo '<p>Total filas de relacion: <b>' . count($relrows) . '</b></p>';
    if (!empty($relrows)) {
        echo '<table class="dbg">';
        echo '<tr><th>id</th><th>attendanceid</th><th>attendancemoduleid</th><th>attendancesessionid</th><th>bbbmoduleid</th><th>bbbid</th><th>sectionid</th><th>timemodified</th></tr>';
        foreach (array_slice($relrows, 0, 50) as $rr) {
            $tm = !empty($rr->timemodified) ? date('Y-m-d H:i:s', (int)$rr->timemodified) : '-';
            echo '<tr>'
                . '<td>' . (int)$rr->id . '</td>'
                . '<td>' . (int)$rr->attendanceid . '</td>'
                . '<td>' . (int)$rr->attendancemoduleid . '</td>'
                . '<td>' . (int)$rr->attendancesessionid . '</td>'
                . '<td>' . (int)$rr->bbbmoduleid . '</td>'
                . '<td>' . (int)$rr->bbbid . '</td>'
                . '<td>' . (int)$rr->sectionid . '</td>'
                . '<td>' . s($tm) . '</td>'
                . '</tr>';
        }
        echo '</table>';
    }
}
echo '</div>';

// Section 4: sessions mapping.
$sessionsets = dbg_abs_build_session_sets($class, $attendanceid, $now);
$takensessionids = dbg_abs_filter_taken_session_ids($sessionsets['union']);
echo '<div class="dbg-section">';
echo '<h3>4) Sesiones detectadas para conteo de inasistencias</h3>';
if ($attendanceid <= 0) {
    echo '<p class="dbg-err">Sin attendanceid no se puede evaluar sesiones.</p>';
} else {
    echo '<table class="dbg">';
    echo '<tr><th>Regla</th><th>Cantidad sesiones pasadas</th></tr>';
    echo '<tr><td>strict: attendanceid + groupid exacto</td><td>' . count($sessionsets['strict']) . '</td></tr>';
    echo '<tr><td>groupor0: attendanceid + (groupid o 0)</td><td>' . count($sessionsets['groupor0']) . '</td></tr>';
    echo '<tr><td>relation: attendancesessionid en relation</td><td>' . count($sessionsets['relation']) . '</td></tr>';
    echo '<tr><td>union usada en debug</td><td><b>' . count($sessionsets['union']) . '</b></td></tr>';
    echo '<tr><td>sesiones tomadas (logs/lasttaken)</td><td><b>' . count($takensessionids) . '</b></td></tr>';
    echo '</table>';

    $groupcounts = $DB->get_records_sql(
        "SELECT groupid, COUNT(*) AS total,
                SUM(CASE WHEN sessdate < :nowts THEN 1 ELSE 0 END) AS past
           FROM {attendance_sessions}
          WHERE attendanceid = :attid
       GROUP BY groupid
       ORDER BY groupid",
        ['attid' => $attendanceid, 'nowts' => $now]
    );

    if (!empty($groupcounts)) {
        echo '<p style="margin-top:10px"><b>Distribucion de sesiones por groupid:</b></p>';
        echo '<table class="dbg">';
        echo '<tr><th>groupid</th><th>total</th><th>pasadas</th><th>lectura</th></tr>';
        foreach ($groupcounts as $gc) {
            $gid = (int)$gc->groupid;
            $note = ($gid === (int)$class->groupid)
                ? '<span class="dbg-ok">coincide con class.groupid</span>'
                : (($gid === 0)
                    ? '<span class="dbg-info">sesiones globales (groupid=0)</span>'
                    : '<span class="dbg-warn">otro grupo</span>');

            echo '<tr>'
                . '<td>' . $gid . '</td>'
                . '<td>' . (int)$gc->total . '</td>'
                . '<td>' . (int)$gc->past . '</td>'
                . '<td>' . $note . '</td>'
                . '</tr>';
        }
        echo '</table>';
    }

    if (!empty($takensessionids)) {
        [$insql, $inparams] = $DB->get_in_or_equal($takensessionids, SQL_PARAMS_NAMED, 'sid');
        $samples = $DB->get_records_sql(
            "SELECT id, attendanceid, groupid, sessdate, duration, description
               FROM {attendance_sessions}
              WHERE id $insql
           ORDER BY sessdate DESC",
            $inparams,
            0,
            20
        );

        if (!empty($samples)) {
            echo '<p style="margin-top:10px"><b>Ultimas sesiones tomadas:</b></p>';
            echo '<table class="dbg">';
            echo '<tr><th>sessionid</th><th>date</th><th>groupid</th><th>duration</th><th>description</th></tr>';
            foreach ($samples as $srow) {
                echo '<tr>'
                    . '<td>' . (int)$srow->id . '</td>'
                    . '<td>' . s(date('Y-m-d H:i:s', (int)$srow->sessdate)) . '</td>'
                    . '<td>' . (int)$srow->groupid . '</td>'
                    . '<td>' . (int)$srow->duration . '</td>'
                    . '<td>' . s((string)$srow->description) . '</td>'
                    . '</tr>';
            }
            echo '</table>';
        }
    }
}
echo '</div>';

// Section 5: statuses.
echo '<div class="dbg-section">';
echo '<h3>5) attendance_statuses y regla de ausencia</h3>';
if ($attendanceid <= 0) {
    echo '<p class="dbg-err">Sin attendanceid no se puede revisar statuses.</p>';
} else {
    $statuses = $DB->get_records_sql(
        "SELECT id, attendanceid, acronym, description, grade, setnumber, visible, deleted
           FROM {attendance_statuses}
          WHERE attendanceid = :attid
       ORDER BY setnumber, id",
        ['attid' => $attendanceid]
    );

    if (empty($statuses)) {
        echo '<p class="dbg-warn">No hay statuses especificos para attendanceid=' . $attendanceid . '. Se muestran globales (setnumber=0).</p>';
        $statuses = $DB->get_records_sql(
            "SELECT id, attendanceid, acronym, description, grade, setnumber, visible, deleted
               FROM {attendance_statuses}
              WHERE setnumber = 0
           ORDER BY id",
            [],
            0,
            50
        );
    }

    if (!empty($statuses)) {
        echo '<table class="dbg">';
        echo '<tr><th>id</th><th>acronym</th><th>description</th><th>grade</th><th>setnumber</th><th>visible</th><th>deleted</th><th>interpreta como ausencia</th></tr>';
        foreach ($statuses as $st) {
            $isabsence = ($st->grade === null || (float)$st->grade <= 0);
            $chip = $isabsence ? '<span class="chip chip-red">SI</span>' : '<span class="chip chip-green">NO</span>';
            echo '<tr>'
                . '<td>' . (int)$st->id . '</td>'
                . '<td>' . s((string)$st->acronym) . '</td>'
                . '<td>' . s((string)$st->description) . '</td>'
                . '<td>' . s((string)($st->grade ?? 'NULL')) . '</td>'
                . '<td>' . (int)$st->setnumber . '</td>'
                . '<td>' . ((int)$st->visible ? '1' : '0') . '</td>'
                . '<td>' . ((int)$st->deleted ? '1' : '0') . '</td>'
                . '<td>' . $chip . '</td>'
                . '</tr>';
        }
        echo '</table>';
    } else {
        echo '<p class="dbg-err">No hay statuses ni por instancia ni globales.</p>';
    }
}
echo '</div>';

// Section 6: students and absences.
echo '<div class="dbg-section">';
echo '<h3>6) Estudiantes y conteo real de inasistencias (latest log por sesion)</h3>';
if (!dbg_abs_table_exists('gmk_course_progre')) {
    echo '<p class="dbg-err">No existe gmk_course_progre en este entorno.</p>';
} else {
    $students = $DB->get_records_sql(
        "SELECT gcp.userid, gcp.status, u.firstname, u.lastname, u.idnumber, u.email
           FROM {gmk_course_progre} gcp
           JOIN {user} u ON u.id = gcp.userid AND u.deleted = 0
          WHERE gcp.classid = :classid
            AND gcp.status IN (1,2,3)
       ORDER BY u.lastname, u.firstname",
        ['classid' => (int)$class->id],
        0,
        500
    );

    if (empty($students)) {
        echo '<p class="dbg-warn">No hay estudiantes activos (status IN 1,2,3) en gmk_course_progre para esta clase.</p>';
    } else {
        $studentids = array_map(static function($r) { return (int)$r->userid; }, array_values($students));
        $studentstats = dbg_abs_compute_student_absences($takensessionids, $studentids);

        $rows = [];
        foreach ($students as $st) {
            $uid = (int)$st->userid;
            $calc = $studentstats[$uid] ?? ['present' => 0, 'logged' => 0, 'absences' => 0];
            $rows[] = [
                'userid' => $uid,
                'name' => trim((string)$st->firstname . ' ' . (string)$st->lastname),
                'status' => (int)$st->status,
                'present' => (int)$calc['present'],
                'logged' => (int)$calc['logged'],
                'absences' => (int)$calc['absences'],
            ];
        }

        usort($rows, static function($a, $b) {
            return $b['absences'] <=> $a['absences'];
        });

        echo '<p>Sesiones tomadas usadas para el calculo: <b>' . count($takensessionids) . '</b></p>';
        echo '<table class="dbg">';
        echo '<tr><th>userid</th><th>estudiante</th><th>status progre</th><th>presentes</th><th>sesiones con log</th><th>inasistencias</th></tr>';
        foreach ($rows as $row) {
            $chip = $row['absences'] > 0
                ? '<span class="chip chip-red">' . $row['absences'] . '</span>'
                : '<span class="chip chip-gray">0</span>';
            echo '<tr>'
                . '<td>' . (int)$row['userid'] . '</td>'
                . '<td>' . s((string)$row['name']) . '</td>'
                . '<td>' . (int)$row['status'] . '</td>'
                . '<td>' . (int)$row['present'] . '</td>'
                . '<td>' . (int)$row['logged'] . '</td>'
                . '<td>' . $chip . '</td>'
                . '</tr>';
        }
        echo '</table>';
    }
}
echo '</div>';

// Section 7: quick diagnosis.
echo '<div class="dbg-section" style="background:#fefce8;border-color:#fde047;">';
echo '<h3 style="color:#854d0e;">7) Diagnostico rapido</h3>';
echo '<ul style="line-height:1.8;">';
echo '<li>Si "strict"=0 y "groupor0" > 0, el problema es filtro por groupid demasiado estricto.</li>';
echo '<li>Si no hay attendanceid, revisar gmk_class.attendancemoduleid y gmk_bbb_attendance_relation.</li>';
echo '<li>Si hay sesiones pero estudiantes con "sesiones con log"=0, aun no se han registrado asistencias en esas sesiones.</li>';
echo '<li>Si hay logs pero inasistencias no cuadran, revisar grade de attendance_statuses (ausencia = grade <= 0 o NULL).</li>';
echo '<li>Si la relacion existe pero apunta a attendanceid/cmid distintos a la clase, hay desalineacion de mapeo.</li>';
echo '</ul>';
echo '</div>';

echo $OUTPUT->footer();
