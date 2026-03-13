<?php
/**
 * Gradebook repair utility (preview/apply) for class-based architecture.
 *
 * @package local_grupomakro_core
 */

$configpath = __DIR__ . '/../../../config.php';
if (!file_exists($configpath)) {
    $configpath = __DIR__ . '/../../config.php';
}
if (!file_exists($configpath)) {
    $configpath = __DIR__ . '/../../../../config.php';
}
require_once($configpath);
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->libdir . '/gradelib.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$courseid = optional_param('courseid', 0, PARAM_INT);
$classid = optional_param('classid', 0, PARAM_INT);
$maxrows = optional_param('maxrows', 200, PARAM_INT);
$apply = optional_param('apply', 0, PARAM_BOOL);

$fixstructure = optional_param('fixstructure', 1, PARAM_BOOL);
$fixinvalidclasscat = optional_param('fixinvalidclasscat', 1, PARAM_BOOL);
$fixwrongmodcat = optional_param('fixwrongmodcat', 1, PARAM_BOOL);
$setaggregateonlygraded = optional_param('setaggregateonlygraded', 0, PARAM_BOOL);
$fixrootmode8 = optional_param('fixrootmode8', 0, PARAM_BOOL);
$forceregrade = optional_param('forceregrade', 1, PARAM_BOOL);

if ($maxrows < 10) {
    $maxrows = 10;
}
if ($maxrows > 1000) {
    $maxrows = 1000;
}

$pageurl = new moodle_url('/local/grupomakro_core/pages/debug_grade_repair.php', [
    'courseid' => $courseid,
    'classid' => $classid,
    'maxrows' => $maxrows,
]);
$PAGE->set_url($pageurl);
$PAGE->set_context($context);
$PAGE->set_title('Debug Repair Gradebook');
$PAGE->set_heading('Debug Repair Gradebook');

if ($apply) {
    require_sesskey();
}

/**
 * Build class filter SQL.
 *
 * @param int $courseid
 * @param int $classid
 * @param string $alias
 * @return array{0:string,1:array}
 */
function gmk_rep_build_class_scope(int $courseid, int $classid, string $alias = 'c'): array {
    $where = "{$alias}.closed = 0";
    $params = [];
    if ($courseid > 0) {
        $where .= " AND {$alias}.corecourseid = :f_courseid";
        $params['f_courseid'] = $courseid;
    }
    if ($classid > 0) {
        $where .= " AND {$alias}.id = :f_classid";
        $params['f_classid'] = $classid;
    }
    return [$where, $params];
}

/**
 * Get rows with limited display size but full count.
 *
 * @param moodle_database $db
 * @param string $sql
 * @param array $params
 * @param int $maxrows
 * @return array{rows:array,total:int,truncated:bool}
 */
function gmk_rep_collect_rows($db, string $sql, array $params, int $maxrows): array {
    $rows = [];
    $total = 0;
    $rs = $db->get_recordset_sql($sql, $params);
    foreach ($rs as $record) {
        $total++;
        if ($total <= $maxrows) {
            $rows[] = (array)$record;
        }
    }
    $rs->close();
    return [
        'rows' => $rows,
        'total' => $total,
        'truncated' => ($total > $maxrows),
    ];
}

/**
 * Render plain html table.
 *
 * @param array $rows
 * @return string
 */
function gmk_rep_table(array $rows): string {
    if (empty($rows)) {
        return '<p style="margin:0;color:#2e7d32;"><b>Sin filas.</b></p>';
    }
    $headers = array_keys($rows[0]);
    $out = '<div style="overflow:auto;max-height:380px;border:1px solid #e5e7eb;background:#fff;">';
    $out .= '<table style="width:100%;border-collapse:collapse;font-size:12px;font-family:monospace;">';
    $out .= '<thead><tr style="background:#1f6feb;color:#fff;">';
    foreach ($headers as $h) {
        $out .= '<th style="padding:6px;border:1px solid #d0d7de;text-align:left;">' . s($h) . '</th>';
    }
    $out .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $out .= '<tr>';
        foreach ($headers as $h) {
            $v = array_key_exists($h, $row) ? $row[$h] : '';
            if (is_null($v)) {
                $v = 'NULL';
            }
            $out .= '<td style="padding:6px;border:1px solid #eef2f7;vertical-align:top;">' . s((string)$v) . '</td>';
        }
        $out .= '</tr>';
    }
    $out .= '</tbody></table></div>';
    return $out;
}

/**
 * Query current anomalies for selected scope.
 *
 * @param moodle_database $db
 * @param string $classwhere
 * @param array $classparams
 * @param array $courseids
 * @param int $maxrows
 * @return array
 */
function gmk_rep_load_anomalies($db, string $classwhere, array $classparams, array $courseids, int $maxrows): array {
    $out = [];

    $sqlinvalid = "SELECT c.id AS classid, c.name AS classname, c.corecourseid, c.gradecategoryid,
                          gc.id AS foundcategoryid, gc.courseid AS categorycourseid, gc.fullname AS categoryname
                     FROM {gmk_class} c
                LEFT JOIN {grade_categories} gc ON gc.id = c.gradecategoryid
                    WHERE {$classwhere}
                      AND c.gradecategoryid > 0
                      AND (gc.id IS NULL OR gc.courseid <> c.corecourseid)
                 ORDER BY c.corecourseid, c.id";
    $out['invalidclasscat'] = gmk_rep_collect_rows($db, $sqlinvalid, $classparams, $maxrows);

    $sqlwrongmodcat = "SELECT c.id AS classid, c.name AS classname, c.corecourseid, c.gradecategoryid AS expectedcategoryid,
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
                     ORDER BY c.corecourseid, c.id, cm.id";
    $out['wrongmodcat'] = gmk_rep_collect_rows($db, $sqlwrongmodcat, $classparams, $maxrows);

    $sqlaggzero = "SELECT c.id AS classid, c.name AS classname, c.corecourseid, c.gradecategoryid,
                          gc.aggregation, gc.aggregateonlygraded, gc.fullname AS categoryname
                     FROM {gmk_class} c
                     JOIN {grade_categories} gc
                       ON gc.id = c.gradecategoryid
                      AND gc.courseid = c.corecourseid
                    WHERE {$classwhere}
                      AND c.gradecategoryid > 0
                      AND gc.aggregateonlygraded = 0
                 ORDER BY c.corecourseid, c.id";
    $out['aggzero'] = gmk_rep_collect_rows($db, $sqlaggzero, $classparams, $maxrows);

    if (empty($courseids)) {
        $out['duproots'] = ['rows' => [], 'total' => 0, 'truncated' => false];
        $out['wrongcourseiteminstance'] = ['rows' => [], 'total' => 0, 'truncated' => false];
        $out['dupcourseitems'] = ['rows' => [], 'total' => 0, 'truncated' => false];
        $out['rootmode8'] = ['rows' => [], 'total' => 0, 'truncated' => false];
        return $out;
    }

    list($insql, $inparams) = $db->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'cid');

    $sqlduproots = "SELECT gc.courseid, COUNT(*) AS root_depth1_count
                      FROM {grade_categories} gc
                     WHERE gc.depth = 1
                       AND gc.courseid {$insql}
                  GROUP BY gc.courseid
                    HAVING COUNT(*) > 1
                  ORDER BY gc.courseid";
    $out['duproots'] = gmk_rep_collect_rows($db, $sqlduproots, $inparams, $maxrows);

    $sqlwrongcourseitem = "SELECT gi.id AS courseitemid, gi.courseid, gi.iteminstance, gi.categoryid, gi.sortorder
                             FROM {grade_items} gi
                            WHERE gi.itemtype = 'course'
                              AND gi.courseid {$insql}
                              AND gi.iteminstance <> gi.courseid
                         ORDER BY gi.courseid, gi.id";
    $out['wrongcourseiteminstance'] = gmk_rep_collect_rows($db, $sqlwrongcourseitem, $inparams, $maxrows);

    $sqldupcourseitems = "SELECT gi.courseid, COUNT(*) AS course_item_count
                            FROM {grade_items} gi
                           WHERE gi.itemtype = 'course'
                             AND gi.courseid {$insql}
                        GROUP BY gi.courseid
                          HAVING COUNT(*) > 1
                        ORDER BY gi.courseid";
    $out['dupcourseitems'] = gmk_rep_collect_rows($db, $sqldupcourseitems, $inparams, $maxrows);

    $sqlrootmode8 = "SELECT gc.courseid, gc.id AS rootcategoryid, gc.aggregation
                       FROM {grade_categories} gc
                      WHERE gc.depth = 1
                        AND gc.courseid {$insql}
                        AND gc.aggregation = 8
                   ORDER BY gc.courseid, gc.id";
    $out['rootmode8'] = gmk_rep_collect_rows($db, $sqlrootmode8, $inparams, $maxrows);

    return $out;
}

echo $OUTPUT->header();
echo '<h2 style="margin-top:0;">Repair de gradebook por clases</h2>';
echo '<p style="max-width:1150px;margin-top:0;">Esta pagina permite previsualizar y aplicar reparaciones de estructura '
    . 'de calificaciones para clases/cursos. Ejecuta primero en preview, luego aplica con sesskey. '
    . 'Se recomienda respaldo previo de base de datos.</p>';

echo '<form method="get" style="border:1px solid #dbe4ef;background:#f8fafc;padding:12px;margin-bottom:12px;">';
echo '<label><b>Course ID:</b></label> <input type="number" name="courseid" value="' . (int)$courseid . '" style="width:120px;margin-right:12px;">';
echo '<label><b>Class ID:</b></label> <input type="number" name="classid" value="' . (int)$classid . '" style="width:120px;margin-right:12px;">';
echo '<label><b>Max rows:</b></label> <input type="number" name="maxrows" min="10" max="1000" value="' . (int)$maxrows . '" style="width:100px;margin-right:12px;">';
echo '<button type="submit" style="padding:6px 12px;">Actualizar preview</button>';
echo '</form>';

list($classwhere, $classparams) = gmk_rep_build_class_scope($courseid, $classid, 'c');

$courses = $DB->get_records_sql(
    "SELECT DISTINCT c.corecourseid AS courseid, co.fullname
       FROM {gmk_class} c
       JOIN {course} co ON co.id = c.corecourseid
      WHERE {$classwhere}
   ORDER BY co.fullname",
    $classparams
);
$courseids = [];
foreach ($courses as $c) {
    $courseids[] = (int)$c->courseid;
}

$anomalies = gmk_rep_load_anomalies($DB, $classwhere, $classparams, $courseids, $maxrows);

echo '<h3>Preview de hallazgos</h3>';
$previewsummary = [
    ['check' => 'invalid class gradecategory', 'rows' => $anomalies['invalidclasscat']['total']],
    ['check' => 'mods with wrong category', 'rows' => $anomalies['wrongmodcat']['total']],
    ['check' => 'duplicate root categories', 'rows' => $anomalies['duproots']['total']],
    ['check' => 'course items wrong iteminstance', 'rows' => $anomalies['wrongcourseiteminstance']['total']],
    ['check' => 'duplicate course items', 'rows' => $anomalies['dupcourseitems']['total']],
    ['check' => 'class categories aggregateonlygraded=0', 'rows' => $anomalies['aggzero']['total']],
    ['check' => 'root aggregation=8 (info)', 'rows' => $anomalies['rootmode8']['total']],
];
echo gmk_rep_table($previewsummary);

$applyresult = [
    'applied' => false,
    'filters' => ['courseid' => $courseid, 'classid' => $classid],
    'options' => [
        'fixstructure' => (int)$fixstructure,
        'fixinvalidclasscat' => (int)$fixinvalidclasscat,
        'fixwrongmodcat' => (int)$fixwrongmodcat,
        'setaggregateonlygraded' => (int)$setaggregateonlygraded,
        'fixrootmode8' => (int)$fixrootmode8,
        'forceregrade' => (int)$forceregrade,
    ],
    'counts' => [
        'structure_courses' => 0,
        'structure_errors' => 0,
        'invalidclasscat_fixed' => 0,
        'invalidclasscat_errors' => 0,
        'wrongmodcat_fixed' => 0,
        'wrongmodcat_conflicts' => 0,
        'wrongmodcat_errors' => 0,
        'aggzero_updated' => 0,
        'rootmode8_updated' => 0,
        'regrade_courses_ok' => 0,
        'regrade_courses_fail' => 0,
    ],
    'structure_details' => [],
    'errors' => [],
    'logs' => [],
];

if ($apply) {
    $applyresult['applied'] = true;
    $started = microtime(true);
    $impactedcourses = [];

    if ($fixstructure) {
        foreach ($courseids as $cid) {
            try {
                $stats = gmk_repair_course_gradebook_duplicates((int)$cid);
                $applyresult['counts']['structure_courses']++;
                $applyresult['structure_details'][] = ['courseid' => (int)$cid] + $stats;
                $applyresult['logs'][] = "structure repaired courseid={$cid}";
                $impactedcourses[(int)$cid] = true;
            } catch (Throwable $e) {
                $applyresult['counts']['structure_errors']++;
                $applyresult['errors'][] = "structure error courseid={$cid}: " . $e->getMessage();
            }
        }
    }

    if ($fixinvalidclasscat) {
        $sqlinvalidall = "SELECT c.id AS classid
                            FROM {gmk_class} c
                       LEFT JOIN {grade_categories} gc ON gc.id = c.gradecategoryid
                           WHERE {$classwhere}
                             AND c.gradecategoryid > 0
                             AND (gc.id IS NULL OR gc.courseid <> c.corecourseid)
                        ORDER BY c.corecourseid, c.id";
        $invalidall = $DB->get_records_sql($sqlinvalidall, $classparams);
        foreach ($invalidall as $r) {
            $cid = (int)$r->classid;
            try {
                $class = $DB->get_record('gmk_class', ['id' => $cid], '*', MUST_EXIST);
                $suffix = '%-' . (int)$class->id . ' grade category';
                $existingsql = "SELECT id
                                  FROM {grade_categories}
                                 WHERE courseid = :courseid
                                   AND " . $DB->sql_like('fullname', ':suffix') . "
                              ORDER BY id DESC";
                $existing = $DB->get_record_sql($existingsql, ['courseid' => (int)$class->corecourseid, 'suffix' => $suffix], IGNORE_MULTIPLE);

                $newcatid = 0;
                $action = 'none';
                if ($existing && !empty($existing->id)) {
                    $newcatid = (int)$existing->id;
                    $action = 'relinked_suffix';
                } else {
                    $newcatid = (int)create_class_grade_category($class);
                    $action = 'created_new';
                }

                if ($newcatid > 0) {
                    $DB->set_field('gmk_class', 'gradecategoryid', $newcatid, ['id' => (int)$class->id]);
                    $applyresult['counts']['invalidclasscat_fixed']++;
                    $applyresult['logs'][] = "invalidclasscat fixed classid={$class->id} action={$action} catid={$newcatid}";
                    $impactedcourses[(int)$class->corecourseid] = true;
                } else {
                    $applyresult['counts']['invalidclasscat_errors']++;
                    $applyresult['errors'][] = "invalidclasscat classid={$class->id}: no cat id returned";
                }
            } catch (Throwable $e) {
                $applyresult['counts']['invalidclasscat_errors']++;
                $applyresult['errors'][] = "invalidclasscat classid={$cid}: " . $e->getMessage();
            }
        }
    }

    if ($fixwrongmodcat) {
        $sqlwrongall = "SELECT c.id AS classid, c.corecourseid, c.gradecategoryid AS expectedcategoryid,
                               gi.id AS gradeitemid, gi.categoryid AS actualcategoryid
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
                      ORDER BY c.corecourseid, c.id, gi.id";
        $wrongall = $DB->get_records_sql($sqlwrongall, $classparams);

        $moveplan = [];
        foreach ($wrongall as $r) {
            $gid = (int)$r->gradeitemid;
            $expected = (int)$r->expectedcategoryid;
            if (!isset($moveplan[$gid])) {
                $moveplan[$gid] = [
                    'gradeitemid' => $gid,
                    'expectedcategoryid' => $expected,
                    'corecourseid' => (int)$r->corecourseid,
                    'classid' => (int)$r->classid,
                ];
            } else if ((int)$moveplan[$gid]['expectedcategoryid'] !== $expected) {
                $applyresult['counts']['wrongmodcat_conflicts']++;
                $applyresult['errors'][] = "wrongmodcat conflict gradeitemid={$gid} expected="
                    . $moveplan[$gid]['expectedcategoryid'] . " vs {$expected}";
                unset($moveplan[$gid]);
            }
        }

        foreach ($moveplan as $plan) {
            try {
                if (!$DB->record_exists('grade_categories', ['id' => (int)$plan['expectedcategoryid'], 'courseid' => (int)$plan['corecourseid']])) {
                    $applyresult['counts']['wrongmodcat_errors']++;
                    $applyresult['errors'][] = "wrongmodcat skip gradeitemid={$plan['gradeitemid']} invalid expected category";
                    continue;
                }
                $DB->set_field('grade_items', 'categoryid', (int)$plan['expectedcategoryid'], ['id' => (int)$plan['gradeitemid']]);
                $applyresult['counts']['wrongmodcat_fixed']++;
                $applyresult['logs'][] = "wrongmodcat moved gradeitemid={$plan['gradeitemid']} -> categoryid={$plan['expectedcategoryid']}";
                $impactedcourses[(int)$plan['corecourseid']] = true;
            } catch (Throwable $e) {
                $applyresult['counts']['wrongmodcat_errors']++;
                $applyresult['errors'][] = "wrongmodcat gradeitemid={$plan['gradeitemid']}: " . $e->getMessage();
            }
        }
    }

    if ($setaggregateonlygraded) {
        $sqlaggall = "SELECT DISTINCT gc.id AS categoryid, c.corecourseid
                        FROM {gmk_class} c
                        JOIN {grade_categories} gc
                          ON gc.id = c.gradecategoryid
                         AND gc.courseid = c.corecourseid
                       WHERE {$classwhere}
                         AND c.gradecategoryid > 0
                         AND gc.aggregateonlygraded = 0
                    ORDER BY gc.id";
        $aggrows = $DB->get_records_sql($sqlaggall, $classparams);
        foreach ($aggrows as $r) {
            try {
                $DB->set_field('grade_categories', 'aggregateonlygraded', 1, ['id' => (int)$r->categoryid]);
                $applyresult['counts']['aggzero_updated']++;
                $applyresult['logs'][] = "aggregateonlygraded set to 1 categoryid={$r->categoryid}";
                $impactedcourses[(int)$r->corecourseid] = true;
            } catch (Throwable $e) {
                $applyresult['errors'][] = "aggregateonlygraded categoryid={$r->categoryid}: " . $e->getMessage();
            }
        }
    }

    if ($fixrootmode8 && !empty($courseids)) {
        list($insql, $inparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'rc');
        $sqlmode8 = "SELECT id AS rootcategoryid, courseid
                       FROM {grade_categories}
                      WHERE depth = 1
                        AND aggregation = 8
                        AND courseid {$insql}
                   ORDER BY courseid, id";
        $mode8rows = $DB->get_records_sql($sqlmode8, $inparams);
        foreach ($mode8rows as $r) {
            try {
                $DB->set_field('grade_categories', 'aggregation', 6, ['id' => (int)$r->rootcategoryid]);
                $applyresult['counts']['rootmode8_updated']++;
                $applyresult['logs'][] = "root aggregation changed 8->6 rootcategoryid={$r->rootcategoryid}";
                $impactedcourses[(int)$r->courseid] = true;
            } catch (Throwable $e) {
                $applyresult['errors'][] = "rootmode8 rootcategoryid={$r->rootcategoryid}: " . $e->getMessage();
            }
        }
    }

    if ($forceregrade && !empty($impactedcourses)) {
        foreach (array_keys($impactedcourses) as $cid) {
            try {
                grade_force_full_regrading((int)$cid);
                $applyresult['counts']['regrade_courses_ok']++;
                $applyresult['logs'][] = "regrade queued courseid={$cid}";
            } catch (Throwable $e) {
                $applyresult['counts']['regrade_courses_fail']++;
                $applyresult['errors'][] = "regrade courseid={$cid}: " . $e->getMessage();
            }
        }
    }

    $applyresult['duration_ms'] = (int)round((microtime(true) - $started) * 1000);
}

$postanomalies = null;
if ($apply) {
    $postanomalies = gmk_rep_load_anomalies($DB, $classwhere, $classparams, $courseids, $maxrows);
}

echo '<h3>Aplicar reparacion</h3>';
echo '<form method="post" style="border:1px solid #e5e7eb;background:#fff;padding:12px;margin-bottom:14px;">';
echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
echo '<input type="hidden" name="apply" value="1">';
echo '<input type="hidden" name="courseid" value="' . (int)$courseid . '">';
echo '<input type="hidden" name="classid" value="' . (int)$classid . '">';
echo '<input type="hidden" name="maxrows" value="' . (int)$maxrows . '">';

echo '<label style="display:block;margin:6px 0;"><input type="checkbox" name="fixstructure" value="1" ' . ($fixstructure ? 'checked' : '') . '> Reparar estructura de gradebook por curso (roots/course-items/orphans)</label>';
echo '<label style="display:block;margin:6px 0;"><input type="checkbox" name="fixinvalidclasscat" value="1" ' . ($fixinvalidclasscat ? 'checked' : '') . '> Reparar clases con gradecategoryid invalido (relink/crear categoria)</label>';
echo '<label style="display:block;margin:6px 0;"><input type="checkbox" name="fixwrongmodcat" value="1" ' . ($fixwrongmodcat ? 'checked' : '') . '> Mover grade_items mod a categoria correcta de la clase</label>';
echo '<label style="display:block;margin:6px 0;"><input type="checkbox" name="setaggregateonlygraded" value="1" ' . ($setaggregateonlygraded ? 'checked' : '') . '> Cambiar aggregateonlygraded a 1 en categorias de clase</label>';
echo '<label style="display:block;margin:6px 0;"><input type="checkbox" name="fixrootmode8" value="1" ' . ($fixrootmode8 ? 'checked' : '') . '> Cambiar aggregation root 8 -> 6 (usar solo si confirmado)</label>';
echo '<label style="display:block;margin:6px 0;"><input type="checkbox" name="forceregrade" value="1" ' . ($forceregrade ? 'checked' : '') . '> Forzar regrade de cursos impactados</label>';
echo '<button type="submit" style="margin-top:8px;padding:8px 14px;background:#c62828;color:#fff;border:none;">Aplicar reparacion</button>';
echo '</form>';

if ($applyresult['applied']) {
    echo '<h3>Resultado de aplicacion</h3>';
    $sumrows = [];
    foreach ($applyresult['counts'] as $k => $v) {
        $sumrows[] = ['metric' => $k, 'value' => $v];
    }
    if (isset($applyresult['duration_ms'])) {
        $sumrows[] = ['metric' => 'duration_ms', 'value' => (int)$applyresult['duration_ms']];
    }
    echo gmk_rep_table($sumrows);

    if (!empty($applyresult['structure_details'])) {
        echo '<h4>Detalle estructura por curso</h4>';
        echo gmk_rep_table($applyresult['structure_details']);
    }

    if (!empty($applyresult['errors'])) {
        echo '<h4 style="color:#b91c1c;">Errores</h4>';
        $errrows = [];
        foreach ($applyresult['errors'] as $e) {
            $errrows[] = ['error' => $e];
        }
        echo gmk_rep_table($errrows);
    }

    if (!empty($applyresult['logs'])) {
        echo '<details><summary><b>Logs de aplicacion</b></summary>';
        $logrows = [];
        foreach ($applyresult['logs'] as $l) {
            $logrows[] = ['log' => $l];
        }
        echo gmk_rep_table($logrows);
        echo '</details>';
    }

    if (is_array($postanomalies)) {
        echo '<h4>Post-check rapido</h4>';
        $postrows = [
            ['check' => 'invalid class gradecategory', 'rows' => $postanomalies['invalidclasscat']['total']],
            ['check' => 'mods with wrong category', 'rows' => $postanomalies['wrongmodcat']['total']],
            ['check' => 'duplicate root categories', 'rows' => $postanomalies['duproots']['total']],
            ['check' => 'course items wrong iteminstance', 'rows' => $postanomalies['wrongcourseiteminstance']['total']],
            ['check' => 'duplicate course items', 'rows' => $postanomalies['dupcourseitems']['total']],
            ['check' => 'class aggregateonlygraded=0', 'rows' => $postanomalies['aggzero']['total']],
            ['check' => 'root aggregation=8 (info)', 'rows' => $postanomalies['rootmode8']['total']],
        ];
        echo gmk_rep_table($postrows);
    }
}

echo '<h3>Muestras de hallazgos (preview)</h3>';
echo '<details><summary><b>invalid class gradecategory</b> (' . (int)$anomalies['invalidclasscat']['total'] . ')</summary>'
    . gmk_rep_table($anomalies['invalidclasscat']['rows']) . '</details>';
echo '<details><summary><b>mods with wrong category</b> (' . (int)$anomalies['wrongmodcat']['total'] . ')</summary>'
    . gmk_rep_table($anomalies['wrongmodcat']['rows']) . '</details>';
echo '<details><summary><b>duplicate root categories</b> (' . (int)$anomalies['duproots']['total'] . ')</summary>'
    . gmk_rep_table($anomalies['duproots']['rows']) . '</details>';
echo '<details><summary><b>course items wrong iteminstance</b> (' . (int)$anomalies['wrongcourseiteminstance']['total'] . ')</summary>'
    . gmk_rep_table($anomalies['wrongcourseiteminstance']['rows']) . '</details>';
echo '<details><summary><b>duplicate course items</b> (' . (int)$anomalies['dupcourseitems']['total'] . ')</summary>'
    . gmk_rep_table($anomalies['dupcourseitems']['rows']) . '</details>';
echo '<details><summary><b>class aggregateonlygraded=0</b> (' . (int)$anomalies['aggzero']['total'] . ')</summary>'
    . gmk_rep_table($anomalies['aggzero']['rows']) . '</details>';
echo '<details><summary><b>root aggregation=8 (info)</b> (' . (int)$anomalies['rootmode8']['total'] . ')</summary>'
    . gmk_rep_table($anomalies['rootmode8']['rows']) . '</details>';

$payload = [
    'generated_at' => date('c'),
    'filters' => ['courseid' => $courseid, 'classid' => $classid, 'maxrows' => $maxrows],
    'preview_counts' => [
        'invalidclasscat' => $anomalies['invalidclasscat']['total'],
        'wrongmodcat' => $anomalies['wrongmodcat']['total'],
        'duproots' => $anomalies['duproots']['total'],
        'wrongcourseiteminstance' => $anomalies['wrongcourseiteminstance']['total'],
        'dupcourseitems' => $anomalies['dupcourseitems']['total'],
        'aggzero' => $anomalies['aggzero']['total'],
        'rootmode8' => $anomalies['rootmode8']['total'],
    ],
    'apply_result' => $applyresult,
    'postcheck_counts' => $postanomalies ? [
        'invalidclasscat' => $postanomalies['invalidclasscat']['total'],
        'wrongmodcat' => $postanomalies['wrongmodcat']['total'],
        'duproots' => $postanomalies['duproots']['total'],
        'wrongcourseiteminstance' => $postanomalies['wrongcourseiteminstance']['total'],
        'dupcourseitems' => $postanomalies['dupcourseitems']['total'],
        'aggzero' => $postanomalies['aggzero']['total'],
        'rootmode8' => $postanomalies['rootmode8']['total'],
    ] : null,
];
echo '<h3>JSON resultado</h3>';
echo '<textarea style="width:100%;min-height:280px;font-family:monospace;font-size:12px;">'
    . s(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
    . '</textarea>';

echo $OUTPUT->footer();
