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
    'instructors_with_disp' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'ttl' => 300, // 5 minutes: instructors with disponibility, low churn
    ],
    // === Calendar / schedule caches (added 2026-08 to fix slow get_class_events) ===
    // Cached enriched gmk_class record keyed by class id. Short TTL because
    // attendance session edits and enrolment changes can shift capacity/days.
    'gmkclass_enriched' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
        'ttl' => 120,
    ],
    // Per-class minimal data: instructors by id (used by list_classes + calendar).
    'gmkinstructor' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'ttl' => 600,
    ],
    // Learning plans by id.
    'gmklearningplan' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'ttl' => 600,
    ],
    // Learning periods by id.
    'gmklearningperiod' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'ttl' => 600,
    ],
    // Course metadata (id, fullname, shortname, customfields).
    'gmkcoursecache' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
        'ttl' => 600,
    ],
    // Classroom name by id.
    'gmkclassroom' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'ttl' => 600,
    ],
    // gmk_bbb_attendance_relation row keyed by relation id; populated after a
    // bulk prefetch keyed by class id in get_class_events().
    'gmkbbbatrel' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'ttl' => 300,
    ],
    // attendance_sessions row keyed by id (for calendar time/duration resolution).
    'gmkattendancesession' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'ttl' => 300,
    ],
];
