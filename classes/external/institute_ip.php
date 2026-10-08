<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Web Services para el geofencing del QR de asistencia.
 *
 * - get_status: estado actual del registro dinamico de IPs del instituto
 *   (kill switch + lista de IPs vigentes + contadores) - consumido por el
 *   teacher_dashboard para que el docente sepa si hay IPs registradas.
 * - register: el docente registra manualmente la IP actual como IP del
 *   instituto con TTL configurable (attendance_qr_dynamic_ip_ttl_hours).
 *   Equivalente al endpoint publico register_institute_ip.php pero invocado
 *   con credenciales Moodle (moodle/user:update o similar).
 *
 * @package    local_grupomakro_core
 */

namespace local_grupomakro_core\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/externallib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;
use external_multiple_structure;

class institute_ip extends external_api {

    /**
     * Status snapshot for the teacher dashboard widget. Returns the kill
     * switch state, the static allowlist entries, the active dynamic rows,
     * and the next expiry so the UI can show "IPs vigentes: N, pr\u00f3xima
     * expira en Xh".
     */
    public static function get_status_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    public static function get_status(): array {
        global $DB;

        $kill = (bool)get_config('local_grupomakro_core', 'attendance_qr_restrict_to_institute');
        $allowlist = (string)get_config('local_grupomakro_core', 'attendance_qr_ip_allowlist');
        $ttlhours = (int)get_config('local_grupomakro_core', 'attendance_qr_dynamic_ip_ttl_hours');
        if ($ttlhours < 1) {
            $ttlhours = 48;
        }
        $now = time();

        $rows = $DB->get_records_sql(
            'SELECT id, ip, source, registered_by_userid, registered_at, expires_at, label
             FROM {gmk_institute_ip_registry}
             WHERE expires_at > :now
             ORDER BY expires_at DESC',
            ['now' => $now]
        );

        $active = [];
        $nextexpiry = 0;
        foreach ($rows as $r) {
            $active[] = [
                'ip' => (string)$r->ip,
                'source' => (string)$r->source,
                'registered_at' => (int)$r->registered_at,
                'expires_at' => (int)$r->expires_at,
                'label' => (string)($r->label ?? ''),
            ];
            if ($nextexpiry === 0 || (int)$r->expires_at < $nextexpiry) {
                $nextexpiry = (int)$r->expires_at;
            }
        }

        return [
            'kill_switch' => $kill,
            'allowlist' => $allowlist,
            'ttl_hours' => $ttlhours,
            'active' => $active,
            'next_expiry' => $nextexpiry,
            'server_time' => $now,
        ];
    }

    public static function get_status_returns(): external_single_structure {
        return new external_single_structure([
            'kill_switch'  => new external_value(PARAM_BOOL, 'Estado del kill switch.'),
            'allowlist'    => new external_value(PARAM_TEXT, 'Allowlist est\u00e1tica cruda (l\u00edneas separadas).'),
            'ttl_hours'    => new external_value(PARAM_INT, 'TTL en horas de cada IP registrada.'),
            'active'       => new external_multiple_structure(
                new external_single_structure([
                    'ip' => new external_value(PARAM_TEXT, 'IP vigente'),
                    'source' => new external_value(PARAM_TEXT, 'Origen'),
                    'registered_at' => new external_value(PARAM_INT, 'Unix timestamp de registro'),
                    'expires_at' => new external_value(PARAM_INT, 'Unix timestamp de expiraci\u00f3n'),
                    'label' => new external_value(PARAM_TEXT, 'Etiqueta opcional'),
                ])
            ),
            'next_expiry'  => new external_value(PARAM_INT, 'Pr\u00f3xima expiraci\u00f3n (0 si no hay IPs activas).'),
            'server_time'  => new external_value(PARAM_INT, 'Unix timestamp actual del server.'),
        ]);
    }

    /**
     * Register the current REMOTE_ADDR (or an explicit IP) as an institute IP
     * for the configured TTL. Returns the inserted row summary.
     */
    public static function register_parameters(): external_function_parameters {
        return new external_function_parameters([
            'label' => new external_value(PARAM_TEXT, 'Etiqueta opcional (p.ej. "docente X desde el lab")', VALUE_DEFAULT, ''),
        ]);
    }

    public static function register(string $label = ''): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::register_parameters(), ['label' => $label]);

        $remoteip = getremoteaddr(['WS_SERVER', 'HTTP_X_FORWARDED_FOR']);
        if ($remoteip === '' || $remoteip === null) {
            return ['status' => 'error', 'message' => 'No se pudo determinar tu IP actual.'];
        }

        $ttlhours = (int)get_config('local_grupomakro_core', 'attendance_qr_dynamic_ip_ttl_hours');
        if ($ttlhours < 1) {
            $ttlhours = 48;
        }
        $now = time();
        $expires = $now + ($ttlhours * 3600);

        // UPSERT: si ya existe una fila vigente para esta IP, extiende su TTL;
        // si existe una vencida, la sobreescribimos (DELETE + INSERT en una
        // transaccion para mantener UNIQUE consistente).
        try {
            $transaction = $DB->start_delegated_transaction();
            $existing = $DB->get_records('gmk_institute_ip_registry', ['ip' => $remoteip]);
            foreach ($existing as $row) {
                $DB->delete_records('gmk_institute_ip_registry', ['id' => $row->id]);
            }
            $record = (object)[
                'ip' => $remoteip,
                'source' => 'teacher_button',
                'registered_by_userid' => (int)$USER->id,
                'registered_at' => $now,
                'expires_at' => $expires,
                'label' => trim($label),
            ];
            $id = $DB->insert_record('gmk_institute_ip_registry', $record);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => 'No se pudo registrar la IP: ' . $e->getMessage()];
        }

        return [
            'status' => 'success',
            'message' => 'IP registrada correctamente.',
            'id' => (int)$id,
            'ip' => $remoteip,
            'registered_at' => $now,
            'expires_at' => $expires,
            'ttl_hours' => $ttlhours,
        ];
    }

    public static function register_returns(): external_single_structure {
        return new external_single_structure([
            'status'  => new external_value(PARAM_TEXT, 'success o error'),
            'message' => new external_value(PARAM_TEXT, 'Mensaje de resultado'),
            'id'      => new external_value(PARAM_INT, 'ID de la fila insertada (en errores = 0)', VALUE_DEFAULT, 0),
            'ip'      => new external_value(PARAM_TEXT, 'IP registrada', VALUE_DEFAULT, ''),
            'registered_at' => new external_value(PARAM_INT, 'Unix timestamp de registro', VALUE_DEFAULT, 0),
            'expires_at'    => new external_value(PARAM_INT, 'Unix timestamp de expiraci\u00f3n', VALUE_DEFAULT, 0),
            'ttl_hours'     => new external_value(PARAM_INT, 'TTL aplicado en horas', VALUE_DEFAULT, 0),
        ]);
    }
}