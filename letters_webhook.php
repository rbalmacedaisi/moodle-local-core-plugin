<?php
// This file is part of Moodle - https://moodle.org/

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');

use local_grupomakro_core\local\letters\manager;

header('Content-Type: application/json');

try {
    $providedtoken = optional_param('token', '', PARAM_RAW_TRIMMED);
    if ($providedtoken === '') {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        if (!empty($headers['X-Webhook-Token'])) {
            $providedtoken = trim((string)$headers['X-Webhook-Token']);
        } else if (!empty($headers['x-webhook-token'])) {
            $providedtoken = trim((string)$headers['x-webhook-token']);
        }
    }

    $configuredtoken = (string)get_config('local_grupomakro_core', 'letter_webhook_token');
    if ($configuredtoken === '') {
        $configuredtoken = (string)get_config('local_grupomakro_core', 'grace_period_token');
    }
    if ($configuredtoken === '' || $providedtoken === '' || !hash_equals($configuredtoken, $providedtoken)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'invalid_token']);
        exit;
    }

    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'invalid_payload']);
        exit;
    }

    $result = manager::handle_payment_webhook($payload);
    echo json_encode($result);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}

