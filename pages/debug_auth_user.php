<?php
/**
 * Página de debug para diagnóstico de autenticación de usuarios
 *
 * Muestra estado de cuenta (suspensión, bloqueo, hash de contraseña),
 * preferencias de lockout, e intento de login fallidos recientes.
 * Permite limpiar el lockout, forzar nueva contraseña y desuspender.
 *
 * @package    local_grupomakro_core
 * @copyright  2025 Antigravity
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/authlib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/debug_auth_user.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Debug: Autenticación de usuario');
$PAGE->set_heading('Debug: Autenticación de usuario');
$PAGE->set_pagelayout('admin');

// ── parámetros ────────────────────────────────────────────────────────────────
$q      = optional_param('q', '', PARAM_RAW_TRIMMED);
$action = optional_param('action', '', PARAM_ALPHANUMEXT);
$userid = optional_param('userid', 0, PARAM_INT);

$msg = '';

// ── acciones POST ─────────────────────────────────────────────────────────────
if ($action && $userid && confirm_sesskey()) {
    $target = $DB->get_record('user', ['id' => $userid, 'deleted' => 0]);
    if (!$target) {
        $msg = '<div class="alert alert-danger">Usuario no encontrado.</div>';
    } else {
        if ($action === 'clear_lockout') {
            $DB->delete_records('user_preferences', ['userid' => $userid, 'name' => 'login_lockout']);
            $DB->delete_records('user_preferences', ['userid' => $userid, 'name' => 'login_lockout_secret']);
            $DB->delete_records('user_preferences', ['userid' => $userid, 'name' => 'login_failed_count']);
            $DB->delete_records('user_preferences', ['userid' => $userid, 'name' => 'login_lockout_ignored']);
            $msg = '<div class="alert alert-success">Lockout limpiado para ' . s($target->username) . '.</div>';

        } else if ($action === 'unsuspend') {
            $DB->set_field('user', 'suspended', 0, ['id' => $userid]);
            $msg = '<div class="alert alert-success">Cuenta de ' . s($target->username) . ' reactivada.</div>';

        } else if ($action === 'set_password') {
            $newpass = optional_param('newpassword', '', PARAM_RAW);
            if (strlen($newpass) < 4) {
                $msg = '<div class="alert alert-danger">Contraseña demasiado corta (mín 4 caracteres).</div>';
            } else {
                update_internal_user_password($target, $newpass);
                // also clear lockout just in case
                $DB->delete_records('user_preferences', ['userid' => $userid, 'name' => 'login_lockout']);
                $DB->delete_records('user_preferences', ['userid' => $userid, 'name' => 'login_failed_count']);
                $DB->set_field('user', 'auth', 'manual', ['id' => $userid]);
                $msg = '<div class="alert alert-success">Contraseña actualizada y lockout limpiado para ' . s($target->username) . '. Auth forzado a "manual".</div>';
            }

        } else if ($action === 'force_manual_auth') {
            $DB->set_field('user', 'auth', 'manual', ['id' => $userid]);
            $msg = '<div class="alert alert-success">Auth cambiado a "manual" para ' . s($target->username) . '.</div>';

        } else if ($action === 'fix_mnethostid') {
            $localHostId = $DB->get_field('mnet_host', 'id', ['wwwroot' => $CFG->wwwroot]);
            if (!$localHostId) { $localHostId = $CFG->mnet_localhost_id; }
            $DB->set_field('user', 'mnethostid', $localHostId, ['id' => $userid]);
            $msg = '<div class="alert alert-success">mnethostid corregido a ' . (int)$localHostId . ' para ' . s($target->username) . '. <strong>Intenta iniciar sesión ahora.</strong></div>';

        } else if ($action === 'simulate_login') {
            $simpass = optional_param('simpassword', '', PARAM_RAW);
            if ($simpass === '') {
                $msg = '<div class="alert alert-warning">Ingresa la contraseña para simular el login.</div>';
            } else {
                // ── Pre-checks que hace authenticate_user_login internamente ──
                $details = [];

                // 1. ¿get_complete_user_data encuentra al usuario?
                $foundUser = get_complete_user_data('username', $target->username);
                if (!$foundUser) {
                    $details[] = ['err', 'get_complete_user_data("username","' . s($target->username) . '") devolvió FALSE — el login no encuentra al usuario. Probable mnethostid incorrecto o username no coincide exactamente.'];
                } else {
                    $details[] = ['ok', 'get_complete_user_data encontró al usuario (ID: ' . $foundUser->id . ')'];
                }

                // 2. ¿manual está en get_enabled_auth_plugins()?
                $authEnabled = get_enabled_auth_plugins();
                if (in_array($target->auth, $authEnabled)) {
                    $details[] = ['ok', 'Plugin auth "' . s($target->auth) . '" está habilitado en el sitio. Plugins activos: ' . implode(', ', $authEnabled)];
                } else {
                    $details[] = ['err', 'Plugin auth "' . s($target->auth) . '" NO está en get_enabled_auth_plugins(). Plugins activos: ' . implode(', ', $authEnabled) . ' — ESTO BLOQUEA EL LOGIN'];
                }

                // 3. Llamar authenticate_user_login con failurereason
                $failurereason = AUTH_LOGIN_OK;
                $simUser = authenticate_user_login($target->username, $simpass, false, $failurereason);

                $reasonMap = [
                    AUTH_LOGIN_OK          => 'AUTH_LOGIN_OK (éxito)',
                    AUTH_LOGIN_FAILED      => 'AUTH_LOGIN_FAILED — contraseña incorrecta según el plugin de auth',
                    AUTH_LOGIN_NOUSER      => 'AUTH_LOGIN_NOUSER — usuario no encontrado (mnethostid incorrecto o username no existe)',
                    AUTH_LOGIN_UNAUTHORISED=> 'AUTH_LOGIN_UNAUTHORISED — plugin de auth denegó el acceso',
                    AUTH_LOGIN_SUSPENDED   => 'AUTH_LOGIN_SUSPENDED — cuenta suspendida',
                    AUTH_LOGIN_LOCKOUT     => 'AUTH_LOGIN_LOCKOUT — cuenta bloqueada por intentos fallidos',
                ];
                $reasonText = isset($reasonMap[$failurereason]) ? $reasonMap[$failurereason] : 'Código desconocido: ' . $failurereason;

                if ($simUser) {
                    $msg = '<div class="alert alert-success">✅ <strong>authenticate_user_login() EXITOSO</strong> — Moodle autentica correctamente. '
                         . 'Si aún falla en el navegador, el problema es de caché, cookies o el tema.</div>';
                } else {
                    $detailsHtml = '';
                    foreach ($details as $d) {
                        $detailsHtml .= '<li style="color:' . ($d[0]==='ok'?'#28a745':'#dc3545') . ';">'
                                      . ($d[0]==='ok'?'✅ ':'❌ ') . s($d[1]) . '</li>';
                    }
                    $msg = '<div class="alert alert-danger">'
                         . '❌ <strong>authenticate_user_login() FALLÓ</strong><br>'
                         . '<strong>failurereason = ' . (int)$failurereason . ' → ' . s($reasonText) . '</strong>'
                         . '<ul style="margin-top:8px;font-size:13px;">' . $detailsHtml . '</ul>'
                         . '</div>';
                }
            }

        } else if ($action === 'test_password') {
            $testpass = optional_param('testpassword', '', PARAM_RAW);
            if ($testpass === '') {
                $msg = '<div class="alert alert-warning">Ingresa una contraseña para probar.</div>';
            } else {
                // Recargar usuario fresco desde DB para tener el hash actual
                $freshUser = $DB->get_record('user', ['id' => $userid, 'deleted' => 0]);
                if (validate_internal_user_password($freshUser, $testpass)) {
                    $msg = '<div class="alert alert-success">✅ Contraseña CORRECTA — el hash almacenado coincide con la contraseña ingresada.</div>';
                } else {
                    $msg = '<div class="alert alert-danger">❌ Contraseña INCORRECTA — el hash almacenado NO coincide. El hash en BD fue generado con otra contraseña.</div>';
                }
            }
        }
    }
}

echo $OUTPUT->header();
?>
<style>
    .dau-wrap { max-width: 960px; margin: 0 auto; font-family: 'Segoe UI', sans-serif; }
    .dau-card { background:#fff; border:1px solid #dee2e6; border-radius:6px; margin-bottom:20px; overflow:hidden; }
    .dau-card-header { background:#343a40; color:#fff; padding:10px 16px; font-weight:600; font-size:15px; }
    .dau-card-header.ok  { background:#28a745; }
    .dau-card-header.warn{ background:#ffc107; color:#212529; }
    .dau-card-header.err { background:#dc3545; }
    .dau-card-body  { padding:16px; }
    .dau-table { width:100%; border-collapse:collapse; font-size:13px; }
    .dau-table th { background:#f8f9fa; padding:7px 10px; text-align:left; border:1px solid #dee2e6; width:240px; }
    .dau-table td { padding:7px 10px; border:1px solid #dee2e6; word-break:break-all; }
    .badge-ok   { background:#28a745; color:#fff; padding:2px 8px; border-radius:4px; font-size:12px; }
    .badge-warn { background:#ffc107; color:#212529; padding:2px 8px; border-radius:4px; font-size:12px; }
    .badge-err  { background:#dc3545; color:#fff; padding:2px 8px; border-radius:4px; font-size:12px; }
    .dau-actions { display:flex; gap:10px; flex-wrap:wrap; margin-top:10px; }
    .dau-actions form { margin:0; }
    .dau-actions .btn { padding:6px 14px; border:none; border-radius:4px; cursor:pointer; font-size:13px; }
    .btn-danger  { background:#dc3545; color:#fff; }
    .btn-warning { background:#ffc107; color:#212529; }
    .btn-success { background:#28a745; color:#fff; }
    .btn-info    { background:#17a2b8; color:#fff; }
    .pref-table  { width:100%; font-size:12px; border-collapse:collapse; }
    .pref-table th, .pref-table td { padding:5px 8px; border:1px solid #dee2e6; }
    .pref-table tr.lockout-row td { background:#fff3cd; }
    .log-table   { width:100%; font-size:12px; border-collapse:collapse; }
    .log-table th { background:#6c757d; color:#fff; padding:5px 8px; text-align:left; }
    .log-table td { padding:5px 8px; border-bottom:1px solid #dee2e6; }
    .search-box  { display:flex; gap:10px; align-items:center; }
    .search-box input[type=text] { flex:1; padding:8px 12px; border:1px solid #ced4da; border-radius:4px; font-size:14px; }
    .search-box button { padding:8px 20px; background:#007bff; color:#fff; border:none; border-radius:4px; cursor:pointer; }
    .pass-form   { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
    .pass-form input[type=text] { padding:5px 10px; border:1px solid #ced4da; border-radius:4px; font-size:13px; width:220px; }
    .hash-mono   { font-family:monospace; font-size:11px; color:#6c757d; }
</style>

<div class="dau-wrap">
    <h2 style="margin-bottom:20px;">🔐 Debug: Autenticación de usuario</h2>

    <?php echo $msg; ?>

    <!-- Búsqueda -->
    <div class="dau-card">
        <div class="dau-card-header">Buscar usuario</div>
        <div class="dau-card-body">
            <form method="get" class="search-box">
                <input type="text" name="q" value="<?php echo s($q); ?>" placeholder="Username, email o ID numérico…">
                <button type="submit">Buscar</button>
            </form>
            <p style="margin-top:8px;font-size:12px;color:#6c757d;">Acepta coincidencia parcial en username o email.</p>
        </div>
    </div>

<?php
if ($q !== '') {
    // Buscar usuarios que coincidan
    $qlike = '%' . $DB->sql_like_escape($q) . '%';
    $users = $DB->get_records_sql(
        "SELECT id, username, email, firstname, lastname, auth, suspended, deleted, confirmed,
                lastlogin, timecreated, password, idnumber, institution, department, city, mnethostid
           FROM {user}
          WHERE deleted = 0
            AND (
                    " . $DB->sql_like('username', ':q1', false) . "
                 OR " . $DB->sql_like('email', ':q2', false) . "
                 OR id = :qid
                )
          ORDER BY lastname, firstname
          LIMIT 20",
        ['q1' => $qlike, 'q2' => $qlike, 'qid' => is_numeric($q) ? (int)$q : 0]
    );

    if (empty($users)) {
        echo '<div class="alert alert-warning">No se encontraron usuarios con ese criterio.</div>';
    }

    foreach ($users as $u) {
        // ── Indicadores de estado ─────────────────────────────────────────────
        $localMnetId = (int)$CFG->mnet_localhost_id;
        $problems = [];
        if ($u->suspended)  $problems[] = ['err',  'Cuenta suspendida'];
        if (!$u->confirmed) $problems[] = ['warn', 'Email no confirmado'];
        if ($u->auth !== 'manual') $problems[] = ['warn', 'Auth: ' . s($u->auth) . ' (no manual)'];
        if ((int)$u->mnethostid !== $localMnetId) $problems[] = ['err', 'mnethostid=' . (int)$u->mnethostid . ' ≠ local=' . $localMnetId . ' → login invisible'];

        // Preferencias de lockout
        $prefs = $DB->get_records('user_preferences', ['userid' => $u->id], 'name');
        $lockout        = isset($prefs['login_lockout'])       ? $prefs['login_lockout']->value       : null;
        $failCount      = isset($prefs['login_failed_count'])  ? $prefs['login_failed_count']->value  : null;
        $lockoutSecret  = isset($prefs['login_lockout_secret'])? $prefs['login_lockout_secret']->value: null;

        if ($lockout !== null && $lockout != '0') $problems[] = ['err', 'Cuenta bloqueada (login_lockout)'];
        if ((int)$failCount > 0) $problems[] = ['warn', 'Intentos fallidos: ' . (int)$failCount];

        $headerClass = empty($problems) ? 'ok' : (count(array_filter($problems, fn($p)=>$p[0]==='err')) > 0 ? 'err' : 'warn');

        $fullname = trim($u->firstname . ' ' . $u->lastname);
        ?>
        <div class="dau-card">
            <div class="dau-card-header <?php echo $headerClass; ?>">
                👤 <?php echo s($fullname); ?> — <?php echo s($u->username); ?> (ID: <?php echo $u->id; ?>)
                <?php if (!empty($problems)): foreach ($problems as $p): ?>
                    <span class="badge-<?php echo $p[0]; ?>" style="margin-left:8px;"><?php echo s($p[1]); ?></span>
                <?php endforeach; endif; ?>
            </div>
            <div class="dau-card-body">

                <!-- Datos mdl_user -->
                <h5 style="margin-bottom:8px;">Campos mdl_user</h5>
                <table class="dau-table">
                    <tr><th>ID</th><td><?php echo $u->id; ?></td></tr>
                    <tr><th>username</th><td><?php echo s($u->username); ?></td></tr>
                    <tr><th>email</th><td><?php echo s($u->email); ?></td></tr>
                    <tr><th>Nombre completo</th><td><?php echo s($fullname); ?></td></tr>
                    <tr><th>auth</th>
                        <td>
                            <?php echo s($u->auth); ?>
                            <?php if ($u->auth === 'manual'): ?>
                                <span class="badge-ok">OK</span>
                            <?php elseif ($u->auth === 'nologin'): ?>
                                <span class="badge-err">BLOQUEADO por auth</span>
                            <?php else: ?>
                                <span class="badge-warn">Externo — puede no usar contraseña Moodle</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr><th>suspended</th>
                        <td>
                            <?php echo $u->suspended ? '<span class="badge-err">1 (SUSPENDIDO)</span>' : '<span class="badge-ok">0 (activo)</span>'; ?>
                        </td>
                    </tr>
                    <tr><th>confirmed</th>
                        <td>
                            <?php echo $u->confirmed ? '<span class="badge-ok">1 (confirmado)</span>' : '<span class="badge-warn">0 (sin confirmar)</span>'; ?>
                        </td>
                    </tr>
                    <tr><th>deleted</th><td><?php echo $u->deleted ? '<span class="badge-err">1 (ELIMINADO)</span>' : '0'; ?></td></tr>
                    <tr><th>idnumber</th><td><?php echo s($u->idnumber); ?></td></tr>
                    <tr><th>institution</th><td><?php echo s($u->institution); ?></td></tr>
                    <tr><th>lastlogin</th>
                        <td><?php echo $u->lastlogin ? userdate($u->lastlogin) . ' (' . format_time(time() - $u->lastlogin) . ' atrás)' : 'Nunca'; ?></td>
                    </tr>
                    <tr><th>timecreated</th><td><?php echo $u->timecreated ? userdate($u->timecreated) : '—'; ?></td></tr>
                    <tr><th>mnethostid</th>
                        <td>
                            <?php echo (int)$u->mnethostid; ?> (mnet_localhost_id del sitio: <?php echo $localMnetId; ?>)
                            <?php if ((int)$u->mnethostid === $localMnetId): ?>
                                <span class="badge-ok">OK</span>
                            <?php else: ?>
                                <span class="badge-err">MISMATCH — el login no encontrará a este usuario</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr><th>Hash contraseña</th>
                        <td>
                            <?php
                            if (empty($u->password)) {
                                echo '<span class="badge-err">VACÍO (sin contraseña)</span>';
                            } elseif ($u->password === 'not cached') {
                                echo '<span class="badge-warn">not cached (auth externo)</span>';
                            } else {
                                // Detectar algoritmo
                                $hashLen = strlen($u->password);
                                if (strpos($u->password, '$2y$') === 0) {
                                    $algo = 'bcrypt';
                                } elseif (strpos($u->password, '$argon') === 0) {
                                    $algo = 'argon2';
                                } elseif (strlen($u->password) === 32) {
                                    $algo = 'MD5 (obsoleto)';
                                } else {
                                    $algo = 'desconocido';
                                }
                                echo '<span class="badge-ok">Establecida (' . $algo . ', ' . $hashLen . ' chars)</span>';
                                echo '<br><span class="hash-mono">' . substr(s($u->password), 0, 20) . '…</span>';
                            }
                            ?>
                        </td>
                    </tr>
                </table>

                <!-- Preferencias de lockout -->
                <h5 style="margin-top:16px;margin-bottom:8px;">Preferencias de autenticación (mdl_user_preferences)</h5>
                <?php
                $authPrefNames = [
                    'login_lockout', 'login_lockout_secret', 'login_failed_count',
                    'login_lockout_ignored', 'auth_forcepasswordchange', 'emailstop',
                    '_lastloaded', 'forced_password_change'
                ];
                $hasAuthPrefs = false;
                ?>
                <table class="pref-table">
                    <tr><th>Nombre</th><th>Valor</th><th>Interpretación</th></tr>
                    <?php foreach ($authPrefNames as $pname):
                        if (!isset($prefs[$pname])) continue;
                        $hasAuthPrefs = true;
                        $pval = $prefs[$pname]->value;
                        $interp = '';
                        if ($pname === 'login_lockout') {
                            $interp = ($pval == '0' || $pval === '') ? '<span class="badge-ok">Sin lockout</span>' : '<span class="badge-err">BLOQUEADO (timestamp: ' . ($pval ? userdate((int)$pval) : $pval) . ')</span>';
                        } elseif ($pname === 'login_failed_count') {
                            $interp = (int)$pval > 0 ? '<span class="badge-warn">' . (int)$pval . ' intentos fallidos</span>' : '<span class="badge-ok">0</span>';
                        } elseif ($pname === 'login_lockout_secret') {
                            $interp = $pval ? '<span class="badge-warn">Secret presente (desbloqueo pendiente)</span>' : '—';
                        } elseif ($pname === 'auth_forcepasswordchange' || $pname === 'forced_password_change') {
                            $interp = $pval ? '<span class="badge-warn">Forzar cambio de contraseña activo</span>' : '—';
                        }
                        ?>
                        <tr class="<?php echo in_array($pname, ['login_lockout','login_failed_count','login_lockout_secret']) ? 'lockout-row' : ''; ?>">
                            <td><?php echo s($pname); ?></td>
                            <td><?php echo s($pval); ?></td>
                            <td><?php echo $interp ?: s($pval); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$hasAuthPrefs): ?>
                        <tr><td colspan="3" style="color:#6c757d;font-style:italic;">Sin preferencias de auth relevantes — cuenta limpia.</td></tr>
                    <?php endif; ?>
                </table>

                <!-- Todas las preferencias del usuario -->
                <?php if (!empty($prefs)): ?>
                <details style="margin-top:10px;">
                    <summary style="cursor:pointer;font-size:13px;color:#6c757d;">Ver todas las preferencias (<?php echo count($prefs); ?>)</summary>
                    <table class="pref-table" style="margin-top:6px;">
                        <tr><th>Nombre</th><th>Valor</th></tr>
                        <?php foreach ($prefs as $p): ?>
                            <tr>
                                <td><?php echo s($p->name); ?></td>
                                <td><?php echo s(substr($p->value, 0, 200)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </details>
                <?php endif; ?>

                <!-- Intentos de login recientes desde el log -->
                <?php
                $logExists = $DB->get_manager()->table_exists('logstore_standard_log');
                if ($logExists) {
                    $since = time() - 7 * 24 * 3600; // última semana
                    $loginLogs = $DB->get_records_sql(
                        "SELECT id, timecreated, eventname, component, action, crud, edulevel,
                                contextid, contextlevel, userid, realuserid, ip, other
                           FROM {logstore_standard_log}
                          WHERE userid = :uid
                            AND timecreated >= :since
                            AND (action IN ('failed', 'loggedin', 'loggedout') OR eventname LIKE '%login%')
                          ORDER BY timecreated DESC
                          LIMIT 30",
                        ['uid' => $u->id, 'since' => $since]
                    );
                    if (!empty($loginLogs)):
                ?>
                <h5 style="margin-top:16px;margin-bottom:8px;">Actividad de login reciente (últimos 7 días)</h5>
                <table class="log-table">
                    <tr><th>Fecha/Hora</th><th>Evento</th><th>Acción</th><th>IP</th></tr>
                    <?php foreach ($loginLogs as $l): ?>
                        <tr style="<?php echo strpos($l->action, 'fail') !== false ? 'background:#fff3cd;' : ''; ?>">
                            <td><?php echo userdate($l->timecreated, '%d/%m/%Y %H:%M:%S'); ?></td>
                            <td><?php
                                $evShort = str_replace(['\\core\\event\\', '\\mod_', '\\local_'], ['', '', ''], $l->eventname);
                                echo s($evShort);
                            ?></td>
                            <td><?php echo s($l->action); ?></td>
                            <td><?php echo s($l->ip); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
                <?php
                    endif;
                }
                ?>

                <!-- Configuración global de lockout -->
                <?php
                $lockoutThreshold = (int)get_config('core', 'lockoutthreshold');
                $lockoutWindow    = (int)get_config('core', 'lockoutwindow');
                $lockoutDuration  = (int)get_config('core', 'lockoutduration');
                ?>
                <details style="margin-top:12px;">
                    <summary style="cursor:pointer;font-size:13px;color:#6c757d;">Configuración global de lockout del sitio</summary>
                    <table class="dau-table" style="margin-top:6px;font-size:12px;">
                        <tr><th>lockoutthreshold (intentos antes de bloquear)</th><td><?php echo $lockoutThreshold ?: 'Desactivado'; ?></td></tr>
                        <tr><th>lockoutwindow (ventana en segundos)</th><td><?php echo $lockoutWindow ? format_time($lockoutWindow) : '—'; ?></td></tr>
                        <tr><th>lockoutduration (duración del bloqueo)</th><td><?php echo $lockoutDuration ? format_time($lockoutDuration) : 'Hasta desbloqueo manual'; ?></td></tr>
                    </table>
                </details>

                <!-- Acciones -->
                <h5 style="margin-top:16px;margin-bottom:10px;">Acciones</h5>
                <div class="dau-actions">

                    <?php if ($lockout !== null && $lockout != '0' || (int)$failCount > 0): ?>
                    <form method="post">
                        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                        <input type="hidden" name="action" value="clear_lockout">
                        <input type="hidden" name="userid" value="<?php echo $u->id; ?>">
                        <input type="hidden" name="q" value="<?php echo s($q); ?>">
                        <button type="submit" class="btn btn-warning">🔓 Limpiar lockout</button>
                    </form>
                    <?php endif; ?>

                    <?php if ($u->suspended): ?>
                    <form method="post">
                        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                        <input type="hidden" name="action" value="unsuspend">
                        <input type="hidden" name="userid" value="<?php echo $u->id; ?>">
                        <input type="hidden" name="q" value="<?php echo s($q); ?>">
                        <button type="submit" class="btn btn-success">✅ Reactivar cuenta</button>
                    </form>
                    <?php endif; ?>

                    <?php if ($u->auth !== 'manual'): ?>
                    <form method="post">
                        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                        <input type="hidden" name="action" value="force_manual_auth">
                        <input type="hidden" name="userid" value="<?php echo $u->id; ?>">
                        <input type="hidden" name="q" value="<?php echo s($q); ?>">
                        <button type="submit" class="btn btn-info">🔧 Forzar auth → manual</button>
                    </form>
                    <?php endif; ?>

                    <?php if ((int)$u->mnethostid !== $localMnetId): ?>
                    <form method="post">
                        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                        <input type="hidden" name="action" value="fix_mnethostid">
                        <input type="hidden" name="userid" value="<?php echo $u->id; ?>">
                        <input type="hidden" name="q" value="<?php echo s($q); ?>">
                        <button type="submit" class="btn btn-danger">🛠 Corregir mnethostid → <?php echo $localMnetId; ?></button>
                    </form>
                    <?php endif; ?>

                    <!-- Resetear contraseña -->
                    <form method="post" class="pass-form">
                        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                        <input type="hidden" name="action" value="set_password">
                        <input type="hidden" name="userid" value="<?php echo $u->id; ?>">
                        <input type="hidden" name="q" value="<?php echo s($q); ?>">
                        <input type="text" name="newpassword" placeholder="Nueva contraseña" autocomplete="off">
                        <button type="submit" class="btn btn-danger">🔑 Forzar contraseña + limpiar lockout</button>
                    </form>

                </div><!-- /dau-actions -->

                <!-- Probar contraseña (valida el hash directamente) -->
                <div style="margin-top:16px;padding:12px;background:#f0f4ff;border:1px solid #c8d6ff;border-radius:6px;">
                    <strong style="font-size:13px;">🔬 Probar contraseña (verifica hash en BD sin iniciar sesión)</strong>
                    <form method="post" class="pass-form" style="margin-top:8px;">
                        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                        <input type="hidden" name="action" value="test_password">
                        <input type="hidden" name="userid" value="<?php echo $u->id; ?>">
                        <input type="hidden" name="q" value="<?php echo s($q); ?>">
                        <input type="text" name="testpassword" placeholder="Contraseña a verificar" autocomplete="off" style="width:280px;">
                        <button type="submit" class="btn btn-info">Verificar hash</button>
                    </form>
                    <p style="font-size:11px;color:#6c757d;margin-top:6px;">
                        Si dice "INCORRECTA", el hash en BD no corresponde a lo que el usuario escribe. Solución: forzar una contraseña nueva arriba.
                    </p>
                </div>

                <!-- Simular login completo (authenticate_user_login) -->
                <div style="margin-top:10px;padding:12px;background:#fff8e1;border:1px solid #ffe082;border-radius:6px;">
                    <strong style="font-size:13px;">🧪 Simular login completo (authenticate_user_login)</strong>
                    <p style="font-size:11px;color:#6c757d;margin:4px 0 8px;">
                        Llama a la misma función que usa la página de login. Detecta el <code>mnethostid</code> incorrecto y otros bloqueos.
                    </p>
                    <form method="post" class="pass-form">
                        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                        <input type="hidden" name="action" value="simulate_login">
                        <input type="hidden" name="userid" value="<?php echo $u->id; ?>">
                        <input type="hidden" name="q" value="<?php echo s($q); ?>">
                        <input type="text" name="simpassword" placeholder="Contraseña a simular" autocomplete="off" style="width:280px;">
                        <button type="submit" class="btn btn-warning">Simular login</button>
                    </form>
                </div>

                <!-- Plugins de autenticación activos en el sitio -->
                <?php
                $enabledAuthStr = get_config('core', 'auth');
                $enabledPlugins = $enabledAuthStr ? array_map('trim', explode(',', $enabledAuthStr)) : [];
                ?>
                <details style="margin-top:12px;">
                    <summary style="cursor:pointer;font-size:13px;color:#6c757d;">Plugins de auth habilitados en el sitio</summary>
                    <div style="margin-top:8px;font-size:13px;">
                        <?php if (empty($enabledPlugins)): ?>
                            <span style="color:#6c757d;">Ninguno (sólo manual).</span>
                        <?php else: foreach ($enabledPlugins as $ap): ?>
                            <span style="display:inline-block;margin:2px 4px;padding:2px 10px;border-radius:4px;
                                background:<?php echo ($ap === $u->auth) ? '#007bff' : '#6c757d'; ?>;color:#fff;font-size:12px;">
                                <?php echo s($ap); ?><?php echo ($ap === $u->auth) ? ' ← usuario' : ''; ?>
                            </span>
                        <?php endforeach; endif; ?>
                        <p style="margin-top:6px;color:#6c757d;font-size:11px;">
                            El campo <code>auth</code> del usuario determina cuál plugin autentica su contraseña.
                            Si el plugin es externo (ldap, db, cas…) la contraseña Moodle puede ignorarse.
                        </p>
                    </div>
                </details>

                <!-- Link a perfil Moodle -->
                <p style="margin-top:14px;font-size:12px;">
                    <a href="<?php echo $CFG->wwwroot; ?>/user/editadvanced.php?id=<?php echo $u->id; ?>" target="_blank">Editar perfil en Moodle →</a>
                    &nbsp;|&nbsp;
                    <a href="<?php echo $CFG->wwwroot; ?>/admin/user.php?delete=<?php echo $u->id; ?>" target="_blank">Admin usuarios →</a>
                </p>

            </div><!-- /dau-card-body -->
        </div><!-- /dau-card -->
        <?php
    } // foreach $users
}
?>
</div><!-- /dau-wrap -->
<?php echo $OUTPUT->footer(); ?>
