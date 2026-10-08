<?php
/**
 * CLI: repara clases presenciales con sesiones de asistencia pero sin modulos BBB
 * vinculados. El flujo normal (create_class_activities / rebuild_class_activities)
 * crea una sala BBB por sesion y las enlaza via gmk_bbb_attendance_relation.
 *
 * En algunas clases (caso: 9845, 2026-V (D) DESARROLLO DE LA PERSONALIDAD (PRESENCIAL) C),
 * el bbbmoduleid quedo en NULL y los registros de la relacion quedaron huerfanos,
 * por lo que el docente no puede iniciar la sesion BBB ("no hay sesion vinculada").
 *
 * Este script:
 *  - Crea una sala BBB por cada attendance_session de la clase usando exactamente
 *    create_big_blue_button_activity() (mismo path que el flujo normal).
 *  - Actualiza cada gmk_bbb_attendance_relation con bbbmoduleid y bbbid.
 *  - Actualiza gmk_class.bbbmoduleids con la lista de cmids creados.
 *  - Invalida caches de curso y dashboard.
 *
 * Uso:
 *   php repair_orphan_bbb_for_class.php <classid> [--apply]
 *
 * Sin --apply hace dry-run (solo muestra lo que haria).
 */

define('CLI_SCRIPT', true);
require '/var/www/html/moodle/config.php';
require_once($CFG->dirroot . '/lib/modinfolib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

// add_moduleinfo exige un usuario con permisos de calendario en el curso.
// En CLI no hay sesion, asi que impersonamos al usuario cron (admin del sistema).
cron_setup_user();

global $DB;

$classid = isset($argv[1]) ? (int)$argv[1] : 0;
$dryrun = empty($argv[2]) || $argv[2] !== '--apply';

if ($classid <= 0) {
    fwrite(STDERR, "Uso: php repair_orphan_bbb_for_class.php <classid> [--apply]\n");
    exit(1);
}

echo $dryrun ? "=== DRY RUN (use --apply para aplicar) ===\n" : "=== APLICANDO CAMBIOS ===\n";
echo "Clase: $classid\n\n";

$class = $DB->get_record('gmk_class', ['id' => $classid], '*', MUST_EXIST);
echo "Nombre: {$class->name}\n";
echo "corecourseid={$class->corecourseid} sectionid={$class->coursesectionid} groupid={$class->groupid}\n";
echo "attendancemoduleid={$class->attendancemoduleid}\n\n";

// Necesario para create_big_blue_button_activity
$class->course = get_course($class->corecourseid);

$BBBmoduleId = (int)gmk_get_module_id_by_name('bigbluebuttonbn');
if ($BBBmoduleId <= 0) {
    fwrite(STDERR, "ERROR: no se encontro el modulo bigbluebuttonbn en {modules}\n");
    exit(2);
}

// Sesiones de asistencia existentes
$sessions = $DB->get_records_sql("
    SELECT id, sessdate, duration
      FROM {attendance_sessions}
     WHERE attendanceid = (SELECT id FROM {attendance} WHERE id IN (
                SELECT instance FROM {course_modules} WHERE id = ?))
       AND groupid = ?
  ORDER BY sessdate ASC
", [$class->attendancemoduleid, $class->groupid]);

if (empty($sessions)) {
    fwrite(STDERR, "ERROR: la clase no tiene sesiones de asistencia\n");
    exit(3);
}

// Resolver attendanceid real
$attcm = $DB->get_record('course_modules', ['id' => $class->attendancemoduleid], 'instance');
$attendanceid = (int)$attcm->instance;
echo "attendanceid real = $attendanceid, sesiones = " . count($sessions) . "\n\n";

$sessions = $DB->get_records_sql(
    "SELECT id, sessdate, duration
       FROM {attendance_sessions}
      WHERE attendanceid = ? AND groupid = ?
   ORDER BY sessdate ASC",
    [$attendanceid, $class->groupid]
);

// Relaciones existentes (todas deberian tener bbbmoduleid NULL)
$rels = $DB->get_records('gmk_bbb_attendance_relation',
    ['classid' => $classid], 'attendancesessionid ASC');
$relBySess = [];
foreach ($rels as $r) {
    $relBySess[(int)$r->attendancesessionid] = $r;
}

$createdCmids = [];
$updatedRels = 0;
$createdRels = 0;

foreach ($sessions as $sess) {
    $sessid = (int)$sess->id;
    $sessdate = (int)$sess->sessdate;
    $duration = (int)($sess->duration ?: $class->classduration);
    $enddate = $sessdate + $duration;

    if (isset($relBySess[$sessid]) && !empty($relBySess[$sessid]->bbbmoduleid)) {
        // Ya tenia BBB vinculado, no tocamos
        echo "  [sess $sessid] ya tiene bbbmoduleid={$relBySess[$sessid]->bbbmoduleid}, OK\n";
        $createdCmids[] = (int)$relBySess[$sessid]->bbbmoduleid;
        continue;
    }

    if ($dryrun) {
        echo "  [sess $sessid] " . date('Y-m-d H:i', $sessdate) . " -> [DRY] crearia BBB cmid=? bbbid=? y actualizaria relacion\n";
        continue;
    }

    try {
        $bbb = create_big_blue_button_activity($class, $sessdate, $enddate, $BBBmoduleId,
            // The 5th argument is the section NUMBER, not its id: passing the id made
            // add_moduleinfo() create an empty section #<id> and pushed the course past maxsections.
            (int)$DB->get_field('course_sections', 'section', ['id' => (int)$class->coursesectionid], MUST_EXIST));
        $cmid = (int)$bbb->coursemodule;
        $bbbid = (int)$bbb->instance;
        $createdCmids[] = $cmid;
        echo "  [sess $sessid] " . date('Y-m-d H:i', $sessdate) . " -> BBB cmid=$cmid bbbid=$bbbid\n";

        if (isset($relBySess[$sessid])) {
            $upd = new stdClass();
            $upd->id = (int)$relBySess[$sessid]->id;
            $upd->bbbmoduleid = $cmid;
            $upd->bbbid = $bbbid;
            $upd->timemodified = time();
            $DB->update_record('gmk_bbb_attendance_relation', $upd);
            $updatedRels++;
        } else {
            $ins = new stdClass();
            $ins->attendancesessionid = $sessid;
            $ins->bbbmoduleid = $cmid;
            $ins->bbbid = $bbbid;
            $ins->classid = $classid;
            $ins->attendancemoduleid = (int)$class->attendancemoduleid;
            $ins->attendanceid = $attendanceid;
            $ins->sectionid = (int)$class->coursesectionid;
            $ins->timecreated = time();
            $ins->timemodified = time();
            $DB->insert_record('gmk_bbb_attendance_relation', $ins);
            $createdRels++;
        }
    } catch (Throwable $e) {
        echo "  [sess $sessid] ERROR: " . $e->getMessage() . "\n";
    }
}

if (!$dryrun && !empty($createdCmids)) {
    // Actualizar gmk_class.bbbmoduleids con la union de lo que ya tenia + lo nuevo
    $existing = array_filter(array_map('trim', explode(',', (string)($class->bbbmoduleids ?? ''))));
    $merged = array_unique(array_merge(array_map('intval', $existing), $createdCmids));
    sort($merged);
    $newList = implode(',', $merged);
    $DB->set_field('gmk_class', 'bbbmoduleids', $newList, ['id' => $classid]);
    echo "\n[ok] gmk_class.bbbmoduleids = '$newList'\n";

    // Invalidar caches
    rebuild_course_cache((int)$class->corecourseid, true);
    if (function_exists('gmk_invalidate_schedule_caches')) {
        gmk_invalidate_schedule_caches($classid);
    }
    echo "[ok] caches invalidados (curso + schedule)\n";
}

echo "\nResumen: createdCmid=" . count($createdCmids)
   . " updatedRels=$updatedRels createdRels=$createdRels\n";
echo $dryrun ? "(DRY RUN - sin cambios en BD)\n" : "(aplicado)\n";
