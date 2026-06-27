<?php
/**
 * Audit page: list every gmk_class whose courseid points to a course
 * whose fullname does not match the class.name (or whose courseid points
 * to a different course than corecourseid does).
 *
 * The class.name format usually looks like:
 *   "2026-III (D) CURSO DE INGLÉS CONVERSACIONAL (PRESENCIAL)"
 *
 * We extract the subject token by stripping the leading "YYYY-N (X)" shift
 * prefix and the trailing modality suffix "(PRESENCIAL|MIXTA|VIRTUAL)" so
 * the comparison is tolerant of these decorations.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_class_courseid_mismatch.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Auditoría: courseid mal asignado en gmk_class');
$PAGE->set_heading('Auditoría: courseid mal asignado en gmk_class');

echo $OUTPUT->header();

/**
 * Normalise a course or class name into a comparable token:
 *  - lowercase
 *  - strip diacritics
 *  - collapse whitespace
 *  - drop the leading "2026-iii (n) " or similar period+shift prefix
 *  - drop trailing modality suffixes like "(presencial)", "(mixta)", etc.
 */
function gmk_audit_normalize_name(string $name): string {
    $s = mb_strtolower(trim($name), 'UTF-8');
    // Strip diacritics (NFD decomposition + remove combining marks).
    $s = preg_replace('/\p{Mn}+/u', '', normalizer_normalize($s, Normalizer::FORM_D));
    $s = preg_replace('/\s+/', ' ', $s);
    // Drop leading period+shift prefix: "2026-iii (d) ", "2026-ii (n) ", etc.
    $s = preg_replace('/^\d{4}-[ivx]+ \([dns]\)\s*/u', '', $s);
    // Drop trailing single-letter group marker FIRST so the modality
    // parens become the actual trailing token.
    $s = preg_replace('/\s+[a-z]$/u', '', $s);
    // Drop any trailing modality / room annotations in parens.
    $s = preg_replace('/\s*\([^)]*\)\s*$/u', '', $s);
    return trim($s);
}

$filter = optional_param('filter', 'all', PARAM_ALPHA); // all | mismatched | ok
$onlyplanid = optional_param('planid', 0, PARAM_INT);

$where = ['1=1'];
$params = [];
if ($onlyplanid > 0) {
    $where[] = 'gc.learningplanid = :planid';
    $params['planid'] = $onlyplanid;
}

$sql = "SELECT gc.id AS classid,
               gc.name AS classname,
               gc.courseid,
               gc.corecourseid,
               gc.learningplanid,
               gc.approved,
               gc.closed,
               c1.fullname  AS course_fullname,
               c1.shortname AS course_shortname,
               c2.fullname  AS corecourse_fullname,
               c2.shortname AS corecourse_shortname,
               lp.name      AS plan_name,
               (SELECT COUNT(*) FROM {gmk_course_progre} cp
                 WHERE cp.classid = gc.id AND cp.status = 2) AS active_enrollments,
               (SELECT COUNT(*) FROM {gmk_class_absence_state} s
                 WHERE s.classid = gc.id) AS state_rows
          FROM {gmk_class} gc
     LEFT JOIN {course} c1 ON c1.id = gc.courseid
     LEFT JOIN {course} c2 ON c2.id = gc.corecourseid
     LEFT JOIN {local_learning_plans} lp ON lp.id = gc.learningplanid
         WHERE " . implode(' AND ', $where) . "
      ORDER BY gc.approved DESC, gc.closed ASC, gc.id DESC";

$rows = $DB->get_records_sql($sql, $params);

// Categorise.
$mismatched = [];
$matching   = [];
$nocourse   = [];
$totals     = ['classes' => 0, 'mismatched' => 0, 'matching' => 0, 'nocourse' => 0];

foreach ($rows as $r) {
    $totals['classes']++;
    $classcore = gmk_audit_normalize_name($r->classname ?? '');
    $coursename = gmk_audit_normalize_name($r->course_fullname ?? '');
    $corecore   = gmk_audit_normalize_name($r->corecourse_fullname ?? '');

    // Decide the "expected" course: the one matching the class name.
    $matchescore = ($corecore !== '' && $corecore === $classcore);
    $matchescourse = ($coursename !== '' && $coursename === $classcore);

    $r->matches_core   = $matchescore;
    $r->matches_course = $matchescourse;
    $r->norm_class     = $classcore;
    $r->norm_course    = $coursename;
    $r->norm_core      = $corecore;

    if ($matchescore && $matchescourse) {
        $matching[] = $r;
        $totals['matching']++;
    } elseif ($matchescore && !$matchescourse) {
        // corecourseid is correct, courseid is wrong.
        $mismatched[] = $r;
        $totals['mismatched']++;
    } elseif (!$matchescore && $matchescourse) {
        // courseid is correct, corecourseid is wrong.
        $mismatched[] = $r;
        $totals['mismatched']++;
    } elseif ($matchescore) {
        // both equal but class normalized differently — show in matching.
        $matching[] = $r;
        $totals['matching']++;
    } else {
        $nocourse[] = $r;
        $totals['nocourse']++;
    }
}

echo '<h2>Resumen</h2>';
echo '<ul>';
echo '<li><strong>Total clases escaneadas:</strong> ' . $totals['classes'] . '</li>';
echo '<li><span style="color:#166534"><strong>Coinciden (OK):</strong></span> ' . $totals['matching'] . '</li>';
echo '<li><span style="color:#b91c1c"><strong>courseid / corecourseid no coincide con class.name:</strong></span> ' . $totals['mismatched'] . '</li>';
echo '<li><span style="color:#92400e"><strong>Sin curso referenciado o sin match normalizado:</strong></span> ' . $totals['nocourse'] . '</li>';
echo '</ul>';

if ($filter === 'all') {
    $show = array_merge($mismatched, $nocourse, $matching);
} elseif ($filter === 'mismatched') {
    $show = $mismatched;
} elseif ($filter === 'ok') {
    $show = $matching;
} elseif ($filter === 'nocourse') {
    $show = $nocourse;
} else {
    $show = $mismatched;
}

echo '<form method="get" style="margin:1em 0">';
echo '<label>Filtro: <select name="filter" onchange="this.form.submit()">';
echo '<option value="all"' . ($filter === 'all' ? ' selected' : '') . '>Todos</option>';
echo '<option value="mismatched"' . ($filter === 'mismatched' ? ' selected' : '') . '>Solo courseid mal asignado</option>';
echo '<option value="ok"' . ($filter === 'ok' ? ' selected' : '') . '>Solo OK</option>';
echo '<option value="nocourse"' . ($filter === 'nocourse' ? ' selected' : '') . '>Solo sin match</option>';
echo '</select></label>';
echo ' &nbsp; <label>Plan: <input type="number" name="planid" value="' . (int)$onlyplanid . '" style="width:6em"></label>';
echo ' <button type="submit">Aplicar</button>';
echo '</form>';

echo '<p><a href="' . (new moodle_url('/local/grupomakro_core/cli/fix_class_courseid_mismatch.php'))->out() . '" target="_blank">'
   . 'Ver documentación del fix CLI →</a> '
   . '<code>sudo -u www-data php local/grupomakro_core/cli/fix_class_courseid_mismatch.php --dry-run</code></p>';

echo '<table border="1" cellpadding="4" cellspacing="0" style="border-collapse:collapse;font-size:13px">';
echo '<thead><tr style="background:#f3f4f6">';
echo '<th>Class ID</th>';
echo '<th>class.name</th>';
echo '<th>courseid</th>';
echo '<th>course.fullname</th>';
echo '<th>corecourseid</th>';
echo '<th>corecourse.fullname</th>';
echo '<th>Match</th>';
echo '<th>Plan</th>';
echo '<th>Aprob.</th>';
echo '<th>Cerrada</th>';
echo '<th>Cursando</th>';
echo '<th>State rows</th>';
echo '</tr></thead><tbody>';

foreach ($show as $r) {
    $status = '';
    $rowstyle = '';
    if ($r->matches_core && $r->matches_course) {
        $status = '<span style="color:#166534">OK</span>';
    } elseif ($r->matches_core && !$r->matches_course) {
        $status = '<span style="color:#b91c1c">courseid mal</span>';
        $rowstyle = 'background:#fef2f2';
    } elseif (!$r->matches_core && $r->matches_course) {
        $status = '<span style="color:#b91c1c">corecourseid mal</span>';
        $rowstyle = 'background:#fef2f2';
    } else {
        $status = '<span style="color:#92400e">sin match</span>';
        $rowstyle = 'background:#fefce8';
    }
    echo '<tr style="' . $rowstyle . '">';
    echo '<td>' . (int)$r->classid . '</td>';
    echo '<td>' . htmlspecialchars((string)$r->classname) . '<br><small style="color:#6b7280">'
       . htmlspecialchars($r->norm_class) . '</small></td>';
    echo '<td>' . ($r->courseid ? (int)$r->courseid : '<em style="color:#9ca3af">NULL</em>') . '</td>';
    echo '<td>' . htmlspecialchars((string)$r->course_fullname)
       . ($r->course_shortname ? ' <small style="color:#6b7280">[' . htmlspecialchars($r->course_shortname) . ']</small>' : '')
       . '<br><small style="color:#6b7280">' . htmlspecialchars($r->norm_course) . '</small></td>';
    echo '<td>' . ($r->corecourseid ? (int)$r->corecourseid : '<em style="color:#9ca3af">NULL</em>') . '</td>';
    echo '<td>' . htmlspecialchars((string)$r->corecourse_fullname)
       . ($r->corecourse_shortname ? ' <small style="color:#6b7280">[' . htmlspecialchars($r->corecourse_shortname) . ']</small>' : '')
       . '<br><small style="color:#6b7280">' . htmlspecialchars($r->norm_core) . '</small></td>';
    echo '<td>' . $status . '</td>';
    echo '<td>' . htmlspecialchars((string)$r->plan_name) . '</td>';
    echo '<td>' . ((int)$r->approved ? '✓' : '✗') . '</td>';
    echo '<td>' . ((int)$r->closed ? '✓' : '✗') . '</td>';
    echo '<td>' . (int)$r->active_enrollments . '</td>';
    echo '<td>' . (int)$r->state_rows . '</td>';
    echo '</tr>';
}

echo '</tbody></table>';

echo '<hr>';
echo '<h3>Notas</h3>';
echo '<ul>';
echo '<li><strong>courseid mal:</strong> el nombre de <code>gmk_class.corecourseid</code> coincide con <code>class.name</code> pero el de <code>gmk_class.courseid</code> no. La corrección es copiar <code>corecourseid</code> a <code>courseid</code>.</li>';
echo '<li><strong>corecourseid mal:</strong> al revés: el nombre de <code>courseid</code> coincide pero el de <code>corecourseid</code> no. La corrección es copiar <code>courseid</code> a <code>corecourseid</code>.</li>';
echo '<li><strong>sin match:</strong> ninguno de los dos cursos referenciados tiene un nombre que coincida (tras normalizar) con el <code>class.name</code>. Revisar manualmente.</li>';
echo '<li>La normalización quita prefijos <code>YYYY-N (X)</code> (período + jornada), sufijos <code>(PRESENCIAL|MIXTA|VIRTUAL)</code> y la letra de grupo final. El resultado es un token en minúsculas sin acentos que se compara entre las tres cadenas.</li>';
echo '</ul>';

echo $OUTPUT->footer();