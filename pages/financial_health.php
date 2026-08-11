<?php
/**
 * Health check para el flujo de sincronización financiera.
 *
 * Pensado para ser consumido por un monitor externo (UptimeRobot,
 * Grafana, Pingdom) sin autenticacion de usuario (se usa una
 * ?token=... o la IP del monitor si lo requieren).
 *
 * Devuelve:
 *   - server_time        (epoch del servidor)
 *   - gmk_financial_status.total
 *   - gmk_financial_status.fresh_lt_1h
 *   - gmk_financial_status.fresh_lt_6h
 *   - gmk_financial_status.stale_gt_24h
 *   - gmk_financial_status.stale_gt_7d
 *   - cron.last_run_unix (timestamp del ultimo run del task update_financial_status)
 *   - cron.last_run_age_seconds
 *   - dlq.pending / resolved / abandoned
 *   - health             (ok | warn | error)
 *
 * @package    local_grupomakro_core
 * @copyright  2026 Grupomakro
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

header('Content-Type: application/json');

$providedtoken = optional_param('token', '', PARAM_RAW_TRIMMED);
$expectedtoken  = (string)get_config('local_grupomakro_core', 'financial_health_token');
if ($expectedtoken !== '' && !hash_equals($expectedtoken, $providedtoken)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'invalid_token']);
    exit;
}

$now = time();

try {
    $total = (int)$DB->count_records('gmk_financial_status');
    $freshlt1h = (int)$DB->count_records_select('gmk_financial_status', 'lastupdated > ?', [$now - 3600]);
    $freshlt6h = (int)$DB->count_records_select('gmk_financial_status', 'lastupdated > ?', [$now - 6 * 3600]);
    $stalegt24h = (int)$DB->count_records_select('gmk_financial_status', 'lastupdated < ? AND lastupdated > 0', [$now - 86400]);
    $stalegt7d  = (int)$DB->count_records_select('gmk_financial_status', 'lastupdated < ? AND lastupdated > 0', [$now - 7 * 86400]);

    $dlqpending  = (int)$DB->count_records('gmk_financial_webhook_dlq', ['state' => 'pending']);
    $dlqresolved = (int)$DB->count_records('gmk_financial_webhook_dlq', ['state' => 'resolved']);
    $dlqabandoned = (int)$DB->count_records('gmk_financial_webhook_dlq', ['state' => 'abandoned']);

    // Ultimo run del cron update_financial_status.
    $lastrunrow = $DB->get_record_sql(
        "SELECT MAX(timestart) AS last_run
           FROM {task_log}
          WHERE classname = ?
            AND component = 'local_grupomakro_core'",
        ['\\local_grupomakro_core\\task\\update_financial_status']
    );
    $lastrun = $lastrunrow ? (int)$lastrunrow->last_run : 0;
    $lastrunage = $lastrun > 0 ? $now - $lastrun : null;

    // Health score: error si DLQ > 50, warn si stale > 30% o DLQ > 5.
    $stalepct = $total > 0 ? ($stalegt24h / $total) : 0;
    if ($dlqpending > 50) {
        $health = 'error';
        $message = 'DLQ con mas de 50 pendientes';
    } else if ($dlqpending > 5 || $stalepct > 0.3) {
        $health = 'warn';
        $message = 'DLQ o snapshot stale elevados';
    } else {
        $health = 'ok';
        $message = 'flujo financiero saludable';
    }

    echo json_encode([
        'success'    => true,
        'health'     => $health,
        'message'    => $message,
        'server_time'=> $now,
        'gmk_financial_status' => [
            'total'       => $total,
            'fresh_lt_1h' => $freshlt1h,
            'fresh_lt_6h' => $freshlt6h,
            'stale_gt_24h'=> $stalegt24h,
            'stale_gt_7d' => $stalegt7d,
            'stale_pct'   => round($stalepct, 4),
        ],
        'cron' => [
            'last_run_unix'         => $lastrun,
            'last_run_age_seconds'  => $lastrunage,
        ],
        'dlq' => [
            'pending'  => $dlqpending,
            'resolved' => $dlqresolved,
            'abandoned'=> $dlqabandoned,
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'health'  => 'error',
        'error'   => $e->getMessage(),
    ]);
}