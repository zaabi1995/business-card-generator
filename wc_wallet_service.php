<?php
/**
 * PassKit web service for the World Cup Apple Wallet pass.
 *
 * Implements Apple's Wallet Web Service (https://developer.apple.com/...)
 * under https://wc.cardify.om/wc-wallet/v1/... . Routed from index.php on
 * the wc host. $wcPath (no trailing slash) is passed in by the front
 * controller; we re-parse the segments here.
 *
 * Endpoints (passTypeId is always APPLE_WALLET_PASS_TYPE_ID):
 *   POST   /v1/devices/{deviceLibId}/registrations/{passTypeId}/{serial}
 *   DELETE /v1/devices/{deviceLibId}/registrations/{passTypeId}/{serial}
 *   GET    /v1/devices/{deviceLibId}/registrations/{passTypeId}[?passesUpdatedSince=tag]
 *   GET    /v1/passes/{passTypeId}/{serial}
 *   POST   /v1/log
 *
 * Auth: every device request carries "Authorization: ApplePass <token>"
 * which MUST equal wc_wallet_passes.auth_token for the serial.
 */

require_once __DIR__ . '/config.php';
require_once INCLUDES_DIR . '/WcHub.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = isset($wcPath) ? $wcPath : (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$path   = rtrim($path, '/');
// segments after /wc-wallet
$rest = preg_replace('#^/wc-wallet#', '', $path);
$seg  = array_values(array_filter(explode('/', $rest), fn($s)=>$s!==''));
// $seg[0] = 'v1'

$db = Database::getInstance();

function wsAuthToken(): string {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($h === '') {
        $hdrs = [];
        if (function_exists('getallheaders')) { $hdrs = getallheaders(); }
        elseif (function_exists('apache_request_headers')) { $hdrs = apache_request_headers(); }
        foreach ($hdrs as $k=>$v) { if (strtolower($k)==='authorization') { $h=$v; break; } }
    }
    if (stripos($h, 'ApplePass ') === 0) return trim(substr($h, 10));
    return '';
}
function wsAuthorize(Database $db, string $serial): bool {
    $row = $db->fetchOne("SELECT auth_token FROM wc_wallet_passes WHERE serial=:s LIMIT 1", ['s'=>$serial]);
    if (!$row) return false;
    return hash_equals((string)$row['auth_token'], wsAuthToken());
}

// POST /v1/log  -- device diagnostic logs. Accept + record, no auth.
if (($seg[1] ?? '') === 'log' && $method === 'POST') {
    $body = file_get_contents('php://input');
    error_log('wc-wallet PassKit log: ' . substr((string)$body, 0, 2000));
    http_response_code(200);
    exit;
}

// /v1/devices/{deviceLibId}/registrations/{passTypeId}[/{serial}]
if (($seg[1] ?? '') === 'devices' && ($seg[3] ?? '') === 'registrations') {
    $deviceLibId = $seg[2] ?? '';
    $serial      = $seg[5] ?? '';

    // GET list of updatable serials for this device.
    if ($method === 'GET' && $serial === '') {
        $since = $_GET['passesUpdatedSince'] ?? null;
        $rows = $db->fetchAll(
            "SELECT r.pass_serial, p.updated_at, p.updated_tag
             FROM wallet_device_registrations r
             JOIN wc_wallet_passes p ON p.serial = r.pass_serial
             WHERE r.device_lib_id = :d",
            ['d'=>$deviceLibId]
        );
        if (!$rows) { http_response_code(204); exit; }
        $serials = []; $maxTs = 0;
        foreach ($rows as $row) {
            $ts = strtotime((string)$row['updated_at']);
            // passesUpdatedSince is the lastUpdated tag we previously returned
            // (a unix ts string). Only include passes touched since then.
            if ($since !== null && $since !== '' && ctype_digit((string)$since) && $ts <= (int)$since) {
                continue;
            }
            $serials[] = $row['pass_serial'];
            if ($ts > $maxTs) $maxTs = $ts;
        }
        if (!$serials) { http_response_code(204); exit; }
        header('Content-Type: application/json');
        echo json_encode(['serialNumbers'=>$serials, 'lastUpdated'=>(string)($maxTs ?: time())]);
        exit;
    }

    // Need a serial + valid auth from here on.
    if ($serial === '' || !wsAuthorize($db, $serial)) { http_response_code(401); exit; }

    if ($method === 'POST') {
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $pushToken = (string)($in['pushToken'] ?? '');
        if ($pushToken === '') { http_response_code(400); exit; }
        $existing = $db->fetchOne(
            "SELECT id FROM wallet_device_registrations WHERE device_lib_id=:d AND pass_serial=:s LIMIT 1",
            ['d'=>$deviceLibId, 's'=>$serial]
        );
        if ($existing) {
            $db->update('wallet_device_registrations', ['push_token'=>$pushToken], 'id=:id', ['id'=>$existing['id']]);
            http_response_code(200); // already registered
        } else {
            $db->insert('wallet_device_registrations', [
                'device_lib_id'=>$deviceLibId, 'pass_serial'=>$serial, 'push_token'=>$pushToken,
            ]);
            http_response_code(201); // newly registered
        }
        exit;
    }

    if ($method === 'DELETE') {
        $db->query(
            "DELETE FROM wallet_device_registrations WHERE device_lib_id=:d AND pass_serial=:s",
            ['d'=>$deviceLibId, 's'=>$serial]
        );
        http_response_code(200);
        exit;
    }

    http_response_code(405); exit;
}

// GET /v1/passes/{passTypeId}/{serial}  -- latest signed .pkpass.
if (($seg[1] ?? '') === 'passes' && $method === 'GET') {
    $serial = $seg[3] ?? '';
    if ($serial === '' || !wsAuthorize($db, $serial)) { http_response_code(401); exit; }

    $row = $db->fetchOne("SELECT user_id, updated_at FROM wc_wallet_passes WHERE serial=:s LIMIT 1", ['s'=>$serial]);
    if (!$row) { http_response_code(404); exit; }

    // Conditional GET: honour If-Modified-Since against the pass row.
    $lastMod = strtotime((string)$row['updated_at']);
    $ims = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '';
    if ($ims !== '' && @strtotime($ims) >= $lastMod) { http_response_code(304); exit; }

    $user = $db->fetchOne("SELECT * FROM wc_users WHERE id=:id LIMIT 1", ['id'=>(int)$row['user_id']]);
    if (!$user) { http_response_code(404); exit; }

    require_once INCLUDES_DIR . '/AppleWalletPass.php';
    require_once INCLUDES_DIR . '/WcWalletApple.php';
    if (!AppleWalletPass::isEnabled()) { http_response_code(503); exit; }
    $bytes = WcWalletApple::build($user);
    header('Content-Type: application/vnd.apple.pkpass');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastMod) . ' GMT');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit;
}

http_response_code(404);
