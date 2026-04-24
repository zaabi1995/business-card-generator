<?php
/**
 * Referral entry point (BHD-234).
 *
 * /r/<code>  → sets cookie + session, redirects to signup with ?ref=<code>.
 *
 * If the code doesn't match any user we still redirect to signup, we never
 * block the funnel on a bad code.
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/Referral.php';

$code = $_GET['code'] ?? '';
$code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$code));

if ($code !== '') {
    Referral::capturePending($code);
}

// If already logged in, send to dashboard, no point in showing signup.
if (class_exists('Auth') && Auth::isLoggedIn()) {
    header('Location: ' . getBasePath() . 'admin/');
    exit;
}

// Redirect to signup with the ref code in the query string so the form can
// use it even if cookies are stripped.
$target = getBasePath() . 'company/register.php' . ($code !== '' ? '?ref_code=' . urlencode($code) : '');
header('Location: ' . $target);
exit;
