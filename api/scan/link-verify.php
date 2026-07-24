<?php
/**
 * POST /api/scan/link-verify.php {identifier, code}
 *
 * Verifies an OTP and attaches the login alias to the immutable scan account.
 * Card profile email and phone fields are separate editable content.
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/ScanAuth.php';
require_once INCLUDES_DIR . '/OtpService.php';
require_once INCLUDES_DIR . '/Phone.php';
require_once __DIR__ . '/_link_common.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST only']);
    exit;
}

$ctx = ScanAuth::requireEmployeeMutation();
require_once __DIR__ . '/_ratelimit.php';
scanRateLimit($ctx, 'link_verify', 60);

$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) {
    $body = [];
}
$raw = trim((string) ($body['identifier'] ?? ''));
$code = trim((string) ($body['code'] ?? ''));
if ($raw === '' || $code === '') {
    echo json_encode([
        'success' => false,
        'error' => 'identifier_and_code_required',
    ]);
    exit;
}

[$identifier, $isEmail] = linkParseIdentifier($raw);
if ($identifier === null) {
    echo json_encode(['success' => false, 'error' => 'invalid_identifier']);
    exit;
}

$verify = OtpService::verify($identifier, $code, 'scan_link');
if (empty($verify['ok'])) {
    echo json_encode([
        'success' => false,
        'error' => $verify['error'] ?? 'invalid_code',
    ]);
    exit;
}

$db = Database::getInstance();
$accountId = (string) $ctx['account_id'];
if (linkAccountHasType($db, $accountId, $isEmail, $identifier)) {
    echo json_encode([
        'success' => false,
        'error' => $isEmail ? 'already_have_email' : 'already_have_phone',
    ]);
    exit;
}

$owner = linkFindOwner($db, $identifier, $isEmail);
if ($owner !== null && !hash_equals($accountId, $owner)) {
    echo json_encode(['success' => false, 'error' => 'identifier_taken']);
    exit;
}

try {
    $linked = ScanIdentity::linkVerifiedIdentifier(
        $db,
        $accountId,
        $identifier,
        $isEmail ? 'email' : 'phone',
        'scan_link_otp'
    );
} catch (Throwable $e) {
    error_log('[scan/link-verify] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'link_failed']);
    exit;
}
if (empty($linked['success'])) {
    echo json_encode([
        'success' => false,
        'error' => $linked['error'] ?? 'link_failed',
    ]);
    exit;
}

$identifiers = ScanIdentity::linkedIdentifiers($db, $accountId);
echo json_encode([
    'success' => true,
    'email' => $identifiers['email'],
    'phone' => $identifiers['phone'],
], JSON_UNESCAPED_UNICODE);
