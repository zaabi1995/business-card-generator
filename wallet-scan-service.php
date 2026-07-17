<?php
/**
 * PassKit web service for updatable Cardify scan passes. The pass.json embeds
 * webServiceURL = https://<apex>/wallet-scan-service.php ; Apple appends /v1/...
 * (delivered here as PATH_INFO). Endpoints (Apple's Wallet passes protocol):
 *
 *   POST   /v1/devices/{dev}/registrations/{passType}/{serial}   register
 *   DELETE /v1/devices/{dev}/registrations/{passType}/{serial}   unregister
 *   GET    /v1/devices/{dev}/registrations/{passType}?passesUpdatedSince=tag
 *   GET    /v1/passes/{passType}/{serial}                        latest .pkpass
 *   POST   /v1/log                                               device logs
 *
 * Auth (register/unregister/latest): `Authorization: ApplePass <token>` checked
 * against the serial's stored token (constant-time). Ownership is server-side.
 * Tokens are never written to logs.
 */
require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/ScanPassService.php';

function wlog(string $msg): void { error_log('[wallet-service] ' . $msg); } // never includes tokens

function bearerApplePass(): ?string {
    $h = '';
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) { if (strtolower($k) === 'authorization') { $h = $v; break; } }
    }
    if ($h === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) $h = $_SERVER['HTTP_AUTHORIZATION'];
    if (stripos($h, 'ApplePass ') === 0) return trim(substr($h, 10));
    return null;
}

// Minimal per-IP rate limit (device endpoints are unauthenticated until the
// token check; protect against abuse of the serial/token guess surface).
function walletRateLimit(string $bucket, int $limit = 120, int $window = 60): bool {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0';
    $key = sys_get_temp_dir() . '/wlrl_' . md5($bucket . $ip . floor(time() / $window));
    $n = (int) @file_get_contents($key);
    if ($n >= $limit) return false;
    @file_put_contents($key, $n + 1);
    return true;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = $_SERVER['PATH_INFO'] ?? '';
$seg = array_values(array_filter(explode('/', $path), fn($s) => $s !== ''));
$passType = defined('APPLE_WALLET_PASS_TYPE_ID') ? APPLE_WALLET_PASS_TYPE_ID : '';

function out(int $code, ?array $json = null): void {
    http_response_code($code);
    if ($json !== null) { header('Content-Type: application/json'); echo json_encode($json); }
    exit;
}

if (!walletRateLimit('svc')) out(429);

// POST /v1/log
if ($method === 'POST' && count($seg) === 2 && $seg[0] === 'v1' && $seg[1] === 'log') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (is_array($body['logs'] ?? null)) { foreach ($body['logs'] as $line) { wlog('device: ' . substr((string)$line, 0, 300)); } }
    out(200);
}

// /v1/devices/{dev}/registrations/{passType}[/{serial}]
if (count($seg) >= 5 && $seg[0] === 'v1' && $seg[1] === 'devices' && $seg[3] === 'registrations') {
    $deviceLib = $seg[2];
    $reqType = $seg[4];
    if ($reqType !== $passType) out(404);
    $serial = $seg[5] ?? null;

    if ($method === 'GET' && $serial === null) {
        $since = $_GET['passesUpdatedSince'] ?? null;
        $res = ScanPassService::serialsForDevice($deviceLib, $since);
        if (empty($res['serialNumbers'])) out(204);
        out(200, ['lastUpdated' => $res['lastUpdated'], 'serialNumbers' => $res['serialNumbers']]);
    }

    if ($serial === null) out(400);
    $token = bearerApplePass();
    if ($token === null || !ScanPassService::authorize($serial, $token)) out(401);

    if ($method === 'POST') {
        $body = json_decode(file_get_contents('php://input'), true);
        $push = trim((string) ($body['pushToken'] ?? ''));
        if ($push === '') out(400);
        $env = (defined('APPLE_WALLET_APNS_ENV') ? APPLE_WALLET_APNS_ENV : 'production');
        $state = ScanPassService::register($serial, $deviceLib, $push, $env);
        out($state === 'created' ? 201 : 200);
    }
    if ($method === 'DELETE') {
        ScanPassService::unregister($serial, $deviceLib);
        out(200);
    }
    out(405);
}

// GET /v1/passes/{passType}/{serial}  -> latest signed pass
if ($method === 'GET' && count($seg) === 4 && $seg[0] === 'v1' && $seg[1] === 'passes') {
    if ($seg[2] !== $passType) out(404);
    $serial = $seg[3];
    $token = bearerApplePass();
    if ($token === null || !ScanPassService::authorize($serial, $token)) out(401);
    $pass = ScanPassService::findBySerial($serial);
    if (!$pass || (int)$pass['revoked'] === 1) out(404);

    // Conditional GET: If-Modified-Since vs the pass last_modified.
    $lastMod = strtotime($pass['last_modified']);
    $ims = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
    if ($ims !== '' && strtotime($ims) >= $lastMod) { http_response_code(304); exit; }

    // Regenerate the current pass for this employee. Delegates to the existing
    // signed-pkpass generator, which reads the live card + embeds the same
    // serial/token/webServiceURL.
    require_once INCLUDES_DIR . '/TenantHost.php';
    $company = Database::getInstance()->fetchOne("SELECT slug FROM companies WHERE id = :c", ['c' => $pass['company_id']]);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastMod) . ' GMT');
    $_GET['i'] = $pass['employee_id'];
    $_GET['c'] = $company['slug'] ?? '';
    $_GET['lang'] = $_GET['lang'] ?? 'en';
    require __DIR__ . '/wallet_apple.php'; // emits application/vnd.apple.pkpass
    exit;
}

out(404);
