<?php
// This file is part of Moodle - https://moodle.org/

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');

header('Content-Type: application/json');

try {
    $providedtoken = optional_param('token', '', PARAM_RAW_TRIMMED);
    if ($providedtoken === '') {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        if (!empty($headers['X-Grace-Token'])) {
            $providedtoken = trim((string)$headers['X-Grace-Token']);
        } else if (!empty($headers['x-grace-token'])) {
            $providedtoken = trim((string)$headers['x-grace-token']);
        }
    }

    $configuredtoken = (string)get_config('local_grupomakro_core', 'grace_period_token');
    if ($configuredtoken === '') {
        $configuredtoken = 'gmk_grace_check_2026';
    }
    if ($configuredtoken === '' || $providedtoken === '' || !hash_equals($configuredtoken, $providedtoken)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'invalid_token']);
        exit;
    }

    $raw = get_config('local_grupomakro_core', 'overdue_grace_days');
    $days = is_numeric($raw) ? max(0, (int)$raw) : 3;

    echo json_encode([
        'status' => 'success',
        'days'   => $days,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'error'  => $e->getMessage(),
    ]);
}
