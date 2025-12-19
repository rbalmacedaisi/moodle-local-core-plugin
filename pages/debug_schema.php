<?php

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('grupomakro_core_manage_courses'); // Use existing permission

echo $OUTPUT->header();
echo "<h2>GMK Schema Debug</h2>";

$columns = $DB->get_columns('gmk_class');
echo "<pre>";
foreach ($columns as $name => $col) {
    if (in_array($name, ['initdate', 'enddate'])) {
        echo "<strong style='color:green'>FOUND: $name (Type: $col->type)</strong>\n";
    } else {
        echo "Column: $name\n";
    }
}
echo "</pre>";

if (!isset($columns['initdate'])) echo "<h3 style='color:red'>MISSING: initdate</h3>";
if (!isset($columns['enddate'])) echo "<h3 style='color:red'>MISSING: enddate</h3>";

echo $OUTPUT->footer();
