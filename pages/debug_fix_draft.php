<?php
$config_path = __DIR__ . '/../../config.php';
if (!file_exists($config_path)) $config_path = __DIR__ . '/../../../config.php';
if (!file_exists($config_path)) $config_path = __DIR__ . '/../../../../config.php';
require_once($config_path);
require_login();
require_capability('moodle/site:config', context_system::instance());

$action     = optional_param('action',    '', PARAM_ALPHA);
$periodid   = optional_param('periodid',   0, PARAM_INT);
$fixclassid = optional_param('fixclassid', 0, PARAM_INT);
$fixtopid   = optional_param('fixtopid',   0, PARAM_INT);

// ── Download draft (before any output) ────────────────────────────────────────
if ($action === 'download' && $periodid > 0 && confirm_sesskey()) {
    $period = $DB->get_record('gmk_academic_periods', ['id' => $periodid]);
    $draft  = $DB->get_field('gmk_academic_periods', 'draft_schedules', ['id' => $periodid]);
    if ($period && $draft) {
        $filename = 'draft_' . preg_replace('/[^a-z0-9_-]/i', '_', $period->name) . '_' . date('Ymd_His') . '.json';
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($draft));
        echo $draft;
        exit;
    }
    // If no draft, fall through to normal page
}

$PAGE->set_url('/local/grupomakro_core/pages/debug_fix_draft.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Fix Draft / Horarios');
echo $OUTPUT->header();

// ── Reload periods helper ──────────────────────────────────────────────────────
function load_periods_list($DB) {
    return $DB->get_records_sql(
        "SELECT id, name,
                CASE WHEN draft_schedules IS NOT NULL AND draft_schedules != '' AND draft_schedules != '[]'
                     THEN 1 ELSE 0 END AS has_draft,
                LENGTH(draft_schedules) AS draft_len
         FROM {gmk_academic_periods}
         ORDER BY id DESC"
    );
}

$periods = load_periods_list($DB);
$message = '';

// ── Handle clear draft ─────────────────────────────────────────────────────────
if ($action === 'clear' && $periodid > 0 && confirm_sesskey()) {
    $period = $DB->get_record('gmk_academic_periods', ['id' => $periodid]);
    if ($period) {
        $DB->set_field('gmk_academic_periods', 'draft_schedules', null, ['id' => $periodid]);
        $message = '<p class="msg-ok">✅ Draft del periodo <b>' . s($period->name) . '</b> (id=' . $periodid . ') borrado.</p>';
        $periods = load_periods_list($DB);
    } else {
        $message = '<p class="msg-err">❌ Periodo no encontrado.</p>';
    }
}

// ── Handle import draft ────────────────────────────────────────────────────────
if ($action === 'import' && $periodid > 0 && confirm_sesskey()) {
    $period = $DB->get_record('gmk_academic_periods', ['id' => $periodid]);
    $uploaded = $_FILES['draftfile'] ?? null;
    if ($period && $uploaded && $uploaded['error'] === UPLOAD_ERR_OK) {
        $content = file_get_contents($uploaded['tmp_name']);
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            $DB->set_field('gmk_academic_periods', 'draft_schedules', $content, ['id' => $periodid]);
            $message = '<p class="msg-ok">✅ Draft importado para <b>' . s($period->name) . '</b> (' . count($decoded) . ' clases). Recarga el tablero.</p>';
            $periods = load_periods_list($DB);
        } else {
            $message = '<p class="msg-err">❌ El archivo no es un JSON válido.</p>';
        }
    } else {
        $message = '<p class="msg-err">❌ Error al subir archivo o periodo no encontrado.</p>';
    }
}

// ── Handle fix class periodid ──────────────────────────────────────────────────
if ($action === 'fixclass' && $fixclassid > 0 && $fixtopid > 0 && confirm_sesskey()) {
    $cls      = $DB->get_record('gmk_class', ['id' => $fixclassid]);
    $toPeriod = $DB->get_record('gmk_academic_periods', ['id' => $fixtopid]);
    if ($cls && $toPeriod) {
        $DB->set_field('gmk_class', 'periodid', $fixtopid, ['id' => $fixclassid]);
        $message = '<p class="msg-ok">✅ Clase id=' . $fixclassid . ' movida al periodo <b>' . s($toPeriod->name) . '</b>.</p>';
    } else {
        $message = '<p class="msg-err">❌ Clase o periodo no encontrado.</p>';
    }
}

?>
<style>
  body { font-family: sans-serif; }
  .msg-ok  { background:#e6f4ea; border:1px solid #34a853; padding:10px 16px; border-radius:4px; margin:8px 0; }
  .msg-err { background:#fce8e6; border:1px solid #d93025; padding:10px 16px; border-radius:4px; margin:8px 0; }
  table { border-collapse:collapse; font-size:13px; margin-top:8px; }
  th { background:#1a73e8; color:white; padding:6px 10px; }
  td { padding:5px 10px; border:1px solid #ccc; }
  tr:hover td { background:#f5f5f5; }
  .btn-red  { background:#d93025; color:white; padding:4px 10px; border-radius:4px; text-decoration:none; font-size:12px; border:none; cursor:pointer; }
  .btn-blue { background:#1a73e8; color:white; padding:4px 10px; border-radius:4px; text-decoration:none; font-size:12px; border:none; cursor:pointer; }
  .btn-green{ background:#34a853; color:white; padding:4px 10px; border-radius:4px; text-decoration:none; font-size:12px; border:none; cursor:pointer; }
  section { margin-top:36px; }
  h2 { border-bottom:2px solid #1a73e8; padding-bottom:4px; }
</style>

<?php if ($message) echo $message; ?>

<!-- ══ SECTION 1: Draft backup ════════════════════════════════════════════════ -->
<section>
<h2>1. Backup y restauración de Draft</h2>
<p>Descarga el draft de un periodo como archivo JSON. Si algo sale mal, impórtalo de nuevo.</p>
<table>
<tr>
  <th>ID</th><th>Periodo</th><th>¿Tiene draft?</th><th>Tamaño</th><th>Descargar</th><th>Importar</th><th>Limpiar</th>
</tr>
<?php foreach ($periods as $p):
    $hasDraft = (int)$p->has_draft;
    $draftLen = $p->draft_len ? number_format((int)$p->draft_len) . ' chars' : '-';
    $dlUrl    = new moodle_url('/local/grupomakro_core/pages/debug_fix_draft.php', [
        'action' => 'download', 'periodid' => $p->id, 'sesskey' => sesskey()
    ]);
    $clearUrl = new moodle_url('/local/grupomakro_core/pages/debug_fix_draft.php', [
        'action' => 'clear', 'periodid' => $p->id, 'sesskey' => sesskey()
    ]);
    $importUrl = new moodle_url('/local/grupomakro_core/pages/debug_fix_draft.php', [
        'action' => 'import', 'periodid' => $p->id, 'sesskey' => sesskey()
    ]);
?>
<tr style="<?= $hasDraft ? 'background:#fffde7' : '' ?>">
  <td><?= (int)$p->id ?></td>
  <td><?= s($p->name) ?></td>
  <td style="text-align:center"><?= $hasDraft
      ? '<b style="color:#d93025">⚠ Sí</b>'
      : '<span style="color:#34a853">✅ Limpio</span>' ?></td>
  <td style="text-align:right"><?= $draftLen ?></td>
  <td>
    <?php if ($hasDraft): ?>
      <a href="<?= $dlUrl ?>" class="btn-blue">⬇ Descargar JSON</a>
    <?php else: ?>
      <span style="color:#999">Sin draft</span>
    <?php endif ?>
  </td>
  <td>
    <form method="post" action="<?= $importUrl ?>" enctype="multipart/form-data" style="display:inline-flex;gap:4px;align-items:center;">
      <input type="hidden" name="sesskey" value="<?= sesskey() ?>">
      <input type="file" name="draftfile" accept=".json" style="font-size:11px;" required>
      <button type="submit" class="btn-green" onclick="return confirm('¿Importar draft para <?= s(addslashes($p->name)) ?>?')">⬆ Importar</button>
    </form>
  </td>
  <td>
    <?php if ($hasDraft): ?>
      <a href="<?= $clearUrl ?>" class="btn-red"
         onclick="return confirm('¿Borrar draft de <?= s(addslashes($p->name)) ?>?')">🗑 Limpiar</a>
    <?php else: ?>
      <span style="color:#999">—</span>
    <?php endif ?>
  </td>
</tr>
<?php endforeach ?>
</table>
</section>

<!-- ══ SECTION 2: Fix periodid de clases ══════════════════════════════════════ -->
<section>
<h2>2. Fix: Clases con periodid incorrecto</h2>
<p>Mueve clases al periodo correcto si fueron publicadas con el periodo equivocado.</p>
<?php
$allPeriods = $DB->get_records_sql("SELECT id, name FROM {gmk_academic_periods} ORDER BY id DESC");
$periodOptions = '';
foreach ($allPeriods as $ap) {
    $periodOptions .= '<option value="' . $ap->id . '">' . s($ap->name) . ' (id=' . $ap->id . ')</option>';
}
$allClasses = $DB->get_records_sql(
    "SELECT c.id, c.name, c.periodid, ap.name as period_name
     FROM {gmk_class} c
     LEFT JOIN {gmk_academic_periods} ap ON ap.id = c.periodid
     ORDER BY c.periodid ASC, c.id ASC"
);
?>
<table>
<tr><th>id</th><th>name</th><th>Periodo actual</th><th>Mover a</th></tr>
<?php foreach ($allClasses as $c):
    $formUrl = new moodle_url('/local/grupomakro_core/pages/debug_fix_draft.php');
?>
<tr>
  <td><?= (int)$c->id ?></td>
  <td><?= s($c->name) ?></td>
  <td><?= s($c->period_name ?: '?') ?> (id=<?= (int)$c->periodid ?>)</td>
  <td>
    <form method="get" action="<?= $formUrl ?>" style="display:inline-flex;gap:4px;align-items:center;">
      <input type="hidden" name="action"     value="fixclass">
      <input type="hidden" name="fixclassid" value="<?= (int)$c->id ?>">
      <input type="hidden" name="sesskey"    value="<?= sesskey() ?>">
      <select name="fixtopid" style="font-size:11px;padding:2px;"><?= $periodOptions ?></select>
      <button type="submit" class="btn-blue">Mover</button>
    </form>
  </td>
</tr>
<?php endforeach ?>
</table>
</section>

<?php echo $OUTPUT->footer();
