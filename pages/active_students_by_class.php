<?php
/**
 * Active students by class report.
 *
 * Filters by class day and Moodle group.
 * Exports data to excel including all detected phone fields.
 *
 * @package local_grupomakro_core
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/dataformatlib.php');

require_login();
admin_externalpage_setup('grupomakro_core_active_students_by_class');

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/active_students_by_class.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Estudiantes activos por clase');
$PAGE->set_heading('Estudiantes activos por clase');

// -----------------------------------------------------------------------------
// Helpers.
// -----------------------------------------------------------------------------

/**
 * Escape helper.
 *
 * @param mixed $value
 * @return string
 */
function asbc_h($value): string {
    return s((string)$value);
}

/**
 * Normalize text (lowercase + remove accents) for resilient matching.
 *
 * @param string $text
 * @return string
 */
function asbc_normalize_text(string $text): string {
    $text = trim($text);
    $text = strtr($text, [
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N',
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n',
        'Ü' => 'U', 'ü' => 'u',
    ]);
    return core_text::strtolower($text);
}

/**
 * Canonical day key from day label.
 *
 * @param string $day
 * @return string
 */
function asbc_day_key(string $day): string {
    $day = asbc_normalize_text($day);
    $map = [
        'lunes' => 'lunes',
        'monday' => 'lunes',
        'martes' => 'martes',
        'tuesday' => 'martes',
        'miercoles' => 'miercoles',
        'wednesday' => 'miercoles',
        'jueves' => 'jueves',
        'thursday' => 'jueves',
        'viernes' => 'viernes',
        'friday' => 'viernes',
        'sabado' => 'sabado',
        'saturday' => 'sabado',
        'domingo' => 'domingo',
        'sunday' => 'domingo',
    ];
    return $map[$day] ?? '';
}

/**
 * Day label for UI.
 *
 * @param string $daykey
 * @return string
 */
function asbc_day_label(string $daykey): string {
    static $labels = [
        'lunes' => 'Lunes',
        'martes' => 'Martes',
        'miercoles' => 'Miercoles',
        'jueves' => 'Jueves',
        'viernes' => 'Viernes',
        'sabado' => 'Sabado',
        'domingo' => 'Domingo',
    ];
    return $labels[$daykey] ?? ucfirst($daykey);
}

/**
 * Parse gmk_class.classdays fallback format (L/M/M/J/V/S/D 0/1).
 *
 * @param string $classdays
 * @return array
 */
function asbc_parse_classdays(string $classdays): array {
    $parts = preg_split('/[\/,|]/', trim($classdays));
    if (!is_array($parts) || count($parts) < 7) {
        return [];
    }
    $order = ['lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado', 'domingo'];
    $result = [];
    foreach ($order as $idx => $daykey) {
        $value = isset($parts[$idx]) ? (int)trim((string)$parts[$idx]) : 0;
        if ($value > 0) {
            $result[] = $daykey;
        }
    }
    return $result;
}

/**
 * Find document field ID candidates.
 *
 * @return int
 */
function asbc_get_document_field_id(): int {
    global $DB;
    $candidates = ['documentnumber', 'document_number', 'documento', 'cedula', 'identificacion'];
    foreach ($candidates as $shortname) {
        $field = $DB->get_record('user_info_field', ['shortname' => $shortname], 'id', IGNORE_MISSING);
        if ($field) {
            return (int)$field->id;
        }
    }
    return 0;
}

/**
 * Decide if custom profile field is phone-like.
 *
 * @param stdClass $field
 * @return bool
 */
function asbc_is_phone_custom_field(stdClass $field): bool {
    $text = asbc_normalize_text((string)$field->shortname . ' ' . (string)$field->name);
    $keywords = ['phone', 'telefono', 'movil', 'mobile', 'celular', 'whatsapp', 'customphone'];
    foreach ($keywords as $keyword) {
        if (strpos($text, $keyword) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Build schedule map by class.
 *
 * @param array $classes class records indexed by class id
 * @return array
 */
function asbc_build_schedule_map(array $classes): array {
    global $DB;

    $classids = array_keys($classes);
    if (empty($classids)) {
        return [];
    }

    list($insql, $inparams) = $DB->get_in_or_equal($classids, SQL_PARAMS_NAMED, 'cls');
    $schedules = $DB->get_records_sql(
        "SELECT id, classid, day, start_time, end_time
           FROM {gmk_class_schedules}
          WHERE classid $insql
          ORDER BY classid ASC, start_time ASC, end_time ASC",
        $inparams
    );

    $dayorder = ['lunes' => 1, 'martes' => 2, 'miercoles' => 3, 'jueves' => 4, 'viernes' => 5, 'sabado' => 6, 'domingo' => 7];
    $map = [];

    foreach ($classes as $classid => $class) {
        $map[(int)$classid] = [
            'daykeys' => [],
            'slots' => [],
            'display' => '--',
        ];
    }

    foreach ($schedules as $schedule) {
        $classid = (int)$schedule->classid;
        if (!isset($map[$classid])) {
            continue;
        }
        $daykey = asbc_day_key((string)$schedule->day);
        if ($daykey === '') {
            continue;
        }

        if (!in_array($daykey, $map[$classid]['daykeys'], true)) {
            $map[$classid]['daykeys'][] = $daykey;
        }

        $start = trim((string)$schedule->start_time);
        $end = trim((string)$schedule->end_time);
        $slot = asbc_day_label($daykey) . ' ' . $start . '-' . $end;
        if (!in_array($slot, $map[$classid]['slots'], true)) {
            $map[$classid]['slots'][] = $slot;
        }
    }

    // Fallback for classes without schedule rows.
    foreach ($classes as $classid => $class) {
        $classid = (int)$classid;
        if (!empty($map[$classid]['daykeys'])) {
            continue;
        }

        $fallbackdays = asbc_parse_classdays((string)($class->classdays ?? ''));
        foreach ($fallbackdays as $daykey) {
            if (!in_array($daykey, $map[$classid]['daykeys'], true)) {
                $map[$classid]['daykeys'][] = $daykey;
            }

            $start = trim((string)($class->inittime ?? ''));
            $end = trim((string)($class->endtime ?? ''));
            if ($start !== '' && $end !== '') {
                $slot = asbc_day_label($daykey) . ' ' . $start . '-' . $end;
            } else {
                $slot = asbc_day_label($daykey);
            }
            if (!in_array($slot, $map[$classid]['slots'], true)) {
                $map[$classid]['slots'][] = $slot;
            }
        }
    }

    // Sort by week order and generate display.
    foreach ($map as $classid => $entry) {
        usort($entry['daykeys'], function($a, $b) use ($dayorder) {
            $oa = $dayorder[$a] ?? 99;
            $ob = $dayorder[$b] ?? 99;
            return $oa <=> $ob;
        });
        $entry['display'] = !empty($entry['slots']) ? implode(' | ', $entry['slots']) : '--';
        $map[$classid] = $entry;
    }

    return $map;
}

// -----------------------------------------------------------------------------
// Params.
// -----------------------------------------------------------------------------

$filterday = optional_param('day', '', PARAM_TEXT);
$filtergroupid = optional_param('groupid', 0, PARAM_INT);
$search = trim(optional_param('search', '', PARAM_TEXT));
$action = optional_param('action', '', PARAM_ALPHA);
$page = max(0, optional_param('page', 0, PARAM_INT));
$perpage = 100;

$filterdaykey = asbc_day_key($filterday);

// -----------------------------------------------------------------------------
// Load active classes.
// -----------------------------------------------------------------------------

$classconditions = [
    'gc.approved = 1',
    'gc.closed = 0',
    'gc.groupid > 0',
    '(gc.enddate = 0 OR gc.enddate >= :nowts)',
];
$classparams = ['nowts' => time()];

if ($filtergroupid > 0) {
    $classconditions[] = 'gc.groupid = :groupid';
    $classparams['groupid'] = $filtergroupid;
}

$classsql = "
    SELECT gc.id,
           gc.name,
           gc.groupid,
           gc.instructorid,
           gc.classdays,
           gc.inittime,
           gc.endtime,
           gc.shift,
           gc.periodid,
           gc.learningplanid,
           COALESCE(g.name, '') AS groupname
      FROM {gmk_class} gc
 LEFT JOIN {groups} g ON g.id = gc.groupid
     WHERE " . implode(' AND ', $classconditions) . "
  ORDER BY gc.name ASC";

$classes = $DB->get_records_sql($classsql, $classparams);
$schedulemap = asbc_build_schedule_map($classes);

// Build group options from active classes (before day filter).
$groupset = [];
foreach ($classes as $class) {
    $gid = (int)$class->groupid;
    if ($gid <= 0) {
        continue;
    }
    $gname = trim((string)$class->groupname);
    if ($gname === '') {
        $gname = 'Grupo ' . $gid;
    }
    $groupset[$gid] = $gname;
}
asort($groupset);

// Apply day filter at class level.
if ($filterdaykey !== '') {
    foreach ($classes as $classid => $class) {
        $daykeys = $schedulemap[(int)$classid]['daykeys'] ?? [];
        if (!in_array($filterdaykey, $daykeys, true)) {
            unset($classes[$classid]);
            unset($schedulemap[(int)$classid]);
        }
    }
}

$classids = array_keys($classes);
$rows = [];
$phonefields = [];

if (!empty($classids)) {
    list($classinsql, $classinparams) = $DB->get_in_or_equal($classids, SQL_PARAMS_NAMED, 'cid');

    // Active student memberships by class group.
    $studentsql = "
        SELECT gc.id AS classid,
               gc.name AS classname,
               gc.groupid,
               COALESCE(g.name, '') AS groupname,
               u.id AS userid,
               u.firstname,
               u.lastname,
               u.email,
               u.idnumber,
               u.phone1,
               u.phone2
          FROM {gmk_class} gc
          JOIN {groups_members} gm
            ON gm.groupid = gc.groupid
          JOIN {user} u
            ON u.id = gm.userid
     LEFT JOIN {groups} g
            ON g.id = gc.groupid
         WHERE gc.id $classinsql
           AND gm.userid <> gc.instructorid
           AND u.deleted = 0
           AND u.suspended = 0
      ORDER BY gc.name ASC, u.lastname ASC, u.firstname ASC";
    $studentrecords = $DB->get_records_sql($studentsql, $classinparams);

    $userids = [];
    foreach ($studentrecords as $record) {
        $userids[(int)$record->userid] = (int)$record->userid;
    }
    $userids = array_values($userids);

    // Identification from student profile field (documentnumber candidate).
    $docfieldid = asbc_get_document_field_id();
    $docmap = [];
    if ($docfieldid > 0 && !empty($userids)) {
        list($uinsql, $uinparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u_doc');
        $docparams = array_merge($uinparams, ['docfieldid' => $docfieldid]);
        $docrows = $DB->get_records_sql(
            "SELECT userid, data
               FROM {user_info_data}
              WHERE fieldid = :docfieldid
                AND userid $uinsql",
            $docparams
        );
        foreach ($docrows as $docrow) {
            $docmap[(int)$docrow->userid] = trim((string)$docrow->data);
        }
    }

    // Detect all phone-related custom profile fields.
    $allcustomfields = $DB->get_records('user_info_field', null, 'id ASC');
    foreach ($allcustomfields as $field) {
        if (asbc_is_phone_custom_field($field)) {
            $phonefields[(int)$field->id] = $field;
        }
    }

    $customphonemap = [];
    if (!empty($phonefields) && !empty($userids)) {
        list($finsql, $finparams) = $DB->get_in_or_equal(array_keys($phonefields), SQL_PARAMS_NAMED, 'f_phone');
        list($uinsql, $uinparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u_phone');
        $rowsparams = array_merge($finparams, $uinparams);
        $customrows = $DB->get_records_sql(
            "SELECT userid, fieldid, data
               FROM {user_info_data}
              WHERE fieldid $finsql
                AND userid $uinsql",
            $rowsparams
        );
        foreach ($customrows as $crow) {
            $uid = (int)$crow->userid;
            $fid = (int)$crow->fieldid;
            $customphonemap[$uid][$fid] = trim((string)$crow->data);
        }
    }

    foreach ($studentrecords as $record) {
        $classid = (int)$record->classid;
        if (!isset($classes[$classid])) {
            continue;
        }

        $studentname = trim((string)$record->firstname . ' ' . (string)$record->lastname);
        $identification = trim((string)($docmap[(int)$record->userid] ?? ''));
        if ($identification === '') {
            $identification = trim((string)$record->idnumber);
        }

        $obj = new stdClass();
        $obj->classid = $classid;
        $obj->classname = (string)$record->classname;
        $obj->schedule = (string)($schedulemap[$classid]['display'] ?? '--');
        $obj->groupid = (int)$record->groupid;
        $obj->groupname = trim((string)$record->groupname) !== '' ? (string)$record->groupname : ('Grupo ' . (int)$record->groupid);
        $obj->userid = (int)$record->userid;
        $obj->studentname = $studentname;
        $obj->identification = $identification;
        $obj->email = (string)$record->email;
        $obj->phone1 = trim((string)$record->phone1);
        $obj->phone2 = trim((string)$record->phone2);

        $allphones = [];
        if ($obj->phone1 !== '') {
            $allphones[] = $obj->phone1;
        }
        if ($obj->phone2 !== '') {
            $allphones[] = $obj->phone2;
        }

        foreach ($phonefields as $fieldid => $field) {
            $value = trim((string)($customphonemap[(int)$record->userid][$fieldid] ?? ''));
            $obj->{'cf_phone_' . $fieldid} = $value;
            if ($value !== '') {
                $allphones[] = $value;
            }
        }

        $allphones = array_values(array_unique(array_filter($allphones)));
        $obj->allphones = implode(' | ', $allphones);

        if ($search !== '') {
            $haystack = asbc_normalize_text(
                $obj->classname . ' ' .
                $obj->groupname . ' ' .
                $obj->studentname . ' ' .
                $obj->identification . ' ' .
                $obj->email . ' ' .
                $obj->phone1 . ' ' .
                $obj->phone2 . ' ' .
                $obj->allphones . ' ' .
                $obj->schedule
            );
            $needle = asbc_normalize_text($search);
            if (strpos($haystack, $needle) === false) {
                continue;
            }
        }

        $rows[] = $obj;
    }
}

// Stable sorting.
usort($rows, function($a, $b) {
    $cmp = strcmp((string)$a->classname, (string)$b->classname);
    if ($cmp !== 0) {
        return $cmp;
    }
    $cmp = ((int)$a->groupid <=> (int)$b->groupid);
    if ($cmp !== 0) {
        return $cmp;
    }
    return strcmp((string)$a->studentname, (string)$b->studentname);
});

// -----------------------------------------------------------------------------
// Export.
// -----------------------------------------------------------------------------

$exportcolumns = [
    'classid' => 'Class ID',
    'classname' => 'Class',
    'schedule' => 'Schedule',
    'groupid' => 'Group ID',
    'groupname' => 'Group',
    'userid' => 'User ID',
    'studentname' => 'Student',
    'identification' => 'Identification',
    'email' => 'Email',
    'phone1' => 'Phone',
    'phone2' => 'Mobile phone',
];
foreach ($phonefields as $fieldid => $field) {
    $label = trim((string)$field->name) !== '' ? trim((string)$field->name) : trim((string)$field->shortname);
    $exportcolumns['cf_phone_' . $fieldid] = 'Phone CF: ' . $label;
}
$exportcolumns['allphones'] = 'All phones merged';

if ($action === 'export') {
    \core\dataformat::download_data(
        'active_students_by_class_' . date('Ymd_His'),
        'excel',
        $exportcolumns,
        $rows
    );
    die();
}

// -----------------------------------------------------------------------------
// Pagination.
// -----------------------------------------------------------------------------

$totalrows = count($rows);
$offset = $page * $perpage;
$pagerows = array_slice($rows, $offset, $perpage);

$pagingurl = new moodle_url('/local/grupomakro_core/pages/active_students_by_class.php', [
    'day' => $filterday,
    'groupid' => $filtergroupid,
    'search' => $search,
]);

// -----------------------------------------------------------------------------
// UI.
// -----------------------------------------------------------------------------

echo $OUTPUT->header();

$dayoptions = [
    '' => 'All days',
    'lunes' => 'Lunes',
    'martes' => 'Martes',
    'miercoles' => 'Miercoles',
    'jueves' => 'Jueves',
    'viernes' => 'Viernes',
    'sabado' => 'Sabado',
    'domingo' => 'Domingo',
];

$uniqueclasses = [];
$uniquestudents = [];
foreach ($rows as $row) {
    $uniqueclasses[(int)$row->classid] = true;
    $uniquestudents[(int)$row->userid] = true;
}

echo '<style>
    .asbc-card{background:#fff;border:1px solid #e6e6e6;border-radius:8px;margin-bottom:16px}
    .asbc-card-h{padding:14px 16px;border-bottom:1px solid #efefef;font-weight:700}
    .asbc-card-b{padding:16px}
    .asbc-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px}
    .asbc-stat{border:1px solid #efefef;border-radius:8px;padding:12px;text-align:center}
    .asbc-stat .n{font-size:1.5rem;font-weight:700;color:#1565c0}
    .asbc-form .form-control{height:38px}
    .asbc-actions{display:flex;gap:8px;justify-content:flex-end}
    .asbc-table th{white-space:nowrap}
    .asbc-table td{vertical-align:middle}
    .asbc-small{font-size:12px;color:#777}
</style>';

echo '<div class="asbc-card">';
echo '<div class="asbc-card-h">Active students by class</div>';
echo '<div class="asbc-card-b">';

echo '<div class="asbc-grid mb-3">';
echo '<div class="asbc-stat"><div class="n">' . count($uniqueclasses) . '</div><div>Classes</div></div>';
echo '<div class="asbc-stat"><div class="n">' . count($uniquestudents) . '</div><div>Students</div></div>';
echo '<div class="asbc-stat"><div class="n">' . $totalrows . '</div><div>Rows</div></div>';
echo '</div>';

echo '<form method="get" class="asbc-form mb-3">';
echo '<div class="row">';
echo '<div class="col-md-3 mb-2">';
echo '<label class="asbc-small">Day</label>';
echo '<select name="day" class="form-control">';
foreach ($dayoptions as $daykey => $daylabel) {
    $selected = ($filterdaykey === $daykey) ? ' selected' : '';
    echo '<option value="' . asbc_h($daykey) . '"' . $selected . '>' . asbc_h($daylabel) . '</option>';
}
echo '</select>';
echo '</div>';

echo '<div class="col-md-4 mb-2">';
echo '<label class="asbc-small">Group</label>';
echo '<select name="groupid" class="form-control">';
echo '<option value="0">All groups</option>';
foreach ($groupset as $gid => $gname) {
    $selected = ((int)$filtergroupid === (int)$gid) ? ' selected' : '';
    echo '<option value="' . (int)$gid . '"' . $selected . '>' . asbc_h($gname) . ' (#' . (int)$gid . ')</option>';
}
echo '</select>';
echo '</div>';

echo '<div class="col-md-3 mb-2">';
echo '<label class="asbc-small">Search</label>';
echo '<input type="text" name="search" class="form-control" value="' . asbc_h($search) . '" placeholder="Name, ID, email, phone...">';
echo '</div>';

echo '<div class="col-md-2 mb-2 d-flex align-items-end asbc-actions">';
echo '<button type="submit" class="btn btn-primary">Filter</button>';
echo '</div>';
echo '</div>';

echo '<div class="asbc-actions">';
echo '<a class="btn btn-outline-secondary" href="' . new moodle_url('/local/grupomakro_core/pages/active_students_by_class.php') . '">Clear</a>';
echo '<button type="submit" name="action" value="export" class="btn btn-success">Excel</button>';
echo '</div>';
echo '</form>';

if (empty($pagerows)) {
    echo '<div class="alert alert-info mb-0">No records found with current filters.</div>';
} else {
    echo '<div class="table-responsive">';
    echo '<table class="table table-striped table-hover asbc-table">';
    echo '<thead><tr>';
    foreach ($exportcolumns as $key => $header) {
        echo '<th>' . asbc_h($header) . '</th>';
    }
    echo '</tr></thead><tbody>';

    foreach ($pagerows as $row) {
        echo '<tr>';
        foreach ($exportcolumns as $key => $header) {
            $value = $row->{$key} ?? '';
            echo '<td>' . asbc_h($value) . '</td>';
        }
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
    echo $OUTPUT->paging_bar($totalrows, $page, $perpage, $pagingurl);
}

echo '</div>'; // card body.
echo '</div>'; // card.

echo $OUTPUT->footer();

