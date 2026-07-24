<?php
/**
 * check_orphan_module_enrollments.php
 *
 * Verifica que no existan "huérfanos" en las tablas de módulos:
 *   - gmk_module_enrollment: inscripciones a módulos cuyo classid ya no
 *     existe en gmk_class.
 *   - gmk_module_invoice_requests.enrolled_classid: solicitudes de
 *     factura de módulos cuyo classid ya no existe.
 *
 * Este script es READ-ONLY: NO modifica la base de datos. Si encuentra
 * huérfanos, los reporta y sale con código 1 (para alertar a cron/monitor).
 *
 * Origen del bug que motiva este check (2026-07-24):
 *   El scheduler de períodos y reset_period_publish.php eliminaban
 *   gmk_class sin limpiar gmk_module_enrollment ni
 *   gmk_module_invoice_requests, causando que los módulos de los
 *   estudiantes desaparecieran silenciosamente de LXP.
 *
 * Uso:
 *   php local/grupomakro_core/cli/check_orphan_module_enrollments.php
 *
 * Salida:
 *   Línea por huérfano + totales + exit code (0 = OK, 1 = huérfanos)
 *
 * Cron sugerido:
 *   0 * * * * php /var/www/html/moodle/local/grupomakro_core/cli/check_orphan_module_enrollments.php \
 *     >> /var/log/moodle/orphan_enrollments.log 2>&1
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

global $DB;

$now = time();
$totalEnrollmentOrphans  = 0;
$totalInvoiceOrphans    = 0;

cli_writeln('=== Orphan Module Enrollments Check ===');
cli_writeln("Timestamp: " . userdate($now, '%Y-%m-%d %H:%M:%S'));
cli_writeln('');

// ---------------------------------------------------------------------------
// 1. gmk_module_enrollment: classid que no existe en gmk_class
// ---------------------------------------------------------------------------
$enrollmentOrphans = $DB->get_records_sql(
    "SELECT me.id          AS enrollment_id,
            me.userid,
            u.firstname,
            u.lastname,
            u.username,
            me.classid     AS bad_classid,
            me.status,
            me.enrolldate,
            me.timemodified
       FROM {gmk_module_enrollment} me
  LEFT JOIN {gmk_class} cl ON cl.id = me.classid
  LEFT JOIN {user}      u  ON u.id  = me.userid
      WHERE cl.id IS NULL
   ORDER BY me.timemodified DESC"
);

if (empty($enrollmentOrphans)) {
    cli_writeln('[OK] gmk_module_enrollment: 0 huérfanos.');
} else {
    $totalEnrollmentOrphans = count($enrollmentOrphans);
    cli_writeln("[WARN] gmk_module_enrollment: {$totalEnrollmentOrphans} inscripción(es) huérfana(s):");
    cli_writeln(str_repeat('-', 110));
    cli_writeln(sprintf('  %-8s %-12s %-25s %-12s %-10s %-12s %-12s',
        'ID', 'userid', 'estudiante', 'classid', 'status', 'enrolldate', 'modificado'));
    cli_writeln(str_repeat('-', 110));
    foreach ($enrollmentOrphans as $row) {
        $name = trim(($row->firstname ?? '') . ' ' . ($row->lastname ?? ''));
        cli_writeln(sprintf('  %-8s %-12s %-25s %-12s %-10s %-12s %-12s',
            $row->enrollment_id,
            $row->userid,
            mb_substr($name, 0, 25),
            $row->bad_classid,
            $row->status,
            userdate((int)$row->enrolldate, '%Y-%m-%d'),
            userdate((int)$row->timemodified, '%Y-%m-%d')
        ));
    }
}

cli_writeln('');

// ---------------------------------------------------------------------------
// 2. gmk_module_invoice_requests: enrolled_classid que no existe en gmk_class
// ---------------------------------------------------------------------------
$invoiceOrphans = $DB->get_records_sql(
    "SELECT r.id              AS request_id,
            r.userid,
            u.firstname,
            u.lastname,
            r.enrolled_classid AS bad_classid,
            r.corecourseid,
            r.module_type,
            r.status,
            r.payment_state,
            r.invoice_number
       FROM {gmk_module_invoice_requests} r
  LEFT JOIN {gmk_class} cl ON cl.id = r.enrolled_classid
  LEFT JOIN {user}      u  ON u.id  = r.userid
      WHERE r.enrolled_classid > 0
        AND cl.id IS NULL
   ORDER BY r.id DESC"
);

if (empty($invoiceOrphans)) {
    cli_writeln('[OK] gmk_module_invoice_requests: 0 solicitudes con enrolled_classid huérfano.');
} else {
    $totalInvoiceOrphans = count($invoiceOrphans);
    cli_writeln("[WARN] gmk_module_invoice_requests: {$totalInvoiceOrphans} solicitud(es) con enrolled_classid huérfano:");
    cli_writeln(str_repeat('-', 130));
    cli_writeln(sprintf('  %-8s %-12s %-25s %-12s %-10s %-15s %-12s %-12s %-10s',
        'ID', 'userid', 'estudiante', 'classid', 'courseid', 'module_type', 'status', 'payment', 'factura'));
    cli_writeln(str_repeat('-', 130));
    foreach ($invoiceOrphans as $row) {
        $name = trim(($row->firstname ?? '') . ' ' . ($row->lastname ?? ''));
        cli_writeln(sprintf('  %-8s %-12s %-25s %-12s %-10s %-15s %-12s %-12s %-10s',
            $row->request_id,
            $row->userid,
            mb_substr($name, 0, 25),
            $row->bad_classid,
            $row->corecourseid,
            $row->module_type,
            $row->status,
            $row->payment_state,
            $row->invoice_number
        ));
    }
}

cli_writeln('');
cli_writeln(str_repeat('=', 80));
cli_writeln('RESUMEN:');
cli_writeln("  gmk_module_enrollment huérfanos:      {$totalEnrollmentOrphans}");
cli_writeln("  gmk_module_invoice_requests huérfanos: {$totalInvoiceOrphans}");

if ($totalEnrollmentOrphans > 0 || $totalInvoiceOrphans > 0) {
    cli_writeln('');
    cli_writeln('ACCIÓN REQUERIDA:');
    cli_writeln('  Este problema oculta módulos a los estudiantes en LXP.');
    cli_writeln('  Revisar manualmente y reasignar a las clases correctas del período activo.');
    cli_writeln('  Ver: local/grupomakro_core/pages/reset_period_publish.php');
    cli_writeln('       classes/external/admin/scheduler.php (clean-up antes de delete)');
    cli_writeln(str_repeat('=', 80));
    exit(1); // Exit non-zero so cron / monitoring can alert.
}

cli_writeln('Todo OK. No se requieren acciones.');
cli_writeln(str_repeat('=', 80));
exit(0);
