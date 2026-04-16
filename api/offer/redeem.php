<?php
/**
 * Card Offer Redemption Endpoint
 *
 * GET /api/offer/redeem.php?oid=<offer_id>&eid=<employee_id>
 *
 * - Atomically increments redemption_count on a non-expired, enabled offer.
 * - Logs `offer_redeem` event via CardAnalytics.
 * - Tenant scoping: oid must belong to eid (no cross-employee redemption).
 * - JSON when Accept: application/json or ?format=json, else 302 back to the
 *   employee's public card page.
 */

require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/CardSections.php';
require_once INCLUDES_DIR . '/CardAnalytics.php';

$offerId    = trim($_GET['oid'] ?? '');
$employeeId = trim($_GET['eid'] ?? '');

$wantsJson = false;
if (!empty($_GET['format']) && strtolower($_GET['format']) === 'json') {
    $wantsJson = true;
} elseif (!empty($_SERVER['HTTP_ACCEPT']) && stripos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
    $wantsJson = true;
}

function offer_respond_error($code, $msg, $wantsJson)
{
    http_response_code($code);
    if ($wantsJson) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => $msg]);
    } else {
        echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
    }
    exit;
}

if ($offerId === '' || $employeeId === '') {
    offer_respond_error(400, 'Missing oid or eid.', $wantsJson);
}

try {
    $employee = findEmployeeById($employeeId);
} catch (Throwable $e) {
    error_log('offer redeem lookup: ' . $e->getMessage());
    $employee = null;
}
if (!$employee || empty($employee['company_id'])) {
    offer_respond_error(404, 'Card not found.', $wantsJson);
}

$offer = CardSections::redeemOffer($employeeId, $offerId);
if (!$offer) {
    offer_respond_error(410, 'Offer expired, disabled, or not found.', $wantsJson);
}

try {
    CardAnalytics::log(
        $employee['id'],
        $employee['company_id'],
        'offer_redeem',
        'offer:' . $offerId
    );
} catch (Throwable $e) {
    // Never block the user response on analytics failure.
    error_log('offer_redeem analytics: ' . $e->getMessage());
}

if ($wantsJson) {
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'offer_id' => $offerId,
        'redemption_count' => (int)$offer['redemption_count'],
    ]);
    exit;
}

// Build redirect back to the public card page.
$companySlug = '';
try {
    $db = Database::getInstance();
    $row = $db->fetchOne("SELECT slug FROM companies WHERE id = :id", ['id' => $employee['company_id']]);
    if ($row && !empty($row['slug'])) {
        $companySlug = $row['slug'];
    }
} catch (Throwable $e) {
    error_log('offer redeem company lookup: ' . $e->getMessage());
}

$dest = '/';
if ($companySlug !== '') {
    $dest = '/' . rawurlencode($companySlug) . '/card/' . rawurlencode($employeeId) . '?redeemed=' . rawurlencode($offerId);
}
header('Location: ' . $dest, true, 302);
exit;
