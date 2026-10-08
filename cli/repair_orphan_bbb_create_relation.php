<?php
/**
 * Variante de repair_orphan_bbb_for_class.php que TAMBIEN crea la fila
 * gmk_bbb_attendance_relation para sesiones que NO la tienen. Usado para
 * clases donde la creacion inicial de la clase fallo y ni siquiera existe
 * la fila de la relacion.
 *
 * Uso:  php repair_orphan_bbb_create_relation.php <classid> [--apply]
 */
define('CLI_SCRIPT', true);
require '/var/www/html/moodle/config.php';
require_once($CFG->dirroot . '/lib/modinfolib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
cron_setup_user();

global $DB;

$classid = isset($argv[1]) ? (int)$argv[1] : 0;
$dryrun = empty($argv[2]) || $argv[2] !== '--apply';

if ($classid <= 0) {
    fwrite(STDERR, "Uso: php repair_orphan_bbb_create_relation.php <classid> [--apply]\n");
    exit(1);
}

echo $dryrun ? "=== DRY RUN ===\n" : "=== APLICANDO ===\n";

$class = $DB->get_record('gmk_class', ['id' => $classid], '*', MUST_EXIST);
echo "Clase $classid: {$class->name}\n";

$class->course = get_course($class->corecourseid);

$BBBmoduleId = (int)gmk_get_module_id_by_name('bigbluebuttonbn');
if ($BBBmoduleId <= 0) {
    fwrite(STDERR, "ERROR: no bigbluebuttonbn module\n");
    exit(2);
}

$attcm = $DB->get_record('course_modules', ['id' => $class->attendancemoduleid], 'instance');
$attendanceid = (int)$attcm->instance;

$sessions = $DB->get_records_sql(
    "SELECT id, sessdate, duration
       FROM {attendance_sessions}
      WHERE attendanceid = ? AND groupid = ?
   ORDER BY sessdate ASC",
    [$attendanceid, $class->groupid]
);

$rels = $DB->get_records('gmk_bbb_attendance_relation',
    ['classid' => $classid], 'attendancesessionid ASC');
$relBySess = [];
foreach ($rels as $r) {
    $relBySess[(int)$r->attendancesessionid] = $r;
}

$createdCmids = [];
$stats = ['already_ok' => 0, 'updated' => 0, 'created_rel' => 0, 'skipped' => 0];

foreach ($sessions as $sess) {
    $sessid = (int)$sess->id;
    $sessdate = (int)$sess->sessdate;
    $duration = (int)($sess->duration ?: $class->classduration);
    $enddate = $sessdate + $duration;

    if (isset($relBySess[$sessid]) && !empty($relBySess[$sessid]->bbbmoduleid)) {
        $stats['already_ok']++;
        $createdCmids[] = (int)$relBySess[$sessid]->bbbmoduleid;
        continue;
    }

    if ($dryrun) {
        echo "  [sess $sessid] " . date('Y-m-d H:i', $sessdate) . " -> [DRY] crearia BBB y/o relacion\n";
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

        if (isset($relBySess[$sessid])) {
            $upd = new stdClass();
            $upd->id = (int)$relBySess[$sessid]->id;
            $upd->bbbmoduleid = $cmid;
            $upd->bbbid = $bbbid;
            $upd->timemodified = time();
            $DB->update_record('gmk_bbb_attendance_relation', $upd);
            $stats['updated']++;
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
            $stats['created_rel']++;
        }
        echo "  [sess $sessid] " . date('Y-m-d H:i', $sessdate) . " -> BBB cmid=$cmid bbbid=$bbbid\n";
    } catch (Throwable $e) {
        $stats['skipped']++;
        echo "  [sess $sessid] " . date('Y-m-d H:i', $sessdate) . " -> ERROR: " . $e->getMessage() . "\n";
    }
}

if (!$dryrun && !empty($createdCmids)) {
    $existing = array_filter(array_map('trim', explode(',', (string)($class->bbbmoduleids ?? ''))));
    $merged = array_values(array_unique(array_merge(array_map('intval', $existing), $createdCmids)));
    sort($merged);
    $newList = implode(',', $merged);
    $DB->set_field('gmk_class', 'bbbmoduleids', $newList, ['id' => $classid]);

    foreach ($createdCmids as $cmid) {
        $current = (int)$DB->get_field('course_modules', 'section', ['id' => $cmid]);
        if ($current !== (int)$class->coursesectionid) {
            gmk_ensure_cmid_in_section_sequence((int)$class->coursesectionid, $cmid);
        }
    }

    rebuild_course_cache((int)$class->corecourseid, true);
    if (function_exists('gmk_invalidate_schedule_caches')) {
        gmk_invalidate_schedule_caches($classid);
    }
    echo "\n[ok] bbbmoduleids = '$newList'\n";
    echo "[ok] cmids movidos a seccion {$class->coursesectionid} + caches invalidados\n";
}

echo "\nResumen: " . json_encode($stats) . "\n";
echo $dryrun ? "(DRY RUN)\n" : "(aplicado)\n";
