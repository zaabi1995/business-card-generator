<?php
/**
 * Paymob Payment Callback
 * Handles both GET redirect (user returns from checkout) and POST webhook (server-to-server)
 */
session_start();
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Billing.php';

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

// Build config
$config = [
    'public_key' => defined('PAYMOB_PUBLIC_KEY') ? PAYMOB_PUBLIC_KEY : '',
    'secret_key' => defined('PAYMOB_SECRET_KEY') ? PAYMOB_SECRET_KEY : '',
    'hmac_secret' => defined('PAYMOB_HMAC_SECRET') ? PAYMOB_HMAC_SECRET : '',
    'integration_ids' => defined('PAYMOB_INTEGRATION_IDS') ? PAYMOB_INTEGRATION_IDS : ''
];

$billing = new Billing('paymob', $config);

// Collect callback data
$data = [];
$hmac = null;

if ($isPost) {
    // POST webhook: JSON body
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true) ?: [];
    $hmac = $data['hmac'] ?? $_GET['hmac'] ?? null;
} else {
    // GET redirect: query params
    $data = $_GET;
    $hmac = $_GET['hmac'] ?? null;
}

// Process callback
$result = $billing->handlePaymobCallback($data, $hmac);

if ($isPost) {
    // Webhook: return JSON response
    header('Content-Type: application/json');
    if ($result['success']) {
        http_response_code(200);
        echo json_encode(['status' => 'success']);
    } else {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $result['error'] ?? 'Unknown error']);
    }
    exit;
}

// GET redirect: send user back to billing page
$baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$billingUrl = $baseUrl . getBasePath() . 'admin/billing.php';

if ($result['success'] && ($result['status'] ?? '') === 'completed') {
    header('Location: ' . $billingUrl . '?payment=success');
} else {
    $errorMsg = $result['error'] ?? 'Payment was not completed';
    $status = $result['status'] ?? 'failed';
    if ($status === 'failed') {
        header('Location: ' . $billingUrl . '?payment=error&message=' . urlencode($errorMsg));
    } else {
        // Pending or unknown
        header('Location: ' . $billingUrl . '?payment=error&message=' . urlencode('Payment is being processed. Please check back shortly.'));
    }
}
exit;
