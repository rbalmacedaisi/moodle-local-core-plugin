<?php
/**
 * Fuente de Datos Financieros (Q10 / Odoo)
 * Permite a los administradores seleccionar si el estado financiero
 * de los estudiantes se consulta desde Q10 (migración temporal) o desde Odoo.
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/financial_source.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Fuente de Datos Financieros');
$PAGE->set_heading('Fuente de Datos Financieros');
$PAGE->set_pagelayout('admin');

// Configuración del proxy Express (mismos valores que bypass_financial.php)
$PROXY_URL    = get_config('local_grupomakro_core', 'odoo_proxy_url') ?: 'https://lms.isi.edu.pa:4000';
$ADMIN_SECRET = get_config('local_grupomakro_core', 'odoo_proxy_admin_secret') ?: 'gmk_admin_bypass_2026';

$action  = optional_param('action', '', PARAM_ALPHANUM);
$message = '';
$error   = '';

// Reutilizamos la misma función helper de bypass_financial.php
function fs_proxy_request(string $method, string $url, string $secret, array $body = []): array {
    $ch = curl_init($url);
    $headers = [
        'Content-Type: application/json',
        'x-admin-secret: ' . $secret,
    ];
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['error' => $curlError, 'httpCode' => 0];
    }
    $data = json_decode($response, true) ?? ['raw' => $response];
    $data['httpCode'] = $httpCode;
    return $data;
}

// Procesar cambio de fuente
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $newSource = ($action === 'q10') ? 'q10' : 'odoo';
    $result = fs_proxy_request(
        'POST',
        $PROXY_URL . '/api/admin/financial-source',
        $ADMIN_SECRET,
        [
            'source'    => $newSource,
            'updatedBy' => fullname($USER) . ' (' . $USER->username . ')',
        ]
    );

    if (isset($result['error'])) {
        $error = 'Error de conexión con el servidor proxy: ' . s($result['error']);
    } else if ($result['httpCode'] === 401) {
        $error = 'No autorizado: el secreto de administración es incorrecto.';
    } else if (!empty($result['success'])) {
        $label = $newSource === 'q10' ? 'Q10 (migración temporal)' : 'Odoo (normal)';
        $message = "Fuente de datos financieros cambiada a: $label.";
        error_log('[grupomakro_core] Fuente financiera cambiada a "' . $newSource . '" por ' .
            fullname($USER) . ' (' . $USER->username . ') desde IP ' . getremoteaddr());
    } else {
        $error = 'Respuesta inesperada del servidor: ' . s(json_encode($result));
    }
}

// Obtener estado actual
$currentConfig   = fs_proxy_request('GET', $PROXY_URL . '/api/admin/financial-source', $ADMIN_SECRET);
$currentSource   = $currentConfig['source'] ?? 'odoo';
$updatedAt       = $currentConfig['updatedAt'] ?? null;
$updatedBy       = $currentConfig['updatedBy'] ?? null;
$connectionError = isset($currentConfig['error']) || ($currentConfig['httpCode'] ?? 0) !== 200;
$isQ10           = ($currentSource === 'q10');

echo $OUTPUT->header();
?>

<style>
.fs-card {
    max-width: 700px;
    margin: 2rem auto;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(0,0,0,0.12);
}
.fs-header {
    padding: 1.5rem 2rem;
    color: white;
    display: flex;
    align-items: center;
    gap: 1rem;
}
.fs-header.q10  { background: linear-gradient(135deg, #e65100, #bf360c); }
.fs-header.odoo { background: linear-gradient(135deg, #2e7d32, #1b5e20); }
.fs-body {
    padding: 2rem;
    background: #fff;
}
.status-badge {
    display: inline-block;
    padding: 0.4rem 1.2rem;
    border-radius: 999px;
    font-weight: bold;
    font-size: 1rem;
    letter-spacing: 0.05em;
}
.status-badge.q10  { background: #fff3e0; color: #bf360c; border: 2px solid #e65100; }
.status-badge.odoo { background: #e8f5e9; color: #1b5e20; border: 2px solid #2e7d32; }
.fs-warning {
    background: #fff8e1;
    border-left: 5px solid #ffc107;
    padding: 1rem 1.5rem;
    border-radius: 4px;
    margin-bottom: 1.5rem;
    font-size: 0.95rem;
}
.fs-info {
    background: #f5f5f5;
    padding: 1rem 1.5rem;
    border-radius: 8px;
    margin-bottom: 1.5rem;
    font-size: 0.9rem;
    color: #555;
}
.fs-btn {
    padding: 0.75rem 2rem;
    border-radius: 8px;
    font-size: 1rem;
    font-weight: bold;
    border: none;
    cursor: pointer;
    display: inline-block;
    text-decoration: none;
}
.fs-btn.use-q10  { background: #e65100; color: white; }
.fs-btn.use-odoo { background: #2e7d32; color: white; }
.fs-btn.back     { background: #eee; color: #333; }
.fs-btn:hover    { opacity: 0.9; }
.source-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
    margin-bottom: 1.5rem;
}
.source-box {
    border: 2px solid #ddd;
    border-radius: 8px;
    padding: 1rem;
    text-align: center;
    font-size: 0.9rem;
}
.source-box.active-odoo { border-color: #2e7d32; background: #e8f5e9; }
.source-box.active-q10  { border-color: #e65100; background: #fff3e0; }
</style>

<?php if ($message): ?>
<div class="alert alert-success" role="alert">
    <i class="fa fa-check-circle"></i> <?php echo s($message); ?>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger" role="alert">
    <i class="fa fa-exclamation-triangle"></i> <?php echo $error; ?>
</div>
<?php endif; ?>

<?php if ($connectionError): ?>
<div class="alert alert-warning" role="alert">
    <i class="fa fa-plug"></i>
    No se pudo conectar con el servidor proxy
    (<code><?php echo s($PROXY_URL); ?></code>).
    Verifique que el servicio esté corriendo.
</div>
<?php endif; ?>

<div class="fs-card">
    <div class="fs-header <?php echo $isQ10 ? 'q10' : 'odoo'; ?>">
        <i class="fa <?php echo $isQ10 ? 'fa-exchange' : 'fa-database'; ?>" style="font-size:2rem"></i>
        <div>
            <h2 style="margin:0;font-size:1.3rem">Fuente de Datos Financieros</h2>
            <div style="font-size:0.9rem;opacity:0.9">
                Selecciona si el estado de mora se consulta desde Q10 o desde Odoo
            </div>
        </div>
    </div>

    <div class="fs-body">

        <div style="margin-bottom:1.5rem;text-align:center">
            <div style="font-size:0.9rem;color:#777;margin-bottom:0.5rem">Fuente activa:</div>
            <span class="status-badge <?php echo $isQ10 ? 'q10' : 'odoo'; ?>">
                <?php echo $isQ10
                    ? '⚠ Q10 — Migración temporal activa'
                    : '✔ Odoo — Fuente normal'; ?>
            </span>
        </div>

        <div class="source-grid">
            <div class="source-box <?php echo !$isQ10 ? 'active-odoo' : ''; ?>">
                <strong style="color:#1b5e20">Odoo</strong><br>
                <small>Fuente normal. Facturas vencidas en Odoo determinan el estado del estudiante.</small>
            </div>
            <div class="source-box <?php echo $isQ10 ? 'active-q10' : ''; ?>">
                <strong style="color:#bf360c">Q10</strong><br>
                <small>Migración temporal. Créditos y cuotas desde Q10 determinan el estado del estudiante.</small>
            </div>
        </div>

        <?php if ($updatedAt): ?>
        <div class="fs-info">
            <strong>Último cambio:</strong>
            <?php echo s(date('d/m/Y H:i:s', strtotime($updatedAt))); ?>
            <?php if ($updatedBy): ?>
                &nbsp;·&nbsp; <strong>Por:</strong> <?php echo s($updatedBy); ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($isQ10): ?>
        <div class="fs-warning">
            <strong>⚠ Fuente Q10 activa:</strong> El estado financiero de los estudiantes
            se está consultando en Q10. Esto es temporal durante la migración contable a Odoo.
            Cambia a Odoo en cuanto la migración esté completa.
        </div>
        <?php else: ?>
        <div class="fs-info">
            El sistema está usando <strong>Odoo</strong> como fuente de verdad financiera.
            Cambia a Q10 solo mientras la información contable esté siendo migrada.
        </div>
        <?php endif; ?>

        <hr style="margin:1.5rem 0">

        <div style="display:flex;gap:1rem;flex-wrap:wrap">
            <?php if ($isQ10): ?>
            <form method="post">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                <button type="submit" name="action" value="odoo"
                    class="fs-btn use-odoo"
                    onclick="return confirm('¿Cambiar la fuente financiera a ODOO?\n\nEl sistema volverá a consultar facturas en Odoo para validar el acceso de los estudiantes.\nLa caché de estados será limpiada.')">
                    <i class="fa fa-database"></i> Usar Odoo
                </button>
            </form>
            <?php else: ?>
            <form method="post">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                <button type="submit" name="action" value="q10"
                    class="fs-btn use-q10"
                    onclick="return confirm('¿Cambiar la fuente financiera a Q10?\n\nEl sistema consultará créditos y cuotas en Q10 (migración temporal).\nLa caché de estados será limpiada.')">
                    <i class="fa fa-exchange"></i> Usar Q10
                </button>
            </form>
            <?php endif; ?>

            <a href="<?php echo new moodle_url('/admin/settings.php', ['section' => 'grupomakrocore_plugin']); ?>"
               class="fs-btn back">
                <i class="fa fa-arrow-left"></i> Volver
            </a>
        </div>
    </div>
</div>

<?php
echo $OUTPUT->footer();
