<?php
/**
 * POST /api/scan/sync.php, pull-based sync endpoint for the mobile app
 *
 * Body: {since: "YYYY-MM-DD HH:MM:SS"|null} -> {success, scans: [...], server_time}
 * The app pulls all changes since its last sync; pushes happen through upload.php
 * (idempotent via device_uuid) and update.php. This completes the offline-first
 * contract the mobile app builds against.
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
    $now = $db->fetchOne("SELECT NOW() n");
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

    echo json_encode(['success' => true, 'scans' => $scans, 'server_time' => $now['n']], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log('[scan/sync] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'server_error']);
    exit;
}
