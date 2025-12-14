<?php
/**
 * Payment Webhook Handler
 * Handles payment notifications from Amwal Pay and other gateways
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Billing.php';

header('Content-Type: application/json');

// Get webhook payload
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_SIGNATURE'] ?? $_SERVER['HTTP_X_AMWAL_SIGNATURE'] ?? '';

// Determine gateway from headers or config
$gateway = $_SERVER['HTTP_X_GATEWAY'] ?? BILLING_GATEWAY ?? 'amwal';

// Get gateway config
$config = [];
if ($gateway === 'amwal') {
    $config = [
        'api_key' => AMWAL_API_KEY ?? '',
        'merchant_id' => AMWAL_MERCHANT_ID ?? '',
        'api_url' => AMWAL_API_URL ?? 'https://api.amwal.com/v1',
        'webhook_secret' => AMWAL_WEBHOOK_SECRET ?? ''
    ];
}

$billing = new Billing($gateway, $config);
$result = $billing->handleWebhook($payload, $signature);

if ($result['success']) {
    http_response_code(200);
    echo json_encode(['status' => 'success']);
} else {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $result['error'] ?? 'Unknown error']);
}
