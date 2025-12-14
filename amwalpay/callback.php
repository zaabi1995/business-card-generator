<?php
/**
 * Amwal Pay Payment Callback Endpoint
 * This endpoint receives payment status from Amwal Pay
 * Based on Amwal Pay Laravel package structure
 */
session_start();
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Billing.php';

header('Content-Type: application/json');

// Get callback data from POST
$callbackData = $_POST;

// Initialize billing with Amwal Pay config
$billing = new Billing('amwal', [
    'merchant_id' => AMWAL_MERCHANT_ID ?? '',
    'terminal_id' => AMWAL_TERMINAL_ID ?? '',
    'secure_key' => AMWAL_SECURE_KEY ?? '',
    'api_url' => AMWAL_API_URL ?? 'https://backend.sa.amwal.tech',
    'callback_url' => (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . getBasePath() . 'amwalpay/callback.php',
    'return_url' => (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . getBasePath() . 'admin/billing.php'
]);

// Handle callback
$result = $billing->handleWebhook($callbackData, null);

if ($result['success']) {
    // Redirect to success page
    $returnUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . getBasePath() . 'admin/billing.php?payment=success&order_id=' . ($callbackData['OrderId'] ?? $callbackData['order_id'] ?? '');
    header('Location: ' . $returnUrl);
    exit;
} else {
    // Redirect to error page
    $returnUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . getBasePath() . 'admin/billing.php?payment=error&message=' . urlencode($result['error'] ?? 'Payment processing failed');
    header('Location: ' . $returnUrl);
    exit;
}
