<?php
/**
 * Card Offer Redemption Endpoint
 *
 * POST /api/offer/redeem.php?oid=<offer_id>&eid=<employee_id>
 * (params may also be in POST body)
 *
 * Hardened Apr 2026 (Codex finding):
 *  - POST ONLY. GET returns 405 — stops state-changing side effects being
 *    triggered by link prefetchers, image crawlers, and email scanners.
 *  - Rate limit: 10 redemptions / IP / hour / offer_id.
 *  - Tenant scoping: oid must belong to eid (CardSections::redeemOffer
 *    enforces this in its WHERE clause).
 *  - JSON when Accept: application/json or ?format=json, else 302 back to the
 *    employee's public card page.
 */

require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/CardSections.php';
require_once INCLUDES_DIR . '/CardAnalytics.php';

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

// --------------------------------------------------------------------------
// Method guard: POST only.
// --------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    offer_respond_error(405, 'Method not allowed. Use POST.', $wantsJson);
}

// Accept params from either query string or POST body.
$offerId    = trim($_POST['oid']  ?? $_GET['oid']  ?? '');
$employeeId = trim($_POST['eid']  ?? $_GET['eid']  ?? '');

if ($offerId === '' || $employeeId === '') {
    offer_respond_error(400, 'Missing oid or eid.', $wantsJson);
}

// --------------------------------------------------------------------------
// Rate limit: 10 redemptions per IP per offer per hour.
// Counts `offer_redeem` events logged into card_events.
// --------------------------------------------------------------------------
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
try {
    $db = Database::getInstance();
    $row = $db->fetchOne(
        "SELECT COUNT(*) AS c FROM card_events
          WHERE event_type = 'offer_redeem'
            AND ip_address = :ip
            AND cta_target = :target
            AND created_at > (NOW() - INTERVAL 1 HOUR)",
        ['ip' => $ip, 'target' => 'offer:' . $offerId]
    );
    $recentCount = (int)($row['c'] ?? 0);
} catch (Throwable $e) {
    error_log('offer_redeem rate lookup: ' . $e->getMessage());
    $recentCount = 0; // don't block on logging-table error
}
if ($recentCount >= 10) {
    offer_respond_error(429, 'Too many redemptions, please try again later.', $wantsJson);
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

// CardSections::redeemOffer() already enforces tenant scoping via its WHERE
// clause (employee_id = :eid) — cross-employee redemption is rejected.
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
header('Location: ' . $dest, true, 303); // 303 See Other — POST→GET
exit;
