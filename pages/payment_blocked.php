<?php
/**
 * Payment blocked page.
 *
 * Displayed when a student in "mora" opens a Moodle course page directly
 * (lms.isi.edu.pa/course/view.php or /mod/...). The redirect comes from
 * local_grupomakro_core_guard_financial_block() in lib.php; the LXP applies
 * the same rule through the Express proxy and its /restriccion-pago page.
 *
 * "Ya pague, verificar" refreshes gmk_financial_status for this student
 * against the proxy and sends them back to the course when they are clear.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

require_login();

$courseid = optional_param('courseid', 0, PARAM_INT);
$verify = optional_param('verify', 0, PARAM_BOOL);

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/payment_blocked.php', ['courseid' => $courseid]));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Acceso restringido');
$PAGE->set_heading('Acceso restringido');
$PAGE->set_pagelayout('standard');

$verifymessage = '';
if ($verify && confirm_sesskey()) {
    // Throttle: the refresh calls Odoo through the proxy.
    $last = $SESSION->gmk_financial_verify_at ?? 0;
    if (time() - $last < 60) {
        $verifymessage = 'Espera un minuto antes de volver a verificar.';
    } else {
        $SESSION->gmk_financial_verify_at = time();
        try {
            local_grupomakro_sync_financial_status([$USER->id]);
        } catch (\Throwable $e) {
            debugging('payment_blocked refresh failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
        unset($SESSION->gmk_financial_block);
        $status = $DB->get_field('gmk_financial_status', 'status', ['userid' => $USER->id], IGNORE_MULTIPLE);
        if ($status !== 'mora') {
            $target = $courseid > SITEID ? new moodle_url('/course/view.php', ['id' => $courseid]) : new moodle_url('/');
            redirect($target, 'Tu estado de pago está al día. Ya puedes continuar.', null, \core\output\notification::NOTIFY_SUCCESS);
        }
        $verifymessage = 'Todavía aparecen facturas vencidas. Si acabas de pagar, el pago puede tardar unos minutos en reflejarse.';
    }
}

$lxpurl = 'https://students.isi.edu.pa/restriccion-pago?motivo=mora';
$verifyurl = new moodle_url('/local/grupomakro_core/pages/payment_blocked.php',
    ['courseid' => $courseid, 'verify' => 1, 'sesskey' => sesskey()]);

echo $OUTPUT->header();
?>
<div class="card mx-auto my-4" style="max-width:640px;">
    <div class="card-body text-center p-4">
        <h3 class="mb-3">Tienes facturas vencidas</h3>
        <p class="mb-4">
            Para acceder a los contenidos de tus cursos debes regularizar tu situación de pago.
            Puedes ver el detalle de tus facturas y pagarlas desde el portal del estudiante.
        </p>
        <?php if ($verifymessage !== '') : ?>
            <div class="alert alert-warning"><?php echo s($verifymessage); ?></div>
        <?php endif; ?>
        <a class="btn btn-primary m-1" href="<?php echo s($lxpurl); ?>">Ver mis facturas y pagar</a>
        <a class="btn btn-secondary m-1" href="<?php echo $verifyurl->out(); ?>">Ya pagué, verificar</a>
    </div>
</div>
<?php
echo $OUTPUT->footer();
