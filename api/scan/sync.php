<?php
/**
 * POST /api/scan/sync.php, pull-based sync endpoint for the mobile app
 *
 * Body: {since: "YYYY-MM-DD HH:MM:SS"|null} -> {success, scans: [...],
 * server_time, has_more, next_since}
 * The app pulls all changes since its last sync; pushes happen through upload.php
 * (idempotent via device_uuid) and update.php. This completes the offline-first
 * contract the mobile app builds against.
 *
 * Clients MUST resume from next_since, not server_time. server_time stays in
 * the response for compatibility only. When has_more is true, next_since is
 * the last returned row's updated_at (page again immediately); otherwise it is
 * the snapshot minus 1 second. The 1-second overlap is intentional: updated_at
 * is DATETIME (1s resolution) and the WHERE is strict >, so a row written in
 * the same second as the snapshot would be lost with a bare cursor. Overlapped
 * rows are re-sent next sync and the client upserts idempotently by id.
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/ScanAuth.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST only']);
    exit;
}

$ctx = ScanAuth::requireEmployee();
$body = json_decode(file_get_contents('php://input'), true) ?: [];

try {
    $db = Database::getInstance();
    $params = ['e' => $ctx['employee_id']];
    $where = "employee_id = :e";
    $since = trim($body['since'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $since)) {
        $where .= " AND updated_at > :s";
        $params['s'] = $since;
    }
    // n = snapshot for server_time (compat), n1 = snapshot minus 1 second,
    // computed in the same statement so both come from one NOW() evaluation.
    $now = $db->fetchOne("SELECT NOW() n, DATE_SUB(NOW(), INTERVAL 1 SECOND) n1");
    $rows = $db->fetchAll(
        "SELECT id, device_uuid, parsed, tags, met_at, met_where, status, image_path, created_at, updated_at
         FROM scans WHERE $where ORDER BY updated_at ASC LIMIT 500", $params);

    $scans = array_map(function ($r) {
        return [
            'id' => (int)$r['id'],
            'device_uuid' => $r['device_uuid'],
            'parsed' => json_decode($r['parsed'], true),
            'tags' => $r['tags'],
            'met_at' => $r['met_at'],
            'met_where' => $r['met_where'],
            'status' => $r['status'],
            'image_url' => $r['image_path'] ? '/' . $r['image_path'] : null,
            'created_at' => $r['created_at'],
            'updated_at' => $r['updated_at'],
        ];
    }, $rows);

    // Exactly a full page means the window may hold more rows than LIMIT;
    // hand back the last row's updated_at so the client pages through the
    // remainder instead of skipping it when it resumes from a snapshot time.
    $hasMore = count($rows) === 500;
    $nextSince = $hasMore ? end($rows)['updated_at'] : $now['n1'];

    echo json_encode([
        'success' => true,
        'scans' => $scans,
        'server_time' => $now['n'],
        'has_more' => $hasMore,
        'next_since' => $nextSince,
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log('[scan/sync] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_error']);
    exit;
}
