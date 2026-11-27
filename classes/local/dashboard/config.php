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
 * Dashboard administrativo: parametros (config_plugins, prefijo dash_).
 *
 * Valores decididos por Ramiro el 19-nov-2026 (PLAN_DASHBOARD_ADMINISTRATIVO.md
 * §2.2 y §7): horario L-V 08:00-17:00 y sabado 08:00-12:00, ANS de la tabla,
 * primera pasada de alertas en silencio, administradores del sitio sin
 * redireccion y solo notificacion web por defecto. Todo se edita en
 * pages/dashboard_settings.php; la logica nunca lee constantes directamente.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\dashboard;

defined('MOODLE_INTERNAL') || die();

class config {

    const COMPONENT = 'local_grupomakro_core';

    /** Horario administrativo por defecto (mismo formato que gmk_tkt_area.hours; 1 = lunes). */
    const DEFAULT_HOURS = '{"1":[["08:00","17:00"]],"2":[["08:00","17:00"]],"3":[["08:00","17:00"]],'
        . '"4":[["08:00","17:00"]],"5":[["08:00","17:00"]],"6":[["08:00","12:00"]]}';

    /**
     * name => [default, type ('int'|'text'), etiqueta].
     */
    const SETTINGS = [
        'dash_home_enabled'   => [0, 'int', 'Dashboard como página de inicio de los administrativos (1 sí, 0 no)'],
        'dash_alerts_enabled' => [0, 'int', 'Alertas de ANS activas (1 sí, 0 no)'],
        'dash_alerts_primed'  => [0, 'int', 'Primera pasada en silencio ya hecha (interno: 0 = la próxima pasada no avisa)'],
        'dash_digest_enabled' => [0, 'int', 'Resumen diario activo (1 sí, 0 no)'],
        'dash_digest_hour'    => [7, 'int', 'Hora del resumen diario (0-23, solo días hábiles)'],
        'dash_digest_last'    => ['', 'text', 'Último día con resumen enviado (interno, AAAA-MM-DD)'],
        'dash_hours'          => [self::DEFAULT_HOURS, 'text', 'Horario administrativo para contar el ANS (JSON)'],
        'dash_sources_off'    => ['', 'text', 'Fuentes apagadas (claves separadas por coma)'],
        'dash_sla'            => ['', 'json', 'ANS por tipo de pendiente'],
    ];

    /**
     * ANS por tipo. kind:
     *  - 'h': horas hábiles desde que empezó a esperar.
     *  - 'd': días hábiles desde que empezó a esperar.
     *  - 'before': días hábiles ANTES de una fecha límite (inicio del periodo o de la clase).
     * warn = amarillo, due = vencido, crit = crítico (a gerencia); crit < 0 = sin crítico.
     * sup = supervisores: shortnames de rol separados por coma, "wellness:<rolekey>" (gmk_wellness_staff_role)
     * o "user:<id>".
     */
    const SLA_DEFAULTS = [
        'wdr.receive'        => ['kind' => 'h', 'warn' => 4, 'due' => 8, 'crit' => 16, 'sup' => 'gmk_director_academico',
            'label' => 'Retiros: solicitud nueva sin tomar (Bienestar)'],
        'wdr.followup'       => ['kind' => 'before', 'warn' => 1, 'due' => 0, 'crit' => -1, 'sup' => 'gmk_director_academico',
            'label' => 'Retiros: seguimiento programado'],
        'wdr.manage'         => ['kind' => 'd', 'warn' => 2, 'due' => 3, 'crit' => 7, 'sup' => 'gmk_director_academico',
            'label' => 'Retiros: en gestión sin seguimiento programado'],
        'wdr.escalation'     => ['kind' => 'h', 'warn' => 8, 'due' => 16, 'crit' => 40, 'sup' => 'gmk_director_academico',
            'label' => 'Retiros: caso escalado sin respuesta'],
        'wdr.process'        => ['kind' => 'h', 'warn' => 18, 'due' => 24, 'crit' => 48, 'sup' => 'gmk_director_academico',
            'label' => 'Retiros: retiro formal pendiente (Secretaría / Registros)'],
        'letters.elaborar'   => ['kind' => 'h', 'warn' => 12, 'due' => 16, 'crit' => 32, 'sup' => 'gmk_secretaria_academica',
            'label' => 'Cartas: por elaborar (solo si la bandeja de cartas no trae su ANS)'],
        'letters.atascada'   => ['kind' => 'h', 'warn' => 3, 'due' => 4, 'crit' => 8, 'sup' => 'gmk_soporte_ti',
            'label' => 'Cartas: atascada en pagada o solicitada (ídem)'],
        'letters.sin_recoger' => ['kind' => 'd', 'warn' => 5, 'due' => 10, 'crit' => -1, 'sup' => 'gmk_secretaria_academica',
            'label' => 'Cartas: lista y sin recoger (ídem)'],
        'planning.next'      => ['kind' => 'before', 'warn' => 20, 'due' => 15, 'crit' => 10,
            'sup' => 'gmk_director_academico,gmk_director_general', 'label' => 'Planificación del próximo periodo'],
        'class.approval'     => ['kind' => 'before', 'warn' => 7, 'due' => 5, 'crit' => 2, 'sup' => 'gmk_director_academico',
            'label' => 'Clases sin aprobar antes del inicio'],
        'class.closing'      => ['kind' => 'd', 'warn' => 2, 'due' => 3, 'crit' => 10, 'sup' => 'gmk_director_academico',
            'label' => 'Clases sin cerrar (tras los 7 días de gracia)'],
        'revalida.grade'     => ['kind' => 'd', 'warn' => 1, 'due' => 2, 'crit' => 5, 'sup' => 'gmk_director_academico',
            'label' => 'Revalidas sin nota tras la sesión'],
        'revalida.invoice'   => ['kind' => 'h', 'warn' => 3, 'due' => 4, 'crit' => 16, 'sup' => 'gmk_soporte_ti',
            'label' => 'Revalidas con la factura fallida'],
        // 20261001131: el plazo es para el PRIMER CONTACTO del caso (2 dias habiles, acordado con Ramiro).
        'absence.block'      => ['kind' => 'd', 'warn' => 1, 'due' => 2, 'crit' => 4, 'sup' => 'gmk_secretaria_academica',
            'label' => 'Inasistencias: caso sin primer contacto'],
        'absence.followup'   => ['kind' => 'h', 'warn' => 1, 'due' => 8, 'crit' => 24, 'sup' => 'gmk_secretaria_academica',
            'label' => 'Inasistencias: seguimiento programado vencido'],
        'wellness.attendance' => ['kind' => 'd', 'warn' => 1, 'due' => 2, 'crit' => 5, 'sup' => 'wellness:bienestar_jefe',
            'label' => 'Eventos de Bienestar sin asistencia'],
        'wellness.waitlist'  => ['kind' => 'before', 'warn' => 3, 'due' => 1, 'crit' => -1, 'sup' => 'wellness:bienestar_jefe',
            'label' => 'Eventos próximos con lista de espera'],
        'psy.confirm'        => ['kind' => 'h', 'warn' => 4, 'due' => 8, 'crit' => 16, 'sup' => 'wellness:bienestar_jefe',
            'label' => 'Citas de psicología sin confirmar'],
        'psy.close'          => ['kind' => 'h', 'warn' => 8, 'due' => 16, 'crit' => 40, 'sup' => 'wellness:bienestar_jefe',
            'label' => 'Citas de psicología pasadas sin cerrar'],
        'modules.paid'       => ['kind' => 'h', 'warn' => 6, 'due' => 8, 'crit' => 16, 'sup' => 'gmk_secretaria_academica',
            'label' => 'Módulos pagados sin matricular'],
        'modules.overdue'    => ['kind' => 'd', 'warn' => 1, 'due' => 3, 'crit' => 10, 'sup' => 'gmk_director_academico',
            'label' => 'Módulos vencidos sin completar'],
        'dlq.pending'        => ['kind' => 'h', 'warn' => 2, 'due' => 4, 'crit' => 8, 'sup' => 'gmk_soporte_ti',
            'label' => 'Webhooks financieros fallidos'],
    ];

    /**
     * @var array|null in-memory settings for the selftest: when set, get() and set() use ONLY this
     * array. set_config() inside a rolled back transaction would still leave the test values in the
     * shared MUC config cache, where cron in another process could read them.
     */
    private static $override = null;

    /**
     * Test mode: every dash_* setting is read from and written to memory (defaults + $values).
     */
    public static function use_memory(array $values): void {
        self::$override = $values;
        self::reset_cache();
    }

    public static function use_database(): void {
        self::$override = null;
        self::reset_cache();
    }

    /**
     * @return int|string
     */
    public static function get(string $name) {
        if (!isset(self::SETTINGS[$name])) {
            throw new \coding_exception('Unknown dashboard setting ' . $name);
        }
        [$default, $type] = self::SETTINGS[$name];
        $value = self::$override !== null
            ? (array_key_exists($name, self::$override) ? self::$override[$name] : false)
            : get_config(self::COMPONENT, $name);
        if ($type === 'json') {
            return json_encode(self::normalise_sla($value === false ? null : json_decode((string)$value, true)));
        }
        if ($value === false || $value === null || $value === '') {
            return $default;
        }
        return $type === 'int' ? (int)$value : (string)$value;
    }

    public static function get_int(string $name): int {
        return (int)self::get($name);
    }

    /**
     * Validates without saving (settings page validates everything before saving anything).
     *
     * @throws \invalid_parameter_exception
     */
    public static function validate(string $name, $value): void {
        if (!isset(self::SETTINGS[$name])) {
            throw new \invalid_parameter_exception('Unknown dashboard setting ' . $name);
        }
        $type = self::SETTINGS[$name][1];
        if ($type === 'json') {
            $decoded = is_array($value) ? $value : json_decode((string)$value, true);
            if (!is_array($decoded)) {
                throw new \invalid_parameter_exception('JSON inválido en ' . $name);
            }
            self::normalise_sla($decoded, true);
        } else if ($type === 'int' && (int)$value < 0) {
            throw new \invalid_parameter_exception('Valor negativo en ' . $name);
        }
    }

    public static function set(string $name, $value): void {
        if (!isset(self::SETTINGS[$name])) {
            throw new \invalid_parameter_exception('Unknown dashboard setting ' . $name);
        }
        $type = self::SETTINGS[$name][1];
        if ($type === 'json') {
            $decoded = is_array($value) ? $value : json_decode((string)$value, true);
            if (!is_array($decoded)) {
                throw new \invalid_parameter_exception('JSON inválido en ' . $name);
            }
            $value = json_encode(self::normalise_sla($decoded, true));
        } else if ($type === 'int') {
            $value = (int)$value;
            if ($value < 0) {
                throw new \invalid_parameter_exception('Valor negativo en ' . $name);
            }
        } else {
            $value = (string)$value;
        }
        if (self::$override !== null) {
            self::$override[$name] = $value;
            self::reset_cache();
            return;
        }
        set_config($name, $value, self::COMPONENT);
        self::reset_cache();
        dashboard::invalidate_all();
    }

    /**
     * Completes the SLA map with the defaults. With $strict, invalid values throw.
     */
    public static function normalise_sla(?array $data, bool $strict = false): array {
        $out = [];
        foreach (self::SLA_DEFAULTS as $type => $def) {
            $row = is_array($data[$type] ?? null) ? $data[$type] : [];
            $clean = ['kind' => $def['kind'], 'label' => $def['label']];
            foreach (['warn', 'due', 'crit'] as $k) {
                $v = array_key_exists($k, $row) && is_numeric($row[$k]) ? (int)$row[$k] : $def[$k];
                $clean[$k] = $v;
            }
            $clean['sup'] = isset($row['sup']) ? trim(preg_replace('/[^a-z0-9_:,]/i', '', (string)$row['sup'])) : $def['sup'];
            $clean['on'] = array_key_exists('on', $row) ? (int)!empty($row['on']) : 1;
            $ok = $def['kind'] === 'before'
                ? ($clean['warn'] >= $clean['due'] && ($clean['crit'] < 0 || $clean['crit'] <= $clean['due']) && $clean['due'] >= 0)
                : ($clean['warn'] > 0 && $clean['due'] >= $clean['warn'] && ($clean['crit'] < 0 || $clean['crit'] >= $clean['due']));
            if (!$ok) {
                if ($strict) {
                    throw new \invalid_parameter_exception('Umbrales incoherentes en ' . $def['label']);
                }
                foreach (['warn', 'due', 'crit'] as $k) {
                    $clean[$k] = $def[$k];
                }
            }
            $out[$type] = $clean;
        }
        return $out;
    }

    /** @var array|null per-request SLA map */
    private static $sla = null;

    public static function sla(string $type): ?array {
        if (self::$sla === null) {
            self::$sla = json_decode((string)self::get('dash_sla'), true) ?: [];
        }
        return self::$sla[$type] ?? null;
    }

    public static function reset_cache(): void {
        self::$sla = null;
        sla::reset_cache();
    }

    public static function source_enabled(string $key): bool {
        $off = array_filter(array_map('trim', explode(',', (string)self::get('dash_sources_off'))));
        return !in_array($key, $off, true);
    }
}
