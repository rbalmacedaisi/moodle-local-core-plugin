<?php
define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

// Now get CLI options.
list($options, $unrecognized) = cli_get_params(
    array(
        'help' => false,
        'file' => false,
        'lpid' => false,
    ),
    array(
        'h' => 'help',
        'f' => 'file',
        'l' => 'lpid',
    )
);

if ($options['help'] || !$options['file'] || !$options['lpid']) {
    $help = 
"Bulk Update Student Periods and Status
This script updates student curricular levels, academic periods, and statuses from a CSV file.

Options:
-h, --help            Print out this help
-f, --file=FILE       Path to CSV file
-l, --lpid=ID         Learning Plan ID to target

CSV Format (no header):
idnumber,currentperiodid,academicperiodid,status

Example:
idnumber,currentperiodid,academicperiodid,status
123456,2,34,activo
789012,1,0,aplazado

Note: idnumber is student's identification. currentperiodid is the Level (Semester). 
academicperiodid is the Calendar Period ID from gmk_academic_calendar.

Example usage:
php local/grupomakro_core/cli/bulk_update_students.php --file=students.csv --lpid=5
";
    cli_writeln($help);
    exit;
}

$filename = $options['file'];
$lpid = (int)$options['lpid'];

if (!file_exists($filename)) {
    cli_error("File not found: $filename");
}

$handle = fopen($filename, "r");
$count = 0;
$updated = 0;
$errors = 0;

cli_writeln("Starting bulk update for Learning Plan ID: $lpid");

while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
    if ($count == 0 && $data[0] == 'idnumber') { // Skip header
        $count++;
        continue;
    }
    
    $idnumber = trim($data[0]);
    $newLevel = isset($data[1]) ? (int)$data[1] : null;
    $newAcadPeriod = isset($data[2]) ? (int)$data[2] : null;
    $newStatus = isset($data[3]) ? trim($data[3]) : 'activo';

    // Find user by idnumber
    $user = $DB->get_record('user', ['idnumber' => $idnumber, 'deleted' => 0]);
    if (!$user) {
        cli_writeln("ERROR: User with idnumber $idnumber not found.");
        $errors++;
        continue;
    }

    $llu = $DB->get_record('local_learning_users', ['userid' => $user->id, 'learningplanid' => $lpid]);
    if (!$llu) {
        cli_writeln("ERROR: User $idnumber (ID: $user->id) not in Learning Plan $lpid.");
        $errors++;
        continue;
    }

    $rec = new stdClass();
    $rec->id = $llu->id;
    if ($newLevel) $rec->currentperiodid = $newLevel;
    if ($newAcadPeriod) $rec->academicperiodid = $newAcadPeriod;
    $rec->status = $newStatus;
    $rec->timemodified = time();
    
    if ($DB->update_record('local_learning_users', $rec)) {
        $updated++;
        // Log suspension if needed
        if ($newStatus != 'activo' && $newStatus != $llu->status) {
             $susp = new stdClass();
             $susp->userid = $user->id;
             $susp->status = $newStatus;
             $susp->timecreated = time();
             $susp->reason = 'Bulk update override';
             $DB->insert_record('gmk_student_suspension', $susp);
        }
    } else {
        cli_writeln("ERROR: Failed to update user $idnumber.");
        $errors++;
    }

    $count++;
}

fclose($handle);
cli_writeln("Finished. Total rows: $count (excl header). Updated: $updated. Errors: $errors.");
