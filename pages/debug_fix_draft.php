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

$action   = optional_param('action', '', PARAM_ALPHA);
$periodid = optional_param('periodid', 0, PARAM_INT);

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

if ($message) echo $message;

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
