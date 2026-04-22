<?php
/**
 * Web Vitals ingestion endpoint (Category N action 370).
 *
 * POST-only, public, accepts a small JSON blob from
 * assets/js/cardify-webvitals.js. Appends to a daily NDJSON log for
 * offline analysis; no per-row parse/aggregate on the hot path.
 *
 * Log: BASE_DIR/logs/webvitals/YYYY-MM-DD.jsonl
 *
 * Size hardening: 4 KB body cap + rate limit 60/min/IP so a hostile
 * client can't flood the disk. Minimal response so beacons stay cheap.
 */
header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo '{"ok":false}';
    exit;
}

require_once __DIR__ . '/../config.php';

$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 4096) {
    http_response_code(413);
    echo '{"ok":false,"error":"too_large"}';
    exit;
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (class_exists('RateLimiter')) {
    require_once INCLUDES_DIR . '/RateLimiter.php';
    if (!RateLimiter::check('webvitals', $ip, 60, 60)) {
        http_response_code(429);
        echo '{"ok":false}';
        exit;
    }
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo '{"ok":false}';
    exit;
}

$payload['received_at'] = date('c');
$payload['ip_prefix']   = substr($ip, 0, 7); // rough privacy-friendly bucket, not full IP

$dir = (defined('BASE_DIR') ? BASE_DIR : dirname(__DIR__)) . '/logs/webvitals';
if (!is_dir($dir)) @mkdir($dir, 0755, true);
$file = $dir . '/' . date('Y-m-d') . '.jsonl';

// Append-only write. LOCK_EX guards concurrent writers so lines never interleave.
@file_put_contents($file, json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

echo '{"ok":true}';
