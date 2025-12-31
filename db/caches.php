<?php
/**
 * Cache definitions for local_grupomakro_core
 */

defined('MOODLE_INTERNAL') || die();

$definitions = [
    'break_timers' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'ttl' => 3600, // 1 hour maximum for a break
    ],
];
