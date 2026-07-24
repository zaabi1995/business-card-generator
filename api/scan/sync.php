<?php
/**
 * POST /api/scan/sync.php, pull-based sync endpoint for the mobile app
 *
 * Body: {cursor: {updated_at: "YYYY-MM-DD HH:MM:SS", id: 123}|null}
 * Legacy clients may send {since: "YYYY-MM-DD HH:MM:SS"|null}.
 * Response: {success, scans: [...], server_time, has_more, next_cursor,
 * next_since}
 * The app pulls all changes since its last sync; pushes happen through upload.php
 * (idempotent via device_uuid) and update.php. This completes the offline-first
 * contract the mobile app builds against.
 *
 * Composite updated_at/id cursors prevent records from being skipped when more
 * than one page shares the same one-second DATETIME value. next_since remains
 * for compatibility with older app builds.
 */
require_once __DIR__ . '/../../config.php';
require_once INCLUDES_DIR . '/ScanAuth.php';

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST only']);
    exit;
}

$ctx = ScanAuth::requireEmployeeMutation();
require_once __DIR__ . '/_ratelimit.php';
scanRateLimit($ctx, 'sync', 1200);
$body = json_decode(file_get_contents('php://input'), true) ?: [];

try {
    $db = Database::getInstance();
    $params = ['e' => $ctx['employee_id']];
    $where = "employee_id = :e";
    $timestampPattern = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';
    $cursorAt = trim((string)($body['cursor']['updated_at'] ?? ''));
    $cursorId = filter_var(
        $body['cursor']['id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 0]]
    );
    if (preg_match($timestampPattern, $cursorAt) && $cursorId !== false) {
        $where .= " AND (updated_at > :cursor_after OR (updated_at = :cursor_equal AND id > :cursor_id))";
        $params['cursor_after'] = $cursorAt;
        $params['cursor_equal'] = $cursorAt;
        $params['cursor_id'] = $cursorId;
    } else {
        $since = trim((string)($body['since'] ?? ''));
        if (preg_match($timestampPattern, $since)) {
            $where .= " AND updated_at > :since";
            $params['since'] = $since;
        }
    }
    // n = snapshot for server_time (compat), n1 = snapshot minus 1 second,
    // computed in the same statement so both come from one NOW() evaluation.
    $now = $db->fetchOne("SELECT NOW() n, DATE_SUB(NOW(), INTERVAL 1 SECOND) n1");
    $rows = $db->fetchAll(
        "SELECT id, device_uuid, parsed, tags, met_at, met_where, status, image_path, image_path_back, created_at, updated_at
         FROM scans WHERE $where ORDER BY updated_at ASC, id ASC LIMIT 500", $params);

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
            'image_back_url' => $r['image_path_back'] ? '/' . $r['image_path_back'] : null,
            'created_at' => $r['created_at'],
            'updated_at' => $r['updated_at'],
        ];
    }, $rows);

    $hasMore = count($rows) === 500;
    $lastRow = $rows ? end($rows) : null;
    $nextCursor = $hasMore && $lastRow
        ? ['updated_at' => $lastRow['updated_at'], 'id' => (int)$lastRow['id']]
        : ['updated_at' => $now['n1'], 'id' => 0];

    echo json_encode([
        'success' => true,
        'scans' => $scans,
        'server_time' => $now['n'],
        'has_more' => $hasMore,
        'next_cursor' => $nextCursor,
        'next_since' => $nextCursor['updated_at'],
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log('[scan/sync] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_error']);
    exit;
}
