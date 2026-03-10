<?php
$config_path = __DIR__ . '/../../config.php';
if (!file_exists($config_path)) $config_path = __DIR__ . '/../../../config.php';
if (!file_exists($config_path)) $config_path = __DIR__ . '/../../../../config.php';
require_once($config_path);
require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url('/local/grupomakro_core/pages/debug_gradebook.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Debug Gradebook');
echo $OUTPUT->header();

function dbg_table($rows, $headers) {
    echo '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse;font-family:monospace;font-size:12px;margin-bottom:16px;">';
    echo '<tr style="background:#1a73e8;color:white;">';
    foreach ($headers as $h) echo "<th>$h</th>";
    echo '</tr>';
    foreach ($rows as $r) {
        echo '<tr>';
        foreach ($r as $v) echo '<td>' . htmlspecialchars((string)$v) . '</td>';
        echo '</tr>';
    }
    echo '</table>';
}

function dbg_section($title) {
    echo "<h3 style='margin-top:24px;border-bottom:2px solid #1a73e8;padding-bottom:4px;'>$title</h3>";
}

// ── Search form ───────────────────────────────────────────────────────────────
$search   = optional_param('search', '', PARAM_TEXT);
$courseId = optional_param('courseid', 0, PARAM_INT);
$userId   = optional_param('userid', 0, PARAM_INT);

echo '<h2>Debug Gradebook</h2>';
echo '<form method="get" style="margin-bottom:16px;font-family:sans-serif;">';
echo '<b>Buscar estudiante:</b> <input name="search" value="' . s($search) . '" placeholder="Nombre o email" style="width:260px;padding:4px;"> ';
echo '<input type="submit" value="Buscar" style="padding:4px 12px;"> ';
if ($userId) echo '<input type="hidden" name="userid" value="' . (int)$userId . '">';
if ($courseId) echo '<input type="hidden" name="courseid" value="' . (int)$courseId . '">';
echo '</form>';

// Student search results
if ($search && !$userId) {
    $users = $DB->get_records_sql(
        "SELECT id, firstname, lastname, email FROM {user}
         WHERE deleted = 0 AND (". $DB->sql_like('email', ':s1', false) ." OR ". $DB->sql_like($DB->sql_concat('firstname',"' '",'lastname'), ':s2', false) .")
         ORDER BY lastname LIMIT 20",
        ['s1' => '%' . $search . '%', 's2' => '%' . $search . '%']
    );
    if ($users) {
        echo '<b>Selecciona el estudiante:</b><ul>';
        foreach ($users as $u) {
            $url = new moodle_url('/local/grupomakro_core/pages/debug_gradebook.php', ['search' => $search, 'userid' => $u->id]);
            echo '<li><a href="' . $url . '">' . fullname($u) . ' — ' . $u->email . '</a></li>';
        }
        echo '</ul>';
    } else {
        echo '<p style="color:red;">No se encontraron usuarios.</p>';
    }
}

// Course selection
if ($userId && !$courseId) {
    $user = $DB->get_record('user', ['id' => $userId]);
    echo '<p><b>Estudiante:</b> ' . fullname($user) . ' (id=' . $userId . ')</p>';
    $enrolments = $DB->get_records_sql(
        "SELECT DISTINCT c.id, c.fullname FROM {course} c
         JOIN {enrol} e ON e.courseid = c.id
         JOIN {user_enrolments} ue ON ue.enrolid = e.id
         WHERE ue.userid = :uid AND c.id != 1 ORDER BY c.fullname",
        ['uid' => $userId]
    );
    if ($enrolments) {
        echo '<b>Selecciona el curso:</b><ul>';
        foreach ($enrolments as $c) {
            $url = new moodle_url('/local/grupomakro_core/pages/debug_gradebook.php', ['search' => $search, 'userid' => $userId, 'courseid' => $c->id]);
            echo '<li><a href="' . $url . '">' . htmlspecialchars($c->fullname) . ' (id=' . $c->id . ')</a></li>';
        }
        echo '</ul>';
    } else {
        echo '<p style="color:red;">Sin matrículas.</p>';
    }
}

// ── Main debug ────────────────────────────────────────────────────────────────
if ($userId && $courseId) {
    $user   = $DB->get_record('user', ['id' => $userId]);
    $course = $DB->get_record('course', ['id' => $courseId]);
    echo '<p><b>Estudiante:</b> ' . fullname($user) . ' (id=' . $userId . ') &nbsp;|&nbsp; <b>Curso:</b> ' . htmlspecialchars($course->fullname) . ' (id=' . $courseId . ')</p>';

    // ── STEP 1: Groups ────────────────────────────────────────────────────────
    dbg_section('PASO 1 — Grupos del estudiante en el curso');
    $userGroups = $DB->get_records_sql(
        'SELECT gm.groupid, g.name FROM {groups_members} gm
         JOIN {groups} g ON g.id = gm.groupid
         WHERE gm.userid = :uid AND g.courseid = :cid',
        ['uid' => $userId, 'cid' => $courseId]
    );
    $groupIds = array_column($userGroups, 'groupid');
    if ($userGroups) {
        dbg_table(array_map(fn($r) => [(int)$r->groupid, $r->name], $userGroups), ['groupid', 'name']);
    } else {
        echo '<p style="color:orange;">⚠️ El estudiante NO está en ningún grupo de este curso.</p>';
    }

    // ── STEP 2: All class category ids in course ──────────────────────────────
    dbg_section('PASO 2 — Todas las clases (gmk_class) del curso y sus gradecategoryid');
    $allClasses = $DB->get_records('gmk_class', ['corecourseid' => $courseId], '', 'id,groupid,gradecategoryid,attendancemoduleid');
    $allClassCategoryIds = [];
    if ($allClasses) {
        $rows = [];
        foreach ($allClasses as $c) {
            $groupName = $DB->get_field('groups', 'name', ['id' => $c->groupid]) ?: '-';
            $catName   = $c->gradecategoryid ? ($DB->get_field('grade_categories', 'fullname', ['id' => $c->gradecategoryid]) ?: '?') : '-';
            $rows[] = [$c->id, $c->groupid, $groupName, $c->gradecategoryid ?: '-', $catName, $c->attendancemoduleid ?: '-'];
            if ($c->gradecategoryid) $allClassCategoryIds[] = (int)$c->gradecategoryid;
        }
        dbg_table($rows, ['class id', 'groupid', 'group name', 'gradecategoryid', 'category name', 'attendancemoduleid']);
    } else {
        echo '<p style="color:orange;">⚠️ No hay clases en gmk_class para este curso.</p>';
    }
    echo '<p><b>allClassCategoryIds:</b> [' . implode(', ', $allClassCategoryIds) . ']</p>';

    // ── STEP 3: Student's classes ─────────────────────────────────────────────
    dbg_section('PASO 3 — Clases del estudiante (por grupo)');
    $studentCategoryIds  = [];
    $attendanceModuleIds = [];
    if (!empty($groupIds)) {
        list($inSql, $inParams) = $DB->get_in_or_equal($groupIds);
        $classes = $DB->get_records_sql(
            "SELECT id, groupid, attendancemoduleid, gradecategoryid FROM {gmk_class}
             WHERE groupid $inSql AND corecourseid = :cid",
            array_merge($inParams, ['cid' => $courseId])
        );
        if ($classes) {
            $rows = [];
            foreach ($classes as $c) {
                $groupName = $DB->get_field('groups', 'name', ['id' => $c->groupid]) ?: '-';
                $catName   = $c->gradecategoryid ? ($DB->get_field('grade_categories', 'fullname', ['id' => $c->gradecategoryid]) ?: '?') : '-';
                $rows[] = [$c->id, $c->groupid, $groupName, $c->gradecategoryid ?: '-', $catName, $c->attendancemoduleid ?: '-'];
                if ($c->gradecategoryid) $studentCategoryIds[] = (int)$c->gradecategoryid;
                if ($c->attendancemoduleid) $attendanceModuleIds[] = (int)$c->attendancemoduleid;
            }
            dbg_table($rows, ['class id', 'groupid', 'group name', 'gradecategoryid', 'category name', 'attendancemoduleid']);
        } else {
            echo '<p style="color:orange;">⚠️ No hay clases en gmk_class para los grupos del estudiante.</p>';
        }
    } else {
        echo '<p style="color:gray;">Sin grupos → studentCategoryIds = []</p>';
    }
    echo '<p><b>studentCategoryIds:</b> [' . implode(', ', $studentCategoryIds) . ']</p>';
    echo '<p><b>attendanceModuleIds:</b> [' . implode(', ', $attendanceModuleIds) . ']</p>';

    // ── STEP 4: All grade items with filter trace ─────────────────────────────
    dbg_section('PASO 4 — Grade items del curso con resultado del filtro');
    $gradeItems = $DB->get_records_sql(
        "SELECT gi.id, gi.categoryid, gi.itemname, gi.itemtype, gi.itemmodule,
                gi.iteminstance, gi.grademax, gi.sortorder,
                gg.finalgrade
         FROM {grade_items} gi
         LEFT JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = :uid
         WHERE gi.courseid = :cid AND gi.itemtype != 'course'
         ORDER BY gi.sortorder ASC",
        ['uid' => $userId, 'cid' => $courseId]
    );

    $rows = [];
    foreach ($gradeItems as $gi) {
        $itemCatId = (int)$gi->categoryid;
        $catName   = $DB->get_field('grade_categories', 'fullname', ['id' => $itemCatId]) ?: '?';

        // Apply filter logic
        $result = '✅ MOSTRAR';
        $reason = '';
        if (!empty($allClassCategoryIds)) {
            $belongsToAClass = in_array($itemCatId, $allClassCategoryIds);
            if ($belongsToAClass && !in_array($itemCatId, $studentCategoryIds)) {
                $result = '❌ FILTRAR';
                $reason = 'cat de otro grupo';
            }
            if ($result === '✅ MOSTRAR' && $gi->itemtype === 'category') {
                $representsCatId = (int)$gi->iteminstance;
                $representsAClass = in_array($representsCatId, $allClassCategoryIds);
                if ($representsAClass && !in_array($representsCatId, $studentCategoryIds)) {
                    $result = '❌ FILTRAR';
                    $reason = 'total de cat de otro grupo (iteminstance=' . $representsCatId . ')';
                }
            }
        }
        if ($result === '✅ MOSTRAR' && $gi->itemmodule === 'bigbluebuttonbn') {
            $result = '❌ FILTRAR'; $reason = 'BBB';
        }
        if ($result === '✅ MOSTRAR' && $gi->itemtype === 'category' && is_null($gi->finalgrade)) {
            $result = '❌ FILTRAR'; $reason = 'category total sin nota';
        }

        $rows[] = [
            $gi->id,
            $itemCatId,
            $catName,
            $gi->itemtype,
            $gi->itemmodule ?: '-',
            $gi->itemname ?: '-',
            is_null($gi->finalgrade) ? 'NULL' : round($gi->finalgrade, 2),
            $result,
            $reason,
        ];
    }
    dbg_table($rows, ['item id', 'categoryid', 'category name', 'itemtype', 'itemmodule', 'itemname', 'finalgrade', 'resultado', 'razón']);

    // ── STEP 5: All grade categories in course ────────────────────────────────
    dbg_section('PASO 5 — Todas las grade_categories del curso');
    $cats = $DB->get_records('grade_categories', ['courseid' => $courseId], 'id ASC');
    $rows = [];
    foreach ($cats as $cat) {
        $isClassCat = in_array((int)$cat->id, $allClassCategoryIds) ? '✅ sí' : 'no';
        $isStudentCat = in_array((int)$cat->id, $studentCategoryIds) ? '✅ sí' : 'no';
        $rows[] = [$cat->id, $cat->fullname, $cat->parent, $isClassCat, $isStudentCat];
    }
    dbg_table($rows, ['id', 'fullname', 'parent', '¿de algún grupo?', '¿del estudiante?']);
}

echo $OUTPUT->footer();
