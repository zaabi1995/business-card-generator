<?php
/**
 * Per-account throttle for the authenticated scan DATA endpoints. These were
 * unlimited: a valid bearer token could hammer sync/upload/create or turn
 * resolve-card into a card-scraper. Call right after ScanAuth::requireEmployee().
 * Keyed on (endpoint, account_id, client-ip) via the shared RateLimiter, so a
 * spoofed IP still can't multiply the per-account budget (getClientIp is
 * CF-gated). 429 + exit on breach. Limits are generous, meant to stop abuse,
 * not normal use.
 */
require_once INCLUDES_DIR . '/RateLimiter.php';
require_once INCLUDES_DIR . '/UrlSafety.php';

function scanRateLimit(array $ctx, string $action, int $limit, int $windowSec = 3600): void
{
    $ip = function_exists('getClientIp') ? getClientIp() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $who = (string) ($ctx['account_id'] ?? 'anon');
    if (!RateLimiter::check('scan_' . $action . ':' . $who, $ip, $limit, $windowSec)) {
        http_response_code(429);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'rate_limited']);
        exit;
    }
}
