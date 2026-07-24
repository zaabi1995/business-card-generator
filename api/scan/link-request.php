<?php
/**
 * POST /api/scan/link-request.php {identifier}. Auth'd: send an OTP to an email
 * or phone the user wants to LINK to their existing account (so they can log in
 * with either). Refuses (a) an identifier already owned by a DIFFERENT active
 * account, and (b) linking a second identifier of a type the account already
 * has (one email + one phone), which would otherwise overwrite it. Bearer-auth.
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/ScanAuth.php';
require_once INCLUDES_DIR . '/OtpService.php';
require_once INCLUDES_DIR . '/RateLimiter.php';
require_once INCLUDES_DIR . '/UrlSafety.php';
require_once INCLUDES_DIR . '/Phone.php';
require_once INCLUDES_DIR . '/WhatsApp.php';
require_once INCLUDES_DIR . '/Mailer.php';
require_once __DIR__ . '/_link_common.php';
header('Content-Type: application/json');
$ctx = ScanAuth::requireEmployeeMutation();
$body = json_decode(file_get_contents('php://input'), true) ?: [];
$raw = trim((string)($body['identifier'] ?? ''));
if ($raw === '') { echo json_encode(['success' => false, 'error' => 'identifier_required']); exit; }
[$identifier, $isEmail, $channel, $masked] = linkParseIdentifier($raw);
if ($identifier === null) { echo json_encode(['success' => false, 'error' => 'invalid_identifier']); exit; }
$db = Database::getInstance();
// Per-account budget so a signed-in user cannot enumerate accounts by probing.
$ip = getClientIp();
if (!RateLimiter::check('scan_link_actor:' . $ctx['account_id'], $ip, 12, 3600)
    || !RateLimiter::check('scan_link_request:' . $identifier, $ip, 5, 900)
    // IP-independent ceilings ('global' key): a spoofed client IP cannot mint a
    // fresh counter, so these caps hold no matter how the source IP is forged.
    || !RateLimiter::check('scan_link_actor_g:' . $ctx['account_id'], 'global', 20, 3600)
    || !RateLimiter::check('scan_link_id_g:' . $identifier, 'global', 5, 3600)) {
    http_response_code(429); echo json_encode(['success' => false, 'error' => 'rate_limited']); exit; }
// (b) already have this TYPE linked (to a different value) -> would overwrite.
if (linkAccountHasType($db, $ctx['account_id'], $isEmail, $identifier)) {
    echo json_encode(['success' => false, 'error' => $isEmail ? 'already_have_email' : 'already_have_phone']); exit; }
// The verified login alias is globally unique across immutable accounts.
$owner = linkFindOwner($db, $identifier, $isEmail);
if ($owner !== null && $owner !== (string)$ctx['account_id']) {
    echo json_encode(['success' => false, 'error' => 'identifier_taken']); exit; }
$res = OtpService::send($identifier, $channel, 'scan_link');
if (empty($res['ok'])) { echo json_encode(['success' => false, 'error' => $res['error'] ?? 'delivery_failed']); exit; }
echo json_encode(['success' => true, 'channel' => $channel, 'identifier_masked' => $masked]);
