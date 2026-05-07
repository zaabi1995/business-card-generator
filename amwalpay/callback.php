<?php
/**
 * Amwal Pay Payment Callback Endpoint
 * This endpoint receives payment status from Amwal Pay.
 *
 * Hardened 2026-05-06: never use \$_SERVER['HTTP_HOST'] when building
 * outbound redirect URLs (cardify rule 8). Attacker-controlled Host
 * header would let a malicious payment-callback request rewrite the
 * success/error redirect to evil.com, hijacking the user's session
 * landing. Pin to APP_HOST constant.
 */
session_start();
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Billing.php';

header('Content-Type: application/json');

$callbackData = $_POST;

$host = defined('APP_HOST') ? APP_HOST : 'cardify.om';
$baseUrl = 'https://' . $host;

// Note: 'defined' guard for the constants too; using bare ?? on an
// undefined constant raises a "use of undefined constant" warning.
$billing = new Billing('amwal', [
    'merchant_id'   => defined('AMWAL_MERCHANT_ID') ? AMWAL_MERCHANT_ID : '',
    'terminal_id'   => defined('AMWAL_TERMINAL_ID') ? AMWAL_TERMINAL_ID : '',
    'secure_key'    => defined('AMWAL_SECURE_KEY')  ? AMWAL_SECURE_KEY  : '',
    'api_url'       => defined('AMWAL_API_URL')     ? AMWAL_API_URL     : 'https://backend.sa.amwal.tech',
    'callback_url'  => $baseUrl . getBasePath() . 'amwalpay/callback.php',
    'return_url'    => $baseUrl . getBasePath() . 'admin/billing.php',
]);

$signature = $_SERVER['HTTP_X_AMWAL_SIGNATURE'] ?? $_POST['Signature'] ?? $_POST['signature'] ?? null;

$result = $billing->handleWebhook($callbackData, $signature);

if ($result['success']) {
    $orderId = $callbackData['OrderId'] ?? $callbackData['order_id'] ?? '';
    header('Location: ' . $baseUrl . getBasePath() . 'admin/billing.php?payment=success&order_id=' . urlencode($orderId));
    exit;
} else {
    $msg = urlencode($result['error'] ?? 'Payment processing failed');
    header('Location: ' . $baseUrl . getBasePath() . 'admin/billing.php?payment=error&message=' . $msg);
    exit;
}
