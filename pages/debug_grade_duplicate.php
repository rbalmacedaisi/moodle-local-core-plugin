<?php
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_admin();

$periodid = optional_param('periodid', 0, PARAM_INT);

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
$classes = $DB->get_records('gmk_class', ['periodid' => $periodid, 'active' => 1], 'id ASC');
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

    // Get grade_items of type 'category' or 'mod' that might be orphaned (created but not linked)
    // Specifically: grade_items for attendance modules in this course that have grade_grades for user 1999
    $orphanItems = [];
    if (!empty($cls->corecourseid)) {
        $orphanItems = $DB->get_records_sql(
            "SELECT gi.id, gi.itemtype, gi.itemmodule, gi.iteminstance, gi.itemname,
                    (SELECT COUNT(*) FROM {grade_grades} gg WHERE gg.itemid = gi.id) as gradecount
             FROM {grade_items} gi
             WHERE gi.courseid = :courseid
               AND gi.itemtype = 'mod'
               AND gi.itemmodule = 'attendance'
               AND gi.iteminstance NOT IN (
                   SELECT cm.instance FROM {course_modules} cm
                   JOIN {modules} m ON m.id = cm.module AND m.name = 'attendance'
                   WHERE cm.id = :attcmid
               )
             ORDER BY gi.id DESC",
            ['courseid' => $cls->corecourseid, 'attcmid' => (int)($cls->attendancemoduleid ?: 0)]
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
        $orphanStr .= "gi.id={$oi->id} grades={$oi->gradecount}<br>";
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

// Also show all grade_categories for all courses in this period (to find orphans)
echo '<hr><h3>grade_items huérfanos para user 1999 (todos los cursos del periodo)</h3>';
$courseIds = array_unique(array_filter(array_column($classes, 'corecourseid')));
if (!empty($courseIds)) {
    $placeholders = implode(',', array_fill(0, count($courseIds), '?'));
    $dupRows = $DB->get_records_sql(
        "SELECT gg.id as ggid, gg.itemid, gg.userid, gi.courseid, gi.itemname, gi.itemmodule, gi.iteminstance,
                gc_cat.fullname as catfullname
         FROM {grade_grades} gg
         JOIN {grade_items} gi ON gi.id = gg.itemid
         LEFT JOIN {grade_categories} gc_cat ON gc_cat.id = gi.categoryid
         WHERE gi.courseid IN ($placeholders)
           AND gg.userid = 1999
           AND gi.itemmodule = 'attendance'
         ORDER BY gi.courseid, gg.itemid",
        $courseIds
    );

    echo '<table border="1" cellpadding="4" style="border-collapse:collapse;font-size:12px">';
    echo '<tr style="background:#eee"><th>grade_grade id</th><th>itemid</th><th>courseid</th><th>itemname</th><th>categoryname</th><th>attendance cm exists?</th></tr>';
    foreach ($dupRows as $r) {
        $cmExists = $DB->record_exists('course_modules', ['instance' => $r->iteminstance, 'course' => $r->courseid]);
        $cmColor = $cmExists ? 'green' : 'red';
        echo "<tr>";
        echo "<td>{$r->ggid}</td>";
        echo "<td>{$r->itemid}</td>";
        echo "<td>{$r->courseid}</td>";
        echo "<td>" . htmlspecialchars($r->itemname) . "</td>";
        echo "<td>" . htmlspecialchars($r->catfullname) . "</td>";
        echo "<td style='color:$cmColor'>" . ($cmExists ? 'SÍ' : 'NO (huérfano)') . "</td>";
        echo "</tr>";
    }
    echo '</table>';
}

echo $OUTPUT->footer();
