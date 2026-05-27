<?php
/**
 * /api/logo-unlock.php (POST)
 *
 * Lead-capture gate for the Omani Logo Library. User provides
 * phone OR email, server stores the lead and drops a 90-day
 * unlock cookie. logo-download.php checks for the cookie on
 * every subsequent download, no JS-only "honor system".
 *
 * Body: application/json OR application/x-www-form-urlencoded
 *   company_id (int, optional)  what they wanted to download
 *   format     (string, optional) svg|png_1024|webp|zip etc.
 *   name       (string, optional, <=120)
 *   phone      (string, optional)  normalized to +<digits>
 *   email      (string, optional)  lowercased, RFC-validated
 *   consent_marketing (bool, optional, default true)
 * One of phone/email is required.
 *
 * Response: 200 {ok:true, cookie_id}
 *           400 {error: 'missing_contact'|'invalid_phone'|'invalid_email'}
 *           405 {error: 'method_not_allowed'}
 *           429 {error: 'rate_limited'}
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/LogoLibrary.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

// Accept JSON or form-encoded
$body = [];
$ct = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($ct, 'application/json') !== false) {
    $raw = file_get_contents('php://input') ?: '';
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) $body = $decoded;
} else {
    $body = $_POST;
}

$companyId = isset($body['company_id']) ? (int) $body['company_id'] : null;
$format    = isset($body['format']) ? substr((string) $body['format'], 0, 16) : null;
$name      = trim((string) ($body['name']  ?? ''));
$phone     = trim((string) ($body['phone'] ?? ''));
$email     = strtolower(trim((string) ($body['email'] ?? '')));
$consent   = !isset($body['consent_marketing']) || (bool) $body['consent_marketing'];

if ($name !== '' && mb_strlen($name) > 120) {
    $name = mb_substr($name, 0, 120);
}

// Phone normalize: strip whitespace/dashes/parens, keep leading +
if ($phone !== '') {
    $phone = preg_replace('/[\s\-\(\)]/', '', $phone);
    if (!preg_match('/^\+?[0-9]{7,15}$/', $phone)) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_phone']);
        exit;
    }
    if ($phone[0] !== '+') {
        $phone = '+' . ltrim($phone, '+');
    }
}

if ($email !== '') {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 160) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_email']);
        exit;
    }
}

if ($phone === '' && $email === '') {
    http_response_code(400);
    echo json_encode(['error' => 'missing_contact']);
    exit;
}

$db = Database::getInstance();
$ipHash = LogoLibrary::ipHash();
$bucket = (int) floor(time() / 3600);

// Rate limit: 5 unlocks/hour/IP (atomic via rate_limits)
$db->getConnection()->prepare(
    "INSERT INTO rate_limits (action, ip, bucket, count, window_sec)
     VALUES ('logo_unlock', :ip, :b, 1, 3600)
     ON DUPLICATE KEY UPDATE count = count + 1"
)->execute([':ip' => $ipHash, ':b' => $bucket]);
$recent = (int) ($db->fetchOne(
    "SELECT count FROM rate_limits WHERE action = 'logo_unlock' AND ip = :ip AND bucket = :b",
    [':ip' => $ipHash, ':b' => $bucket]
)['count'] ?? 0);
if ($recent > 5) {
    http_response_code(429);
    header('Retry-After: 3600');
    echo json_encode(['error' => 'rate_limited']);
    exit;
}

$cookieId = bin2hex(random_bytes(16));
$db->getConnection()->prepare(
    "INSERT INTO logo_leads
        (company_id, format, name, phone, email, cookie_id,
         ip_hash, user_agent_hash, referrer, consent_marketing)
     VALUES (:cid, :fmt, :name, :phone, :email, :ck,
             :ih, :uh, :ref, :consent)"
)->execute([
    ':cid'     => $companyId ?: null,
    ':fmt'     => $format ?: null,
    ':name'    => $name ?: null,
    ':phone'   => $phone ?: null,
    ':email'   => $email ?: null,
    ':ck'      => $cookieId,
    ':ih'      => $ipHash,
    ':uh'      => LogoLibrary::uaHash(),
    ':ref'     => mb_substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 512) ?: null,
    ':consent' => $consent ? 1 : 0,
]);

// 90-day cookie, secure, HttpOnly. Server-side check on logo-download.php.
$cookieParams = [
    'expires'  => time() + 90 * 86400,
    'path'     => '/',
    'domain'   => '',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax',
];
$host = $_SERVER['HTTP_HOST'] ?? '';
if (preg_match('/(^|\.)cardify\.om$/', $host)) {
    $cookieParams['domain'] = '.cardify.om';
}
setcookie('cardify_logo_unlock_v1', $cookieId, $cookieParams);

// Don't block on Dardasha; fire-and-forget pretty CRM ping if phone given.
// Intentionally skipped, lead lives in logo_leads, manual follow-up later.

echo json_encode(['ok' => true, 'cookie_id' => $cookieId]);
