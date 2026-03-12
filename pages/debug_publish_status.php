<?php
/**
 * Debug: Estado de publicacion de horarios
 *
 * Muestra que clases de un periodo tienen grupos/secciones/actividades creadas
 * y cuales les falta, permitiendo re-crear las estructuras faltantes.
 *
 * @package local_grupomakro_core
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir  . '/adminlib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$ajax = optional_param('ajax', '', PARAM_ALPHANUMEXT);

/**
 * Snapshot de diagnostico para errores de publicacion de actividades.
 */
function gmk_debug_publish_diagnostics($class) {
    global $DB;

    $diag = [
        'class' => [
            'id' => (int)$class->id,
            'corecourseid' => (int)$class->corecourseid,
            'coursesectionid' => (int)$class->coursesectionid,
            'attendancemoduleid' => (int)$class->attendancemoduleid,
            'groupid' => (int)$class->groupid,
            'name' => (string)$class->name,
        ],
    ];

    $modsAttendance = $DB->get_records('modules', ['name' => 'attendance'], 'id ASC', 'id,name');
    $modsBBB = $DB->get_records('modules', ['name' => 'bigbluebuttonbn'], 'id ASC', 'id,name');
    $diag['modules'] = [
        'attendance_ids' => array_values(array_map('intval', array_keys($modsAttendance))),
        'bbb_ids' => array_values(array_map('intval', array_keys($modsBBB))),
    ];

    if (!empty($class->coursesectionid)) {
        $sec = $DB->get_record('course_sections', ['id' => $class->coursesectionid], 'id,course,section,name,sequence');
        $diag['section'] = $sec ? (array)$sec : null;
        if ($sec) {
            $sameSectionRows = $DB->get_records('course_sections', ['course' => $sec->course, 'section' => $sec->section], 'id ASC', 'id,course,section');
            $diag['section']['same_course_section_count'] = count($sameSectionRows);
            $diag['section']['same_course_section_ids'] = array_values(array_map('intval', array_keys($sameSectionRows)));
        }
    }

    if (!empty($class->attendancemoduleid)) {
        $attCM = $DB->get_record_sql(
            "SELECT cm.id, cm.course, cm.section, cm.instance, m.name AS modulename
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.id = :cmid",
            ['cmid' => (int)$class->attendancemoduleid]
        );
        $diag['attendance_cmid'] = $attCM ? (array)$attCM : null;
    }

    if (!empty($class->corecourseid) && !empty($class->coursesectionid) && !empty($modsAttendance)) {
        $attids = array_map('intval', array_keys($modsAttendance));
        list($insql, $inparams) = $DB->get_in_or_equal($attids, SQL_PARAMS_NAMED, 'attm');
        $params = $inparams + ['courseid' => (int)$class->corecourseid, 'sectionid' => (int)$class->coursesectionid];
        $cms = $DB->get_records_sql(
            "SELECT id, course, section, module, instance
               FROM {course_modules}
              WHERE course = :courseid
                AND section = :sectionid
                AND module {$insql}
           ORDER BY id ASC",
            $params
        );
        $diag['attendance_cms_in_target_section'] = array_values(array_map(static function($r) {
            return (array)$r;
        }, $cms));
    }

    if (!empty($class->corecourseid)) {
        $coursecontexts = $DB->get_records('context', ['contextlevel' => CONTEXT_COURSE, 'instanceid' => (int)$class->corecourseid], 'id ASC', 'id,contextlevel,instanceid,path,depth');
        $diag['course_context_count'] = count($coursecontexts);
        $diag['course_context_ids'] = array_values(array_map('intval', array_keys($coursecontexts)));

        $rootCandidates = $DB->get_records_select(
            'grade_categories',
            'courseid = :c AND (parent IS NULL OR parent = 0)',
            ['c' => (int)$class->corecourseid],
            'id ASC',
            'id,courseid,parent,depth,path,fullname'
        );
        $diag['gradebook_root_candidates_count'] = count($rootCandidates);
        $diag['gradebook_root_candidates'] = array_values(array_map(static function($r) {
            return (array)$r;
        }, $rootCandidates));

        $rootcats = array_filter($rootCandidates, static function($r) {
            return (int)$r->depth === 1;
        });
        $diag['gradebook_root_categories_count'] = count($rootcats);
        $diag['gradebook_root_categories'] = array_values(array_map(static function($r) {
            return (array)$r;
        }, $rootcats));

        $courseitems = $DB->get_records('grade_items', [
            'courseid' => (int)$class->corecourseid,
            'itemtype' => 'course',
            'iteminstance' => (int)$class->corecourseid
        ], 'id ASC', 'id,courseid,itemtype,iteminstance,categoryid,sortorder,itemname');
        $diag['gradebook_course_items_count'] = count($courseitems);
        $diag['gradebook_course_items'] = array_values(array_map(static function($r) {
            return (array)$r;
        }, $courseitems));
    }

    return $diag;
}

function gmk_debug_is_duplicate_read_error(Throwable $e): bool {
    $msg = function_exists('mb_strtolower') ? mb_strtolower((string)$e->getMessage(), 'UTF-8') : strtolower((string)$e->getMessage());
    if (strpos($msg, 'registro en lectura') !== false) return true;
    if (strpos($msg, 'mas de un registro') !== false) return true;
    if (strpos($msg, 'más de un registro') !== false) return true;
    if (strpos($msg, 'more than one record') !== false) return true;

    $p = $e->getPrevious();
    while ($p) {
        $pmsg = function_exists('mb_strtolower') ? mb_strtolower((string)$p->getMessage(), 'UTF-8') : strtolower((string)$p->getMessage());
        if (strpos($pmsg, 'registro en lectura') !== false
            || strpos($pmsg, 'mas de un registro') !== false
            || strpos($pmsg, 'más de un registro') !== false
            || strpos($pmsg, 'more than one record') !== false) {
            return true;
        }
        $p = $p->getPrevious();
    }
    return false;
}

function gmk_debug_log_exception_chain(Throwable $e, array &$log, string $label = 'Error') {
    $idx = 0;
    $cur = $e;
    while ($cur) {
        $prefix = $idx === 0 ? $label : ($label . " (prev {$idx})");
        $log[] = "{$prefix}: [" . get_class($cur) . '] ' . $cur->getMessage();
        $log[] = "{$prefix} @ " . basename($cur->getFile()) . ':' . $cur->getLine();
        if (property_exists($cur, 'debuginfo') && !empty($cur->debuginfo)) {
            $log[] = "{$prefix} debuginfo: " . (string)$cur->debuginfo;
        }
        $traceLines = explode("\n", $cur->getTraceAsString());
        $tracePrefix = $idx === 0 ? 'trace' : ('prev-trace ' . $idx);
        foreach (array_slice($traceLines, 0, 8) as $tl) {
            $log[] = "    [{$tracePrefix}] " . $tl;
        }
        $cur = $cur->getPrevious();
        $idx++;
    }
}

function gmk_debug_cleanup_partial_class_activities($classid, array &$log) {
    global $DB;

    $class = $DB->get_record('gmk_class', ['id' => $classid], '*', MUST_EXIST);
    if (empty($class->corecourseid)) {
        $log[] = "Auto-repair: clase sin corecourseid, no se puede limpiar.";
        return $class;
    }

    $log[] = "Auto-repair: limpiando artefactos de actividades para clase {$class->id}...";

    $attendanceModuleIds = [];
    $bbbModuleIds = [];

    $mods = $DB->get_records_list('modules', 'name', ['attendance', 'bigbluebuttonbn'], '', 'id,name');
    $attendanceModIds = [];
    $bbbModIds = [];
    foreach ($mods as $m) {
        if ($m->name === 'attendance') $attendanceModIds[] = (int)$m->id;
        if ($m->name === 'bigbluebuttonbn') $bbbModIds[] = (int)$m->id;
    }

    if (!empty($attendanceModIds)) {
        list($insql, $inparams) = $DB->get_in_or_equal($attendanceModIds, SQL_PARAMS_NAMED, 'attm');
        $params = $inparams + ['courseid' => (int)$class->corecourseid, 'suffix' => '%-' . (int)$class->id];
        $attcms = $DB->get_records_sql(
            "SELECT cm.id
               FROM {course_modules} cm
               JOIN {attendance} a ON a.id = cm.instance
              WHERE cm.course = :courseid
                AND cm.module {$insql}
                AND " . $DB->sql_like('a.name', ':suffix'),
            $params
        );
        $attendanceModuleIds = array_values(array_map(static function($r) { return (int)$r->id; }, $attcms));
    }

    if (!empty($bbbModIds)) {
        list($insql2, $inparams2) = $DB->get_in_or_equal($bbbModIds, SQL_PARAMS_NAMED, 'bbbm');
        $params2 = $inparams2 + ['courseid' => (int)$class->corecourseid, 'suffix' => '%-' . (int)$class->id . '-%'];
        $bbbcms = $DB->get_records_sql(
            "SELECT cm.id
               FROM {course_modules} cm
               JOIN {bigbluebuttonbn} b ON b.id = cm.instance
              WHERE cm.course = :courseid
                AND cm.module {$insql2}
                AND " . $DB->sql_like('b.name', ':suffix'),
            $params2
        );
        $bbbModuleIds = array_values(array_map(static function($r) { return (int)$r->id; }, $bbbcms));
    }

    if (!empty($class->attendancemoduleid)) {
        $attendanceModuleIds[] = (int)$class->attendancemoduleid;
    }
    if (!empty($class->bbbmoduleids)) {
        foreach (explode(',', (string)$class->bbbmoduleids) as $id) {
            $id = (int)trim($id);
            if ($id > 0) $bbbModuleIds[] = $id;
        }
    }

    $attendanceModuleIds = array_values(array_unique(array_filter($attendanceModuleIds)));
    $bbbModuleIds = array_values(array_unique(array_filter($bbbModuleIds)));

    foreach ($bbbModuleIds as $cmid) {
        try {
            course_delete_module($cmid);
            $log[] = "Auto-repair: BBB eliminado cmid={$cmid}";
        } catch (Throwable $delerr) {
            $log[] = "Auto-repair WARN: no se pudo eliminar BBB cmid={$cmid}: " . $delerr->getMessage();
        }
    }

    foreach ($attendanceModuleIds as $cmid) {
        try {
            course_delete_module($cmid);
            $log[] = "Auto-repair: Attendance eliminado cmid={$cmid}";
        } catch (Throwable $delerr) {
            $log[] = "Auto-repair WARN: no se pudo eliminar attendance cmid={$cmid}: " . $delerr->getMessage();
        }
    }

    $DB->delete_records('gmk_bbb_attendance_relation', ['classid' => $classid]);
    $DB->set_field('gmk_class', 'attendancemoduleid', 0, ['id' => $classid]);
    $DB->set_field('gmk_class', 'bbbmoduleids', null, ['id' => $classid]);

    $log[] = "Auto-repair: campos reset (attendancemoduleid=0, bbbmoduleids=NULL).";
    return $DB->get_record('gmk_class', ['id' => $classid], '*', MUST_EXIST);
}

function gmk_debug_rebuild_grade_category_tree(int $parentid, string $parentpath, int $depth, array &$log, int $maxdepth = 30) {
    global $DB;

    if ($depth > $maxdepth) {
        $log[] = "Auto-repair gradebook WARN: maxdepth alcanzado al reconstruir arbol desde parent={$parentid}";
        return;
    }

    $children = $DB->get_records('grade_categories', ['parent' => $parentid], 'id ASC', 'id,parent,depth,path');
    foreach ($children as $child) {
        $newpath = $parentpath . $child->id . '/';
        if ((int)$child->depth !== $depth) {
            $DB->set_field('grade_categories', 'depth', $depth, ['id' => $child->id]);
        }
        if ((string)$child->path !== $newpath) {
            $DB->set_field('grade_categories', 'path', $newpath, ['id' => $child->id]);
        }
        gmk_debug_rebuild_grade_category_tree((int)$child->id, $newpath, $depth + 1, $log, $maxdepth);
    }
}

function gmk_debug_repair_course_gradebook_duplicates($courseid, array &$log) {
    global $DB, $CFG;

    if (empty($courseid)) {
        return;
    }

    require_once($CFG->libdir . '/gradelib.php');

    $rootCandidates = $DB->get_records_select(
        'grade_categories',
        'courseid = :c AND (parent IS NULL OR parent = 0)',
        ['c' => (int)$courseid],
        'id ASC',
        'id,courseid,parent,depth,path,fullname'
    );
    $rootcats = array_filter($rootCandidates, static function($r) {
        return (int)$r->depth === 1;
    });

    $courseitems = $DB->get_records('grade_items', [
        'courseid' => (int)$courseid,
        'itemtype' => 'course',
        'iteminstance' => (int)$courseid
    ], 'id ASC', 'id,courseid,itemtype,iteminstance,categoryid,sortorder,itemname');

    $log[] = "Auto-repair gradebook: rootCandidates=" . count($rootCandidates)
        . " rootcats(depth=1)=" . count($rootcats)
        . " courseitems=" . count($courseitems);

    $rootIds = array_values(array_map('intval', array_keys($rootCandidates)));
    $canonicalRootId = null;
    foreach ($courseitems as $it) {
        $cid = (int)$it->categoryid;
        if ($cid > 0 && in_array($cid, $rootIds, true)) {
            $canonicalRootId = $cid;
            break;
        }
    }
    if (!$canonicalRootId && !empty($rootcats)) {
        $canonicalRootId = (int)array_key_first($rootcats);
    }
    if (!$canonicalRootId && !empty($rootIds)) {
        $canonicalRootId = (int)min($rootIds);
    }

    if ($canonicalRootId) {
        foreach ($rootCandidates as $rid => $cat) {
            $rid = (int)$rid;
            if ($rid === $canonicalRootId) {
                continue;
            }

            $movedChildren = $DB->count_records('grade_categories', ['parent' => $rid]);
            if ($movedChildren > 0) {
                $DB->set_field('grade_categories', 'parent', $canonicalRootId, ['parent' => $rid]);
            }
            $movedItems = $DB->count_records('grade_items', ['categoryid' => $rid]);
            if ($movedItems > 0) {
                $DB->set_field('grade_items', 'categoryid', $canonicalRootId, ['categoryid' => $rid]);
            }

            $DB->delete_records('grade_categories', ['id' => $rid]);
            $log[] = "Auto-repair gradebook: root category fusionada id={$rid} -> canonical={$canonicalRootId} (children={$movedChildren}, items={$movedItems})";
        }

        $DB->set_field('grade_categories', 'parent', null, ['id' => $canonicalRootId]);
        $DB->set_field('grade_categories', 'depth', 1, ['id' => $canonicalRootId]);
        $DB->set_field('grade_categories', 'path', '/' . $canonicalRootId . '/', ['id' => $canonicalRootId]);
        gmk_debug_rebuild_grade_category_tree($canonicalRootId, '/' . $canonicalRootId . '/', 2, $log);
    }

    $courseitems = $DB->get_records('grade_items', [
        'courseid' => (int)$courseid,
        'itemtype' => 'course',
        'iteminstance' => (int)$courseid
    ], 'id ASC', 'id,courseid,itemtype,iteminstance,categoryid,sortorder,itemname');

    if (empty($courseitems) && $canonicalRootId) {
        try {
            $courseItem = new \grade_item();
            $courseItem->courseid = (int)$courseid;
            $courseItem->categoryid = (int)$canonicalRootId;
            $courseItem->itemtype = 'course';
            $courseItem->iteminstance = (int)$courseid;
            $courseItem->itemnumber = 0;
            $courseItem->gradetype = GRADE_TYPE_VALUE;
            $courseItem->grademax = 100;
            $courseItem->grademin = 0;
            $courseItem->sortorder = 1;
            $courseItem->insert();
            $log[] = "Auto-repair gradebook: course grade_item creado id={$courseItem->id} categoryid={$canonicalRootId}";
        } catch (Throwable $ce) {
            $log[] = "Auto-repair gradebook WARN: no se pudo crear course grade_item: " . $ce->getMessage();
        }
    }

    if (count($courseitems) > 1) {
        $canonicalItem = null;
        foreach ($courseitems as $it) {
            if (!empty($canonicalRootId) && (int)$it->categoryid === (int)$canonicalRootId) {
                $canonicalItem = $it;
                break;
            }
        }
        if (!$canonicalItem) {
            $canonicalItem = reset($courseitems);
        }
        $canonicalItemId = (int)$canonicalItem->id;
        foreach ($courseitems as $itid => $it) {
            $itid = (int)$itid;
            if ($itid === $canonicalItemId) {
                continue;
            }
            $hasGrades = $DB->record_exists('grade_grades', ['itemid' => $itid]);
            if (!$hasGrades) {
                $DB->delete_records('grade_items', ['id' => $itid]);
                $log[] = "Auto-repair gradebook: course grade_item duplicado eliminado id={$itid}";
            } else {
                $log[] = "Auto-repair gradebook WARN: course grade_item id={$itid} no eliminado (tiene grades).";
            }
        }
    }

    if (!empty($canonicalRootId)) {
        $courseitems = $DB->get_records('grade_items', [
            'courseid' => (int)$courseid,
            'itemtype' => 'course',
            'iteminstance' => (int)$courseid
        ], 'id ASC', 'id,categoryid');
        foreach ($courseitems as $it) {
            if ((int)$it->categoryid !== (int)$canonicalRootId) {
                $DB->set_field('grade_items', 'categoryid', $canonicalRootId, ['id' => (int)$it->id]);
                $log[] = "Auto-repair gradebook: course grade_item {$it->id} relinked a categoryid={$canonicalRootId}";
            }
        }
    }
}

function gmk_debug_rows_to_array($rows) {
    return array_values(array_map(static function($r) {
        return (array)$r;
    }, $rows));
}

function gmk_debug_capture_sql($label, $sql, array $params = [], int $limit = 300) {
    global $DB;
    $rows = $DB->get_records_sql($sql, $params, 0, $limit);
    return [
        'label' => $label,
        'sql' => $sql,
        'params' => $params,
        'count' => count($rows),
        'limit' => $limit,
        'rows' => gmk_debug_rows_to_array($rows),
    ];
}

function gmk_debug_table_columns($table) {
    global $DB;
    $cols = $DB->get_columns($table);
    $out = [];
    foreach ($cols as $name => $col) {
        $raw = (array)$col;
        $out[] = [
            'name' => (string)$name,
            'type' => property_exists($col, 'type') ? (string)$col->type : null,
            'meta_type' => property_exists($col, 'meta_type') ? (string)$col->meta_type : null,
            'not_null' => property_exists($col, 'not_null') ? (bool)$col->not_null : null,
            'max_length' => property_exists($col, 'max_length') ? $col->max_length : null,
            'default_value' => property_exists($col, 'default_value') ? $col->default_value : null,
            'raw' => $raw,
        ];
    }
    return $out;
}

function gmk_debug_collect_class_snapshot(int $classid, int $hintcmid = 0): array {
    global $DB;

    $class = $DB->get_record('gmk_class', ['id' => $classid], '*', MUST_EXIST);
    $courseid = (int)$class->corecourseid;
    $sectionid = (int)$class->coursesectionid;
    $attcmid = (int)$class->attendancemoduleid;

    $groupReason = '';
    $sectionReason = '';
    $attReason = '';

    $snapshot = [
        'generated_at' => date('c'),
        'class' => (array)$class,
        'flags' => [
            'group_valid' => gmk_is_valid_class_group($class, $groupReason),
            'section_valid' => gmk_is_valid_class_section($class, $sectionReason),
            'attendance_valid' => gmk_is_valid_class_attendance_module($class, $attReason),
            'group_reason' => $groupReason,
            'section_reason' => $sectionReason,
            'attendance_reason' => $attReason,
        ],
        'structures' => [],
        'queries' => [],
    ];

    $tables = [
        'gmk_class',
        'groups',
        'course_sections',
        'course_modules',
        'modules',
        'attendance',
        'attendance_sessions',
        'bigbluebuttonbn',
        'gmk_bbb_attendance_relation',
        'grade_categories',
        'grade_items',
        'grade_grades',
        'context',
    ];
    foreach ($tables as $t) {
        try {
            $snapshot['structures'][$t] = gmk_debug_table_columns($t);
        } catch (Throwable $e) {
            $snapshot['structures'][$t] = ['error' => $e->getMessage()];
        }
    }

    if ($class->groupid) {
        $snapshot['queries'][] = gmk_debug_capture_sql(
            'group_record',
            "SELECT id, courseid, name, idnumber FROM {groups} WHERE id = :gid",
            ['gid' => (int)$class->groupid],
            10
        );
    }

    if ($sectionid > 0) {
        $snapshot['queries'][] = gmk_debug_capture_sql(
            'section_record',
            "SELECT id, course, section, name, sequence, visible
               FROM {course_sections}
              WHERE id = :sid",
            ['sid' => $sectionid],
            10
        );
    }

    if ($attcmid > 0) {
        $snapshot['queries'][] = gmk_debug_capture_sql(
            'attendance_cmid_record',
            "SELECT cm.id, cm.course, cm.section, cm.instance, cm.visible, cm.deletioninprogress, m.name AS modulename
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.id = :cmid",
            ['cmid' => $attcmid],
            10
        );
    }

    if ($courseid > 0 && $sectionid > 0) {
        $snapshot['queries'][] = gmk_debug_capture_sql(
            'section_modules',
            "SELECT cm.id, m.name AS modulename, cm.instance, cm.visible, cm.section
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.course = :courseid
                AND cm.section = :sectionid
           ORDER BY cm.id ASC",
            ['courseid' => $courseid, 'sectionid' => $sectionid],
            500
        );

        $snapshot['queries'][] = gmk_debug_capture_sql(
            'attendance_modules_in_section',
            "SELECT cm.id AS cmid, cm.course, cm.section, cm.instance, cm.visible, a.name
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
               JOIN {attendance} a ON a.id = cm.instance
              WHERE cm.course = :courseid
                AND cm.section = :sectionid
                AND m.name = 'attendance'
           ORDER BY cm.id ASC",
            ['courseid' => $courseid, 'sectionid' => $sectionid],
            500
        );

        $snapshot['queries'][] = gmk_debug_capture_sql(
            'bbb_modules_in_section',
            "SELECT cm.id AS cmid, cm.course, cm.section, cm.instance, cm.visible, b.name, b.openingtime, b.closingtime
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
               JOIN {bigbluebuttonbn} b ON b.id = cm.instance
              WHERE cm.course = :courseid
                AND cm.section = :sectionid
                AND m.name = 'bigbluebuttonbn'
           ORDER BY cm.id ASC",
            ['courseid' => $courseid, 'sectionid' => $sectionid],
            2000
        );
    }

    if ($courseid > 0) {
        $snapshot['queries'][] = gmk_debug_capture_sql(
            'modules_catalog',
            "SELECT id, name FROM {modules} WHERE name IN ('attendance','bigbluebuttonbn') ORDER BY name, id",
            [],
            20
        );

        $snapshot['queries'][] = gmk_debug_capture_sql(
            'course_context',
            "SELECT id, contextlevel, instanceid, path, depth
               FROM {context}
              WHERE contextlevel = :ctxlvl
                AND instanceid = :courseid
           ORDER BY id ASC",
            ['ctxlvl' => CONTEXT_COURSE, 'courseid' => $courseid],
            20
        );

        $snapshot['queries'][] = gmk_debug_capture_sql(
            'grade_categories_all',
            "SELECT id, courseid, parent, depth, path, fullname
               FROM {grade_categories}
              WHERE courseid = :courseid
           ORDER BY id ASC",
            ['courseid' => $courseid],
            500
        );

        $snapshot['queries'][] = gmk_debug_capture_sql(
            'grade_items_all',
            "SELECT id, courseid, itemtype, itemmodule, iteminstance, itemnumber, categoryid, itemname, sortorder
               FROM {grade_items}
              WHERE courseid = :courseid
           ORDER BY id ASC",
            ['courseid' => $courseid],
            1000
        );

        $snapshot['queries'][] = gmk_debug_capture_sql(
            'grade_items_course_totals',
            "SELECT id, courseid, itemtype, iteminstance, categoryid, itemname, sortorder
               FROM {grade_items}
              WHERE courseid = :courseid
                AND itemtype = 'course'
                AND iteminstance = :iteminstance
           ORDER BY id ASC",
            ['courseid' => $courseid, 'iteminstance' => $courseid],
            100
        );

        $snapshot['queries'][] = gmk_debug_capture_sql(
            'grade_grades_by_item_count',
            "SELECT gi.id AS itemid, gi.itemtype, gi.itemmodule, gi.iteminstance, COUNT(gg.id) AS grade_rows
               FROM {grade_items} gi
               LEFT JOIN {grade_grades} gg ON gg.itemid = gi.id
              WHERE gi.courseid = :courseid
           GROUP BY gi.id, gi.itemtype, gi.itemmodule, gi.iteminstance
           ORDER BY grade_rows DESC, gi.id ASC",
            ['courseid' => $courseid],
            1000
        );

        $snapshot['queries'][] = gmk_debug_capture_sql(
            'attendance_candidates_by_classid_suffix',
            "SELECT cm.id AS cmid, cm.course, cm.section, cm.instance, cm.visible, a.name
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
               JOIN {attendance} a ON a.id = cm.instance
              WHERE cm.course = :courseid
                AND m.name = 'attendance'
                AND " . $DB->sql_like('a.name', ':suffix') . "
           ORDER BY cm.id DESC",
            ['courseid' => $courseid, 'suffix' => '%-' . $classid],
            500
        );

        $snapshot['queries'][] = gmk_debug_capture_sql(
            'bbb_candidates_by_classid_suffix',
            "SELECT cm.id AS cmid, cm.course, cm.section, cm.instance, cm.visible, b.name, b.openingtime, b.closingtime
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
               JOIN {bigbluebuttonbn} b ON b.id = cm.instance
              WHERE cm.course = :courseid
                AND m.name = 'bigbluebuttonbn'
                AND " . $DB->sql_like('b.name', ':suffix') . "
           ORDER BY cm.id DESC",
            ['courseid' => $courseid, 'suffix' => '%-' . $classid . '-%'],
            2000
        );

        $snapshot['queries'][] = gmk_debug_capture_sql(
            'recent_attendance_bbb_cms_in_course',
            "SELECT cm.id AS cmid, cm.course, cm.section, cm.instance, cm.visible, m.name AS modulename, cm.added
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.course = :courseid
                AND m.name IN ('attendance','bigbluebuttonbn')
           ORDER BY cm.id DESC",
            ['courseid' => $courseid],
            500
        );
    }

    if ($hintcmid > 0) {
        $snapshot['queries'][] = gmk_debug_capture_sql(
            'hint_cmid_lookup',
            "SELECT cm.id AS cmid, cm.course, cm.section, cm.instance, cm.visible, cm.deletioninprogress, m.name AS modulename
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.id = :cmid",
            ['cmid' => $hintcmid],
            10
        );
    }

    $relations = $DB->get_records('gmk_bbb_attendance_relation', ['classid' => $classid], 'id ASC');
    $snapshot['queries'][] = [
        'label' => 'gmk_bbb_attendance_relation_by_class',
        'sql' => "SELECT * FROM {gmk_bbb_attendance_relation} WHERE classid = :classid ORDER BY id ASC",
        'params' => ['classid' => $classid],
        'count' => count($relations),
        'limit' => 1000,
        'rows' => gmk_debug_rows_to_array($relations),
    ];

    $bbbcmids = [];
    if (!empty($class->bbbmoduleids)) {
        foreach (explode(',', (string)$class->bbbmoduleids) as $id) {
            $id = (int)trim($id);
            if ($id > 0) $bbbcmids[] = $id;
        }
    }
    foreach ($relations as $rel) {
        if (!empty($rel->bbbmoduleid)) {
            $bbbcmids[] = (int)$rel->bbbmoduleid;
        }
    }
    $bbbcmids = array_values(array_unique(array_filter($bbbcmids)));

    if (!empty($bbbcmids)) {
        list($insql, $inparams) = $DB->get_in_or_equal($bbbcmids, SQL_PARAMS_NAMED, 'bbbc');
        $snapshot['queries'][] = gmk_debug_capture_sql(
            'bbb_cmids_expanded',
            "SELECT cm.id AS cmid, cm.course, cm.section, cm.instance, cm.visible, m.name AS modulename,
                    b.id AS bbbid, b.name, b.openingtime, b.closingtime
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
               LEFT JOIN {bigbluebuttonbn} b ON b.id = cm.instance
              WHERE cm.id {$insql}
           ORDER BY cm.id ASC",
            $inparams,
            1000
        );
    }

    if ($attcmid > 0) {
        $attinstance = $DB->get_field('course_modules', 'instance', ['id' => $attcmid]);
        if ($attinstance) {
            $snapshot['queries'][] = gmk_debug_capture_sql(
                'attendance_instance',
                "SELECT id, name, course, grade
                   FROM {attendance}
                  WHERE id = :attid",
                ['attid' => (int)$attinstance],
                10
            );
            $snapshot['queries'][] = gmk_debug_capture_sql(
                'attendance_sessions',
                "SELECT id, attendanceid, sessdate, duration, groupid, lasttaken
                   FROM {attendance_sessions}
                  WHERE attendanceid = :attid
               ORDER BY sessdate ASC, id ASC",
                ['attid' => (int)$attinstance],
                2000
            );
        }
    }

    if ($sectionid > 0) {
        $sectionrow = $DB->get_record('course_sections', ['id' => $sectionid], 'id,sequence');
        $sequence = $sectionrow ? trim((string)$sectionrow->sequence) : '';
        $seqcmids = $sequence === '' ? [] : array_values(array_filter(array_map('intval', explode(',', $sequence))));
        $sectioncms = $DB->get_records_sql(
            "SELECT id FROM {course_modules}
              WHERE course = :courseid
                AND section = :sectionid
           ORDER BY id ASC",
            ['courseid' => $courseid, 'sectionid' => $sectionid]
        );
        $sectioncmids = array_values(array_map(static function($r) { return (int)$r->id; }, $sectioncms));
        $snapshot['sequence_check'] = [
            'sequence_raw' => $sequence,
            'sequence_cmids' => $seqcmids,
            'section_cmids' => $sectioncmids,
            'missing_in_sequence' => array_values(array_diff($sectioncmids, $seqcmids)),
            'extra_in_sequence' => array_values(array_diff($seqcmids, $sectioncmids)),
        ];
    }

    return $snapshot;
}

// -- AJAX: inspeccionar datos DB de una clase para analisis --------------------
if ($ajax === 'inspect_class') {
    $PAGE->set_context(context_system::instance());
    ob_start();
    header('Content-Type: application/json');
    try {
        require_sesskey();
        $classid = required_param('classid', PARAM_INT);
        $hintcmid = optional_param('hintcmid', 0, PARAM_INT);
        $data = gmk_debug_collect_class_snapshot($classid, $hintcmid);
        ob_end_clean();
        echo json_encode(['status' => 'success', 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        ob_end_clean();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// -- AJAX: re-crear estructuras Moodle para una clase -------------------------
if ($ajax === 'recreate') {
    $PAGE->set_context(context_system::instance());
    ob_start(); // buffer all output so debug messages don't contaminate JSON
    header('Content-Type: application/json');
    try {
        $classid = required_param('classid', PARAM_INT);
        require_sesskey();

        $class = $DB->get_record('gmk_class', ['id' => $classid], '*', MUST_EXIST);
        $log   = [];

        // 1. Grupo
        $groupReason = '';
        if (!gmk_is_valid_class_group($class, $groupReason)) {
            if (!empty($class->groupid)) {
                $log[] = "Grupo invalido ({$groupReason}), recreando...";
            }
            try {
                $groupId = create_class_group($class);
                $DB->set_field('gmk_class', 'groupid', $groupId, ['id' => $classid]);
                $class->groupid = $groupId;
                $log[] = "OK Grupo creado: id=$groupId";
            } catch (Throwable $e) {
                ob_end_clean();
                echo json_encode(['status' => 'error', 'message' => 'Error creando grupo: ' . $e->getMessage(), 'log' => $log]);
                exit;
            }
        } else {
            $log[] = "- Grupo ya existe: id={$class->groupid}";
        }

        // 2. Seccion
        $sectionReason = '';
        if (!gmk_is_valid_class_section($class, $sectionReason)) {
            if (!empty($class->coursesectionid)) {
                $log[] = "Seccion invalida ({$sectionReason}), recreando...";
            }
            try {
                $sectionId = create_class_section($class);
                $DB->set_field('gmk_class', 'coursesectionid', $sectionId, ['id' => $classid]);
                $class->coursesectionid = $sectionId;
                $log[] = "OK Seccion creada: id=$sectionId";
            } catch (Throwable $e) {
                $log[] = "WARN Error creando seccion: " . $e->getMessage();
            }
        } else {
            $log[] = "- Seccion ya existe: id={$class->coursesectionid}";
        }

        // 3. Actividades (attendance + BBB)
        $attReason = '';
        $hasActivities = gmk_is_valid_class_attendance_module($class, $attReason);
        if (!$hasActivities && !empty($class->attendancemoduleid)) {
            $log[] = "Attendance invalido ({$attReason}), recreando actividades...";
        }
        try {
            create_class_activities($class, $hasActivities);
            // Re-read to get updated fields
            $class = $DB->get_record('gmk_class', ['id' => $classid]);
            $log[] = "OK Actividades creadas/actualizadas: attendancemoduleid={$class->attendancemoduleid}";
        } catch (Throwable $e) {
            $log[] = "WARN Error creando actividades: " . $e->getMessage();
            gmk_debug_log_exception_chain($e, $log, 'Excepcion');
            $log[] = "-> Excepcion: " . get_class($e);
            $log[] = "-> Ubicacion: " . basename($e->getFile()) . ":" . $e->getLine();
            $traceLines = explode("\n", $e->getTraceAsString());
            foreach (array_slice($traceLines, 0, 6) as $tl) {
                $log[] = "    " . $tl;
            }
            $diag = gmk_debug_publish_diagnostics($class);
            $log[] = "-> Diagnostico: " . json_encode($diag, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if (gmk_debug_is_duplicate_read_error($e)) {
                $log[] = "-> Auto-repair: detectado error de duplicado en lectura. Limpiando y reintentando...";
                try {
                    gmk_debug_repair_course_gradebook_duplicates((int)$class->corecourseid, $log);
                    $class = gmk_debug_cleanup_partial_class_activities($classid, $log);
                    create_class_activities($class, false);
                    $class = $DB->get_record('gmk_class', ['id' => $classid], '*', MUST_EXIST);
                    $log[] = "OK Auto-repair: reintento exitoso, attendancemoduleid={$class->attendancemoduleid}";
                } catch (Throwable $retrye) {
                    $log[] = "WARN Auto-repair fallo en reintento: " . $retrye->getMessage();
                    gmk_debug_log_exception_chain($retrye, $log, 'RetryEx');
                    $diag2 = gmk_debug_publish_diagnostics($class);
                    $log[] = "-> Diagnostico post-reintento: " . json_encode($diag2, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            }
        }

        // Re-read final state and validate consistency.
        $class = $DB->get_record('gmk_class', ['id' => $classid]);
        $finalAttReason = '';
        if (!gmk_is_valid_class_attendance_module($class, $finalAttReason)) {
            $log[] = "ERROR final: attendance invalido tras re-crear ({$finalAttReason})";
            ob_end_clean();
            echo json_encode([
                'status'  => 'error',
                'message' => "La clase no quedo con attendance valido: {$finalAttReason}",
                'log'     => $log,
                'groupid' => $class->groupid,
                'coursesectionid' => $class->coursesectionid,
                'attendancemoduleid' => $class->attendancemoduleid,
                'diagnostics' => gmk_debug_publish_diagnostics($class),
            ]);
            exit;
        }

        ob_end_clean();
        echo json_encode([
            'status'  => 'success',
            'log'     => $log,
            'groupid'         => $class->groupid,
            'coursesectionid' => $class->coursesectionid,
            'attendancemoduleid' => $class->attendancemoduleid,
        ]);
    } catch (Throwable $e) {
        ob_end_clean();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// -- AJAX: re-crear TODAS las clases incompletas del periodo ------------------
if ($ajax === 'recreate_all') {
    $PAGE->set_context(context_system::instance());
    ob_start(); // buffer all output so debug messages don't contaminate JSON
    header('Content-Type: application/json');
    try {
        $periodid = required_param('periodid', PARAM_INT);
        require_sesskey();
        raise_memory_limit(MEMORY_HUGE);
        core_php_time_limit::raise(600);

        // Clases con alguna estructura faltante o invalida.
        $allPeriodClasses = $DB->get_records('gmk_class', ['periodid' => $periodid]);
        $classes = [];
        foreach ($allPeriodClasses as $candidate) {
            $dummy = '';
            $groupOk = gmk_is_valid_class_group($candidate, $dummy);
            $sectionOk = gmk_is_valid_class_section($candidate, $dummy);
            $attOk = gmk_is_valid_class_attendance_module($candidate, $dummy);
            if (!$groupOk || !$sectionOk || !$attOk) {
                $classes[$candidate->id] = $candidate;
            }
        }

        $results = ['ok' => 0, 'errors' => [], 'repairs' => [], 'skipped' => 0];

        foreach ($classes as $class) {
            try {
                $groupReason = '';
                if (!gmk_is_valid_class_group($class, $groupReason)) {
                    $groupId = create_class_group($class);
                    $DB->set_field('gmk_class', 'groupid', $groupId, ['id' => $class->id]);
                    $class->groupid = $groupId;
                }
                $sectionReason = '';
                if (!gmk_is_valid_class_section($class, $sectionReason)) {
                    $sectionId = create_class_section($class);
                    $DB->set_field('gmk_class', 'coursesectionid', $sectionId, ['id' => $class->id]);
                    $class->coursesectionid = $sectionId;
                }
                $attReason = '';
                $hasActivities = gmk_is_valid_class_attendance_module($class, $attReason);
                try {
                    create_class_activities($class, $hasActivities);
                } catch (Throwable $e) {
                    if (!gmk_debug_is_duplicate_read_error($e)) {
                        throw $e;
                    }
                    $repairlog = [];
                    $repairlog[] = "Clase {$class->id}: duplicate-read detectado, auto-repair en progreso.";
                    gmk_debug_repair_course_gradebook_duplicates((int)$class->corecourseid, $repairlog);
                    $class = gmk_debug_cleanup_partial_class_activities($class->id, $repairlog);
                    create_class_activities($class, false);
                    $results['repairs'][] = ['id' => $class->id, 'name' => $class->name, 'log' => $repairlog];
                }
                $class = $DB->get_record('gmk_class', ['id' => $class->id], '*', MUST_EXIST);
                $finalAttReason = '';
                if (!gmk_is_valid_class_attendance_module($class, $finalAttReason)) {
                    throw new \Exception("Attendance invalido tras recreacion: {$finalAttReason}");
                }
                $results['ok']++;
            } catch (Throwable $e) {
                $results['errors'][] = ['id' => $class->id, 'name' => $class->name, 'error' => $e->getMessage()];
            }
        }

        ob_end_clean();
        echo json_encode(['status' => 'success', 'data' => $results, 'total' => count($classes)]);
    } catch (Throwable $e) {
        ob_end_clean();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// -- Parametros ---------------------------------------------------------------
$periodid = optional_param('periodid', 0, PARAM_INT);
$allPeriods = $DB->get_records('gmk_academic_periods', null, 'id DESC', 'id, name');
if (!$periodid && !empty($allPeriods)) {
    $first = reset($allPeriods);
    $periodid = $first->id;
}

$PAGE->set_url('/local/grupomakro_core/pages/debug_publish_status.php');
$PAGE->set_context($context);
$PAGE->set_title('Debug: Estado de publicacion de horarios');
echo $OUTPUT->header();
?>
<style>
body{font-size:13px}
h2{margin-top:20px;border-bottom:2px solid #ddd;padding-bottom:6px}
table{border-collapse:collapse;width:100%;margin:8px 0;font-size:12px}
th,td{border:1px solid #ddd;padding:5px 8px;vertical-align:middle}
th{background:#f2f2f2;font-weight:bold;position:sticky;top:0;z-index:1}
.ok   {background:#d4edda}
.warn {background:#fff3cd}
.err  {background:#f8d7da}
.info-box{background:#d1ecf1;border:1px solid #bee5eb;border-radius:4px;padding:8px 12px;margin:6px 0;font-size:12px}
.warn-box{background:#fff3cd;border:1px solid #ffc107;border-radius:4px;padding:8px 12px;margin:6px 0}
.err-box {background:#f8d7da;border:1px solid #f5c6cb;border-radius:4px;padding:8px 12px;margin:6px 0}
.ok-box  {background:#d4edda;border:1px solid #c3e6cb;border-radius:4px;padding:8px 12px;margin:6px 0}
.btn{padding:4px 12px;border:none;border-radius:4px;cursor:pointer;color:#fff;font-size:12px}
.btn-primary{background:#007bff}.btn-success{background:#28a745}.btn-danger{background:#dc3545}.btn-warning{background:#fd7e14}
.btn:disabled{opacity:.5;cursor:not-allowed}
.check{color:green;font-weight:bold}.cross{color:red;font-weight:bold}.dash{color:#aaa}
.badge{display:inline-block;padding:2px 7px;border-radius:10px;font-size:11px;font-weight:bold;color:#fff}
.badge-ok{background:#28a745}.badge-warn{background:#fd7e14}.badge-err{background:#dc3545}
.log-out{font-family:monospace;font-size:11px;background:#1e1e1e;color:#d4d4d4;padding:8px;border-radius:4px;white-space:pre-wrap;max-height:520px;overflow-y:auto;margin-top:4px;display:none}
.spinner{display:inline-block;width:12px;height:12px;border:2px solid #eee;border-top-color:#007bff;border-radius:50%;animation:spin 1s linear infinite;vertical-align:middle}
@keyframes spin{to{transform:rotate(360deg)}}
</style>

<h1>Debug: Estado de Publicacion de Horarios</h1>

<!-- Selector de periodo -->
<form method="get" style="display:flex;gap:8px;align-items:center;margin-bottom:16px;flex-wrap:wrap">
  <label><strong>Periodo:</strong></label>
  <select name="periodid" style="padding:4px 8px;border-radius:4px;border:1px solid #ccc">
    <?php foreach ($allPeriods as $p): ?>
      <option value="<?php echo $p->id ?>" <?php echo ($p->id == $periodid) ? 'selected' : '' ?>>
        <?php echo htmlspecialchars($p->name) ?> (ID:<?php echo $p->id ?>)
      </option>
    <?php endforeach ?>
  </select>
  <button type="submit" class="btn btn-primary">Ver</button>
</form>

<?php if (!$periodid): ?>
<div class="warn-box">Selecciona un periodo.</div>
<?php echo $OUTPUT->footer(); exit; ?>
<?php endif ?>

<?php
// -- Cargar todas las clases del periodo --------------------------------------
$classes = $DB->get_records_sql(
    "SELECT c.*,
            co.fullname  AS coursename,
            lp.name      AS planname,
            u.firstname  AS instr_first,
            u.lastname   AS instr_last
       FROM {gmk_class} c
       LEFT JOIN {course}               co ON co.id = c.corecourseid
       LEFT JOIN {local_learning_plans} lp ON lp.id = c.learningplanid
       LEFT JOIN {user}                  u ON u.id  = c.instructorid
      WHERE c.periodid = :pid
      ORDER BY lp.name, co.fullname, c.name",
    ['pid' => $periodid]
);

$total    = count($classes);
$complete = 0; // group + section + activities
$noGroup  = 0;
$noSection= 0;
$noActivities = 0;
$incomplete = 0;

foreach ($classes as $c) {
    $dummy = '';
    $g  = gmk_is_valid_class_group($c, $dummy);
    $s  = gmk_is_valid_class_section($c, $dummy);
    $a  = gmk_is_valid_class_attendance_module($c, $dummy);
    if ($g && $s && $a) { $complete++; } else { $incomplete++; }
    if (!$g) $noGroup++;
    if (!$s) $noSection++;
    if (!$a) $noActivities++;
}
?>

<!-- Resumen -->
<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px">
  <div class="info-box" style="min-width:120px;text-align:center">
    <div style="font-size:24px;font-weight:bold"><?php echo $total ?></div>
    <div>Total clases</div>
  </div>
  <div class="ok-box" style="min-width:120px;text-align:center">
    <div style="font-size:24px;font-weight:bold"><?php echo $complete ?></div>
    <div>Completas</div>
  </div>
  <div class="<?php echo $incomplete > 0 ? 'err-box' : 'ok-box' ?>" style="min-width:120px;text-align:center">
    <div style="font-size:24px;font-weight:bold"><?php echo $incomplete ?></div>
    <div>Incompletas</div>
  </div>
  <div class="warn-box" style="min-width:120px;text-align:center">
    <div style="font-size:24px;font-weight:bold"><?php echo $noGroup ?></div>
    <div>Sin grupo</div>
  </div>
  <div class="warn-box" style="min-width:120px;text-align:center">
    <div style="font-size:24px;font-weight:bold"><?php echo $noSection ?></div>
    <div>Sin seccion</div>
  </div>
  <div class="warn-box" style="min-width:120px;text-align:center">
    <div style="font-size:24px;font-weight:bold"><?php echo $noActivities ?></div>
    <div>Sin actividades</div>
  </div>
</div>

<?php if ($incomplete > 0): ?>
<div style="margin-bottom:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
  <button class="btn btn-danger" onclick="recreateAll()">
    > Re-crear estructuras faltantes en las <?php echo $incomplete ?> clases incompletas
  </button>
  <span id="recreate-all-result" style="font-size:12px;font-weight:bold"></span>
</div>
<?php endif ?>

<!-- Filtro -->
<div style="margin-bottom:8px;display:flex;gap:8px;align-items:center">
  <label style="font-size:12px">Filtrar:</label>
  <button class="btn btn-primary" style="padding:2px 8px;font-size:11px" onclick="filterRows('all')">Todas (<?php echo $total ?>)</button>
  <button class="btn btn-danger"  style="padding:2px 8px;font-size:11px" onclick="filterRows('incomplete')">Incompletas (<?php echo $incomplete ?>)</button>
  <button class="btn btn-success" style="padding:2px 8px;font-size:11px" onclick="filterRows('complete')">Completas (<?php echo $complete ?>)</button>
</div>

<!-- Tabla principal -->
<table id="main-table">
  <thead>
    <tr>
      <th>ID</th>
      <th>Clase</th>
      <th>Plan / Curso</th>
      <th>Instructor</th>
      <th title="Moodle course ID">CID</th>
      <th title="groupid en gmk_class">Grupo</th>
      <th title="coursesectionid en gmk_class">Seccion</th>
      <th title="attendancemoduleid en gmk_class">Attendance</th>
      <th title="bbbmoduleids en gmk_class">BBB</th>
      <th>Estado</th>
      <th>Accion</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($classes as $c):
      $groupReason = '';
      $sectionReason = '';
      $attReason = '';
      $hasGroup  = gmk_is_valid_class_group($c, $groupReason);
      $hasSect   = gmk_is_valid_class_section($c, $sectionReason);
      $hasAtt    = gmk_is_valid_class_attendance_module($c, $attReason);
      $hasBBB    = !empty($c->bbbmoduleids);
      $allOk     = $hasGroup && $hasSect && $hasAtt;
      $rowClass  = $allOk ? 'ok' : ((!$hasGroup) ? 'err' : 'warn');

      // Verificar que groupid realmente existe en BD de Moodle
      $groupExists = !empty($c->groupid) ? $DB->record_exists('groups', ['id' => $c->groupid]) : false;
      $sectExists  = !empty($c->coursesectionid) ? $DB->record_exists('course_sections', ['id' => $c->coursesectionid]) : false;
      $attExists   = !empty($c->attendancemoduleid) ? $DB->record_exists('course_modules', ['id' => $c->attendancemoduleid]) : false;

      // Count BBB modules
      $bbbCount = 0;
      if (!empty($c->bbbmoduleids)) {
          $bbbIds = array_filter(explode(',', $c->bbbmoduleids));
          $bbbCount = count($bbbIds);
      }

      $statusBadge = $allOk
          ? '<span class="badge badge-ok">OK</span>'
          : '<span class="badge badge-err">INCOMPLETA</span>';

      // Attendance sessions count: attendancemoduleid is a course_modules.id (cmid).
      // course_modules.instance -> attendance.id -> attendance_sessions.attendanceid
      $attSessions = 0;
      if ($hasAtt && $attExists) {
          $attInstanceId = $DB->get_field('course_modules', 'instance', ['id' => $c->attendancemoduleid]);
          if ($attInstanceId) {
              $attSessions = $DB->count_records('attendance_sessions', ['attendanceid' => $attInstanceId]);
          }
      }
  ?>
  <tr class="<?php echo $rowClass ?>" data-complete="<?php echo $allOk ? '1' : '0' ?>" id="row-<?php echo $c->id ?>">
    <td><?php echo $c->id ?></td>
    <td>
      <strong><?php echo htmlspecialchars($c->name) ?></strong><br>
      <small style="color:#888">approved=<?php echo $c->approved ?> | type=<?php echo $c->type ?></small>
    </td>
    <td>
      <span style="font-size:11px;color:#555"><?php echo htmlspecialchars($c->planname ?? '-') ?></span><br>
      <span style="font-size:11px"><?php echo htmlspecialchars($c->coursename ?? 'ID:'.$c->corecourseid) ?></span>
    </td>
    <td style="font-size:11px"><?php echo $c->instructorid ? htmlspecialchars($c->instr_first . ' ' . $c->instr_last) : '-' ?></td>
    <td><?php echo $c->corecourseid ?></td>
    <td class="<?php echo $hasGroup ? ($groupExists ? '' : 'warn') : 'err' ?>">
      <?php if ($hasGroup): ?>
        <?php echo $groupExists ? '<span class="check">OK</span>' : '<span class="cross">WARN</span>' ?>
        <small><?php echo $c->groupid ?></small>
      <?php else: ?>
        <span class="cross">X</span>
      <?php endif ?>
    </td>
    <td class="<?php echo $hasSect ? ($sectExists ? '' : 'warn') : 'err' ?>">
      <?php if ($hasSect): ?>
        <?php echo $sectExists ? '<span class="check">OK</span>' : '<span class="cross">WARN</span>' ?>
        <small><?php echo $c->coursesectionid ?></small>
      <?php else: ?>
        <span class="cross">X</span>
      <?php endif ?>
    </td>
    <td class="<?php echo $hasAtt ? ($attExists ? '' : 'warn') : 'err' ?>">
      <?php if ($hasAtt): ?>
        <?php echo $attExists ? '<span class="check">OK</span>' : '<span class="cross">WARN</span>' ?>
        <small><?php echo $c->attendancemoduleid ?></small>
        <?php if ($attSessions > 0): ?>
          <br><small style="color:#28a745"><?php echo $attSessions ?> sesiones</small>
        <?php else: ?>
          <br><small style="color:#dc3545">0 sesiones</small>
        <?php endif ?>
      <?php else: ?>
        <span class="cross">X</span>
      <?php endif ?>
    </td>
    <td>
      <?php if ($hasBBB): ?>
        <span class="check">OK</span> <small><?php echo $bbbCount ?></small>
      <?php else: ?>
        <span class="dash">-</span>
      <?php endif ?>
    </td>
    <td><?php echo $statusBadge ?></td>
    <td>
      <?php if (!$allOk || !$groupExists || !$sectExists || !$attExists): ?>
      <button class="btn btn-warning" style="padding:2px 8px;font-size:11px"
        onclick="recreateOne(<?php echo $c->id ?>, this)">Re-crear</button>
      <?php else: ?>
      <span style="color:#28a745;font-size:11px">OK</span>
      <?php endif ?>
      <button class="btn btn-primary" style="padding:2px 8px;font-size:11px;margin-left:4px"
        onclick="inspectOne(<?php echo $c->id ?>, this)">Diagnosticar</button>
      <div class="log-out" id="log-<?php echo $c->id ?>"></div>
    </td>
  </tr>
  <?php endforeach ?>
  </tbody>
</table>

<script>
const SESSKEY  = <?php echo json_encode(sesskey()) ?>;
const AJAX_URL = <?php echo json_encode((new moodle_url('/local/grupomakro_core/pages/debug_publish_status.php'))->out(false)) ?>;
const PERIODID = <?php echo (int)$periodid ?>;

function filterRows(mode) {
    document.querySelectorAll('#main-table tbody tr').forEach(tr => {
        if (mode === 'all') {
            tr.style.display = '';
        } else if (mode === 'complete') {
            tr.style.display = tr.dataset.complete === '1' ? '' : 'none';
        } else if (mode === 'incomplete') {
            tr.style.display = tr.dataset.complete === '0' ? '' : 'none';
        }
    });
}

async function recreateOne(classid, btn) {
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span>';
    const logEl = document.getElementById('log-' + classid);
    logEl.style.display = 'block';
    logEl.textContent = 'Procesando...';

    const fd = new FormData();
    fd.append('ajax', 'recreate');
    fd.append('classid', classid);
    fd.append('sesskey', SESSKEY);

    try {
        const res  = await fetch(AJAX_URL, { method: 'POST', body: fd });
        const text = await res.text();
        let d;
        try { d = JSON.parse(text); } catch(e) { logEl.textContent = 'No JSON:\n' + text; btn.disabled = false; btn.textContent = 'Re-crear'; return; }

        const header = `status=${d.status || 'unknown'}`
            + (d.message ? `\nmessage=${d.message}` : '')
            + '\n';
        logEl.textContent = header + (d.log ? d.log.join('\n') : JSON.stringify(d));

        if (d.status === 'success') {
            btn.textContent = 'OK';
            btn.style.background = '#28a745';
            // Actualizar fila
            const row = document.getElementById('row-' + classid);
            if (row) {
                row.dataset.complete = '1';
                row.className = 'ok';
            }
        } else {
            btn.disabled = false;
            btn.textContent = 'Re-crear';
        }
    } catch(e) {
        logEl.textContent = 'Error JS: ' + e.message;
        btn.disabled = false;
        btn.textContent = 'Re-crear';
    }
}

async function inspectOne(classid, btn) {
    const prevHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span>';
    const logEl = document.getElementById('log-' + classid);
    logEl.style.display = 'block';
    logEl.textContent = 'Cargando diagnostico...';

    const fd = new FormData();
    fd.append('ajax', 'inspect_class');
    fd.append('classid', classid);
    // If the row currently shows an attendance cmid, pass it as hint for direct lookup.
    const row = document.getElementById('row-' + classid);
    if (row && row.children && row.children.length > 7) {
        const txt = (row.children[7].innerText || row.children[7].textContent || '').trim();
        const m = txt.match(/\\b(\\d{2,})\\b/);
        if (m && m[1]) {
            fd.append('hintcmid', m[1]);
        }
    }
    fd.append('sesskey', SESSKEY);

    try {
        const res = await fetch(AJAX_URL, { method: 'POST', body: fd });
        const text = await res.text();
        let d;
        try { d = JSON.parse(text); } catch(e) {
            logEl.textContent = 'No JSON:\n' + text;
            btn.disabled = false;
            btn.innerHTML = prevHtml;
            return;
        }
        if (d.status !== 'success') {
            logEl.textContent = 'Error: ' + (d.message || JSON.stringify(d));
            btn.disabled = false;
            btn.innerHTML = prevHtml;
            return;
        }

        logEl.textContent = JSON.stringify(d.data, null, 2);
        btn.disabled = false;
        btn.innerHTML = prevHtml;
    } catch (e) {
        logEl.textContent = 'Error JS: ' + e.message;
        btn.disabled = false;
        btn.innerHTML = prevHtml;
    }
}

async function recreateAll() {
    if (!confirm('Re-crear estructuras faltantes en TODAS las clases incompletas del periodo?\nEsto puede tardar varios minutos.')) return;

    const btn = event.target;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Procesando...';
    const resEl = document.getElementById('recreate-all-result');
    resEl.textContent = '';

    const fd = new FormData();
    fd.append('ajax', 'recreate_all');
    fd.append('periodid', PERIODID);
    fd.append('sesskey', SESSKEY);

    try {
        const res  = await fetch(AJAX_URL, { method: 'POST', body: fd });
        const text = await res.text();
        let d;
        try { d = JSON.parse(text); } catch(e) { resEl.textContent = 'No JSON: ' + text; btn.disabled = false; return; }

        if (d.status === 'success') {
            const r = d.data;
            const repairs = Array.isArray(r.repairs) ? r.repairs.length : 0;
            resEl.style.color = r.errors.length > 0 ? '#fd7e14' : '#28a745';
            resEl.textContent = `OK ${r.ok}/${d.total} procesadas correctamente.`
                + (repairs > 0 ? ` ${repairs} auto-reparadas.` : '')
                + (r.errors.length > 0 ? ` ${r.errors.length} errores: ` + r.errors.map(e => `[${e.id}] ${e.error}`).join(' | ') : '');
            // Recargar la pagina para ver estado actualizado
            setTimeout(() => location.reload(), 2000);
        } else {
            resEl.style.color = '#dc3545';
            resEl.textContent = 'Error: ' + d.message;
        }
        btn.disabled = false;
        btn.textContent = '> Re-crear estructuras faltantes';
    } catch(e) {
        resEl.textContent = 'Error JS: ' + e.message;
        btn.disabled = false;
        btn.textContent = '> Re-crear estructuras faltantes';
    }
}
</script>

<?php echo $OUTPUT->footer() ?>


