<?php
define('CLI_SCRIPT', true);
require '/var/www/html/moodle/config.php';
$candidates = [
    $CFG->dirroot . '/local/grupomakro_core/pix/institute-logo.png',
    $CFG->dirroot . '/local/grupomakro_core/pix/institute-logo.jpg',
    $CFG->dirroot . '/theme/soluttolmsadmin/pix/static/logo ISI-1 (1).png',
];
foreach ($candidates as $cand) {
    echo $cand . "\n  exists: " . (file_exists($cand) ? 'yes' : 'no') . "  readable: " . (is_readable($cand) ? 'yes' : 'no') . "\n";
}