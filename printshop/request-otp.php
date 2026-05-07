<?php
/**
 * POST handler: request a print-shop login OTP.
 *
 * Looks up an active operator by phone (E.164 normalised) or email,
 * dispatches a 6-digit OTP via OtpService, returns JSON.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Phone.php';
require_once INCLUDES_DIR . '/PrintShopOperator.php';
require_once INCLUDES_DIR . '/WhatsApp.php';
require_once INCLUDES_DIR . '/Mailer.php';
require_once INCLUDES_DIR . '/OtpService.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error_message' => 'Method not allowed']);
    exit;
}
if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error_message' => 'Invalid CSRF token']);
    exit;
}

$raw = trim($_POST['identifier'] ?? '');
if ($raw === '') {
    echo json_encode(['ok' => false, 'error_message' => 'Please enter your phone or email.']);
    exit;
}

// Detect channel
$isEmail = (strpos($raw, '@') !== false);
$identifier = null;
$channel    = null;

if ($isEmail) {
    if (!filter_var($raw, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['ok' => false, 'error_message' => 'That email looks invalid.']);
        exit;
    }
    $identifier = strtolower($raw);
    $channel    = 'email';
} else {
    $identifier = Phone::normalize($raw);
    if (!$identifier) {
        echo json_encode(['ok' => false, 'error_message' => 'Phone number format not recognised.']);
        exit;
    }
    $channel = 'whatsapp';
}

// Look up active operator
$operator = $isEmail
    ? PrintShopOperator::getByEmail($identifier)
    : PrintShopOperator::getByPhone($identifier);

// Anti-enumeration: do not branch on whether the operator exists. Previous
// code returned 'No active operator with that phone or email' for unknown
// identifiers and 'code sent' for known ones, letting an attacker probe a
// list and learn which phones/emails are valid print-shop operators. Now
// always show the same 'code sent' page; if the identifier was bogus, no
// OTP actually goes out (no SMS/WhatsApp budget burn) and the verify step
// naturally fails since otp_codes has no matching row.
if (!$operator) {
    echo json_encode([
        'ok'      => true,
        'channel' => $channel,
        'masked'  => $isEmail ? $identifier : Phone::mask($identifier),
        'message' => $isEmail
            ? 'If that email is registered, we just emailed a 6-digit code to ' . $identifier . '.'
            : 'If that phone is registered, we sent a 6-digit code via WhatsApp to ' . Phone::mask($identifier) . '.',
    ]);
    error_log('[printshop/request-otp] no-op send for ' . substr(hash('sha256', $identifier), 0, 12) . ' (anti-enumeration)');
    exit;
}

// Dispatch OTP
$res = OtpService::send($identifier, $channel, 'printshop_login');
if (!$res['ok']) {
    $code = $res['error'] ?? 'send_failed';
    $msg  = [
        'rate_limited_identifier' => 'Too many codes requested. Try again in an hour.',
        'rate_limited_ip'         => 'Too many codes from your network. Try again later.',
        'invalid channel'         => 'Selected channel is not supported.',
        'send_failed'             => 'Could not send the code. Please try again.',
    ][$code] ?? 'Could not send the code.';
    echo json_encode(['ok' => false, 'error_message' => $msg]);
    exit;
}

echo json_encode([
    'ok'      => true,
    'channel' => $channel,
    'masked'  => $isEmail ? $identifier : Phone::mask($identifier),
    'message' => $isEmail
        ? 'We just emailed a 6-digit code to ' . $identifier . '.'
        : 'We sent a 6-digit code via WhatsApp to ' . Phone::mask($identifier) . '.',
]);
