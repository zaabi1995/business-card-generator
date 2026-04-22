<?php
/**
 * GET /api/health (Cat T action 457).
 *
 * Lightweight liveness endpoint for external uptime monitors (Uptime
 * Robot / StatusCake / BetterStack). Returns 200 + JSON when the app
 * can serve requests, the DB is reachable, and storage is writable.
 * 503 otherwise.
 *
 * Kept minimal on purpose: every check must complete in <300ms or the
 * monitor will mark the site down.
 *   - DB: SELECT 1 via existing PDO singleton (re-uses the pooled
 *     connection if one is already open on this request).
 *   - Storage: is_writable on data/cache, which is the canary dir
 *     touched by every request.
 *
 * The endpoint intentionally exposes no company / payment info, so
 * it's safe to expose publicly.
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

$started = microtime(true);
$checks  = [
    'app'     => true,
    'db'      => false,
    'storage' => false,
];

try {
    $row = Database::getInstance()->fetchOne("SELECT 1 AS ok");
    $checks['db'] = !empty($row);
} catch (Throwable $_) {
    $checks['db'] = false;
}

$cacheDir = (defined('BASE_DIR') ? BASE_DIR : dirname(__DIR__)) . '/data/cache';
$checks['storage'] = is_writable($cacheDir);

$ok = $checks['app'] && $checks['db'] && $checks['storage'];
http_response_code($ok ? 200 : 503);

echo json_encode([
    'service'    => 'cardify',
    'status'     => $ok ? 'up' : 'degraded',
    'checks'     => $checks,
    'latency_ms' => (int) round((microtime(true) - $started) * 1000),
    'timestamp'  => gmdate('c'),
    'release'    => defined('CARDIFY_VERSION') ? CARDIFY_VERSION : 'main',
]);
