<?php
// This file is part of Moodle - http://moodle.org/
//
// Limpieza de gmk_class_queue (roster planificado de cada clase).
//
// gmk_class_queue NO es una cola de aprobacion: es el roster planificado de la
// clase, y las rutas de matriculacion lo conservan a proposito. Con el tiempo
// acumula dos tipos de basura:
//
//   1. Filas cuya clase ya no existe. Un borrado masivo de clases entre el
//      2026-02-21 y el 2026-03-13 dejo 95.728 filas apuntando a 5.326 classid
//      inexistentes. Ningun codigo puede alcanzarlas: todo consulta por classid.
//   2. Filas de clases aprobadas o cerradas donde el alumno nunca se matriculo.
//      Republicar el tablero reescribe el roster incluso en clases ya aprobadas,
//      y bulk_approve_period solo mira las clases con approved = 0, asi que esos
//      alumnos quedaban planificados para siempre sin que nadie los inscribiera.
//
// CONSERVA: las filas cuyo alumno SI esta matriculado (roster real) y las de
// clases sin aprobar (plan vigente que la aprobacion todavia va a procesar).
//
// Uso:
//   php limpiar_cola_planificacion.php            # ensayo, no toca nada
//   APPLY=1 php limpiar_cola_planificacion.php    # aplica
//
// Antes de borrar hace un respaldo comprimido de la tabla completa en
// /home/ubuntu/ y al terminar verifica que solo cambio gmk_class_queue, en la
// cantidad exacta: gmk_class, gmk_course_progre, groups_members y
// gmk_class_pre_registration deben quedar identicas.
//
// @package    local_grupomakro_core
define("CLI_SCRIPT", true);
require("/var/www/html/moodle/config.php");
global $DB;
$DRY = getenv("APPLY") !== "1";
$now = time();
echo ($DRY ? "*** ENSAYO (DRY RUN) ***" : "*** APLICANDO ***") . "\n\n";

// --- FOTO ANTES ---
$antes = [
 "queue_total"      => $DB->count_records("gmk_class_queue"),
 "queue_sin_clase"  => $DB->count_records_sql("SELECT COUNT(*) FROM {gmk_class_queue} q LEFT JOIN {gmk_class} c ON c.id=q.classid WHERE c.id IS NULL"),
 "clases"           => $DB->count_records("gmk_class"),
 "progre_con_class" => $DB->count_records_select("gmk_course_progre", "classid > 0"),
 "groups_members"   => $DB->count_records("groups_members"),
 "prereg"           => $DB->count_records("gmk_class_pre_registration"),
];
echo "=== ANTES ===\n"; foreach ($antes as $k=>$v) printf("  %-18s %8d\n", $k, $v);

// --- GRUPO 1: filas cuya clase ya no existe ---
$g1 = $DB->get_fieldset_sql("SELECT q.id FROM {gmk_class_queue} q LEFT JOIN {gmk_class} c ON c.id=q.classid WHERE c.id IS NULL");

// --- GRUPO 2: filas de clases existentes donde el alumno nunca se matriculo y ya no se matriculara ---
$rows = $DB->get_records_sql(
 "SELECT q.id, q.userid, q.classid, c.approved, c.closed, c.groupid, c.enddate
    FROM {gmk_class_queue} q JOIN {gmk_class} c ON c.id = q.classid");
$members = []; $progre = [];
foreach ($DB->get_recordset_sql("SELECT DISTINCT groupid, userid FROM {groups_members}") as $g) $members[$g->groupid][(int)$g->userid] = true;
foreach ($DB->get_recordset_sql("SELECT DISTINCT classid, userid FROM {gmk_course_progre} WHERE classid > 0") as $p) $progre[$p->classid][(int)$p->userid] = true;

$g2 = []; $keep = ["matriculado"=>0, "sin_aprobar"=>0];
foreach ($rows as $r) {
    $uid = (int)$r->userid;
    if (isset($members[$r->groupid][$uid]) || isset($progre[$r->classid][$uid])) { $keep["matriculado"]++; continue; }
    if ((int)$r->approved !== 1 && (int)$r->closed !== 1) { $keep["sin_aprobar"]++; continue; }
    $g2[] = (int)$r->id;
}

printf("\n=== A BORRAR ===\n  %-34s %8d\n  %-34s %8d\n  %-34s %8d\n",
  "1. clase inexistente (borrada)", count($g1),
  "2. aprobada/cerrada sin matricular", count($g2),
  "TOTAL", count($g1)+count($g2));
printf("\n=== A CONSERVAR ===\n  %-34s %8d\n  %-34s %8d\n  %-34s %8d\n",
  "alumno matriculado (roster real)", $keep["matriculado"],
  "clase sin aprobar (plan vigente)", $keep["sin_aprobar"],
  "TOTAL", $keep["matriculado"]+$keep["sin_aprobar"]);

$ids = array_merge(array_map("intval",$g1), $g2);
if (count($ids) + $keep["matriculado"] + $keep["sin_aprobar"] !== $antes["queue_total"]) {
    echo "\n!!! el reparto no suma el total — abortando\n"; exit(1);
}
echo "\n  control: borrar + conservar == total  OK\n";

if ($DRY) { echo "\nNada modificado. Ejecutar con APPLY=1.\n"; exit(0); }

// --- RESPALDO COMPLETO ---
$bk = "/home/ubuntu/gmk_class_queue_backup_" . date("Ymd_His") . ".json.gz";
$fh = gzopen($bk, "w9");
gzwrite($fh, "[");
$first = true;
foreach ($DB->get_recordset("gmk_class_queue") as $r) { gzwrite($fh, ($first?"":",") . json_encode($r, JSON_UNESCAPED_UNICODE)); $first = false; }
gzwrite($fh, "]"); gzclose($fh);
printf("\nrespaldo COMPLETO: %s (%s bytes)\n", $bk, number_format(filesize($bk)));

// --- BORRADO ---
foreach (array_chunk($ids, 1000) as $chunk) {
    list($insql, $params) = $DB->get_in_or_equal($chunk);
    $DB->delete_records_select("gmk_class_queue", "id $insql", $params);
}

// --- FOTO DESPUES ---
$despues = [
 "queue_total"      => $DB->count_records("gmk_class_queue"),
 "queue_sin_clase"  => $DB->count_records_sql("SELECT COUNT(*) FROM {gmk_class_queue} q LEFT JOIN {gmk_class} c ON c.id=q.classid WHERE c.id IS NULL"),
 "clases"           => $DB->count_records("gmk_class"),
 "progre_con_class" => $DB->count_records_select("gmk_course_progre", "classid > 0"),
 "groups_members"   => $DB->count_records("groups_members"),
 "prereg"           => $DB->count_records("gmk_class_pre_registration"),
];
echo "\n=== DESPUES ===\n";
foreach ($despues as $k=>$v) printf("  %-18s %8d   (antes %d, delta %+d)\n", $k, $v, $antes[$k], $v-$antes[$k]);

$ok = ($despues["queue_total"] === $antes["queue_total"] - count($ids))
   && ($despues["clases"] === $antes["clases"])
   && ($despues["progre_con_class"] === $antes["progre_con_class"])
   && ($despues["groups_members"] === $antes["groups_members"])
   && ($despues["prereg"] === $antes["prereg"]);
echo $ok ? "\nCONTROLES OK: solo se toco gmk_class_queue, en la cantidad exacta.\n"
         : "\n!!! DESCUADRE EN LOS CONTROLES — revisar y restaurar desde el respaldo\n";
