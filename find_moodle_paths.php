<?php
define('CLI_SCRIPT', true);
$config_found = false;
$paths = [
    __DIR__ . '/../../config.php',
    __DIR__ . '/../../../config.php',
    'C:/Moodle/config.php', // Common Windows install path
];

foreach ($paths as $path) {
    if (file_exists($path)) {
        require_once($path);
        $config_found = true;
        echo "Config found at: $path\n";
        break;
    }
}

if (!$config_found) {
    echo "ERROR: config.php not found.\n";
    exit(1);
}

global $CFG;

echo "Moodle Root: " . $CFG->dirroot . "\n";

$qtypes = ['ddimageortext', 'ddmarker'];

foreach ($qtypes as $qtype) {
    $file = $CFG->dirroot . "/question/type/$qtype/questiontype.php";
    if (file_exists($file)) {
        echo "Found $qtype at: $file\n";
    } else {
        echo "Not found $qtype at: $file\n";
    }
}
