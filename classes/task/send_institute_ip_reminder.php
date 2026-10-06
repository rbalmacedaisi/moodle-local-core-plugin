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
 * Scheduled task: enviar el recordatorio diario al admin para que registre
 * la IP actual del instituto desde un dispositivo en la red del ISI.
 *
 * Solo se envia si el kill switch (attendance_qr_restrict_to_institute) esta
 * activo. La URL del recordatorio apunta a pages/register_institute_ip.php
 * con el token compartido; al abrir el link desde la red del instituto el
 * server lee REMOTE_ADDR y lo persiste en gmk_institute_ip_registry.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\task;

defined('MOODLE_INTERNAL') || die();

class send_institute_ip_reminder extends \core\task\scheduled_task {

    public function get_name() {
        return get_string('task:send_institute_ip_reminder', 'local_grupomakro_core');
    }

    public function execute() {
        global $DB, $CFG;

        // Kill switch off: no hay que recordar nada.
        $enabled = (bool)get_config('local_grupomakro_core', 'attendance_qr_restrict_to_institute');
        if (!$enabled) {
            mtrace('Geofencing kill switch OFF; recordatorio suprimido.');
            return;
        }

        // Window gate: respeta attendance_qr_reminder_time (HH:MM, TZ Panama).
        // El cron corre cada 30 min y solo envia dentro de la hora exacta.
        $tz = new \DateTimeZone('America/Panama');
        $now = new \DateTime('now', $tz);
        $configured = trim((string)get_config('local_grupomakro_core', 'attendance_qr_reminder_time'));
        if ($configured !== '' && preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $configured, $m)) {
            $targetH = (int)$m[1];
            $targetM = (int)$m[2];
            if ((int)$now->format('H') !== $targetH || (int)$now->format('i') > ($targetM + 29)) {
                mtrace('Recordatorio fuera de la ventana configurada (' . $configured . '); suprimido.');
                return;
            }
        }

        $email = trim((string)get_config('local_grupomakro_core', 'attendance_qr_admin_email'));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            mtrace('attendance_qr_admin_email no configurado o invalido; recordatorio suprimido.');
            return;
        }

        $token = (string)get_config('local_grupomakro_core', 'attendance_qr_institute_token');
        if ($token === '') {
            mtrace('attendance_qr_institute_token vacio; recordatorio suprimido.');
            return;
        }

        $ttlhours = (int)get_config('local_grupomakro_core', 'attendance_qr_dynamic_ip_ttl_hours');
        if ($ttlhours < 1) {
            $ttlhours = 48;
        }

        $url = (new \moodle_url('/local/grupomakro_core/pages/register_institute_ip.php',
            ['token' => $token]))->out(false);

        $subject = get_string('msg:institute_ip_reminder:subject', 'local_grupomakro_core');
        $body = get_string('msg:institute_ip_reminder:body', 'local_grupomakro_core', [
            'ttl' => $ttlhours,
            'url' => $url,
        ]);

        // Limpieza de IPs vencidas: borrar filas con expires_at < now para que la
        // tabla no crezca indefinidamente. Es un side-effect benigno del cron.
        $DB->delete_records_select('gmk_institute_ip_registry', 'expires_at < :now', ['now' => time()]);

        // Evita enviar dos veces el mismo dia si el cron corre dentro de la ventana.
        $key = 'institute_ip_reminder_last_sent';
        $lastday = (int)get_config('local_grupomakro_core', $key);
        if ($lastday === (int)$now->format('Ymd')) {
            mtrace('Recordatorio ya enviado hoy; suprimido.');
            return;
        }

        $admin = \core_user::get_user_by_email($email, IGNORE_MULTIPLE);
        if (!$admin) {
            // No hay usuario Moodle con ese email: el cron envia como email puro
            // via email_to_user con un stub user de Moodle.
            $admin = \core_user::get_noreply_user();
        }

        $mailresult = email_to_user(
            $admin,
            \core_user::get_noreply_user(),
            $subject,
            $body,
            $body
        );

        if ($mailresult) {
            set_config($key, (int)$now->format('Ymd'), 'local_grupomakro_core');
            mtrace('Recordatorio de IP del instituto enviado a ' . $email);
        } else {
            mtrace('No se pudo enviar el recordatorio de IP a ' . $email);
        }
    }
}