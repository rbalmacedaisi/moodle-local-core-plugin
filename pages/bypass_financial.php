<?php
/**
 * Bypass de Estado Financiero en Login
 * Permite a los administradores activar/desactivar la excepción global
 * que omite las validaciones financieras al autenticarse un estudiante.
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/bypass_financial.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Ignorar Estado Financiero en Login');
$PAGE->set_heading('Ignorar Estado Financiero en Login');
$PAGE->set_pagelayout('admin');

// Configuración del proxy Express
$PROXY_URL   = get_config('local_grupomakro_core', 'odoo_proxy_url') ?: 'https://lms.isi.edu.pa:4000';
$ADMIN_SECRET = get_config('local_grupomakro_core', 'odoo_proxy_admin_secret') ?: 'gmk_admin_bypass_2026';

$action  = optional_param('action', '', PARAM_ALPHA);
$message = '';
$error   = '';

// Función auxiliar: llamada al proxy
function proxy_request(string $method, string $url, string $secret, array $body = []): array {
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

// Procesar acción POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $enable = ($action === 'enable');
    $result = proxy_request(
        'POST',
        $PROXY_URL . '/api/admin/bypass',
        $ADMIN_SECRET,
        ['enabled' => $enable, 'updatedBy' => fullname($USER) . ' (' . $USER->username . ')']
    );

    if (isset($result['error'])) {
        $error = 'Error de conexión con el servidor proxy: ' . s($result['error']);
    } else if ($result['httpCode'] === 401) {
        $error = 'No autorizado: el secreto de administración es incorrecto.';
    } else if (!empty($result['success'])) {
        $estado = $enable ? 'ACTIVADO' : 'DESACTIVADO';
        $message = "Bypass financiero $estado correctamente.";
        // Registrar en el log de Moodle
        $event = \core\event\admin_settings_changed::create([
            'context' => context_system::instance(),
            'other'   => ['action' => 'bypass_financial_' . ($enable ? 'enabled' : 'disabled')]
        ]);
        $event->trigger();
    } else {
        $error = 'Respuesta inesperada del servidor: ' . s(json_encode($result));
    }
}

// Obtener estado actual del bypass
$bypassStatus = proxy_request('GET', $PROXY_URL . '/api/admin/bypass', $ADMIN_SECRET);
$bypassEnabled  = !empty($bypassStatus['enabled']);
$bypassUpdatedAt = $bypassStatus['updatedAt'] ?? null;
$bypassUpdatedBy = $bypassStatus['updatedBy'] ?? null;
$connectionError = isset($bypassStatus['error']) || ($bypassStatus['httpCode'] ?? 0) !== 200;

echo $OUTPUT->header();
?>

<style>
.bypass-card {
    max-width: 700px;
    margin: 2rem auto;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(0,0,0,0.12);
}
.bypass-header {
    padding: 1.5rem 2rem;
    color: white;
    display: flex;
    align-items: center;
    gap: 1rem;
}
.bypass-header.active   { background: linear-gradient(135deg, #e53935, #c62828); }
.bypass-header.inactive { background: linear-gradient(135deg, #1976d2, #0d47a1); }
.bypass-body {
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
.status-badge.active   { background: #ffebee; color: #c62828; border: 2px solid #e53935; }
.status-badge.inactive { background: #e3f2fd; color: #1565c0; border: 2px solid #1976d2; }
.bypass-warning {
    background: #fff8e1;
    border-left: 5px solid #ffc107;
    padding: 1rem 1.5rem;
    border-radius: 4px;
    margin-bottom: 1.5rem;
    font-size: 0.95rem;
}
.bypass-info {
    background: #f5f5f5;
    padding: 1rem 1.5rem;
    border-radius: 8px;
    margin-bottom: 1.5rem;
    font-size: 0.9rem;
    color: #555;
}
.bypass-btn {
    padding: 0.75rem 2rem;
    border-radius: 8px;
    font-size: 1rem;
    font-weight: bold;
    border: none;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
}
.bypass-btn.enable  { background: #e53935; color: white; }
.bypass-btn.disable { background: #1976d2; color: white; }
.bypass-btn:hover   { opacity: 0.9; }
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

<div class="bypass-card">
    <div class="bypass-header <?php echo $bypassEnabled ? 'active' : 'inactive'; ?>">
        <i class="fa <?php echo $bypassEnabled ? 'fa-exclamation-circle' : 'fa-shield'; ?>" style="font-size:2rem"></i>
        <div>
            <h2 style="margin:0;font-size:1.3rem">Ignorar Estado Financiero en Login</h2>
            <div style="font-size:0.9rem;opacity:0.9">Control global de validación financiera al autenticarse</div>
        </div>
    </div>

    <div class="bypass-body">

        <div style="margin-bottom:1.5rem;text-align:center">
            <div style="font-size:0.9rem;color:#777;margin-bottom:0.5rem">Estado actual:</div>
            <span class="status-badge <?php echo $bypassEnabled ? 'active' : 'inactive'; ?>">
                <?php echo $bypassEnabled ? '⚠ BYPASS ACTIVO — Verificación financiera DESACTIVADA' : '✔ Verificación financiera ACTIVA (normal)'; ?>
            </span>
        </div>

        <?php if ($bypassUpdatedAt): ?>
        <div class="bypass-info">
            <strong>Último cambio:</strong>
            <?php echo s(date('d/m/Y H:i:s', strtotime($bypassUpdatedAt))); ?>
            <?php if ($bypassUpdatedBy): ?>
                &nbsp;·&nbsp; <strong>Por:</strong> <?php echo s($bypassUpdatedBy); ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($bypassEnabled): ?>
        <div class="bypass-warning">
            <strong>⚠ Advertencia:</strong> El bypass está activo. Todos los estudiantes pueden
            iniciar sesión <strong>sin importar su estado financiero</strong>. Desactívelo tan
            pronto sea posible para restaurar el control de acceso normal.
        </div>
        <?php else: ?>
        <div class="bypass-info">
            Cuando el bypass está <strong>inactivo</strong>, el sistema valida que cada estudiante
            esté al día en sus pagos antes de permitirle acceder a la plataforma. Esta es la
            configuración normal y recomendada.
        </div>
        <?php endif; ?>

        <hr style="margin:1.5rem 0">

        <div style="display:flex;gap:1rem;flex-wrap:wrap">
            <?php if (!$bypassEnabled): ?>
            <form method="post">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                <button type="submit" name="action" value="enable"
                    class="bypass-btn enable"
                    onclick="return confirm('¿Está seguro de que desea ACTIVAR el bypass?\n\nTodos los estudiantes podrán iniciar sesión sin validación financiera.')">
                    <i class="fa fa-unlock"></i> Activar Bypass
                </button>
            </form>
            <?php else: ?>
            <form method="post">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                <button type="submit" name="action" value="disable"
                    class="bypass-btn disable"
                    onclick="return confirm('¿Está seguro de que desea DESACTIVAR el bypass?\n\nSe restaurará la validación financiera normal.')">
                    <i class="fa fa-lock"></i> Desactivar Bypass
                </button>
            </form>
            <?php endif; ?>

            <a href="<?php echo new moodle_url('/admin/settings.php', ['section' => 'grupomakrocore_plugin']); ?>"
               class="bypass-btn" style="background:#eee;color:#333">
                <i class="fa fa-arrow-left"></i> Volver
            </a>
        </div>
    </div>
</div>

<?php
echo $OUTPUT->footer();
