<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_admin();

$periodid = optional_param('periodid', 0, PARAM_INT);

$PAGE->set_url('/local/grupomakro_core/pages/debug_grade_duplicate.php', ['periodid' => $periodid]);
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Debug Grade Duplicate');

echo $OUTPUT->header();

// List periods
$periods = $DB->get_records_menu('gmk_academic_periods', [], 'id DESC', 'id, name', 0, 20);
echo '<form method="get"><select name="periodid">';
echo '<option value="">-- Selecciona periodo --</option>';
foreach ($periods as $pid => $pname) {
    $sel = ($pid == $periodid) ? 'selected' : '';
    echo "<option value=\"$pid\" $sel>$pname</option>";
}
echo '</select> <button type="submit">Ver</button></form><hr>';

if (!$periodid) {
    echo $OUTPUT->footer();
    exit;
}

// Get all classes for the period
$classes = $DB->get_records('gmk_class', ['periodid' => $periodid], 'id ASC');
echo '<h3>Clases del periodo ' . $periodid . ' (' . count($classes) . ' clases)</h3>';

echo '<table border="1" cellpadding="4" style="border-collapse:collapse;font-size:12px;width:100%">';
echo '<tr style="background:#eee">
    <th>ID</th>
    <th>Nombre</th>
    <th>corecourseid</th>
    <th>groupid</th>
    <th>coursesectionid</th>
    <th>attendancemoduleid</th>
    <th>gradecategoryid</th>
    <th>grade_categories en DB</th>
    <th>grade_items huérfanos</th>
    <th>grade_grades duplicados</th>
    <th>Diagnóstico</th>
</tr>';

foreach ($classes as $cls) {
    $diag = [];

    // Check grade_categories by id
    $catById = null;
    if (!empty($cls->gradecategoryid)) {
        $catById = $DB->get_record('grade_categories', ['id' => $cls->gradecategoryid, 'courseid' => $cls->corecourseid], 'id, fullname');
    }

    // Search grade_categories by suffix pattern (our new logic)
    $catBySuffix = null;
    if (!empty($cls->corecourseid)) {
        $catBySuffix = $DB->get_record_sql(
            "SELECT id, fullname FROM {grade_categories} WHERE courseid = :courseid AND " . $DB->sql_like('fullname', ':suffix'),
            ['courseid' => $cls->corecourseid, 'suffix' => '%-' . $cls->id . ' grade category']
        );
    }

    // Search ALL grade_categories for this course that mention this classid
    $allCatsForClass = $DB->get_records_sql(
        "SELECT id, fullname FROM {grade_categories} WHERE courseid = :courseid AND " . $DB->sql_like('fullname', ':pat'),
        ['courseid' => $cls->corecourseid, 'pat' => '%' . $cls->id . '%']
    );

    // Get all attendance grade_items for this course with their grade_grade counts
    $orphanItems = [];
    if (!empty($cls->corecourseid)) {
        $orphanItems = $DB->get_records_sql(
            "SELECT gi.id, gi.itemmodule, gi.iteminstance, gi.itemname,
                    (SELECT COUNT(*) FROM {grade_grades} gg WHERE gg.itemid = gi.id) as gradecount,
                    (SELECT COUNT(*) FROM {course_modules} cm JOIN {modules} m ON m.id = cm.module AND m.name = 'attendance' WHERE cm.instance = gi.iteminstance AND cm.course = gi.courseid) as cm_exists
             FROM {grade_items} gi
             WHERE gi.courseid = :courseid
               AND gi.itemmodule = 'attendance'
             ORDER BY gi.id DESC",
            ['courseid' => $cls->corecourseid]
        );
    }

    // Check duplicate grade_grades: items with id in range 762-800 that have grades for user 1999
    $dupGrades = $DB->get_records_sql(
        "SELECT gg.id, gg.itemid, gg.userid, gi.itemname
         FROM {grade_grades} gg
         JOIN {grade_items} gi ON gi.id = gg.itemid
         WHERE gi.courseid = :courseid
           AND gg.userid = 1999
           AND gi.itemmodule = 'attendance'
         ORDER BY gg.itemid",
        ['courseid' => $cls->corecourseid]
    );

    if (empty($cls->gradecategoryid)) $diag[] = '<span style="color:red">gradecategoryid=0</span>';
    if (!$catById && !empty($cls->gradecategoryid)) $diag[] = '<span style="color:red">gradecategoryid en DB no existe</span>';
    if (!$catBySuffix && empty($cls->gradecategoryid)) $diag[] = '<span style="color:orange">No hay cat por sufijo</span>';
    if ($catBySuffix && empty($cls->gradecategoryid)) $diag[] = '<span style="color:green">Cat encontrada por sufijo: id=' . $catBySuffix->id . '</span>';
    if (empty($cls->corecourseid)) $diag[] = '<span style="color:red">corecourseid=0</span>';
    if (empty($cls->groupid)) $diag[] = '<span style="color:orange">groupid=0</span>';
    if (empty($cls->attendancemoduleid)) $diag[] = '<span style="color:orange">attendancemoduleid=0</span>';

    $allCatsStr = '';
    foreach ($allCatsForClass as $c) {
        $allCatsStr .= "id={$c->id}: " . htmlspecialchars(substr($c->fullname, 0, 60)) . "<br>";
    }

    $orphanStr = '';
    foreach ($orphanItems as $oi) {
        $color = $oi->cm_exists ? 'green' : 'red';
        $orphanStr .= "<span style='color:$color'>gi.id={$oi->id} grades={$oi->gradecount} cm=" . ($oi->cm_exists ? 'OK' : 'ORPHAN') . "</span><br>";
    }

    $dupStr = '';
    foreach ($dupGrades as $dg) {
        $dupStr .= "itemid={$dg->itemid}<br>";
    }

    $color = (!empty($diag)) ? '#fff3cd' : '#d4edda';
    echo "<tr style='background:$color'>";
    echo "<td>{$cls->id}</td>";
    echo "<td>" . htmlspecialchars(substr($cls->name, 0, 50)) . "</td>";
    echo "<td>{$cls->corecourseid}</td>";
    echo "<td>{$cls->groupid}</td>";
    echo "<td>{$cls->coursesectionid}</td>";
    echo "<td>{$cls->attendancemoduleid}</td>";
    echo "<td>{$cls->gradecategoryid}</td>";
    echo "<td>$allCatsStr</td>";
    echo "<td>$orphanStr</td>";
    echo "<td>$dupStr</td>";
    echo "<td>" . implode('<br>', $diag) . "</td>";
    echo "</tr>";
}

echo '</table>';

// Direct item lookup — investigar itemid específico
$lookupItemId = optional_param('itemid', 0, PARAM_INT);
$lookupUserId = optional_param('userid', 0, PARAM_INT);
echo '<hr><h3>Investigar grade_item específico</h3>';
echo '<form method="get">
    <input type="hidden" name="periodid" value="' . $periodid . '">
    itemid: <input type="number" name="itemid" value="' . $lookupItemId . '" style="width:80px">
    userid: <input type="number" name="userid" value="' . $lookupUserId . '" style="width:80px">
    <button type="submit">Buscar</button>
</form>';

if ($lookupItemId) {
    $gi = $DB->get_record('grade_items', ['id' => $lookupItemId]);
    if ($gi) {
        echo '<pre style="font-size:11px;background:#f5f5f5;padding:8px">';
        echo "grade_item id=$lookupItemId:\n";
        echo "  itemtype={$gi->itemtype}  itemmodule={$gi->itemmodule}  iteminstance={$gi->iteminstance}\n";
        echo "  courseid={$gi->courseid}  categoryid={$gi->categoryid}  itemname=" . htmlspecialchars($gi->itemname) . "\n";
        // Find category
        if ($gi->categoryid) {
            $cat = $DB->get_record('grade_categories', ['id' => $gi->categoryid]);
            echo "  category=" . htmlspecialchars($cat ? $cat->fullname : 'NOT FOUND') . "\n";
        }
        // Find course_module if attendance
        if ($gi->itemmodule === 'attendance') {
            $cm = $DB->get_record('course_modules', ['instance' => $gi->iteminstance, 'course' => $gi->courseid]);
            echo "  course_module=" . ($cm ? "id={$cm->id}" : "NOT FOUND (orphan!)") . "\n";
        }
        // Check grade_grades for this item
        $ggs = $DB->get_records('grade_grades', ['itemid' => $lookupItemId], '', 'id, userid, finalgrade, rawgrade');
        echo "  grade_grades count=" . count($ggs) . "\n";
        if ($lookupUserId) {
            $gg = $DB->get_record('grade_grades', ['itemid' => $lookupItemId, 'userid' => $lookupUserId]);
            echo "  grade_grade for userid=$lookupUserId: " . ($gg ? "EXISTS (id={$gg->id})" : "NOT FOUND") . "\n";
        }
        echo '</pre>';
    } else {
        echo "<p style='color:red'>grade_item id=$lookupItemId no existe en la BD.</p>";
    }
}

// All grade_grades for all users in courses of this period — find duplicates
echo '<hr><h3>Todos los grade_grades para cursos del periodo (busca duplicados)</h3>';
$courseIds = array_unique(array_filter(array_column((array)$classes, 'corecourseid')));
if (!empty($courseIds)) {
    list($insql, $inparams) = $DB->get_in_or_equal($courseIds);
    // Find (itemid, userid) pairs that appear more than once
    $dupPairs = $DB->get_records_sql(
        "SELECT gg.itemid, gg.userid, COUNT(*) as cnt, gi.itemtype, gi.itemmodule, gi.itemname, gi.courseid
         FROM {grade_grades} gg
         JOIN {grade_items} gi ON gi.id = gg.itemid
         WHERE gi.courseid $insql
         GROUP BY gg.itemid, gg.userid
         HAVING COUNT(*) > 1
         ORDER BY cnt DESC",
        $inparams
    );
    if (empty($dupPairs)) {
        echo '<p style="color:green">No se encontraron duplicados en grade_grades.</p>';
    } else {
        echo '<table border="1" cellpadding="4" style="border-collapse:collapse;font-size:12px">';
        echo '<tr style="background:#f66;color:white"><th>itemid</th><th>userid</th><th>count</th><th>itemtype</th><th>itemmodule</th><th>itemname</th><th>courseid</th></tr>';
        foreach ($dupPairs as $dp) {
            echo "<tr><td>{$dp->itemid}</td><td>{$dp->userid}</td><td style='color:red;font-weight:bold'>{$dp->cnt}</td>";
            echo "<td>{$dp->itemtype}</td><td>{$dp->itemmodule}</td>";
            echo "<td>" . htmlspecialchars(substr($dp->itemname, 0, 60)) . "</td>";
            echo "<td>{$dp->courseid}</td></tr>";
        }
        echo '</table>';
    }

    // Show all grade_items of type 'category' in these courses that might be orphaned
    echo '<hr><h3>grade_items tipo category en cursos del periodo</h3>';
    $catItems = $DB->get_records_sql(
        "SELECT gi.id, gi.itemtype, gi.iteminstance, gi.courseid, gi.itemname,
                gc.fullname as catname,
                (SELECT COUNT(*) FROM {grade_grades} gg WHERE gg.itemid = gi.id) as gradecount
         FROM {grade_items} gi
         LEFT JOIN {grade_categories} gc ON gc.id = gi.iteminstance
         WHERE gi.courseid $insql AND gi.itemtype = 'category'
         ORDER BY gi.courseid, gi.id",
        $inparams
    );
    echo '<table border="1" cellpadding="4" style="border-collapse:collapse;font-size:12px">';
    echo '<tr style="background:#eee"><th>gi.id</th><th>courseid</th><th>iteminstance(catid)</th><th>catname</th><th>grade_grades</th></tr>';
    foreach ($catItems as $ci) {
        $catExists = $DB->record_exists('grade_categories', ['id' => $ci->iteminstance]);
        $bg = $catExists ? '' : 'background:#fcc';
        echo "<tr style='$bg'>";
        echo "<td>{$ci->id}</td><td>{$ci->courseid}</td><td>{$ci->iteminstance}</td>";
        echo "<td>" . htmlspecialchars(substr($ci->catname ?? 'NOT FOUND', 0, 70)) . "</td>";
        echo "<td>{$ci->gradecount}</td></tr>";
    }
    echo '</table>';
}

echo $OUTPUT->footer();
