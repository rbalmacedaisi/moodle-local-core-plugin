<?php
/**
 * Gestión del Periodo de Gracia en Primer Login
 * Permite activar/desactivar y monitorear los periodos de gracia de primer acceso.
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/grace_period.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Periodo de Gracia en Primer Login');
$PAGE->set_heading('Periodo de Gracia en Primer Login');
$PAGE->set_pagelayout('admin');

$action  = optional_param('action', '', PARAM_ALPHA);
$message = '';
$error   = '';

// Process POST actions.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    if ($action === 'enable') {
        set_config('grace_period_enabled', 1, 'local_grupomakro_core');
        $message = 'Periodo de gracia ACTIVADO. Los estudiantes que inicien sesión por primera vez tendrán acceso hasta fin de mes.';
        error_log('[grupomakro_core] Periodo de gracia ACTIVADO por ' . fullname($USER) . ' (' . $USER->username . ')');
    } else if ($action === 'disable') {
        set_config('grace_period_enabled', 0, 'local_grupomakro_core');
        $message = 'Periodo de gracia DESACTIVADO. Los nuevos primeros logins ya no generarán periodos de gracia.';
        error_log('[grupomakro_core] Periodo de gracia DESACTIVADO por ' . fullname($USER) . ' (' . $USER->username . ')');
    } else if ($action === 'delete') {
        $graceid = required_param('graceid', PARAM_INT);
        $DB->delete_records('gmk_grace_period', ['id' => $graceid]);
        $message = 'Periodo de gracia eliminado correctamente.';
    }
}

$graceEnabled = (bool) get_config('local_grupomakro_core', 'grace_period_enabled');
$graceToken   = get_config('local_grupomakro_core', 'grace_period_token') ?: 'gmk_grace_check_2026';

// Load active and expired grace records.
$now = time();
$activeRecords  = $DB->get_records_select('gmk_grace_period', 'graceuntil >= :now', ['now' => $now], 'timecreated DESC');
$expiredRecords = $DB->get_records_select('gmk_grace_period', 'graceuntil < :now',  ['now' => $now], 'timecreated DESC');

echo $OUTPUT->header();
?>

<style>
.grace-card {
    max-width: 900px;
    margin: 2rem auto;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 4px 20px rgba(0,0,0,0.12);
}
.grace-header {
    padding: 1.5rem 2rem;
    color: white;
    display: flex;
    align-items: center;
    gap: 1rem;
}
.grace-header.active   { background: linear-gradient(135deg, #2e7d32, #1b5e20); }
.grace-header.inactive { background: linear-gradient(135deg, #546e7a, #37474f); }
.grace-body { padding: 2rem; background: #fff; }
.status-badge {
    display: inline-block;
    padding: 0.4rem 1.2rem;
    border-radius: 999px;
    font-weight: bold;
    font-size: 1rem;
}
.status-badge.active   { background: #e8f5e9; color: #2e7d32; border: 2px solid #4caf50; }
.status-badge.inactive { background: #eceff1; color: #546e7a; border: 2px solid #90a4ae; }
.grace-btn {
    padding: 0.65rem 1.8rem;
    border-radius: 8px;
    font-size: 0.95rem;
    font-weight: bold;
    border: none;
    cursor: pointer;
    display: inline-block;
    text-decoration: none;
}
.grace-btn.enable  { background: #2e7d32; color: white; }
.grace-btn.disable { background: #546e7a; color: white; }
.grace-btn.danger  { background: #c62828; color: white; padding: 0.3rem 0.9rem; font-size: 0.85rem; }
.grace-btn:hover   { opacity: 0.88; }
.token-box {
    background: #f5f5f5;
    border-radius: 6px;
    padding: 0.6rem 1rem;
    font-family: monospace;
    font-size: 0.9rem;
    color: #333;
    word-break: break-all;
}
table.grace-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
table.grace-table th { background: #eceff1; padding: 0.6rem 1rem; text-align: left; }
table.grace-table td { padding: 0.6rem 1rem; border-bottom: 1px solid #eee; }
.badge-active   { background: #e8f5e9; color: #2e7d32; border-radius: 999px; padding: 0.2rem 0.7rem; font-size: 0.8rem; font-weight: bold; }
.badge-expired  { background: #ffebee; color: #c62828; border-radius: 999px; padding: 0.2rem 0.7rem; font-size: 0.8rem; font-weight: bold; }
</style>

<?php if ($message): ?>
<div class="alert alert-success" role="alert">
    <i class="fa fa-check-circle"></i> <?php echo s($message); ?>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger" role="alert">
    <i class="fa fa-exclamation-triangle"></i> <?php echo s($error); ?>
</div>
<?php endif; ?>

<div class="grace-card">
    <div class="grace-header <?php echo $graceEnabled ? 'active' : 'inactive'; ?>">
        <i class="fa <?php echo $graceEnabled ? 'fa-hourglass-half' : 'fa-hourglass-o'; ?>" style="font-size:2rem"></i>
        <div>
            <h2 style="margin:0;font-size:1.3rem">Periodo de Gracia en Primer Login</h2>
            <div style="font-size:0.9rem;opacity:0.9">Acceso automático hasta fin de mes para estudiantes nuevos</div>
        </div>
    </div>

    <div class="grace-body">

        <div style="margin-bottom:1.5rem;text-align:center">
            <div style="font-size:0.9rem;color:#777;margin-bottom:0.5rem">Estado de la función:</div>
            <span class="status-badge <?php echo $graceEnabled ? 'active' : 'inactive'; ?>">
                <?php echo $graceEnabled ? '✔ ACTIVO — Se otorgan periodos de gracia en el primer login' : '✘ INACTIVO — Sin periodos de gracia automáticos'; ?>
            </span>
        </div>

        <div style="margin-bottom:1.5rem">
            <strong>Token compartido con Express Server:</strong>
            <div class="token-box" style="margin-top:0.4rem">
                <?php echo s($graceToken); ?>
            </div>
            <div style="font-size:0.82rem;color:#888;margin-top:0.3rem">
                Este token debe coincidir con <code>MOODLE_GRACE_TOKEN</code> en <code>server.js</code>.
                Puede cambiarlo desde <a href="<?php echo new moodle_url('/admin/settings.php', ['section' => 'financial_settingspage']); ?>">Configuración → Financiero</a>.
            </div>
        </div>

        <div style="font-size:0.9rem;background:#f9f9f9;border-left:4px solid #90caf9;padding:0.8rem 1.2rem;border-radius:4px;margin-bottom:1.5rem">
            Cuando la función está <strong>activa</strong>, al primer login de un estudiante se crea un registro de gracia
            válido hasta el <strong>último día del mes en curso (23:59:59)</strong>. Durante ese tiempo, el Express Server
            permite el acceso al LXP sin consultar Odoo.
        </div>

        <div style="display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:2rem">
            <?php if (!$graceEnabled): ?>
            <form method="post">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                <button type="submit" name="action" value="enable" class="grace-btn enable"
                    onclick="return confirm('¿Activar los periodos de gracia en primer login?')">
                    <i class="fa fa-toggle-on"></i> Activar
                </button>
            </form>
            <?php else: ?>
            <form method="post">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                <button type="submit" name="action" value="disable" class="grace-btn disable"
                    onclick="return confirm('¿Desactivar los periodos de gracia?\\nLos periodos ya creados seguirán siendo válidos hasta su vencimiento.')">
                    <i class="fa fa-toggle-off"></i> Desactivar
                </button>
            </form>
            <?php endif; ?>

            <a href="<?php echo new moodle_url('/admin/settings.php', ['section' => 'financial_settingspage']); ?>"
               class="grace-btn" style="background:#eee;color:#333">
                <i class="fa fa-cog"></i> Configuración Financiera
            </a>
        </div>

        <hr style="margin:1.5rem 0">

        <h3 style="margin-bottom:1rem">Periodos de gracia activos (<?php echo count($activeRecords); ?>)</h3>
        <?php if (empty($activeRecords)): ?>
            <p style="color:#888;font-size:0.9rem">No hay periodos de gracia activos en este momento.</p>
        <?php else: ?>
        <table class="grace-table">
            <tr>
                <th>Usuario (ID)</th>
                <th>Documento</th>
                <th>Válido hasta</th>
                <th>Creado el</th>
                <th>Estado</th>
                <th>Acción</th>
            </tr>
            <?php foreach ($activeRecords as $g):
                $u = $DB->get_record('user', ['id' => $g->userid], 'id, firstname, lastname, username', IGNORE_MISSING);
                $displayName = $u ? fullname($u) . ' (' . $u->username . ')' : 'ID ' . $g->userid;
            ?>
            <tr>
                <td><?php echo s($displayName); ?></td>
                <td><?php echo s($g->documentnumber ?: '—'); ?></td>
                <td><?php echo date('d/m/Y H:i', $g->graceuntil); ?></td>
                <td><?php echo date('d/m/Y H:i', $g->timecreated); ?></td>
                <td><span class="badge-active">Activo</span></td>
                <td>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                        <input type="hidden" name="graceid" value="<?php echo (int)$g->id; ?>">
                        <button type="submit" name="action" value="delete" class="grace-btn danger"
                            onclick="return confirm('¿Eliminar el periodo de gracia para <?php echo s($displayName); ?>?')">
                            <i class="fa fa-trash"></i> Eliminar
                        </button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>

        <?php if (!empty($expiredRecords)): ?>
        <details style="margin-top:1.5rem">
            <summary style="cursor:pointer;font-size:0.95rem;color:#777">
                Historial de periodos vencidos (<?php echo count($expiredRecords); ?>)
            </summary>
            <table class="grace-table" style="margin-top:0.8rem">
                <tr>
                    <th>Usuario (ID)</th>
                    <th>Documento</th>
                    <th>Venció el</th>
                    <th>Creado el</th>
                    <th>Estado</th>
                </tr>
                <?php foreach ($expiredRecords as $g):
                    $u = $DB->get_record('user', ['id' => $g->userid], 'id, firstname, lastname, username', IGNORE_MISSING);
                    $displayName = $u ? fullname($u) . ' (' . $u->username . ')' : 'ID ' . $g->userid;
                ?>
                <tr>
                    <td><?php echo s($displayName); ?></td>
                    <td><?php echo s($g->documentnumber ?: '—'); ?></td>
                    <td><?php echo date('d/m/Y H:i', $g->graceuntil); ?></td>
                    <td><?php echo date('d/m/Y H:i', $g->timecreated); ?></td>
                    <td><span class="badge-expired">Vencido</span></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </details>
        <?php endif; ?>

    </div>
</div>

<?php
echo $OUTPUT->footer();
