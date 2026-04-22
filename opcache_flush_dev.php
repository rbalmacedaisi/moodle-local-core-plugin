<?php
// Temporary dev tool — delete after use.
// Flushes PHP OPcache so updated plugin files take effect immediately.
require_once(dirname(__FILE__) . '/../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

$results = [];

if (function_exists('opcache_reset')) {
    $ok = opcache_reset();
    $results[] = 'opcache_reset(): ' . ($ok ? 'OK' : 'FAILED');
} else {
    $results[] = 'opcache_reset(): NOT AVAILABLE (OPcache disabled or CLI only)';
}

// Also purge Moodle's own cache layer
purge_all_caches();
$results[] = 'purge_all_caches(): OK';

header('Content-Type: text/plain; charset=utf-8');
echo implode("\n", $results) . "\n";
echo "Done at " . date('Y-m-d H:i:s') . "\n";
