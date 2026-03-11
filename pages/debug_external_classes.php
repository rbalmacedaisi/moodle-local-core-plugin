<?php
$config_path = __DIR__ . '/../../config.php';
if (!file_exists($config_path)) $config_path = __DIR__ . '/../../../config.php';
if (!file_exists($config_path)) $config_path = __DIR__ . '/../../../../config.php';
require_once($config_path);
require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url('/local/grupomakro_core/pages/debug_external_classes.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Debug External Classes');
echo $OUTPUT->header();

$periodid = optional_param('periodid', 0, PARAM_INT);

echo '<h2>Debug: Clases Externas</h2>';

// Period selector
$periods = $DB->get_records_sql("SELECT id, name FROM {gmk_academic_periods} ORDER BY id DESC");
echo '<form method="get" style="margin-bottom:16px;font-family:sans-serif;">';
echo '<b>Periodo activo:</b> <select name="periodid" onchange="this.form.submit()">';
echo '<option value="0">-- Selecciona --</option>';
foreach ($periods as $p) {
    $sel = ($p->id == $periodid) ? 'selected' : '';
    echo '<option value="'.$p->id.'" '.$sel.'>'.htmlspecialchars($p->name).' (id='.$p->id.')</option>';
}
echo '</select></form>';

if (!$periodid) { echo $OUTPUT->footer(); exit; }

$period = $DB->get_record('gmk_academic_periods', ['id' => $periodid]);
echo '<p><b>Periodo seleccionado:</b> '.htmlspecialchars($period->name)
    .' | startdate='.userdate($period->startdate)
    .' | enddate='.userdate($period->enddate).'</p>';

// All classes with their periodid and overlap check
$classes = $DB->get_records_sql(
    "SELECT c.id, c.name, c.periodid as inst_periodid,
            ap.name as period_name,
            c.initdate, c.enddate,
            CASE WHEN c.periodid = :pid THEN 'PROPIO'
                 WHEN c.periodid != :pid2 AND c.initdate <= :enddate AND c.enddate >= :startdate THEN 'EXTERNO (solapado)'
                 ELSE 'OTRO (no solapado)'
            END as clasificacion
     FROM {gmk_class} c
     LEFT JOIN {gmk_academic_periods} ap ON ap.id = c.periodid
     WHERE c.periodid = :pid3
        OR (c.periodid != :pid4 AND c.initdate <= :enddate2 AND c.enddate >= :startdate2)
     ORDER BY c.periodid ASC, c.id ASC",
    [
        'pid'        => $periodid,
        'pid2'       => $periodid,
        'pid3'       => $periodid,
        'pid4'       => $periodid,
        'startdate'  => $period->startdate,
        'enddate'    => $period->enddate,
        'startdate2' => $period->startdate,
        'enddate2'   => $period->enddate,
    ]
);

echo '<p><b>Total clases encontradas:</b> '.count($classes).'</p>';

echo '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse;font-family:monospace;font-size:12px;">';
echo '<tr style="background:#1a73e8;color:white;">
    <th>id</th><th>name</th><th>inst_periodid</th><th>period_name</th>
    <th>initdate</th><th>enddate</th><th>clasificacion</th><th>finalPeriodId (frontend)</th><th>isExternal?</th>
</tr>';

foreach ($classes as $c) {
    $instPid = (int)$c->inst_periodid;
    // Replicate backend logic: finalPeriodId = inst_periodid OR academic_period_id fallback
    $finalPeriodId = $instPid; // con el fix aplicado
    $isExternal = ($finalPeriodId !== $periodid && $finalPeriodId !== 0) ? 'YES' : 'NO';
    $color = $isExternal === 'YES' ? '#fff3e0' : '#e8f5e9';
    $clasif = htmlspecialchars($c->clasificacion);

    echo '<tr style="background:'.$color.';">
        <td>'.$c->id.'</td>
        <td>'.htmlspecialchars($c->name).'</td>
        <td>'.$instPid.'</td>
        <td>'.htmlspecialchars($c->period_name ?: '?').'</td>
        <td>'.($c->initdate ? userdate($c->initdate, '%d/%m/%Y') : '-').'</td>
        <td>'.($c->enddate  ? userdate($c->enddate,  '%d/%m/%Y') : '-').'</td>
        <td>'.$clasif.'</td>
        <td>'.$finalPeriodId.'</td>
        <td style="font-weight:bold;color:'.($isExternal==='YES'?'#e65100':'#2e7d32').'">'.$isExternal.'</td>
    </tr>';
}
echo '</table>';

echo $OUTPUT->footer();
