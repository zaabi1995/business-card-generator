<?php
/**
 * Card CTA click tracker + safe redirect.
 *
 * Usage: /card_click.php?eid=<employee_id>&cta=<click_phone|...>&dest=<urlencoded-target>
 *
 * Hardened against open-redirect abuse (Codex findings, Apr 2026):
 *  - `eid`, `cta`, `dest` are ALL required. Missing/invalid → 400 (no redirect).
 *  - `eid` MUST resolve to an existing employee in DB.
 *  - `cta` MUST be in the click-event allow-list (subset of CardAnalytics::EVENT_TYPES).
 *  - `dest` MUST be tel:, mailto:, sms:, whatsapp:, a same-origin path, OR an
 *    https:// URL whose host is on the cardify whitelist (no arbitrary externals).
 *  - URL shorteners (goo.gl, bit.ly, t.co, …) are REJECTED even if listed, they
 *    are checked via the shared UNSAFE_SHORTENERS constant in includes/UrlSafety.php
 *    so additions stay in sync with admin/short-links.php. (Round-2 Finding 1.)
 *  - Trailing-dot hosts (`cardify.om.`) are canonicalised before matching,
 *    so they can't bypass blocklists. (Round-2 Finding 6.)
 */

require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/CardAnalytics.php';
require_once INCLUDES_DIR . '/UrlSafety.php';

$employeeId = trim($_GET['eid'] ?? '');
$cta        = trim($_GET['cta'] ?? '');
$dest       = (string) ($_GET['dest'] ?? '');

// Allow-list CTAs that this endpoint will log (no `view`, `qr_scan` here).
$allowedCta = [
    'click_phone', 'click_mobile', 'click_whatsapp', 'click_email',
    'click_website', 'click_map', 'click_social', 'save_contact', 'wallet_add',
    'product_order_click',
    // PDF download CTA, emitted from the public card's bottom bar. Dest
    // points at same-origin /card-pdf.php so redirect-host check is harmless.
    'download_pdf',
    // Viral "Made with Cardify" footer on every public card, routes visitors
    // to the /claim landing page. Tracked so we can measure conversion from
    // card view → claim-page click (the whole point of the viral loop).
    'viral_footer_click',
];

// Whitelist of https hosts we will redirect to. Same-origin paths are handled
// separately (leading `/`). Add trusted externals here ONLY.
//
// NOTE: URL shorteners (goo.gl, bit.ly, …) are explicitly NOT on this list
// and are blocked regardless by isAllowedRedirectHost() via UNSAFE_SHORTENERS.
$allowedHttpsHosts = [
    'cardify.om',
    'www.cardify.om',
    'bhd.om',
    'www.bhd.om',
    'bhdoman.com',
    'www.bhdoman.com',
    'wa.me',
    'api.whatsapp.com',
    'maps.google.com',
    'www.google.com',
];

// ---------------------------------------------------------------------------
// 1. Required params.
// ---------------------------------------------------------------------------
if ($employeeId === '' || $cta === '' || $dest === '') {
    http_response_code(400);
    echo 'Missing required parameters.';
    exit;
}

// 2. CTA must be in allow-list.
if (!in_array($cta, $allowedCta, true)) {
    http_response_code(400);
    echo 'Invalid cta.';
    exit;
}

// 3. Destination must pass the shared URL allow-list (canonicalised host,
//    shortener blocklist enforced centrally).
$safeDest = isAllowedRedirectHost($dest, $allowedHttpsHosts);
if ($safeDest === null) {
    http_response_code(400);
    echo 'Invalid destination.';
    exit;
}

// 4. Employee ID must exist in DB, prevents using cardify.om as a generic
//    phishing redirector (the attacker needs a real employee slug/id).
try {
    $employee = findEmployeeById($employeeId);
} catch (Throwable $e) {
    error_log('card_click employee lookup failed: ' . $e->getMessage());
    $employee = null;
}
if (!$employee || empty($employee['company_id'])) {
    http_response_code(400);
    echo 'Unknown employee.';
    exit;
}

// ---------------------------------------------------------------------------
// Log the event. Never let logging block the redirect.
// ---------------------------------------------------------------------------
try {
    CardAnalytics::log(
        $employee['id'],
        $employee['company_id'],
        $cta,
        $safeDest
    );
} catch (Throwable $e) {
    error_log('card_click log failed: ' . $e->getMessage());
}

// ---------------------------------------------------------------------------
// Redirect.
// ---------------------------------------------------------------------------
header('Location: ' . $safeDest, true, 302);
exit;
