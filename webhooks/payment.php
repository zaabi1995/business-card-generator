<?php
/**
 * Payment Webhook Handler
 * Handles payment notifications from Amwal Pay and other gateways
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Billing.php';

header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

// Get webhook payload
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_SIGNATURE'] ?? $_SERVER['HTTP_X_AMWAL_SIGNATURE'] ?? '';

// Determine gateway from headers or config
$gateway = $_SERVER['HTTP_X_GATEWAY'] ?? (defined('BILLING_GATEWAY') ? BILLING_GATEWAY : 'paymob');

// Get gateway config
$config = [];
if ($gateway === 'paymob') {
    $config = [
        'public_key' => defined('PAYMOB_PUBLIC_KEY') ? PAYMOB_PUBLIC_KEY : '',
        'secret_key' => defined('PAYMOB_SECRET_KEY') ? PAYMOB_SECRET_KEY : '',
        'hmac_secret' => defined('PAYMOB_HMAC_SECRET') ? PAYMOB_HMAC_SECRET : '',
        'integration_ids' => defined('PAYMOB_INTEGRATION_IDS') ? PAYMOB_INTEGRATION_IDS : ''
    ];
} elseif ($gateway === 'amwal') {
    $config = [
        'merchant_id' => defined('AMWAL_MERCHANT_ID') ? AMWAL_MERCHANT_ID : '',
        'terminal_id' => defined('AMWAL_TERMINAL_ID') ? AMWAL_TERMINAL_ID : '',
        'secure_key' => defined('AMWAL_SECURE_KEY') ? AMWAL_SECURE_KEY : '',
        'api_url' => defined('AMWAL_API_URL') ? AMWAL_API_URL : 'https://backend.sa.amwal.tech'
    ];
}

// Require valid webhook signature when secure key is configured (Amwal legacy)
if ($gateway === 'amwal' && !empty($config['secure_key']) && empty($signature)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Missing webhook signature']);
    error_log('Payment webhook: Missing signature from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    exit;
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
