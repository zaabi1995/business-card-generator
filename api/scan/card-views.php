<?php
/**
 * GET /api/scan/card-views.php -> "who viewed my card" for the signed-in
 * employee, from the same qr_scans the web already records. Bearer-auth.
 * Privacy: exposes only coarse location (city/country) + device/browser, NEVER
 * the raw IP or precise lat/long that qr_scans also stores.
 *
 * Query: ?limit (recent list, default 30, max 100)
 * Response: {success, totals:{all,last7,last30,unique_visitors},
 *            daily:[{date,count}] (last 14d), recent:[{at,device,browser,os,city,country,country_code}]}
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/ScanAuth.php';

header('Content-Type: application/json');
$ctx = ScanAuth::requireEmployee();
require_once __DIR__ . '/_ratelimit.php';
scanRateLimit($ctx, 'card_views', 600);

$db = Database::getInstance();
$emp = $ctx['employee_id'];
$limit = min(100, max(1, (int) ($_GET['limit'] ?? 30)));

try {
    $totals = $db->fetchOne(
        "SELECT
            COUNT(*) AS all_time,
            SUM(scanned_at >= (NOW() - INTERVAL 7 DAY))  AS last7,
            SUM(scanned_at >= (NOW() - INTERVAL 30 DAY)) AS last30,
            COUNT(DISTINCT visitor_id) AS uniq
         FROM qr_scans WHERE employee_id = :e",
        ['e' => $emp]
    ) ?: [];

    $daily = $db->fetchAll(
        "SELECT DATE(scanned_at) AS d, COUNT(*) AS c
           FROM qr_scans
          WHERE employee_id = :e AND scanned_at >= (NOW() - INTERVAL 14 DAY)
          GROUP BY DATE(scanned_at) ORDER BY d ASC",
        ['e' => $emp]
    );

    // limit is (int)-clamped above, safe to interpolate.
    $recent = $db->fetchAll(
        "SELECT scanned_at, device_type, browser, os, city, country_name, country_code
           FROM qr_scans WHERE employee_id = :e
          ORDER BY scanned_at DESC LIMIT $limit",
        ['e' => $emp]
    );
} catch (\Throwable $e) {
    error_log('[scan/card-views] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_error']);
    exit;
}

echo json_encode([
    'success' => true,
    'totals'  => [
        'all'             => (int) ($totals['all_time'] ?? 0),
        'last7'           => (int) ($totals['last7'] ?? 0),
        'last30'          => (int) ($totals['last30'] ?? 0),
        'unique_visitors' => (int) ($totals['uniq'] ?? 0),
    ],
    'daily'   => array_map(fn ($r) => ['date' => $r['d'], 'count' => (int) $r['c']], $daily),
    'recent'  => array_map(fn ($r) => [
        'at'           => $r['scanned_at'],
        'device'       => $r['device_type'] ?? null,
        'browser'      => $r['browser'] ?? null,
        'os'           => $r['os'] ?? null,
        'city'         => $r['city'] ?? null,
        'country'      => $r['country_name'] ?? null,
        'country_code' => $r['country_code'] ?? null,
    ], $recent),
], JSON_UNESCAPED_UNICODE);
