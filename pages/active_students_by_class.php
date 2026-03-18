<?php
/**
 * Active students by class report.
 *
 * Filters by class day and group, with Excel export.
 *
 * @package local_grupomakro_core
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/dataformatlib.php');

require_login();
admin_externalpage_setup('grupomakro_core_active_students_by_class');

$title = get_string('active_students_by_class_page', 'local_grupomakro_core');
$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/active_students_by_class.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('admin');
$PAGE->set_title($title);
$PAGE->set_heading($title);

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
 * Normalize text for resilient matching.
 *
 * @param string $text
 * @return string
 */
function asbc_normalize_text(string $text): string {
    $text = trim($text);
    if ($text === '') {
        return '';
    }
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    if ($ascii !== false && $ascii !== '') {
        $text = $ascii;
    }
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
 * Parse gmk_class.classdays fallback format (0/1 values for week days).
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
 * Get candidate profile fields for identification number.
 *
 * @return array [fieldid => score]
 */
function asbc_get_document_field_candidates(): array {
    global $DB;

    $prioritybyshortname = [
        'documentnumber' => 1000,
        'document_number' => 990,
        'numerodocumento' => 980,
        'numero_documento' => 970,
        'identificacion' => 960,
        'identification' => 950,
        'cedula' => 940,
    ];
    $namekeywords = [
        'identificacion' => 80,
        'numero de documento' => 70,
        'numero documento' => 60,
        'cedula' => 50,
    ];

    $candidates = [];
    $fields = $DB->get_records('user_info_field', null, 'id ASC');
    foreach ($fields as $field) {
        $shortname = asbc_normalize_text((string)$field->shortname);
        $name = asbc_normalize_text((string)$field->name);
        $score = 0;

        if (isset($prioritybyshortname[$shortname])) {
            $score = $prioritybyshortname[$shortname];
        } else {
            foreach ($namekeywords as $keyword => $boost) {
                if ($name !== '' && strpos($name, $keyword) !== false) {
                    $score = max($score, $boost);
                }
            }
        }

        if ($score > 0) {
            $candidates[(int)$field->id] = $score;
        }
    }

    arsort($candidates);
    return $candidates;
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
 * @param array $classes
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

$filterday = optional_param('day', '', PARAM_TEXT);
$filtergroupid = optional_param('groupid', 0, PARAM_INT);
$search = trim(optional_param('search', '', PARAM_TEXT));
$action = optional_param('action', '', PARAM_ALPHA);
$page = max(0, optional_param('page', 0, PARAM_INT));
$perpage = 100;
$filterdaykey = asbc_day_key($filterday);

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
 LEFT JOIN {groups} g
        ON g.id = gc.groupid
     WHERE " . implode(' AND ', $classconditions) . "
  ORDER BY gc.name ASC";

$classes = $DB->get_records_sql($classsql, $classparams);
$schedulemap = asbc_build_schedule_map($classes);

$groupset = [];
foreach ($classes as $class) {
    $groupid = (int)$class->groupid;
    if ($groupid <= 0) {
        continue;
    }
    $groupname = trim((string)$class->groupname);
    if ($groupname === '') {
        $groupname = 'Grupo ' . $groupid;
    }
    $groupset[$groupid] = $groupname;
}
asort($groupset);

if ($filterdaykey !== '') {
    foreach ($classes as $classid => $class) {
        $daykeys = $schedulemap[(int)$classid]['daykeys'] ?? [];
        if (!in_array($filterdaykey, $daykeys, true)) {
            unset($classes[$classid]);
            unset($schedulemap[(int)$classid]);
        }
    }
}

$rows = [];
$phonefields = [];
$classids = array_keys($classes);

if (!empty($classids)) {
    list($classinsql, $classinparams) = $DB->get_in_or_equal($classids, SQL_PARAMS_NAMED, 'cid');

    $studentsql = "
        SELECT gm.id,
               gc.id AS classid,
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

    $docmap = [];
    $docfieldcandidates = asbc_get_document_field_candidates();
    if (!empty($docfieldcandidates) && !empty($userids)) {
        $docfieldids = array_keys($docfieldcandidates);
        list($finsql, $finparams) = $DB->get_in_or_equal($docfieldids, SQL_PARAMS_NAMED, 'docf');
        list($uinsql, $uinparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'docu');
        $docparams = array_merge($finparams, $uinparams);
        $docrows = $DB->get_records_sql(
            "SELECT id, userid, fieldid, data
               FROM {user_info_data}
              WHERE fieldid $finsql
                AND userid $uinsql
           ORDER BY userid ASC, id ASC",
            $docparams
        );
        foreach ($docrows as $docrow) {
            $userid = (int)$docrow->userid;
            $fieldid = (int)$docrow->fieldid;
            $value = trim((string)$docrow->data);
            if ($value === '') {
                continue;
            }
            if (!isset($docmap[$userid])) {
                $docmap[$userid] = ['value' => $value, 'score' => (int)$docfieldcandidates[$fieldid]];
                continue;
            }
            if ((int)$docfieldcandidates[$fieldid] > (int)$docmap[$userid]['score']) {
                $docmap[$userid] = ['value' => $value, 'score' => (int)$docfieldcandidates[$fieldid]];
            }
        }
    }

    $allcustomfields = $DB->get_records('user_info_field', null, 'id ASC');
    foreach ($allcustomfields as $field) {
        if (asbc_is_phone_custom_field($field)) {
            $phonefields[(int)$field->id] = $field;
        }
    }

    $customphonemap = [];
    if (!empty($phonefields) && !empty($userids)) {
        list($finsql, $finparams) = $DB->get_in_or_equal(array_keys($phonefields), SQL_PARAMS_NAMED, 'phf');
        list($uinsql, $uinparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'phu');
        $customphoneparams = array_merge($finparams, $uinparams);
        $customrows = $DB->get_records_sql(
            "SELECT id, userid, fieldid, data
               FROM {user_info_data}
              WHERE fieldid $finsql
                AND userid $uinsql
           ORDER BY userid ASC, fieldid ASC",
            $customphoneparams
        );
        foreach ($customrows as $crow) {
            $userid = (int)$crow->userid;
            $fieldid = (int)$crow->fieldid;
            $customphonemap[$userid][$fieldid] = trim((string)$crow->data);
        }
    }

    $needlesearch = asbc_normalize_text($search);
    foreach ($studentrecords as $record) {
        $classid = (int)$record->classid;
        if (!isset($classes[$classid])) {
            continue;
        }

        $studentname = trim((string)$record->firstname . ' ' . (string)$record->lastname);
        $identification = trim((string)($docmap[(int)$record->userid]['value'] ?? ''));
        if ($identification === '') {
            $identification = trim((string)$record->idnumber);
        }

        $row = new stdClass();
        $row->classid = $classid;
        $row->classname = (string)$record->classname;
        $row->schedule = (string)($schedulemap[$classid]['display'] ?? '--');
        $row->groupid = (int)$record->groupid;
        $row->groupname = trim((string)$record->groupname) !== '' ? (string)$record->groupname : ('Grupo ' . (int)$record->groupid);
        $row->userid = (int)$record->userid;
        $row->studentname = $studentname;
        $row->identification = $identification;
        $row->email = (string)$record->email;
        $row->phone1 = trim((string)$record->phone1);
        $row->phone2 = trim((string)$record->phone2);

        $allphones = [];
        if ($row->phone1 !== '') {
            $allphones[] = $row->phone1;
        }
        if ($row->phone2 !== '') {
            $allphones[] = $row->phone2;
        }

        foreach ($phonefields as $fieldid => $field) {
            $value = trim((string)($customphonemap[(int)$record->userid][$fieldid] ?? ''));
            $row->{'cf_phone_' . $fieldid} = $value;
            if ($value !== '') {
                $allphones[] = $value;
            }
        }

        $allphones = array_values(array_unique(array_filter($allphones)));
        $row->allphones = implode(' | ', $allphones);

        if ($needlesearch !== '') {
            $haystack = asbc_normalize_text(
                $row->classname . ' ' .
                $row->groupname . ' ' .
                $row->studentname . ' ' .
                $row->identification . ' ' .
                $row->email . ' ' .
                $row->phone1 . ' ' .
                $row->phone2 . ' ' .
                $row->allphones . ' ' .
                $row->schedule
            );
            if (strpos($haystack, $needlesearch) === false) {
                continue;
            }
        }

        $rows[] = $row;
    }
}

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

$exportcolumns = [
    'classid' => 'ID clase',
    'classname' => 'Clase',
    'schedule' => 'Horario',
    'groupid' => 'ID grupo',
    'groupname' => 'Grupo',
    'userid' => 'ID usuario',
    'studentname' => 'Estudiante',
    'identification' => 'Cedula/Identificacion',
    'email' => 'Correo',
    'phone1' => 'Telefono',
    'phone2' => 'Telefono movil',
];
foreach ($phonefields as $fieldid => $field) {
    $label = trim((string)$field->name) !== '' ? trim((string)$field->name) : trim((string)$field->shortname);
    $exportcolumns['cf_phone_' . $fieldid] = 'Telefono extra: ' . $label;
}
$exportcolumns['allphones'] = 'Telefonos consolidados';

if ($action === 'export') {
    \core\dataformat::download_data(
        'estudiantes_activos_por_clase_' . date('Ymd_His'),
        'excel',
        $exportcolumns,
        $rows
    );
    die();
}

$totalrows = count($rows);
$offset = $page * $perpage;
$pagerows = array_slice($rows, $offset, $perpage);

$pagingurl = new moodle_url('/local/grupomakro_core/pages/active_students_by_class.php', [
    'day' => $filterday,
    'groupid' => $filtergroupid,
    'search' => $search,
]);

echo $OUTPUT->header();

$dayoptions = [
    '' => 'Todos',
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
echo '<div class="asbc-card-h">' . asbc_h($title) . '</div>';
echo '<div class="asbc-card-b">';

echo '<div class="asbc-grid mb-3">';
echo '<div class="asbc-stat"><div class="n">' . count($uniqueclasses) . '</div><div>Clases</div></div>';
echo '<div class="asbc-stat"><div class="n">' . count($uniquestudents) . '</div><div>Estudiantes</div></div>';
echo '<div class="asbc-stat"><div class="n">' . $totalrows . '</div><div>Registros</div></div>';
echo '</div>';

echo '<form method="get" class="asbc-form mb-3">';
echo '<div class="row">';
echo '<div class="col-md-3 mb-2">';
echo '<label class="asbc-small">Dia de clase</label>';
echo '<select name="day" class="form-control">';
foreach ($dayoptions as $daykey => $daylabel) {
    $selected = ($filterdaykey === $daykey) ? ' selected' : '';
    echo '<option value="' . asbc_h($daykey) . '"' . $selected . '>' . asbc_h($daylabel) . '</option>';
}
echo '</select>';
echo '</div>';

echo '<div class="col-md-4 mb-2">';
echo '<label class="asbc-small">Grupo</label>';
echo '<select name="groupid" class="form-control">';
echo '<option value="0">Todos los grupos</option>';
foreach ($groupset as $gid => $gname) {
    $selected = ((int)$filtergroupid === (int)$gid) ? ' selected' : '';
    echo '<option value="' . (int)$gid . '"' . $selected . '>' . asbc_h($gname) . ' (#' . (int)$gid . ')</option>';
}
echo '</select>';
echo '</div>';

echo '<div class="col-md-3 mb-2">';
echo '<label class="asbc-small">Busqueda</label>';
echo '<input type="text" name="search" class="form-control" value="' . asbc_h($search) . '" placeholder="Nombre, cedula, correo, telefono...">';
echo '</div>';

echo '<div class="col-md-2 mb-2 d-flex align-items-end asbc-actions">';
echo '<button type="submit" class="btn btn-primary">Filtrar</button>';
echo '</div>';
echo '</div>';

echo '<div class="asbc-actions">';
echo '<a class="btn btn-outline-secondary" href="' . (new moodle_url('/local/grupomakro_core/pages/active_students_by_class.php'))->out(false) . '">Limpiar</a>';
echo '<button type="submit" name="action" value="export" class="btn btn-success">Exportar Excel</button>';
echo '</div>';
echo '</form>';

if (empty($pagerows)) {
    echo '<div class="alert alert-info mb-0">No se encontraron registros con los filtros seleccionados.</div>';
} else {
    echo '<div class="table-responsive">';
    echo '<table class="table table-striped table-hover asbc-table">';
    echo '<thead><tr>';
    foreach ($exportcolumns as $header) {
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

echo '</div>';
echo '</div>';
echo $OUTPUT->footer();

