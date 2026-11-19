<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Cartas (20261001130): plazos (ANS), alertas y recordatorios.
 *
 * - Plazo por tipo y estado en minutos HABILES (gmk_letter_type.sla_json sobre
 *   el valor general letters_sla_default), con el horario del area Academico y
 *   los feriados de gmk_holidays (support\business_hours).
 * - Niveles: 0 en plazo, 1 por vencer (75 %), 2 vencida (100 %), 3 critica (200 %).
 *   Cada nivel se avisa UNA vez por estado (sla_level se reinicia al cambiar de
 *   estado) y los avisos de una pasada se agrupan: un solo mensaje por persona.
 *   Nivel 1 -> responsable (o equipo); 2 -> + supervisores; 3 -> + gerencia.
 * - Recordatorios al estudiante: pago (letters_pay_remind_days), anulacion por
 *   falta de pago (letters_pay_cancel_days) y retiro (letters_pickup_remind_days
 *   habiles).
 * - Resumen diario de cartas vencidas al equipo y a los supervisores.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_grupomakro_core\local\letters;

use local_grupomakro_core\local\support\business_hours;
use local_grupomakro_core\local\support\config as supportconfig;
use stdClass;

defined('MOODLE_INTERNAL') || die();

class sla {

    const LEVEL_OK = 0;
    const LEVEL_WARN = 1;
    const LEVEL_BREACHED = 2;
    const LEVEL_CRITICAL = 3;

    const LEVEL_LABELS = [0 => 'en plazo', 1 => 'por vencer', 2 => 'vencida', 3 => 'crítica'];

    /** Minutos habiles por estado (0 / ausente = sin plazo). 1 dia habil = 780 min (08:00-21:00). */
    const DEFAULT_SLA = [
        'solicitada' => 60,
        'pagada' => 60,
        'pendiente_gestion' => 1560,
        'pendiente_recoleccion' => 11700,
    ];

    /** Bits of gmk_letter_request.reminders. */
    const REMINDED_PAYMENT = 1;
    const REMINDED_PICKUP = 2;

    const SETTINGS = [
        'letters_pay_remind_days' => 3,
        'letters_pay_cancel_days' => 15,
        'letters_pickup_remind_days' => 5,
        'letters_alerts_enabled' => 1,
    ];

    public static function setting(string $name): int {
        $v = get_config('local_grupomakro_core', $name);
        return ($v === false || $v === '') ? (int)(self::SETTINGS[$name] ?? 0) : (int)$v;
    }

    /**
     * Budgets of a letter type: status => business minutes.
     */
    public static function budgets(?stdClass $type): array {
        $budgets = self::DEFAULT_SLA;
        $general = json_decode((string)get_config('local_grupomakro_core', 'letters_sla_default'), true);
        foreach ([(array)$general, (array)json_decode((string)($type->sla_json ?? ''), true)] as $over) {
            foreach ($over as $status => $minutes) {
                if (is_string($status) && is_numeric($minutes)) {
                    $budgets[$status] = max(0, (int)$minutes);
                }
            }
        }
        return $budgets;
    }

    public static function budget(?stdClass $type, string $status): int {
        return (int)(self::budgets($type)[$status] ?? 0);
    }

    /**
     * Fields to store when a letter enters $status at $now.
     */
    public static function enter_fields(?stdClass $type, string $status, int $now): array {
        $budget = self::budget($type, $status);
        return [
            'status_since' => $now,
            'due_at' => $budget > 0 ? business_hours::add_minutes($now, $budget, team::area()) : 0,
            'sla_level' => self::LEVEL_OK,
            'sla_notified_at' => 0,
        ];
    }

    public static function level_for(int $elapsed, int $budget): int {
        if ($budget <= 0) {
            return self::LEVEL_OK;
        }
        if ($elapsed >= 2 * $budget) {
            return self::LEVEL_CRITICAL;
        }
        if ($elapsed >= $budget) {
            return self::LEVEL_BREACHED;
        }
        if ($elapsed * 4 >= $budget * 3) {
            return self::LEVEL_WARN;
        }
        return self::LEVEL_OK;
    }

    /**
     * Current level of a request (read only, business minutes since status_since).
     */
    public static function current_level(stdClass $request, ?stdClass $type, int $now = 0): int {
        $budget = self::budget($type, (string)$request->status);
        if ($budget <= 0 || !in_array((string)$request->status, team::OWNED_STATUSES, true)) {
            return self::LEVEL_OK;
        }
        $since = (int)$request->status_since ?: (int)$request->timemodified;
        return self::level_for(business_hours::elapsed($since, $now ?: time(), team::area()), $budget);
    }

    /**
     * One pass of the scheduled task. $ids limits it to those requests (selftest).
     *
     * @return array stats
     */
    public static function run(int $now = 0, array $ids = []): array {
        global $DB;
        $now = $now ?: time();
        $stats = ['assigned' => 0, 'raised' => 0, 'alerts' => 0, 'payreminders' => 0, 'cancelled' => 0,
            'pickupreminders' => 0, 'digest' => 0];
        if (!self::setting('letters_alerts_enabled')) {
            return $stats;
        }
        [$sin, $params] = $DB->get_in_or_equal(team::OWNED_STATUSES, SQL_PARAMS_NAMED, 'st');
        $where = "r.status $sin";
        if ($ids) {
            [$iin, $iparams] = $DB->get_in_or_equal(array_map('intval', $ids), SQL_PARAMS_NAMED, 'id');
            $where .= " AND r.id $iin";
            $params += $iparams;
        }
        $rows = $DB->get_records_sql("SELECT r.* FROM {gmk_letter_request} r WHERE $where ORDER BY r.id", $params);
        $types = $DB->get_records('gmk_letter_type');

        // 1) Nobody owns it: assign (the letters that need someone of the team).
        foreach ($rows as $r) {
            if ((int)$r->assignedto === 0 && in_array($r->status, ['pendiente_gestion', 'pendiente_recoleccion'], true)) {
                $agent = team::pick($now);
                if ($agent > 0) {
                    // Las anteriores a la bandeja no mandan un aviso por carta: van en la alerta agrupada.
                    manager::assign((int)$r->id, $agent, 0, 'auto', empty($r->legacy));
                    $r->assignedto = $agent;
                    $stats['assigned']++;
                }
            }
        }

        // 2) Levels: each level once per status; grouped per recipient.
        $outbox = [];
        foreach ($rows as $r) {
            $type = $types[(int)$r->lettertypeid] ?? null;
            $level = self::current_level($r, $type, $now);
            if ($level <= (int)$r->sla_level) {
                continue;
            }
            $DB->update_record('gmk_letter_request', (object)['id' => (int)$r->id, 'sla_level' => $level,
                'sla_notified_at' => $now]);
            manager::log('sla_level', (int)$r->id, (string)$r->status, (string)$r->status,
                'Plazo: ' . self::LEVEL_LABELS[$level], 0, ['from' => (int)$r->sla_level, 'to' => $level]);
            $stats['raised']++;
            $r->sla_level = $level;
            $recipients = (int)$r->assignedto > 0 ? [(int)$r->assignedto] : team::recipients_unassigned();
            if ($level >= self::LEVEL_BREACHED) {
                $recipients = array_merge($recipients, team::supervisors());
            }
            if ($level >= self::LEVEL_CRITICAL) {
                $recipients = array_merge($recipients, team::gerencia());
            }
            foreach (team::active($recipients) as $uid) {
                $outbox[$uid][(int)$r->id] = $r;
            }
        }
        foreach ($outbox as $uid => $items) {
            if (self::send_alert($uid, $items, $types, $now)) {
                $stats['alerts']++;
            }
        }

        // 3) Student reminders.
        $stats = array_merge($stats, self::student_reminders($now, $ids, $types));

        // 4) Daily digest (only in the real task).
        if (!$ids) {
            $stats['digest'] = self::maybe_send_digest($now);
        }
        return $stats;
    }

    /**
     * Payment reminder, cancellation for non-payment and pickup reminder.
     */
    private static function student_reminders(int $now, array $ids, array $types): array {
        global $DB;
        $out = ['payreminders' => 0, 'cancelled' => 0, 'pickupreminders' => 0];
        $params = [];
        $extra = '';
        if ($ids) {
            [$iin, $params] = $DB->get_in_or_equal(array_map('intval', $ids), SQL_PARAMS_NAMED, 'id');
            $extra = " AND id $iin";
        }
        $remind = self::setting('letters_pay_remind_days') * DAYSECS;
        $cancel = self::setting('letters_pay_cancel_days') * DAYSECS;
        foreach ($DB->get_records_select('gmk_letter_request', "status = 'pendiente_pago'$extra", $params) as $r) {
            $since = (int)$r->status_since ?: (int)$r->timemodified;
            if ($cancel > 0 && $now - $since >= $cancel) {
                manager::set_request_status((int)$r->id, manager::STATUS_CANCELADA, 0,
                    'Anulada automáticamente: no se registró el pago en ' . self::setting('letters_pay_cancel_days')
                    . ' días. Puedes volver a solicitarla cuando quieras.', ['auto' => 'payment_timeout']);
                $out['cancelled']++;
                continue;
            }
            if ($remind > 0 && $now - $since >= $remind && !((int)$r->reminders & self::REMINDED_PAYMENT)) {
                $DB->set_field('gmk_letter_request', 'reminders', (int)$r->reminders | self::REMINDED_PAYMENT,
                    ['id' => (int)$r->id]);
                manager::notify_student($r, 'payment_reminder');
                manager::log('reminder', (int)$r->id, 'pendiente_pago', 'pendiente_pago', 'Recordatorio de pago', 0);
                $out['payreminders']++;
            }
        }
        $pickup = self::setting('letters_pickup_remind_days') * 780;
        foreach ($DB->get_records_select('gmk_letter_request', "status = 'pendiente_recoleccion'$extra", $params) as $r) {
            if ($pickup <= 0 || ((int)$r->reminders & self::REMINDED_PICKUP)) {
                continue;
            }
            $since = (int)$r->status_since ?: (int)$r->timemodified;
            if (business_hours::elapsed($since, $now, team::area()) >= $pickup) {
                $DB->set_field('gmk_letter_request', 'reminders', (int)$r->reminders | self::REMINDED_PICKUP,
                    ['id' => (int)$r->id]);
                manager::notify_student($r, 'pickup_reminder');
                manager::log('reminder', (int)$r->id, 'pendiente_recoleccion', 'pendiente_recoleccion',
                    'Recordatorio de retiro', 0);
                $out['pickupreminders']++;
            }
        }
        return $out;
    }

    /**
     * One grouped alert to $uid about $items (requestid => row).
     */
    private static function send_alert(int $uid, array $items, array $types, int $now): bool {
        $to = \core_user::get_user($uid);
        if (!$to || !empty($to->deleted) || !empty($to->suspended)) {
            return false;
        }
        $max = 0;
        $counts = [1 => 0, 2 => 0, 3 => 0];
        foreach ($items as $r) {
            $max = max($max, (int)$r->sla_level);
            $counts[(int)$r->sla_level]++;
        }
        $parts = [];
        foreach ([3 => 'crítica', 2 => 'vencida', 1 => 'por vencer'] as $l => $label) {
            if ($counts[$l] > 0) {
                $parts[] = $counts[$l] . ' ' . ($counts[$l] === 1 ? $label : self::plural($label));
            }
        }
        $subject = 'Cartas: ' . implode(', ', $parts);
        $tab = $max >= self::LEVEL_BREACHED ? 'overdue' : 'mine';
        $url = (new \moodle_url('/local/grupomakro_core/pages/letter_desk.php', ['tab' => $tab]))->out(false);
        $body = "Solicitudes de carta que necesitan atención:\n\n" . self::lines($items, $types, $now)
            . "\n\nAbrir la bandeja de cartas: " . $url;
        manager::send_message('letter_sla_alert', $to, $subject, $body, $url, 'Bandeja de cartas');
        return true;
    }

    private static function plural(string $label): string {
        return ['crítica' => 'críticas', 'vencida' => 'vencidas', 'por vencer' => 'por vencer'][$label] ?? $label;
    }

    /**
     * Text lines for alerts and digest (max 20).
     */
    private static function lines(array $items, array $types, int $now): string {
        $users = [];
        foreach ($items as $r) {
            $users[] = (int)$r->userid;
            $users[] = (int)$r->assignedto;
        }
        $names = manager::user_names($users);
        $labels = manager::get_status_labels();
        $lines = [];
        $n = 0;
        foreach ($items as $r) {
            if (++$n > 20) {
                $lines[] = '… y ' . (count($items) - 20) . ' más.';
                break;
            }
            $type = $types[(int)$r->lettertypeid] ?? null;
            $since = (int)$r->status_since ?: (int)$r->timemodified;
            $elapsed = business_hours::elapsed($since, $now, team::area());
            $line = '• Carta #' . (int)$r->id . ' · ' . ($type ? format_string($type->name) : '') . ' · '
                . ($names[(int)$r->userid] ?? '') . ' · ' . ($labels[$r->status] ?? $r->status)
                . ' hace ' . self::format_business($elapsed) . ' · ' . self::LEVEL_LABELS[(int)$r->sla_level]
                . ' · Responsable: ' . ((int)$r->assignedto > 0 ? ($names[(int)$r->assignedto] ?? '') : 'sin asignar');
            if (!empty($r->legacy)) {
                $line .= ' · Anterior a la bandeja: confirma si ya se gestionó.';
            }
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    /**
     * Business minutes as "3 h" / "2 d hábiles" (1 d = 780 min).
     */
    public static function format_business(int $minutes): string {
        if ($minutes < 60) {
            return max(0, $minutes) . ' min hábiles';
        }
        if ($minutes < 780) {
            return intdiv($minutes, 60) . ' h hábiles';
        }
        $d = round($minutes / 780, 1);
        return str_replace('.', ',', (string)$d) . ' d hábiles';
    }

    /**
     * Once a day from tkt_digest_hour: overdue letters to each owner, all of them
     * to the supervisors. Returns how many people got it.
     */
    public static function maybe_send_digest(int $now = 0, bool $force = false): int {
        global $DB;
        $now = $now ?: time();
        $local = (new \DateTimeImmutable('@' . $now))->setTimezone(\core_date::get_server_timezone_object());
        $today = $local->format('Y-m-d');
        if (!$force && ((int)$local->format('G') < supportconfig::get_int('tkt_digest_hour')
                || get_config('local_grupomakro_core', 'letters_digest_last') === $today)) {
            return 0;
        }
        if (!$force) {
            set_config('letters_digest_last', $today, 'local_grupomakro_core');
        }
        [$sin, $params] = $DB->get_in_or_equal(team::OWNED_STATUSES, SQL_PARAMS_NAMED, 'st');
        $rows = $DB->get_records_select('gmk_letter_request', "status $sin AND sla_level >= :lvl",
            $params + ['lvl' => self::LEVEL_BREACHED], 'sla_level DESC, status_since ASC');
        if (!$rows) {
            return 0;
        }
        $types = $DB->get_records('gmk_letter_type');
        $byowner = [];
        foreach ($rows as $r) {
            $owners = (int)$r->assignedto > 0 ? [(int)$r->assignedto] : team::recipients_unassigned();
            foreach ($owners as $uid) {
                $byowner[$uid][(int)$r->id] = $r;
            }
        }
        foreach (team::supervisors() as $uid) {
            $byowner[$uid] = $rows;
        }
        $url = (new \moodle_url('/local/grupomakro_core/pages/letter_desk.php', ['tab' => 'overdue']))->out(false);
        $sent = 0;
        foreach ($byowner as $uid => $items) {
            $to = \core_user::get_user($uid);
            if (!$to || !empty($to->deleted) || !empty($to->suspended)) {
                continue;
            }
            $subject = 'Resumen de cartas vencidas: ' . count($items);
            $body = "Cartas fuera de plazo al " . $local->format('d/m/Y') . ":\n\n" . self::lines($items, $types, $now)
                . "\n\nAbrir la bandeja de cartas: " . $url;
            manager::send_message('letter_sla_alert', $to, $subject, $body, $url, 'Bandeja de cartas');
            $sent++;
        }
        return $sent;
    }
}
