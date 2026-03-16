<?php
// Debug page: repair orphan cmids in course section sequences.
//
// This removes stale course module ids from course_sections.sequence and
// rebuilds course cache so modinfo/navigation stops crashing with invalid cmid.

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$courseid = optional_param('courseid', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_repair_course_sequence.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Debug Repair Course Sequence');
$PAGE->set_heading('Debug Repair Course Sequence');

/**
 * Parse section sequence into ordered unique cmids.
 *
 * @param string $sequence
 * @return int[]
 */
function gmk_dbg_seq_parse(string $sequence): array {
    $ids = [];
    $seen = [];
    foreach (explode(',', trim($sequence)) as $raw) {
        $cmid = (int)trim((string)$raw);
        if ($cmid <= 0 || isset($seen[$cmid])) {
            continue;
        }
        $seen[$cmid] = true;
        $ids[] = $cmid;
    }
    return $ids;
}

/**
 * Collect section sequence diagnostics for one course.
 *
 * @param int $courseid
 * @return array<int,array<string,mixed>>
 */
function gmk_dbg_seq_collect(int $courseid): array {
    global $DB;

    $rows = [];
    if ($courseid <= 0) {
        return $rows;
    }

    $sections = $DB->get_records('course_sections', ['course' => $courseid], 'section ASC, id ASC', 'id,course,section,name,sequence');
    foreach ($sections as $section) {
        $raw = trim((string)$section->sequence);
        $seqids = gmk_dbg_seq_parse($raw);
        $actualids = $DB->get_fieldset_select('course_modules', 'id', 'section = :sid', ['sid' => (int)$section->id]);
        $actualids = array_values(array_map('intval', $actualids));
        sort($actualids);

        $orphans = array_values(array_diff($seqids, $actualids));
        $missing = array_values(array_diff($actualids, $seqids));

        $rows[] = [
            'id' => (int)$section->id,
            'sectionnum' => (int)$section->section,
            'name' => (string)$section->name,
            'sequence' => $raw,
            'seqids' => $seqids,
            'actualids' => $actualids,
            'orphans' => $orphans,
            'missing' => $missing,
            'ok' => empty($orphans),
        ];
    }

    return $rows;
}

$repairmessage = '';
$repairclass = '';

if ($action === 'repair' && $courseid > 0) {
    require_sesskey();
    $summary = gmk_prune_invalid_course_section_sequences((int)$courseid, true);
    $repairmessage = 'Repair applied for course ' . (int)$courseid
        . ': updated sections=' . (int)($summary['updatedsections'] ?? 0)
        . ', removed orphan cmids=' . (int)($summary['removedcmids'] ?? 0)
        . '.';
    $repairclass = 'alert alert-success';
}

$courserec = null;
if ($courseid > 0) {
    $courserec = $DB->get_record('course', ['id' => $courseid], 'id,fullname,shortname', IGNORE_MISSING);
}

$rows = $courseid > 0 ? gmk_dbg_seq_collect((int)$courseid) : [];

echo $OUTPUT->header();

echo '<h2>Repair Course Section Sequence</h2>';
echo '<p>Use this tool when Moodle throws invalid course module errors caused by orphan cmids in section sequence.</p>';

if (!empty($repairmessage)) {
    echo html_writer::div(s($repairmessage), $repairclass);
}

echo '<form method="get" style="margin:12px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap">';
echo '<label for="courseid"><strong>Course ID</strong></label>';
echo '<input type="number" min="1" name="courseid" id="courseid" value="' . (int)$courseid . '" style="padding:6px 8px;width:140px">';
echo '<button type="submit" class="btn btn-primary">Inspect</button>';
echo '</form>';

if ($courseid <= 0) {
    echo $OUTPUT->footer();
    exit;
}

if (!$courserec) {
    echo html_writer::div('Course not found: ' . (int)$courseid, 'alert alert-danger');
    echo $OUTPUT->footer();
    exit;
}

echo html_writer::div(
    '<strong>Course:</strong> ' . (int)$courserec->id . ' - ' . s($courserec->fullname) . ' (' . s($courserec->shortname) . ')',
    'alert alert-info'
);

$totalorphans = 0;
$sectionswithorphans = 0;
foreach ($rows as $r) {
    if (!empty($r['orphans'])) {
        $sectionswithorphans++;
        $totalorphans += count($r['orphans']);
    }
}

if ($sectionswithorphans > 0) {
    echo html_writer::div(
        'Found orphan cmids in sequence. Sections=' . $sectionswithorphans . ', orphan cmids=' . $totalorphans . '.',
        'alert alert-warning'
    );
} else {
    echo html_writer::div('No orphan cmids found in section sequence.', 'alert alert-success');
}

$repairurl = new moodle_url('/local/grupomakro_core/pages/debug_repair_course_sequence.php', [
    'courseid' => (int)$courseid,
    'action' => 'repair',
    'sesskey' => sesskey(),
]);
echo '<p><a class="btn btn-secondary" href="' . $repairurl->out(false) . '">Run Repair</a></p>';

echo '<table class="generaltable">';
echo '<thead><tr>';
echo '<th>Section ID</th><th>Section Num</th><th>Name</th><th>Orphan CMIDs</th><th>Missing In Sequence</th><th>Status</th>';
echo '</tr></thead><tbody>';

foreach ($rows as $r) {
    $orphans = empty($r['orphans']) ? '-' : implode(', ', $r['orphans']);
    $missing = empty($r['missing']) ? '-' : implode(', ', $r['missing']);
    $status = empty($r['orphans']) ? 'OK' : 'HAS_ORPHANS';
    echo '<tr>';
    echo '<td>' . (int)$r['id'] . '</td>';
    echo '<td>' . (int)$r['sectionnum'] . '</td>';
    echo '<td>' . s($r['name']) . '</td>';
    echo '<td>' . s($orphans) . '</td>';
    echo '<td>' . s($missing) . '</td>';
    echo '<td>' . s($status) . '</td>';
    echo '</tr>';
}

echo '</tbody></table>';

echo $OUTPUT->footer();

