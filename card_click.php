<?php
/**
 * Card CTA click tracker + safe redirect.
 *
 * Usage: /card_click.php?eid=<employee_id>&cta=<click_phone|...>&dest=<urlencoded-target>
 *
 * Hardened against open-redirect abuse (Codex finding, Apr 2026):
 *  - `eid`, `cta`, `dest` are ALL required. Missing/invalid → 400 (no redirect).
 *  - `eid` MUST resolve to an existing employee in DB.
 *  - `cta` MUST be in the click-event allow-list (subset of CardAnalytics::EVENT_TYPES).
 *  - `dest` MUST be tel:, mailto:, sms:, whatsapp:, a same-origin path, OR an
 *    https:// URL whose host is on the cardify whitelist (no arbitrary externals).
 */

require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/CardAnalytics.php';

$employeeId = trim($_GET['eid'] ?? '');
$cta        = trim($_GET['cta'] ?? '');
$dest       = (string) ($_GET['dest'] ?? '');

// Allow-list CTAs that this endpoint will log (no `view`, `qr_scan` here).
$allowedCta = [
    'click_phone', 'click_mobile', 'click_whatsapp', 'click_email',
    'click_website', 'click_map', 'click_social', 'save_contact', 'wallet_add',
    'product_order_click',
];

// Whitelist of https hosts we will redirect to. Same-origin paths are handled
// separately (leading `/`). Add trusted externals here ONLY.
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
    'goo.gl',
];

/**
 * Validate destination URL against strict allow-list.
 * Returns sanitized URL string, or null if rejected.
 *
 * @param string   $dest
 * @param string[] $allowedHttpsHosts
 * @return string|null
 */
function card_click_validate_dest($dest, array $allowedHttpsHosts)
{
    if ($dest === '') {
        return null;
    }
    // Relative same-origin path
    if ($dest[0] === '/') {
        // Disallow "//" (protocol-relative) — open redirect vector
        if (isset($dest[1]) && $dest[1] === '/') {
            return null;
        }
        // Disallow CRLF injection
        if (preg_match('/[\r\n]/', $dest)) {
            return null;
        }
        return $dest;
    }

    $lower = strtolower($dest);

    // Non-http(s) schemes: tel:, mailto:, sms:, whatsapp: — accept as-is (no host).
    $passthroughSchemes = ['tel:', 'mailto:', 'sms:', 'whatsapp:'];
    foreach ($passthroughSchemes as $prefix) {
        if (strpos($lower, $prefix) === 0) {
            if (preg_match('/[\r\n]/', $dest)) {
                return null;
            }
            return $dest;
        }
    }

    // https:// — validate URL, then check host whitelist.
    if (strpos($lower, 'https://') === 0) {
        if (!filter_var($dest, FILTER_VALIDATE_URL)) {
            return null;
        }
        $host = parse_url($dest, PHP_URL_HOST);
        if (!$host) {
            return null;
        }
        $host = strtolower($host);
        if (!in_array($host, $allowedHttpsHosts, true)) {
            return null;
        }
        return $dest;
    }

    // Everything else (http://, javascript:, data:, file:, ftp:, …) rejected.
    return null;
}

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

// 3. Destination must pass URL allow-list.
$safeDest = card_click_validate_dest($dest, $allowedHttpsHosts);
if ($safeDest === null) {
    http_response_code(400);
    echo 'Invalid destination.';
    exit;
}

// 4. Employee ID must exist in DB — prevents using cardify.om as a generic
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
