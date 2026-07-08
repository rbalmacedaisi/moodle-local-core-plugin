<?php
define('CLI_SCRIPT', true);
require '/var/www/html/moodle/config.php';

echo "=== Antes del filtro (todos los student) ===\n";
$sql = "SELECT lu.status, COUNT(DISTINCT lu.userid) AS users
          FROM {local_learning_users} lu
          JOIN {user} u ON u.id = lu.userid AND u.deleted = 0 AND u.suspended = 0
          JOIN {local_learning_plans} lp ON lp.id = lu.learningplanid
     WHERE lu.userrolename = 'student'
       AND EXISTS (SELECT 1 FROM {local_learning_courses} lcr WHERE lcr.learningplanid = lu.learningplanid AND lcr.isrequired = 1)
  GROUP BY lu.status";
foreach ($DB->get_records_sql($sql) as $r) {
    echo "  " . $r->status . ": " . $r->users . " users\n";
}

echo "\n=== Despues del filtro (activo + egresado) ===\n";
$sql = "SELECT COUNT(*) AS total
          FROM {local_learning_users} lu
          JOIN {user} u ON u.id = lu.userid AND u.deleted = 0 AND u.suspended = 0
          JOIN {local_learning_plans} lp ON lp.id = lu.learningplanid
     WHERE lu.userrolename = 'student'
       AND lu.status IN ('activo', 'egresado')
       AND EXISTS (SELECT 1 FROM {local_learning_courses} lcr WHERE lcr.learningplanid = lu.learningplanid AND lcr.isrequired = 1)";
echo "  total: " . $DB->get_field_sql($sql) . "\n";

echo "\n=== Por status (con filtro activo/egresado) ===\n";
$sql = "SELECT lu.status, COUNT(*) AS cnt
          FROM {local_learning_users} lu
          JOIN {user} u ON u.id = lu.userid AND u.deleted = 0 AND u.suspended = 0
          JOIN {local_learning_plans} lp ON lp.id = lu.learningplanid
     WHERE lu.userrolename = 'student'
       AND lu.status IN ('activo', 'egresado')
       AND EXISTS (SELECT 1 FROM {local_learning_courses} lcr WHERE lcr.learningplanid = lu.learningplanid AND lcr.isrequired = 1)
  GROUP BY lu.status";
foreach ($DB->get_records_sql($sql) as $r) {
    echo "  " . $r->status . ": " . $r->cnt . "\n";
}