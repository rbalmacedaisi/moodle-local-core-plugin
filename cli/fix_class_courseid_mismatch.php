<?php
/**
 * CLI fix: repair gmk_class.courseid and/or gmk_class.corecourseid when
 * one of them points to a course whose fullname does not match class.name.
 *
 * Reuses the same normaliser as the audit page (pages/debug_class_courseid_mismatch.php)
 * so the comparison logic is shared.
 *
 * For each affected class:
 *   - "courseid mal"   -> set gmk_class.courseid = gmk_class.corecourseid
 *   - "corecourseid mal" -> set gmk_class.corecourseid = gmk_class.courseid
 *   - "sin match" -> SKIPPED (needs manual review)
 *
 * Usage:
 *   sudo -u www-data php local/grupomakro_core/cli/fix_class_courseid_mismatch.php            # execute
 *   sudo -u www-data php local/grupomakro_core/cli/fix_class_courseid_mismatch.php --dry-run  # preview
 *   sudo -u www-data php local/grupomakro_core/cli/fix_class_courseid_mismatch.php --limit 100 # cap the run
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');

$dryrun = in_array('--dry-run', $argv ?? [], true);
$limit  = 0;
foreach ($argv ?? [] as $i => $a) {
    if ($a === '--limit' && isset($argv[$i + 1])) {
        $limit = (int)$argv[$i + 1];
    }
}

// Pick an admin to run as.
$admin = null;
if (isloggedin() && !isguestuser()) {
    $admin = $USER;
}
if (!$admin) {
    $admins = get_admins();
    if (!empty($admins)) {
        $admin = reset($admins);
        \core\session\manager::login_user($admin);
    }
}
if (!$admin) {
    fwrite(STDERR, "[fix_class_courseid_mismatch] No admin available to run as.\n");
    exit(2);
}

echo "[fix_class_courseid_mismatch] Running as user #{$admin->id} ({$admin->firstname} {$admin->lastname})\n";
echo "[fix_class_courseid_mismatch] dry_run=" . ($dryrun ? 'yes' : 'no') . " limit=" . ($limit > 0 ? $limit : 'none') . "\n\n";

/**
 * Shared normaliser (kept in sync with pages/debug_class_courseid_mismatch.php).
 *
 * For classnames we return an array of candidate normalisations because the
 * trailing single-letter rule is ambiguous (it can be a group marker "G" or
 * a Roman numeral "I" in "INGLÉS I"). The fix/audit logic tries every
 * candidate against the course name.
 *
 * For course names (moodle course.fullname) we just do the minimal cleanup.
 */
function gmk_fix_normalize_name(string $name, bool $as_classname = false): array|string {
    if (!$as_classname) {
        $s = mb_strtolower(trim($name), 'UTF-8');
        $s = preg_replace('/\p{Mn}+/u', '', normalizer_normalize($s, Normalizer::FORM_D));
        $s = preg_replace('/\s+/', ' ', $s);
        return trim($s);
    }

    $base = mb_strtolower(trim($name), 'UTF-8');
    $base = preg_replace('/\p{Mn}+/u', '', normalizer_normalize($base, Normalizer::FORM_D));
    $base = preg_replace('/\s+/', ' ', $base);

    // Strip the period+shift prefix.
    $a = preg_replace('/^\d{4}-[ivx]+ \([dns]\)\s*/u', '', $base);
    // Strip all parens content anywhere.
    $a = preg_replace('/\s*\([^)]*\)/u', '', $a);
    // Strip trailing known room/place words.
    $a = preg_replace(
        '/\s+(auditorio|sala|aula|laboratorio|taller|online|virtual|piso|modulo)$/iu',
        '',
        $a
    );
    // Variation 1: keep trailing letter (preserves Roman numerals).
    $variations = [trim($a)];
    // Variation 2: also strip trailing single letter (group marker).
    $variations[] = trim(preg_replace('/\s+[a-z]$/u', '', $a));

    return array_values(array_unique(array_filter($variations, static fn($v) => $v !== '')));
}

global $DB;

$sql = "SELECT gc.id AS classid,
               gc.name AS classname,
               gc.courseid,
               gc.corecourseid,
               c1.fullname AS course_fullname,
               c2.fullname AS corecourse_fullname,
               lp.name     AS plan_name
          FROM {gmk_class} gc
     LEFT JOIN {course} c1 ON c1.id = gc.courseid
     LEFT JOIN {course} c2 ON c2.id = gc.corecourseid
     LEFT JOIN {local_learning_plans} lp ON lp.id = gc.learningplanid
      ORDER BY gc.id ASC";

$rows = $DB->get_records_sql($sql);

$fixed_courseid    = 0;
$fixed_corecourseid = 0;
$skipped_nomatch   = 0;
$skipped_missing   = 0;
$already_ok        = 0;

$changes = [];
$i = 0;
foreach ($rows as $r) {
    $i++;
    if ($limit > 0 && $i > $limit) {
        break;
    }
    $classcore = gmk_fix_normalize_name($r->classname ?? '', true);
    $coursename = gmk_fix_normalize_name($r->course_fullname ?? '');
    $corecore   = gmk_fix_normalize_name($r->corecourse_fullname ?? '');

    $matchescore = ($corecore !== '' && $corecore === $classcore);
    $matchescourse = ($coursename !== '' && $coursename === $classcore);

    if ($matchescore && $matchescourse) {
        $already_ok++;
        continue;
    }

    if ($matchescore && !$matchescourse && !empty($r->corecourseid)) {
        // Copy corecourseid -> courseid.
        $changes[] = [
            'classid'    => (int)$r->classid,
            'classname'  => $r->classname,
            'kind'       => 'courseid',
            'old_value'  => $r->courseid,
            'new_value'  => $r->corecourseid,
            'plan'       => $r->plan_name,
        ];
        $fixed_courseid++;
        continue;
    }

    if (!$matchescore && $matchescourse && !empty($r->courseid)) {
        // Copy courseid -> corecourseid.
        $changes[] = [
            'classid'    => (int)$r->classid,
            'classname'  => $r->classname,
            'kind'       => 'corecourseid',
            'old_value'  => $r->corecourseid,
            'new_value'  => $r->courseid,
            'plan'       => $r->plan_name,
        ];
        $fixed_corecourseid++;
        continue;
    }

    if (!$matchescore && !$matchescourse) {
        $skipped_nomatch++;
        continue;
    }

    $skipped_missing++;
}

echo "[fix_class_courseid_mismatch] Audit summary:\n";
echo "  classes scanned:               " . count($rows) . "\n";
echo "  already OK:                    {$already_ok}\n";
echo "  to fix (courseid):             {$fixed_courseid}\n";
echo "  to fix (corecourseid):         {$fixed_corecourseid}\n";
echo "  skipped (sin match):           {$skipped_nomatch}\n";
echo "  skipped (origen NULL):         {$skipped_missing}\n\n";

if ($dryrun) {
    echo "[fix_class_courseid_mismatch] DRY-RUN: no changes applied. First 20 planned changes:\n";
    foreach (array_slice($changes, 0, 20) as $c) {
        printf(
            "  classid=%d  kind=%-13s old=%s new=%s  plan=%s  classname=%s\n",
            $c['classid'],
            $c['kind'],
            $c['old_value'] === null ? 'NULL' : $c['old_value'],
            $c['new_value'] === null ? 'NULL' : $c['new_value'],
            $c['plan'] ?? '?',
            $c['classname']
        );
    }
    if (count($changes) > 20) {
        echo "  ... (" . (count($changes) - 20) . " more)\n";
    }
    echo "\n[fix_class_courseid_mismatch] DRY-RUN done. Re-run without --dry-run to apply.\n";
    exit(0);
}

// Apply changes.
foreach ($changes as $c) {
    if ($c['kind'] === 'courseid') {
        $DB->set_field('gmk_class', 'courseid', $c['new_value'], ['id' => $c['classid']]);
    } elseif ($c['kind'] === 'corecourseid') {
        $DB->set_field('gmk_class', 'corecourseid', $c['new_value'], ['id' => $c['classid']]);
    }
}

echo "[fix_class_courseid_mismatch] DONE.\n";
echo "  courseid updates:              {$fixed_courseid}\n";
echo "  corecourseid updates:          {$fixed_corecourseid}\n";
echo "  skipped (sin match):           {$skipped_nomatch}\n";
echo "  skipped (origen NULL):         {$skipped_missing}\n";

echo "\nFirst 20 applied changes:\n";
foreach (array_slice($changes, 0, 20) as $c) {
    printf(
        "  classid=%d  kind=%-13s old=%s new=%s  plan=%s\n",
        $c['classid'],
        $c['kind'],
        $c['old_value'] === null ? 'NULL' : $c['old_value'],
        $c['new_value'] === null ? 'NULL' : $c['new_value'],
        $c['plan'] ?? '?'
    );
}
if (count($changes) > 20) {
    echo "  ... (" . (count($changes) - 20) . " more)\n";
}