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

if (!$operator) {
    // Don't leak whether the identifier is registered. Tell user generically.
    echo json_encode([
        'ok' => false,
        'error_message' => 'No active operator with that phone or email. Ask an admin to add you.',
    ]);
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
