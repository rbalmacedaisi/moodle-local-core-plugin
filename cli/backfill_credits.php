<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * CLI script: backfill credits from local_learning_credits into gmk_course_progre.
 *
 * Resync every per-student progress row's credits field with the canonical value
 * from local_learning_credits (with the legacy course custom field as fallback).
 * This is the catch-up counterpart to the cron task, useful for the first deployment
 * or whenever the cache has drifted far from the canonical store.
 *
 * USAGE:
 *   php backfill_credits.php --dry-run
 *   php backfill_credits.php --execute
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Grupo Makro
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', 1);
define('MOODLE_INTERNAL', 1);

$configpath = __DIR__ . '/../../../config.php';
if (!file_exists($configpath)) {
    $configpath = '/var/www/html/moodle/config.php';
}
if (!file_exists($configpath)) {
    fwrite(STDERR, "ERROR: Moodle config.php not found.\n");
    exit(2);
}

require_once($configpath);
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/local/sc_learningplans/classes/local/credit_resolver.php');

use local_sc_learningplans\local\credit_resolver;

$longoptions = [
    'dry-run' => false,
    'execute' => false,
    'help'    => false,
];

$options = getopt('', ['dry-run', 'execute', 'help']);

if (isset($options['help']) || (!$options && !defined('CLI_SCRIPT_NO_ARGS_HELP'))) {
    echo "Usage:\n";
    echo "  php backfill_credits.php --dry-run   List per-(plan, course) pairs and what would change.\n";
    echo "  php backfill_credits.php --execute   Apply the sync to gmk_course_progre.credits.\n";
    exit(0);
}

$dryrun = isset($options['dry-run']);
$execute = isset($options['execute']);

if (!$dryrun && !$execute) {
    fwrite(STDERR, "ERROR: pass --dry-run or --execute\n");
    exit(1);
}

cli_heading('Credit backfill (' . ($dryrun ? 'dry-run' : 'execute') . ')');

$started = microtime(true);
$result = credit_resolver::backfill_all();
$duration = round(microtime(true) - $started, 3);

printf("Scanned distinct (plan, course) pairs : %d\n", $result['scanned']);
printf("Per-student snapshots refreshed      : %d\n", $result['updated']);
printf("Pairs with no resolvable credit value: %d\n", $result['missing']);
printf("Duration                             : %.3fs\n", $duration);

if ($execute) {
    cli_heading('Done. Cron credit_integrity_check will keep the cache aligned from now on.');
} else {
    cli_heading('Dry-run only. Re-run with --execute to apply.');
}

exit(0);