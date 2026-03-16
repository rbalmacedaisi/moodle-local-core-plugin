<?php
/**
 * Debug/Fix: Orphaned course_modules
 *
 * Scans a Moodle course for course_modules rows whose activity instance
 * (attendance, bigbluebuttonbn) was deleted without the cm row being cleaned up.
 * These orphans cause "invalidrecordunknown" crashes when Moodle tries to display the course.
 *
 * Actions:
 *   search  – list courses matching the query (GET, no sesskey)
 *   inspect – show orphan diagnostics for a course (GET)
 *   fix     – delete orphaned cm rows + repair sequences + rebuild cache (POST + sesskey)
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$search   = optional_param('search', '', PARAM_TEXT);
$courseid = optional_param('courseid', 0, PARAM_INT);
$action   = optional_param('action', '', PARAM_ALPHA);

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_fix_orphaned_modules.php'));
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Fix Módulos Huérfanos');
$PAGE->set_heading('Fix Módulos Huérfanos');

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Return all course_modules that have no matching instance in their module table.
 * Only checks attendance and bigbluebuttonbn.
 *
 * @return array[] each row: cmid, course, section, modname, instance
 */
function gmk_dbgfom_find_orphans(int $courseid): array {
    global $DB;

    $orphans = [];
    if ($courseid <= 0) {
        return $orphans;
    }

    foreach (['attendance', 'bigbluebuttonbn'] as $modname) {
        $modid = $DB->get_field('modules', 'id', ['name' => $modname]);
        if (!$modid) {
            continue;
        }
        $rows = $DB->get_records_sql(
            "SELECT cm.id AS cmid, cm.course, cm.section, cm.instance, cm.deletioninprogress
               FROM {course_modules} cm
              WHERE cm.course = :courseid
                AND cm.module = :modid
                AND NOT EXISTS (SELECT 1 FROM {{$modname}} t WHERE t.id = cm.instance)",
            ['courseid' => $courseid, 'modid' => (int)$modid]
        );
        foreach ($rows as $row) {
            $orphans[] = [
                'cmid'               => (int)$row->cmid,
                'course'             => (int)$row->course,
                'section'            => (int)$row->section,
                'modname'            => $modname,
                'instance'           => (int)$row->instance,
                'deletioninprogress' => (int)$row->deletioninprogress,
            ];
        }
    }

    return $orphans;
}

/**
 * Collect sequence orphans (cmids in sequence that don't exist in course_modules).
 *
 * @return array[] each row: sectionid, sectionnum, name, orphan_cmids[]
 */
function gmk_dbgfom_find_seq_orphans(int $courseid): array {
    global $DB;

    $result = [];
    if ($courseid <= 0) {
        return $result;
    }

    $sections = $DB->get_records(
        'course_sections',
        ['course' => $courseid],
        'section ASC, id ASC',
        'id, section, name, sequence'
    );

    foreach ($sections as $sec) {
        $seq = trim((string)$sec->sequence);
        if ($seq === '') {
            continue;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $seq)))));
        if (empty($ids)) {
            continue;
        }
        list($insql, $inparams) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'cid');
        $existing = $DB->get_fieldset_sql(
            "SELECT id FROM {course_modules} WHERE id {$insql}",
            $inparams
        );
        $existing = array_map('intval', $existing);
        $orphan_cmids = array_values(array_diff($ids, $existing));
        if (!empty($orphan_cmids)) {
            $result[] = [
                'sectionid'    => (int)$sec->id,
                'sectionnum'   => (int)$sec->section,
                'name'         => (string)$sec->name,
                'orphan_cmids' => $orphan_cmids,
            ];
        }
    }

    return $result;
}

// ── Action: fix ───────────────────────────────────────────────────────────────

$fixlog     = [];
$fixstatus  = ''; // 'success' | 'error'

if ($action === 'fix' && $courseid > 0) {
    require_sesskey();

    try {
        // 1. Delete orphaned course_modules rows.
        $orphans = gmk_dbgfom_find_orphans($courseid);
        $deleted = 0;
        foreach ($orphans as $o) {
            $DB->delete_records('course_modules', ['id' => $o['cmid']]);
            $fixlog[] = "Eliminado course_modules cmid={$o['cmid']} ({$o['modname']} instance={$o['instance']})";
            $deleted++;
        }
        $fixlog[] = "course_modules huérfanos eliminados: {$deleted}";

        // 2. Repair section sequences (remove stale cmids).
        $seqrepair = gmk_prune_invalid_course_section_sequences((int)$courseid, false);
        $fixlog[] = "Reparación de sequences: secciones actualizadas={$seqrepair['updatedsections']}, cmids removidos={$seqrepair['removedcmids']}";

        // 3. Rebuild course modinfo cache.
        if (!function_exists('rebuild_course_cache')) {
            require_once($CFG->libdir . '/modinfolib.php');
        }
        rebuild_course_cache((int)$courseid, true);
        $fixlog[] = "rebuild_course_cache ejecutado.";

        $fixstatus = 'success';
    } catch (\Throwable $e) {
        $fixlog[] = "ERROR: " . $e->getMessage();
        $fixstatus = 'error';
    }
}

// ── Collect data for display ──────────────────────────────────────────────────

$courserec  = null;
$orphans    = [];
$seqorphans = [];

if ($courseid > 0) {
    $courserec  = $DB->get_record('course', ['id' => $courseid], 'id,fullname,shortname,visible', IGNORE_MISSING);
    $orphans    = gmk_dbgfom_find_orphans($courseid);
    $seqorphans = gmk_dbgfom_find_seq_orphans($courseid);
}

$searchresults = [];
if ($search !== '' && $courseid === 0) {
    $like1 = $DB->sql_like('c.fullname', ':q1', false);
    $like2 = $DB->sql_like('c.shortname', ':q2', false);
    $searchresults = $DB->get_records_sql(
        "SELECT c.id, c.fullname, c.shortname, c.visible
           FROM {course} c
          WHERE c.id <> 1 AND ({$like1} OR {$like2})
          ORDER BY c.fullname ASC
          LIMIT 40",
        ['q1' => '%' . $search . '%', 'q2' => '%' . $search . '%']
    );
}

// ── Output ────────────────────────────────────────────────────────────────────

echo $OUTPUT->header();

echo '<h2>Fix: Módulos Huérfanos en Curso</h2>';
echo '<p style="color:#555">Detecta y elimina filas <code>course_modules</code> cuya instancia (<em>attendance</em>, <em>bigbluebuttonbn</em>) ya no existe en la base de datos. '
   . 'Esto causa el error <strong>invalidrecordunknown</strong> al buscar cursos en Moodle.</p>';

// ── Search form ───────────────────────────────────────────────────────────────
echo '<form method="get" style="margin:16px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap">';
echo '<label for="fom_search" style="font-weight:bold">Buscar curso:</label>';
echo '<input type="text" id="fom_search" name="search" value="' . s($search) . '" placeholder="Nombre o shortname…" '
   . 'style="padding:6px 10px;min-width:280px;border:1px solid #ccc;border-radius:4px">';
echo '<button type="submit" class="btn btn-primary">Buscar</button>';
if ($courseid > 0) {
    echo '<a class="btn btn-secondary" href="' . (new moodle_url('/local/grupomakro_core/pages/debug_fix_orphaned_modules.php'))->out(false) . '">Nueva búsqueda</a>';
}
echo '</form>';

// ── Search results ────────────────────────────────────────────────────────────
if (!empty($searchresults)) {
    echo '<h3>Resultados (' . count($searchresults) . ')</h3>';
    echo '<table class="generaltable" style="width:auto">';
    echo '<thead><tr><th>ID</th><th>Nombre</th><th>Shortname</th><th>Visible</th><th></th></tr></thead><tbody>';
    foreach ($searchresults as $cr) {
        $inspecturl = new moodle_url(
            '/local/grupomakro_core/pages/debug_fix_orphaned_modules.php',
            ['courseid' => (int)$cr->id, 'search' => s($search)]
        );
        echo '<tr>';
        echo '<td>' . (int)$cr->id . '</td>';
        echo '<td>' . s($cr->fullname) . '</td>';
        echo '<td>' . s($cr->shortname) . '</td>';
        echo '<td>' . ((int)$cr->visible ? '✔' : '✖') . '</td>';
        echo '<td><a class="btn btn-sm btn-secondary" href="' . $inspecturl->out(false) . '">Inspeccionar</a></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}

if ($search !== '' && empty($searchresults) && $courseid === 0) {
    echo '<div class="alert alert-warning">No se encontraron cursos para <strong>' . s($search) . '</strong>.</div>';
}

// ── Course details & diagnostics ──────────────────────────────────────────────
if ($courseid > 0) {

    if (!$courserec) {
        echo '<div class="alert alert-danger">Curso no encontrado (id=' . (int)$courseid . ').</div>';
        echo $OUTPUT->footer();
        exit;
    }

    echo '<div class="alert alert-info" style="margin-top:16px">';
    echo '<strong>Curso seleccionado:</strong> [' . (int)$courserec->id . '] '
       . s($courserec->fullname) . ' &nbsp;·&nbsp; <em>' . s($courserec->shortname) . '</em>';
    echo '</div>';

    // Fix result banner.
    if (!empty($fixlog)) {
        $alertclass = $fixstatus === 'success' ? 'alert-success' : 'alert-danger';
        echo '<div class="alert ' . $alertclass . '"><ul style="margin:0">';
        foreach ($fixlog as $line) {
            echo '<li>' . s($line) . '</li>';
        }
        echo '</ul></div>';
    }

    // ── Orphaned course_modules ──────────────────────────────────────────────
    echo '<h3>Módulos Huérfanos (course_modules sin instancia)</h3>';

    if (empty($orphans)) {
        echo '<div class="alert alert-success">No se encontraron módulos huérfanos.</div>';
    } else {
        echo '<div class="alert alert-danger">Se encontraron <strong>' . count($orphans) . '</strong> módulo(s) huérfano(s). '
           . 'El curso fallará al mostrarse hasta que se reparen.</div>';

        echo '<table class="generaltable">';
        echo '<thead><tr>'
           . '<th>cmid</th><th>Módulo</th><th>instance (inexistente)</th>'
           . '<th>section</th><th>deletioninprogress</th>'
           . '</tr></thead><tbody>';
        foreach ($orphans as $o) {
            $badge = $o['deletioninprogress'] ? ' <span style="color:#e67e22">(deletion in progress)</span>' : '';
            echo '<tr>';
            echo '<td><strong>' . (int)$o['cmid'] . '</strong></td>';
            echo '<td>' . s($o['modname']) . '</td>';
            echo '<td>' . (int)$o['instance'] . '</td>';
            echo '<td>' . (int)$o['section'] . '</td>';
            echo '<td>' . (int)$o['deletioninprogress'] . $badge . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    // ── Sequence orphans ──────────────────────────────────────────────────────
    echo '<h3>CMIDs Huérfanos en Sequence de Secciones</h3>';

    if (empty($seqorphans)) {
        echo '<div class="alert alert-success">No se encontraron CMIDs huérfanos en las secuencias.</div>';
    } else {
        $total_seq = array_sum(array_map(function($r) { return count($r['orphan_cmids']); }, $seqorphans));
        echo '<div class="alert alert-warning">Se encontraron <strong>' . $total_seq . '</strong> CMID(s) en sequences que ya no existen en <code>course_modules</code>.</div>';

        echo '<table class="generaltable">';
        echo '<thead><tr><th>Section ID</th><th>Sección #</th><th>Nombre</th><th>CMIDs huérfanos</th></tr></thead><tbody>';
        foreach ($seqorphans as $sr) {
            echo '<tr>';
            echo '<td>' . (int)$sr['sectionid'] . '</td>';
            echo '<td>' . (int)$sr['sectionnum'] . '</td>';
            echo '<td>' . s($sr['name']) . '</td>';
            echo '<td>' . implode(', ', array_map('intval', $sr['orphan_cmids'])) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    // ── Fix button ────────────────────────────────────────────────────────────
    $hasIssues = !empty($orphans) || !empty($seqorphans);

    echo '<div style="margin-top:24px">';
    if ($hasIssues) {
        $fixurl = new moodle_url('/local/grupomakro_core/pages/debug_fix_orphaned_modules.php', [
            'courseid' => (int)$courseid,
            'search'   => s($search),
            'action'   => 'fix',
            'sesskey'  => sesskey(),
        ]);
        echo '<form method="post" action="' . $fixurl->out(false) . '">';
        echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
        echo '<button type="submit" class="btn btn-danger" '
           . 'onclick="return confirm(\'¿Confirmar reparación del curso ' . (int)$courseid . '? Se eliminarán los módulos huérfanos y se repararán las sequences.\')">'
           . '⚠ Reparar curso'
           . '</button>';
        echo ' <span style="color:#888;font-size:13px">Elimina cmids huérfanos → repara sequences → rebuild_course_cache</span>';
        echo '</form>';
    } else {
        echo '<div class="alert alert-success"><strong>El curso no tiene problemas. No se requiere reparación.</strong></div>';
    }
    echo '</div>';
}

echo $OUTPUT->footer();
