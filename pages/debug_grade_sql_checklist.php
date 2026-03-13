<?php
/**
 * Debug SQL checklist for gradebook integrity in class/group based setup.
 *
 * @package local_grupomakro_core
 */

$config_path = __DIR__ . '/../../../config.php';
if (!file_exists($config_path)) {
    $config_path = __DIR__ . '/../../config.php';
}
if (!file_exists($config_path)) {
    $config_path = __DIR__ . '/../../../../config.php';
}
require_once($config_path);

require_login();
$sysctx = context_system::instance();
require_capability('moodle/site:config', $sysctx);

$courseid = optional_param('courseid', 0, PARAM_INT);
$classid = optional_param('classid', 0, PARAM_INT);
$maxrows = optional_param('maxrows', 200, PARAM_INT);
$run = optional_param('run', 0, PARAM_BOOL);

if ($maxrows < 10) {
    $maxrows = 10;
}
if ($maxrows > 1000) {
    $maxrows = 1000;
}

$pageurl = new moodle_url('/local/grupomakro_core/pages/debug_grade_sql_checklist.php', [
    'courseid' => $courseid,
    'classid' => $classid,
    'maxrows' => $maxrows,
    'run' => $run,
]);
$PAGE->set_url($pageurl);
$PAGE->set_context($sysctx);
$PAGE->set_title('Debug Checklist SQL de Calificaciones');
$PAGE->set_heading('Debug Checklist SQL de Calificaciones');

/**
 * Collect SQL rows preserving duplicates and order.
 *
 * @param moodle_database $db
 * @param string $sql
 * @param array $params
 * @param int $maxrows
 * @return array{rows:array,returned:int,truncated:bool}
 */
function gmk_dbg_collect_rows($db, string $sql, array $params, int $maxrows): array {
    $rows = [];
    $returned = 0;
    $rs = $db->get_recordset_sql($sql, $params, 0, $maxrows + 1);
    foreach ($rs as $record) {
        $returned++;
        if ($returned <= $maxrows) {
            $rows[] = (array)$record;
        }
    }
    $rs->close();
    return [
        'rows' => $rows,
        'returned' => $returned,
        'truncated' => ($returned > $maxrows),
    ];
}

/**
 * Render generic html table.
 *
 * @param array $rows
 * @return string
 */
function gmk_dbg_table(array $rows): string {
    if (empty($rows)) {
        return '<p style="margin:0;color:#2e7d32;"><b>Sin hallazgos.</b></p>';
    }

    $headers = array_keys($rows[0]);
    $out = '<div style="overflow:auto;max-height:420px;border:1px solid #e0e0e0;background:#fff;">';
    $out .= '<table style="width:100%;border-collapse:collapse;font-size:12px;font-family:monospace;">';
    $out .= '<thead><tr style="background:#1f6feb;color:#fff;">';
    foreach ($headers as $h) {
        $out .= '<th style="padding:6px;border:1px solid #d0d7de;text-align:left;">' . s($h) . '</th>';
    }
    $out .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $out .= '<tr>';
        foreach ($headers as $h) {
            $val = array_key_exists($h, $row) ? $row[$h] : '';
            if (is_null($val)) {
                $val = 'NULL';
            } else if (is_bool($val)) {
                $val = $val ? '1' : '0';
            }
            $out .= '<td style="padding:6px;border:1px solid #eef2f7;vertical-align:top;">' . s((string)$val) . '</td>';
        }
        $out .= '</tr>';
    }
    $out .= '</tbody></table></div>';
    return $out;
}

/**
 * Badge for check status.
 *
 * @param string $status
 * @return string
 */
function gmk_dbg_status_badge(string $status): string {
    if ($status === 'ok') {
        return '<span style="background:#2e7d32;color:#fff;padding:2px 8px;border-radius:10px;font-size:11px;">OK</span>';
    }
    if ($status === 'info') {
        return '<span style="background:#1565c0;color:#fff;padding:2px 8px;border-radius:10px;font-size:11px;">INFO</span>';
    }
    return '<span style="background:#c62828;color:#fff;padding:2px 8px;border-radius:10px;font-size:11px;">ISSUE</span>';
}

echo $OUTPUT->header();

echo '<h2 style="margin-top:0;">Checklist SQL de diagnostico de calificaciones</h2>';
echo '<p style="margin-top:0;max-width:1100px;">'
    . 'Esta pagina ejecuta consultas de integridad para detectar condiciones que pueden producir notas finales '
    . 'incorrectas cuando se usa una arquitectura por clases/grupos/categorias. '
    . 'Filtra por curso o clase si lo necesitas.'
    . '</p>';

echo '<form method="get" style="background:#f8fafc;border:1px solid #dbe4ef;padding:12px;margin:12px 0;">';
echo '<input type="hidden" name="run" value="1">';
echo '<label style="display:inline-block;min-width:90px;"><b>Course ID</b></label> ';
echo '<input type="number" name="courseid" value="' . (int)$courseid . '" style="width:120px;margin-right:12px;">';
echo '<label style="display:inline-block;min-width:80px;"><b>Class ID</b></label> ';
echo '<input type="number" name="classid" value="' . (int)$classid . '" style="width:120px;margin-right:12px;">';
echo '<label style="display:inline-block;min-width:95px;"><b>Max rows</b></label> ';
echo '<input type="number" name="maxrows" value="' . (int)$maxrows . '" min="10" max="1000" style="width:100px;margin-right:12px;">';
echo '<button type="submit" style="padding:6px 12px;">Diagnosticar</button>';
echo '</form>';

if (!$run) {
    echo '<p style="color:#444;">Ejecuta el diagnostico con el formulario para ver el checklist.</p>';
    echo $OUTPUT->footer();
    exit;
}

$classwhere = 'c.closed = 0';
$classparams = [];
if ($courseid > 0) {
    $classwhere .= ' AND c.corecourseid = :filtercourseid';
    $classparams['filtercourseid'] = $courseid;
}
if ($classid > 0) {
    $classwhere .= ' AND c.id = :filterclassid';
    $classparams['filterclassid'] = $classid;
}

$scopewhere = 'sc.closed = 0';
$scopeparams = [];
if ($courseid > 0) {
    $scopewhere .= ' AND sc.corecourseid = :scopecourseid';
    $scopeparams['scopecourseid'] = $courseid;
}
if ($classid > 0) {
    $scopewhere .= ' AND sc.id = :scopeclassid';
    $scopeparams['scopeclassid'] = $classid;
}
$coursescopesql = "SELECT DISTINCT sc.corecourseid FROM {gmk_class} sc WHERE {$scopewhere}";

$checks = [];

$checks[] = [
    'id' => 'classes_without_gradecategory',
    'title' => 'Clases activas sin gradecategoryid',
    'description' => 'Si una clase no tiene categoria propia, el calculo puede mezclar items de otras clases del mismo curso.',
    'sql' => "SELECT c.id AS classid, c.name AS classname, c.corecourseid, c.groupid, c.coursesectionid, c.gradecategoryid
                FROM {gmk_class} c
               WHERE {$classwhere}
                 AND (c.gradecategoryid IS NULL OR c.gradecategoryid = 0)
            ORDER BY c.corecourseid, c.id",
    'params' => $classparams,
    'type' => 'issue',
];

$checks[] = [
    'id' => 'classes_with_invalid_gradecategory',
    'title' => 'Clases con gradecategoryid invalido o cruzado de curso',
    'description' => 'La categoria existe pero no pertenece al mismo curso de la clase, o no existe.',
    'sql' => "SELECT c.id AS classid, c.name AS classname, c.corecourseid, c.gradecategoryid,
                     gc.id AS foundcategoryid, gc.courseid AS categorycourseid, gc.fullname AS categoryname
                FROM {gmk_class} c
           LEFT JOIN {grade_categories} gc ON gc.id = c.gradecategoryid
               WHERE {$classwhere}
                 AND c.gradecategoryid > 0
                 AND (gc.id IS NULL OR gc.courseid <> c.corecourseid)
            ORDER BY c.corecourseid, c.id",
    'params' => $classparams,
    'type' => 'issue',
];

$checks[] = [
    'id' => 'duplicate_class_categories',
    'title' => 'Misma gradecategoryid usada por multiples clases',
    'description' => 'Dos o mas clases compartiendo categoria puede contaminar notas y ponderaciones.',
    'sql' => "SELECT c.id AS classid, c.name AS classname, c.corecourseid, c.gradecategoryid
                FROM {gmk_class} c
                JOIN (
                    SELECT corecourseid, gradecategoryid
                      FROM {gmk_class}
                     WHERE gradecategoryid > 0
                  GROUP BY corecourseid, gradecategoryid
                    HAVING COUNT(*) > 1
                ) d
                  ON d.corecourseid = c.corecourseid
                 AND d.gradecategoryid = c.gradecategoryid
               WHERE {$classwhere}
            ORDER BY c.corecourseid, c.gradecategoryid, c.id",
    'params' => $classparams,
    'type' => 'issue',
];

$checks[] = [
    'id' => 'section_mods_with_wrong_category',
    'title' => 'Items mod en seccion de clase pero con categoria distinta a la clase',
    'description' => 'La actividad esta en la seccion de la clase, pero su grade_item no esta en la categoria esperada.',
    'sql' => "SELECT c.id AS classid, c.name AS classname, c.corecourseid, c.gradecategoryid AS expectedcategoryid,
                     cm.id AS cmid, m.name AS modulename, cm.instance AS moduleinstance,
                     gi.id AS gradeitemid, gi.categoryid AS actualcategoryid, gi.itemname AS gradeitemname
                FROM {gmk_class} c
                JOIN {course_modules} cm
                  ON cm.course = c.corecourseid
                 AND cm.section = c.coursesectionid
                JOIN {modules} m
                  ON m.id = cm.module
                JOIN {grade_items} gi
                  ON gi.courseid = c.corecourseid
                 AND gi.itemtype = 'mod'
                 AND gi.itemmodule = m.name
                 AND gi.iteminstance = cm.instance
               WHERE {$classwhere}
                 AND c.gradecategoryid > 0
                 AND c.coursesectionid > 0
                 AND gi.categoryid <> c.gradecategoryid
            ORDER BY c.corecourseid, c.id, cm.id",
    'params' => $classparams,
    'type' => 'issue',
];

$checks[] = [
    'id' => 'category_items_outside_section',
    'title' => 'Items mod dentro de categoria de clase pero fuera de su seccion',
    'description' => 'El item esta en la categoria de la clase, pero su modulo no esta en la seccion de esa clase.',
    'sql' => "SELECT c.id AS classid, c.name AS classname, c.corecourseid,
                     c.coursesectionid AS expectedsection, gi.id AS gradeitemid, gi.itemname, gi.itemmodule, gi.iteminstance,
                     cm.id AS cmid, cm.section AS actualsection
                FROM {gmk_class} c
                JOIN {grade_items} gi
                  ON gi.courseid = c.corecourseid
                 AND gi.itemtype = 'mod'
                 AND gi.categoryid = c.gradecategoryid
           LEFT JOIN {modules} m
                  ON m.name = gi.itemmodule
           LEFT JOIN {course_modules} cm
                  ON cm.course = gi.courseid
                 AND cm.module = m.id
                 AND cm.instance = gi.iteminstance
               WHERE {$classwhere}
                 AND c.gradecategoryid > 0
                 AND c.coursesectionid > 0
                 AND (cm.id IS NULL OR cm.section <> c.coursesectionid)
            ORDER BY c.corecourseid, c.id, gi.id",
    'params' => $classparams,
    'type' => 'issue',
];

$checks[] = [
    'id' => 'attendance_module_invalid',
    'title' => 'attendancemoduleid invalido para la clase',
    'description' => 'Valida que el cmid de asistencia exista, sea modulo attendance, pertenezca al curso y a la seccion de la clase.',
    'sql' => "SELECT c.id AS classid, c.name AS classname, c.corecourseid, c.coursesectionid,
                     c.attendancemoduleid, cm.id AS foundcmid, cm.course AS cmcourse, cm.section AS cmsection,
                     m.name AS cmmodulename
                FROM {gmk_class} c
           LEFT JOIN {course_modules} cm
                  ON cm.id = c.attendancemoduleid
           LEFT JOIN {modules} m
                  ON m.id = cm.module
               WHERE {$classwhere}
                 AND (
                        c.attendancemoduleid IS NULL
                     OR c.attendancemoduleid = 0
                     OR cm.id IS NULL
                     OR m.name <> 'attendance'
                     OR cm.course <> c.corecourseid
                     OR (c.coursesectionid > 0 AND cm.section <> c.coursesectionid)
                 )
            ORDER BY c.corecourseid, c.id",
    'params' => $classparams,
    'type' => 'issue',
];

$checks[] = [
    'id' => 'section_mods_missing_grade_item',
    'title' => 'Actividades calificables sin grade_item',
    'description' => 'Actividad en seccion de clase (assign/quiz/attendance) sin registro en grade_items.',
    'sql' => "SELECT c.id AS classid, c.name AS classname, c.corecourseid, c.coursesectionid,
                     cm.id AS cmid, m.name AS modulename, cm.instance AS moduleinstance
                FROM {gmk_class} c
                JOIN {course_modules} cm
                  ON cm.course = c.corecourseid
                 AND cm.section = c.coursesectionid
                JOIN {modules} m
                  ON m.id = cm.module
           LEFT JOIN {grade_items} gi
                  ON gi.courseid = c.corecourseid
                 AND gi.itemtype = 'mod'
                 AND gi.itemmodule = m.name
                 AND gi.iteminstance = cm.instance
               WHERE {$classwhere}
                 AND c.coursesectionid > 0
                 AND m.name IN ('assign','quiz','attendance')
                 AND gi.id IS NULL
            ORDER BY c.corecourseid, c.id, cm.id",
    'params' => $classparams,
    'type' => 'issue',
];

$checks[] = [
    'id' => 'duplicate_root_categories',
    'title' => 'Cursos con mas de una categoria root (depth=1)',
    'description' => 'Esto puede romper el arbol de calificaciones y provocar calculos inconsistentes.',
    'sql' => "SELECT gc.courseid, COUNT(*) AS root_depth1_count
                FROM {grade_categories} gc
               WHERE gc.depth = 1
                 AND gc.courseid IN ({$coursescopesql})
            GROUP BY gc.courseid
              HAVING COUNT(*) > 1
            ORDER BY gc.courseid",
    'params' => $scopeparams,
    'type' => 'issue',
];

$checks[] = [
    'id' => 'course_items_wrong_iteminstance',
    'title' => 'grade_items de tipo course con iteminstance incorrecto',
    'description' => 'Debe cumplirse iteminstance = courseid para evitar errores de regrade.',
    'sql' => "SELECT gi.id AS courseitemid, gi.courseid, gi.iteminstance, gi.categoryid, gi.sortorder
                FROM {grade_items} gi
               WHERE gi.itemtype = 'course'
                 AND gi.courseid IN ({$coursescopesql})
                 AND gi.iteminstance <> gi.courseid
            ORDER BY gi.courseid, gi.id",
    'params' => $scopeparams,
    'type' => 'issue',
];

$checks[] = [
    'id' => 'duplicate_course_items',
    'title' => 'Cursos con multiples grade_items tipo course',
    'description' => 'Solo debe existir un item de tipo course por curso.',
    'sql' => "SELECT gi.courseid, COUNT(*) AS course_item_count
                FROM {grade_items} gi
               WHERE gi.itemtype = 'course'
                 AND gi.courseid IN ({$coursescopesql})
            GROUP BY gi.courseid
              HAVING COUNT(*) > 1
            ORDER BY gi.courseid",
    'params' => $scopeparams,
    'type' => 'issue',
];

$checks[] = [
    'id' => 'duplicate_category_totals',
    'title' => 'Duplicados de itemtype=category por iteminstance',
    'description' => 'No debe haber dos category totals para la misma categoria.',
    'sql' => "SELECT gi.courseid, gi.iteminstance AS categoryid, COUNT(*) AS category_item_count
                FROM {grade_items} gi
               WHERE gi.itemtype = 'category'
                 AND gi.courseid IN ({$coursescopesql})
            GROUP BY gi.courseid, gi.iteminstance
              HAVING COUNT(*) > 1
            ORDER BY gi.courseid, gi.iteminstance",
    'params' => $scopeparams,
    'type' => 'issue',
];

$checks[] = [
    'id' => 'class_category_aggregateonlygraded_zero',
    'title' => 'Categorias de clase con aggregateonlygraded = 0',
    'description' => 'Con esta configuracion, actividades sin nota pueden entrar como 0 segun la agregacion.',
    'sql' => "SELECT c.id AS classid, c.name AS classname, c.corecourseid, c.gradecategoryid,
                     gc.aggregation, gc.aggregateonlygraded, gc.fullname AS categoryname
                FROM {gmk_class} c
                JOIN {grade_categories} gc
                  ON gc.id = c.gradecategoryid
                 AND gc.courseid = c.corecourseid
               WHERE {$classwhere}
                 AND c.gradecategoryid > 0
                 AND gc.aggregateonlygraded = 0
            ORDER BY c.corecourseid, c.id",
    'params' => $classparams,
    'type' => 'issue',
];

$checks[] = [
    'id' => 'root_aggregation_mode_8',
    'title' => 'Categoria root con aggregation = 8',
    'description' => 'En Moodle core moderno, 8 corresponde a MODE (no MAX). Revisar si fue configurado asi por error.',
    'sql' => "SELECT gc.courseid, gc.id AS rootcategoryid, gc.aggregation, gc.aggregateonlygraded
                FROM {grade_categories} gc
               WHERE gc.depth = 1
                 AND gc.courseid IN ({$coursescopesql})
                 AND gc.aggregation = 8
            ORDER BY gc.courseid",
    'params' => $scopeparams,
    'type' => 'info',
];

$started = microtime(true);
$results = [];
$issuescount = 0;

foreach ($checks as $check) {
    $fetch = gmk_dbg_collect_rows($DB, $check['sql'], $check['params'], $maxrows);
    $hasrows = !empty($fetch['rows']);
    $status = 'ok';
    if ($check['type'] === 'issue' && $hasrows) {
        $status = 'issue';
        $issuescount++;
    } else if ($check['type'] === 'info' && $hasrows) {
        $status = 'info';
    }

    $results[] = [
        'id' => $check['id'],
        'title' => $check['title'],
        'description' => $check['description'],
        'sql' => $check['sql'],
        'params' => $check['params'],
        'status' => $status,
        'rows' => $fetch['rows'],
        'rows_returned' => $fetch['returned'],
        'truncated' => $fetch['truncated'],
    ];
}

$durationms = (int)round((microtime(true) - $started) * 1000);

echo '<h3 style="margin-bottom:8px;">Resumen</h3>';
echo '<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:14px;">';
echo '<div style="padding:10px 14px;border:1px solid #d0d7de;background:#fff;">'
    . '<b>Checks ejecutados:</b> ' . count($results) . '</div>';
echo '<div style="padding:10px 14px;border:1px solid #d0d7de;background:#fff;">'
    . '<b>Checks con issues:</b> <span style="color:#b91c1c;">' . $issuescount . '</span></div>';
echo '<div style="padding:10px 14px;border:1px solid #d0d7de;background:#fff;">'
    . '<b>Tiempo:</b> ' . $durationms . ' ms</div>';
echo '</div>';

$summaryrows = [];
foreach ($results as $r) {
    $summaryrows[] = [
        'check_id' => $r['id'],
        'status' => strtoupper($r['status']),
        'rows' => $r['rows_returned'],
        'truncated' => $r['truncated'] ? 'YES' : 'NO',
        'title' => $r['title'],
    ];
}
echo gmk_dbg_table($summaryrows);

echo '<hr style="margin:18px 0;">';
echo '<h3>Detalle por check</h3>';

foreach ($results as $r) {
    $boxborder = '#d0d7de';
    $boxbg = '#f6f8fa';
    if ($r['status'] === 'issue') {
        $boxborder = '#f5c2c7';
        $boxbg = '#fff5f5';
    } else if ($r['status'] === 'info') {
        $boxborder = '#b6d4fe';
        $boxbg = '#f0f7ff';
    }

    echo '<div style="border:1px solid ' . $boxborder . ';background:' . $boxbg . ';padding:12px;margin-bottom:14px;">';
    echo '<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;">';
    echo '<div><b>' . s($r['title']) . '</b><br><span style="font-size:12px;color:#444;">' . s($r['description']) . '</span></div>';
    echo '<div>' . gmk_dbg_status_badge($r['status']) . '</div>';
    echo '</div>';
    echo '<div style="margin-top:8px;font-size:12px;"><b>Rows:</b> ' . (int)$r['rows_returned']
        . ($r['truncated'] ? ' (truncated to ' . (int)$maxrows . ')' : '') . '</div>';

    echo '<details style="margin-top:8px;"><summary><b>SQL</b></summary>';
    echo '<pre style="white-space:pre-wrap;background:#0d1117;color:#e6edf3;padding:10px;font-size:12px;">' . s($r['sql']) . '</pre>';
    if (!empty($r['params'])) {
        echo '<pre style="white-space:pre-wrap;background:#111827;color:#d1fae5;padding:10px;font-size:12px;">'
            . s(json_encode($r['params'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
    }
    echo '</details>';

    echo '<div style="margin-top:10px;">' . gmk_dbg_table($r['rows']) . '</div>';
    echo '</div>';
}

$diagnostic = [
    'generated_at' => date('c'),
    'filters' => [
        'courseid' => $courseid,
        'classid' => $classid,
        'maxrows' => $maxrows,
    ],
    'summary' => [
        'checks_executed' => count($results),
        'checks_with_issues' => $issuescount,
        'duration_ms' => $durationms,
    ],
    'checks' => [],
];

foreach ($results as $r) {
    $diagnostic['checks'][] = [
        'id' => $r['id'],
        'title' => $r['title'],
        'status' => $r['status'],
        'rows' => $r['rows_returned'],
        'truncated' => $r['truncated'],
        'sql' => $r['sql'],
        'params' => $r['params'],
        'data' => $r['rows'],
    ];
}

echo '<h3>JSON del diagnostico</h3>';
echo '<textarea style="width:100%;min-height:320px;font-family:monospace;font-size:12px;">'
    . s(json_encode($diagnostic, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
    . '</textarea>';

echo $OUTPUT->footer();

