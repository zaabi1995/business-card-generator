<?php
/**
 * JSON payment-intent endpoint for the INLINE Apple Pay + Paymob Pixel card
 * checkout. ADDITIVE to the existing hosted-redirect flow, which is untouched.
 *
 * It reuses Payment::createIntent verbatim (through PrintShopBilling::payOnline
 * for print orders, Payment::createCardOrderIntent for card credits) so the
 * `payments` row + special_reference are created IDENTICALLY to the hosted
 * path, and the SAME HMAC-gated webhook (paymob/callback.php) activates the
 * order. This endpoint only surfaces the publicKey + clientSecret that the
 * hosted checkout URL already embeds, so the browser can drive the Pixel /
 * Apple Pay element itself. It never creates a second intention per call.
 *
 * POST only, admin-authenticated, CSRF-gated. Returns:
 *   { success, publicKey, clientSecret, special_reference, fallbackUrl }
 *   { success:false, error, paid? }
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Auth.php';
require_once INCLUDES_DIR . '/Payment.php';
require_once INCLUDES_DIR . '/PrintShopBilling.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$fail = static function (int $code, string $err, array $extra = []): void {
    http_response_code($code);
    echo json_encode(array_merge(['success' => false, 'error' => $err], $extra));
    exit;
};

// State-changing endpoint: POST only (no GET side effects for scanners/prefetch).
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $fail(405, 'method_not_allowed');
}

requireAdmin(); // redirects unauthenticated callers to /login (JSON parse then fails -> hosted fallback in JS)
$companyId = getCurrentCompanyId();
if (!$companyId) {
    $fail(401, 'not_authenticated');
}

// CSRF token rides in the query string (?csrf=), same session token the page issues.
$csrf = $_GET['csrf'] ?? $_POST['csrf'] ?? '';
if (!validateCSRFToken($csrf)) {
    $fail(403, 'invalid_csrf');
}

$purpose = $_GET['purpose'] ?? $_POST['purpose'] ?? '';
$result  = null;

if ($purpose === 'print_order') {
    $orderId = (int) ($_GET['order'] ?? $_POST['order'] ?? 0);
    if ($orderId <= 0) {
        $fail(400, 'missing_order');
    }
    // Ownership + already-paid guard (mirrors admin/order-checkout.php).
    $db    = Database::getInstance();
    $order = $db->fetchOne(
        "SELECT id, company_id, payment_status FROM print_orders WHERE id = :id",
        ['id' => $orderId]
    );
    if (!$order) {
        $fail(404, 'order_not_found');
    }
    if ($order['company_id'] !== $companyId && !Auth::hasRole('super_admin')) {
        $fail(403, 'forbidden');
    }
    if (($order['payment_status'] ?? '') === 'paid') {
        $fail(409, 'already_paid', ['paid' => true]);
    }
    // Full-amount inline intent. Deposits stay on the hosted flow (unchanged).
    $result = PrintShopBilling::payOnline($orderId);

} elseif ($purpose === 'card_order') {
    $count = (int) ($_GET['count'] ?? $_POST['count'] ?? 0);
    $count = max(10, min(5000, $count));
    // Volume-tier ladder, kept in sync with admin/card-credits.php $BUNDLES.
    $tiers = [
        ['count' => 10,  'price' => 0.500],
        ['count' => 50,  'price' => 0.400],
        ['count' => 100, 'price' => 0.350],
        ['count' => 500, 'price' => 0.280],
    ];
    $price = $tiers[0]['price'];
    foreach ($tiers as $t) {
        if ($count >= $t['count']) {
            $price = $t['price'];
        }
    }
    $result = Payment::createCardOrderIntent($companyId, $count, $price, 'OMR');

} else {
    $fail(400, 'unknown_purpose');
}

if (empty($result['success']) || empty($result['client_secret']) || empty($result['public_key'])) {
    $fail(502, $result['error'] ?? 'intent_failed');
}

echo json_encode([
    'success'           => true,
    'publicKey'         => $result['public_key'],
    'clientSecret'      => $result['client_secret'],
    'special_reference' => $result['special_reference'] ?? '',
    'fallbackUrl'       => $result['checkout_url'] ?? '',
]);
