<?php
define('CLI_SCRIPT', true);
require '/var/www/html/moodle/config.php';
echo "=== local_learning_users columns ===\n";
foreach ($DB->get_columns('local_learning_users') as $c) {
    echo "  " . $c->name . ' (' . $c->type . ')' . "\n";
}
echo "\n=== sample values for the userrolename field ===\n";
$vals = $DB->get_records_sql("SELECT DISTINCT userrolename, COUNT(*) as cnt FROM {local_learning_users} GROUP BY userrolename ORDER BY cnt DESC");
foreach ($vals as $v) {
    echo "  '" . $v->userrolename . "' = " . $v->cnt . "\n";
}

echo "\n=== Check for userstate/estado columns ===\n";
foreach ($DB->get_columns('local_learning_users') as $c) {
    if (preg_match('/state|status|estado|condition|situation/i', $c->name)) {
        echo "  Found: " . $c->name . ' (' . $c->type . ')' . "\n";
        $vals = $DB->get_records_sql("SELECT DISTINCT $c->name AS v, COUNT(*) AS cnt FROM {local_learning_users} GROUP BY $c->name ORDER BY cnt DESC LIMIT 10");
        foreach ($vals as $v) {
            echo "    '" . $v->v . "' = " . $v->cnt . "\n";
        }
    }
}

echo "\n=== Check user table for profile fields ===\n";
$profilefields = $DB->get_records('user_info_field', null, 'shortname ASC');
foreach ($profilefields as $f) {
    if (preg_match('/state|status|estado|academic|condition/i', $f->shortname)) {
        echo "  profile field: " . $f->shortname . "\n";
    }
}