<?php
/**
 * add_sessions_to_class.php
 *
 * Adds N extra sessions to an existing gmk_class, on the same weekday(s) as the
 * current schedule, extending the class enddate accordingly. Strictly additive:
 * never deletes or overwrites existing rows.
 *
 * USO:
 *   php add_sessions_to_class.php --classid=XXXX --apply
 *   php add_sessions_to_class.php --classid=XXXX                 (dry-run)
 *
 * Parametros opcionales:
 *   --start-from=YYYY-MM-DD    Forzar fecha de inicio de las nuevas sesiones
 *                              (default: dia siguiente al ultimo attendance_sessions)
 *   --count=7                  Numero de sesiones por dia de la semana (default: 7)
 *   --start-time=17:45         Hora de inicio en TZ Panama (default: 17:45)
 *   --end-time=19:45           Hora de fin en TZ Panama (default: 19:45)
 *   --backup-dir=/path         Donde guardar el JSON pre-cambio (default: /tmp)
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

date_default_timezone_set('America/Panama');
cron_setup_user();

/* ============================================================
   1) Parseo de argumentos
   ============================================================ */
$argv = $_SERVER['argv'];
$apply = in_array('--apply', $argv);
$classid = null;
$count = 7;
$startTime = '17:45';
$endTime   = '19:45';
$startFrom = null;
$backupDir = '/tmp';

foreach ($argv as $a) {
    if (preg_match('/^--classid=(\d+)$/', $a, $m))    $classid = (int)$m[1];
    if (preg_match('/^--count=(\d+)$/', $a, $m))      $count   = (int)$m[1];
    if (preg_match('/^--start-time=(\d{2}:\d{2})$/', $a, $m)) $startTime = $m[1];
    if (preg_match('/^--end-time=(\d{2}:\d{2})$/', $a, $m))   $endTime   = $m[1];
    if (preg_match('/^--start-from=(\d{4}-\d{2}-\d{2})$/', $a, $m)) $startFrom = $m[1];
    if (preg_match('/^--backup-dir=(.+)$/', $a, $m))   $backupDir = $m[1];
}

if (!$classid) {
    fwrite(STDERR, "ERROR: --classid requerido\n"); exit(2);
}
if ($count < 1 || $count > 50) {
    fwrite(STDERR, "ERROR: --count fuera de rango (1..50)\n"); exit(2);
}

/* ============================================================
   2) Cargar clase y validar estado
   ============================================================ */
$class = $DB->get_record('gmk_class', ['id' => $classid], '*', MUST_EXIST);
$class->course = get_course($class->corecourseid);
$class->courseid = $class->corecourseid;

if (empty($class->attendancemoduleid) || empty($class->coursesectionid) || empty($class->groupid)) {
    fwrite(STDERR, "ERROR: clase {$classid} sin attendance/section/group\n"); exit(3);
}

$attendanceCm = get_coursemodule_from_id('attendance', $class->attendancemoduleid, 0, false, MUST_EXIST);
$attendanceRecord = $DB->get_record('attendance', ['id' => $attendanceCm->instance], '*', MUST_EXIST);

$BBBmoduleId = $DB->get_field('modules', 'id', ['name' => 'bigbluebuttonbn']);

/* ============================================================
   3) Determinar dias de la semana de la clase actual
   ============================================================ */
$schedules = $DB->get_records('gmk_class_schedules', ['classid' => $classid]);

$isoToMoodle = [1=>'Lun',2=>'Mar',3=>'Mie',4=>'Jue',5=>'Vie',6=>'Sab',7=>'Dom'];
$dayTextToIso = [
    'lunes' => 1, 'martes' => 2, 'miercoles' => 3, 'jueves' => 4, 'viernes' => 5, 'sabado' => 6, 'domingo' => 7,
    'miércoles' => 3, 'sábado' => 6,
    'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7,
    'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6, 'sunday' => 7,
];
$weekdaysIso = [];
$scheduleRows = [];

if (!empty($schedules)) {
    foreach ($schedules as $s) {
        $dayKey = strtolower(trim((string)$s->day));
        $iso = null;
        if (is_numeric($s->day) && (int)$s->day >= 1 && (int)$s->day <= 7) {
            $iso = (int)$s->day;
        } elseif (isset($dayTextToIso[$dayKey])) {
            $iso = $dayTextToIso[$dayKey];
        } else {
            $dayNoAccents = strtr($dayKey, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u']);
            if (isset($dayTextToIso[$dayNoAccents])) {
                $iso = $dayTextToIso[$dayNoAccents];
            }
        }
        if ($iso !== null) {
            $weekdaysIso[$iso] = true;
            $s->day = $iso;
            $scheduleRows[] = $s;
        } else {
            fwrite(STDERR, "WARN: schedule {$s->id} con day='{$s->day}' no reconocido\n");
        }
    }
} else {
    $bitToIso = [0=>1, 1=>2, 2=>3, 3=>4, 4=>5, 5=>6, 6=>7];
    $classdays = (int)$class->classdays;
    for ($b = 0; $b < 7; $b++) {
        if ($classdays & (1 << $b)) $weekdaysIso[$bitToIso[$b]] = true;
    }
}
$weekdaysIso = array_keys($weekdaysIso);
sort($weekdaysIso);

if (empty($weekdaysIso)) {
    fwrite(STDERR, "ERROR: no se pudo determinar el dia de la semana de la clase\n"); exit(3);
}

/* ============================================================
   4) Detectar la ultima fecha con sesion existente
   ============================================================ */
$existingSessions = $DB->get_records(
    'attendance_sessions',
    ['attendanceid' => $attendanceRecord->id, 'groupid' => $class->groupid],
    'sessdate DESC',
    'id, sessdate, duration'
);
$lastExistingTs = 0;
foreach ($existingSessions as $s) {
    if ((int)$s->sessdate > $lastExistingTs) $lastExistingTs = (int)$s->sessdate;
}

if ($startFrom) {
    $firstNewDate = $startFrom;
} else {
    $firstNewDate = $lastExistingTs
        ? date('Y-m-d', strtotime('+1 day', $lastExistingTs))
        : date('Y-m-d');
}

list($hh, $mm) = explode(':', $startTime);
list($ehh, $emm) = explode(':', $endTime);

/* ============================================================
   5) Generar fechas candidatas: count fechas por cada dia de la semana
   ============================================================ */
$candidates = [];
foreach ($weekdaysIso as $iso) {
    $cursorTs = strtotime($firstNewDate . ' ' . sprintf('%02d:%02d', $hh, $mm) . ' America/Panama');
    while ((int)date('N', $cursorTs) !== $iso) {
        $cursorTs = strtotime('+1 day', $cursorTs);
    }
    for ($i = 0; $i < $count; $i++) {
        $candidates[] = [
            'ts'   => $cursorTs,
            'date' => date('Y-m-d', $cursorTs),
            'iso'  => $iso,
        ];
        $cursorTs = strtotime('+7 days', $cursorTs);
    }
}
usort($candidates, fn($a, $b) => $a['ts'] <=> $b['ts']);

$newEnddateTs = end($candidates)['ts'];

/* ============================================================
   6) Anti-duplicado: descartar fechas que YA tienen session
   ============================================================ */
$attendanceStructure = new \mod_attendance_structure($attendanceRecord, $attendanceCm, $class->course);
$toCreate = [];
$skippedDup = [];
foreach ($candidates as $c) {
    $exists = $DB->record_exists_sql(
        "SELECT 1 FROM {attendance_sessions}
          WHERE attendanceid = ? AND groupid = ? AND sessdate = ?",
        [$attendanceRecord->id, $class->groupid, $c['ts']]
    );
    if ($exists) {
        $skippedDup[] = $c['date'];
    } else {
        $toCreate[] = $c;
    }
}

/* ============================================================
   7) Backup pre-cambio
   ============================================================ */
$tsStr = date('Ymd_His');
$backupPath = rtrim($backupDir, '/') . "/class_{$classid}_backup_{$tsStr}.json";
$backup = [
    'class'             => $class,
    'attendance'        => $attendanceRecord,
    'attendancemodule'  => $attendanceCm,
    'sessions_existing' => array_map(fn($s) => ['id' => $s->id, 'sessdate' => $s->sessdate, 'duration' => $s->duration], $existingSessions),
    'schedules'         => $scheduleRows,
    'candidates'        => $candidates,
    'skippedDup'        => $skippedDup,
    'toCreate'          => $toCreate,
];
file_put_contents($backupPath, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

/* ============================================================
   8) Reporte dry-run
   ============================================================ */
echo "=================================================\n";
echo " CLASE: {$class->name} (id={$classid})\n";
echo " Tipo: {$class->type} | enddate actual: " . date('Y-m-d', (int)$class->enddate) . " (TZ Panama)\n";
echo " Dias de la semana actuales: " . implode(',', array_map(fn($i) => $isoToMoodle[$i], $weekdaysIso)) . "\n";
echo " Ultima sesion existente:    " . ($lastExistingTs ? date('Y-m-d H:i', $lastExistingTs) : '(ninguna)') . "\n";
echo " Primera sesion nueva:       " . (!empty($toCreate) ? date('Y-m-d H:i', $toCreate[0]['ts']) : '-') . "\n";
echo " Ultima sesion nueva:        " . (!empty($toCreate) ? date('Y-m-d H:i', end($toCreate)['ts']) : '-') . "\n";
echo " Total candidatas:           " . count($candidates) . "\n";
echo " Saltadas (duplicadas):      " . count($skippedDup) . "\n";
echo " A crear:                    " . count($toCreate) . "\n";
echo " Horario (TZ Panama):        {$startTime} - {$endTime}\n";
echo " Backup:                     {$backupPath}\n";
echo " Modo:                       " . ($apply ? 'APLICAR' : 'DRY-RUN (no se modifica nada)') . "\n";
echo "=================================================\n";

if (empty($toCreate)) {
    echo "Nada que crear. Saliendo.\n"; exit(0);
}

foreach ($toCreate as $i => $c) {
    echo sprintf(
        "  [%02d] %s  %s  %s-%s\n",
        $i + 1,
        $c['date'],
        $isoToMoodle[$c['iso']],
        $startTime,
        $endTime
    );
}

if (!$apply) {
    echo "\nDRY-RUN: nada modificado. Agregar --apply para ejecutar.\n";
    exit(0);
}

/* ============================================================
   9) EJECUCION: solo operaciones ADITIVAS
   ============================================================ */
$created = [];
foreach ($toCreate as $c) {
    $startTS = $c['ts'];
    $endTS   = strtotime(date('Y-m-d', $c['ts']) . ' ' . $endTime . ' America/Panama');

    // (a) Crear BBB
    $bbbInfo = create_big_blue_button_activity($class, $startTS, $endTS, $BBBmoduleId, $class->coursesectionid);

    // (b) Construir attendance_session_object
    $sessionObj = create_attendance_session_object($class, $startTS, $endTS - $startTS, $bbbInfo);

    // (c) Insertar sesion via API de attendance
    $sessId = $attendanceStructure->add_session($sessionObj);

    // (d) Insertar relacion BBB<->asistencia
    $rel = new stdClass();
    $rel->attendancesessionid = $sessId;
    $rel->bbbmoduleid         = $bbbInfo->coursemodule;
    $rel->bbbid               = $bbbInfo->instance;
    $rel->classid             = $class->id;
    $rel->attendancemoduleid  = $attendanceCm->id;
    $rel->attendanceid        = $attendanceRecord->id;
    $rel->sectionid           = $class->coursesectionid;
    $rel->usermodified        = $USER->id ?? 0;
    $rel->timecreated         = time();
    $rel->timemodified        = time();
    $rel->id = $DB->insert_record('gmk_bbb_attendance_relation', $rel);

    $created[] = [
        'date'      => $c['date'],
        'iso'       => $c['iso'],
        'sessionid' => $sessId,
        'bbbcmid'   => $bbbInfo->coursemodule,
        'bbbid'     => $bbbInfo->instance,
        'relid'     => $rel->id,
    ];

    echo sprintf("  CREADO  %s  sessid=%d  bbbcmid=%d  relid=%d\n", $c['date'], $sessId, $bbbInfo->coursemodule, $rel->id);
}

/* ============================================================
   10) ACTUALIZACIONES DE CONTROL (no destructivas)
   ============================================================ */

// (a) gmk_class.bbbmoduleids: APPEND al CSV existente
$existingCsv = $class->bbbmoduleids;
$newIds = array_column($created, 'bbbcmid');
$merged = $existingCsv
    ? array_merge(array_filter(explode(',', $existingCsv)), $newIds)
    : $newIds;
$merged = array_values(array_unique(array_map('intval', $merged)));
$class->bbbmoduleids = implode(',', $merged);

// (b) gmk_class.enddate: extender al maximo
if ((int)$newEnddateTs > (int)$class->enddate) {
    $class->enddate = $newEnddateTs;
}

// (c) gmk_class_schedules: APPEND fechas nuevas a assigned_dates
foreach ($scheduleRows as $s) {
    if (!in_array((int)$s->day, $weekdaysIso)) continue;
    $dates = !empty($s->assigned_dates) ? json_decode($s->assigned_dates, true) : [];
    if (!is_array($dates)) $dates = [];
    foreach ($toCreate as $c) {
        if ($c['iso'] === (int)$s->day && !in_array($c['date'], $dates, true)) {
            $dates[] = $c['date'];
        }
    }
    sort($dates);
    $s->assigned_dates = json_encode(array_values($dates));
    $s->timemodified   = time();
    $DB->update_record('gmk_class_schedules', $s);
}

$class->timemodified = time();
$DB->update_record('gmk_class', $class);

// (d) Cache del curso
rebuild_course_cache((int)$class->corecourseid, true);

/* ============================================================
   11) Resumen final
   ============================================================ */
echo "\n=================================================\n";
echo " RESULTADO\n";
echo "=================================================\n";
echo " Sesiones creadas: " . count($created) . "\n";
echo " Nuevo enddate:    " . date('Y-m-d', $class->enddate) . "\n";
echo " Backup en:        {$backupPath}\n";
echo "=================================================\n";