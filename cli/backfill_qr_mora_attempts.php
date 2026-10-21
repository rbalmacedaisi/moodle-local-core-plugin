<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Load the QR scans refused for mora (reasoncode payment_restricted) from the
 * monthly QR attendance logs into gmk_qr_mora_attempt.
 *
 * Idempotent: a line already loaded (same traceid) is skipped. Dry run by default.
 *
 * Usage:
 *   php local/grupomakro_core/cli/backfill_qr_mora_attempts.php [--apply]
 *
 * @package    local_grupomakro_core
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_grupomakro_core\local\qr_attendance_log;

list($options, $unrecognized) = cli_get_params(['apply' => false, 'help' => false], ['h' => 'help']);
if ($options['help']) {
    echo "php backfill_qr_mora_attempts.php [--apply]\n";
    exit(0);
}
$apply = !empty($options['apply']);

$found = 0;
$new = 0;
$skipped = 0;
foreach (array_reverse(qr_attendance_log::files()) as $file) {
    $fh = fopen($file, 'r');
    while (($line = fgets($fh)) !== false) {
        if (strpos($line, '"payment_restricted"') === false) {
            continue;
        }
        $d = json_decode($line, true);
        if (!is_array($d) || ($d['reasoncode'] ?? '') !== 'payment_restricted'
                || (int)($d['sessionid'] ?? 0) <= 0 || (int)($d['userid'] ?? 0) <= 0) {
            continue;
        }
        $found++;
        $traceid = (string)($d['traceid'] ?? '');
        if ($traceid === '' || $DB->record_exists('gmk_qr_mora_attempt', ['traceid' => $traceid])) {
            $skipped++;
            continue;
        }
        // The traceid carries the UTC time of the scan (qr_YmdHis_xxxx).
        $time = 0;
        if (preg_match('/^qr_(\d{14})_/', $traceid, $m)) {
            $dt = DateTime::createFromFormat('YmdHis', $m[1], new DateTimeZone('UTC'));
            $time = $dt ? $dt->getTimestamp() : 0;
        }
        if ($time <= 0) {
            $time = (int)strtotime((string)($d['time'] ?? ''));
        }
        $new++;
        if ($apply) {
            $DB->insert_record('gmk_qr_mora_attempt', (object)[
                'sessionid'       => (int)$d['sessionid'],
                'userid'          => (int)$d['userid'],
                'classid'         => (int)($d['classid'] ?? 0),
                'financialstatus' => substr((string)($d['extra']['financial_status'] ?? ''), 0, 30),
                'ipaddress'       => substr((string)($d['request']['remote_addr'] ?? ''), 0, 45),
                'traceid'         => substr($traceid, 0, 64),
                'timecreated'     => $time,
            ]);
        }
    }
    fclose($fh);
    echo basename($file) . " leido\n";
}
echo ($apply ? 'APLICADO' : 'SIMULACION') . ": rechazos por mora en el log $found | nuevos $new | ya cargados $skipped\n";
