<?php
define('CLI_SCRIPT', true);
require_once('/var/www/html/moodle/config.php');
global $DB;

echo "=== TEST: un caso de la query 'check' (CAMILO ANDRES) ===\n";

// Tomemos un caso real y veamos qué pasa con su matriculacion
$userid = 1880; // CAITA MURCIA, CAMILO ANDRES
$user = $DB->get_record('user', ['id' => $userid]);
echo "Usuario: {$user->firstname} {$user->lastname} (id=$userid)\n\n";

// Cursos donde tiene grupos
echo "--- Grupos del usuario en cada curso ---\n";
$sql = "SELECT c.id, c.shortname, g.id AS gid, g.name AS gname
          FROM {groups_members} gm
          JOIN {groups} g ON g.id = gm.groupid
          JOIN {course} c ON c.id = g.courseid
         WHERE gm.userid = ?";
foreach ($DB->get_records_sql($sql, [$userid]) as $r) {
    echo "  curso={$r->id} {$r->shortname} | grupo={$r->gid} | {$r->gname}\n";
}

// Para cada curso: tiene matriculacion activa?
echo "\n--- Matriculacion activa (user_enrolments.status = 0) ---\n";
$sql = "SELECT c.id, c.shortname, ue.status
          FROM {course} c
          JOIN {enrol} e ON e.courseid = c.id
          JOIN {user_enrolments} ue ON ue.enrolid = e.id AND ue.userid = ?
         WHERE c.id IN (
             SELECT g.courseid FROM {groups_members} gm
             JOIN {groups} g ON g.id = gm.groupid
             WHERE gm.userid = ?
         )";
foreach ($DB->get_records_sql($sql, [$userid, $userid]) as $r) {
    echo "  curso={$r->id} {$r->shortname} | ue.status={$r->status}\n";
}

// Para cada curso: tiene rol student?
echo "\n--- Rol 'student' en el contexto del curso ---\n";
$sql = "SELECT c.id, c.shortname, ctx.id AS ctxid, ra.id AS raid, r.shortname
          FROM {course} c
          JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
          LEFT JOIN {role_assignments} ra ON ra.userid = ? AND ra.contextid = ctx.id
          LEFT JOIN {role} r ON r.id = ra.roleid
         WHERE c.id IN (
             SELECT g.courseid FROM {groups_members} gm
             JOIN {groups} g ON g.id = gm.groupid
             WHERE gm.userid = ?
         )";
foreach ($DB->get_records_sql($sql, [$userid, $userid]) as $r) {
    echo "  curso={$r->id} {$r->shortname} | ctx={$r->ctxid} | ra={$r->raid} | rol=" . ($r->shortname ?? 'NULL') . "\n";
}
