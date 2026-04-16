<?php
/**
 * Card CTA click tracker + safe redirect.
 *
 * Usage: /card_click.php?eid=<employee_id>&cta=<click_phone|...>&dest=<urlencoded-target>
 *
 * - Validates eid + cta against allow-lists.
 * - Validates dest scheme: tel:, mailto:, sms:, whatsapp:, https://, or same-origin relative paths.
 * - Logs the event via CardAnalytics::log().
 * - 302-redirects to $dest on success, or to "/" if the target is invalid/missing.
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

/**
 * Validate destination URL.
 * Returns sanitized URL string, or null if rejected.
 */
function card_click_validate_dest($dest)
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
        return $dest;
    }

    $lower = strtolower($dest);
    $allowed = ['tel:', 'mailto:', 'sms:', 'whatsapp:', 'https://'];
    foreach ($allowed as $prefix) {
        if (strpos($lower, $prefix) === 0) {
            // For https:// do a filter_var check to reject junk
            if ($prefix === 'https://' && !filter_var($dest, FILTER_VALIDATE_URL)) {
                return null;
            }
            return $dest;
        }
    }
    return null;
}

$safeDest = card_click_validate_dest($dest);

if ($employeeId !== '' && in_array($cta, $allowedCta, true)) {
    // Look up company for scoping (employee row has company_id)
    try {
        $employee = findEmployeeById($employeeId);
        if ($employee && !empty($employee['company_id'])) {
            CardAnalytics::log(
                $employee['id'],
                $employee['company_id'],
                $cta,
                $safeDest
            );
        }
    } catch (Throwable $e) {
        // Never let logging block the redirect
        error_log('card_click log failed: ' . $e->getMessage());
    }
}

// Redirect
if ($safeDest !== null) {
    header('Location: ' . $safeDest, true, 302);
    exit;
}

http_response_code(400);
echo 'Invalid destination.';
