<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Move old QR attendance logs into the monthly files in moodledata.
 *
 * Each line goes to the month of its "time" field and every touched month is
 * rewritten sorted by time, so lines already written there by the new code are
 * kept in order. Source files are never modified or deleted.
 *
 * Usage:
 *   php local/grupomakro_core/cli/migrate_qr_attendance_log.php --source=/path/a.log [--source2=/path/b.log]
 *
 * @package    local_grupomakro_core
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_grupomakro_core\local\qr_attendance_log;

list($options, $unrecognized) = cli_get_params(['source' => '', 'source2' => '', 'help' => false], ['h' => 'help']);
$sources = array_filter([$options['source'], $options['source2']]);
if ($options['help'] || !$sources) {
    echo "php migrate_qr_attendance_log.php --source=file [--source2=file]\n";
    exit(0);
}

$bymonth = [];
$read = 0;
$skipped = 0;
foreach ($sources as $src) {
    if (!is_readable($src)) {
        cli_error("No se puede leer $src");
    }
    $fh = fopen($src, 'r');
    while (($line = fgets($fh)) !== false) {
        $line = rtrim($line, "\r\n");
        if ($line === '') {
            continue;
        }
        $read++;
        $time = '';
        $decoded = json_decode($line, true);
        if (is_array($decoded) && !empty($decoded['time'])) {
            $time = (string)$decoded['time'];
        }
        if (!preg_match('/^\d{4}-\d{2}/', $time)) {
            $skipped++;
            continue;
        }
        $bymonth[substr($time, 0, 7)][] = [$time, $line];
    }
    fclose($fh);
    echo "leido $src\n";
}

foreach ($bymonth as $month => $lines) {
    $file = qr_attendance_log::file_for($month . '-01 00:00:00');
    $existing = 0;
    if (is_file($file)) {
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $decoded = json_decode($line, true);
            $lines[] = [is_array($decoded) ? (string)($decoded['time'] ?? '') : '', $line];
            $existing++;
        }
    }
    // Same line twice (a source given twice, or a rerun) is kept once.
    $seen = [];
    $lines = array_values(array_filter($lines, function ($l) use (&$seen) {
        $key = sha1($l[1]);
        if (isset($seen[$key])) {
            return false;
        }
        $seen[$key] = true;
        return true;
    }));
    usort($lines, function ($a, $b) {
        return strcmp($a[0], $b[0]);
    });
    $tmp = $file . '.tmp';
    file_put_contents($tmp, implode(PHP_EOL, array_column($lines, 1)) . PHP_EOL, LOCK_EX);
    rename($tmp, $file);
    echo basename($file) . ": " . count($lines) . " lineas (ya habia $existing)\n";
}
echo "lineas leidas: $read | sin fecha (omitidas): $skipped\n";
