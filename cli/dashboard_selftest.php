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
 * Dashboard administrativo: prueba de punta a punta SIN dejar rastro.
 *
 *   php dashboard_selftest.php --staff=<userid de un administrativo> [--student=<userid de prueba>]
 *
 * Todo corre dentro de UNA transaccion que al final se revierte siempre
 * (pendientes de prueba y filas de gmk_dash_alert). La configuracion dash_* de la prueba vive solo
 * en memoria (config::use_memory): el cron que corra mientras tanto sigue leyendo la real.
 * No se envia ningun mensaje: la escalera y el resumen se corren con
 * $send = false y solo se revisan los mensajes que se habrian enviado.
 * Lo unico que avanza son los contadores AUTO_INCREMENT de las tablas usadas.
 *
 * Recorre:
 *  1. ANS: horas habiles (12/16/32), fin de semana con sabado medio dia,
 *     dias habiles (1/2/5), fecha limite "antes" (7/5/2) y umbrales incoherentes.
 *  2. Fuentes con datos de prueba: retiro (sin recibir), webhook fallido,
 *     modulo pagado sin matricular y cita de psicologia (privada: sin nombre).
 *  3. "Lo mio" frente a "Mi equipo" (una cita de otro psicologo no es mia).
 *  4. Escalera: primera pasada en silencio, segunda sin nada nuevo, vencido
 *     avisa al responsable y a supervisores, critico a gerencia, sale de la
 *     cola y vuelve (se avisa otra vez), sin duplicados.
 *  5. Resumen diario (si hoy es dia habil) y una vez por dia.
 *  6. Tiempo de armado del dashboard del --staff en ambas vistas.
 *
 * @package    local_grupomakro_core
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_grupomakro_core\local\dashboard\config;
use local_grupomakro_core\local\dashboard\dashboard;
use local_grupomakro_core\local\dashboard\escalation;
use local_grupomakro_core\local\dashboard\registry;
use local_grupomakro_core\local\dashboard\sla;
use local_grupomakro_core\local\support\business_hours;

list($options, $unrecognized) = cli_get_params(['staff' => 0, 'student' => 0, 'help' => false], ['h' => 'help']);
if ($options['help'] || (int)$options['staff'] <= 0) {
    cli_writeln("Uso: php dashboard_selftest.php --staff=<userid de un administrativo> [--student=<userid de prueba>]\n"
        . "Corre el dashboard, el ANS y la escalera dentro de una transaccion y la revierte. No envia mensajes.");
    exit(0);
}
$staffid = (int)$options['staff'];
$studentid = (int)$options['student'] ?: $staffid;
foreach ([$staffid, $studentid] as $uid) {
    if (!$DB->record_exists('user', ['id' => $uid, 'deleted' => 0])) {
        cli_error("El usuario $uid no existe.");
    }
}
if (!$DB->get_manager()->table_exists('gmk_dash_alert')) {
    cli_error('Falta la tabla gmk_dash_alert: corre primero admin/cli/upgrade.php.');
}

$results = [];
$check = function(string $label, bool $ok, string $detail = '') use (&$results) {
    $results[] = [$ok, $label, $detail];
    cli_writeln(($ok ? '  PASS ' : '  FAIL ') . $label . ($detail !== '' ? '  [' . $detail . ']' : ''));
};

/**
 * Inserts a row filling every NOT NULL column without default.
 */
$insert = function(string $table, array $fields) use ($DB): int {
    $row = [];
    foreach ($DB->get_columns($table) as $name => $col) {
        if ($name === 'id' || array_key_exists($name, $fields)) {
            continue;
        }
        if (!empty($col->not_null) && empty($col->has_default)) {
            $row[$name] = in_array($col->meta_type, ['I', 'N', 'F', 'R', 'L'], true) ? 0 : 'x';
        }
    }
    return (int)$DB->insert_record($table, (object)($fields + $row));
};

$find = function(array $items, string $ref) {
    foreach ($items as $it) {
        if ((string)$it->ref === $ref) {
            return $it;
        }
    }
    return null;
};

$fullname = function(int $id) {
    $u = \core_user::get_user($id);
    return $u ? fullname($u) . " ($id)" : "($id)";
};

cli_writeln('Dashboard administrativo - prueba con rollback. Personal: ' . $fullname($staffid)
    . '; estudiante de prueba: ' . $fullname($studentid));
$transaction = $DB->start_delegated_transaction();
try {
    // Known configuration for the whole test, IN MEMORY: nothing goes to config_plugins nor to the
    // shared config cache, so cron running meanwhile keeps reading the real (switched off) values.
    config::use_memory(['dash_hours' => config::DEFAULT_HOURS]);

    // ------------------------------------------------------------------
    // 1. ANS
    // ------------------------------------------------------------------
    cli_writeln('1. ANS');
    $tz = \core_date::get_server_timezone_object();
    // A Wednesday 09:00 far enough ahead to have no holidays loaded by accident.
    $wed = (new DateTimeImmutable('2031-03-12 09:00:00', $tz))->getTimestamp();
    $holiday = (int)$DB->record_exists_select('gmk_holidays', 'date >= :a AND date <= :b',
        ['a' => $wed - 3 * DAYSECS, 'b' => $wed + 30 * DAYSECS]);
    $check('sin feriados cerca de la fecha de prueba', !$holiday, userdate($wed));
    $hours = sla::hours_area();
    $days = sla::days_area();
    $mk = function(string $type, int $since, int $deadline = 0) {
        return (object)['type' => $type, 'since' => $since, 'deadline' => $deadline];
    };
    foreach ([[11 * 60, 0], [12 * 60, 1], [16 * 60, 2], [32 * 60, 3]] as [$min, $want]) {
        $now = business_hours::add_minutes($wed, $min, $hours);
        [$level, $due] = sla::evaluate($mk('wdr.receive', $wed), $now);
        $check("retiro sin recibir a las " . ($min / 60) . " h hábiles = nivel $want", $level === $want,
            "nivel $level, ahora " . userdate($now, '%a %d %H:%M'));
    }
    $check('vence a las 16 h hábiles (miércoles 9:00 + 8 h + 8 h = jueves 16:00)',
        $due === (new DateTimeImmutable('2031-03-13 16:00:00', $tz))->getTimestamp(), userdate($due, '%a %d %H:%M'));
    $sat = (new DateTimeImmutable('2031-03-15 11:00:00', $tz))->getTimestamp();
    $mon = business_hours::add_minutes($sat, 120, $hours);
    $check('sábado 11:00 + 2 h hábiles = lunes 9:00 (sábado cierra a las 12)',
        userdate($mon, '%u %H:%M') === '1 09:00', userdate($mon, '%a %d %H:%M'));
    foreach ([[0, 0], [1, 1], [2, 2], [5, 3]] as [$n, $want]) {
        $now = business_hours::add_minutes($wed, $n * 1440 + 1, $days);
        [$level] = sla::evaluate($mk('revalida.grade', $wed), $now);
        $check("revalida sin nota a los $n día(s) hábiles = nivel $want", $level === $want, "nivel $level");
    }
    $sun = (new DateTimeImmutable('2031-03-16 10:00:00', $tz))->getTimestamp();
    $check('el domingo no es día hábil', !business_hours::is_open($days, $sun));
    foreach ([[10, 0], [7, 1], [5, 2], [2, 3], [-1, 3]] as [$n, $want]) {
        $deadline = $n >= 0 ? business_hours::add_minutes($wed, $n * 1440, $days) : $wed - DAYSECS;
        [$level, $due] = sla::evaluate($mk('class.approval', 0, $deadline), $wed);
        $check("clase sin aprobar a $n día(s) hábiles del inicio = nivel $want", $level === $want, "nivel $level");
    }
    $threw = false;
    try {
        config::set('dash_sla', ['wdr.receive' => ['warn' => 20, 'due' => 10, 'crit' => 30]]);
    } catch (\invalid_parameter_exception $e) {
        $threw = true;
    }
    $check('umbrales incoherentes se rechazan (amarillo después de vencido)', $threw);
    $off = ['wdr.receive' => ['on' => 0]];
    config::set('dash_sla', $off);
    config::reset_cache();
    [$level] = sla::evaluate($mk('wdr.receive', $wed), $wed + 40 * DAYSECS);
    $check('un tipo apagado no tiene semáforo', $level === 0);
    config::set('dash_sla', []);

    // ------------------------------------------------------------------
    // 2. Sources with test data (ages relative to the real now)
    // ------------------------------------------------------------------
    cli_writeln('2. Fuentes');
    $now = time();
    $old = $now - 60 * DAYSECS; // Old enough to be critical in every aging SLA.
    $tag = 'SELFTEST-' . $now;
    $wdrid = $insert('gmk_wdr', ['userid' => $studentid, 'request_number' => $tag, 'fullname' => 'Prueba Dashboard',
        'status' => 'solicitada', 'timecreated' => $old, 'timemodified' => $old]);
    $dlqid = $insert('gmk_financial_webhook_dlq', ['reason' => 'invoice_paid', 'state' => 'pending', 'attempts' => 1,
        'timecreated' => $now - 30 * MINSECS]);
    $modid = $insert('gmk_module_invoice_requests', ['userid' => $studentid, 'status' => 'paid', 'enrolled_classid' => 0,
        'paidat' => $old, 'timecreated' => $old, 'timemodified' => $old]);
    $otherpsy = (int)$DB->get_field_sql("SELECT MIN(id) FROM {user} WHERE deleted = 0 AND id <> :a AND id <> :b AND id > 2",
        ['a' => $staffid, 'b' => $studentid]);
    $psyid = $insert('gmk_wellness_psy_appts', ['userid' => $studentid, 'psychologist_userid' => $otherpsy,
        'status' => 'pendiente', 'appointment_at' => $now + 3 * DAYSECS, 'reason' => 'motivo privado de prueba',
        'timecreated' => $old, 'timemodified' => $old]);

    $src = registry::sources(true);
    $items = dashboard::source_items($src['withdrawals'], $staffid, 'all', $now);
    $it = $find($items, $tag);
    $check('retiro de prueba aparece como sin recibir', $it && $it->type === 'wdr.receive');
    $check('retiro de hace 60 días = crítico', $it && $it->level === sla::LEVEL_CRIT, $it ? 'nivel ' . $it->level : '');
    $items = dashboard::source_items($src['webhook_dlq'], $staffid, 'all', $now);
    $it = $find($items, 'DLQ-' . $dlqid);
    $check('webhook fallido de hace 30 min = en plazo o por vencer', $it && $it->level <= sla::LEVEL_WARN,
        $it ? 'nivel ' . $it->level : 'no aparece');
    $items = dashboard::source_items($src['modules_paid'], $staffid, 'all', $now);
    $check('módulo pagado sin matricular aparece', (bool)$find($items, 'MOD-' . $modid));
    $items = dashboard::source_items($src['psychology'], $staffid, 'all', $now);
    $it = $find($items, 'CITA-' . $psyid);
    $student = \core_user::get_user($studentid);
    $leak = $it && (stripos($it->title . $it->subtitle, (string)$student->lastname) !== false
        || stripos($it->title . $it->subtitle, 'motivo') !== false);
    $check('cita de psicología aparece sin nombre ni motivo', $it && !$leak, $it ? $it->title : 'no aparece');
    $check('la cita es del psicólogo asignado', $it && (int)$it->owner === $otherpsy);

    // ------------------------------------------------------------------
    // 3. Lo mio / Mi equipo
    // ------------------------------------------------------------------
    cli_writeln('3. Lo mío y Mi equipo');
    $mine = dashboard::source_items($src['psychology'], $staffid, 'mine', $now);
    $check('la cita de otro psicólogo no está en "lo mío"', !$find($mine, 'CITA-' . $psyid));
    $team = dashboard::source_items($src['psychology'], $staffid, 'team', $now);
    $check('la cita sí está en "mi equipo"', (bool)$find($team, 'CITA-' . $psyid));
    $minewdr = dashboard::source_items($src['withdrawals'], $staffid, 'mine', $now);
    $check('un retiro sin responsable está en "lo mío" (cola del rol)', (bool)$find($minewdr, $tag));

    // ------------------------------------------------------------------
    // 4. Escalation ladder
    // ------------------------------------------------------------------
    cli_writeln('4. Escalera');
    $DB->delete_records('gmk_dash_alert');
    config::set('dash_alerts_enabled', 0);
    $r = escalation::run($now, false);
    $check('alertas apagadas: no hace nada', $r['items'] === 0 && $r['new'] === 0);
    config::set('dash_alerts_enabled', 1);
    config::set('dash_alerts_primed', 0);
    $check('la configuración de prueba no llega a la base ni a la caché compartida (el cron no la ve)',
        !get_config('local_grupomakro_core', 'dash_alerts_enabled') && config::get_int('dash_alerts_enabled') === 1);
    $r = escalation::run($now, false);
    $check('primera pasada en silencio: registra niveles sin mensajes', $r['silent'] === 1 && $r['messages'] === 0
        && $DB->record_exists('gmk_dash_alert', ['srckey' => 'withdrawals', 'itemref' => $tag]), json_encode($r));
    $check('primera pasada marcada', config::get_int('dash_alerts_primed') === 1);
    $r = escalation::run($now, false);
    $check('segunda pasada: nada nuevo, ningún mensaje', $r['new'] === 0 && $r['messages'] === 0, json_encode($r));

    // The withdrawal leaves the queue: its rows go away.
    $DB->set_field('gmk_wdr', 'status', 'procesada', ['id' => $wdrid]);
    $r = escalation::run($now, false);
    $check('sale de la cola: sus niveles se borran',
        !$DB->record_exists('gmk_dash_alert', ['srckey' => 'withdrawals', 'itemref' => $tag]), json_encode($r));
    // Comes back (reopened, new clock): notified again, now not silent.
    $DB->update_record('gmk_wdr', (object)['id' => $wdrid, 'status' => 'solicitada', 'timecreated' => $old + 60]);
    $r = escalation::run($now, false);
    $mine = array_filter(escalation::$lastmessages, function($m) {
        return strpos($m->body, 'Prueba Dashboard') !== false;
    });
    $check('vuelve a la cola: se avisa de nuevo', $r['new'] >= 1 && count($mine) >= 1, json_encode($r));
    $queue = $src['withdrawals']->queue_users();
    $sups = escalation::resolve_people((string)config::sla('wdr.receive')['sup']);
    $ger = escalation::gerencia();
    $to = array_map(function($m) {
        return (int)$m->userid;
    }, $mine);
    $check('crítico: lo reciben la cola del rol, supervisores y gerencia',
        !array_diff(array_unique(array_merge($queue, $sups, $ger)), $to),
        'cola ' . count($queue) . ', supervisores ' . count($sups) . ', gerencia ' . count($ger) . ', mensajes ' . count($to));
    $one = reset($mine);
    $check('mensaje crítico por el proveedor de gerencia', $one && $one->provider === 'dashboard_escalation',
        $one ? $one->provider . ' · ' . $one->subject : '');
    $check('un solo mensaje por persona y pasada', count($to) === count(array_unique($to)));
    $r = escalation::run($now, false);
    $check('repetir la pasada no duplica', $r['new'] === 0 && $r['messages'] === 0, json_encode($r));
    $dups = $DB->get_records_sql("SELECT srckey, itemref, alertlevel, COUNT(1) AS n FROM {gmk_dash_alert}
                                   GROUP BY srckey, itemref, alertlevel HAVING COUNT(1) > 1");
    $check('sin filas duplicadas en gmk_dash_alert', !$dups);

    // Supervisor spec parsing.
    $people = escalation::resolve_people('user:' . $staffid . ',rol_que_no_existe');
    $check('supervisores "user:<id>" y rol inexistente', $people === [$staffid], json_encode($people));

    // ------------------------------------------------------------------
    // 5. Digest
    // ------------------------------------------------------------------
    cli_writeln('5. Resumen diario');
    config::set('dash_digest_enabled', 1);
    config::set('dash_digest_hour', 0);
    config::set('dash_digest_last', '');
    $d = escalation::digest($now, false);
    if ($d['skipped'] === 'día no hábil') {
        $check('hoy no es día hábil: no se envía', $d['messages'] === 0);
    } else {
        $check('resumen preparado para quien tiene pendientes', $d['skipped'] === '' && $d['users'] > 0,
            json_encode($d));
        $d2 = escalation::digest($now, false);
        $check('una sola vez por día', $d2['skipped'] === 'ya enviado hoy', json_encode($d2));
    }

    // ------------------------------------------------------------------
    // 6. Staff dashboard and timing
    // ------------------------------------------------------------------
    cli_writeln('6. Dashboard de ' . $fullname($staffid));
    foreach (['mine', 'team'] as $scope) {
        if ($scope === 'team' && !dashboard::can_team($staffid)) {
            cli_writeln('  (sin vista de equipo para este usuario)');
            continue;
        }
        $t0 = microtime(true);
        $data = dashboard::build($staffid, $scope, $now);
        $ms = (int)round((microtime(true) - $t0) * 1000);
        $errors = array_filter($data['cards'], function($c) {
            return $c['error'] !== '';
        });
        $check("vista $scope sin fuentes con error", !$errors, implode(', ', array_column($errors, 'key')));
        $check("vista $scope en menos de 1500 ms", $ms < 1500, $ms . ' ms, ' . count($data['cards']) . ' tarjetas, '
            . $data['totals']['total'] . ' pendientes');
        foreach ($data['cards'] as $c) {
            cli_writeln(sprintf('     %-34s %4d  (crit %d, venc %d, por vencer %d)', $c['label'], $c['counts']['total'],
                $c['counts']['critical'], $c['counts']['breached'], $c['counts']['warning']));
        }
    }
    $json = json_encode(dashboard::build($staffid, 'mine', $now));
    $check('la respuesta del WS no lleva el motivo de la cita', strpos($json, 'motivo privado') === false);
} catch (\Throwable $e) {
    $check('excepcion inesperada', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

try {
    $transaction->rollback(new \moodle_exception('dashboard_selftest_rollback'));
} catch (\moodle_exception $e) {
    // Expected: rollback() rethrows.
}
config::use_database();
dashboard::invalidate_all();

$failed = count(array_filter($results, function($r) {
    return !$r[0];
}));
cli_writeln('');
cli_writeln(sprintf('%d pruebas, %d fallas. Todo revertido (filas de gmk_dash_alert: %d; alertas activas: %d; inicio: %d).',
    count($results), $failed, $DB->count_records('gmk_dash_alert'), (int)get_config('local_grupomakro_core', 'dash_alerts_enabled'),
    (int)get_config('local_grupomakro_core', 'dash_home_enabled')));
exit($failed ? 1 : 0);
