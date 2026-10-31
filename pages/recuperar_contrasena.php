<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Password recovery, step 1: ask for the cédula or the email and send the link.
 *
 * Standalone page (no login, no Moodle interface). Moodle's forgot_password.php
 * and the LXP "¿Olvidaste tu contraseña?" link land here through the core
 * setting forgottenpasswordurl.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

use local_grupomakro_core\local\password_recovery;

$PAGE->set_url(new moodle_url('/local/grupomakro_core/pages/recuperar_contrasena.php'));
$PAGE->set_context(context_system::instance());

$identifier = trim(optional_param('identificador', '', PARAM_RAW_TRIMMED));
$sent = false;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!confirm_sesskey()) {
        $error = 'La página expiró. Vuelve a intentarlo.';
    } else if ($identifier === '') {
        $error = 'Escribe tu cédula o tu correo.';
    } else {
        password_recovery::request($identifier);
        $sent = true;
    }
}

if ($sent) {
    $content = '<h1>Revisa tu correo</h1>'
        . '<div class="msg ok">Si los datos corresponden a una cuenta, te enviamos un correo con un enlace '
        . 'para crear una contraseña nueva.</div>'
        . '<p>El enlace vence en ' . floor((int)($CFG->pwresettime ?? 1800) / MINSECS) . ' minutos. '
        . 'Si no lo ves, revisa también la carpeta de Spam o Correo no deseado.</p>'
        . '<p class="hint">¿Ya no tienes acceso a ese correo? Escribe a Soporte TI: '
        . s(core_user::get_support_user()->email) . '</p>';
    password_recovery::output_page('Revisa tu correo', $content);
}

$content = '<h1>Recuperar contraseña</h1>'
    . '<p>Escribe tu cédula (tu usuario) o el correo de tu cuenta. Te enviaremos un enlace para crear una contraseña nueva.</p>'
    . ($error !== '' ? '<div class="msg err">' . s($error) . '</div>' : '')
    . '<form method="post" action="' . $PAGE->url->out(false) . '" autocomplete="off">'
    . '<input type="hidden" name="sesskey" value="' . sesskey() . '">'
    . '<label for="identificador">Cédula o correo</label>'
    . '<input type="text" id="identificador" name="identificador" value="' . s($identifier) . '" required autofocus>'
    . '<button type="submit" class="btn">Enviar enlace</button>'
    . '</form>';
password_recovery::output_page('Recuperar contraseña', $content);
