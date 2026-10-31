<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Password recovery, step 2: the link from the email sets the new password.
 *
 * Standalone page (no login, no Moodle interface). On success the student is
 * sent to the LXP login, staff to Moodle's login; nobody is logged in here.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

use local_grupomakro_core\local\password_recovery;

$token = optional_param('token', '', PARAM_ALPHANUM);
$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/restablecer_contrasena.php', ['token' => $token]));
$PAGE->set_context(context_system::instance());

$requesturl = (new moodle_url('/local/grupomakro_core/pages/recuperar_contrasena.php'))->out(false);
$check = password_recovery::check_token($token);
if (!empty($check['error'])) {
    password_recovery::output_page('Enlace no válido',
        '<h1>Enlace no válido</h1><div class="msg err">' . s($check['error']) . '</div>'
        . '<a class="btn" href="' . s($requesturl) . '">Solicitar un enlace nuevo</a>');
}
$user = $check['user'];

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!confirm_sesskey()) {
        $error = 'La página expiró. Vuelve a intentarlo.';
    } else {
        $error = password_recovery::set_password($user,
            (string)optional_param('password', '', PARAM_RAW), (string)optional_param('password2', '', PARAM_RAW));
        if ($error === '') {
            $loginurl = password_recovery::login_url($user);
            password_recovery::output_page('Contraseña actualizada',
                '<h1>Contraseña actualizada</h1>'
                . '<div class="msg ok">Ya puedes iniciar sesión con tu usuario <b>' . s($user->username)
                . '</b> y tu contraseña nueva.</div>'
                . '<a class="btn" href="' . s($loginurl) . '">Ir a iniciar sesión</a>');
        }
    }
}

$policy = !empty($CFG->passwordpolicy) ? '<p class="hint">' . s(html_to_text(print_password_policy(), 0, false)) . '</p>' : '';
$content = '<h1>Crea tu contraseña nueva</h1>'
    . '<p>Cuenta: <b>' . s($user->username) . '</b></p>'
    . ($error !== '' ? '<div class="msg err">' . s($error) . '</div>' : '')
    . '<form method="post" action="' . $PAGE->url->out(false) . '" autocomplete="off">'
    . '<input type="hidden" name="sesskey" value="' . sesskey() . '">'
    . '<label for="password">Contraseña nueva</label>'
    . '<input type="password" id="password" name="password" required autofocus autocomplete="new-password">'
    . '<label for="password2">Repite la contraseña</label>'
    . '<input type="password" id="password2" name="password2" required autocomplete="new-password">'
    . $policy
    . '<button type="submit" class="btn">Guardar contraseña</button>'
    . '</form>';
password_recovery::output_page('Crea tu contraseña nueva', $content);
