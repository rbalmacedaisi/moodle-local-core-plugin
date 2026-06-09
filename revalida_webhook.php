<?php
// This file is part of Moodle - https://moodle.org/
//
// Receives revalidation invoice payment notifications from the Express proxy
// (forwarded from Odoo) and marks the gmk_revalidations row as paid.

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');

use local_grupomakro_core\local\revalida_manager;

require_once($CFG->dirroot . '/local/grupomakro_core/classes/local/revalida_manager.php');

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

    $configuredtoken = (string)get_config('local_grupomakro_core', 'revalida_webhook_token');
    if ($configuredtoken === '') {
        $configuredtoken = (string)get_config('local_grupomakro_core', 'letter_webhook_token');
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

    $result = revalida_manager::handle_payment_webhook($payload);
    echo json_encode($result);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
