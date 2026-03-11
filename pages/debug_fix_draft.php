<?php
$config_path = __DIR__ . '/../../config.php';
if (!file_exists($config_path)) $config_path = __DIR__ . '/../../../config.php';
if (!file_exists($config_path)) $config_path = __DIR__ . '/../../../../config.php';
require_once($config_path);
require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url('/local/grupomakro_core/pages/debug_fix_draft.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Fix Draft Duplicados');
echo $OUTPUT->header();

$action      = optional_param('action', '', PARAM_ALPHA);
$periodid    = optional_param('periodid', 0, PARAM_INT);
$fixclassid  = optional_param('fixclassid', 0, PARAM_INT);
$fixtopid    = optional_param('fixtopid', 0, PARAM_INT);

echo '<h2>Fix: Limpiar Draft de Periodo</h2>';
echo '<p style="font-family:sans-serif;color:#555;">Borra el draft guardado de un periodo para que el tablero de planificación muestre solo las clases publicadas en BD, sin duplicados.</p>';

// ── Listar periodos ────────────────────────────────────────────────────────────
$periods = $DB->get_records_sql(
    "SELECT id, name,
            CASE WHEN draft_schedules IS NOT NULL AND draft_schedules != '' AND draft_schedules != '[]'
                 THEN 1 ELSE 0 END AS has_draft,
            LENGTH(draft_schedules) AS draft_len
     FROM {gmk_academic_periods}
     ORDER BY id DESC"
);

// ── Handle clear action ────────────────────────────────────────────────────────
$message = '';
if ($action === 'clear' && $periodid > 0 && confirm_sesskey()) {
    $period = $DB->get_record('gmk_academic_periods', ['id' => $periodid]);
    if ($period) {
        $DB->set_field('gmk_academic_periods', 'draft_schedules', null, ['id' => $periodid]);
        $message = '<p style="background:#e6f4ea;border:1px solid #34a853;padding:10px 16px;border-radius:4px;font-family:sans-serif;">
            ✅ Draft del periodo <b>' . htmlspecialchars($period->name) . '</b> (id=' . $periodid . ') borrado correctamente.
            El tablero ya no mostrará duplicados.
        </p>';
        // Reload periods list
        $periods = $DB->get_records_sql(
            "SELECT id, name,
                    CASE WHEN draft_schedules IS NOT NULL AND draft_schedules != '' AND draft_schedules != '[]'
                         THEN 1 ELSE 0 END AS has_draft,
                    LENGTH(draft_schedules) AS draft_len
             FROM {gmk_academic_periods}
             ORDER BY id DESC"
        );
    } else {
        $message = '<p style="background:#fce8e6;border:1px solid #d93025;padding:10px 16px;border-radius:4px;font-family:sans-serif;">❌ Periodo no encontrado.</p>';
    }
}

// ── Handle fix class periodid action ──────────────────────────────────────────
if ($action === 'fixclass' && $fixclassid > 0 && $fixtopid > 0 && confirm_sesskey()) {
    $cls = $DB->get_record('gmk_class', ['id' => $fixclassid]);
    $toPeriod = $DB->get_record('gmk_academic_periods', ['id' => $fixtopid]);
    if ($cls && $toPeriod) {
        $DB->set_field('gmk_class', 'periodid', $fixtopid, ['id' => $fixclassid]);
        $message = '<p style="background:#e6f4ea;border:1px solid #34a853;padding:10px 16px;border-radius:4px;font-family:sans-serif;">
            ✅ Clase <b>id='.$fixclassid.'</b> movida al periodo <b>'.htmlspecialchars($toPeriod->name).'</b> (id='.$fixtopid.').
        </p>';
    } else {
        $message = '<p style="background:#fce8e6;border:1px solid #d93025;padding:10px 16px;border-radius:4px;font-family:sans-serif;">❌ Clase o periodo no encontrado.</p>';
    }
}

if ($message) echo $message;

// ── Section: Fix clases con periodid incorrecto ────────────────────────────────
echo '<h2 style="margin-top:32px;">Fix: Clases con periodid incorrecto</h2>';
echo '<p style="font-family:sans-serif;color:#555;">Clases que tienen un nombre de otro periodo (p.ej. "2026-I ...") pero están guardadas con un periodid diferente. Muévelas al periodo correcto.</p>';

$allPeriods = $DB->get_records_sql("SELECT id, name FROM {gmk_academic_periods} ORDER BY id DESC");
$periodOptions = '';
foreach ($allPeriods as $ap) {
    $periodOptions .= '<option value="'.$ap->id.'">'.htmlspecialchars($ap->name).' (id='.$ap->id.')</option>';
}

$allClasses = $DB->get_records_sql(
    "SELECT c.id, c.name, c.periodid, ap.name as period_name
     FROM {gmk_class} c
     LEFT JOIN {gmk_academic_periods} ap ON ap.id = c.periodid
     ORDER BY c.periodid DESC, c.id ASC"
);

echo '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse;font-family:monospace;font-size:12px;margin-top:8px;">';
echo '<tr style="background:#1a73e8;color:white;">
    <th>id</th><th>name</th><th>periodid actual</th><th>Mover a periodo</th>
</tr>';
foreach ($allClasses as $c) {
    $fixUrl = new moodle_url('/local/grupomakro_core/pages/debug_fix_draft.php', ['sesskey' => sesskey()]);
    echo '<tr>
        <td>'.(int)$c->id.'</td>
        <td>'.htmlspecialchars($c->name).'</td>
        <td>'.htmlspecialchars($c->period_name ?: $c->periodid).' (id='.(int)$c->periodid.')</td>
        <td>
            <form method="get" action="'.$fixUrl.'" style="display:inline-flex;gap:4px;align-items:center;">
                <input type="hidden" name="action" value="fixclass">
                <input type="hidden" name="fixclassid" value="'.(int)$c->id.'">
                <input type="hidden" name="sesskey" value="'.sesskey().'">
                <select name="fixtopid" style="font-size:11px;padding:2px;">
                    '.$periodOptions.'
                </select>
                <button type="submit" style="background:#1a73e8;color:white;border:none;padding:3px 8px;border-radius:3px;cursor:pointer;font-size:11px;">Mover</button>
            </form>
        </td>
    </tr>';
}
echo '</table>';

// ── Table ──────────────────────────────────────────────────────────────────────
echo '<table border="1" cellpadding="8" cellspacing="0" style="border-collapse:collapse;font-family:monospace;font-size:13px;margin-top:16px;">';
echo '<tr style="background:#1a73e8;color:white;">
    <th>ID</th>
    <th>Nombre</th>
    <th>¿Tiene draft?</th>
    <th>Tamaño draft</th>
    <th>Acción</th>
</tr>';

foreach ($periods as $p) {
    $hasDraft   = (int)$p->has_draft;
    $draftLen   = $p->draft_len ? number_format((int)$p->draft_len) . ' chars' : '-';
    $draftLabel = $hasDraft
        ? '<span style="color:#d93025;font-weight:bold;">⚠ Sí</span>'
        : '<span style="color:#34a853;">✅ Limpio</span>';

    $clearUrl = new moodle_url('/local/grupomakro_core/pages/debug_fix_draft.php', [
        'action'   => 'clear',
        'periodid' => $p->id,
        'sesskey'  => sesskey(),
    ]);

    $btn = $hasDraft
        ? '<a href="' . $clearUrl . '" onclick="return confirm(\'¿Borrar el draft de ' . htmlspecialchars(addslashes($p->name)) . '?\');"
              style="background:#d93025;color:white;padding:4px 12px;border-radius:4px;text-decoration:none;font-family:sans-serif;font-size:12px;">
              Limpiar Draft
           </a>'
        : '<span style="color:#999;font-family:sans-serif;font-size:12px;">Sin acción</span>';

    echo '<tr style="' . ($hasDraft ? 'background:#fff8f0;' : '') . '">
        <td>' . (int)$p->id . '</td>
        <td>' . htmlspecialchars($p->name) . '</td>
        <td style="text-align:center;">' . $draftLabel . '</td>
        <td style="text-align:right;">' . $draftLen . '</td>
        <td>' . $btn . '</td>
    </tr>';
}

echo '</table>';

echo $OUTPUT->footer();
