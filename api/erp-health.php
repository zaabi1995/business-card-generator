<?php
/**
 * GET /api/erp-health (Category S action 441).
 *
 * Lightweight check that:
 *   1. ERP sync is configured (erp_settings rows present + enabled).
 *   2. The configured erp_api_url is reachable.
 *   3. The JWT token in erp_settings authenticates against ERP
 *      (the all-roles bypass endpoint /api/admin/me returns 200).
 *
 * Response is JSON so ops dashboards (Uptime Robot, StatusCake) can
 * parse it. Always returns JSON + never leaks the token value itself.
 *
 * Rate-limited to 30 requests per IP per minute. No PII in the
 * response. Exposed via nginx rewrite /api/erp-health -> this file.
 */
require_once __DIR__ . '/../config.php';
require_once INCLUDES_DIR . '/ERPSync.php';
require_once INCLUDES_DIR . '/SecurityHeaders.php';

SecurityHeaders::send();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

// Per-IP throttle. Cheap file-backed counter.
if (class_exists('RateLimiter')) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        RateLimiter::check('erp-health:' . $ip, 30, 60);
    } catch (Throwable $_) { /* fail open on limiter errors */ }
}

$started = microtime(true);
$result  = [
    'service'   => 'cardify-erp-sync',
    'status'    => 'unknown',
    'checks'    => [],
    'timestamp' => gmdate('c'),
];

// 1. Config present + enabled.
try {
    $enabled  = ERPSync::isEnabled();
    $settings = ERPSync::getSettings();
    $apiUrl   = trim((string) ($settings['erp_api_url'] ?? ''));
    $hasToken = !empty($settings['erp_api_token']);
    $result['checks']['configured'] = $enabled;
    $result['checks']['has_token']  = $hasToken;
    $result['checks']['api_url']    = $apiUrl ?: null; // public OK, identifies the ERP endpoint
} catch (Throwable $e) {
    http_response_code(503);
    $result['status'] = 'error';
    $result['error']  = 'settings_load_failed';
    echo json_encode($result); exit;
}
if (!$enabled || !$apiUrl || !$hasToken) {
    http_response_code(503);
    $result['status'] = 'degraded';
    $result['error']  = 'erp_sync_disabled_or_misconfigured';
    echo json_encode($result); exit;
}

// 2. Reachability + token validity against /api/admin/me.
$probeUrl = rtrim($apiUrl, '/') . '/api/admin/me';
$ch = curl_init($probeUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $settings['erp_api_token'],
        'Accept: application/json',
        'X-Cardify-Health: 1',
    ],
    CURLOPT_FOLLOWLOCATION => false,
]);
$body     = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

$result['checks']['reachable'] = $httpCode > 0;
$result['checks']['auth_ok']   = $httpCode === 200;
$result['latency_ms']          = (int) round((microtime(true) - $started) * 1000);

if ($curlErr && $httpCode === 0) {
    http_response_code(503);
    $result['status'] = 'unreachable';
    $result['error']  = 'network_error';
    echo json_encode($result); exit;
}

if ($httpCode === 401 || $httpCode === 403) {
    http_response_code(503);
    $result['status'] = 'auth_failed';
    $result['error']  = 'token_rejected_by_erp';
    $result['checks']['http_status'] = $httpCode;
    echo json_encode($result); exit;
}

if ($httpCode !== 200) {
    http_response_code(503);
    $result['status'] = 'degraded';
    $result['error']  = 'unexpected_status';
    $result['checks']['http_status'] = $httpCode;
    echo json_encode($result); exit;
}

$result['status'] = 'ok';
echo json_encode($result);
