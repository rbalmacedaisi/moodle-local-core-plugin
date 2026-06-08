<?php
define('CLI_SCRIPT', true);
require_once('/var/www/html/moodle/config.php');
global $DB;

echo "=== TEST: la query que usa fix_multi_groups.php ===\n\n";

$sql = "
    SELECT
        u.id            AS userid,
        u.firstname,
        u.lastname,
        c.id            AS courseid,
        c.shortname,
        COUNT(DISTINCT gm.groupid) AS num_grupos
    FROM {user} u
    JOIN {groups_members} gm ON gm.userid = u.id
    JOIN {groups}        g  ON g.id       = gm.groupid
    JOIN {course}        c  ON c.id       = g.courseid
    JOIN {enrol}         e  ON e.courseid = c.id
    JOIN {user_enrolments} ue ON ue.enrolid = e.id AND ue.userid = u.id AND ue.status = 0
    JOIN {context}      ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
    JOIN {role_assignments} ra ON ra.userid = u.id AND ra.contextid = ctx.id
    JOIN {role}         r   ON r.id = ra.roleid AND r.shortname = 'student'
    WHERE u.deleted   = 0
      AND u.suspended = 0
      AND g.name REGEXP 'M[ÓO]DULO'
    GROUP BY u.id, c.id
    HAVING COUNT(DISTINCT gm.groupid) > 1
    ORDER BY u.lastname
    LIMIT 10
";

echo "Resultado query 'fix' (limit 10):\n";
$rows = $DB->get_records_sql($sql);
echo "Filas: " . count($rows) . "\n";
foreach ($rows as $r) {
    echo "  [{$r->userid}] {$r->lastname}, {$r->firstname} | curso={$r->courseid} {$r->shortname} | grupos={$r->num_grupos}\n";
}

// Y la query original del check_multi_groups (sin filtro modulo)
echo "\n=== TEST: query 'check' original ===\n";
$sql2 = "
    SELECT
        u.id AS userid,
        u.firstname,
        u.lastname,
        c.id AS courseid,
        c.shortname,
        COUNT(DISTINCT gm.groupid) AS num_grupos
    FROM {user} u
    JOIN {groups_members} gm ON u.id = gm.userid
    JOIN {groups} g ON gm.groupid = g.id
    JOIN {course} c ON g.courseid = c.id
    WHERE u.deleted = 0 AND u.suspended = 0
    GROUP BY u.id, c.id
    HAVING num_grupos > 1
    LIMIT 5
";
foreach ($DB->get_records_sql($sql2) as $r) {
    echo "  [{$r->userid}] {$r->lastname}, {$r->firstname} | {$r->shortname} | grupos={$r->num_grupos}\n";
}
