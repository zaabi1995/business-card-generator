<?php
/**
 * Card Offer Redemption Endpoint
 *
 * POST /api/offer/redeem.php?oid=<offer_id>&eid=<employee_id>
 * (params may also be in POST body)
 *
 * Hardened Apr 2026 (Codex round-1):
 *  - POST ONLY. GET returns 405 — stops state-changing side effects being
 *    triggered by link prefetchers, image crawlers, and email scanners.
 *  - Rate limit: 10 redemptions / IP / hour / offer_id.
 *  - Tenant scoping: oid must belong to eid (CardSections::redeemOffer
 *    enforces this in its WHERE clause).
 *  - JSON when Accept: application/json or ?format=json, else 302 back to the
 *    employee's public card page.
 *
 * Hardened Apr 2026 (Codex round-2):
 *  - CSRF — Origin/Referer MUST match cardify.om, a configured card custom
 *    domain, or the current HTTP_HOST. Blocks auto-POST from evil.com.
 *    (Finding 2)
 *  - Client IP now uses getClientIp() so the rate-limit check sees the same
 *    value that CardAnalytics::log() writes (HTTP_CF_CONNECTING_IP behind
 *    Cloudflare). (Finding 3)
 *  - Rate limit moved to atomic RateLimiter::check() — no more check-then-log
 *    race (Finding 3/4).
 */

require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/CardSections.php';
require_once INCLUDES_DIR . '/CardAnalytics.php';
require_once INCLUDES_DIR . '/UrlSafety.php';
require_once INCLUDES_DIR . '/RateLimiter.php';

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

// --------------------------------------------------------------------------
// Origin / Referer check — replaces a CSRF token (there's no auth cookie to
// steal; the concern is auto-POST from evil.com to burn a coupon).
//
// A legitimate request comes from:
//   - the public cardify.om host (or www)
//   - a card-owner custom domain (employee_custom_domains.domain)
//   - the same HTTP_HOST the card is being served on (local dev, staging)
// Anything else → 403. Missing Origin+Referer (e.g. native app, curl test) is
// allowed ONLY if the referrer-policy header on the card page stripped it.
// Cardify currently sends Referrer-Policy: strict-origin-when-cross-origin,
// so same-origin POSTs always carry at least a Referer.
// --------------------------------------------------------------------------
function offer_origin_is_trusted(): bool
{
    $origin  = $_SERVER['HTTP_ORIGIN']  ?? '';
    $referer = $_SERVER['HTTP_REFERER'] ?? '';

    // Neither header present — reject. Modern browsers send at least one on
    // state-changing POST.
    if ($origin === '' && $referer === '') {
        return false;
    }

    $candidateHost = '';
    if ($origin !== '') {
        $candidateHost = canonicalHostFromUrl($origin);
    } elseif ($referer !== '') {
        $candidateHost = canonicalHostFromUrl($referer);
    }
    if ($candidateHost === '') {
        return false;
    }

    // 1. cardify.om canonical hosts.
    $trusted = ['cardify.om', 'www.cardify.om'];

    // 2. Current request host (so local dev / staging / aaPanel test URLs work).
    $httpHost = canonicalHost((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($httpHost !== '') {
        $trusted[] = $httpHost;
    }

    // 3. Any configured card custom domain (Pro-tier feature). Best-effort —
    //    if the table doesn't exist, just skip.
    try {
        $db = Database::getInstance();
        if ($db->isConnected()) {
            $rows = $db->fetchAll("SELECT domain FROM employee_custom_domains WHERE status = 'active'");
            foreach ($rows as $r) {
                $d = canonicalHost((string) ($r['domain'] ?? ''));
                if ($d !== '') {
                    $trusted[] = $d;
                }
            }
        }
    } catch (Throwable $e) {
        // table missing / query error — ignore, fall back to cardify-only.
    }

    return in_array($candidateHost, $trusted, true);
}

if (!offer_origin_is_trusted()) {
    offer_respond_error(403, 'Cross-origin request blocked.', $wantsJson);
}

// Honeypot — bots that auto-fill every field still get silently dropped.
if (!empty($_POST['website_url'])) {
    if ($wantsJson) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
    } else {
        echo 'OK';
    }
    exit;
}

// Accept params from either query string or POST body.
$offerId    = trim($_POST['oid']  ?? $_GET['oid']  ?? '');
$employeeId = trim($_POST['eid']  ?? $_GET['eid']  ?? '');

if ($offerId === '' || $employeeId === '') {
    offer_respond_error(400, 'Missing oid or eid.', $wantsJson);
}

// --------------------------------------------------------------------------
// Rate limit: 10 redemptions per IP per offer per hour. Uses the shared
// atomic RateLimiter so the check and the increment happen in one
// INSERT ... ON DUPLICATE KEY UPDATE (no TOCTOU race with concurrent POSTs).
// --------------------------------------------------------------------------
$ip = getClientIp();
if (!RateLimiter::check('offer_redeem:' . $offerId, $ip, 10, 3600)) {
    offer_respond_error(429, 'Too many redemptions, please try again later.', $wantsJson);
}

try {
    $employee = findEmployeeById($employeeId);
} catch (Throwable $e) {
    error_log('offer redeem lookup: ' . $e->getMessage());
    $employee = null;
}
if (!$employee || empty($employee['company_id'])) {
    // Refund the rate-limit slot since the request never made it past input
    // validation — keeps a misspelled eid from locking the user out.
    RateLimiter::refund('offer_redeem:' . $offerId, $ip, 3600);
    offer_respond_error(404, 'Card not found.', $wantsJson);
}

// CardSections::redeemOffer() already enforces tenant scoping via its WHERE
// clause (employee_id = :eid) — cross-employee redemption is rejected.
$offer = CardSections::redeemOffer($employeeId, $offerId);
if (!$offer) {
    RateLimiter::refund('offer_redeem:' . $offerId, $ip, 3600);
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
