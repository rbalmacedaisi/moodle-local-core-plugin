<?php
define('CLI_SCRIPT', true);
require '/var/www/html/moodle/config.php';

// Show the structure of local_learning_users
echo "=== Columns of local_learning_users ===\n";
$columns = $DB->get_columns('local_learning_users');
foreach ($columns as $c) {
    echo "  {$c->name} ({$c->type})\n";
}

echo "\n=== Columns of local_learning_plans ===\n";
$columns = $DB->get_columns('local_learning_plans');
foreach ($columns as $c) {
    echo "  {$c->name} ({$c->type})\n";
}

// Look for tables with currperiodid
echo "\n=== Tables with 'currperiod' column ===\n";
$like = $DB->get_records_sql("SHOW TABLES LIKE '%period%'");
foreach ($like as $t) {
    $name = array_values((array)$t)[0];
    try {
        $cols = $DB->get_columns($name);
        foreach ($cols as $c) {
            if (strpos($c->name, 'currperiod') !== false || strpos($c->name, 'subperiod') !== false) {
                echo "  $name.{$c->name} ({$c->type})\n";
            }
        }
    } catch (Throwable $e) {
        // skip
    }
}
