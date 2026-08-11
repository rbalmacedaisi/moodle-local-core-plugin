<?php
/**
 * Dead-letter queue de webhooks financieros fallidos.
 *
 * Lista las entregas del endpoint pages/financial_webhook.php que no
 * pudieron procesarse (HMAC ok pero sync a Odoo fallo, o quedo en DLQ por
 * 3+ reintentos). Permite al admin:
 *
 *   - Ver las ultimas N filas pendientes / resueltas / abandonadas.
 *   - Reintentar una fila individual (?action=retry&id=X).
 *   - Marcar como resuelta manualmente (?action=resolve&id=X).
 *   - Reintentar todas las pendientes (?action=retry_all).
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Grupomakro
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$action  = optional_param('action', '', PARAM_ALPHA);
$dlqid   = optional_param('id', 0, PARAM_INT);
$state   = optional_param('state', 'pending', PARAM_ALPHA);
$page    = max(0, optional_param('page', 0, PARAM_INT));
$perpage = 50;

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/financial_webhook_dlq.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('admin');
$PAGE->set_title('DLQ — Webhooks financieros');
$PAGE->set_heading('DLQ — Webhooks financieros');

$message = '';
$error   = '';

// --- Helpers --------------------------------------------------------------
function fwhd_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function fwhd_relative_time(int $ts): string {
    $delta = time() - $ts;
    if ($delta < 0)         return 'en el futuro';
    if ($delta < 60)        return 'hace ' . $delta . 's';
    if ($delta < 3600)      return 'hace ' . intdiv($delta, 60) . ' min';
    if ($delta < 86400)     return 'hace ' . intdiv($delta, 3600) . ' h';
    return 'hace ' . intdiv($delta, 86400) . ' d';
}

// --- Acciones POST --------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    if ($action === 'retry' && $dlqid > 0) {
        $row = $DB->get_record('gmk_financial_webhook_dlq', ['id' => $dlqid], '*', MUST_EXIST);
        if ($row->state !== 'pending') {
            $error = 'La fila no esta en estado pendiente.';
        } else {
            $payload   = json_decode($row->payload, true) ?: [];
            $signature = (string)($row->signature ?? '');
            $secret    = (string)get_config('local_grupomakro_core', 'financial_webhook_secret');
            if ($secret === '') {
                $secret = 'gmk_payment_invalidate_2026';
            }
            // Reutilizamos el mismo helper del endpoint principal via inclusion
            // simbolica: cargamos el codigo del endpoint y llamamos su handler.
            // Para mantener el codigo DRY y evitar duplicar la logica, lo
            // hacemos via una llamada HTTP interna al endpoint en modo retry.
            $url = new moodle_url('/local/grupomakro_core/pages/financial_webhook.php',
                ['action' => 'retry', 'id' => $dlqid]);
            require_once($CFG->libdir . '/filelib.php');
            $ch = curl_init($url->out(false));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_COOKIE, 'MoodleSession=' . session_id());
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $resp = curl_exec($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $decoded = json_decode($resp, true);
            if ($httpcode >= 200 && $httpcode < 300 && !empty($decoded['success'])) {
                $message = 'Reintento procesado: ' . ($decoded['new_state'] ?? '?');
            } else {
                $error = 'Fallo el reintento: ' . ($decoded['error'] ?? ('HTTP ' . $httpcode));
            }
        }
    } else if ($action === 'resolve' && $dlqid > 0) {
        $row = $DB->get_record('gmk_financial_webhook_dlq', ['id' => $dlqid], '*', MUST_EXIST);
        $row->state = 'resolved';
        $row->last_error = 'manualmente resuelto desde DLQ';
        $row->last_received_at = time();
        $DB->update_record('gmk_financial_webhook_dlq', $row);
        $message = 'Fila marcada como resuelta.';
    } else if ($action === 'retry_all') {
        $pending = $DB->get_records('gmk_financial_webhook_dlq',
            ['state' => 'pending'], 'id ASC', 'id', 0, 200);
        $ok = 0; $fail = 0;
        foreach ($pending as $row) {
            $url = new moodle_url('/local/grupomakro_core/pages/financial_webhook.php',
                ['action' => 'retry', 'id' => $row->id]);
            require_once($CFG->libdir . '/filelib.php');
            $ch = curl_init($url->out(false));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_COOKIE, 'MoodleSession=' . session_id());
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            $resp = curl_exec($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $decoded = json_decode($resp, true);
            if ($httpcode >= 200 && $httpcode < 300 && !empty($decoded['success'])) {
                $ok++;
            } else {
                $fail++;
            }
            usleep(200000); // 200ms entre reintentos
        }
        $message = "Reintento masivo: $ok ok, $fail fallos.";
    }
}

// --- Datos ----------------------------------------------------------------
$statspending  = (int)$DB->count_records('gmk_financial_webhook_dlq', ['state' => 'pending']);
$statsresolved = (int)$DB->count_records('gmk_financial_webhook_dlq', ['state' => 'resolved']);
$statsabandoned = (int)$DB->count_records('gmk_financial_webhook_dlq', ['state' => 'abandoned']);

$allowedstates = ['pending', 'resolved', 'abandoned'];
if (!in_array($state, $allowedstates, true)) {
    $state = 'pending';
}
$total = (int)$DB->count_records('gmk_financial_webhook_dlq', ['state' => $state]);
$rows  = $DB->get_records('gmk_financial_webhook_dlq',
    ['state' => $state], 'last_received_at DESC, id DESC', '*',
    $page * $perpage, $perpage);

echo $OUTPUT->header();
?>
<style>
.fwhd-wrap { max-width: 1300px; margin: 0 auto; padding: 16px; font-family: system-ui, sans-serif; }
.fwhd-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 16px; }
.fwhd-stat { background: #fff; border: 1px solid #dee2e6; border-radius: 8px; padding: 14px; text-align: center; }
.fwhd-stat .n { font-size: 1.8rem; font-weight: 700; }
.fwhd-stat.pending  .n { color: #c62828; }
.fwhd-stat.resolved .n { color: #2e7d32; }
.fwhd-stat.abandoned .n { color: #555; }
.fwhd-stat .l { font-size: 12px; color: #777; text-transform: uppercase; letter-spacing: .04em; }
.fwhd-tabs { margin-bottom: 12px; display: flex; gap: 8px; }
.fwhd-tabs a { padding: 6px 14px; border-radius: 6px; border: 1px solid #ccc; text-decoration: none; color: #333; font-size: 13px; }
.fwhd-tabs a.active { background: #1976d2; color: #fff; border-color: #1976d2; }
.fwhd-table { width: 100%; border-collapse: collapse; font-size: 13px; background: #fff; }
.fwhd-table th { background: #f0f4f8; padding: 8px 10px; text-align: left; font-weight: 700; font-size: 11px; text-transform: uppercase; }
.fwhd-table td { padding: 8px 10px; border-top: 1px solid #f0f4f8; vertical-align: middle; }
.fwhd-table tr:hover td { background: #fafbfc; }
.fwhd-err { color: #c62828; font-size: 12px; max-width: 360px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.fwhd-btn { padding: 5px 12px; border-radius: 5px; border: none; cursor: pointer; font-size: 12px; font-weight: 600; }
.fwhd-btn.retry { background: #1976d2; color: #fff; }
.fwhd-btn.resolve { background: #2e7d32; color: #fff; }
.fwhd-btn.bulk { background: #fb8c00; color: #fff; }
.fwhd-msg { background: #e8f5e9; border-left: 4px solid #2e7d32; padding: 10px 14px; border-radius: 4px; margin-bottom: 12px; }
.fwhd-err-banner { background: #ffebee; border-left: 4px solid #c62828; padding: 10px 14px; border-radius: 4px; margin-bottom: 12px; }
</style>
<div class="fwhd-wrap">

<div class="fwhd-stats">
    <div class="fwhd-stat pending">
        <div class="n"><?php echo $statspending; ?></div>
        <div class="l">Pendientes</div>
    </div>
    <div class="fwhd-stat resolved">
        <div class="n"><?php echo $statsresolved; ?></div>
        <div class="l">Resueltos</div>
    </div>
    <div class="fwhd-stat abandoned">
        <div class="n"><?php echo $statsabandoned; ?></div>
        <div class="l">Abandonados</div>
    </div>
</div>

<?php if ($message): ?>
    <div class="fwhd-msg"><?php echo fwhd_h($message); ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="fwhd-err-banner"><?php echo fwhd_h($error); ?></div>
<?php endif; ?>

<div class="fwhd-tabs">
    <?php foreach ($allowedstates as $s): ?>
        <a href="?state=<?php echo $s; ?>"
           class="<?php echo $state === $s ? 'active' : ''; ?>">
            <?php echo ucfirst($s); ?>
            (<?php
            echo $s === 'pending'   ? $statspending :
                ($s === 'resolved'  ? $statsresolved :
                $statsabandoned);
            ?>)
        </a>
    <?php endforeach; ?>
    <?php if ($state === 'pending' && $statspending > 0): ?>
        <form method="post" style="margin-left:auto;display:inline">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="action" value="retry_all">
            <button type="submit" class="fwhd-btn bulk"
                    onclick="return confirm('Reintentar TODAS las pendientes (<?php echo $statspending; ?>)?')">
                Reintentar todas
            </button>
        </form>
    <?php endif; ?>
</div>

<?php if (empty($rows)): ?>
    <div class="alert alert-info">No hay filas en estado <b><?php echo fwhd_h($state); ?></b>.</div>
<?php else: ?>
<table class="fwhd-table">
    <thead>
        <tr>
            <th>ID</th>
            <th>VAT</th>
            <th>Razon</th>
            <th>Factura</th>
            <th>Intentos</th>
            <th>Recibido</th>
            <th>Ultimo error</th>
            <th>Acciones</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td>#<?php echo (int)$r->id; ?></td>
            <td><code><?php echo fwhd_h($r->partner_vat); ?></code></td>
            <td><?php echo fwhd_h($r->reason); ?></td>
            <td><?php echo fwhd_h($r->invoice_id); ?></td>
            <td style="text-align:center"><?php echo (int)$r->attempts; ?></td>
            <td><?php echo fwhd_h(date('Y-m-d H:i:s', (int)$r->last_received_at)); ?>
                <small class="text-muted"><?php echo fwhd_h(fwhd_relative_time((int)$r->last_received_at)); ?></small>
            </td>
            <td>
                <div class="fwhd-err" title="<?php echo fwhd_h($r->last_error); ?>">
                    <?php echo fwhd_h($r->last_error ?: '—'); ?>
                </div>
            </td>
            <td>
                <?php if ($r->state === 'pending'): ?>
                    <form method="post" style="display:inline-block;margin:0">
                        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                        <input type="hidden" name="action" value="retry">
                        <input type="hidden" name="id" value="<?php echo (int)$r->id; ?>">
                        <button type="submit" class="fwhd-btn retry">Reintentar</button>
                    </form>
                    <form method="post" style="display:inline-block;margin:0">
                        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                        <input type="hidden" name="action" value="resolve">
                        <input type="hidden" name="id" value="<?php echo (int)$r->id; ?>">
                        <button type="submit" class="fwhd-btn resolve">Marcar OK</button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<div style="margin-top:12px;text-align:center">
    <?php
    $pagingurl = new moodle_url('/local/grupomakro_core/pages/financial_webhook_dlq.php',
        ['state' => $state]);
    echo $OUTPUT->paging_bar($total, $page, $perpage, $pagingurl);
    ?>
</div>
<?php endif; ?>

</div>
<?php
echo $OUTPUT->footer();