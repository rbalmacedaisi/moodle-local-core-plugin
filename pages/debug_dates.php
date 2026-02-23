<?php
require_once('../../../config.php');
require_login();
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/local/grupomakro_core/pages/debug_dates.php');
echo $OUTPUT->header();
?>
<script>
    const dateStr = '2026-06-30';
    const d = new Date(dateStr);
    console.log("Original string:", dateStr);
    console.log("Parsed Date object:", d);
    console.log("Local year-month-day:", d.getFullYear(), d.getMonth()+1, d.getDate());
    console.log("UTC year-month-day:", d.getUTCFullYear(), d.getUTCMonth()+1, d.getUTCDate());

    // Fix for timezone shift:
    const parts = dateStr.split('-');
    const fixedD = new Date(parts[0], parts[1] - 1, parts[2]);
    console.log("Fixed Date object:", fixedD);
    console.log("Fixed Local year-month-day:", fixedD.getFullYear(), fixedD.getMonth()+1, fixedD.getDate());
</script>
<h2>Revisa la consola de tu navegador</h2>
<?php
echo $OUTPUT->footer();
