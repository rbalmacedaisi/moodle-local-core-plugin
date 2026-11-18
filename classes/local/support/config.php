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
 * Mesa de ayuda: parametros generales (config_plugins, prefijo tkt_).
 *
 * Todo lo decidido el 17-nov-2026 es editable desde pages/support_settings.php
 * (§12-bis del plan). Aqui solo viven los valores por defecto y la lectura;
 * nada de la logica lee constantes.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\support;

defined('MOODLE_INTERNAL') || die();

class config {

    const COMPONENT = 'local_grupomakro_core';

    /**
     * name => [default, type ('int'|'text'), etiqueta para la pagina de configuracion].
     * Los umbrales del semaforo y el resumen diario se guardan ya en la fase 1
     * para que se puedan ajustar antes de que la fase 2 los use.
     */
    const SETTINGS = [
        'tkt_max_open'          => [5,    'int',  'Tickets abiertos por estudiante (máximo)'],
        'tkt_max_daily'         => [10,   'int',  'Tickets nuevos por estudiante por día (máximo)'],
        'tkt_remind_hours'      => [12,   'int',  'Horas sin respuesta del estudiante para el recordatorio (0 = no)'],
        'tkt_close_hours'       => [24,   'int',  'Horas sin respuesta del estudiante para el cierre automático'],
        'tkt_autoclose_enabled' => [1,    'int',  'Cierre automático activo (1 sí, 0 no)'],
        'tkt_sla_green'         => [20,   'int',  'Semáforo: meta de respuesta (min hábiles)'],
        'tkt_sla_yellow'        => [60,   'int',  'Semáforo: amarillo desde (min hábiles)'],
        'tkt_sla_orange'        => [180,  'int',  'Semáforo: naranja desde (min hábiles)'],
        'tkt_sla_red'           => [300,  'int',  'Semáforo: rojo / vencido desde (min hábiles)'],
        'tkt_red_repeat'        => [120,  'int',  'Repetir aviso a gerencia en rojo cada (min hábiles, 0 = no)'],
        'tkt_escalation_enabled' => [1,   'int',  'Escalamiento automático activo (1 sí, 0 no)'],
        'tkt_ack_minutes'       => [5,    'int',  'Sin acuse (abrir o tomar): avisar al respaldo a los (min hábiles, 0 = no)'],
        'tkt_ack_admins'        => [1,    'int',  'Sin acuse: avisar también a los administradores del sitio (1 sí, 0 no)'],
        'tkt_mail_gap'          => [10,   'int',  'Correo: como máximo uno cada (min) por ticket y persona'],
        'tkt_badge_poll'        => [30,   'int',  'Indicador flotante: consultar cada (segundos, pestaña visible)'],
        'tkt_snooze_minutes'    => [5,    'int',  'Bloqueo en pantalla: posponer (min)'],
        'tkt_snooze_max'        => [2,    'int',  'Bloqueo en pantalla: veces que se puede posponer'],
        'tkt_attach_maxmb'      => [10,   'int',  'Tamaño máximo de adjunto (MB, fase 3)'],
        'tkt_attach_types'      => ['pdf,jpg,png,docx', 'text', 'Tipos de adjunto permitidos (fase 3)'],
        'tkt_rating_enabled'    => [1,    'int',  'Calificación de la atención activa (1 sí, 0 no)'],
        'tkt_rating_low'        => [2,    'int',  'Calificación igual o menor que avisa a supervisores'],
        'tkt_rating_days'       => [7,    'int',  'Pedir calificación de tickets cerrados en los últimos (días)'],
        'tkt_digest_hour'       => [7,    'int',  'Hora del resumen diario a gerencia y supervisores (0-23)'],
        'tkt_digest_enabled'    => [1,    'int',  'Resumen diario activo (1 sí, 0 no)'],
        // JSON: se editan en la pestaña "Avisos y escalamiento" (ver policy).
        'tkt_escalation'        => ['', 'json', 'Escalera de escalamiento por nivel'],
        'tkt_incisive'          => ['', 'json', 'Perfiles de aviso incisivo por área, administradores y persona'],
    ];

    /**
     * @return int|string
     */
    public static function get(string $name) {
        if (!isset(self::SETTINGS[$name])) {
            throw new \coding_exception('Unknown support setting ' . $name);
        }
        [$default, $type] = self::SETTINGS[$name];
        $value = get_config(self::COMPONENT, $name);
        if ($type === 'json') {
            return json_encode(policy::normalise($name, $value === false ? null : json_decode((string)$value, true)));
        }
        if ($value === false || $value === null || $value === '') {
            return $default;
        }
        return $type === 'int' ? (int)$value : (string)$value;
    }

    /**
     * Decoded JSON setting, always complete (defaults filled in).
     */
    public static function get_json(string $name): array {
        return json_decode((string)self::get($name), true) ?: [];
    }

    public static function get_int(string $name): int {
        return (int)self::get($name);
    }

    /**
     * @return array name => ['value' => current, 'default' => default, 'type' => type, 'label' => label]
     */
    public static function all(): array {
        $out = [];
        foreach (self::SETTINGS as $name => [$default, $type, $label]) {
            if ($type === 'json') {
                $default = json_encode(policy::normalise($name, null));
            }
            $out[$name] = ['value' => self::get($name), 'default' => $default, 'type' => $type, 'label' => $label];
        }
        return $out;
    }

    /**
     * Saves one setting. Returns the previous value (for the audit event).
     *
     * @param int|string $value
     * @return int|string
     */
    public static function set(string $name, $value) {
        if (!isset(self::SETTINGS[$name])) {
            throw new \invalid_parameter_exception('Unknown support setting ' . $name);
        }
        $old = self::get($name);
        $type = self::SETTINGS[$name][1];
        if ($type === 'json') {
            $decoded = is_array($value) ? $value : json_decode((string)$value, true);
            if (!is_array($decoded)) {
                throw new \invalid_parameter_exception('JSON inválido en ' . $name);
            }
            $value = json_encode(policy::normalise($name, $decoded, true));
        } else if ($type === 'int') {
            $value = (int)$value;
            if ($value < 0) {
                throw new \invalid_parameter_exception('Negative value for ' . $name);
            }
        } else {
            $value = trim(clean_param((string)$value, PARAM_TEXT));
        }
        set_config($name, $value, self::COMPONENT);
        return $old;
    }

    /**
     * Base URL of the student portal (same setting the messaging uses).
     */
    public static function portal_url(string $path = ''): string {
        global $CFG;
        $base = rtrim((string)get_config(self::COMPONENT, 'student_app_url'), '/');
        if ($base === '') {
            $base = $CFG->wwwroot;
        }
        return $base . $path;
    }
}
