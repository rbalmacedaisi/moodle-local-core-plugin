<?php
require_once(__DIR__ . '/../../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

global $DB;
$cols = $DB->get_columns('gmk_class_schedules');
echo "Columnas de gmk_class_schedules:\n";
foreach ($cols as $c) {
    echo "{$c->name} ({$c->type})\n";
}
