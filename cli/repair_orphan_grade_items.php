<?php
// CLI: repara grade_items de assigns/quiz/attendance que quedaron en la
// categoria padre del curso en vez de en la sub-categoria de la clase.
//
// Por que existe: el observer course_module_created originalmente confiaba
// en get_fast_modinfo() que durante el evento a veces devuelve el cm sin
// la section resuelta y sale silenciosamente. Como consecuencia, las
// actividades creadas nativamente en Moodle quedan en la categoria raiz
// del curso y rompen la suma de ponderaciones del libro de calificaciones.

define('CLI_SCRIPT', true);
require '/var/www/html/moodle/config.php';
require_once($CFG->dirroot . '/lib/gradelib.php');

global $DB;

$dryrun = !empty($argv[1]) && $argv[1] === '--apply' ? false : true;

echo $dryrun ? "=== DRY RUN (use --apply para ejecutar) ===\n" : "=== APLICANDO CAMBIOS ===\n";

// Clases con sub-categoria propia, no cerradas, initdate > 0
$classes = $DB->get_records_sql("
    SELECT id, name, corecourseid, coursesectionid, gradecategoryid, initdate
      FROM {gmk_class}
     WHERE gradecategoryid > 0
       AND closed = 0
       AND initdate > 0
");

$moved = 0;
$skipped = 0;
$already = 0;

foreach ($classes as $class) {
    if (empty($class->coursesectionid)) {
        continue;
    }
    // course_modules de la seccion de la clase
    $cms = $DB->get_records_sql("
        SELECT cm.id, cm.instance, m.name AS modname
          FROM {course_modules} cm
          JOIN {modules} m ON m.id = cm.module
         WHERE cm.course = :courseid AND cm.section = :sectionid
    ", ['courseid' => (int)$class->corecourseid, 'sectionid' => (int)$class->coursesectionid]);

    foreach ($cms as $cm) {
        if (!in_array($cm->modname, ['assign', 'quiz', 'attendance'], true)) {
            $skipped++;
            continue;
        }
        // Buscar el grade_item correspondiente en TODAS las categorias del curso
        // (no asumimos que esta en la categoria padre - puede estar en cualquier otra)
        $gi = $DB->get_record('grade_items', [
            'courseid' => (int)$class->corecourseid,
            'itemtype' => 'mod',
            'itemmodule' => $cm->modname,
            'iteminstance' => (int)$cm->instance,
        ], 'id, categoryid, itemname');

        if (!$gi) {
            continue;
        }
        if ((int)$gi->categoryid === (int)$class->gradecategoryid) {
            $already++;
            continue;
        }
        $oldcat = (int)$gi->categoryid;
        $newcat = (int)$class->gradecategoryid;
        echo sprintf(
            "  [class %d] grade_item %d \"%s\" cm=%d mod=%s instance=%d: categoryid %d -> %d\n",
            $class->id, $gi->id, $gi->itemname, $cm->id, $cm->modname, $cm->instance, $oldcat, $newcat
        );
        if (!$dryrun) {
            $DB->set_field('grade_items', 'categoryid', $newcat, ['id' => $gi->id]);
        }
        $moved++;
    }
}

echo "\n";
echo "Resumen: movidos=" . $moved . " ya_ok=" . $already . " skipped(no_mod_relevante)=" . $skipped . "\n";
if ($dryrun) {
    echo "(DRY RUN - no se hicieron cambios. Ejecute con --apply para aplicar.)\n";
}
