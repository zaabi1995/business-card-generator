<?php
/**
 * One-time, idempotent creation of the Google Wallet GenericClass that every
 * Cardify business-card pass references (GoogleWalletPass::classResourceId()).
 *
 * Run once, after the Google Wallet config constants + service-account JSON are
 * in place (see docs/superpowers/plans/2026-04-16-wallet-passes.md):
 *
 *     /www/server/php/83/bin/php scripts/create-google-wallet-class.php
 *
 * Safe to re-run: if the class already exists it is left untouched.
 *
 * Why this exists: GoogleWalletPass::buildSaveUrl() emits a "skinny" save JWT
 * that carries only the genericObject and references the class by id. Google
 * requires that class to exist server-side first; a save link does NOT
 * auto-create it. Without this step every "Add to Google Wallet" tap fails.
 *
 * CLI only. Reuses includes/jwt.php (RS256) for the service-account auth flow.
 */

require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/jwt.php';
require_once INCLUDES_DIR . '/GoogleWalletPass.php';

function gwc_out(string $s): void { fwrite(STDOUT, $s . "\n"); }
function gwc_fail(string $s): void { fwrite(STDERR, 'ERROR: ' . $s . "\n"); exit(1); }

/** POST application/x-www-form-urlencoded; returns ['code'=>int,'body'=>string]. */
function gwc_post_form(string $url, array $fields): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 30,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ($body === false) ? ['code' => 0, 'body' => $err] : ['code' => $code, 'body' => $body];
}

/** Authenticated JSON request; returns ['code'=>int,'body'=>string]. */
function gwc_json(string $url, string $method, ?array $payload, string $bearer): array {
    $ch = curl_init($url);
    $headers = ['Authorization: Bearer ' . $bearer, 'Accept: application/json'];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_TIMEOUT        => 30,
    ];
    if ($payload !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers[] = 'Content-Type: application/json';
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ($body === false) ? ['code' => 0, 'body' => $err] : ['code' => $code, 'body' => $body];
}

if (!GoogleWalletPass::isEnabled()) {
    gwc_fail('Google Wallet is not configured/enabled. Set GOOGLE_WALLET_* constants + the '
        . 'service-account JSON in config.php first (see the plan doc), then re-run.');
}

$raw = @file_get_contents(GOOGLE_WALLET_SERVICE_ACCOUNT_JSON);
if ($raw === false) {
    gwc_fail('Cannot read service account JSON: ' . GOOGLE_WALLET_SERVICE_ACCOUNT_JSON);
}
$sa = json_decode($raw, true);
if (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) {
    gwc_fail('Invalid service account JSON (missing client_email / private_key).');
}

// 1. Mint an OAuth2 access token via the service-account JWT-bearer flow.
$now = time();
$assertion = cardify_jwt_rs256_sign([
    'iss'   => $sa['client_email'],
    'scope' => 'https://www.googleapis.com/auth/wallet_object.issuer',
    'aud'   => 'https://oauth2.googleapis.com/token',
    'iat'   => $now,
    'exp'   => $now + 3600,
], $sa['private_key'], ['kid' => $sa['private_key_id'] ?? null]);

$tokenResp = gwc_post_form('https://oauth2.googleapis.com/token', [
    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
    'assertion'  => $assertion,
]);
$token = json_decode($tokenResp['body'], true);
if ($tokenResp['code'] !== 200 || empty($token['access_token'])) {
    gwc_fail("OAuth token exchange failed (HTTP {$tokenResp['code']}): {$tokenResp['body']}");
}
$accessToken = (string) $token['access_token'];

// 2. Idempotency: does the class already exist?
$classId = GoogleWalletPass::classResourceId();
$base    = 'https://walletobjects.googleapis.com/walletobjects/v1/genericClass';

$get = gwc_json($base . '/' . rawurlencode($classId), 'GET', null, $accessToken);
if ($get['code'] === 200) {
    gwc_out("Class already exists: {$classId} , nothing to do.");
    exit(0);
}
if ($get['code'] !== 404) {
    gwc_fail("Unexpected response checking class (HTTP {$get['code']}): {$get['body']}");
}

// 3. Create the class. A generic class only needs an id; all display fields
//    (header / subheader / barcode / colors) live on each object.
$post = gwc_json($base, 'POST', ['id' => $classId], $accessToken);
if ($post['code'] === 200) {
    gwc_out("Created Google Wallet class: {$classId}");
    exit(0);
}
gwc_fail("Class creation failed (HTTP {$post['code']}): {$post['body']}");
