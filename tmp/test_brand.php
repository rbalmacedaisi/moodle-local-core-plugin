<?php
define('CLI_SCRIPT', true);
require '/var/www/html/moodle/config.php';
$type = optional_param('asset', '', PARAM_ALPHANUMEXT);
echo "type=$type\n";
if ($type === 'brand') {
    echo "brand branch entered\n";
    $candidates = [
        $CFG->dirroot . '/local/grupomakro_core/pix/institute-logo.png',
        $CFG->dirroot . '/local/grupomakro_core/pix/institute-logo.jpg',
        $CFG->dirroot . '/theme/soluttolmsadmin/pix/static/logo ISI-1 (1).png',
    ];
    foreach ($candidates as $cand) {
        echo "  $cand readable: " . (is_readable($cand) ? 'yes' : 'no') . "\n";
        if (is_readable($cand)) { echo "  would send: $cand\n"; break; }
    }
}