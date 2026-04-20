#!/usr/bin/env php
<?php
/**
 * Fix script for pre-existing module enrollments that lack original_status.
 *
 * BEFORE RUNNING: Moodle must be in maintenance mode or the site should be quiet.
 *
 * USAGE:
 *   Step 1 (dry-run - read only):
 *     php fix_module_original_status.php --dry-run
 *
 *   Step 2 (real fix - requires confirmation):
 *     php fix_module_original_status.php --confirm
 *
 * This script will NOT modify any data without the --confirm flag.
 */

define('CLI_SCRIPT', 1);
define('MOODLE_INTERNAL', 1);

// Path to Moodle config
$configPath = __DIR__ . '/../../config.php';
if (!file_exists($configPath)) {
    $configPath = '/var/www/html/moodle/config.php';
}
if (!file_exists($configPath)) {
    die("ERROR: Could not find Moodle config.php\n");
}

/** @var mixed */
require_once($configPath);
require_once($CFG->dirroot . '/lib/clilib.php');

echo "=== Fix: Set original_status for pre-existing module enrollments ===\n\n";
echo "This script works in TWO steps:\n";
echo "  Step 1 --dry-run  : Shows what WOULD be changed (read-only)\n";
echo "  Step 2 --confirm : Actually applies the changes\n\n";

if (!isset($argv[1]) || !in_array($argv[1], ['--dry-run', '--confirm'], true)) {
    echo "Usage:\n";
    echo "  php fix_module_original_status.php --dry-run   (preview changes)\n";
    echo "  php fix_module_original_status.php --confirm  (apply changes)\n";
    exit(1);
}

$dryRun = ($argv[1] === '--dry-run');

echo "Mode: " . ($dryRun ? 'DRY-RUN (no changes will be made)' : 'CONFIRM (changes will be applied)') . "\n\n";

global $DB;

// Find all module enrollments where original_status IS NULL
$sql = "
    SELECT gme.id as enrollment_id,
           gme.userid,
           gme.classid,
           gme.enrolldate,
           gme.status as enrollment_status,
           gc.corecourseid,
           gc.learningplanid,
           c.fullname as coursename,
           u.firstname,
           u.lastname,
           u.email,
           gcp.status as current_progre_status,
           gcp.id as progre_id
      FROM {gmk_module_enrollment} gme
      JOIN {gmk_class} gc ON gc.id = gme.classid
      JOIN {course} c ON c.id = gc.corecourseid
      JOIN {user} u ON u.id = gme.userid
 LEFT JOIN {gmk_course_progre} gcp
            ON gcp.userid = gme.userid
           AND gcp.courseid = gc.corecourseid
           AND gcp.learningplanid = gc.learningplanid
     WHERE gme.original_status IS NULL
       AND gme.status = 'active'
";

$affected = $DB->get_records_sql($sql);

echo "Found " . count($affected) . " module enrollment(s) needing original_status fix.\n\n";

if (empty($affected)) {
    echo "No pre-existing module enrollments without original_status found.\n";
    echo "Nothing to do. Exiting.\n";
    exit(0);
}

echo "----------------------------------------\n";
echo sprintf("%-6s %-25s %-30s %-12s %-12s\n", "EnrlID", "Student", "Course", "CurrStatus", "WillBe");
echo "----------------------------------------\n";

$statusLabels = [
    0 => 'No disponible',
    1 => 'Disponible',
    2 => 'Cursando',
    3 => 'Aprobada',
    4 => 'Aprobada',
    5 => 'Reprobada',
    6 => 'Revalida',
    7 => 'Reprobado',
    99 => 'Migración Pend.',
];

$previewData = [];

foreach ($affected as $row) {
    $studentName = trim("{$row->firstname} {$row->lastname}");
    $currentStatus = $row->current_progre_status !== null ? $statusLabels[$row->current_progre_status] ?? "Status {$row->current_progre_status}" : 'N/A (no progre record)';
    $willBeStatus = 'Cursando (2)';
    $willBeOriginal = $row->current_progre_status !== null ? "{$statusLabels[$row->current_progre_status]} ({$row->current_progre_status})" : 'NULL';

    echo sprintf(
        "%-6d %-25s %-30s %-12s %-12s\n",
        $row->enrollment_id,
        substr($studentName, 0, 23),
        substr($row->coursename, 0, 28),
        $currentStatus,
        $willBeOriginal . ' → 2'
    );

    $previewData[] = $row;
}

echo "\n";

if ($dryRun) {
    echo "DRY-RUN COMPLETE. No changes were made.\n";
    echo "Run with --confirm to apply these changes.\n";
    exit(0);
}

// =====================================================
// CONFIRM MODE - We are about to make changes
// =====================================================

echo "WARNING: You are about to modify " . count($previewData) . " record(s) in the PRODUCTION database.\n";
echo "\n";
echo "ARE YOU SURE YOU WANT TO CONTINUE? (type 'yes' to proceed): ";
$handle = fopen("php://stdin", "r");
$line = trim(fgets($handle));
fclose($handle);

if ($line !== 'yes') {
    echo "Aborted. No changes were made.\n";
    exit(0);
}

echo "\nProceeding with updates...\n";

$successCount = 0;
$errorCount = 0;
$skippedCount = 0;

foreach ($previewData as $row) {
    $errors = [];

    // 1. Update gmk_module_enrollment.original_status
    if ($row->current_progre_status !== null) {
        $updated = $DB->set_field(
            'gmk_module_enrollment',
            'original_status',
            $row->current_progre_status,
            ['id' => $row->enrollment_id]
        );
        if (!$updated) {
            $errors[] = "Failed to update original_status on enrollment {$row->enrollment_id}";
        }
    }

    // 2. Update gmk_course_progre.status to 2 (Cursando)
    if ($row->progre_id) {
        $updated = $DB->set_field(
            'gmk_course_progre',
            'status',
            2,
            ['id' => $row->progre_id]
        );
        if (!$updated) {
            $errors[] = "Failed to update status to 2 on progre {$row->progre_id}";
        }
    } else {
        // No progress record exists - create one
        $newProgress = new \stdClass();
        $newProgress->userid = $row->userid;
        $newProgress->courseid = $row->corecourseid;
        $newProgress->learningplanid = $row->learningplanid;
        $newProgress->status = 2;
        $newProgress->progress = 0;
        $newProgress->grade = 0;  // NOT NULL field - use 0 as default
        $newProgress->credits = 0;
        $newProgress->timecreated = time();
        $newProgress->timemodified = time();
        $inserted = $DB->insert_record('gmk_course_progre', $newProgress);
        if (!$inserted) {
            $errors[] = "Failed to create new progre record for user {$row->userid}, course {$row->corecourseid}";
        }
    }

    if (empty($errors)) {
        $successCount++;
        echo "  [OK] Enrollment {$row->enrollment_id}: {$row->firstname} {$row->lastname} - {$row->coursename}\n";
    } else {
        $errorCount++;
        echo "  [ERROR] Enrollment {$row->enrollment_id}: " . implode(', ', $errors) . "\n";
    }
}

echo "\n========================================\n";
echo "SUMMARY:\n";
echo "  Success: {$successCount}\n";
echo "  Errors:  {$errorCount}\n";
echo "========================================\n";

if ($errorCount > 0) {
    echo "Some records could not be updated. Please review errors above.\n";
    exit(1);
}

echo "All records updated successfully.\n";
exit(0);
