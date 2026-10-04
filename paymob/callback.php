<?php
/**
 * Paymob Payment Callback
 * Routes through Payment.php for new payments, falls back to Billing.php for legacy
 * Handles both GET redirect (user returns) and POST webhook (server-to-server)
 */
session_start();
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Payment.php';

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

// Collect callback data
if ($isPost) {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true) ?: [];
    $hmac = $data['hmac'] ?? ($_GET['hmac'] ?? null);
} else {
    $data = $_GET;
    $hmac = $_GET['hmac'] ?? null;
}

// IQ test purchases (cardify.om/iq) carry an IQ_ reference and have their own table.
$__flat = Payment::flattenCallback($data, $_GET ?? []);
$__ref = $__flat['merchant_order_id'] ?? ($__flat['special_reference'] ?? null);
if (is_string($__ref) && strncmp($__ref, 'IQ_', 3) === 0) {
    require_once INCLUDES_DIR . '/iq/IqPay.php';
    $iq = IqPay::handleCallback($data, $hmac);
    if ($isPost) {
        header('Content-Type: application/json');
        http_response_code($iq['success'] ? 200 : 400);
        echo json_encode(['status' => $iq['success'] ? 'success' : 'error', 'message' => $iq['error'] ?? '']);
        exit;
    }
    $dest = ($iq['product'] ?? '') === 'report' && !empty($iq['attempt'])
        ? '/iq/report/' . rawurlencode($iq['attempt'])
        : '/iq/account';
    header('Location: https://' . (defined('APP_HOST') ? APP_HOST : 'cardify.om') . $dest . '?payment=' . ($iq['success'] ? 'success' : 'failed'));
    exit;
}

// Try Payment.php first (new unified handler)
$result = Payment::handleCallback($data, $hmac);

// If not found in new payments table, fall back to legacy Billing
if (!$result['success'] && !empty($result['legacy'])) {
    require_once INCLUDES_DIR . '/Billing.php';
    $config = [
        'public_key' => defined('PAYMOB_PUBLIC_KEY') ? PAYMOB_PUBLIC_KEY : '',
        'secret_key' => defined('PAYMOB_SECRET_KEY') ? PAYMOB_SECRET_KEY : '',
        'hmac_secret' => defined('PAYMOB_HMAC_SECRET') ? PAYMOB_HMAC_SECRET : '',
        'integration_ids' => defined('PAYMOB_INTEGRATION_IDS') ? PAYMOB_INTEGRATION_IDS : ''
    ];
    $billing = new Billing('paymob', $config);
    $result = $billing->handleLegacyPaymobCallback($data, $hmac);
}

if ($isPost) {
    // Webhook: return JSON response
    header('Content-Type: application/json');
    http_response_code($result['success'] ? 200 : 400);
    echo json_encode(['status' => $result['success'] ? 'success' : 'error', 'message' => $result['error'] ?? '']);
    exit;
}

// GET redirect: route user to correct page based on payment type.
// Never use $_SERVER['HTTP_HOST'] here, it is attacker-controlled and would
// let a Host header injection rewrite the success URL to a third-party domain.
$host = defined('APP_HOST') ? APP_HOST : 'cardify.om';
$baseUrl = 'https://' . $host;
$type = $result['type'] ?? 'subscription';

if ($type === 'logo_pass' || ($_GET['logo'] ?? '') === '1') {
    header('Location: ' . $baseUrl . '/logo-access.php?payment=returned&order=' . rawurlencode($result['reference_id'] ?? ''));
    exit;
}

if ($result['success']) {
    if ($type === 'print_order') {
        $orderId = $result['reference_id'] ?? '';
        header('Location: ' . $baseUrl . getBasePath() . 'admin/order-checkout.php?payment=success&order=' . urlencode($orderId));
    } else {
        header('Location: ' . $baseUrl . getBasePath() . 'admin/billing.php?payment=success');
    }
} else {
    $error = urlencode($result['error'] ?? 'Payment failed');
    if ($type === 'print_order') {
        $orderId = $result['reference_id'] ?? '';
        header('Location: ' . $baseUrl . getBasePath() . 'admin/order-checkout.php?payment=error&order=' . urlencode($orderId) . '&message=' . $error);
    } else {
        $status = $result['status'] ?? 'failed';
        header('Location: ' . $baseUrl . getBasePath() . 'admin/billing.php?payment=error&message=' . $error);
    }
}
exit;
