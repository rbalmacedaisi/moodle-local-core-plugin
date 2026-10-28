<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Password recovery outside Moodle's interface.
 *
 * Same rules as core (login/lib.php core_login_process_password_reset and
 * core_login_process_password_set): reset records in user_password_resets,
 * pwresettime, one re-send per request, password policy, history, lockout reset
 * and passwordchangelogout. Differences: the email links to
 * pages/restablecer_contrasena.php, and setting the password never logs the user
 * into Moodle — students are sent to the LXP login, everyone else to Moodle's.
 *
 * Moodle's own forgot_password.php redirects here through the core setting
 * forgottenpasswordurl.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local;

defined('MOODLE_INTERNAL') || die();

class password_recovery {

    /**
     * Account behind a cédula (username) or an email, if it may reset its password.
     */
    public static function find_user(string $identifier): ?\stdClass {
        global $CFG, $DB;
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }
        if (strpos($identifier, '@') !== false) {
            $user = $DB->get_record_sql(
                "SELECT * FROM {user}
                  WHERE " . $DB->sql_equal('email', ':email1', false, true) . "
                    AND id IN (SELECT id FROM {user}
                                WHERE mnethostid = :mnethostid AND deleted = 0 AND suspended = 0
                                  AND " . $DB->sql_equal('email', ':email2', false, false) . ")",
                ['email1' => $identifier, 'email2' => $identifier, 'mnethostid' => $CFG->mnet_localhost_id],
                IGNORE_MULTIPLE);
        } else {
            $user = $DB->get_record('user', ['username' => \core_text::strtolower($identifier),
                'mnethostid' => $CFG->mnet_localhost_id, 'deleted' => 0, 'suspended' => 0]);
        }
        if (!$user || empty($user->confirmed) || isguestuser($user) || $user->auth === 'nologin'
                || !is_enabled_auth($user->auth) || !get_auth_plugin($user->auth)->can_reset_password()) {
            return null;
        }
        return $user;
    }

    /**
     * Handle a reset request. Says nothing about whether the account exists:
     * the caller always shows the same message.
     */
    public static function request(string $identifier): void {
        global $CFG, $DB;
        $user = self::find_user($identifier);
        if (!$user) {
            return;
        }
        $pwresettime = isset($CFG->pwresettime) ? (int)$CFG->pwresettime : 1800;
        $reset = $DB->get_record('user_password_resets', ['userid' => $user->id]);
        if (!$reset) {
            $reset = self::new_reset($user);
        } else if ($reset->timerequested < time() - $pwresettime) {
            $DB->delete_records('user_password_resets', ['id' => $reset->id]);
            $reset = self::new_reset($user);
        } else if (empty($reset->timererequested)) {
            // A still valid request: the email is sent once more (greylisting delays).
            $reset->timererequested = time();
            $DB->update_record('user_password_resets', $reset);
        } else {
            return; // Already re-sent once; wait for that email or for the expiry.
        }
        self::send_email($user, $reset);
    }

    private static function new_reset(\stdClass $user): \stdClass {
        global $DB;
        $reset = (object)['timerequested' => time(), 'userid' => $user->id, 'token' => random_string(32)];
        $reset->id = $DB->insert_record('user_password_resets', $reset);
        return $reset;
    }

    private static function send_email(\stdClass $user, \stdClass $reset): bool {
        global $CFG;
        $minutes = floor((isset($CFG->pwresettime) ? (int)$CFG->pwresettime : 1800) / MINSECS);
        $link = (new \moodle_url('/local/grupomakro_core/pages/restablecer_contrasena.php',
            ['token' => $reset->token]))->out(false);
        $site = format_string(get_site()->fullname);
        $name = trim($user->firstname);
        $signoff = generate_email_signoff();

        $subject = $site . ': restablecer tu contraseña';
        $text = "Hola $name:\n\n"
            . "Recibimos una solicitud para restablecer la contraseña de tu cuenta {$user->username}.\n\n"
            . "Para crear una contraseña nueva, abre este enlace (válido por $minutes minutos):\n$link\n\n"
            . "Si no lo solicitaste, ignora este correo: tu contraseña no cambia.\n\n$signoff";
        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#1f2937;max-width:560px">'
            . '<p>Hola ' . s($name) . ':</p>'
            . '<p>Recibimos una solicitud para restablecer la contraseña de tu cuenta <b>' . s($user->username) . '</b>.</p>'
            . '<p style="margin:24px 0"><a href="' . s($link) . '" style="background:#1f3a5f;color:#ffffff;'
            . 'text-decoration:none;padding:12px 22px;border-radius:6px;font-weight:bold;display:inline-block">'
            . 'Crear contraseña nueva</a></p>'
            . '<p style="font-size:13px;color:#6b7280">El enlace es válido por ' . $minutes . ' minutos. '
            . 'Si el botón no funciona, copia esta dirección en tu navegador:<br>' . s($link) . '</p>'
            . '<p style="font-size:13px;color:#6b7280">Si no lo solicitaste, ignora este correo: tu contraseña no cambia.</p>'
            . '<p>' . nl2br(s($signoff)) . '</p></div>';
        return email_to_user($user, \core_user::get_support_user(), $subject, $text, $html);
    }

    /**
     * User of a reset token: ['user' => record] or ['error' => message].
     */
    public static function check_token(string $token): array {
        global $CFG, $DB;
        $pwresettime = isset($CFG->pwresettime) ? (int)$CFG->pwresettime : 1800;
        $user = $token === '' ? null : $DB->get_record_sql(
            "SELECT u.*, upr.timerequested, upr.id AS tokenid
               FROM {user} u
               JOIN {user_password_resets} upr ON upr.userid = u.id
              WHERE upr.token = ? AND u.deleted = 0 AND u.suspended = 0", [$token]);
        if (!$user) {
            return ['error' => 'El enlace no es válido o ya fue usado. Solicita uno nuevo.'];
        }
        if ($user->timerequested < time() - $pwresettime) {
            return ['error' => 'El enlace venció (es válido por ' . floor($pwresettime / MINSECS)
                . ' minutos). Solicita uno nuevo.'];
        }
        if ($user->auth === 'nologin' || !is_enabled_auth($user->auth) || isguestuser($user)) {
            return ['error' => 'Esta cuenta no puede cambiar su contraseña. Comunícate con Soporte TI.'];
        }
        return ['user' => $user];
    }

    /**
     * Set the new password for the owner of a valid token. Returns an error
     * message, or '' on success. Never logs the user in.
     */
    public static function set_password(\stdClass $user, string $password, string $confirm): string {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/lib.php');
        if ($password !== $confirm) {
            return 'Las contraseñas no coinciden.';
        }
        $errmsg = '';
        if (!check_password_policy($password, $errmsg, $user)) {
            return html_to_text($errmsg, 0, false);
        }
        if (user_is_previously_used_password($user->id, $password)) {
            return 'Esa contraseña ya la usaste antes. Elige una distinta.';
        }
        // The token is single use.
        $DB->delete_records('user_password_resets', ['id' => $user->tokenid]);
        if (!get_auth_plugin($user->auth)->user_update_password($user, $password)) {
            return 'No se pudo guardar la contraseña. Intenta de nuevo o comunícate con Soporte TI.';
        }
        user_add_password_history($user->id, $password);
        if (!empty($CFG->passwordchangelogout)) {
            \core\session\manager::kill_user_sessions($user->id);
        }
        login_unlock_account($user);
        unset_user_preference('auth_forcepasswordchange', $user);
        unset_user_preference('create_password', $user);
        return '';
    }

    /**
     * Print a standalone page (no Moodle header, menus or session) and stop.
     */
    public static function output_page(string $title, string $content): void {
        $site = s(format_string(get_site()->fullname));
        header('Content-Type: text/html; charset=utf-8');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store');
        echo '<!doctype html><html lang="es"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . s($title) . ' · ' . $site . '</title><style>'
            . 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
            . 'background:#eef2f7;font-family:Arial,Helvetica,sans-serif;color:#1f2937;padding:16px;box-sizing:border-box}'
            . '.card{background:#fff;max-width:420px;width:100%;border-radius:12px;box-shadow:0 8px 30px rgba(15,23,42,.12);padding:32px 28px}'
            . '.brand{font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#b8862b;margin-bottom:6px}'
            . 'h1{font-size:22px;margin:0 0 12px;color:#1f3a5f}p{font-size:14px;line-height:1.55;margin:0 0 14px}'
            . 'label{display:block;font-size:13px;font-weight:700;margin:14px 0 6px}'
            . 'input[type=text],input[type=password]{width:100%;box-sizing:border-box;padding:11px 12px;font-size:15px;'
            . 'border:1px solid #cbd5e1;border-radius:8px}input:focus{outline:2px solid #1f3a5f;border-color:#1f3a5f}'
            . '.btn{display:block;width:100%;box-sizing:border-box;margin-top:20px;padding:12px;border:0;border-radius:8px;'
            . 'background:#1f3a5f;color:#fff;font-size:15px;font-weight:700;text-align:center;text-decoration:none;cursor:pointer}'
            . '.msg{padding:12px 14px;border-radius:8px;font-size:14px;margin-bottom:14px}'
            . '.ok{background:#ecfdf5;color:#065f46}.err{background:#fef2f2;color:#991b1b}'
            . '.hint{font-size:12px;color:#6b7280}.link{display:block;text-align:center;margin-top:16px;font-size:13px;color:#1f3a5f}'
            . '</style></head><body><main class="card"><div class="brand">' . $site . '</div>'
            . $content . '</main></body></html>';
        exit;
    }

    /**
     * Where to log in after the change: the LXP for students, Moodle for staff.
     */
    public static function login_url(\stdClass $user): string {
        global $DB;
        $isstudent = $DB->record_exists('local_learning_users', ['userid' => $user->id, 'userroleid' => 5])
            && !$DB->record_exists('local_learning_users', ['userid' => $user->id, 'userroleid' => 4])
            && !is_siteadmin($user);
        if ($isstudent) {
            $base = rtrim(trim((string)get_config('local_grupomakro_core', 'student_app_url')), '/');
            return ($base !== '' ? $base : 'https://students.isi.edu.pa') . '/login';
        }
        return (new \moodle_url('/login/index.php'))->out(false);
    }
}
