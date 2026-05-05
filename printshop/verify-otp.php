<?php
/**
 * POST handler: verify the OTP and mint a print-shop operator session.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/Phone.php';
require_once INCLUDES_DIR . '/PrintShopOperator.php';
require_once INCLUDES_DIR . '/PrintShop.php';
require_once INCLUDES_DIR . '/PrintShopAuth.php';
require_once INCLUDES_DIR . '/WhatsApp.php';
require_once INCLUDES_DIR . '/Mailer.php';
require_once INCLUDES_DIR . '/OtpService.php';
require_once INCLUDES_DIR . '/AuditLog.php';

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

$raw  = trim($_POST['identifier'] ?? '');
$code = trim($_POST['code'] ?? '');
$remember = ($_POST['remember'] ?? '0') === '1';

if ($raw === '' || $code === '' || strlen($code) !== 6) {
    echo json_encode(['ok' => false, 'error_message' => 'Please enter the 6-digit code.']);
    exit;
}

$isEmail = (strpos($raw, '@') !== false);
$identifier = $isEmail ? strtolower($raw) : Phone::normalize($raw);
if (!$identifier) {
    echo json_encode(['ok' => false, 'error_message' => 'Identifier format not recognised.']);
    exit;
}

$res = OtpService::verify($identifier, $code, 'printshop_login');
if (empty($res['ok'])) {
    $err = $res['error'] ?? 'invalid_code';
    $msg = [
        'invalid_code'   => 'That code is incorrect.',
        'expired'        => 'That code has expired. Request a new one.',
        'too_many_tries' => 'Too many incorrect attempts. Request a fresh code.',
    ][$err] ?? 'Could not verify the code.';
    echo json_encode(['ok' => false, 'error_message' => $msg]);
    exit;
}

$operator = $isEmail
    ? PrintShopOperator::getByEmail($identifier)
    : PrintShopOperator::getByPhone($identifier);

if (!$operator) {
    echo json_encode(['ok' => false, 'error_message' => 'Operator not found.']);
    exit;
}

$shop = PrintShop::getById((int) $operator['print_shop_id']);
if (!$shop || ($shop['status'] ?? '') !== 'active') {
    echo json_encode(['ok' => false, 'error_message' => 'Print shop is not active.']);
    exit;
}

PrintShopAuth::loginAsOperator($operator, $shop, $isEmail ? 'email_otp' : 'phone_otp');

if ($remember) {
    // 30-day refresh token; verified next session by re-issuing OTP-less login
    // when cookie + operator status check still match. Keep this minimal:
    // store an HMAC-signed identifier so the lookup is cheap.
    $secret = defined('APP_SECRET') ? APP_SECRET : ($_SERVER['APP_SECRET'] ?? 'cardify-default-secret');
    $payload = base64_encode(json_encode([
        'op'  => $operator['id'],
        'sh'  => (int) $shop['id'],
        'exp' => time() + 30 * 86400,
    ]));
    $sig = hash_hmac('sha256', $payload, $secret);
    $cookie = $payload . '.' . $sig;
    $secure = !empty($_SERVER['HTTPS']);
    setcookie('pso_remember', $cookie, [
        'expires'  => time() + 30 * 86400,
        'path'     => '/printshop/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

AuditLog::log('login', 'print_shop_operator', $operator['id'], null, [
    'channel' => $isEmail ? 'email' : 'whatsapp',
    'shop_id' => (int) $shop['id'],
]);

echo json_encode([
    'ok'       => true,
    'redirect' => getBasePath() . 'printshop/dashboard.php',
]);
